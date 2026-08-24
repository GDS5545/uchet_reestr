<?php
if (!defined('ABSPATH')) { exit; }

/**
 * amoCRM OAuth2 API client. Adapted from the original "Woo amoCRM Confirm
 * Access" plugin's client, with one deliberate simplification: receipt
 * attachment now reads the single explicit `_zaas_receipt_attachment_id`
 * meta that ZAAS_Kaspi's upload handler sets, instead of heuristically
 * scanning the whole uploads directory for a plausible receipt image by
 * filename/timestamp — that scan was a real feature of the original but a
 * poor fit for a merged plugin that already knows exactly which
 * attachment is the receipt.
 */
final class ZAAS_AmoCRM_Client {

    private function domain() {
        $domain = trim((string) ZAAS_Plugin::instance()->settings()['amo_account_domain']);
        $domain = preg_replace('#^https?://#', '', $domain);
        return rtrim($domain, '/');
    }

    private function api_base() { return 'https://' . $this->domain(); }

    public function exchange_code($code) {
        $s = ZAAS_Plugin::instance()->settings();
        return $this->token_request([
            'client_id' => $s['amo_client_id'], 'client_secret' => $s['amo_client_secret'],
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $s['amo_redirect_uri'],
        ]);
    }

    private function refresh_token() {
        $s = ZAAS_Plugin::instance()->settings();
        if (empty($s['amo_refresh_token'])) { return new WP_Error('zaas_amo_no_refresh', 'Нет refresh token amoCRM. Авторизуйте amoCRM заново.'); }
        return $this->token_request([
            'client_id' => $s['amo_client_id'], 'client_secret' => $s['amo_client_secret'],
            'grant_type' => 'refresh_token', 'refresh_token' => $s['amo_refresh_token'], 'redirect_uri' => $s['amo_redirect_uri'],
        ]);
    }

    private function token_request($payload) {
        if (!$this->domain()) { return new WP_Error('zaas_amo_no_domain', 'Не указан домен amoCRM.'); }
        $response = wp_remote_post($this->api_base() . '/oauth2/access_token', [
            'timeout' => 30, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            return new WP_Error('zaas_amo_token_error', 'Ошибка получения токена amoCRM: ' . wp_remote_retrieve_body($response));
        }
        ZAAS_Plugin::instance()->update_settings([
            'amo_access_token' => sanitize_text_field($body['access_token']),
            'amo_refresh_token' => sanitize_text_field($body['refresh_token']),
            'amo_token_expires_at' => time() + (int) $body['expires_in'] - 120,
        ]);
        return $body;
    }

    private function get_access_token() {
        $s = ZAAS_Plugin::instance()->settings();
        if (empty($s['amo_access_token']) || time() >= (int) $s['amo_token_expires_at']) {
            $refresh = $this->refresh_token();
            if (is_wp_error($refresh)) { return $refresh; }
            $s = ZAAS_Plugin::instance()->settings();
        }
        return $s['amo_access_token'];
    }

    public function request($method, $path, $body = null, $retry = true) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) { return $token; }
        $args = [
            'timeout' => 30, 'method' => strtoupper($method),
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ];
        if ($body !== null) { $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE); }
        $response = wp_remote_request($this->api_base() . $path, $args);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code === 401 && $retry) {
            $refreshed = $this->refresh_token();
            if (is_wp_error($refreshed)) { return $refreshed; }
            return $this->request($method, $path, $body, false);
        }
        if ($code < 200 || $code >= 300) { return new WP_Error('zaas_amo_api_error', 'amoCRM API error ' . $code . ': ' . $raw); }
        return is_array($decoded) ? $decoded : [];
    }

    public function create_contact($order) {
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (!$name) { $name = $order->get_formatted_billing_full_name(); }
        if (!$name) { $name = 'Клиент WooCommerce #' . $order->get_id(); }

        $custom_fields = [];
        if ($phone) { $custom_fields[] = ['field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']]]; }
        if ($email) { $custom_fields[] = ['field_code' => 'EMAIL', 'values' => [['value' => $email, 'enum_code' => 'WORK']]]; }

        $result = $this->request('POST', '/api/v4/contacts', [['name' => $name, 'custom_fields_values' => $custom_fields]]);
        if (is_wp_error($result)) { return $result; }
        return $result['_embedded']['contacts'][0]['id'] ?? null;
    }

    public function create_lead_for_order($order) {
        $contact_id = $this->create_contact($order);
        if (is_wp_error($contact_id)) { return $contact_id; }

        $items = [];
        foreach ($order->get_items() as $item) { $items[] = $item->get_name() . ' × ' . $item->get_quantity(); }

        $s = ZAAS_Plugin::instance()->settings();
        $lead = [
            'name' => 'Заказ WooCommerce #' . $order->get_id(),
            'price' => (int) round((float) $order->get_total()),
            '_embedded' => ['tags' => [['name' => 'WooCommerce'], ['name' => 'Заказ #' . $order->get_id()]]],
        ];
        if (!empty($s['amo_pipeline_id'])) { $lead['pipeline_id'] = (int) $s['amo_pipeline_id']; }
        if (!empty($s['amo_new_status_id'])) { $lead['status_id'] = (int) $s['amo_new_status_id']; }
        if ($contact_id) { $lead['_embedded']['contacts'] = [['id' => (int) $contact_id]]; }

        $result = $this->request('POST', '/api/v4/leads', [$lead]);
        if (is_wp_error($result)) { return $result; }
        $lead_id = $result['_embedded']['leads'][0]['id'] ?? null;
        if ($lead_id) {
            $this->add_order_note_to_lead($lead_id, $order, $items);
            $this->attach_receipt_to_lead($lead_id, $order);
        }
        return $lead_id;
    }

    public function add_order_note_to_lead($lead_id, $order, $items) {
        $text = 'Заказ WooCommerce #' . $order->get_id() . "\n"
            . 'Сумма: ' . $order->get_total() . ' ' . $order->get_currency() . "\n"
            . 'Email: ' . $order->get_billing_email() . "\n"
            . 'Телефон: ' . $order->get_billing_phone() . "\n"
            . 'Товары: ' . implode(', ', $items) . "\n"
            . 'Ссылка на заказ: ' . admin_url('post.php?post=' . $order->get_id() . '&action=edit');

        return $this->request('POST', '/api/v4/leads/notes', [[
            'entity_id' => (int) $lead_id, 'note_type' => 'common', 'params' => ['text' => $text],
        ]]);
    }

    /**
     * Attaches the customer-uploaded Kaspi receipt (see
     * ZAAS_Kaspi::handle_receipt_upload) to the lead via amoCRM's Files
     * (Drive) API, then leaves a note pointing at it.
     */
    public function attach_receipt_to_lead($lead_id, $order) {
        $attachment_id = (int) $order->get_meta('_zaas_receipt_attachment_id');
        if (!$attachment_id) { return false; }
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) { return false; }

        $upload = $this->upload_file($file_path, 'Чек оплаты заказа ' . $order->get_order_number());
        if (is_wp_error($upload) || empty($upload['uuid'])) { return false; }

        $link = $this->request('PUT', '/api/v4/leads/' . (int) $lead_id . '/files', [['file_uuid' => $upload['uuid']]]);
        if (is_wp_error($link)) { return false; }

        $this->request('POST', '/api/v4/leads/notes', [[
            'entity_id' => (int) $lead_id, 'note_type' => 'common',
            'params' => ['text' => 'Чек оплаты Kaspi автоматически прикреплён к сделке.'],
        ]]);
        return true;
    }

    private function upload_file($file_path, $display_name) {
        $file_path = wp_normalize_path($file_path);
        $size = (int) @filesize($file_path);
        if ($size <= 0 || $size > 15 * MB_IN_BYTES) { return new WP_Error('zaas_amo_file_size', 'Файл чека пустой или больше 15 МБ.'); }

        $account = $this->request('GET', '/api/v4/account?with=drive_url');
        if (is_wp_error($account)) { return $account; }
        $drive_url = !empty($account['drive_url']) ? rtrim($account['drive_url'], '/') : '';
        if (!$drive_url) { return new WP_Error('zaas_amo_no_drive_url', 'amoCRM не вернула drive_url.'); }

        $mime = function_exists('wp_check_filetype_and_ext') ? wp_check_filetype_and_ext($file_path, basename($file_path)) : [];
        $content_type = $mime['type'] ?? (function_exists('mime_content_type') ? mime_content_type($file_path) : 'application/octet-stream');

        $token = $this->get_access_token();
        if (is_wp_error($token)) { return $token; }

        $session_resp = wp_remote_post($drive_url . '/v1.0/sessions', [
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['file_name' => sanitize_file_name($display_name), 'file_size' => $size, 'content_type' => $content_type, 'with_preview' => true]),
        ]);
        if (is_wp_error($session_resp)) { return $session_resp; }
        $session = json_decode(wp_remote_retrieve_body($session_resp), true);
        $upload_url = $session['upload_url'] ?? '';
        if (!$upload_url) { return new WP_Error('zaas_amo_no_upload_url', 'amoCRM Files API не вернул upload_url.'); }

        $data = file_get_contents($file_path);
        $upload_resp = wp_remote_post($upload_url, [
            'timeout' => 120,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => $content_type],
            'body' => $data,
        ]);
        if (is_wp_error($upload_resp)) { return $upload_resp; }
        $result = json_decode(wp_remote_retrieve_body($upload_resp), true);
        return is_array($result) ? $result : new WP_Error('zaas_amo_upload_failed', 'Не удалось загрузить файл в amoCRM.');
    }

    public function get_lead($lead_id) {
        return $this->request('GET', '/api/v4/leads/' . (int) $lead_id);
    }
}

/**
 * Settings/OAuth admin flow + order->lead sync + native amoCRM webhook
 * (shared-secret query param — amoCRM's own webhook feature cannot
 * compute an HMAC signature, so this intentionally matches the original
 * plugin's auth model rather than the HMAC scheme used for the generic
 * Kaspi automation webhook in class-zaas-kaspi.php).
 */
final class ZAAS_AmoCRM {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_post_zaas_amo_exchange_code', [$this, 'handle_oauth_callback']);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_checkout_order_processed', [$this, 'maybe_create_lead']);
            add_action('woocommerce_order_status_pending', [$this, 'maybe_create_lead']);
            add_action('woocommerce_order_status_on-hold', [$this, 'maybe_create_lead']);
        }
    }

    private function p() { return ZAAS_Plugin::instance(); }
    private function client() { return new ZAAS_AmoCRM_Client(); }

    public function authorize_url() {
        $s = $this->p()->settings();
        if (empty($s['amo_account_domain']) || empty($s['amo_client_id'])) { return ''; }
        return 'https://' . preg_replace('#^https?://#', '', $s['amo_account_domain'])
            . '/oauth?client_id=' . rawurlencode($s['amo_client_id'])
            . '&mode=post_message&redirect_uri=' . rawurlencode($s['amo_redirect_uri']);
    }

    public function handle_oauth_callback() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); }
        check_admin_referer(ZAAS_Plugin::NONCE);
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? $_GET['code'] ?? ''));
        if (!$code) { wp_die('Код авторизации amoCRM не получен.'); }
        $result = $this->client()->exchange_code($code);
        $redirect = admin_url('admin.php?page=zaas-amocrm');
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('zaas_error', rawurlencode($result->get_error_message()), $redirect));
        } else {
            wp_safe_redirect(add_query_arg('zaas_notice', rawurlencode('amoCRM подключён.'), $redirect));
        }
        exit;
    }

    public function maybe_create_lead($order_id) {
        $s = $this->p()->settings();
        if (empty($s['amo_auto_create_lead']) || empty($s['amo_access_token'])) { return; }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_zaas_amo_lead_id')) { return; }

        $has_protected_item = false;
        foreach ($order->get_items() as $item) {
            if (get_post_meta($item->get_product_id(), '_zaas_enabled', true)) { $has_protected_item = true; break; }
        }
        if (!$has_protected_item) { return; }

        $lead_id = $this->client()->create_lead_for_order($order);
        if (is_wp_error($lead_id) || !$lead_id) { return; }
        $order->update_meta_data('_zaas_amo_lead_id', $lead_id);
        $order->save();
        $this->p()->log('amo_lead_created', 'order', $order_id, $lead_id);
    }

    public function register_routes() {
        register_rest_route('zaas/v1', '/amocrm-webhook', [
            'methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_webhook'], 'permission_callback' => '__return_true',
        ]);
    }

    public function handle_webhook(WP_REST_Request $request) {
        if (!class_exists('WooCommerce') || !class_exists('ZAAS_Kaspi')) {
            return new WP_REST_Response(['success' => false, 'message' => 'WooCommerce is not active'], 503);
        }
        $s = $this->p()->settings();
        $secret = (string) $s['amo_webhook_secret'];
        $incoming = (string) $request->get_param('secret');
        if ($secret && !hash_equals($secret, $incoming)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid secret'], 403);
        }
        if ($request->get_method() === 'GET') {
            return new WP_REST_Response(['success' => true, 'message' => 'Webhook active'], 200);
        }

        $lead_ids = $this->extract_lead_ids($request->get_params());
        if (!$lead_ids) { return new WP_REST_Response(['success' => true, 'message' => 'No lead id in webhook'], 200); }

        $approved_status_id = (int) $s['amo_approved_status_id'];
        if (!$approved_status_id) { return new WP_REST_Response(['success' => false, 'message' => 'Approved status ID not configured'], 500); }

        $client = $this->client();
        $processed = []; $errors = [];
        global $wpdb;

        foreach (array_unique($lead_ids) as $lead_id) {
            $lead = $client->get_lead($lead_id);
            if (is_wp_error($lead)) { $errors[] = 'Lead #' . $lead_id . ': ' . $lead->get_error_message(); continue; }
            if ((int) ($lead['status_id'] ?? 0) !== $approved_status_id) {
                $processed[] = ['lead_id' => $lead_id, 'status' => 'ignored']; continue;
            }

            $order_id = wc_get_orders(['meta_key' => '_zaas_amo_lead_id', 'meta_value' => $lead_id, 'limit' => 1, 'return' => 'ids']);
            $order_id = $order_id ? (int) $order_id[0] : 0;
            if (!$order_id) { $errors[] = 'Lead #' . $lead_id . ': заказ не найден по _zaas_amo_lead_id'; continue; }

            $result = ZAAS_Kaspi::instance()->approve_order($order_id, 'Подтверждено amoCRM (лид #' . $lead_id . ')');
            if (is_wp_error($result)) { $errors[] = 'Order #' . $order_id . ': ' . $result->get_error_message(); continue; }
            $processed[] = ['lead_id' => $lead_id, 'order_id' => $order_id, 'status' => 'completed'];
        }

        return new WP_REST_Response(['success' => empty($errors), 'processed' => $processed, 'errors' => $errors], empty($errors) ? 200 : 207);
    }

    private function extract_lead_ids($params) {
        $ids = [];
        if (isset($params['leads']) && is_array($params['leads'])) {
            foreach (['update', 'add', 'status'] as $action) {
                if (!empty($params['leads'][$action]) && is_array($params['leads'][$action])) {
                    foreach ($params['leads'][$action] as $lead) {
                        if (is_array($lead) && !empty($lead['id'])) { $ids[] = (int) $lead['id']; }
                    }
                }
            }
        }
        foreach (['lead_id', 'entity_id', 'id'] as $key) {
            if (!empty($params[$key])) { $ids[] = (int) $params[$key]; }
        }
        return array_values(array_filter(array_map('absint', $ids)));
    }
}
