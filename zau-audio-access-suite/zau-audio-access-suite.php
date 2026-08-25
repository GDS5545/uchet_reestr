<?php
/**
 * Plugin Name: ZAU Аудиодоступ — объединённый доступ к аудиокнигам
 * Description: Единый доступ к платным аудиокнигам WooCommerce: постоянная ссылка + устройство, вход по PIN и Passkey, шифрованное хранение и потоковая отдача файлов, оплата Kaspi, интеграция amoCRM, перенос старых доступов, виджеты и стили Elementor.
 * Version: 1.2.0
 * Author: Dauren / ZAU
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: zau-audio-access-suite
 */

if (!defined('ABSPATH')) { exit; }

define('ZAAS_PLUGIN_FILE', __FILE__);
define('ZAAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZAAS_PLUGIN_URL', plugin_dir_url(__FILE__));

final class ZAAS_Plugin {

    const VERSION = '1.2.0';
    const DB_VERSION = '1.2.0';
    const OPT_DB_VERSION = 'zaas_db_version';
    const OPT_SETTINGS = 'zaas_settings';
    const CAP_MANAGE = 'zaas_manage_access';
    const NONCE = 'zaas_nonce';

    private static $instance = null;

    /** @var wpdb */
    public $wpdb;

    public $grants_table;
    public $devices_table;
    public $passkeys_table;
    public $otp_table;
    public $stream_locks_table;
    public $log_table;
    public $pins_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->grants_table       = $wpdb->prefix . 'zaas_grants';
        $this->devices_table      = $wpdb->prefix . 'zaas_devices';
        $this->passkeys_table     = $wpdb->prefix . 'zaas_passkeys';
        $this->otp_table          = $wpdb->prefix . 'zaas_otp';
        $this->stream_locks_table = $wpdb->prefix . 'zaas_stream_locks';
        $this->log_table          = $wpdb->prefix . 'zaas_log';
        $this->pins_table         = $wpdb->prefix . 'zaas_pins';

        add_action('plugins_loaded', [$this, 'load_modules'], 5);
        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 20);
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compat']);
        add_action('init', [$this, 'load_textdomain']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('zau-audio-access-suite', false, dirname(plugin_basename(ZAAS_PLUGIN_FILE)) . '/languages');
    }

    public function declare_hpos_compat() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ZAAS_PLUGIN_FILE, true);
        }
    }

    public function load_modules() {
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-install.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-otp.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-access.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-pin.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-webauthn.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-stream.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-amocrm.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-legacy-import.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-admin.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-elementor.php';
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-pwa.php';

        ZAAS_OTP::instance();
        ZAAS_Access::instance();
        ZAAS_PIN::instance();
        ZAAS_WebAuthn::instance();
        ZAAS_Stream::instance();
        if (class_exists('WooCommerce')) {
            // WC_Gateway_ZAAS_Kaspi (in class-zaas-kaspi.php) extends
            // WC_Payment_Gateway at file scope, so both the require and
            // the woocommerce.php glue must stay behind this guard —
            // loading them when WooCommerce is inactive would fatal.
            require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-woocommerce.php';
            require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-kaspi.php';
            ZAAS_WooCommerce::instance();
            ZAAS_Kaspi::instance();
        }
        ZAAS_AmoCRM::instance();
        ZAAS_Legacy_Import::instance();
        ZAAS_Admin::instance();
        ZAAS_Elementor::init();
        ZAAS_PWA::instance();

        do_action('zaas_loaded');
    }

    public static function activate() {
        require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-install.php';
        ZAAS_Install::install();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            require_once ZAAS_PLUGIN_DIR . 'includes/class-zaas-install.php';
            ZAAS_Install::install();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    /**
     * Single settings array for the whole plugin (access policy, PIN policy,
     * Kaspi, amoCRM, and design tokens). Always merged over defaults so a
     * plugin update that adds a new key never yields an undefined index.
     */
    public function settings() {
        $defaults = [
            // access / entry links
            'library_page_id'        => 0,
            'default_access_days'    => 90,
            'default_activation_hours' => 72,
            'max_devices'             => 2,
            'session_days'            => 365,
            'simultaneous_streams'    => 1,
            'strict_user_agent'      => 0,

            // OTP recovery
            'otp_minutes'             => 15,
            'otp_max_attempts'        => 5,

            // PIN (permanent, union-style)
            'pin_enabled'             => 1,
            'pin_setup_mode'          => 'optional', // off|optional|required
            'pin_min_length'          => 4,
            'pin_max_length'          => 8,
            'pin_max_attempts'        => 5,
            'pin_lock_minutes'        => 15,
            'pin_device_only'         => 0,
            'auth_password_enabled'   => 1,

            // passkeys
            'passkeys_enabled'        => 1,
            'passkey_prompt_enabled'  => 1,

            // PWA (optional, off by default — see class-zaas-pwa.php)
            'pwa_enabled'             => 0,

            // Kaspi gateway settings live under WooCommerce > Payments > Kaspi
            // (standard WC_Payment_Gateway option storage) — see
            // class-zaas-kaspi.php. Nothing Kaspi-specific is duplicated here.

            // amoCRM
            'amo_account_domain'      => '',
            'amo_client_id'           => '',
            'amo_client_secret'       => '',
            'amo_redirect_uri'        => admin_url('admin.php?page=zaas-amocrm'),
            'amo_pipeline_id'         => '',
            'amo_new_status_id'       => '',
            'amo_approved_status_id'  => '',
            'amo_webhook_secret'      => '',
            'amo_access_token'        => '',
            'amo_refresh_token'       => '',
            'amo_token_expires_at'    => 0,
            'amo_auto_create_lead'    => 1,

            // design tokens (applied as CSS custom properties, see assets/css/theme.css)
            'design_accent'           => '#1565C0',
            'design_accent_dark'      => '#0D47A1',
            'design_surface'          => '#FFFFFF',
            'design_text'             => '#152033',
            'design_radius'           => 14,
            'design_enabled'          => 1,
        ];
        return wp_parse_args((array) get_option(self::OPT_SETTINGS, []), $defaults);
    }

    public function update_settings(array $partial) {
        $settings = wp_parse_args($partial, $this->settings());
        update_option(self::OPT_SETTINGS, $settings, false);
        return $settings;
    }

    public function log($action, $object_type = '', $object_id = 0, $details = '') {
        $this->wpdb->insert($this->log_table, [
            'user_id'     => get_current_user_id(),
            'action'      => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id'   => absint($object_id),
            'details'     => is_scalar($details) ? (string) $details : wp_json_encode($details),
            'ip'          => $this->client_ip(),
            'created_at'  => current_time('mysql'),
        ]);
    }

    public function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return substr(sanitize_text_field(wp_unslash($ip)), 0, 64);
    }
}

register_activation_hook(__FILE__, ['ZAAS_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['ZAAS_Plugin', 'deactivate']);

ZAAS_Plugin::instance();
