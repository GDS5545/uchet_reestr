<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Single shared one-time-code mailer/verifier, used both for the "forgot my
 * access link" recovery flow (channel 'entry_recovery') and PIN reset
 * (channel 'pin_reset'). Consolidates what WCSAA and ZSPA each implemented
 * separately as near-identical 6-digit-code flows.
 */
final class ZAAS_OTP {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('zaas_daily_cleanup', [$this, 'cleanup_expired']);
        if (!wp_next_scheduled('zaas_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'zaas_daily_cleanup');
        }
    }

    private function table() {
        return ZAAS_Plugin::instance()->otp_table;
    }

    private function destination_hash($destination, $channel) {
        return hash('sha256', $channel . '|' . strtolower(trim((string) $destination)));
    }

    /**
     * Sends a 6-digit code to $email for $channel. Rate-limited to one send
     * per 60 seconds per (destination, channel, ip) to prevent mail-bombing.
     * Always returns true on the "no such account" case too -- callers must
     * not let this leak whether an email exists.
     */
    public function send($email, $channel, $subject, $message_template) {
        $email = sanitize_email($email);
        if (!is_email($email)) { return new WP_Error('zaas_otp_email', 'Некорректный email.'); }

        $rate_key = 'zaas_otp_send_' . md5($channel . '|' . $email . '|' . ZAAS_Plugin::instance()->client_ip());
        if (get_transient($rate_key)) {
            return new WP_Error('zaas_otp_rate', 'Код уже отправлен. Подождите минуту и попробуйте снова.');
        }
        set_transient($rate_key, 1, 60);

        $settings = ZAAS_Plugin::instance()->settings();
        $code = str_pad((string) wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl_minutes = max(1, (int) $settings['otp_minutes']);

        global $wpdb;
        $wpdb->insert($this->table(), [
            'destination_hash' => $this->destination_hash($email, $channel),
            'channel'          => sanitize_key($channel),
            'code_hash'        => wp_hash_password($code),
            'attempts'         => 0,
            'verified'         => 0,
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + $ttl_minutes * MINUTE_IN_SECONDS),
            'ip'               => ZAAS_Plugin::instance()->client_ip(),
            'created_at'       => current_time('mysql', true),
        ]);

        $body = str_replace('{code}', $code, $message_template);
        wp_mail($email, $subject, $body);
        return true;
    }

    /**
     * Verifies $code for ($email, $channel). On success marks the row
     * verified and returns true; on failure increments attempts and
     * returns a WP_Error. Enforces settings()['otp_max_attempts'].
     */
    public function verify($email, $channel, $code) {
        global $wpdb;
        $email = sanitize_email($email);
        $code = preg_replace('/\D/', '', (string) $code);
        if ($code === '') { return new WP_Error('zaas_otp_code', 'Введите код из письма.'); }

        $settings = ZAAS_Plugin::instance()->settings();
        $max_attempts = max(1, (int) $settings['otp_max_attempts']);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE destination_hash=%s AND channel=%s AND verified=0 AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",
            $this->destination_hash($email, $channel), sanitize_key($channel)
        ));

        if (!$row) { return new WP_Error('zaas_otp_missing', 'Код не найден или истёк. Запросите новый.'); }
        if ((int) $row->attempts >= $max_attempts) { return new WP_Error('zaas_otp_locked', 'Слишком много попыток. Запросите новый код.'); }

        if (!wp_check_password($code, $row->code_hash)) {
            $wpdb->update($this->table(), ['attempts' => (int) $row->attempts + 1], ['id' => $row->id]);
            return new WP_Error('zaas_otp_invalid', 'Неверный код.');
        }

        $wpdb->update($this->table(), ['verified' => 1], ['id' => $row->id]);
        return true;
    }

    public function cleanup_expired() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$this->table()} WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)");
    }
}
