<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One Kaspi payment gateway that merges what used to be two plugins:
 *  - the actual WC_Payment_Gateway + QR/pay-URL + receipt upload
 *    (formerly "Woo Kaspi QR amoCRM Access"), and
 *  - the interstitial "оплата через Kaspi" instructions page
 *    (formerly "Woo Kaspi Payment Instructions Addon"), now simply the
 *    gateway's own woocommerce_thankyou_{id} screen instead of a second,
 *    differently-keyed implementation of the same QR+button UI.
 *
 * Approval funnels through WooCommerce's own order-status machinery
 * ($order->payment_complete() / update_status('completed')) which is
 * already wired to ZAAS_WooCommerce::maybe_create_grants() — so there is
 * exactly one place in the whole plugin that turns "order paid" into
 * "grants created + email sent", not three.
 */
final class ZAAS_Kaspi {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('init', [$this, 'register_order_status']);
        add_filter('wc_order_statuses', [$this, 'register_order_status_label']);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', [$this, 'allow_payment_complete_from_review']);

        add_action('admin_post_zaas_kaspi_approve', [$this, 'handle_admin_approve']);
        add_action('admin_post_zaas_kaspi_reject', [$this, 'handle_admin_reject']);
        add_action('admin_post_zaas_kaspi_upload_receipt', [$this, 'handle_receipt_upload']);
        add_action('admin_post_nopriv_zaas_kaspi_upload_receipt', [$this, 'handle_receipt_upload']);

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('zaas_order_metabox_extra', [$this, 'render_order_metabox_actions']);

        // Shortcode aliases for sites migrating page content built with the
        // original "Woo Kaspi QR amoCRM Access" plugin's shortcode names —
        // both simply delegate to ZAAS_Access so there is no second copy
        // of the protected-content/buy-button logic.
        add_shortcode('kaspi_protected_content', [ZAAS_Access::instance(), 'protected_shortcode']);
        add_shortcode('kaspi_buy_button', [ZAAS_Access::instance(), 'buy_button_shortcode']);
        add_shortcode('kaspi_receipt_upload', [$this, 'receipt_upload_shortcode']);
    }

    /**
     * Standalone receipt-upload form usable outside the thank-you page
     * (e.g. embedded in a "проверьте оплату" page). Resolves the order
     * from an explicit order_id/order_key attribute, the order-received
     * query var, or the customer's last order.
     */
    public function receipt_upload_shortcode($atts) {
        $atts = shortcode_atts(['order_id' => 0, 'order_key' => ''], $atts);
        $order_id = absint($atts['order_id']) ?: absint(get_query_var('order-received'));
        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order && is_user_logged_in()) {
            $customer_orders = wc_get_orders(['customer' => get_current_user_id(), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'payment_method' => 'zaas_kaspi']);
            $order = $customer_orders ? $customer_orders[0] : null;
        }
        if (!$order) { return '<p class="zaas-muted">Заказ не найден. Откройте эту страницу по ссылке из письма о заказе.</p>'; }

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway = $gateways['zaas_kaspi'] ?? null;
        if (!$gateway instanceof WC_Gateway_ZAAS_Kaspi) { return ''; }

        ob_start();
        $gateway->render_payment_box($order->get_id());
        return ob_get_clean();
    }

    /**
     * Quick approve/reject buttons in the order edit screen — parity with
     * WKQAA's order-screen approve/reject admin_post actions.
     */
    public function render_order_metabox_actions($order) {
        if ($order->get_payment_method() !== 'zaas_kaspi') { return; }
        echo '<p style="margin-top:10px;"><strong>Kaspi:</strong> ' . esc_html($order->get_status()) . '</p>';
        if (!$order->has_status(['completed', 'cancelled', 'refunded'])) {
            echo '<p>';
            echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_kaspi_approve&order_id=' . $order->get_id()), ZAAS_Plugin::NONCE)) . '">Подтвердить оплату</a> ';
            echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_kaspi_reject&order_id=' . $order->get_id()), ZAAS_Plugin::NONCE)) . '">Отклонить</a>';
            echo '</p>';
        }
        $attachment_id = (int) $order->get_meta('_zaas_receipt_attachment_id');
        if ($attachment_id) {
            echo '<p><a href="' . esc_url(wp_get_attachment_url($attachment_id)) . '" target="_blank" rel="noopener">Открыть загруженный чек</a></p>';
        }
    }

    private function p() { return ZAAS_Plugin::instance(); }

    public function register_gateway($gateways) {
        $gateways[] = 'WC_Gateway_ZAAS_Kaspi';
        return $gateways;
    }

    public function register_order_status() {
        register_post_status('wc-receipt-review', [
            'label' => 'Проверка чека Kaspi', 'public' => false, 'exclude_from_search' => true,
            'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Проверка чека <span class="count">(%s)</span>', 'Проверка чека <span class="count">(%s)</span>'),
        ]);
        register_post_status('wc-receipt-rejected', [
            'label' => 'Чек отклонён', 'public' => false, 'exclude_from_search' => true,
            'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Чек отклонён <span class="count">(%s)</span>', 'Чек отклонён <span class="count">(%s)</span>'),
        ]);
    }

    public function register_order_status_label($statuses) {
        $statuses['wc-receipt-review'] = 'Проверка чека Kaspi';
        $statuses['wc-receipt-rejected'] = 'Чек отклонён';
        return $statuses;
    }

    public function allow_payment_complete_from_review($statuses) {
        $statuses[] = 'receipt-review';
        return $statuses;
    }

    /* ------------------------------------------------------------------ *
     *  Approve / reject (shared by admin UI and the signed REST webhook)
     * ------------------------------------------------------------------ */

    public function approve_order($order_id, $note = 'Оплата Kaspi подтверждена') {
        $order = wc_get_order($order_id);
        if (!$order) { return new WP_Error('zaas_order_missing', 'Заказ не найден.'); }
        if (!$order->has_status('completed')) {
            $order->payment_complete();
            $order->update_status('completed', $note);
        }
        $this->p()->log('kaspi_approved', 'order', $order_id, $note);
        return true;
    }

    public function reject_order($order_id, $note = 'Чек Kaspi отклонён') {
        $order = wc_get_order($order_id);
        if (!$order) { return new WP_Error('zaas_order_missing', 'Заказ не найден.'); }
        $order->update_status('receipt-rejected', $note);
        $this->p()->log('kaspi_rejected', 'order', $order_id, $note);
        return true;
    }

    public function handle_admin_approve() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); }
        check_admin_referer(ZAAS_Plugin::NONCE);
        $order_id = absint($_GET['order_id'] ?? 0);
        $this->approve_order($order_id, 'Подтверждено вручную администратором');
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    public function handle_admin_reject() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); }
        check_admin_referer(ZAAS_Plugin::NONCE);
        $order_id = absint($_GET['order_id'] ?? 0);
        $this->reject_order($order_id, 'Отклонено вручную администратором');
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /**
     * Signed webhook so an external automation (amoCRM scenario, Make,
     * Albato, or a Kaspi-side bot) can approve/reject without WordPress
     * credentials. Signature = hash_hmac('sha256', "{action}|{order_id}",
     * secret) where secret is WC_Gateway_ZAAS_Kaspi's own "secret_key"
     * option — deliberately HMAC-signed rather than a bare shared-secret
     * query param.
     */
    public function register_rest_routes() {
        register_rest_route('zaas/v1', '/kaspi/(?P<action>approve|reject)', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'rest_handle'],
            'permission_callback' => [$this, 'rest_permission'],
            'args' => ['order_id' => ['required' => true]],
        ]);
    }

    private function gateway_secret() {
        $settings = get_option('woocommerce_zaas_kaspi_settings', []);
        return (string) ($settings['secret_key'] ?? '');
    }

    public function rest_permission(WP_REST_Request $request) {
        $secret = $this->gateway_secret();
        if ($secret === '') { return false; }
        $action = $request->get_param('action');
        $order_id = absint($request->get_param('order_id'));
        $signature = (string) $request->get_param('signature');
        $expected = hash_hmac('sha256', $action . '|' . $order_id, $secret);
        return $signature !== '' && hash_equals($expected, $signature);
    }

    public function rest_handle(WP_REST_Request $request) {
        $order_id = absint($request->get_param('order_id'));
        $action = $request->get_param('action');
        $result = $action === 'approve' ? $this->approve_order($order_id, 'Подтверждено вебхуком') : $this->reject_order($order_id, 'Отклонено вебхуком');
        if (is_wp_error($result)) { return new WP_REST_Response(['ok' => false, 'message' => $result->get_error_message()], 404); }
        return new WP_REST_Response(['ok' => true], 200);
    }

    /* ------------------------------------------------------------------ *
     *  Receipt upload (customer-facing, on the thank-you page)
     * ------------------------------------------------------------------ */

    public function handle_receipt_upload() {
        $order_id = absint($_POST['order_id'] ?? 0);
        $order_key = sanitize_text_field(wp_unslash($_POST['order_key'] ?? ''));
        $order = wc_get_order($order_id);
        if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
            wp_die('Некорректная ссылка на заказ.');
        }
        check_admin_referer('zaas_kaspi_receipt_' . $order_id);

        if (empty($_FILES['receipt']) || !is_uploaded_file($_FILES['receipt']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('zaas_error', 'no_file', $order->get_checkout_order_received_url()));
            exit;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload('receipt', 0);
        if (is_wp_error($attachment_id)) {
            wp_safe_redirect(add_query_arg('zaas_error', 'upload_failed', $order->get_checkout_order_received_url()));
            exit;
        }

        $order->update_meta_data('_zaas_receipt_attachment_id', $attachment_id);
        $order->update_status('receipt-review', 'Клиент загрузил чек Kaspi.');
        $order->save();

        $this->p()->log('kaspi_receipt_uploaded', 'order', $order_id);
        do_action('zaas_kaspi_receipt_uploaded', $order_id, $attachment_id);

        wp_safe_redirect(add_query_arg('zaas_notice', 'receipt_uploaded', $order->get_checkout_order_received_url()));
        exit;
    }
}

/**
 * The gateway itself. Kept in the same file as ZAAS_Kaspi (rather than a
 * separate class-zaas-kaspi-gateway.php) since the two are always loaded
 * together and reference each other constantly.
 */
final class WC_Gateway_ZAAS_Kaspi extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'zaas_kaspi';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Kaspi QR (ZAU Аудиодоступ)';
        $this->method_description = 'Оплата через Kaspi QR/перевод с загрузкой чека и подтверждением администратором или вебхуком.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'render_payment_box']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => ['title' => 'Включить', 'type' => 'checkbox', 'label' => 'Включить оплату Kaspi', 'default' => 'no'],
            'title' => ['title' => 'Название на кассе', 'type' => 'text', 'default' => 'Оплата Kaspi QR'],
            'description' => ['title' => 'Описание на кассе', 'type' => 'textarea', 'default' => 'Оплата переводом на Kaspi по QR-коду с последующей загрузкой чека.'],
            'qr_image_url' => ['title' => 'URL изображения QR', 'type' => 'text', 'description' => 'Ссылка на картинку QR-кода Kaspi.'],
            'pay_url_template' => [
                'title' => 'Шаблон ссылки на оплату', 'type' => 'text',
                'description' => 'Плейсхолдеры: {amount} {amount_decimal} {order_id} {order_number} {comment} {return_url} {currency} {phone} {email}',
            ],
            'auto_select' => ['title' => 'Автовыбор', 'type' => 'checkbox', 'label' => 'Выбирать Kaspi автоматически на кассе', 'default' => 'no'],
            'hide_other_gateways' => ['title' => 'Скрыть остальные способы оплаты', 'type' => 'checkbox', 'label' => 'Показывать только Kaspi', 'default' => 'no'],
            'auto_redirect_to_kaspi' => [
                'title' => 'Мгновенный переход в Kaspi', 'type' => 'checkbox',
                'label' => 'Сразу перенаправлять клиента на ссылку оплаты вместо страницы с инструкцией',
                'description' => 'Если включено — клиент попадёт прямо в Kaspi, а страница с QR/загрузкой чека откроется после возврата на order-received.', 'default' => 'no',
            ],
            'support_text' => [
                'title' => 'Текст поддержки', 'type' => 'textarea',
                'description' => 'Показывается под формой загрузки чека (например, контакт поддержки на случай проблем с оплатой).', 'default' => '',
            ],
            'secret_key' => [
                'title' => 'Секретный ключ вебхука', 'type' => 'text',
                'description' => 'Используется для подписи запросов approve/reject: zaas/v1/kaspi/(approve|reject)?order_id=...&signature=hash_hmac(sha256,"action|order_id",secret).',
            ],
        ];
    }

    private function build_pay_url($order) {
        $template = $this->get_option('pay_url_template');
        if (!$template) { return ''; }
        $replacements = [
            '{amount}' => (string) intval($order->get_total()),
            '{amount_decimal}' => number_format((float) $order->get_total(), 2, '.', ''),
            '{order_id}' => (string) $order->get_id(),
            '{order_number}' => (string) $order->get_order_number(),
            '{comment}' => rawurlencode('Заказ №' . $order->get_order_number()),
            '{return_url}' => rawurlencode($order->get_checkout_order_received_url()),
            '{currency}' => $order->get_currency(),
            '{phone}' => preg_replace('/\D/', '', (string) $order->get_billing_phone()),
            '{email}' => rawurlencode((string) $order->get_billing_email()),
        ];
        return strtr($template, $replacements);
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', 'Ожидание оплаты Kaspi.');
        $pay_url = $this->build_pay_url($order);
        $order->update_meta_data('_zaas_kaspi_pay_url', $pay_url);
        $order->save();
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        // Optional: skip the order-received instructions page and send the
        // customer straight into Kaspi (WKQAA's auto_redirect_to_kaspi
        // behaviour). They still land on order-received — and see the QR
        // + receipt-upload box — the next time they open the order email.
        if ($pay_url && $this->get_option('auto_redirect_to_kaspi') === 'yes') {
            return ['result' => 'success', 'redirect' => $pay_url];
        }
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    public function render_payment_box($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $qr = $this->get_option('qr_image_url');
        $pay_url = $order->get_meta('_zaas_kaspi_pay_url') ?: $this->build_pay_url($order);

        echo '<div class="zaas-interface zaas-kaspi-box">';
        echo '<h2>Оплата через Kaspi</h2>';
        if ($qr) { echo '<img class="zaas-kaspi-qr" src="' . esc_url($qr) . '" alt="Kaspi QR">'; }
        if ($pay_url) { echo '<p><a class="zaas-btn zaas-btn-primary" href="' . esc_url($pay_url) . '">Оплатить в Kaspi</a></p>'; }
        echo '<p class="zaas-muted">После оплаты загрузите скриншот или фото чека — доступ откроется после подтверждения (обычно в течение нескольких минут).</p>';

        echo '<form class="zaas-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="zaas_kaspi_upload_receipt">';
        echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
        echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '">';
        wp_nonce_field('zaas_kaspi_receipt_' . $order->get_id());
        echo '<input type="file" name="receipt" accept="image/*,.pdf" required>';
        echo '<button type="submit" class="zaas-btn zaas-btn-secondary">Отправить чек</button>';
        echo '</form>';

        if (isset($_GET['zaas_notice']) && $_GET['zaas_notice'] === 'receipt_uploaded') {
            echo '<p class="zaas-notice-success">Чек получен, ожидайте подтверждения.</p>';
        }
        $support_text = $this->get_option('support_text');
        if ($support_text) { echo '<p class="zaas-muted">' . wp_kses_post(wpautop($support_text)) . '</p>'; }
        echo '</div>';
    }
}
