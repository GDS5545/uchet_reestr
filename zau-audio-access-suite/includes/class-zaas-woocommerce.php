<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WooCommerce glue: product-level protection settings, order-status ->
 * grant creation, one unified "your book is ready" email, and an order
 * lock so concurrent status-change webhooks (WooCommerce itself, the
 * Kaspi gateway, and the amoCRM approve callback can all fire close
 * together) never create duplicate grants or send duplicate emails.
 */
final class ZAAS_WooCommerce {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_general_product_data', [$this, 'product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);

        add_action('woocommerce_order_status_processing', [$this, 'maybe_create_grants']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_create_grants']);
        add_action('woocommerce_payment_complete', [$this, 'maybe_create_grants']);

        add_action('woocommerce_order_details_after_order_table', [$this, 'render_order_access_box']);
        add_action('add_meta_boxes', [$this, 'register_admin_metabox']);

        add_action('init', [$this, 'register_my_account_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_my_account_menu_item']);
        add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', [$this, 'render_my_account_endpoint']);
        add_filter('the_title', [$this, 'filter_endpoint_page_title']);
    }

    const ACCOUNT_ENDPOINT = 'moi-knigi';

    /**
     * "Мои книги" tab in WooCommerce → Моя учётная запись — parity with
     * WKQAA's My Books account page, simply rendering the same
     * [zaas_library] shortcode content that also lives at the standalone
     * library page.
     */
    public function register_my_account_endpoint() {
        add_rewrite_endpoint(self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_my_account_menu_item($items) {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'orders') { $new[self::ACCOUNT_ENDPOINT] = 'Мои книги'; }
        }
        if (!isset($new[self::ACCOUNT_ENDPOINT])) { $new[self::ACCOUNT_ENDPOINT] = 'Мои книги'; }
        return $new;
    }

    public function render_my_account_endpoint() {
        echo do_shortcode('[zaas_library]');
    }

    public function filter_endpoint_page_title($title) {
        if (is_account_page() && is_wc_endpoint_url(self::ACCOUNT_ENDPOINT) && in_the_loop()) {
            return 'Мои книги';
        }
        return $title;
    }

    private function p() { return ZAAS_Plugin::instance(); }

    /* ------------------------------------------------------------------ *
     *  Product settings: which pages does this product unlock
     * ------------------------------------------------------------------ */

    public function product_fields() {
        global $post;
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => '_zaas_enabled', 'label' => 'ZAU Аудиодоступ',
            'description' => 'Продукт открывает доступ к защищённым страницам/аудио ниже.',
        ]);
        $pages = (array) get_post_meta($post->ID, '_zaas_content_pages', true);
        woocommerce_wp_text_input([
            'id' => '_zaas_content_pages_input', 'label' => 'ID страниц через запятую',
            'value' => implode(',', array_map('absint', $pages)),
            'description' => 'Страницы, которые становятся доступны после покупки (см. class-zaas-access.php protect_pages()).',
        ]);
        echo '</div>';
    }

    public function save_product_fields($post_id) {
        update_post_meta($post_id, '_zaas_enabled', isset($_POST['_zaas_enabled']) ? 1 : 0);
        $raw = sanitize_text_field(wp_unslash($_POST['_zaas_content_pages_input'] ?? ''));
        $ids = array_filter(array_map('absint', explode(',', $raw)));
        update_post_meta($post_id, '_zaas_content_pages', array_values($ids));

        // Reverse index on each page so ZAAS_Access::protect_pages() can do
        // a single postmeta lookup instead of scanning every product.
        foreach ($ids as $page_id) {
            $existing = (array) get_post_meta($page_id, '_zaas_product_ids', true);
            if (!in_array($post_id, $existing, true)) {
                $existing[] = $post_id;
                update_post_meta($page_id, '_zaas_product_ids', array_values($existing));
            }
        }
    }

    /* ------------------------------------------------------------------ *
     *  Order -> grants
     * ------------------------------------------------------------------ */

    private function acquire_order_lock($order_id) {
        global $wpdb;
        $key = 'zaas_order_lock_' . absint($order_id);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key));
        return (string) $got === '1';
    }

    private function release_order_lock($order_id) {
        global $wpdb;
        $key = 'zaas_order_lock_' . absint($order_id);
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }

    public function maybe_create_grants($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        if (!$this->acquire_order_lock($order_id)) { return; }

        try {
            $email = $order->get_billing_email();
            if (!is_email($email)) { return; }

            $tokens_by_grant_id = [];
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                if (!get_post_meta($product_id, '_zaas_enabled', true)) { continue; }

                $result = ZAAS_Access::instance()->create_grant([
                    'order_id' => $order_id, 'order_item_id' => $item_id, 'product_id' => $product_id,
                    'customer_id' => $order->get_customer_id(), 'customer_email' => $email, 'source' => 'woocommerce',
                ]);
                if (!is_wp_error($result) && $result['created'] && $result['token']) {
                    $tokens_by_grant_id[$result['grant']->id] = $result['token'];
                }
            }

            if ($tokens_by_grant_id && !$order->get_meta('_zaas_access_email_sent_at')) {
                $this->send_access_email($order_id, $tokens_by_grant_id);
                $order->update_meta_data('_zaas_access_email_sent_at', current_time('mysql'));
                $order->save();
            }
        } finally {
            $this->release_order_lock($order_id);
        }
    }

    /**
     * Sends one email covering every active grant tied to this order.
     * Reused verbatim by ZAAS_AmoCRM and ZAAS_Kaspi when *they* approve an
     * order, so there is exactly one "your book is ready" template in the
     * whole plugin instead of the five near-duplicates the original
     * plugin set had.
     */
    public function send_access_email($order_id, array $tokens_by_grant_id = []) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $email = $order->get_billing_email();
        if (!is_email($email)) { return; }

        global $wpdb;
        $grants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE order_id=%d AND customer_email=%s",
            $order_id, $email
        ));
        if (!$grants) { return; }

        $links = [];
        foreach ($grants as $grant) {
            $product = wc_get_product($grant->product_id);
            if (isset($tokens_by_grant_id[$grant->id])) {
                $url = ZAAS_Access::instance()->activation_url($tokens_by_grant_id[$grant->id]);
            } elseif ($grant->status === 'active') {
                $url = ZAAS_Access::instance()->entry_url($grant->access_uid);
            } else {
                // No plain token available (e.g. resend without regenerating)
                // — skip rather than emit a broken/unusable link.
                continue;
            }
            $links[] = sprintf('<p><a href="%s" style="display:inline-block;padding:14px 28px;background:#1565C0;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;">Перейти к книге «%s»</a></p>',
                esc_url($url), esc_html($product ? $product->get_name() : 'Аудиокнига'));
        }
        if (!$links) { return; }

        $settings = $this->p()->settings();
        $body = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#152033;">'
            . '<p>Здравствуйте!</p><p>Ваш заказ №' . esc_html($order->get_order_number()) . ' готов. Нажмите на кнопку ниже, чтобы открыть книгу — ссылка привязывается к вашему устройству, повторный вход не потребуется.</p>'
            . implode('', $links);
        if (!empty($settings['pin_enabled'])) {
            $body .= '<p>Совет: на странице книги можно установить постоянный PIN-код для быстрого входа с других устройств.</p>';
        }
        $body .= '</div>';

        wp_mail($email, 'Доступ к аудиокниге открыт', $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------ *
     *  Admin/order UI
     * ------------------------------------------------------------------ */

    public function render_order_access_box($order) {
        global $wpdb;
        $grants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE order_id=%d", $order->get_id()
        ));
        if (!$grants) { return; }
        echo '<h2>ZAU Аудиодоступ</h2><table class="shop_table"><thead><tr><th>Продукт</th><th>Статус</th><th>Действует до</th></tr></thead><tbody>';
        foreach ($grants as $grant) {
            $product = wc_get_product($grant->product_id);
            echo '<tr><td>' . esc_html($product ? $product->get_name() : $grant->product_id) . '</td>';
            echo '<td>' . esc_html($grant->status) . '</td>';
            echo '<td>' . esc_html($grant->access_expires_at ?: '—') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function register_admin_metabox() {
        add_meta_box('zaas_order_access', 'ZAU Аудиодоступ', [$this, 'render_admin_metabox'], wc_get_page_screen_id('shop-order'), 'side');
    }

    public function render_admin_metabox($post_or_order) {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order) { return; }
        $this->render_order_access_box($order);
        /**
         * Admin-only extension point (never fired on the customer-facing
         * order-received page): ZAAS_Kaspi and ZAAS_AmoCRM hook here to
         * show their own status + manual approve/reject/sync buttons
         * without this class needing to know about either module.
         */
        do_action('zaas_order_metabox_extra', $order);
    }
}
