<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical entitlement store and identity layer. This is the single
 * "source of truth" grants table that WooCommerce order hooks (see
 * class-zaas-woocommerce.php), the Kaspi gateway, and the amoCRM webhook
 * all write into, replacing the three separate access ledgers that used to
 * exist across the original plugin set.
 *
 * Identity model: a customer is identified by email. A device cookie pair
 * (zaas_device / zaas_session) binds a browser to that email without
 * requiring a WordPress user account; PIN (class-zaas-pin.php) and
 * passkeys (class-zaas-webauthn.php) are optional stronger re-entry
 * methods layered on top of the same device record.
 */
final class ZAAS_Access {

    const DEVICE_COOKIE  = 'zaas_device';
    const SESSION_COOKIE = 'zaas_session';

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private $resolved_email = null;
    private $resolved_email_checked = false;

    private function __construct() {
        add_action('init', [$this, 'register_rewrites']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'handle_activation'], -20);
        add_action('template_redirect', [$this, 'handle_entry'], -19);
        add_action('template_redirect', [$this, 'protect_pages'], 1);
        add_action('wp_head', [$this, 'noindex_protected_pages']);

        add_shortcode('zaas_library', [$this, 'library_shortcode']);
        add_shortcode('zaas_protected', [$this, 'protected_shortcode']);
        add_shortcode('zaas_auth', [$this, 'auth_shortcode']);
        add_shortcode('zaas_buy_button', [$this, 'buy_button_shortcode']);

        foreach (['zaas_request_recovery', 'zaas_verify_recovery', 'zaas_refresh_nonce', 'zaas_logout', 'zaas_password_login'] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . $action]);
        }

        add_action('wp_enqueue_scripts', [$this, 'public_assets']);
        add_action('zaas_daily_cleanup', [$this, 'cleanup_expired']);
    }

    private function p() { return ZAAS_Plugin::instance(); }

    /* ------------------------------------------------------------------ *
     *  Rewrites
     * ------------------------------------------------------------------ */

    public function register_rewrites() {
        add_rewrite_rule('^audio-dostup/([A-Za-z0-9_-]+)/?$', 'index.php?zaas_activate=$matches[1]', 'top');
        add_rewrite_rule('^moya-kniga/([a-f0-9]{32})/?$', 'index.php?zaas_entry=$matches[1]', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'zaas_activate';
        $vars[] = 'zaas_entry';
        return $vars;
    }

    /* ------------------------------------------------------------------ *
     *  Grant lifecycle (called by WooCommerce / Kaspi / amoCRM / import)
     * ------------------------------------------------------------------ */

    /**
     * Creates (or returns the existing) pending grant for one order line
     * item and returns the plain activation token to embed in the email
     * link. Only the sha256 hash of the token is stored.
     */
    public function create_grant($args) {
        global $wpdb;
        $defaults = [
            'order_id' => 0, 'order_item_id' => 0, 'product_id' => 0,
            'customer_id' => 0, 'customer_email' => '', 'source' => 'woocommerce',
        ];
        $args = wp_parse_args($args, $defaults);
        $args['customer_email'] = sanitize_email($args['customer_email']);
        if (!is_email($args['customer_email']) || !$args['product_id']) {
            return new WP_Error('zaas_grant_invalid', 'Некорректные данные для выдачи доступа.');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE order_id=%d AND order_item_id=%d AND product_id=%d AND customer_email=%s",
            $args['order_id'], $args['order_item_id'], $args['product_id'], $args['customer_email']
        ));
        if ($existing) {
            return ['grant' => $existing, 'token' => null, 'created' => false];
        }

        $settings = $this->p()->settings();
        $token = bin2hex(random_bytes(32));
        $access_uid = bin2hex(random_bytes(16));
        $activation_hours = max(1, (int) $settings['default_activation_hours']);

        $data = [
            'order_id'       => absint($args['order_id']),
            'order_item_id'  => absint($args['order_item_id']),
            'product_id'     => absint($args['product_id']),
            'customer_id'    => absint($args['customer_id']),
            'customer_email' => $args['customer_email'],
            'token_hash'     => hash('sha256', $token),
            'access_uid'     => $access_uid,
            'status'         => 'pending',
            'activation_expires_at' => gmdate('Y-m-d H:i:s', time() + $activation_hours * HOUR_IN_SECONDS),
            'source'         => sanitize_key($args['source']),
            'created_at'     => current_time('mysql', true),
            'updated_at'     => current_time('mysql', true),
        ];
        $inserted = $wpdb->insert($this->p()->grants_table, $data);
        if (!$inserted) {
            return new WP_Error('zaas_grant_db', 'Не удалось создать доступ: ' . $wpdb->last_error);
        }
        $data['id'] = (int) $wpdb->insert_id;
        $this->p()->log('grant_created', 'grant', $data['id'], $args['customer_email']);
        do_action('zaas_grant_created', $data['id'], $data);
        return ['grant' => (object) $data, 'token' => $token, 'created' => true];
    }

    /**
     * Issues a brand-new activation token for an existing grant (invalidating
     * any previous one) and returns it in plain form for a one-off resend
     * email. Used by the admin "Resend" action.
     */
    public function regenerate_token($grant_id) {
        global $wpdb;
        $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p()->grants_table} WHERE id=%d", absint($grant_id)));
        if (!$grant) { return new WP_Error('zaas_grant_missing', 'Доступ не найден.'); }

        $settings = $this->p()->settings();
        $token = bin2hex(random_bytes(32));
        $activation_hours = max(1, (int) $settings['default_activation_hours']);
        $wpdb->update($this->p()->grants_table, [
            'token_hash' => hash('sha256', $token),
            'activation_expires_at' => gmdate('Y-m-d H:i:s', time() + $activation_hours * HOUR_IN_SECONDS),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $grant->id]);
        $this->p()->log('grant_token_regenerated', 'grant', $grant->id, $grant->customer_email);
        return $token;
    }

    /**
     * Inserts a grant that is already 'active' (no activation-link click
     * required) — used exclusively by ZAAS_Legacy_Import to migrate
     * customers who already had working access under one of the old
     * plugins. Idempotent on (order_id, order_item_id, product_id, email).
     */
    public function import_active_grant($args) {
        global $wpdb;
        $defaults = ['order_id' => 0, 'order_item_id' => 0, 'product_id' => 0, 'customer_id' => 0, 'customer_email' => '', 'access_expires_at' => null, 'source' => 'legacy_import'];
        $args = wp_parse_args($args, $defaults);
        $args['customer_email'] = sanitize_email($args['customer_email']);
        if (!is_email($args['customer_email']) || !$args['product_id']) { return new WP_Error('zaas_import_invalid', 'Некорректные данные строки импорта.'); }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table} WHERE order_id=%d AND order_item_id=%d AND product_id=%d AND customer_email=%s",
            $args['order_id'], $args['order_item_id'], $args['product_id'], $args['customer_email']
        ));
        if ($existing) { return ['id' => (int) $existing, 'created' => false]; }

        $data = [
            'order_id'          => absint($args['order_id']),
            'order_item_id'     => absint($args['order_item_id']),
            'product_id'        => absint($args['product_id']),
            'customer_id'       => absint($args['customer_id']),
            'customer_email'    => $args['customer_email'],
            'token_hash'        => hash('sha256', wp_generate_password(40, false)), // unused, no click-through needed
            'access_uid'        => bin2hex(random_bytes(16)),
            'status'            => 'active',
            'activated_at'      => current_time('mysql', true),
            'access_expires_at' => $args['access_expires_at'],
            'source'            => sanitize_key($args['source']),
            'created_at'        => current_time('mysql', true),
            'updated_at'        => current_time('mysql', true),
        ];
        $inserted = $wpdb->insert($this->p()->grants_table, $data);
        if (!$inserted) { return new WP_Error('zaas_import_db', 'Ошибка записи: ' . $wpdb->last_error); }
        return ['id' => (int) $wpdb->insert_id, 'created' => true];
    }

    public function activation_url($token) {
        return home_url('/audio-dostup/' . rawurlencode($token) . '/');
    }

    public function entry_url($access_uid) {
        return home_url('/moya-kniga/' . $access_uid . '/');
    }

    /**
     * Activates a grant from its plain token: validates hash + expiry,
     * marks the grant active, binds the current browser as a device, and
     * returns the grant row (or WP_Error).
     */
    public function activate_token($token) {
        global $wpdb;
        $hash = hash('sha256', (string) $token);
        $grant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE token_hash=%s AND status IN ('pending','active') LIMIT 1",
            $hash
        ));
        if (!$grant) { return new WP_Error('zaas_token_invalid', 'Ссылка недействительна или уже была использована.'); }
        if ($grant->status === 'pending' && $grant->activation_expires_at && strtotime($grant->activation_expires_at . ' UTC') < time()) {
            return new WP_Error('zaas_token_expired', 'Срок действия ссылки истёк. Запросите новую на email.');
        }

        if ($grant->status === 'pending') {
            $settings = $this->p()->settings();
            $access_days = max(1, (int) $settings['default_access_days']);
            $wpdb->update($this->p()->grants_table, [
                'status'            => 'active',
                'activated_at'      => current_time('mysql', true),
                'access_expires_at' => gmdate('Y-m-d H:i:s', time() + $access_days * DAY_IN_SECONDS),
                'updated_at'        => current_time('mysql', true),
            ], ['id' => $grant->id]);
        }

        $this->bind_device($grant->customer_email);
        $this->p()->log('grant_activated', 'grant', $grant->id, $grant->customer_email);
        return $grant;
    }

    public function grants_for_email($email) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' ORDER BY activated_at DESC",
            sanitize_email($email)
        ));
    }

    public function has_active_access($product_id, $email = null) {
        $email = $email ?: $this->resolve_customer_email();
        if (!$email) { return false; }
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table}
             WHERE customer_email=%s AND product_id=%d AND status='active'
               AND (access_expires_at IS NULL OR access_expires_at > UTC_TIMESTAMP()) LIMIT 1",
            sanitize_email($email), absint($product_id)
        ));
        return (bool) $row;
    }

    /* ------------------------------------------------------------------ *
     *  Device / session cookie pair
     * ------------------------------------------------------------------ */

    private function device_secret_hash($secret) {
        return hash_hmac('sha256', $secret, wp_salt('auth') . '|zaas-device');
    }

    /**
     * Issues a fresh device secret, stores only its HMAC, sets the cookie,
     * and (re)binds it to $email. Called after link activation, PIN login,
     * OTP verification, and passkey login.
     */
    public function bind_device($email, $device_name = '') {
        global $wpdb;
        $email = sanitize_email($email);
        $secret = bin2hex(random_bytes(32));
        $hash = $this->device_secret_hash($secret);
        $settings = $this->p()->settings();

        $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p()->devices_table} WHERE device_hash=%s", $hash));
        if (!$existing) {
            $this->enforce_device_limit($email, (int) $settings['max_devices']);
            $wpdb->insert($this->p()->devices_table, [
                'customer_email'  => $email,
                'device_hash'     => $hash,
                'device_name'     => $device_name ?: $this->guess_device_name($ua),
                'user_agent_hash' => hash('sha256', $ua),
                'ip_prefix'       => $this->ip_prefix(),
                'created_at'      => current_time('mysql', true),
                'last_seen_at'    => current_time('mysql', true),
            ]);
        }

        $days = max(1, (int) $settings['session_days']);
        $expire = time() + $days * DAY_IN_SECONDS;
        $cookie_args = ['expires' => $expire, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'];
        setcookie(self::DEVICE_COOKIE, $secret, $cookie_args);
        $_COOKIE[self::DEVICE_COOKIE] = $secret;
        $this->resolved_email = $email;
        $this->resolved_email_checked = true;
        return true;
    }

    private function enforce_device_limit($email, $max_devices) {
        if ($max_devices <= 0) { return; }
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p()->devices_table} WHERE customer_email=%s AND revoked=0",
            $email
        ));
        if ($count >= $max_devices) {
            $oldest = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->p()->devices_table} WHERE customer_email=%s AND revoked=0 ORDER BY last_seen_at ASC LIMIT 1",
                $email
            ));
            if ($oldest) {
                $wpdb->update($this->p()->devices_table, ['revoked' => 1, 'revoked_at' => current_time('mysql', true), 'revoke_reason' => 'device_limit'], ['id' => $oldest]);
            }
        }
    }

    private function guess_device_name($ua) {
        if (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false) { return 'iOS устройство'; }
        if (stripos($ua, 'android') !== false) { return 'Android устройство'; }
        if (stripos($ua, 'windows') !== false) { return 'Windows'; }
        if (stripos($ua, 'mac os') !== false) { return 'Mac'; }
        return 'Браузер';
    }

    private function ip_prefix() {
        $ip = $this->p()->client_ip();
        if (strpos($ip, '.') !== false) {
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3)) . '.0/24';
        }
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::/64';
        }
        return $ip;
    }

    /**
     * Resolves the current visitor's verified email from the device
     * cookie, if any. Cheap in-request memoization since this is called
     * from template_redirect gating and from shortcodes on the same page.
     */
    public function resolve_customer_email() {
        if ($this->resolved_email_checked) { return $this->resolved_email; }
        $this->resolved_email_checked = true;
        $this->resolved_email = null;

        $secret = isset($_COOKIE[self::DEVICE_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::DEVICE_COOKIE])) : '';
        if ($secret !== '' && preg_match('/^[a-f0-9]{64}$/', $secret)) {
            global $wpdb;
            $hash = $this->device_secret_hash($secret);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->p()->devices_table} WHERE device_hash=%s AND revoked=0 LIMIT 1", $hash
            ));
            if ($row) {
                $wpdb->update($this->p()->devices_table, ['last_seen_at' => current_time('mysql', true)], ['id' => $row->id]);
                $this->resolved_email = $row->customer_email;
                return $this->resolved_email;
            }
        }

        // No (or a stale) device cookie: if the visitor is already logged
        // into WordPress some other way (wp-login.php, or the password
        // login AJAX handler earlier in this same request) and their
        // account email actually owns an active grant, adopt that
        // identity and silently bind this browser as a device too, so
        // the next request resolves straight from the cookie.
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (is_email($user->user_email)) {
                global $wpdb;
                $has_grant = (bool) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' LIMIT 1", $user->user_email
                ));
                if ($has_grant) {
                    $this->resolved_email = $user->user_email;
                    if (!headers_sent()) { $this->bind_device($user->user_email); }
                    return $this->resolved_email;
                }
            }
        }

        return null;
    }

    public function logout_device() {
        $secret = isset($_COOKIE[self::DEVICE_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::DEVICE_COOKIE])) : '';
        if ($secret !== '') {
            global $wpdb;
            $wpdb->update($this->p()->devices_table, ['revoked' => 1, 'revoked_at' => current_time('mysql', true), 'revoke_reason' => 'logout'], ['device_hash' => $this->device_secret_hash($secret)]);
        }
        setcookie(self::DEVICE_COOKIE, '', time() - YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN);
        unset($_COOKIE[self::DEVICE_COOKIE]);
        $this->resolved_email = null;
        $this->resolved_email_checked = true;
    }

    /* ------------------------------------------------------------------ *
     *  Route handlers
     * ------------------------------------------------------------------ */

    public function handle_activation() {
        $token = get_query_var('zaas_activate');
        if (!$token) { return; }
        nocache_headers();
        $grant = $this->activate_token($token);
        if (is_wp_error($grant)) {
            wp_die(esc_html($grant->get_error_message()), 'Доступ', ['response' => 410]);
        }
        wp_safe_redirect($this->entry_url($grant->access_uid));
        exit;
    }

    public function handle_entry() {
        $uid = get_query_var('zaas_entry');
        if (!$uid) { return; }
        nocache_headers();
        global $wpdb;
        $grant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p()->grants_table} WHERE access_uid=%s AND status='active' LIMIT 1", $uid
        ));
        if (!$grant) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $email = $this->resolve_customer_email();
        if ($email && strtolower($email) === strtolower($grant->customer_email)) {
            $settings = $this->p()->settings();
            $library = absint($settings['library_page_id']);
            wp_safe_redirect($library ? get_permalink($library) : home_url('/'));
            exit;
        }
        // Device cookie missing/mismatched: fall through to render the
        // page (library page shortcode shows the recovery/PIN login form).
    }

    /**
     * Gates any page listed in a purchased product's `_zaas_content_pages`
     * meta: visitors without an active grant are redirected to the library
     * page (which renders the login/recovery form).
     */
    public function protect_pages() {
        if (!is_singular('page')) { return; }
        global $post;
        if (!$post) { return; }
        $product_ids = get_post_meta($post->ID, '_zaas_product_ids', true);
        if (empty($product_ids) || !is_array($product_ids)) { return; }

        $email = $this->resolve_customer_email();
        $allowed = false;
        foreach ($product_ids as $pid) {
            if ($this->has_active_access($pid, $email)) { $allowed = true; break; }
        }
        if ($allowed) { return; }

        nocache_headers();
        $settings = $this->p()->settings();
        $library = absint($settings['library_page_id']);
        if ($library && $library !== $post->ID) {
            wp_safe_redirect(get_permalink($library));
            exit;
        }
    }

    public function noindex_protected_pages() {
        if (!is_singular('page')) { return; }
        global $post;
        if ($post && get_post_meta($post->ID, '_zaas_product_ids', true)) {
            echo '<meta name="robots" content="noindex,nofollow">' . "\n";
        }
    }

    /* ------------------------------------------------------------------ *
     *  Shortcodes
     * ------------------------------------------------------------------ */

    public function library_shortcode($atts = []) {
        nocache_headers();
        $email = $this->resolve_customer_email();
        if (!$email) {
            return $this->auth_shortcode($atts);
        }

        $grants = $this->grants_for_email($email);
        ob_start();
        $settings = $this->p()->settings();
        echo '<div class="zaas-interface zaas-library">';
        echo '<div class="zaas-library-head"><h2>Мои аудиокниги</h2><div class="zaas-library-head-actions">';
        if (!empty($settings['passkey_prompt_enabled']) && ZAAS_WebAuthn::instance()->is_available()) {
            echo '<button type="button" class="zaas-btn zaas-btn-secondary" data-zaas-passkey-enroll>Добавить Passkey</button> ';
        }
        echo '<button type="button" class="zaas-btn zaas-btn-ghost" data-zaas-logout>Выйти на этом устройстве</button>';
        echo '</div></div>';
        if (!$grants) {
            echo '<p class="zaas-muted">Пока нет активных доступов.</p>';
        } else {
            echo '<div class="zaas-library-grid">';
            foreach ($grants as $grant) {
                $product = function_exists('wc_get_product') ? wc_get_product($grant->product_id) : null;
                if (!$product) { continue; }
                $pages = get_post_meta($grant->product_id, '_zaas_content_pages', true);
                $target = is_array($pages) && $pages ? get_permalink((int) $pages[0]) : '#';
                echo '<a class="zaas-card zaas-book-card" href="' . esc_url($target) . '">';
                echo wp_kses_post($product->get_image('medium'));
                echo '<div class="zaas-book-title">' . esc_html($product->get_name()) . '</div>';
                if ($grant->access_expires_at) {
                    echo '<div class="zaas-book-expiry">до ' . esc_html(mysql2date('d.m.Y', $grant->access_expires_at)) . '</div>';
                }
                echo '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * The wrapped content is only ever shown to a visitor this browser
     * already recognizes (device cookie or a genuine WP login). Anyone
     * else sees the login gate itself, not a "buy" prompt — matching how
     * the original zau_audio widget worked: the widget IS the lock, and
     * it only opens via PIN or username/password (or the email-link
     * recovery flow), never by simply clicking past it. A "buy" prompt
     * only appears once we know who the visitor is and know for a fact
     * they don't own this particular product.
     */
    public function protected_shortcode($atts, $content = '') {
        $atts = shortcode_atts(['product_id' => 0, 'methods' => '', 'show_recovery' => 'yes'], (array) $atts);
        $product_id = absint($atts['product_id']);
        $email = $this->resolve_customer_email();
        if (!$email) { return $this->auth_shortcode($atts); }
        if ($product_id && !$this->has_active_access($product_id, $email)) {
            return $this->buy_button_shortcode(['product_id' => $product_id]);
        }
        return do_shortcode($content);
    }

    public function buy_button_shortcode($atts = []) {
        if (!function_exists('wc_get_product')) { return ''; }
        $atts = shortcode_atts(['product_id' => 0, 'text' => 'Купить доступ'], $atts);
        $product_id = absint($atts['product_id']);
        $product = $product_id ? wc_get_product($product_id) : null;
        $url = $product ? $product->add_to_cart_url() : wc_get_page_permalink('shop');
        return '<a class="zaas-btn zaas-btn-primary zaas-buy-button" href="' . esc_url($url) . '">' . esc_html($atts['text']) . '</a>';
    }

    const AUTH_METHOD_LABELS = ['recovery' => 'Ссылка на почту', 'pin' => 'Вход по PIN', 'password' => 'Логин и пароль'];

    /**
     * Login gate shown whenever the visitor isn't recognized — this is
     * the whole widget/shortcode when it's used standalone ([zaas_auth]),
     * and it's also what audio_shortcode()/protected_shortcode() fall
     * back to instead of a "buy" prompt, so a protected-audio widget acts
     * as its own lock: it only opens via one of the enabled methods
     * below, never by simply not being logged in.
     *
     * `methods` (comma list of recovery/pin/password) lets a caller (an
     * Elementor widget instance, typically) narrow which methods show;
     * it can only intersect with what's globally enabled in settings,
     * never re-enable something switched off site-wide.
     */
    public function auth_shortcode($atts = []) {
        $atts = shortcode_atts(['methods' => '', 'show_recovery' => 'yes'], (array) $atts);
        $settings = $this->p()->settings();

        $global_methods = ['recovery'];
        if (!empty($settings['pin_enabled'])) { $global_methods[] = 'pin'; }
        if (!empty($settings['auth_password_enabled'])) { $global_methods[] = 'password'; }

        $wanted = $atts['methods'] !== '' ? array_filter(array_map('trim', explode(',', $atts['methods']))) : $global_methods;
        $methods = array_values(array_intersect($global_methods, $wanted));
        if ($atts['show_recovery'] === 'no') { $methods = array_values(array_diff($methods, ['recovery'])); }
        if (!$methods) { $methods = ['recovery']; } // never render a dead-end with zero usable tabs
        $active_method = $methods[0];

        ob_start();
        echo '<div class="zaas-interface zaas-auth" data-zaas-auth>';

        if (count($methods) > 1) {
            echo '<div class="zaas-auth-tabs" role="tablist">';
            foreach ($methods as $m) {
                echo '<button type="button" class="zaas-tab' . ($m === $active_method ? ' is-active' : '') . '" data-zaas-tab="' . esc_attr($m) . '">' . esc_html(self::AUTH_METHOD_LABELS[$m]) . '</button>';
            }
            echo '</div>';
        }

        if (in_array('recovery', $methods, true)) {
            echo '<div class="zaas-tab-panel' . ('recovery' === $active_method ? ' is-active' : '') . '" data-zaas-panel="recovery">';
            echo '<p class="zaas-muted">Введите email, указанный при покупке — вышлем ссылку для входа.</p>';
            echo '<form class="zaas-form" data-zaas-recovery-form>';
            echo '<label>Email<input type="email" name="email" required></label>';
            echo '<div class="zaas-otp-step" data-zaas-otp-step hidden><label>Код из письма<input type="text" inputmode="numeric" name="code" maxlength="6"></label></div>';
            echo '<button type="submit" class="zaas-btn zaas-btn-primary">Получить доступ</button>';
            echo '<div class="zaas-form-message" data-zaas-message aria-live="polite"></div>';
            echo '</form>';
            echo '</div>';
        }

        if (in_array('password', $methods, true)) {
            echo '<div class="zaas-tab-panel' . ('password' === $active_method ? ' is-active' : '') . '" data-zaas-panel="password">';
            echo '<form class="zaas-form" data-zaas-password-form>';
            echo '<label>Email или логин<input type="text" name="login" required autocomplete="username"></label>';
            echo '<label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>';
            echo '<button type="submit" class="zaas-btn zaas-btn-primary">Войти</button>';
            echo '<div class="zaas-form-message" data-zaas-message aria-live="polite"></div>';
            echo '</form>';
            echo '</div>';
        }

        // PIN panel is supplied by ZAAS_PIN so this class doesn't need to
        // know PIN internals; it checks $methods/$active_method itself.
        echo apply_filters('zaas_auth_extra_tabs', '', ['methods' => $methods, 'active' => $active_method]);

        echo '</div>';
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ *
     *  AJAX: email-link recovery (request + verify -> binds device)
     * ------------------------------------------------------------------ */

    public function ajax_zaas_request_recovery() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) { wp_send_json_error(['message' => 'Введите корректный email.'], 400); }

        global $wpdb;
        $has_grant = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' LIMIT 1", $email
        ));
        // Always send the generic response regardless of $has_grant to avoid
        // leaking which emails have purchased something.
        if ($has_grant) {
            ZAAS_OTP::instance()->send(
                $email, 'entry_recovery', 'Код для входа в личный кабинет',
                "Ваш код для входа: {code}\nОн действует ограниченное время. Если вы не запрашивали код, просто игнорируйте письмо."
            );
        }
        wp_send_json_success(['message' => 'Если этот email покупал у нас книгу, мы выслали код подтверждения.']);
    }

    public function ajax_zaas_verify_recovery() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = sanitize_email($_POST['email'] ?? '');
        $code = sanitize_text_field($_POST['code'] ?? '');
        $result = ZAAS_OTP::instance()->verify($email, 'entry_recovery', $code);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }

        global $wpdb;
        $has_grant = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' LIMIT 1", $email
        ));
        if (!$has_grant) { wp_send_json_error(['message' => 'Активный доступ не найден.'], 404); }

        $this->bind_device($email);
        $this->p()->log('otp_login', 'device', 0, $email);
        $settings = $this->p()->settings();
        $library = absint($settings['library_page_id']);
        wp_send_json_success(['message' => 'Вход выполнен.', 'redirect' => $library ? get_permalink($library) : home_url('/')]);
    }

    /**
     * WordPress username/password login as an access method (the third
     * method the original audio-lock widgets offered alongside PIN and
     * the emailed link). Requires the authenticated account's email to
     * actually have an active grant — a valid WP password alone isn't
     * enough, exactly like PIN/OTP login also check for a grant.
     */
    public function ajax_zaas_password_login() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $settings = $this->p()->settings();
        if (empty($settings['auth_password_enabled'])) {
            wp_send_json_error(['message' => 'Вход по логину и паролю отключён.'], 400);
        }

        $login = sanitize_text_field(wp_unslash($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($login === '' || $password === '') {
            wp_send_json_error(['message' => 'Введите логин/email и пароль.'], 400);
        }

        $rate_key = 'zaas_pwd_lock_' . md5(strtolower($login) . '|' . $this->p()->client_ip());
        $state = get_transient($rate_key);
        $state = is_array($state) ? $state : ['attempts' => 0];
        if ($state['attempts'] >= 5) {
            wp_send_json_error(['message' => 'Слишком много попыток. Попробуйте позже.'], 429);
        }

        $user = wp_authenticate(sanitize_user($login), $password);
        if (is_wp_error($user)) {
            $state['attempts']++;
            set_transient($rate_key, $state, 15 * MINUTE_IN_SECONDS);
            wp_send_json_error(['message' => 'Неверный логин или пароль.'], 400);
        }
        delete_transient($rate_key);

        $email = $user->user_email;
        global $wpdb;
        $has_grant = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' LIMIT 1", $email
        ));
        if (!$has_grant) {
            wp_send_json_error(['message' => 'Для этой учётной записи не найден активный доступ к аудиокнигам.'], 404);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);

        $this->bind_device($email);
        $this->p()->log('password_login', 'device', 0, $email);
        $library = absint($settings['library_page_id']);
        wp_send_json_success(['message' => 'Вход выполнен.', 'redirect' => $library ? get_permalink($library) : home_url('/')]);
    }

    public function ajax_zaas_logout() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $this->logout_device();
        wp_send_json_success(['redirect' => home_url('/')]);
    }

    public function ajax_zaas_refresh_nonce() {
        wp_send_json_success(['nonce' => wp_create_nonce(ZAAS_Plugin::NONCE)]);
    }

    /* ------------------------------------------------------------------ *
     *  Assets
     * ------------------------------------------------------------------ */

    public function public_assets() {
        $ver = ZAAS_Plugin::VERSION;
        wp_enqueue_style('zaas-theme', ZAAS_PLUGIN_URL . 'assets/css/theme.css', [], $ver);
        wp_enqueue_style('zaas-public', ZAAS_PLUGIN_URL . 'assets/css/public.css', ['zaas-theme'], $ver);
        wp_enqueue_script('zaas-theme', ZAAS_PLUGIN_URL . 'assets/js/theme.js', [], $ver, true);
        wp_enqueue_script('zaas-public', ZAAS_PLUGIN_URL . 'assets/js/public.js', ['zaas-theme'], $ver, true);

        $settings = $this->p()->settings();
        wp_localize_script('zaas-theme', 'ZAASTheme', [
            'accent'     => sanitize_hex_color($settings['design_accent']) ?: '#1565C0',
            'accentDark' => sanitize_hex_color($settings['design_accent_dark']) ?: '#0D47A1',
            'surface'    => sanitize_hex_color($settings['design_surface']) ?: '#FFFFFF',
            'text'       => sanitize_hex_color($settings['design_text']) ?: '#152033',
            'radius'     => max(0, min(40, (int) $settings['design_radius'])),
            'enabled'    => !empty($settings['design_enabled']),
        ]);
        wp_localize_script('zaas-public', 'ZAASData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(ZAAS_Plugin::NONCE),
            'pinEnabled' => !empty($settings['pin_enabled']),
        ]);
    }

    /**
     * Daily housekeeping (mirrors WCSAA's wcsaa_daily_cleanup cron):
     * expires pending grants whose activation window ran out, marks
     * expired 'active' grants, and prunes long-revoked devices/passkeys
     * so the admin lists don't grow forever. Hooked to the same
     * 'zaas_daily_cleanup' event ZAAS_OTP schedules, so there is only
     * one wp_schedule_event() call for the whole plugin.
     */
    public function cleanup_expired() {
        global $wpdb;
        $p = $this->p();

        $wpdb->query(
            "UPDATE {$p->grants_table} SET status='expired', updated_at=UTC_TIMESTAMP()
             WHERE status='pending' AND activation_expires_at IS NOT NULL AND activation_expires_at < UTC_TIMESTAMP()"
        );
        $wpdb->query(
            "UPDATE {$p->grants_table} SET status='expired', updated_at=UTC_TIMESTAMP()
             WHERE status='active' AND access_expires_at IS NOT NULL AND access_expires_at < UTC_TIMESTAMP()"
        );
        $wpdb->query(
            "DELETE FROM {$p->devices_table} WHERE revoked=1 AND revoked_at IS NOT NULL AND revoked_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)"
        );
        $wpdb->query(
            "DELETE FROM {$p->passkeys_table} WHERE revoked=1"
        );
        $wpdb->query(
            "DELETE FROM {$p->stream_locks_table} WHERE updated_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        );
    }
}
