<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One-shot migration tool for sites that ran the old plugin set. Reads
 * from whichever of the legacy stores are actually present and copies
 * matching rows into the canonical `zaas_grants` table as already-active
 * grants (no activation-link click required — these customers already
 * had working access).
 *
 * Sources understood:
 *  - {prefix}wcsaa_grants           (wc-secure-audio-access's own table)
 *  - option `zau_audio_lock_temp_access` (ZAU core / bridge / ZSPA rows)
 *  - user meta `_wkqaa_book_access` (Woo Kaspi QR amoCRM Access)
 *
 * Deliberately NOT migrated (see readme.txt "Не перенесено"): WCSAA
 * device/passkey records (customers simply re-bind on first visit),
 * the bespoke ZSPA ECDSA device keys, and the ZAURPL01 Sodium-encrypted
 * legacy audio format — those require the original plugin's own tooling.
 */
final class ZAAS_Legacy_Import {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_zaas_legacy_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_zaas_legacy_import_batch', [$this, 'ajax_import_batch']);
    }

    private function p() { return ZAAS_Plugin::instance(); }

    public function sources_available() {
        global $wpdb;
        $wcsaa_table = $wpdb->prefix . 'wcsaa_grants';
        $has_wcsaa = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wcsaa_table)) === $wcsaa_table;

        $temp_access = get_option('zau_audio_lock_temp_access', []);
        $has_zau = is_array($temp_access) && !empty($temp_access);

        $has_wkqaa = (bool) $wpdb->get_var("SELECT meta_id FROM {$wpdb->usermeta} WHERE meta_key='_wkqaa_book_access' LIMIT 1");

        return ['wcsaa' => $has_wcsaa, 'zau_temp' => $has_zau, 'wkqaa' => $has_wkqaa];
    }

    public function ajax_scan() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_send_json_error(['message' => 'Недостаточно прав.'], 403); }

        global $wpdb;
        $counts = [];

        $available = $this->sources_available();
        if ($available['wcsaa']) {
            $counts['wcsaa'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wcsaa_grants WHERE status='active'");
        }
        if ($available['zau_temp']) {
            $temp_access = get_option('zau_audio_lock_temp_access', []);
            $counts['zau_temp'] = is_array($temp_access) ? count($temp_access) : 0;
        }
        if ($available['wkqaa']) {
            $counts['wkqaa'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='_wkqaa_book_access'");
        }

        wp_send_json_success(['available' => $available, 'counts' => $counts]);
    }

    public function ajax_import_batch() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_send_json_error(['message' => 'Недостаточно прав.'], 403); }

        $source = sanitize_key($_POST['source'] ?? '');
        $offset = absint($_POST['offset'] ?? 0);
        $limit = 50;

        switch ($source) {
            case 'wcsaa':   $result = $this->import_wcsaa_batch($offset, $limit); break;
            case 'zau_temp': $result = $this->import_zau_temp_batch($offset, $limit); break;
            case 'wkqaa':   $result = $this->import_wkqaa_batch($offset, $limit); break;
            default: wp_send_json_error(['message' => 'Неизвестный источник импорта.'], 400);
        }

        wp_send_json_success($result);
    }

    private function import_wcsaa_batch($offset, $limit) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcsaa_grants';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status='active' ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
        ));
        $created = 0; $skipped = 0;
        foreach ($rows as $row) {
            $r = ZAAS_Access::instance()->import_active_grant([
                'order_id' => $row->order_id, 'order_item_id' => $row->order_item_id, 'product_id' => $row->product_id,
                'customer_id' => $row->customer_id, 'customer_email' => $row->customer_email,
                'access_expires_at' => $row->access_expires_at, 'source' => 'legacy_wcsaa',
            ]);
            if (!is_wp_error($r) && $r['created']) { $created++; } else { $skipped++; }
        }
        return ['processed' => count($rows), 'created' => $created, 'skipped' => $skipped, 'done' => count($rows) < $limit];
    }

    private function import_zau_temp_batch($offset, $limit) {
        $temp_access = get_option('zau_audio_lock_temp_access', []);
        if (!is_array($temp_access)) { $temp_access = []; }
        $slice = array_slice($temp_access, $offset, $limit);
        $created = 0; $skipped = 0;

        foreach ($slice as $entry) {
            if (!is_array($entry) || empty($entry['user_id'])) { $skipped++; continue; }
            $user = get_userdata((int) $entry['user_id']);
            if (!$user || !is_email($user->user_email)) { $skipped++; continue; }

            $product_ids = [];
            if (!empty($entry['product_id'])) { $product_ids[] = (int) $entry['product_id']; }
            if (!empty($entry['product_ids']) && is_array($entry['product_ids'])) {
                foreach ($entry['product_ids'] as $pid) { $product_ids[] = (int) $pid; }
            }
            $product_ids = array_unique(array_filter($product_ids));
            if (!$product_ids) { $skipped++; continue; }

            $expires = !empty($entry['expires']) ? gmdate('Y-m-d H:i:s', (int) $entry['expires']) : null;

            foreach ($product_ids as $product_id) {
                $r = ZAAS_Access::instance()->import_active_grant([
                    'customer_id' => $user->ID, 'customer_email' => $user->user_email,
                    'product_id' => $product_id, 'access_expires_at' => $expires, 'source' => 'legacy_zau_temp',
                ]);
                if (!is_wp_error($r) && $r['created']) { $created++; } else { $skipped++; }
            }
        }
        return ['processed' => count($slice), 'created' => $created, 'skipped' => $skipped, 'done' => count($slice) < $limit];
    }

    private function import_wkqaa_batch($offset, $limit) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key='_wkqaa_book_access' ORDER BY umeta_id ASC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        $created = 0; $skipped = 0;

        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);
            if (!$user || !is_email($user->user_email)) { $skipped++; continue; }
            $entries = maybe_unserialize($row->meta_value);
            if (!is_array($entries)) { $skipped++; continue; }

            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['product_id'])) { $skipped++; continue; }
                $r = ZAAS_Access::instance()->import_active_grant([
                    'order_id' => $entry['order_id'] ?? 0, 'order_item_id' => $entry['item_id'] ?? 0,
                    'product_id' => (int) $entry['product_id'], 'customer_id' => $user->ID, 'customer_email' => $user->user_email,
                    'source' => 'legacy_wkqaa',
                ]);
                if (!is_wp_error($r) && $r['created']) { $created++; } else { $skipped++; }
            }
        }
        return ['processed' => count($rows), 'created' => $created, 'skipped' => $skipped, 'done' => count($rows) < $limit];
    }
}
