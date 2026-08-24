<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Permanent PIN login, modeled directly on the union-registry plugin's
 * "постоянный PIN участника" pattern (ZAU_Union_Module):
 *  - PIN is customer-chosen, digits only, length bounded by settings.
 *  - Stored only as a bcrypt/phpass hash (wp_hash_password), never plain.
 *  - Login is rate-limited per (identity, ip) via a transient lockout,
 *    exactly like the reference plugin's zau_pin_lock_<hash> transient.
 *  - Forgot-PIN goes through the shared OTP mailer (channel 'pin_reset').
 *  - Optional "device-only" mode: once a device is bound, the identifier
 *    field is skipped entirely and only the PIN is asked for.
 */
final class ZAAS_PIN {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_filter('zaas_auth_extra_tabs', [$this, 'render_pin_tab'], 10, 2);

        foreach (['zaas_pin_set', 'zaas_pin_login', 'zaas_pin_request_reset', 'zaas_pin_reset'] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    private function p() { return ZAAS_Plugin::instance(); }
    private function table() { return $this->p()->pins_table; }

    /* ------------------------------------------------------------------ *
     *  Validation / storage
     * ------------------------------------------------------------------ */

    private function validate_pin_value($pin) {
        $settings = $this->p()->settings();
        $pin = preg_replace('/\D/', '', (string) $pin);
        $min = max(4, (int) $settings['pin_min_length']);
        $max = max($min, (int) $settings['pin_max_length']);
        if (strlen($pin) < $min || strlen($pin) > $max) {
            return new WP_Error('zaas_pin_length', sprintf('PIN должен содержать от %d до %d цифр.', $min, $max));
        }
        return $pin;
    }

    public function has_pin($email) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE customer_email=%s", sanitize_email($email)
        ));
    }

    public function set_pin($email, $pin) {
        $pin = $this->validate_pin_value($pin);
        if (is_wp_error($pin)) { return $pin; }
        global $wpdb;
        $email = sanitize_email($email);
        $hash = wp_hash_password($pin);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE customer_email=%s", $email));
        if ($existing) {
            $wpdb->update($this->table(), ['pin_hash' => $hash, 'updated_at' => current_time('mysql', true)], ['id' => $existing]);
        } else {
            $wpdb->insert($this->table(), [
                'customer_email' => $email, 'pin_hash' => $hash,
                'set_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true),
            ]);
        }
        $this->p()->log('pin_set', 'pin', 0, $email);
        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Rate limiting (mirrors zau_pin_lock_<hash> transient pattern)
     * ------------------------------------------------------------------ */

    private function lock_key($identity) {
        return 'zaas_pin_lock_' . md5(strtolower((string) $identity) . '|' . $this->p()->client_ip());
    }

    private function is_locked($identity) {
        $state = get_transient($this->lock_key($identity));
        return is_array($state) && !empty($state['locked']);
    }

    private function register_failure($identity) {
        $settings = $this->p()->settings();
        $max_attempts = max(1, (int) $settings['pin_max_attempts']);
        $lock_minutes = max(1, (int) $settings['pin_lock_minutes']);
        $key = $this->lock_key($identity);
        $state = get_transient($key);
        $state = is_array($state) ? $state : ['attempts' => 0, 'locked' => false];
        $state['attempts']++;
        if ($state['attempts'] >= $max_attempts) { $state['locked'] = true; }
        set_transient($key, $state, $lock_minutes * MINUTE_IN_SECONDS);
    }

    private function clear_lock($identity) {
        delete_transient($this->lock_key($identity));
    }

    /* ------------------------------------------------------------------ *
     *  Login
     * ------------------------------------------------------------------ */

    /**
     * $identity is an email when the visitor has no device cookie yet;
     * when pin_device_only is enabled and a device cookie is present, the
     * identity is resolved from the device instead (no email field shown).
     */
    public function login($identity, $pin) {
        $settings = $this->p()->settings();
        if (empty($settings['pin_enabled'])) { return new WP_Error('zaas_pin_disabled', 'Вход по PIN отключён.'); }

        $email = null;
        if (!empty($settings['pin_device_only'])) {
            $email = ZAAS_Access::instance()->resolve_customer_email();
        }
        if (!$email) {
            $email = sanitize_email($identity);
            if (!is_email($email)) { return new WP_Error('zaas_pin_identity', 'Укажите email, использованный при покупке.'); }
        }

        if ($this->is_locked($email)) {
            return new WP_Error('zaas_pin_locked', 'Слишком много попыток. Попробуйте позже.', ['status' => 429]);
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE customer_email=%s", $email));
        if (!$row || !wp_check_password((string) $pin, $row->pin_hash)) {
            $this->register_failure($email);
            return new WP_Error('zaas_pin_invalid', 'Неверный PIN.');
        }

        $has_grant = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p()->grants_table} WHERE customer_email=%s AND status='active' LIMIT 1", $email
        ));
        if (!$has_grant) {
            $this->register_failure($email);
            return new WP_Error('zaas_pin_no_access', 'Активный доступ не найден.');
        }

        $this->clear_lock($email);
        ZAAS_Access::instance()->bind_device($email);
        $this->p()->log('pin_login', 'pin', 0, $email);
        return $email;
    }

    /* ------------------------------------------------------------------ *
     *  Forgot PIN -> OTP -> set new PIN
     * ------------------------------------------------------------------ */

    public function request_reset($email) {
        return ZAAS_OTP::instance()->send(
            $email, 'pin_reset', 'Код для сброса PIN',
            "Код для установки нового PIN: {code}\nЕсли вы не запрашивали смену PIN, проигнорируйте письмо."
        );
    }

    public function reset_with_code($email, $code, $new_pin) {
        $verified = ZAAS_OTP::instance()->verify($email, 'pin_reset', $code);
        if (is_wp_error($verified)) { return $verified; }
        $set = $this->set_pin($email, $new_pin);
        if (is_wp_error($set)) { return $set; }
        ZAAS_Access::instance()->bind_device($email);
        $this->p()->log('pin_reset_login', 'pin', 0, $email);
        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Front-end markup
     * ------------------------------------------------------------------ */

    public function render_pin_tab($extra, $atts) {
        $settings = $this->p()->settings();
        if (empty($settings['pin_enabled'])) { return $extra; }

        $device_only = !empty($settings['pin_device_only']) && ZAAS_Access::instance()->resolve_customer_email();

        ob_start();
        echo '<div class="zaas-tab-panel" data-zaas-panel="pin">';
        echo '<form class="zaas-form" data-zaas-pin-login-form>';
        if (!$device_only) {
            echo '<label>Email<input type="email" name="identity" required></label>';
        }
        echo '<label>PIN-код<input type="password" name="pin" inputmode="numeric" autocomplete="one-time-code" required></label>';
        echo '<button type="submit" class="zaas-btn zaas-btn-primary">Войти по PIN</button>';
        echo '<button type="button" class="zaas-btn zaas-btn-link" data-zaas-pin-forgot>Забыли PIN?</button>';
        echo '<div class="zaas-form-message" data-zaas-message aria-live="polite"></div>';
        echo '</form>';

        echo '<form class="zaas-form" data-zaas-pin-reset-form hidden>';
        echo '<label>Email<input type="email" name="email" required></label>';
        echo '<button type="button" class="zaas-btn zaas-btn-secondary" data-zaas-pin-send-code>Выслать код</button>';
        echo '<div class="zaas-otp-step" data-zaas-otp-step hidden>';
        echo '<label>Код из письма<input type="text" name="code" inputmode="numeric" maxlength="6"></label>';
        echo '<label>Новый PIN<input type="password" name="new_pin" inputmode="numeric"></label>';
        echo '<button type="submit" class="zaas-btn zaas-btn-primary">Установить новый PIN</button>';
        echo '</div>';
        echo '<div class="zaas-form-message" data-zaas-message aria-live="polite"></div>';
        echo '</form>';
        echo '</div>';

        return $extra . ob_get_clean();
    }

    /* ------------------------------------------------------------------ *
     *  AJAX
     * ------------------------------------------------------------------ */

    public function ajax_zaas_pin_set() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = ZAAS_Access::instance()->resolve_customer_email();
        if (!$email) { wp_send_json_error(['message' => 'Сначала выполните вход по ссылке из письма.'], 401); }
        $result = $this->set_pin($email, $_POST['pin'] ?? '');
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        wp_send_json_success(['message' => 'PIN сохранён.']);
    }

    public function ajax_zaas_pin_login() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $result = $this->login($_POST['identity'] ?? '', $_POST['pin'] ?? '');
        if (is_wp_error($result)) {
            $status = $result->get_error_data('status') ?: 400;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }
        $settings = $this->p()->settings();
        $library = absint($settings['library_page_id']);
        wp_send_json_success(['message' => 'Вход выполнен.', 'redirect' => $library ? get_permalink($library) : home_url('/')]);
    }

    public function ajax_zaas_pin_request_reset() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) { wp_send_json_error(['message' => 'Некорректный email.'], 400); }
        $this->request_reset($email);
        wp_send_json_success(['message' => 'Если этот email покупал у нас книгу, мы выслали код для сброса PIN.']);
    }

    public function ajax_zaas_pin_reset() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = sanitize_email($_POST['email'] ?? '');
        $code = sanitize_text_field($_POST['code'] ?? '');
        $new_pin = sanitize_text_field($_POST['new_pin'] ?? '');
        $result = $this->reset_with_code($email, $code, $new_pin);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        $settings = $this->p()->settings();
        $library = absint($settings['library_page_id']);
        wp_send_json_success(['message' => 'PIN обновлён, вход выполнен.', 'redirect' => $library ? get_permalink($library) : home_url('/')]);
    }
}
