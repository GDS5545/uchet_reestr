<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Schema installer. Re-run idempotently by ZAAS_Plugin::maybe_upgrade() on
 * every version bump (not only on activation) so a plain zip-replace
 * deployment that skips WordPress's activation hook still gets new columns.
 */
final class ZAAS_Install {

    public static function install() {
        self::install_tables();
        self::install_capabilities();
        self::ensure_pages();
        self::ensure_protected_directory();
    }

    private static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = ZAAS_Plugin::instance();

        $sql = [];

        $sql[] = "CREATE TABLE {$p->grants_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_email varchar(190) NOT NULL,
            token_hash char(64) NOT NULL,
            access_uid char(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            activation_expires_at datetime NULL,
            activated_at datetime NULL,
            access_expires_at datetime NULL,
            device_hash char(64) NULL,
            last_seen_at datetime NULL,
            email_sent_at datetime NULL,
            source varchar(40) NOT NULL DEFAULT 'woocommerce',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_item_product_email (order_id, order_item_id, product_id, customer_email),
            KEY customer_email (customer_email),
            KEY access_uid (access_uid),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->devices_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_email varchar(190) NOT NULL,
            device_hash char(64) NOT NULL,
            session_hash char(64) NULL,
            device_name varchar(190) NULL,
            user_agent_hash char(64) NULL,
            ip_prefix varchar(64) NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            revoked_at datetime NULL,
            revoke_reason varchar(190) NULL,
            created_at datetime NOT NULL,
            last_seen_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY device_hash (device_hash),
            KEY customer_email (customer_email)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->passkeys_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_email varchar(190) NOT NULL,
            credential_hash char(64) NOT NULL,
            credential_id text NOT NULL,
            public_key text NOT NULL,
            sign_count bigint(20) unsigned NOT NULL DEFAULT 0,
            transports varchar(190) NULL,
            label varchar(190) NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY credential_hash (credential_hash),
            KEY customer_email (customer_email)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->otp_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            destination_hash char(64) NOT NULL,
            channel varchar(30) NOT NULL DEFAULT 'entry_recovery',
            code_hash varchar(255) NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            verified tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            ip varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY destination_channel (destination_hash, channel),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->stream_locks_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_email varchar(190) NOT NULL,
            device_id varchar(64) NOT NULL,
            audio_key varchar(190) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email_device_audio (customer_email, device_id, audio_key),
            KEY updated_at (updated_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->pins_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_email varchar(190) NOT NULL,
            pin_hash varchar(255) NOT NULL,
            set_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY customer_email (customer_email)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p->log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(60) NOT NULL,
            object_type varchar(40) NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details text NULL,
            ip varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset;";

        foreach ($sql as $statement) { dbDelta($statement); }
    }

    private static function install_capabilities() {
        $caps = ['read' => true, ZAAS_Plugin::CAP_MANAGE => true, 'upload_files' => true];
        add_role('zaas_access_manager', 'Менеджер аудиодоступа', $caps);
        $manager = get_role('zaas_access_manager');
        if ($manager) { foreach ($caps as $cap => $grant) { if ($grant) { $manager->add_cap($cap); } } }
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap(ZAAS_Plugin::CAP_MANAGE); }
    }

    /**
     * Auto-create the library landing page (where [zaas_library] lives) if
     * the admin hasn't picked one yet, mirroring the reference plugin's
     * ensure_verify_page() pattern.
     */
    private static function ensure_pages() {
        $p = ZAAS_Plugin::instance();
        $settings = $p->settings();
        $page_id = absint($settings['library_page_id']);
        if ($page_id && get_post($page_id)) { return; }

        $existing = get_page_by_path('moi-audioknigi');
        if ($existing) {
            $page_id = $existing->ID;
        } else {
            $page_id = wp_insert_post([
                'post_title'   => 'Мои аудиокниги',
                'post_name'    => 'moi-audioknigi',
                'post_content' => '[zaas_library]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
        }
        if (!is_wp_error($page_id) && $page_id) {
            $p->update_settings(['library_page_id' => (int) $page_id]);
        }
    }

    /**
     * Create the non-web-accessible upload directory used for encrypted
     * audio storage and drop an .htaccess deny-all inside it (Nginx hosts
     * must add an equivalent `location` block manually — documented in
     * readme.txt).
     */
    public static function ensure_protected_directory() {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'zaas-protected-audio';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }
}
