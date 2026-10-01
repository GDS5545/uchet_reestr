<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Точный перенос 1:1 со старого сайта (Ultimate Member + WPForms).
 *
 * Прежние инструменты «угадывали» человека по ФИО/email/телефону/ИИН и
 * собирали профиль из ВСЕХ заявлений, прикреплённых к старому аккаунту. Если
 * с одного аккаунта подавали заявления за нескольких людей (председатель,
 * ответственный, родственник), в профиль владельца попадали чужие ФИО, ИИН и
 * организация. Здесь правила другие и не допускают догадок:
 *
 *  1. Аккаунт = старый WordPress User ID. Существующий аккаунт нового сайта
 *     используется только при точном совпадении email (на обоих сайтах email
 *     уникален, поэтому связь всегда один-к-одному).
 *  2. Профиль заполняется данными самого аккаунта Ultimate Member; из
 *     заявлений — только пустые поля и только когда ФИО в заявлении совпадает
 *     с владельцем аккаунта.
 *  3. КАЖДАЯ запись WPForms переносится отдельной архивной заявкой без
 *     объединения и без удаления «дублей». Заявка, поданная с аккаунта за
 *     другого человека, остаётся у подавшего с пометкой и не влияет на профиль.
 *  4. Каждая запись хранит исходную копию и SHA-256. Сверка сравнивает её с
 *     живыми данными старого сайта и показывает, перенесено ли 100%.
 *  5. Пользователь в кабинете может отметить «это не моё» и «в профиле чужие
 *     данные»; администратор разбирает такие отметки в очереди.
 */
final class ZAU_Exact_Migration {
    const VERSION = '2.35.2';
    const DB_VERSION = '1.2.0';
    const OPT_DB = 'zau_exact_migration_db_version';
    const OPT = 'zau_exact_migration_settings';
    const JOB_OPT = 'zau_exact_migration_job';
    const FORMS_OPT = 'zau_exact_migration_forms';
    const LAST_CLEANUP_OPT = 'zau_exact_migration_last_cleanup';
    const LOCK_OPT = 'zau_exact_migration_lock';
    const LOCK_TTL = 180;
    const NONCE = 'zau_exact_migration_nonce';
    const PUBLIC_NONCE = 'zau_exact_public_nonce';
    const ROUTE_BASE = '/zau-legacy-exact/v1';

    const S_OWN = 'legacy';
    const S_OTHER = 'legacy_other';
    const S_DISPUTED = 'legacy_disputed';
    const S_UNASSIGNED = 'legacy_unassigned';
    const S_SUPERSEDED = 'legacy_superseded';
    const S_HIDDEN = 'legacy_hidden';

    private static $instance = null;
    private $map_table;
    private $report_table;
    private $submissions_table;
    private $forms_table;
    private $docs_index_table;
    private $docs_table;
    private $form_cache = [];

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    /** Статусы архивных заявок: не показываются в списке «последняя заявка по форме». */
    public static function archive_statuses() {
        return [self::S_OWN, self::S_OTHER, self::S_DISPUTED, self::S_UNASSIGNED, self::S_SUPERSEDED, self::S_HIDDEN];
    }

    private function __construct() {
        global $wpdb;
        $this->map_table = $wpdb->prefix . 'zau_exact_map';
        $this->report_table = $wpdb->prefix . 'zau_exact_report';
        $this->docs_index_table = $wpdb->prefix . 'zau_exact_docs';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 39);
        add_action('admin_menu', [$this, 'admin_menu'], 40);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_zau_exact_save', [$this, 'save_settings']);
        add_action('admin_post_zau_exact_report', [$this, 'download_report']);
        add_action('admin_post_zau_exact_queue_action', [$this, 'queue_action']);
        add_action('admin_post_zau_exact_audit_action', [$this, 'audit_action']);
        add_action('admin_post_zau_exact_audit_csv', [$this, 'audit_csv']);
        add_action('wp_ajax_zau_exact_audit_run', [$this, 'ajax_audit_run']);
        add_action('wp_ajax_zau_exact_namefix_run', [$this, 'ajax_namefix_run']);
        add_action('wp_ajax_zau_exact_regen_run', [$this, 'ajax_regen_run']);
        add_action('admin_post_zau_exact_regen_save', [$this, 'regen_save']);
        add_action('admin_post_zau_exact_regen_csv', [$this, 'regen_csv']);

        add_action('wp_ajax_zau_exact_test', [$this, 'ajax_test']);
        add_action('wp_ajax_zau_exact_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_exact_process', [$this, 'ajax_process']);
        add_action('wp_ajax_zau_exact_reset', [$this, 'ajax_reset']);

        add_action('wp_ajax_zau_exact_submission_action', [$this, 'ajax_submission_action']);
        add_action('wp_ajax_zau_exact_profile_dispute', [$this, 'ajax_profile_dispute']);
        add_action('wp_enqueue_scripts', [$this, 'public_assets']);
        add_filter('zau_union_cabinet_submissions_after', [$this, 'cabinet_archive_html'], 10, 2);
        add_filter('zau_union_member_card_after', [$this, 'member_card_after_html'], 10, 3);
        add_filter('zau_union_prefill_field_value', [$this, 'prefill_field_value'], 10, 3);
        add_filter('zau_union_form_before_fields', [$this, 'form_prefill_notice'], 10, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Установка                                                          */
    /* ------------------------------------------------------------------ */

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB) !== self::DB_VERSION) {
            $this->install_tables();
            $this->migrate_site_hash();
            $this->backfill_docs_index();
            update_option(self::OPT_DB, self::DB_VERSION, false);
        }
    }

    private function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->map_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_hash varchar(64) NOT NULL,
            source_type varchar(10) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            checksum varchar(64) NOT NULL DEFAULT '',
            decision varchar(40) NOT NULL DEFAULT '',
            manual tinyint(1) NOT NULL DEFAULT 0,
            message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source (site_hash,source_type,source_id),
            KEY target_user_id (target_user_id),
            KEY target_submission_id (target_submission_id),
            KEY decision (decision)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->report_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_token varchar(64) NOT NULL,
            source_type varchar(20) NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            decision varchar(40) NOT NULL,
            message text NULL,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY job_decision (job_token,decision),
            KEY job_source (job_token,source_type,source_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->docs_index_table} (
            doc_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            run varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (doc_id),
            KEY user_id (user_id),
            KEY submission_id (submission_id),
            KEY run (run)
        ) $charset;");
    }

    /** Индекс документов, созданных из перенесённых заявлений (для реестра и итогов без медленного поиска по JSON). */
    public static function docs_index_table() {
        global $wpdb;
        return $wpdb->prefix . 'zau_exact_docs';
    }

    private function backfill_docs_index() {
        global $wpdb;
        $docs = $wpdb->prefix . 'zau_certificates';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $docs)) !== $docs) { return; }
        $ph = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->docs_index_table} (doc_id,user_id,submission_id,run,created_at)
             SELECT d.id,d.user_id,d.source_submission_id,'',%s FROM {$docs} d JOIN {$this->submissions_table} s ON s.id=d.source_submission_id AND s.status IN ($ph)
             WHERE d.data_json LIKE %s",
            array_merge([current_time('mysql')], self::archive_statuses(), ['%"legacy_exact_regen":1%'])
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Общие помощники                                                    */
    /* ------------------------------------------------------------------ */

    private function can_manage() {
        return current_user_can('manage_options') || (class_exists('ZAU_Certificate_PDF_Generator') && current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE));
    }

    private function require_access() {
        if (!$this->can_manage()) { wp_send_json_error(['message'=>'Недостаточно прав.'], 403); }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function settings() {
        $bridge = (array)get_option('zau_remote_bridge_settings', []);
        return wp_parse_args((array)get_option(self::OPT, []), [
            'old_url'=>(string)($bridge['old_url'] ?? ''),
            'secret'=>'',
            'batch_size'=>40,
            'default_status'=>'Состоит в профсоюзе',
            'create_users'=>1,
            'preserve_password'=>1,
            'skip_admins'=>1,
            'download_files'=>1,
        ]);
    }

    /**
     * Ключ старого сайта: только имя хоста без www, поэтому смена http↔https,
     * «www.» или слэша в настройках не «теряет» уже перенесённые записи.
     */
    private function site_hash($url = null) {
        $url = strtolower(trim((string)($url === null ? $this->settings()['old_url'] : $url)));
        $host = (string)wp_parse_url(strpos($url, '://') === false ? 'https://' . $url : $url, PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', $host);
        return hash('sha256', 'host:' . ($host !== '' ? $host : $url));
    }

    /** До 2.28.3 ключ считался от полного адреса. Если источник был один — переводим его записи на новый ключ. */
    private function migrate_site_hash() {
        global $wpdb;
        if ((string)$this->settings()['old_url'] === '') { return; }
        $new = $this->site_hash();
        $hashes = (array)$wpdb->get_col("SELECT DISTINCT site_hash FROM {$this->map_table}");
        if (count($hashes) === 1 && $hashes[0] !== $new) {
            $wpdb->update($this->map_table, ['site_hash'=>$new], ['site_hash'=>$hashes[0]]);
        }
    }

    private function progress_counts() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT source_type,COUNT(*) c FROM {$this->map_table} WHERE site_hash=%s GROUP BY source_type", $this->site_hash()));
        $out = ['user'=>0, 'entry'=>0, 'other_sources'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->map_table} WHERE site_hash<>%s", $this->site_hash()))];
        foreach ((array)$rows as $r) { $out[$r->source_type] = (int)$r->c; }
        return $out;
    }

    private static function canonicalize(&$value) {
        if (!is_array($value)) { return; }
        foreach ($value as &$item) { self::canonicalize($item); }
        unset($item);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
    }

    /** Точно такая же функция работает в мосте старого сайта. */
    public static function checksum(array $item) {
        unset($item['checksum']);
        $normalized = json_decode(wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        if (!is_array($normalized)) { $normalized = []; }
        self::canonicalize($normalized);
        return hash('sha256', (string)wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Для аккаунтов хеш пароля не хранится в копии, поэтому сверяется сумма без него. */
    private static function user_compare_checksum(array $item) {
        unset($item['password_hash'], $item['checksum']);
        return self::checksum($item);
    }

    private function lower($value) {
        $value = (string)$value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Приводит слово ФИО к единому виду только для сравнения:
     * казахские буквы → русские (ұ→у, қ→к…), латинские «двойники» внутри
     * кириллического слова → кириллица (Беловa, Cауле), слово целиком латиницей →
     * транслитерация (Natbaeva → натбаева), затем й/ы → и, без ъ/ь и удвоенных букв.
     */
    private function fold_name_token($token) {
        static $kaz = ['ә'=>'а','ғ'=>'г','қ'=>'к','ң'=>'н','ө'=>'о','ұ'=>'у','ү'=>'у','һ'=>'х','і'=>'и','ё'=>'е','э'=>'е'];
        static $look = ['a'=>'а','b'=>'в','c'=>'с','e'=>'е','h'=>'н','k'=>'к','m'=>'м','o'=>'о','p'=>'р','t'=>'т','x'=>'х','y'=>'у','i'=>'і'];
        static $tr2 = ['shch'=>'щ','sh'=>'ш','ch'=>'ч','zh'=>'ж','kh'=>'х','ts'=>'ц','ya'=>'я','yu'=>'ю','yo'=>'е','ye'=>'е','ia'=>'ия'];
        static $tr1 = ['a'=>'а','b'=>'б','v'=>'в','g'=>'г','d'=>'д','e'=>'е','z'=>'з','i'=>'и','y'=>'ы','j'=>'ж','k'=>'к','l'=>'л','m'=>'м','n'=>'н','o'=>'о','p'=>'п','r'=>'р','s'=>'с','t'=>'т','u'=>'у','f'=>'ф','h'=>'х','c'=>'ц','w'=>'у','q'=>'к','x'=>'кс'];
        $hasLat = (bool)preg_match('/[a-z]/', $token);
        $hasCyr = (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $token);
        if ($hasLat && $hasCyr) { $token = strtr($token, $look); }
        elseif ($hasLat) { $token = strtr(strtr($token, $tr2), $tr1); }
        $token = strtr($token, $kaz);
        $token = strtr($token, ['й'=>'и','ы'=>'и','ъ'=>'','ь'=>'']);
        return (string)preg_replace('/(.)\1+/u', '$1', $token);
    }

    private function name_tokens($value) {
        $value = $this->lower(wp_strip_all_tags((string)$value));
        $value = preg_replace('/[^\p{L}]+/u', ' ', $value);
        $tokens = [];
        foreach (preg_split('/\s+/u', trim((string)$value)) as $token) {
            $token = $this->fold_name_token($token);
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len >= 2) { $tokens[$token] = 1; }
        }
        return array_keys($tokens);
    }

    private function normalize_label($value) {
        $value = str_replace('ё', 'е', $this->lower(wp_strip_all_tags((string)$value)));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', (string)$value));
    }

    private function scalar_text($value) {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($v) use (&$flat) { if (is_scalar($v) && trim((string)$v) !== '') { $flat[] = trim((string)$v); } });
            return implode(', ', array_unique($flat));
        }
        return trim((string)$value);
    }

    private function meta_first(array $meta, $key) {
        if (!isset($meta[$key])) { return ''; }
        $value = is_array($meta[$key]) ? reset($meta[$key]) : $meta[$key];
        return is_string($value) ? $value : (string)$value;
    }

    private function normalize_phone($value) {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (strlen($digits) === 10) { $digits = '7' . $digits; }
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '8') { $digits = '7' . substr($digits, 1); }
        return (strlen($digits) >= 10 && strlen($digits) <= 15) ? '+' . $digits : '';
    }

    private function mysql_date($value) {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) { return ''; }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return $value; }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    private function get_map($type, $sourceId) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->map_table} WHERE site_hash=%s AND source_type=%s AND source_id=%d LIMIT 1",
            $this->site_hash(), $type, (int)$sourceId
        ));
    }

    private function save_map($type, $sourceId, array $fields) {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->get_map($type, $sourceId);
        $fields['updated_at'] = $now;
        if ($existing) {
            $wpdb->update($this->map_table, $fields, ['id'=>(int)$existing->id]);
            return (int)$existing->id;
        }
        $wpdb->insert($this->map_table, array_merge([
            'site_hash'=>$this->site_hash(), 'source_type'=>$type, 'source_id'=>(int)$sourceId,
            'target_user_id'=>0, 'target_submission_id'=>0, 'checksum'=>'', 'decision'=>'', 'manual'=>0, 'message'=>'',
            'created_at'=>$now,
        ], $fields));
        return (int)$wpdb->insert_id;
    }

    private function report($job, $type, $sourceId, $decision, $message = '', $userId = 0, $submissionId = 0, $details = null) {
        global $wpdb;
        $wpdb->insert($this->report_table, [
            'job_token'=>(string)$job['token'],
            'source_type'=>$type,
            'source_id'=>(int)$sourceId,
            'decision'=>$decision,
            'message'=>$message,
            'target_user_id'=>(int)$userId,
            'target_submission_id'=>(int)$submissionId,
            'details'=>$details === null ? null : wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'=>current_time('mysql'),
        ]);
    }

    private function update_user_json_meta($userId, $key, $value) {
        // update_user_meta() снимает слэши — без wp_slash() JSON с кавычками внутри значений испортился бы.
        update_user_meta($userId, $key, wp_slash(wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private function user_json_meta($userId, $key) {
        $value = json_decode((string)get_user_meta($userId, $key, true), true);
        return is_array($value) ? $value : [];
    }

    /* ------------------------------------------------------------------ */
    /* Связь со старым сайтом                                             */
    /* ------------------------------------------------------------------ */

    private function remote_get($route, array $query = []) {
        $s = $this->settings();
        if (empty($s['old_url']) || empty($s['secret'])) {
            return new WP_Error('zau_exact_settings', 'Укажите адрес старого сайта и секретный ключ моста 2.0.');
        }
        $signedRoute = self::ROUTE_BASE . '/' . ltrim($route, '/');
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $base = untrailingslashit($s['old_url']);
        $urls = [
            $base . '/wp-json' . $signedRoute . ($queryString !== '' ? '?' . $queryString : ''),
            // Запасной адрес для сайтов без «красивых» постоянных ссылок.
            $base . '/?rest_route=' . rawurlencode($signedRoute) . ($queryString !== '' ? '&' . $queryString : ''),
        ];
        $lastError = null;
        foreach ($urls as $url) {
            $timestamp = (string)time();
            $signature = hash_hmac('sha256', $timestamp . "\nGET\n" . $signedRoute . "\n" . $queryString, (string)$s['secret']);
            $response = wp_remote_get($url, [
                'timeout'=>90,
                'redirection'=>2,
                'headers'=>['X-ZAU-Timestamp'=>$timestamp, 'X-ZAU-Signature'=>$signature, 'Accept'=>'application/json'],
            ]);
            if (is_wp_error($response)) { $lastError = $response; continue; }
            $code = (int)wp_remote_retrieve_response_code($response);
            $body = (string)wp_remote_retrieve_body($response);
            $json = json_decode($body, true);
            if ($code === 404) { $lastError = new WP_Error('zau_exact_404', 'На старом сайте не найден мост 2.0 (zau-legacy-exact). Установите файл old-site-bridge/zau-legacy-exact-export.php как плагин на старом сайте.'); continue; }
            if ($code < 200 || $code >= 300) {
                return new WP_Error('zau_exact_http', is_array($json) && !empty($json['message']) ? (string)$json['message'] : ('Старый сайт вернул HTTP ' . $code));
            }
            if (!is_array($json)) { return new WP_Error('zau_exact_json', 'Старый сайт вернул некорректный ответ (не JSON).'); }
            return $json;
        }
        return $lastError ?: new WP_Error('zau_exact_http', 'Старый сайт недоступен.');
    }

    /* ------------------------------------------------------------------ */
    /* Администрирование                                                  */
    /* ------------------------------------------------------------------ */

    public function admin_menu() {
        add_submenu_page(null, 'Точный перенос 1:1', 'Точный перенос 1:1', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-exact-migration', [$this, 'page']);
        add_submenu_page(null, 'Проверка ФИО и подписей', 'Проверка ФИО и подписей', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-exact-audit', [$this, 'audit_page']);
        add_submenu_page(null, 'Пересоздание перенесённых заявлений', 'Пересоздание перенесённых заявлений', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-exact-regen', [$this, 'regen_page']);
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-exact-migration') === false && strpos((string)$hook, 'zau-exact-audit') === false && strpos((string)$hook, 'zau-exact-regen') === false) { return; }
        wp_enqueue_style('zau-remote-bridge', plugins_url('../assets/css/remote-bridge.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('zau-exact-migration', plugins_url('../assets/css/exact-migration.css', __FILE__), ['zau-remote-bridge'], self::VERSION);
        wp_enqueue_script('zau-exact-migration', plugins_url('../assets/js/exact-migration.js', __FILE__), [], self::VERSION, true);
        wp_localize_script('zau-exact-migration', 'ZAUExactMigration', [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
            'job'=>($job = (array)get_option(self::JOB_OPT, [])) ? $this->public_job($job) : null,
        ]);
    }

    public function save_settings() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $url = esc_url_raw(trim((string)wp_unslash($_POST['old_url'] ?? '')));
        $current = $this->settings();
        $secret = sanitize_text_field(wp_unslash($_POST['secret'] ?? ''));
        update_option(self::OPT, [
            'old_url'=>$url ? untrailingslashit($url) : '',
            'secret'=>$secret !== '' ? $secret : (string)$current['secret'],
            'batch_size'=>max(10, min(100, absint($_POST['batch_size'] ?? 40))),
            'default_status'=>sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'Состоит в профсоюзе')),
            'create_users'=>!empty($_POST['create_users']) ? 1 : 0,
            'preserve_password'=>!empty($_POST['preserve_password']) ? 1 : 0,
            'skip_admins'=>!empty($_POST['skip_admins']) ? 1 : 0,
            'download_files'=>!empty($_POST['download_files']) ? 1 : 0,
        ], false);
        $this->migrate_site_hash();
        wp_safe_redirect(add_query_arg(['page'=>'zau-exact-migration', 'saved'=>1], admin_url('admin.php')));
        exit;
    }

    public function ajax_test() {
        $this->require_access();
        $status = $this->remote_get('/status');
        if (is_wp_error($status)) { wp_send_json_error(['message'=>$status->get_error_message()], 400); }
        wp_send_json_success(['message'=>sprintf(
            'Мост %s подключён. Аккаунтов: %d, записей WPForms: %s, форм: %d.',
            (string)($status['bridge_version'] ?? '?'),
            (int)($status['user_count'] ?? 0),
            !empty($status['entries_supported']) ? (string)(int)($status['entry_count'] ?? 0) : 'таблица записей не найдена (WPForms Lite не хранит записи)',
            (int)($status['form_count'] ?? 0)
        )]);
    }

    private function modes() {
        return [
            'dry'=>'Пробный перенос',
            'import'=>'Перенос',
            'verify'=>'Сверка',
            'cleanup_preview'=>'Проверка прежних переносов',
            'cleanup_apply'=>'Скрытие дублей прежних переносов',
            'cleanup_rollback'=>'Откат скрытия',
        ];
    }

    public function ajax_start() {
        $this->require_access();
        $mode = sanitize_key((string)($_POST['mode'] ?? 'dry'));
        if (!isset($this->modes()[$mode])) { wp_send_json_error(['message'=>'Неизвестный режим.'], 400); }
        $current = (array)get_option(self::JOB_OPT, []);
        if (!empty($current['status']) && $current['status'] === 'running') {
            wp_send_json_error(['message'=>'Уже выполняется задание «' . ($this->modes()[$current['mode']] ?? '') . '». Дождитесь окончания или сбросьте его.'], 409);
        }
        $job = [
            'token'=>'exact-' . wp_generate_uuid4(),
            'mode'=>$mode,
            'status'=>'running',
            'cursor'=>0,
            'totals'=>['users'=>0, 'entries'=>0],
            'processed'=>['users'=>0, 'entries'=>0, 'profiles'=>0, 'submissions'=>0],
            'stats'=>[],
            'log'=>[],
            'started_at'=>current_time('mysql'),
            'finished_at'=>'',
        ];
        if (in_array($mode, ['dry', 'import', 'verify'], true)) {
            $status = $this->remote_get('/status');
            if (is_wp_error($status)) { wp_send_json_error(['message'=>$status->get_error_message()], 400); }
            if (($status['bridge'] ?? '') !== 'exact') { wp_send_json_error(['message'=>'На старом сайте нужен мост 2.0 (точный экспорт).'], 409); }
            $job['totals'] = ['users'=>(int)($status['user_count'] ?? 0), 'entries'=>(int)($status['entry_count'] ?? 0)];
            $job['phase'] = $mode === 'verify' ? 'v_users' : 'forms';
            $job['log'][] = $this->modes()[$mode] . ': аккаунтов на старом сайте ' . $job['totals']['users'] . ', записей WPForms ' . $job['totals']['entries'] . '.';
            if ($mode === 'dry') { $job['log'][] = 'Пробный режим: пользователи и заявки не создаются, только отчёт.'; }
        } elseif ($mode === 'cleanup_rollback') {
            $last = (string)get_option(self::LAST_CLEANUP_OPT, '');
            if ($last === '') { wp_send_json_error(['message'=>'Нет выполненного скрытия, которое можно откатить.'], 404); }
            $job['phase'] = 'rollback';
            $job['rollback_token'] = $last;
            $job['log'][] = 'Откат: возвращаются статусы и документы, изменённые при скрытии дублей.';
        } else {
            $job['phase'] = 'cleanup_index';
            $job['log'][] = $mode === 'cleanup_apply'
                ? 'Скрытие заявок прежних переносов, у которых есть точная копия. Ничего не удаляется.'
                : 'Проверка: какие заявки прежних переносов имеют точную копию. Изменений нет.';
        }
        update_option(self::JOB_OPT, $job, false);
        wp_send_json_success($this->public_job($job));
    }

    public function ajax_reset() {
        $this->require_access();
        delete_option(self::JOB_OPT);
        delete_option(self::LOCK_OPT);
        wp_send_json_success(['message'=>'Текущее задание сброшено. Перенесённые данные и отчёты не удалены.']);
    }

    private function acquire_lock() {
        $lock = (array)get_option(self::LOCK_OPT, []);
        if (!empty($lock['at']) && (time() - (int)$lock['at']) > self::LOCK_TTL) { delete_option(self::LOCK_OPT); }
        $token = wp_generate_uuid4();
        return add_option(self::LOCK_OPT, ['token'=>$token, 'at'=>time()], '', false) ? $token : '';
    }

    private function release_lock($token) {
        $lock = (array)get_option(self::LOCK_OPT, []);
        if (empty($lock['token']) || hash_equals((string)$lock['token'], (string)$token)) { delete_option(self::LOCK_OPT); }
    }

    private function add_stat(&$job, $key, $n = 1) {
        if (!isset($job['stats'][$key])) { $job['stats'][$key] = 0; }
        $job['stats'][$key] += $n;
    }

    public function ajax_process() {
        $this->require_access();
        $lock = $this->acquire_lock();
        if (!$lock) { wp_send_json_error(['message'=>'Предыдущая партия ещё обрабатывается.', 'retry_after'=>3], 409); }
        @set_time_limit(300);
        $job = (array)get_option(self::JOB_OPT, []);
        if (empty($job['token']) || ($job['status'] ?? '') !== 'running') {
            $this->release_lock($lock);
            wp_send_json_error(['message'=>'Активное задание не найдено.'], 404);
        }
        $token = (string)$job['token'];
        $result = $this->run_step($job);
        if (is_wp_error($result)) {
            $job['log'][] = 'Ошибка: ' . $result->get_error_message() . ' Повторите — обработка продолжится с того же места.';
        }
        if (count($job['log']) > 200) { $job['log'] = array_slice($job['log'], -200); }
        $check = (array)get_option(self::JOB_OPT, []);
        if ((string)($check['token'] ?? '') !== $token) {
            $this->release_lock($lock);
            wp_send_json_error(['message'=>'Задание сброшено в другой вкладке.'], 409);
        }
        update_option(self::JOB_OPT, $job, false);
        $this->release_lock($lock);
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message(), 'job'=>$this->public_job($job), 'retry_after'=>5], 502); }
        wp_send_json_success($this->public_job($job));
    }

    private function public_job($job) {
        return [
            'token'=>$job['token'] ?? '',
            'mode'=>$job['mode'] ?? '',
            'mode_label'=>$this->modes()[$job['mode'] ?? ''] ?? '',
            'status'=>$job['status'] ?? 'idle',
            'phase'=>$job['phase'] ?? '',
            'totals'=>$job['totals'] ?? [],
            'processed'=>$job['processed'] ?? [],
            'stats'=>$job['stats'] ?? [],
            'log'=>array_slice((array)($job['log'] ?? []), -40),
            'summary'=>$job['summary'] ?? null,
            'finished_at'=>$job['finished_at'] ?? '',
        ];
    }

    private function finish(&$job, $line) {
        $job['status'] = 'finished';
        $job['phase'] = 'finished';
        $job['finished_at'] = current_time('mysql');
        $job['log'][] = $line;
    }

    private function run_step(&$job) {
        $mode = (string)$job['mode'];
        $dry = $mode === 'dry';
        $batch = (int)$this->settings()['batch_size'];
        switch ((string)$job['phase']) {
            case 'forms':
                $payload = $this->remote_get('/forms');
                if (is_wp_error($payload)) { return $payload; }
                $forms = [];
                foreach ((array)($payload['forms'] ?? []) as $form) {
                    $fields = [];
                    foreach ((array)($form['fields'] ?? []) as $field) { $fields[(string)$field['id']] = ['label'=>(string)($field['label'] ?? ''), 'type'=>(string)($field['type'] ?? '')]; }
                    $forms[(int)$form['id']] = ['title'=>(string)($form['title'] ?? ''), 'fields'=>$fields];
                }
                update_option(self::FORMS_OPT, $forms, false);
                $job['log'][] = 'Получено форм WPForms: ' . count($forms) . '. Этап 1: аккаунты.';
                $job['phase'] = 'users';
                $job['cursor'] = 0;
                return true;

            case 'users':
            case 'v_users':
                $payload = $this->remote_get('/users', ['cursor'=>(int)$job['cursor'], 'per_page'=>$batch]);
                if (is_wp_error($payload)) { return $payload; }
                foreach ((array)($payload['items'] ?? []) as $item) {
                    if ($job['phase'] === 'v_users') { $this->verify_user((array)$item, $job); }
                    else { $this->process_user((array)$item, $job, $dry); }
                    $job['processed']['users']++;
                }
                $job['cursor'] = (int)($payload['next_cursor'] ?? $job['cursor']);
                if (empty($payload['has_more'])) {
                    $job['phase'] = $job['phase'] === 'v_users' ? 'v_entries' : 'dupcheck';
                    $job['cursor'] = 0;
                    $job['log'][] = $job['phase'] === 'dupcheck'
                        ? 'Аккаунты обработаны: ' . $job['processed']['users'] . '. Проверка новых аккаунтов на возможные дубли по ФИО.'
                        : 'Аккаунты обработаны: ' . $job['processed']['users'] . '. Этап 2: записи WPForms.';
                }
                return true;

            case 'entries':
            case 'v_entries':
                $perPage = (!$dry && $job['phase'] === 'entries' && !empty($this->settings()['download_files'])) ? max(5, (int)floor($batch / 2)) : $batch;
                $payload = $this->remote_get('/entries', ['cursor'=>(int)$job['cursor'], 'per_page'=>$perPage]);
                if (is_wp_error($payload)) { return $payload; }
                if (!empty($payload['unsupported'])) { $job['log'][] = 'На старом сайте нет таблицы записей WPForms — переносить нечего.'; }
                foreach ((array)($payload['items'] ?? []) as $item) {
                    if ($job['phase'] === 'v_entries') { $this->verify_entry((array)$item, $job); }
                    else { $this->process_entry((array)$item, $job, $dry); }
                    $job['processed']['entries']++;
                }
                $job['cursor'] = (int)($payload['next_cursor'] ?? $job['cursor']);
                if (empty($payload['has_more'])) {
                    $job['cursor'] = 0;
                    if ($job['phase'] === 'v_entries') {
                        $job['phase'] = 'v_orphans';
                    } elseif ($dry) {
                        $this->finish($job, 'Пробный перенос завершён. Скачайте отчёт и проверьте строки conflict, unassigned и other_person.');
                    } else {
                        $job['phase'] = 'profiles';
                        $job['log'][] = 'Записи перенесены: ' . $job['processed']['entries'] . '. Этап 3: пустые поля профилей из собственных заявлений.';
                    }
                }
                return true;

            case 'dupcheck':
                $done = $this->dupcheck_step($job, $dry);
                if ($done) {
                    $job['phase'] = 'entries';
                    $job['cursor'] = 0;
                    $job['log'][] = 'Этап 2: записи WPForms.';
                }
                return true;

            case 'profiles':
                global $wpdb;
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id,target_user_id FROM {$this->map_table} WHERE site_hash=%s AND source_type='user' AND target_user_id>0 AND id>%d ORDER BY id ASC LIMIT 50",
                    $this->site_hash(), (int)$job['cursor']
                ));
                foreach ((array)$rows as $row) {
                    $job['cursor'] = (int)$row->id;
                    $changed = $this->enrich_from_own_entries((int)$row->target_user_id, false);
                    if ($changed) { $this->add_stat($job, 'profile_filled'); }
                    $job['processed']['profiles']++;
                }
                if (count((array)$rows) < 50) {
                    $this->finish($job, 'Перенос завершён. Запустите «Сверку», чтобы убедиться, что перенесено 100% записей без искажений.');
                }
                return true;

            case 'v_orphans':
                $this->verify_orphans($job);
                $this->finish($job, 'Сверка завершена.');
                $job['summary'] = $this->verify_summary($job);
                return true;

            case 'cleanup_index':
                $this->cleanup_index_step($job);
                return true;

            case 'cleanup':
                $this->cleanup_step($job, $job['mode'] === 'cleanup_apply');
                return true;

            case 'rollback':
                $this->rollback_step($job);
                return true;
        }
        $this->finish($job, 'Задание завершено.');
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Аккаунты                                                           */
    /* ------------------------------------------------------------------ */

    private function old_user_name_tokens(array $item) {
        $meta = (array)($item['meta'] ?? []);
        $tokens = array_unique(array_merge(
            $this->name_tokens($this->meta_first($meta, 'last_name') . ' ' . $this->meta_first($meta, 'first_name')),
            $this->name_tokens((string)($item['display_name'] ?? ''))
        ));
        return array_values($tokens);
    }

    private function process_user(array $item, &$job, $dry) {
        $oldId = (int)($item['id'] ?? 0);
        if (!$oldId) { return; }
        $meta0 = (array)($item['meta'] ?? []);
        $tokens = ['tokens'=>$this->old_user_name_tokens($item), 'first'=>$this->meta_first($meta0, 'first_name'), 'last'=>$this->meta_first($meta0, 'last_name'), 'display'=>(string)($item['display_name'] ?? '')];
        if (self::checksum($item) !== (string)($item['checksum'] ?? '')) {
            $this->add_stat($job, 'transfer_error');
            $this->report($job, 'user', $oldId, 'transfer_error', 'Контрольная сумма не совпала: данные повреждены при передаче. Повторите перенос.');
            return;
        }
        $settings = $this->settings();
        $email = strtolower(trim((string)($item['user_email'] ?? '')));
        $email = is_email($email) ? $email : '';
        $compare = self::user_compare_checksum($item);
        if (!empty($settings['skip_admins']) && in_array('administrator', (array)($item['roles'] ?? []), true)) {
            $existing = $email ? get_user_by('email', $email) : false;
            $this->add_stat($job, 'skipped_admin');
            $this->report($job, 'user', $oldId, 'skipped_admin', 'Администратор старого сайта не переносится (настройка). Его записи WPForms попадут в «Без владельца».', $existing ? (int)$existing->ID : 0);
            return;
        }

        $map = $this->get_map('user', $oldId);
        $mappedUser = ($map && (int)$map->target_user_id) ? get_user_by('id', (int)$map->target_user_id) : false;
        if ($mappedUser) {
            if ((string)$map->checksum === $compare) {
                $this->add_stat($job, 'unchanged');
                $this->report($job, 'user', $oldId, 'unchanged', 'Аккаунт уже перенесён, изменений на старом сайте нет.', (int)$mappedUser->ID, 0, $tokens);
                return;
            }
            if ($dry) {
                $this->add_stat($job, 'would_update');
                $this->report($job, 'user', $oldId, 'would_update', 'Аккаунт на старом сайте изменился: обновится копия и заполнятся пустые поля.', (int)$mappedUser->ID, 0, $tokens);
                return;
            }
            $this->store_account_snapshot((int)$mappedUser->ID, $item);
            $this->fill_profile_from_account((int)$mappedUser->ID, $item, false);
            $this->save_map('user', $oldId, ['checksum'=>$compare, 'decision'=>'updated']);
            $this->add_stat($job, 'updated');
            $this->report($job, 'user', $oldId, 'updated', 'Копия аккаунта обновлена, заполнены только пустые поля.', (int)$mappedUser->ID, 0, $tokens);
            return;
        }

        $target = 0;
        $decision = '';
        $message = '';
        $priorMismatch = null;
        if ($email) {
            $existing = get_user_by('email', $email);
            if ($existing) {
                global $wpdb;
                $other = $wpdb->get_var($wpdb->prepare(
                    "SELECT source_id FROM {$this->map_table} WHERE site_hash=%s AND source_type='user' AND target_user_id=%d AND source_id<>%d LIMIT 1",
                    $this->site_hash(), (int)$existing->ID, $oldId
                ));
                if ($other) {
                    $this->add_stat($job, 'conflict');
                    $this->report($job, 'user', $oldId, 'conflict', 'Email ' . $email . ' на новом сайте уже связан со старым аккаунтом #' . (int)$other . '. Нужна ручная проверка.', (int)$existing->ID);
                    return;
                }
                $target = (int)$existing->ID;
                $decision = $dry ? 'would_link_existing' : 'linked_existing';
                $message = 'Найден аккаунт нового сайта с тем же email.';
                $priorLegacy = (string)get_user_meta($target, 'zau_legacy_user_id', true);
                if ($priorLegacy !== '' && (int)$priorLegacy !== $oldId) {
                    $message .= ' Прежний перенос связывал этот аккаунт со старым #' . $priorLegacy . ' — профиль стоит проверить.';
                    if (!$dry) { update_user_meta($target, 'zau_exact_prior_mismatch', $priorLegacy); }
                }
            }
        }
        if (!$target) {
            // Прежние инструменты переноса часто создавали аккаунт без email, но записывали
            // в него номер старого аккаунта. Такой аккаунт — тот же человек: связываем его,
            // а не создаём второй, и записываем в него email со старого сайта.
            $prior = $this->prior_import_account($oldId);
            $oldTokens = $this->old_user_name_tokens($item);
            $priorTokens = $prior ? array_values(array_unique(array_merge($this->name_tokens($prior->last_name . ' ' . $prior->first_name), $this->name_tokens($prior->display_name)))) : [];
            $common = $prior ? count(array_intersect($oldTokens, $priorTokens)) : 0;
            if ($prior && ($common >= 2 || (count($oldTokens) === 1 && $common === 1))) {
                $target = (int)$prior->ID;
                $decision = $dry ? 'would_link_prior_import' : 'linked_prior_import';
                $message = 'Найден аккаунт #' . $target . ' «' . $prior->display_name . '», созданный прежним переносом из этого же старого аккаунта, без email; ФИО совпадает' . ($email ? '; в него будет записан email ' . $email : '') . '.';
                if (!$dry && $email && !email_exists($email)) {
                    add_filter('send_email_change_email', '__return_false');
                    wp_update_user(['ID'=>$target, 'user_email'=>$email]);
                    remove_filter('send_email_change_email', '__return_false');
                }
            } elseif ($prior) {
                // Номер старого аккаунта записан в аккаунт с другим ФИО: прежний перенос мог
                // перепутать людей. Не связываем — создаём аккаунт и выносим пару на ручную проверку.
                $priorMismatch = $prior;
            }
        }
        if (!$target) {
            if (empty($settings['create_users'])) {
                $this->add_stat($job, 'skipped_no_create');
                $this->report($job, 'user', $oldId, 'skipped_no_create', 'Аккаунт не найден по email, а создание новых аккаунтов выключено.');
                return;
            }
            if ($dry) {
                $this->add_stat($job, 'would_create');
                $this->report($job, 'user', $oldId, 'would_create', $email ? 'Будет создан новый аккаунт с email ' . $email . '.' : 'Будет создан новый аккаунт без email (на старом сайте email пустой).', 0, 0, $tokens);
                if (!empty($priorMismatch)) { $this->report_prior_mismatch($job, $oldId, $item, $priorMismatch, 0, true); }
                return;
            }
            $created = $this->create_user($item, $email);
            if (is_wp_error($created)) {
                $this->add_stat($job, 'error');
                $this->report($job, 'user', $oldId, 'error', $created->get_error_message());
                return;
            }
            $target = (int)$created;
            $decision = 'created';
            $message = 'Создан новый аккаунт.';
        }
        if ($dry) {
            $this->add_stat($job, $decision);
            $this->report($job, 'user', $oldId, $decision, $message, $target, 0, $tokens);
            return;
        }
        $this->store_account_snapshot($target, $item);
        $this->fill_profile_from_account($target, $item, false);
        $this->save_map('user', $oldId, ['target_user_id'=>$target, 'checksum'=>$compare, 'decision'=>$decision, 'message'=>$message]);
        $this->add_stat($job, $decision);
        $this->report($job, 'user', $oldId, $decision, $message, $target, 0, $tokens);
        if (!empty($priorMismatch)) { $this->report_prior_mismatch($job, $oldId, $item, $priorMismatch, $target, false); }
    }

    private function old_display_name(array $item) {
        $meta = (array)($item['meta'] ?? []);
        $name = trim($this->meta_first($meta, 'last_name') . ' ' . $this->meta_first($meta, 'first_name'));
        $display = trim((string)($item['display_name'] ?? ''));
        return $name !== '' ? ($display !== '' && $display !== $name ? $name . ' / ' . $display : $name) : $display;
    }

    /**
     * Номер старого аккаунта записан в аккаунт другого человека (старый мост брал ФИО
     * руководителя/председателя из заявления). Это НЕ дубль: такой аккаунт ошибочный.
     * Он не предлагается для объединения, а попадает в отдельную очередь, где его можно скрыть.
     */
    private function report_prior_mismatch(&$job, $oldId, array $item, $prior, $createdId, $dry) {
        $this->add_stat($job, 'prior_account_mismatch');
        $this->report($job, 'user', $oldId, 'prior_account_mismatch',
            'Прежний перенос записал этот старый аккаунт в аккаунт #' . (int)$prior->ID . ' «' . $prior->display_name . '» (без email), но это другой человек (в старом аккаунте «' . $this->old_display_name($item) . '»). Не связано: ' . ($dry ? 'человеку будет создан свой аккаунт, а ошибочный аккаунт #' . (int)$prior->ID . ' попадёт в очередь «Ошибочные аккаунты прежнего переноса».' : 'человеку создан свой аккаунт, ошибочный аккаунт #' . (int)$prior->ID . ' — в очереди «Ошибочные аккаунты прежнего переноса».'),
            (int)$createdId, 0, ['prior_account'=>(int)$prior->ID, 'prior_name'=>$prior->display_name, 'old_name'=>$this->old_display_name($item)]);
        if (!$dry) {
            update_user_meta((int)$prior->ID, 'zau_exact_prior_wrong', (int)$oldId);
            if ($createdId) { update_user_meta((int)$prior->ID, 'zau_exact_prior_wrong_real', (int)$createdId); }
        }
    }

    /** Единственный аккаунт без email с zau_legacy_user_id = старому ID, не занятый другим старым аккаунтом и не помеченный как дубль. */
    private function prior_import_account($oldId) {
        global $wpdb;
        $ids = (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_user_id' AND meta_value=%s LIMIT 10", (string)(int)$oldId));
        $found = [];
        foreach ($ids as $id) {
            $user = get_user_by('id', (int)$id);
            if (!$user || trim((string)$user->user_email) !== '' || user_can($user, 'manage_options')) { continue; }
            if (get_user_meta((int)$id, 'zau_legacy_duplicate_of', true) || get_user_meta((int)$id, 'zau_member_status', true) === 'Дубликат переноса') { continue; }
            $taken = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->map_table} WHERE site_hash=%s AND source_type='user' AND target_user_id=%d AND source_id<>%d", $this->site_hash(), (int)$id, (int)$oldId));
            if ($taken) { continue; }
            $found[] = $user;
        }
        return count($found) === 1 ? $found[0] : null;
    }

    private function create_user(array $item, $email) {
        $settings = $this->settings();
        $meta = (array)($item['meta'] ?? []);
        $login = sanitize_user((string)($item['user_login'] ?? ''), true);
        if ($login === '' || username_exists($login)) {
            $base = $login !== '' ? $login : ($email ? sanitize_user(strtok($email, '@'), true) : 'legacy_' . (int)$item['id']);
            if ($base === '') { $base = 'legacy_' . (int)$item['id']; }
            $login = $base;
            $i = 1;
            while (username_exists($login)) { $login = $base . '_' . $i++; }
        }
        $display = trim((string)($item['display_name'] ?? ''));
        $first = $this->meta_first($meta, 'first_name');
        $last = $this->meta_first($meta, 'last_name');
        if ($display === '' || $display === (string)($item['user_login'] ?? '')) { $display = trim($last . ' ' . $first) ?: $display; }
        $userId = wp_insert_user([
            'user_login'=>$login,
            'user_pass'=>wp_generate_password(32, true, true),
            'user_email'=>$email,
            'display_name'=>$display ?: $login,
            'first_name'=>$first,
            'last_name'=>$last,
            'role'=>'subscriber',
            'user_registered'=>$this->mysql_date($item['user_registered'] ?? '') ?: current_time('mysql', true),
        ]);
        if (is_wp_error($userId)) { return $userId; }
        $hash = (string)($item['password_hash'] ?? '');
        if (!empty($settings['preserve_password']) && $this->valid_password_hash($hash)) {
            global $wpdb;
            $wpdb->update($wpdb->users, ['user_pass'=>$hash], ['ID'=>(int)$userId], ['%s'], ['%d']);
            clean_user_cache((int)$userId);
            update_user_meta((int)$userId, 'zau_legacy_password_preserved', 1);
        }
        update_user_meta((int)$userId, 'zau_exact_created', current_time('mysql'));
        return (int)$userId;
    }

    private function valid_password_hash($hash) {
        $hash = (string)$hash;
        if (strlen($hash) < 20 || strlen($hash) > 255) { return false; }
        return (bool)(preg_match('/^\$(P|H)\$[\.\/0-9A-Za-z]{31}$/', $hash) || preg_match('/^\$2[ayb]\$\d{2}\$[\.\/0-9A-Za-z]{53}$/', $hash) || strpos($hash, '$wp$') === 0 || strpos($hash, '$argon2') === 0);
    }

    private function store_account_snapshot($userId, array $item) {
        $copy = $item;
        unset($copy['password_hash'], $copy['checksum']);
        $this->update_user_json_meta($userId, 'zau_exact_legacy_raw', $copy);
        update_user_meta($userId, 'zau_exact_legacy_checksum', self::user_compare_checksum($item));
        update_user_meta($userId, 'zau_exact_legacy_user_id', (int)$item['id']);
        if (get_user_meta($userId, 'zau_legacy_user_id', true) === '') { update_user_meta($userId, 'zau_legacy_user_id', (string)(int)$item['id']); }
        update_user_meta($userId, 'zau_imported_from_legacy_site', 1);
        update_user_meta($userId, 'zau_exact_imported_at', current_time('mysql'));
    }

    /** Данные профиля из самого аккаунта: стандартные поля WordPress, поля Ultimate Member и его анкета регистрации. */
    private function profile_from_account(array $item) {
        $meta = (array)($item['meta'] ?? []);
        $profile = [
            'first_name'=>$this->meta_first($meta, 'first_name'),
            'last_name'=>$this->meta_first($meta, 'last_name'),
            'display_name'=>(string)($item['display_name'] ?? ''),
        ];
        foreach (['mobile_number', 'phone_number', 'phone', 'billing_phone', 'user_phone', 'telephone', 'tel'] as $key) {
            $phone = $this->normalize_phone($this->meta_first($meta, $key));
            if ($phone) { $profile['phone'] = $phone; break; }
        }
        $labelled = [];
        $skip = '/^(_|wp_|um_|session|rich_editing|syntax_highlighting|comment_shortcuts|admin_color|use_ssl|show_admin_bar|locale|dismissed|nickname$|description$|first_name$|last_name$|account_status|submitted$|timestamp|profile_photo|cover_photo|synced_|last_update|full_name$|user_password|confirm_user_password)/i';
        foreach ($meta as $key => $values) {
            $key = (string)$key;
            if (preg_match($skip, $key) || strpos($key, 'capabilities') !== false || strpos($key, 'user_level') !== false) { continue; }
            $value = maybe_unserialize($this->meta_first($meta, $key));
            $text = $this->scalar_text($value);
            if ($text !== '' && strlen($text) < 1000) { $labelled[str_replace('_', ' ', $key)] = $text; }
        }
        $submitted = maybe_unserialize($this->meta_first($meta, 'submitted'));
        if (is_array($submitted)) {
            foreach ($submitted as $key => $value) {
                if (preg_match('/pass|form_id|timestamp|request|_wpnonce|nonce/i', (string)$key)) { continue; }
                $text = $this->scalar_text($value);
                if ($text !== '' && strlen($text) < 1000 && !isset($labelled[str_replace('_', ' ', (string)$key)])) { $labelled[str_replace('_', ' ', (string)$key)] = $text; }
            }
        }
        if (class_exists('ZAU_Remote_Profile_Repair')) {
            foreach (ZAU_Remote_Profile_Repair::instance()->extract_profile_fields($labelled) as $key => $value) {
                if (!isset($profile[$key]) || $profile[$key] === '') { $profile[$key] = $value; }
            }
        }
        $umStatus = $this->meta_first($meta, 'account_status');
        $profile['_um_status'] = $umStatus;
        return $profile;
    }

    private function member_status_from_um($umStatus) {
        $umStatus = (string)$umStatus;
        if (strpos($umStatus, 'awaiting') === 0) { return 'На рассмотрении'; }
        if ($umStatus === 'rejected') { return 'Заявление отклонено'; }
        return (string)$this->settings()['default_status'];
    }

    private function fill_profile_from_account($userId, array $item, $overwrite) {
        $profile = $this->profile_from_account($item);
        if (get_user_meta($userId, 'zau_member_status', true) === '' || $overwrite) {
            update_user_meta($userId, 'zau_member_status', $this->member_status_from_um($profile['_um_status']));
        }
        unset($profile['_um_status']);
        return $this->apply_profile($userId, $profile, $overwrite);
    }

    /** Записывает профиль. Без $overwrite заполняет только пустые поля — данные, введённые на новом сайте, не трогаются. */
    private function apply_profile($userId, array $profile, $overwrite) {
        $changed = [];
        $user = get_user_by('id', $userId);
        if (!$user) { return $changed; }
        $update = ['ID'=>$userId];
        foreach (['first_name', 'last_name'] as $field) {
            if (!empty($profile[$field]) && ($overwrite || trim((string)$user->$field) === '')) { $update[$field] = $profile[$field]; }
        }
        $display = trim((string)($profile['display_name'] ?? ''));
        if ($display === '' || $display === $user->user_login) { $display = trim(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? '')); }
        if ($display !== '' && ($overwrite || $user->display_name === '' || $user->display_name === $user->user_login)) { $update['display_name'] = $display; }
        if (count($update) > 1) { wp_update_user($update); $changed = array_merge($changed, array_keys($update)); }

        $set = function ($key, $value) use ($userId, $overwrite, &$changed) {
            $value = is_scalar($value) ? trim((string)$value) : '';
            if ($value === '') { return; }
            $old = (string)get_user_meta($userId, $key, true);
            if ($old !== '' && !$overwrite) { return; }
            if ($old === $value) { return; }
            update_user_meta($userId, $key, $value);
            $changed[] = $key;
        };
        if (!empty($profile['phone'])) { $set('zau_phone', $this->normalize_phone($profile['phone']) ?: $profile['phone']); }
        foreach (['iin', 'birth_date', 'address', 'position', 'department', 'organization', 'organization_bin', 'organization_director', 'organization_address', 'branch_name', 'middle_name'] as $key) {
            if (!empty($profile[$key])) { $set('zau_profile_' . $key, $profile[$key]); }
        }

        $card = json_decode((string)get_user_meta($userId, 'zau_member_card_data', true), true);
        if (!is_array($card)) { $card = []; }
        $cardChanged = false;
        foreach (['iin', 'birth_date', 'address', 'position', 'department'] as $key) {
            if (empty($profile[$key])) { continue; }
            if (!$overwrite && !empty($card[$key])) { continue; }
            if ((string)($card[$key] ?? '') === (string)$profile[$key]) { continue; }
            $card[$key] = (string)$profile[$key];
            $cardChanged = true;
        }
        if ($cardChanged) {
            update_user_meta($userId, 'zau_member_card_data', wp_slash(wp_json_encode($card, JSON_UNESCAPED_UNICODE)));
            if (!get_user_meta($userId, 'zau_member_card_review_status', true)) { update_user_meta($userId, 'zau_member_card_review_status', 'pending'); }
            $changed[] = 'zau_member_card_data';
        }

        if (class_exists('ZAU_Remote_Profile_Repair')) {
            $repair = ZAU_Remote_Profile_Repair::instance();
            if ((!empty($profile['organization']) || !empty($profile['organization_bin'])) && ($overwrite || !(int)get_user_meta($userId, 'zau_organization_id', true))) {
                $org = $repair->match_organization((string)($profile['organization'] ?? ''), (string)($profile['organization_bin'] ?? ''), true, false);
                if (!empty($org['id'])) {
                    update_user_meta($userId, 'zau_organization_id', (int)$org['id']);
                    update_user_meta($userId, 'zau_organization_name', (string)$org['name']);
                    if (!empty($org['bin'])) { update_user_meta($userId, 'zau_organization_bin', (string)$org['bin']); } else { delete_user_meta($userId, 'zau_organization_bin'); }
                    $changed[] = 'zau_organization_id';
                }
            }
            if (!empty($profile['branch_name']) && ($overwrite || !(int)get_user_meta($userId, 'zau_profile_branch_id', true))) {
                $branch = $repair->match_branch((string)$profile['branch_name']);
                if (!empty($branch['id'])) {
                    update_user_meta($userId, 'zau_profile_branch_id', (int)$branch['id']);
                    update_user_meta($userId, 'zau_profile_branch_name', (string)$branch['name']);
                    $changed[] = 'zau_profile_branch_id';
                }
            }
        }
        return array_values(array_unique($changed));
    }

    /**
     * Ищет на новом сайте аккаунты с тем же ФИО, что у создаваемого аккаунта, но с
     * другим email: скорее всего, человек уже зарегистрировался заново. Такие пары
     * не объединяются автоматически — они попадают в отчёт и в очередь «Возможные дубли».
     */
    private function duplicate_candidates($first, $last, $display, $excludeUserId, $job, $oldId = 0) {
        global $wpdb;
        $first = trim((string)$first); $last = trim((string)$last); $display = trim((string)$display);
        $want = $this->name_tokens($last . ' ' . $first);
        if (count($want) < 2) { $want = $this->name_tokens($display); }
        if (count($want) < 2) { return []; }
        $ids = [];
        if ($first !== '' && $last !== '') {
            $ids = array_merge($ids, (array)$wpdb->get_col($wpdb->prepare(
                "SELECT ln.user_id FROM {$wpdb->usermeta} ln JOIN {$wpdb->usermeta} fn ON fn.user_id=ln.user_id AND fn.meta_key='first_name' AND fn.meta_value=%s WHERE ln.meta_key='last_name' AND ln.meta_value=%s LIMIT 20",
                $first, $last
            )));
        }
        $variants = array_values(array_unique(array_filter([$display, trim($last . ' ' . $first), trim($first . ' ' . $last)])));
        if ($variants) {
            $ph = implode(',', array_fill(0, count($variants), '%s'));
            $ids = array_merge($ids, (array)$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE display_name IN ($ph) LIMIT 20", $variants)));
            $ids = array_merge($ids, (array)$wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_profile_full_name' AND meta_value IN ($ph) LIMIT 20", $variants)));
        }
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (!$id || $id === (int)$excludeUserId) { continue; }
            $user = get_user_by('id', $id);
            if (!$user || user_can($user, 'manage_options')) { continue; }
            if (get_user_meta($id, 'zau_legacy_duplicate_of', true) || get_user_meta($id, 'zau_member_status', true) === 'Дубликат переноса') { continue; }
            // Аккаунт, в который прежний перенос записал ДРУГОЙ старый аккаунт, не предлагаем:
            // его данные собраны из чужих заявлений, объединение перепутало бы людей.
            $priorLegacy = (string)get_user_meta($id, 'zau_legacy_user_id', true);
            if (($priorLegacy !== '' && (int)$priorLegacy !== (int)$oldId) || get_user_meta($id, 'zau_exact_prior_wrong', true)) { continue; }
            // Аккаунт уже принадлежит ДРУГОМУ старому пользователю — это однофамилец, не дубль.
            $taken = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->map_table} WHERE site_hash=%s AND source_type='user' AND target_user_id=%d", $this->site_hash(), $id))
                + (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND target_user_id=%d AND decision IN ('would_link_existing','linked_existing','would_link_prior_import','linked_prior_import','unchanged','updated','would_update')", (string)$job['token'], $id));
            if ($taken) { continue; }
            $have = array_values(array_unique(array_merge($this->name_tokens($user->last_name . ' ' . $user->first_name), $this->name_tokens($user->display_name))));
            if (count(array_intersect($want, $have)) < 2) { continue; }
            $out[] = ['id'=>$id, 'name'=>$user->display_name, 'email'=>$user->user_email];
        }
        return array_slice($out, 0, 5);
    }

    private function dupcheck_step(&$job, $dry) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,source_id,target_user_id,details FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND decision IN ('created','would_create') AND id>%d ORDER BY id ASC LIMIT 100",
            (string)$job['token'], (int)$job['cursor']
        ));
        foreach ((array)$rows as $row) {
            $job['cursor'] = (int)$row->id;
            $d = json_decode((string)$row->details, true);
            if (!is_array($d)) { continue; }
            $found = $this->duplicate_candidates($d['first'] ?? '', $d['last'] ?? '', $d['display'] ?? '', (int)$row->target_user_id, $job, (int)$row->source_id);
            if (!$found) { continue; }
            $list = implode('; ', array_map(function ($c) { return '#' . $c['id'] . ' ' . $c['name'] . ' <' . $c['email'] . '>'; }, $found));
            $this->add_stat($job, 'possible_duplicate');
            $this->report($job, 'user', (int)$row->source_id, 'possible_duplicate',
                'Возможный дубль: на новом сайте уже есть аккаунт с тем же ФИО и другим email — ' . $list . '. ' . ($dry
                    ? 'Если это тот же человек, до переноса впишите в тот аккаунт email со старого сайта (тогда аккаунты свяжутся), либо объедините после переноса в очереди «Возможные дубли».'
                    : 'Проверьте и объедините в очереди «Возможные дубли».'),
                (int)$row->target_user_id, 0, ['candidates'=>$found]);
            if (!$dry && (int)$row->target_user_id) {
                $list = (array)get_user_meta((int)$row->target_user_id, 'zau_exact_possible_duplicate', true);
                update_user_meta((int)$row->target_user_id, 'zau_exact_possible_duplicate', array_values(array_unique(array_filter(array_map('intval', array_merge($list, wp_list_pluck($found, 'id')))))));
            }
        }
        return count((array)$rows) < 100;
    }

    /**
     * Объединяет аккаунт, созданный переносом, с уже существующим аккаунтом того же человека.
     * Всё переносится к существующему аккаунту; созданный помечается как дубль и
     * скрывается из реестра (не удаляется). Email существующего аккаунта не меняется.
     */
    private function merge_accounts($fromId, $intoId) {
        global $wpdb;
        $from = get_user_by('id', (int)$fromId); $into = get_user_by('id', (int)$intoId);
        if (!$from || !$into || $from->ID === $into->ID) { return false; }
        if (get_user_meta((int)$into->ID, 'zau_exact_prior_wrong', true)) { return false; }
        $wpdb->update($this->map_table, ['target_user_id'=>(int)$into->ID, 'updated_at'=>current_time('mysql')], ['target_user_id'=>(int)$from->ID]);
        $wpdb->update($this->submissions_table, ['user_id'=>(int)$into->ID], ['user_id'=>(int)$from->ID]);
        $wpdb->update($this->docs_table, ['user_id'=>(int)$into->ID], ['user_id'=>(int)$from->ID]);
        $raw = $this->user_json_meta((int)$from->ID, 'zau_exact_legacy_raw');
        if ($raw) {
            if (!get_user_meta((int)$into->ID, 'zau_exact_legacy_raw', true)) {
                $this->update_user_json_meta((int)$into->ID, 'zau_exact_legacy_raw', $raw);
                update_user_meta((int)$into->ID, 'zau_exact_legacy_user_id', (int)($raw['id'] ?? 0));
                update_user_meta((int)$into->ID, 'zau_exact_legacy_checksum', (string)get_user_meta((int)$from->ID, 'zau_exact_legacy_checksum', true));
            }
            $this->fill_profile_from_account((int)$into->ID, $raw, false);
        }
        $this->enrich_from_own_entries((int)$into->ID, false);
        $log = $this->user_json_meta((int)$into->ID, 'zau_exact_merges');
        $log[] = ['from'=>(int)$from->ID, 'old_email'=>$from->user_email, 'at'=>current_time('mysql'), 'by'=>get_current_user_id()];
        $this->update_user_json_meta((int)$into->ID, 'zau_exact_merges', $log);
        update_user_meta((int)$from->ID, 'zau_legacy_duplicate_of', (int)$into->ID);
        update_user_meta((int)$from->ID, 'zau_hidden_from_registry', 1);
        update_user_meta((int)$from->ID, 'zau_member_status', 'Дубликат переноса');
        update_user_meta((int)$from->ID, 'zau_exact_merged_email', $from->user_email);
        delete_user_meta((int)$from->ID, 'zau_exact_possible_duplicate');
        // Освобождаем email, чтобы вход по коду на старый email не открывал пустой аккаунт-дубль.
        $wpdb->update($wpdb->users, ['user_email'=>''], ['ID'=>(int)$from->ID]);
        clean_user_cache((int)$from->ID);
        // У существующего аккаунта не было email — отдаём ему email со старого сайта, иначе человек не сможет войти по коду.
        if (trim((string)$into->user_email) === '' && is_email($from->user_email)) {
            $wpdb->update($wpdb->users, ['user_email'=>$from->user_email], ['ID'=>(int)$into->ID]);
            clean_user_cache((int)$into->ID);
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Заявления WPForms                                                  */
    /* ------------------------------------------------------------------ */

    private function forms_def() {
        $forms = get_option(self::FORMS_OPT, []);
        return is_array($forms) ? $forms : [];
    }

    /** Поля записи в виде списка [id, label, type, value] в порядке формы. */
    private function entry_fields(array $item) {
        $forms = $this->forms_def();
        $def = (array)($forms[(int)($item['form_id'] ?? 0)]['fields'] ?? []);
        $out = [];
        foreach ((array)($item['fields'] ?? []) as $id => $field) {
            if (!is_array($field)) { continue; }
            $fid = (string)($field['id'] ?? $id);
            $type = (string)($field['type'] ?? ($def[$fid]['type'] ?? ''));
            if (in_array($type, ['divider', 'html', 'pagebreak', 'content', 'captcha'], true)) { continue; }
            $label = trim((string)($field['name'] ?? ''));
            if ($label === '') { $label = trim((string)($def[$fid]['label'] ?? '')); }
            if ($label === '') { $label = 'Поле ' . $fid; }
            $value = $field['value'] ?? '';
            if (($value === '' || $value === null) && isset($field['value_raw'])) { $value = $field['value_raw']; }
            $out[] = ['id'=>$fid, 'label'=>$label, 'type'=>$type, 'value'=>$this->scalar_text($value)];
        }
        return $out;
    }

    private function applicant_name(array $fields) {
        $exclude = '/председател|руководител|директор|бухгалтер|ответствен|работодател|родител|супруг|контакт/u';
        foreach ($fields as $f) {
            if ($f['type'] === 'name' && $f['value'] !== '' && !preg_match($exclude, $this->lower($f['label']))) { return $f['value']; }
        }
        foreach ($fields as $f) {
            $label = $this->lower($f['label']);
            if ($f['value'] !== '' && preg_match('/фио|ф\.и\.о|аты.?жөн|full.?name|полное имя/u', $label) && !preg_match($exclude, $label)) { return $f['value']; }
        }
        $parts = ['last'=>'', 'first'=>'', 'middle'=>''];
        foreach ($fields as $f) {
            $label = $this->lower($f['label']);
            if ($f['value'] === '' || preg_match($exclude, $label)) { continue; }
            if ($parts['last'] === '' && preg_match('/фамили|тег/u', $label)) { $parts['last'] = $f['value']; }
            elseif ($parts['middle'] === '' && preg_match('/отчеств|әкесінің/u', $label)) { $parts['middle'] = $f['value']; }
            elseif ($parts['first'] === '' && preg_match('/(^|\s)имя($|\s)|first.?name|(^|\s)аты($|\s)/u', $label) && !preg_match('/наименован/u', $label)) { $parts['first'] = $f['value']; }
        }
        return trim(implode(' ', array_filter($parts)));
    }

    /** yes — ФИО заявления совпадает с владельцем аккаунта; no — явно другой человек; unknown — сравнить не с чем. */
    private function applicant_match($applicant, array $ownerTokens) {
        $a = $this->name_tokens($applicant);
        if (!$a || count($ownerTokens) < 2) { return 'unknown'; }
        $common = count(array_intersect($a, $ownerTokens));
        if ($common >= 2 || ($common === 1 && count($a) === 1)) { return 'yes'; }
        return 'no';
    }

    private function first_email(array $fields) {
        foreach ($fields as $f) {
            if ($f['type'] === 'email' || preg_match('/email|e-mail|почт/u', $this->lower($f['label']))) {
                $email = strtolower(trim($f['value']));
                if (is_email($email)) { return $email; }
            }
        }
        return '';
    }

    private function report_tokens($job, $where, $value) {
        global $wpdb;
        if (empty($job['token'])) { return []; }
        $details = $wpdb->get_var($wpdb->prepare(
            "SELECT details FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND {$where}=%d AND decision NOT IN ('possible_duplicate','prior_account_mismatch') ORDER BY id DESC LIMIT 1",
            (string)$job['token'], (int)$value
        ));
        $details = json_decode((string)$details, true);
        return is_array($details) ? (array)($details['tokens'] ?? []) : [];
    }

    private function owner_tokens_for_user($userId, $job = null) {
        $raw = $this->user_json_meta($userId, 'zau_exact_legacy_raw');
        if ($raw) { return $this->old_user_name_tokens($raw); }
        // Пробный режим: копии аккаунта ещё нет, ФИО берётся из старого аккаунта в отчёте этого задания.
        $fromReport = $job ? $this->report_tokens($job, 'target_user_id', $userId) : [];
        if ($fromReport) { return $fromReport; }
        $user = get_user_by('id', $userId);
        return $user ? array_values(array_unique(array_merge($this->name_tokens($user->last_name . ' ' . $user->first_name), $this->name_tokens($user->display_name)))) : [];
    }

    /** Владелец записи определяется только по старому User ID; для гостевой записи — по точному email. */
    private function resolve_entry_owner(array $item, array $fields, $job, $dry) {
        global $wpdb;
        $oldUser = (int)($item['user_id'] ?? 0);
        if ($oldUser > 0) {
            $map = $this->get_map('user', $oldUser);
            if ($map && (int)$map->target_user_id && get_user_by('id', (int)$map->target_user_id)) {
                return ['user_id'=>(int)$map->target_user_id, 'method'=>'account', 'tokens'=>$this->owner_tokens_for_user((int)$map->target_user_id, $job)];
            }
            if ($dry) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT decision,target_user_id FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND source_id=%d AND decision NOT IN ('possible_duplicate','prior_account_mismatch') ORDER BY id DESC LIMIT 1",
                    (string)$job['token'], $oldUser
                ));
                if ($row && in_array($row->decision, ['would_create', 'would_link_existing', 'would_link_prior_import', 'would_update', 'unchanged'], true)) {
                    return ['user_id'=>(int)$row->target_user_id, 'method'=>'account', 'pending'=>1, 'tokens'=>$this->report_tokens($job, 'source_id', $oldUser)];
                }
            }
            // Аккаунт подавшего не переносится (администратор старого сайта или удалён там).
            // Прикрепляем запись к аккаунту с email из заявления, но только если ФИО в
            // заявлении совпадает с владельцем этого email — иначе оставляем без владельца.
            $email = $this->first_email($fields);
            $byEmail = $email ? get_user_by('email', $email) : false;
            if ($byEmail) {
                $tokens = $this->owner_tokens_for_user((int)$byEmail->ID, $job);
                if ($this->applicant_match($this->applicant_name($fields), $tokens) === 'yes') {
                    return ['user_id'=>(int)$byEmail->ID, 'method'=>'email_after_missing_account', 'tokens'=>$tokens];
                }
            }
            return ['user_id'=>0, 'method'=>'account_not_transferred', 'message'=>'Старый аккаунт #' . $oldUser . ' не перенесён (удалён на старом сайте, администратор или конфликт), а email и ФИО в заявлении не совпали ни с одним аккаунтом.'];
        }
        $email = $this->first_email($fields);
        if ($email) {
            $user = get_user_by('email', $email);
            if ($user) { return ['user_id'=>(int)$user->ID, 'method'=>'guest_email', 'tokens'=>$this->owner_tokens_for_user((int)$user->ID, $job)]; }
        }
        return ['user_id'=>0, 'method'=>'guest_no_account', 'message'=>'Запись подана без входа в аккаунт, и её email не совпадает ни с одним аккаунтом.'];
    }

    private function ensure_form($oldFormId) {
        global $wpdb;
        $oldFormId = (int)$oldFormId;
        if (isset($this->form_cache[$oldFormId])) { return $this->form_cache[$oldFormId]; }
        $slug = 'legacy-exact-' . $oldFormId;
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->forms_table} WHERE slug=%s LIMIT 1", $slug));
        if (!$id) {
            $def = (array)($this->forms_def()[$oldFormId] ?? []);
            $fields = [];
            foreach ((array)($def['fields'] ?? []) as $fid => $f) {
                if (in_array($f['type'], ['divider', 'html', 'pagebreak', 'content', 'captcha'], true)) { continue; }
                $fields[] = ['key'=>'f' . preg_replace('/\D+/', '', (string)$fid), 'label'=>$f['label'] ?: 'Поле ' . $fid, 'type'=>$f['type'] === 'textarea' ? 'textarea' : 'text', 'required'=>0];
            }
            $now = current_time('mysql');
            $wpdb->insert($this->forms_table, [
                'name'=>trim(($def['title'] ?? '') ?: 'Форма #' . $oldFormId) . ' (старый сайт)',
                'slug'=>$slug,
                'description'=>'Архив записей WPForms #' . $oldFormId . ', перенесённых точным переносом 1:1.',
                'fields_json'=>wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
                'template_ids_json'=>'[]',
                'require_auth'=>1,
                'active'=>0,
                'created_by'=>get_current_user_id(),
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            $id = (int)$wpdb->insert_id;
        }
        $this->form_cache[$oldFormId] = $id;
        return $id;
    }

    /** Скачивает файлы (подписи, вложения), лежащие на старом сайте, чтобы они не пропали после его отключения. */
    private function download_entry_files($entryId, array $fields) {
        $s = $this->settings();
        $host = wp_parse_url((string)$s['old_url'], PHP_URL_HOST);
        if (!$host) { return []; }
        $urls = [];
        foreach ($fields as $f) {
            if (preg_match_all('#https?://[^\s,"\'<>]+#iu', (string)$f['value'], $m)) {
                foreach ($m[0] as $url) {
                    if (strcasecmp((string)wp_parse_url($url, PHP_URL_HOST), $host) === 0) { $urls[$url] = 1; }
                }
            }
        }
        if (!$urls) { return []; }
        $up = wp_upload_dir();
        $folder = 'zau-legacy-files/' . (int)$entryId . '-' . substr(hash('sha256', wp_salt('auth') . $entryId), 0, 10);
        $dir = trailingslashit($up['basedir']) . $folder;
        wp_mkdir_p($dir);
        if (!is_file(trailingslashit($up['basedir']) . 'zau-legacy-files/index.php')) { @file_put_contents(trailingslashit($up['basedir']) . 'zau-legacy-files/index.php', "<?php\n"); }
        $out = [];
        foreach (array_slice(array_keys($urls), 0, 10) as $url) {
            $name = sanitize_file_name(rawurldecode(basename((string)wp_parse_url($url, PHP_URL_PATH))));
            if ($name === '' || !preg_match('/\.(jpe?g|png|gif|webp|pdf|docx?|xlsx?|txt|zip)$/i', $name)) { continue; }
            $path = trailingslashit($dir) . $name;
            if (!is_file($path)) {
                $response = wp_remote_get($url, ['timeout'=>30, 'limit_response_size'=>20 * MB_IN_BYTES]);
                if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) { $out[$url] = ''; continue; }
                file_put_contents($path, wp_remote_retrieve_body($response));
            }
            $out[$url] = trailingslashit($up['baseurl']) . $folder . '/' . rawurlencode($name);
        }
        return $out;
    }

    private function extracted_entry_data(array $fields) {
        $labelled = [];
        foreach ($fields as $f) { if ($f['value'] !== '' && !isset($labelled[$f['label']])) { $labelled[$f['label']] = $f['value']; } }
        $data = class_exists('ZAU_Remote_Profile_Repair') ? ZAU_Remote_Profile_Repair::instance()->extract_profile_fields($labelled) : [];
        $email = $this->first_email($fields);
        if ($email) { $data['email'] = $email; }
        return $data;
    }

    private function process_entry(array $item, &$job, $dry) {
        global $wpdb;
        $entryId = (int)($item['id'] ?? 0);
        if (!$entryId) { return; }
        $checksum = (string)($item['checksum'] ?? '');
        if (self::checksum($item) !== $checksum) {
            $this->add_stat($job, 'transfer_error');
            $this->report($job, 'entry', $entryId, 'transfer_error', 'Контрольная сумма не совпала: данные повреждены при передаче. Повторите перенос.');
            return;
        }
        $fields = $this->entry_fields($item);
        $owner = $this->resolve_entry_owner($item, $fields, $job, $dry);
        $applicant = $this->applicant_name($fields);
        $match = ($owner['user_id'] || !empty($owner['pending'])) ? $this->applicant_match($applicant, (array)($owner['tokens'] ?? [])) : 'unknown';
        $sourceStatus = strtolower((string)($item['status'] ?? ''));
        if (in_array($sourceStatus, ['spam', 'trash', 'abandoned', 'partial'], true)) { $status = self::S_HIDDEN; }
        elseif (!$owner['user_id'] && empty($owner['pending'])) { $status = self::S_UNASSIGNED; }
        else { $status = $match === 'no' ? self::S_OTHER : self::S_OWN; }
        $decisionByStatus = [self::S_OWN=>'own', self::S_OTHER=>'other_person', self::S_UNASSIGNED=>'unassigned', self::S_HIDDEN=>'hidden_' . ($sourceStatus ?: 'status')];

        $map = $this->get_map('entry', $entryId);
        $existing = ($map && (int)$map->target_submission_id) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d", (int)$map->target_submission_id)) : null;
        if ($existing && (string)$map->checksum === $checksum) {
            $this->add_stat($job, 'unchanged');
            $this->report($job, 'entry', $entryId, 'unchanged', 'Запись уже перенесена, изменений нет.', (int)$existing->user_id, (int)$existing->id);
            return;
        }
        if ($dry) {
            $decision = $existing ? 'would_update' : $decisionByStatus[$status];
            $message = $existing ? 'Запись изменилась на старом сайте — копия будет обновлена.' : $this->entry_message($status, $owner, $applicant);
            $this->add_stat($job, $decision);
            $this->report($job, 'entry', $entryId, $decision, $message, (int)$owner['user_id'], 0, ['applicant'=>$applicant, 'match'=>$match, 'owner_method'=>$owner['method']]);
            return;
        }

        $forms = $this->forms_def();
        $copy = $item;
        unset($copy['checksum']);
        $data = [
            'legacy_exact'=>1,
            'legacy_entry_id'=>$entryId,
            'legacy_form_id'=>(int)($item['form_id'] ?? 0),
            'legacy_form_title'=>(string)($forms[(int)($item['form_id'] ?? 0)]['title'] ?? ''),
            'legacy_user_id'=>(int)($item['user_id'] ?? 0),
            'legacy_entry_date'=>(string)($item['date'] ?? ''),
            'legacy_owner_method'=>(string)$owner['method'],
            'legacy_applicant_name'=>$applicant,
            'legacy_applicant_match'=>$match,
            'legacy_checksum'=>$checksum,
            'legacy_fields'=>$fields,
        ];
        // ИИН, организация и т. п. выносятся на верхний уровень (их читают карточка и отчёты)
        // только когда заявление точно принадлежит владельцу аккаунта.
        if ($match === 'yes' && $status === self::S_OWN) {
            foreach ($this->extracted_entry_data($fields) as $key => $value) { $data[$key] = $value; }
        }
        $data['full_name'] = $applicant !== '' ? $applicant : (($u = get_user_by('id', (int)$owner['user_id'])) ? $u->display_name : '');
        if (!empty($this->settings()['download_files'])) {
            $files = $this->download_entry_files($entryId, $fields);
            if ($files) { $data['legacy_files'] = $files; }
        }
        $data['legacy_raw'] = $copy;

        $createdAt = $this->mysql_date($item['date'] ?? '') ?: current_time('mysql');
        if ($existing) {
            $old = json_decode((string)$existing->data_json, true);
            if (!is_array($old)) { $old = []; }
            foreach (['legacy_dispute', 'legacy_claimed', 'legacy_admin_assigned'] as $keep) { if (isset($old[$keep])) { $data[$keep] = $old[$keep]; } }
            $history = (array)($old['legacy_revisions'] ?? []);
            $history[] = ['replaced_at'=>current_time('mysql'), 'previous_checksum'=>(string)($old['legacy_checksum'] ?? '')];
            $data['legacy_revisions'] = array_slice($history, -10);
            $update = ['data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at'=>$createdAt, 'updated_at'=>current_time('mysql')];
            // Решения пользователя и администратора (спор, «это моё», ручное назначение) повторный перенос не меняет.
            if (empty($map->manual)) { $update['user_id'] = (int)$owner['user_id']; $update['status'] = $status; }
            $wpdb->update($this->submissions_table, $update, ['id'=>(int)$existing->id]);
            $this->save_map('entry', $entryId, ['checksum'=>$checksum, 'decision'=>'updated', 'target_user_id'=>empty($map->manual) ? (int)$owner['user_id'] : (int)$map->target_user_id]);
            $this->add_stat($job, 'updated');
            $this->report($job, 'entry', $entryId, 'updated', 'Копия записи обновлена по данным старого сайта.', (int)$existing->user_id, (int)$existing->id);
            return;
        }
        $wpdb->insert($this->submissions_table, [
            'form_id'=>$this->ensure_form((int)($item['form_id'] ?? 0)),
            'user_id'=>(int)$owner['user_id'],
            'status'=>$status,
            'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'signature_urls_json'=>'[]',
            'ip'=>'legacy-exact',
            'created_at'=>$createdAt,
            'updated_at'=>current_time('mysql'),
        ]);
        $submissionId = (int)$wpdb->insert_id;
        if (!$submissionId) {
            $this->add_stat($job, 'error');
            $this->report($job, 'entry', $entryId, 'error', 'Не удалось сохранить заявку: ' . $wpdb->last_error);
            return;
        }
        $decision = $decisionByStatus[$status];
        $message = $this->entry_message($status, $owner, $applicant);
        $this->save_map('entry', $entryId, ['target_user_id'=>(int)$owner['user_id'], 'target_submission_id'=>$submissionId, 'checksum'=>$checksum, 'decision'=>$decision, 'message'=>$message]);
        $this->add_stat($job, $decision);
        $job['processed']['submissions']++;
        $this->report($job, 'entry', $entryId, $decision, $message, (int)$owner['user_id'], $submissionId, ['applicant'=>$applicant, 'match'=>$match, 'owner_method'=>$owner['method']]);
    }

    private function entry_message($status, array $owner, $applicant) {
        if ($status === self::S_HIDDEN) { return 'На старом сайте запись была в спаме/корзине или не дозаполнена. Сохранена, но в кабинете не показывается.'; }
        if ($status === self::S_UNASSIGNED) { return (string)($owner['message'] ?? 'Владелец не определён.'); }
        if ($status === self::S_OTHER) { return 'Подано с аккаунта за другого человека (' . $applicant . '). Остаётся у подавшего с пометкой и не заполняет его профиль.'; }
        if ($owner['method'] === 'email_after_missing_account') { return 'Аккаунт подавшего на старом сайте не переносится; запись прикреплена по email и совпавшему ФИО.'; }
        return $owner['method'] === 'guest_email' ? 'Гостевая запись прикреплена по точному email.' : 'Заявление владельца аккаунта.';
    }

    /** Заполняет ПУСТЫЕ поля профиля из самого нового собственного заявления (ФИО совпало с владельцем). */
    private function enrich_from_own_entries($userId, $overwrite) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT data_json FROM {$this->submissions_table} WHERE user_id=%d AND status=%s ORDER BY created_at DESC,id DESC LIMIT 20",
            (int)$userId, self::S_OWN
        ));
        foreach ((array)$rows as $json) {
            $data = json_decode((string)$json, true);
            if (!is_array($data) || ($data['legacy_applicant_match'] ?? '') !== 'yes') { continue; }
            $profile = $this->extracted_entry_data((array)($data['legacy_fields'] ?? []));
            unset($profile['email']);
            return $this->apply_profile((int)$userId, $profile, $overwrite);
        }
        return [];
    }

    /* ------------------------------------------------------------------ */
    /* Сверка                                                             */
    /* ------------------------------------------------------------------ */

    private function verify_user(array $item, &$job) {
        $oldId = (int)($item['id'] ?? 0);
        $fresh = self::user_compare_checksum($item);
        $map = $this->get_map('user', $oldId);
        if (!$map || !(int)$map->target_user_id) {
            $admin = in_array('administrator', (array)($item['roles'] ?? []), true) && !empty($this->settings()['skip_admins']);
            $decision = $admin ? 'skipped_admin' : 'missing';
            $this->add_stat($job, 'users_' . $decision);
            $this->report($job, 'user', $oldId, $decision, $admin ? 'Администратор не переносится по настройке.' : 'Аккаунт не перенесён. Запустите перенос повторно.');
            return;
        }
        $userId = (int)$map->target_user_id;
        if (!get_user_by('id', $userId)) {
            $this->add_stat($job, 'users_missing');
            $this->report($job, 'user', $oldId, 'missing', 'Связанный аккаунт нового сайта удалён.', $userId);
            return;
        }
        $stored = $this->user_json_meta($userId, 'zau_exact_legacy_raw');
        $storedSum = $stored ? self::checksum($stored) : '';
        if ($storedSum !== $fresh) {
            $decision = ((string)$map->checksum === $storedSum) ? 'changed_on_old_site' : 'stored_mismatch';
            $this->add_stat($job, 'users_' . $decision);
            $this->report($job, 'user', $oldId, $decision, $decision === 'changed_on_old_site' ? 'Аккаунт изменился на старом сайте после переноса — запустите перенос ещё раз.' : 'Сохранённая копия не совпадает с источником.', $userId);
            return;
        }
        $this->add_stat($job, 'users_ok');
        $this->report($job, 'user', $oldId, 'ok', '', $userId);
    }

    private function verify_entry(array $item, &$job) {
        global $wpdb;
        $entryId = (int)($item['id'] ?? 0);
        $fresh = self::checksum($item);
        $map = $this->get_map('entry', $entryId);
        $sub = ($map && (int)$map->target_submission_id) ? $wpdb->get_row($wpdb->prepare("SELECT id,user_id,status,data_json FROM {$this->submissions_table} WHERE id=%d", (int)$map->target_submission_id)) : null;
        if (!$sub) {
            $this->add_stat($job, 'entries_missing');
            $this->report($job, 'entry', $entryId, 'missing', 'Запись не перенесена. Запустите перенос повторно.');
            return;
        }
        $data = json_decode((string)$sub->data_json, true);
        $raw = is_array($data) && is_array($data['legacy_raw'] ?? null) ? $data['legacy_raw'] : [];
        $storedSum = $raw ? self::checksum($raw) : '';
        if ($storedSum !== $fresh) {
            $decision = ((string)$map->checksum === $storedSum) ? 'changed_on_old_site' : 'stored_mismatch';
            $this->add_stat($job, 'entries_' . $decision);
            $this->report($job, 'entry', $entryId, $decision, $decision === 'changed_on_old_site' ? 'Запись изменилась на старом сайте после переноса — запустите перенос ещё раз.' : 'Сохранённая копия не совпадает с источником.', (int)$sub->user_id, (int)$sub->id);
            return;
        }
        if ($sub->status === self::S_HIDDEN) {
            $this->add_stat($job, 'entries_ok_hidden');
            $this->report($job, 'entry', $entryId, 'ok_hidden', 'Данные перенесены полностью (спам/корзина/черновик на старом сайте, в кабинете не показывается).', (int)$sub->user_id, (int)$sub->id);
            return;
        }
        if ($sub->status === self::S_UNASSIGNED || !(int)$sub->user_id) {
            $this->add_stat($job, 'entries_ok_unassigned');
            $this->report($job, 'entry', $entryId, 'ok_unassigned', 'Данные перенесены полностью, но владелец не назначен — см. очередь «Без владельца».', 0, (int)$sub->id);
            return;
        }
        $this->add_stat($job, 'entries_ok');
        $this->report($job, 'entry', $entryId, 'ok', '', (int)$sub->user_id, (int)$sub->id);
    }

    private function verify_orphans(&$job) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.source_type,m.source_id,m.target_user_id,m.target_submission_id FROM {$this->map_table} m
             LEFT JOIN {$this->report_table} r ON r.job_token=%s AND r.source_type=m.source_type AND r.source_id=m.source_id
             WHERE m.site_hash=%s AND r.id IS NULL LIMIT 5000",
            (string)$job['token'], $this->site_hash()
        ));
        foreach ((array)$rows as $row) {
            $this->add_stat($job, $row->source_type . 's_deleted_on_old_site');
            $this->report($job, $row->source_type, (int)$row->source_id, 'deleted_on_old_site', 'Запись есть на новом сайте, но удалена на старом. На новом сайте она сохранена.', (int)$row->target_user_id, (int)$row->target_submission_id);
        }
    }

    private function verify_summary($job) {
        $s = (array)$job['stats'];
        $users = (int)($job['totals']['users'] ?? 0);
        $entries = (int)($job['totals']['entries'] ?? 0);
        $usersOk = (int)($s['users_ok'] ?? 0) + (int)($s['users_skipped_admin'] ?? 0);
        $entriesOk = (int)($s['entries_ok'] ?? 0) + (int)($s['entries_ok_unassigned'] ?? 0) + (int)($s['entries_ok_hidden'] ?? 0);
        $problems = 0;
        foreach ($s as $key => $value) {
            if (preg_match('/_(missing|stored_mismatch|changed_on_old_site)$/', $key)) { $problems += (int)$value; }
        }
        return [
            'users_total'=>$users, 'users_ok'=>$usersOk,
            'entries_total'=>$entries, 'entries_ok'=>$entriesOk,
            'unassigned'=>(int)($s['entries_ok_unassigned'] ?? 0),
            'problems'=>$problems,
            'complete'=>$problems === 0 && $usersOk === (int)$job['processed']['users'] && $entriesOk === (int)$job['processed']['entries'],
            'not_imported'=>(int)($s['users_ok'] ?? 0) === 0 && $entriesOk === 0 && $problems > 0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Прежние переносы: скрытие дублей и откат                           */
    /* ------------------------------------------------------------------ */

    private function prior_entry_id(array $data) {
        if (!empty($data['legacy_exact']) || ($data['legacy_source'] ?? '') === 'external_csv') { return 0; }
        foreach (['legacy_wpforms_entry_id', 'legacy_entry_id'] as $key) {
            if (!empty($data[$key]) && (int)$data[$key] > 0) { return (int)$data[$key]; }
        }
        return 0;
    }

    /**
     * Что делать с заявкой прежнего переноса, у которой есть точная копия:
     *  supersede      — скрыть прежнюю; документы к владельцу точной копии;
     *  reassign_exact — прежняя привязка верна (ФИО заявителя = владелец прежней заявки),
     *                   точную копию отдать этому владельцу, прежнюю скрыть;
     *  review         — ничего не менять, показать администратору.
     */
    private function cleanup_plan($prior, $exact, $map, $jobToken = '') {
        if ((int)$prior->user_id === (int)$exact->user_id) {
            return ['action'=>'supersede', 'message'=>'та же заявка у того же аккаунта — прежняя копия скрывается, остаётся точная.'];
        }
        if ($exact->status === self::S_DISPUTED) {
            return ['action'=>'review', 'message'=>'пользователь отметил точную копию «не моё» — ждёт решения администратора, ничего не меняется.'];
        }
        $data = json_decode((string)$exact->data_json, true);
        $applicant = is_array($data) ? (string)($data['legacy_applicant_name'] ?? '') : '';
        $priorUser = get_user_by('id', (int)$prior->user_id);
        $priorTokens = $priorUser ? array_values(array_unique(array_merge($this->name_tokens($priorUser->last_name . ' ' . $priorUser->first_name), $this->name_tokens($priorUser->display_name)))) : [];
        $priorMatch = $this->tokens_match($this->name_tokens($applicant), $priorTokens);
        $priorName = $priorUser ? '#' . (int)$priorUser->ID . ' «' . $priorUser->display_name . '»' : '#' . (int)$prior->user_id;
        if ($priorMatch === 'yes') {
            if (in_array($exact->status, [self::S_OTHER, self::S_UNASSIGNED], true) && empty($map->manual)) {
                return ['action'=>'reassign_exact', 'message'=>'прежний перенос верно прикрепил заявку к заявителю ' . $priorName . ' («' . $applicant . '»); точная копия была ' . ((int)$exact->user_id ? 'у подавшего #' . (int)$exact->user_id : 'без владельца') . ' — передаётся заявителю, прежняя копия скрывается, документы остаются у заявителя.'];
            }
            return ['action'=>'review', 'message'=>'ФИО заявителя «' . $applicant . '» совпадает и с прежним аккаунтом ' . $priorName . ', и с владельцем точной копии #' . (int)$exact->user_id . ' — возможно, два аккаунта одного человека. Ничего не меняется.'];
        }
        // Есть другая прежняя копия той же записи у аккаунта самого заявителя — эта копия ошибочная.
        // (Медленный поиск по таблице нужен только когда точная копия не у заявителя.)
        $sibling = $exact->status !== self::S_OWN ? $this->prior_copy_owner_matching((int)($data['legacy_entry_id'] ?? 0), (int)$prior->id, $applicant, $jobToken) : 0;
        if ($sibling) {
            return ['action'=>'supersede', 'docs_owner'=>$sibling, 'message'=>'заявка была у чужого аккаунта ' . $priorName . '; другая прежняя копия этой записи — у заявителя #' . $sibling . ' («' . $applicant . '»). Эта копия скрывается, документы переходят к заявителю.'];
        }
        if ($exact->status === self::S_OWN) {
            return ['action'=>'supersede', 'message'=>'заявка была у чужого аккаунта ' . $priorName . '; заявитель «' . $applicant . '» — владелец точной копии #' . (int)$exact->user_id . '. Прежняя копия скрывается, документы переходят к владельцу.'];
        }
        return ['action'=>'review', 'message'=>'заявка у аккаунта ' . $priorName . ', а заявитель «' . $applicant . '» не совпадает ни с ним, ни с владельцем точной копии' . ((int)$exact->user_id ? ' #' . (int)$exact->user_id : ' (без владельца)') . '. Ничего не меняется — назначьте владельца вручную.'];
    }

    /** ID аккаунта заявителя, если у него есть другая (не скрытая) прежняя копия этой записи WPForms. */
    /** Быстрый индекс «номер записи WPForms → прежние заявки и их аккаунты» на время задания. */
    private function cleanup_index_step(&$job) {
        global $wpdb;
        $started = microtime(true);
        if ((int)$job['cursor'] === 0) { $wpdb->query($wpdb->prepare("DELETE FROM {$this->report_table} WHERE job_token=%s AND source_type='idx'", (string)$job['token'])); }
        $placeholders = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,user_id,data_json FROM {$this->submissions_table} WHERE id>%d AND status NOT IN ($placeholders) ORDER BY id ASC LIMIT 500",
            array_merge([(int)$job['cursor']], self::archive_statuses())
        ));
        foreach ((array)$rows as $row) {
            if (microtime(true) - $started > 15) { return; }
            $job['cursor'] = (int)$row->id;
            $data = json_decode((string)$row->data_json, true);
            $entryId = is_array($data) ? $this->prior_entry_id($data) : 0;
            if (!$entryId || !(int)$row->user_id) { continue; }
            $wpdb->insert($this->report_table, ['job_token'=>(string)$job['token'], 'source_type'=>'idx', 'source_id'=>$entryId, 'decision'=>'idx', 'message'=>'', 'target_user_id'=>(int)$row->user_id, 'target_submission_id'=>(int)$row->id, 'details'=>null, 'created_at'=>current_time('mysql')]);
        }
        if (count((array)$rows) < 500) {
            $job['phase'] = 'cleanup';
            $job['cursor'] = 0;
            $job['log'][] = 'Индекс прежних заявок построен. Проверка заявок…';
        }
    }

    /** ID аккаунта заявителя, если у него есть другая прежняя копия этой записи WPForms (по индексу задания). */
    private function prior_copy_owner_matching($entryId, $exceptSubmissionId, $applicant, $jobToken = '') {
        global $wpdb;
        if (!$entryId || $applicant === '' || $jobToken === '') { return 0; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT target_user_id,target_submission_id FROM {$this->report_table} WHERE job_token=%s AND source_type='idx' AND source_id=%d AND target_submission_id<>%d LIMIT 20",
            $jobToken, (int)$entryId, (int)$exceptSubmissionId
        ));
        $want = $this->name_tokens($applicant);
        foreach ((array)$rows as $r) {
            $u = get_user_by('id', (int)$r->target_user_id);
            if (!$u) { continue; }
            $tokens = array_values(array_unique(array_merge($this->name_tokens($u->last_name . ' ' . $u->first_name), $this->name_tokens($u->display_name))));
            if ($this->tokens_match($want, $tokens) === 'yes') { return (int)$u->ID; }
        }
        return 0;
    }

    private function cleanup_step(&$job, $apply) {
        global $wpdb;
        $started = microtime(true);
        // Строки отчёта от оборванной (не сохранившей курсор) партии удаляем, чтобы не было повторов.
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->report_table} WHERE job_token=%s AND source_type='prior' AND source_id>%d", (string)$job['token'], (int)$job['cursor']));
        $placeholders = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,user_id,status,data_json FROM {$this->submissions_table} WHERE id>%d AND status NOT IN ($placeholders) ORDER BY id ASC LIMIT 200",
            array_merge([(int)$job['cursor']], self::archive_statuses())
        ));
        $stoppedEarly = false;
        foreach ((array)$rows as $row) {
            // Партия ограничена ~15 секундами, чтобы сервер не оборвал запрос.
            if (microtime(true) - $started > 15) { $stoppedEarly = true; break; }
            $job['cursor'] = (int)$row->id;
            $data = json_decode((string)$row->data_json, true);
            if (!is_array($data)) { continue; }
            $entryId = $this->prior_entry_id($data);
            if (!$entryId) { continue; }
            $this->add_stat($job, 'prior_submissions');
            $map = $this->get_map('entry', $entryId);
            $exact = ($map && (int)$map->target_submission_id) ? $wpdb->get_row($wpdb->prepare("SELECT id,user_id,status,data_json FROM {$this->submissions_table} WHERE id=%d", (int)$map->target_submission_id)) : null;
            if (!$exact) {
                $this->add_stat($job, 'no_exact_copy');
                $this->report($job, 'prior', (int)$row->id, 'no_exact_copy', 'Нет точной копии записи WPForms #' . $entryId . ' — заявка оставлена как есть.', (int)$row->user_id, 0, ['entry_id'=>$entryId]);
                continue;
            }
            $docs = $wpdb->get_results($wpdb->prepare("SELECT id,user_id,source_submission_id FROM {$this->docs_table} WHERE source_submission_id=%d", (int)$row->id));
            $details = ['entry_id'=>$entryId, 'prev_status'=>(string)$row->status, 'prev_user_id'=>(int)$row->user_id, 'exact_submission_id'=>(int)$exact->id, 'exact_user_id'=>(int)$exact->user_id, 'docs'=>[]];
            foreach ((array)$docs as $doc) { $details['docs'][] = ['id'=>(int)$doc->id, 'user_id'=>(int)$doc->user_id, 'source_submission_id'=>(int)$doc->source_submission_id]; }
            $plan = $this->cleanup_plan($row, $exact, $map, (string)$job['token']);
            $details['plan'] = $plan['action'];
            $message = 'Запись WPForms #' . $entryId . ': ' . $plan['message'] . ' Документов: ' . count($details['docs']) . '.';
            $this->add_stat($job, 'plan_' . $plan['action']);
            if ($plan['action'] === 'review') {
                $this->report($job, 'prior', (int)$row->id, $apply ? 'needs_review' : 'would_review', $message, (int)$row->user_id, (int)$exact->id, $details);
                continue;
            }
            if (!$apply) {
                $this->report($job, 'prior', (int)$row->id, $plan['action'] === 'reassign_exact' ? 'would_reassign' : 'would_supersede', $message, (int)$row->user_id, (int)$exact->id, $details);
                continue;
            }
            $docsOwner = !empty($plan['docs_owner']) ? (int)$plan['docs_owner'] : (int)$exact->user_id;
            if ($plan['action'] === 'reassign_exact') {
                // Прежняя привязка была верной (у заявителя), а точная копия — у подавшего или без
                // владельца: отдаём точную копию заявителю, прежнюю заявку скрываем, документы остаются у него.
                $details['exact_prev'] = ['user_id'=>(int)$exact->user_id, 'status'=>(string)$exact->status, 'manual'=>(int)($map->manual ?? 0), 'map_user'=>(int)($map->target_user_id ?? 0)];
                $this->set_submission_state((int)$exact->id, self::S_OWN, (int)$row->user_id, ['legacy_reassigned'=>['from'=>(int)$exact->user_id, 'reason'=>'applicant_matches_prior_owner', 'at'=>current_time('mysql')]]);
                $docsOwner = (int)$row->user_id;
            }
            $wpdb->update($this->submissions_table, ['status'=>self::S_SUPERSEDED, 'updated_at'=>current_time('mysql')], ['id'=>(int)$row->id]);
            foreach ($details['docs'] as $doc) {
                $update = ['source_submission_id'=>(int)$exact->id];
                if ($docsOwner) { $update['user_id'] = $docsOwner; }
                $wpdb->update($this->docs_table, $update, ['id'=>(int)$doc['id']]);
            }
            $this->add_stat($job, 'superseded');
            $this->report($job, 'prior', (int)$row->id, 'superseded', $message, (int)$row->user_id, (int)$exact->id, $details);
        }
        if (!$stoppedEarly && count((array)$rows) < 200) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->report_table} WHERE job_token=%s AND source_type='idx'", (string)$job['token']));
            if ($apply) { update_option(self::LAST_CLEANUP_OPT, (string)$job['token'], false); }
            $this->finish($job, $apply ? 'Скрытие завершено. Его можно откатить кнопкой «Откатить скрытие».' : 'Проверка завершена. Для применения нажмите «Скрыть дубли прежних переносов».');
        }
    }

    private function rollback_step(&$job) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,source_id,details FROM {$this->report_table} WHERE job_token=%s AND decision='superseded' AND id>%d ORDER BY id ASC LIMIT 200",
            (string)$job['rollback_token'], (int)$job['cursor']
        ));
        foreach ((array)$rows as $row) {
            $job['cursor'] = (int)$row->id;
            $details = json_decode((string)$row->details, true);
            if (!is_array($details)) { continue; }
            $wpdb->update($this->submissions_table, ['status'=>(string)($details['prev_status'] ?? 'submitted'), 'user_id'=>(int)($details['prev_user_id'] ?? 0), 'updated_at'=>current_time('mysql')], ['id'=>(int)$row->source_id, 'status'=>self::S_SUPERSEDED]);
            foreach ((array)($details['docs'] ?? []) as $doc) {
                $wpdb->update($this->docs_table, ['user_id'=>(int)$doc['user_id'], 'source_submission_id'=>(int)$doc['source_submission_id']], ['id'=>(int)$doc['id']]);
            }
            if (!empty($details['exact_prev']) && !empty($details['exact_submission_id'])) {
                $ep = $details['exact_prev'];
                $this->set_submission_state((int)$details['exact_submission_id'], (string)$ep['status'], (int)$ep['user_id'], ['legacy_reassigned'=>null]);
                $this->save_map('entry', (int)$details['entry_id'], ['manual'=>(int)$ep['manual'], 'target_user_id'=>(int)$ep['map_user']]);
            }
            $this->add_stat($job, 'restored');
        }
        if (count((array)$rows) < 200) {
            delete_option(self::LAST_CLEANUP_OPT);
            $this->finish($job, 'Откат завершён: статусы и документы возвращены.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Отчёт                                                              */
    /* ------------------------------------------------------------------ */

    public function download_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') { $job = (array)get_option(self::JOB_OPT, []); $token = (string)($job['token'] ?? ''); }
        if ($token === '') { wp_die('Отчёт не найден.'); }
        // Предупреждения PHP других плагинов не должны попадать внутрь CSV.
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-exact-report-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        zau_fputcsv($out, ['Тип', 'Старый ID', 'Решение', 'Пояснение', 'Аккаунт нового сайта', 'Заявка нового сайта', 'ФИО в заявлении', 'Совпадение ФИО', 'ФИО в старом аккаунте', 'Аккаунт нового сайта: ФИО и email'], ';');
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->report_table} WHERE job_token=%s ORDER BY id ASC LIMIT 2000 OFFSET %d", $token, $offset));
            foreach ((array)$rows as $row) {
                $details = json_decode((string)$row->details, true);
                $oldName = (string)($details['old_name'] ?? '');
                if ($oldName === '' && $row->source_type === 'user' && is_array($details)) {
                    $oldName = trim(trim(($details['last'] ?? '') . ' ' . ($details['first'] ?? '')) . ' / ' . ($details['display'] ?? ''), ' /');
                }
                $newUser = (int)$row->target_user_id ? get_user_by('id', (int)$row->target_user_id) : false;
                zau_fputcsv($out, [$row->source_type, $row->source_id, $row->decision, $row->message, $row->target_user_id ?: '', $row->target_submission_id ?: '', $details['applicant'] ?? '', $details['match'] ?? '', $oldName, $newUser ? trim($newUser->display_name . ' <' . $newUser->user_email . '>') : ''], ';');
            }
            $offset += count((array)$rows);
        } while (count((array)$rows) === 2000);
        fclose($out);
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Очередь администратора                                             */
    /* ------------------------------------------------------------------ */

    private function find_user_by_ref($ref) {
        $ref = trim((string)$ref);
        if ($ref === '') { return false; }
        if (ctype_digit($ref)) { return get_user_by('id', (int)$ref); }
        if (is_email($ref)) { return get_user_by('email', $ref); }
        return get_user_by('login', $ref);
    }

    private function set_submission_state($submissionId, $status, $userId = null, array $dataPatch = []) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d", (int)$submissionId));
        if (!$row) { return false; }
        $data = json_decode((string)$row->data_json, true);
        if (!is_array($data) || empty($data['legacy_exact'])) { return false; }
        foreach ($dataPatch as $key => $value) {
            if ($value === null) { unset($data[$key]); } else { $data[$key] = $value; }
        }
        $update = ['status'=>$status, 'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at'=>current_time('mysql')];
        if ($userId !== null) { $update['user_id'] = (int)$userId; }
        $wpdb->update($this->submissions_table, $update, ['id'=>(int)$row->id]);
        $entryId = (int)($data['legacy_entry_id'] ?? 0);
        if ($entryId) {
            $map = $this->get_map('entry', $entryId);
            if ($map) { $this->save_map('entry', $entryId, ['manual'=>1, 'target_user_id'=>$userId !== null ? (int)$userId : (int)$row->user_id]); }
        }
        return true;
    }

    /** Скрывать безопасно, если аккаунтом не пользовались: нет email, входов, обычных заявлений и документов. */
    private function junk_account_info($userId) {
        global $wpdb;
        $user = get_user_by('id', (int)$userId);
        $logs = $wpdb->prefix . 'zau_cert_logs';
        $logins = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs)) === $logs
            ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE user_id=%d AND action IN ('otp_login','pin_login','password_login','pin_reset_login')", (int)$userId)) : 0;
        $ph = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $subs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE user_id=%d AND status NOT IN ($ph)", array_merge([(int)$userId], self::archive_statuses())));
        $docs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->docs_table} WHERE user_id=%d", (int)$userId));
        $reasons = [];
        if ($user && trim((string)$user->user_email) !== '') { $reasons[] = 'есть email'; }
        if ($logins) { $reasons[] = 'в аккаунт входили'; }
        if ($subs) { $reasons[] = 'есть обычные заявления'; }
        if ($docs) { $reasons[] = 'есть документы'; }
        return ['logins'=>$logins, 'subs'=>$subs, 'docs'=>$docs, 'safe'=>!$reasons, 'reasons'=>$reasons];
    }

    private function prior_review_count() {
        global $wpdb;
        $token = (string)get_option(self::LAST_CLEANUP_OPT, '');
        if ($token === '') { return 0; }
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->report_table} r JOIN {$this->submissions_table} s ON s.id=r.source_id WHERE r.job_token=%s AND r.decision='needs_review' AND s.status<>%s", $token, self::S_SUPERSEDED));
    }

    private function hide_junk($userId) {
        $userId = (int)$userId;
        if (!get_user_meta($userId, 'zau_exact_prior_wrong', true) || get_user_meta($userId, 'zau_exact_junk_hidden', true)) { return false; }
        update_user_meta($userId, 'zau_exact_junk_prev_status', (string)get_user_meta($userId, 'zau_member_status', true));
        update_user_meta($userId, 'zau_exact_junk_prev_hidden', get_user_meta($userId, 'zau_hidden_from_registry', true) ? 1 : 0);
        update_user_meta($userId, 'zau_member_status', 'Дубликат переноса');
        update_user_meta($userId, 'zau_hidden_from_registry', 1);
        $real = (int)get_user_meta($userId, 'zau_exact_prior_wrong_real', true);
        if ($real) { update_user_meta($userId, 'zau_legacy_duplicate_of', $real); }
        update_user_meta($userId, 'zau_exact_junk_hidden', current_time('mysql'));
        return true;
    }

    private function restore_junk($userId) {
        $userId = (int)$userId;
        if (!get_user_meta($userId, 'zau_exact_junk_hidden', true)) { return false; }
        $prev = (string)get_user_meta($userId, 'zau_exact_junk_prev_status', true);
        if ($prev !== '') { update_user_meta($userId, 'zau_member_status', $prev); } else { delete_user_meta($userId, 'zau_member_status'); }
        if (!get_user_meta($userId, 'zau_exact_junk_prev_hidden', true)) { delete_user_meta($userId, 'zau_hidden_from_registry'); }
        delete_user_meta($userId, 'zau_legacy_duplicate_of');
        delete_user_meta($userId, 'zau_exact_junk_hidden');
        return true;
    }

    public function queue_action() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $do = sanitize_key((string)($_POST['do'] ?? ''));
        $submissionId = absint($_POST['submission_id'] ?? 0);
        $userId = absint($_POST['user_id'] ?? 0);
        $view = sanitize_key((string)($_POST['view'] ?? 'disputed'));
        $msg = 'Готово.';
        if ($do === 'assign') {
            $target = $this->find_user_by_ref(wp_unslash($_POST['target'] ?? ''));
            if (!$target) { $msg = 'Аккаунт не найден. Укажите ID, email или логин.'; }
            else {
                $this->set_submission_state($submissionId, sanitize_key($_POST['as'] ?? '') === 'other' ? self::S_OTHER : self::S_OWN, (int)$target->ID, ['legacy_admin_assigned'=>['by'=>get_current_user_id(), 'at'=>current_time('mysql')], 'legacy_dispute'=>null]);
                $msg = 'Заявка передана аккаунту #' . (int)$target->ID . ' (' . $target->display_name . ').';
            }
        } elseif ($do === 'keep_owner') {
            $this->set_submission_state($submissionId, self::S_OWN, null, ['legacy_dispute'=>null, 'legacy_admin_assigned'=>['by'=>get_current_user_id(), 'at'=>current_time('mysql'), 'kept'=>1]]);
            $msg = 'Отметка снята, заявка оставлена у владельца.';
        } elseif ($do === 'mark_other') {
            $this->set_submission_state($submissionId, self::S_OTHER, null, ['legacy_dispute'=>null]);
            $msg = 'Заявка оставлена у подавшего с пометкой «за другого человека».';
        } elseif ($do === 'merge' && $userId) {
            $into = absint($_POST['into'] ?? 0);
            $msg = $this->merge_accounts($userId, $into) ? 'Аккаунты объединены: всё перенесено в аккаунт #' . $into . '. Созданный переносом аккаунт #' . $userId . ' скрыт как дубль.' : 'Не удалось объединить аккаунты.';
        } elseif ($do === 'prior_hide' && $submissionId) {
            global $wpdb;
            $token = (string)get_option(self::LAST_CLEANUP_OPT, '');
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,user_id,status FROM {$this->submissions_table} WHERE id=%d", $submissionId));
            if ($token && $row && $row->status !== self::S_SUPERSEDED) {
                $wpdb->update($this->submissions_table, ['status'=>self::S_SUPERSEDED, 'updated_at'=>current_time('mysql')], ['id'=>$submissionId]);
                // Запись в отчёт последнего скрытия — «Откатить скрытие» вернёт и её.
                $this->report(['token'=>$token], 'prior', $submissionId, 'superseded', 'Скрыто администратором вручную из очереди «Прежние заявки на проверку».', (int)$row->user_id, 0, ['prev_status'=>(string)$row->status, 'prev_user_id'=>(int)$row->user_id, 'docs'=>[], 'manual'=>1]);
                $msg = 'Прежняя копия #' . $submissionId . ' скрыта.';
            } else { $msg = 'Не удалось скрыть: нет данных последнего скрытия или копия уже скрыта.'; }
        } elseif ($do === 'junk_hide' && $userId) {
            $msg = $this->hide_junk($userId) ? 'Ошибочный аккаунт #' . $userId . ' скрыт.' : 'Аккаунт не скрыт.';
        } elseif ($do === 'junk_restore' && $userId) {
            $msg = $this->restore_junk($userId) ? 'Аккаунт #' . $userId . ' возвращён.' : 'Аккаунт не был скрыт.';
        } elseif ($do === 'junk_hide_all') {
            global $wpdb;
            $n = 0;
            foreach ((array)$wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_prior_wrong'") as $id) {
                $info = $this->junk_account_info((int)$id);
                if ($info['safe'] && $this->hide_junk((int)$id)) { $n++; }
            }
            $msg = 'Скрыто ошибочных аккаунтов: ' . $n . '. Аккаунты, которыми пользовались, оставлены для ручной проверки.';
        } elseif ($do === 'not_duplicate' && $userId) {
            delete_user_meta($userId, 'zau_exact_possible_duplicate');
            $msg = 'Отмечено: это разные люди.';
        } elseif ($do === 'restore_profile' && $userId) {
            $msg = $this->restore_profile($userId) ? 'Профиль пересобран из старого аккаунта. Прежние значения сохранены в резервной копии.' : 'Нет копии старого аккаунта для этого пользователя.';
        } elseif ($do === 'undo_restore' && $userId) {
            $msg = $this->undo_restore($userId) ? 'Профиль возвращён к состоянию до восстановления.' : 'Резервная копия не найдена.';
        } elseif ($do === 'close_profile_dispute' && $userId) {
            $dispute = $this->user_json_meta($userId, 'zau_exact_profile_dispute');
            if ($dispute) { $dispute['status'] = 'closed'; $dispute['closed_at'] = current_time('mysql'); $this->update_user_json_meta($userId, 'zau_exact_profile_dispute', $dispute); }
            delete_user_meta($userId, 'zau_exact_prior_mismatch');
            $msg = 'Отметка закрыта.';
        }
        wp_safe_redirect(add_query_arg(['page'=>'zau-exact-migration', 'view'=>$view, 'msg'=>rawurlencode($msg)], admin_url('admin.php')) . '#zau-exact-queue');
        exit;
    }

    private function profile_meta_keys() {
        return ['zau_phone', 'zau_profile_iin', 'zau_profile_birth_date', 'zau_profile_address', 'zau_profile_position', 'zau_profile_department', 'zau_profile_organization', 'zau_profile_organization_bin', 'zau_profile_organization_director', 'zau_profile_organization_address', 'zau_profile_branch_name', 'zau_profile_middle_name', 'zau_member_card_data', 'zau_organization_id', 'zau_organization_name', 'zau_organization_bin', 'zau_profile_branch_id', 'zau_legacy_iin'];
    }

    /** Пересобирает профиль ТОЛЬКО из копии старого аккаунта и собственных заявлений; прежние значения сохраняются. */
    private function restore_profile($userId) {
        $raw = $this->user_json_meta($userId, 'zau_exact_legacy_raw');
        $user = get_user_by('id', $userId);
        if (!$raw || !$user) { return false; }
        $backup = ['at'=>current_time('mysql'), 'by'=>get_current_user_id(), 'user'=>['first_name'=>$user->first_name, 'last_name'=>$user->last_name, 'display_name'=>$user->display_name], 'meta'=>[]];
        foreach ($this->profile_meta_keys() as $key) { $backup['meta'][$key] = get_user_meta($userId, $key, true); }
        $backups = $this->user_json_meta($userId, 'zau_exact_profile_backups');
        $backups[] = $backup;
        $this->update_user_json_meta($userId, 'zau_exact_profile_backups', array_slice($backups, -5));
        foreach ($this->profile_meta_keys() as $key) { delete_user_meta($userId, $key); }
        wp_update_user(['ID'=>$userId, 'first_name'=>'', 'last_name'=>'', 'display_name'=>$user->user_login]);
        $this->fill_profile_from_account($userId, $raw, false);
        $this->enrich_from_own_entries($userId, false);
        update_user_meta($userId, 'zau_member_card_review_status', 'pending');
        update_user_meta($userId, 'zau_exact_profile_restored_at', current_time('mysql'));
        delete_user_meta($userId, 'zau_exact_prior_mismatch');
        $dispute = $this->user_json_meta($userId, 'zau_exact_profile_dispute');
        if ($dispute) { $dispute['status'] = 'resolved'; $dispute['closed_at'] = current_time('mysql'); $this->update_user_json_meta($userId, 'zau_exact_profile_dispute', $dispute); }
        return true;
    }

    private function undo_restore($userId) {
        $backups = $this->user_json_meta($userId, 'zau_exact_profile_backups');
        $last = array_pop($backups);
        if (!is_array($last)) { return false; }
        wp_update_user(array_merge(['ID'=>$userId], (array)($last['user'] ?? [])));
        foreach ((array)($last['meta'] ?? []) as $key => $value) {
            if ($value === '' || $value === null) { delete_user_meta($userId, $key); }
            else { update_user_meta($userId, $key, is_string($value) ? wp_slash($value) : $value); }
        }
        $this->update_user_json_meta($userId, 'zau_exact_profile_backups', $backups);
        return true;
    }

    private function queue_counts() {
        global $wpdb;
        return [
            'disputed'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE status=%s", self::S_DISPUTED)),
            'profiles'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE (meta_key='zau_exact_profile_dispute' AND meta_value LIKE '%\"status\":\"open\"%') OR meta_key='zau_exact_prior_mismatch'"),
            'unassigned'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE status=%s", self::S_UNASSIGNED)),
            'other'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE status=%s", self::S_OTHER)),
            'duplicates'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_possible_duplicate'"),
            'prior_review'=>$this->prior_review_count(),
            'junk'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT m.user_id) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->usermeta} h ON h.user_id=m.user_id AND h.meta_key='zau_exact_junk_hidden' WHERE m.meta_key='zau_exact_prior_wrong' AND h.umeta_id IS NULL"),
        ];
    }

    private function queue_form($do, $label, array $hidden, $extra = '', $class = 'button') {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zau-exact-inline">';
        $html .= '<input type="hidden" name="action" value="zau_exact_queue_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        $html .= wp_nonce_field(self::NONCE, '_wpnonce', true, false);
        foreach ($hidden as $k => $v) { $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">'; }
        return $html . $extra . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private function render_queue() {
        global $wpdb;
        $view = sanitize_key((string)($_GET['view'] ?? 'disputed'));
        if (!in_array($view, ['disputed', 'profiles', 'duplicates', 'junk', 'prior_review', 'unassigned', 'other'], true)) { $view = 'disputed'; }
        $counts = $this->queue_counts();
        $labels = ['disputed'=>'Пользователь отметил «не моё»', 'profiles'=>'Профили на проверку', 'duplicates'=>'Возможные дубли', 'junk'=>'Ошибочные аккаунты прежнего переноса', 'prior_review'=>'Прежние заявки на проверку', 'unassigned'=>'Без владельца', 'other'=>'Поданы за другого человека'];
        echo '<section class="zau-rb-card" id="zau-exact-queue"><h2>5. Очередь проверки</h2>';
        if (!empty($_GET['msg'])) { echo '<div class="notice notice-info inline"><p>' . esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['msg'])))) . '</p></div>'; }
        echo '<p class="zau-exact-tabs">';
        foreach ($labels as $key => $label) {
            echo '<a class="button' . ($key === $view ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg(['page'=>'zau-exact-migration', 'view'=>$key], admin_url('admin.php')) . '#zau-exact-queue') . '">' . esc_html($label) . ' (' . (int)$counts[$key] . ')</a> ';
        }
        echo '</p>';
        if ($view === 'prior_review') {
            $token = (string)get_option(self::LAST_CLEANUP_OPT, '');
            $rows = $token ? $wpdb->get_results($wpdb->prepare("SELECT r.source_id,r.message,s.user_id,s.status FROM {$this->report_table} r JOIN {$this->submissions_table} s ON s.id=r.source_id WHERE r.job_token=%s AND r.decision='needs_review' AND s.status<>%s ORDER BY r.id ASC LIMIT 500", $token, self::S_SUPERSEDED)) : [];
            echo '<p class="description">Заявки прежних переносов, которые «Скрыть дубли прежних переносов» не стал трогать: по ФИО нельзя уверенно сказать, чья это заявка. Точная копия каждой записи уже есть (см. «Без владельца» или «Поданы за другого человека»). Если прежняя копия у чужого человека — «Скрыть прежнюю копию» (документы не переносятся, всё откатывается кнопкой «Откатить скрытие»).</p>';
            echo '<table class="widefat striped"><thead><tr><th>Прежняя заявка</th><th>Сейчас у аккаунта</th><th>Почему не тронута</th><th>Действие</th></tr></thead><tbody>';
            if (!$rows) { echo '<tr><td colspan="4">' . ($token ? 'Нет прежних заявок на проверку.' : 'Сначала выполните «Скрыть дубли прежних переносов».') . '</td></tr>'; }
            foreach ((array)$rows as $r) {
                $u = get_user_by('id', (int)$r->user_id);
                echo '<tr><td>#' . (int)$r->source_id . '</td><td>' . ($u ? '<a href="' . esc_url(get_edit_user_link((int)$u->ID)) . '">#' . (int)$u->ID . '</a> ' . esc_html($u->display_name) . '<br><small>' . esc_html($u->user_email) . '</small>' : '—') . '</td><td>' . esc_html((string)$r->message) . '</td><td>' . $this->queue_form('prior_hide', 'Скрыть прежнюю копию', ['submission_id'=>(int)$r->source_id, 'view'=>$view]) . '</td></tr>';
            }
            echo '</tbody></table></section>';
            return;
        }
        if ($view === 'junk') {
            $ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_prior_wrong' ORDER BY user_id ASC LIMIT 1000");
            echo '<p class="description">Прежний перенос создал эти аккаунты без email и записал в них номер старого аккаунта, но с ФИО другого человека (чаще всего руководителя или председателя из заявления). Настоящим владельцам точный перенос создал их собственные аккаунты. «Скрыть» ставит статус «Дубликат переноса» и убирает аккаунт из реестра; ничего не удаляется, «Вернуть» отменяет. Сначала выполните «Скрыть дубли прежних переносов» — тогда заявки и PDF уйдут из этих аккаунтов к настоящим владельцам.</p>';
            $safe = 0;
            $rowsHtml = '';
            foreach ((array)$ids as $id) {
                $id = (int)$id;
                $user = get_user_by('id', $id);
                if (!$user) { continue; }
                $info = $this->junk_account_info($id);
                $real = (int)get_user_meta($id, 'zau_exact_prior_wrong_real', true);
                $realUser = $real ? get_user_by('id', $real) : false;
                $hidden = (bool)get_user_meta($id, 'zau_exact_junk_hidden', true);
                if (!$hidden && $info['safe']) { $safe++; }
                $state = $hidden ? '<strong>Скрыт</strong>' : ($info['safe'] ? 'Можно скрыть' : '<span style="color:#b42318">Проверить вручную: ' . esc_html(implode(', ', $info['reasons'])) . '</span>');
                $action = $hidden ? $this->queue_form('junk_restore', 'Вернуть', ['user_id'=>$id, 'view'=>$view]) : $this->queue_form('junk_hide', 'Скрыть', ['user_id'=>$id, 'view'=>$view], '', $info['safe'] ? 'button button-primary' : 'button');
                $rowsHtml .= '<tr><td><a href="' . esc_url(get_edit_user_link($id)) . '">#' . $id . '</a> ' . esc_html($user->display_name) . '</td><td>' . ($realUser ? '<a href="' . esc_url(get_edit_user_link($real)) . '">#' . $real . '</a> ' . esc_html($realUser->display_name) . '<br><small>' . esc_html($realUser->user_email) . '</small>' : 'старый #' . (int)get_user_meta($id, 'zau_exact_prior_wrong', true)) . '</td><td>' . esc_html('входов: ' . $info['logins'] . ', заявлений: ' . $info['subs'] . ', документов: ' . $info['docs']) . '</td><td>' . $state . '</td><td>' . $action . '</td></tr>';
            }
            if ($safe) { echo '<p>' . $this->queue_form('junk_hide_all', 'Скрыть все безопасные (' . $safe . ')', ['view'=>$view], '', 'button button-primary') . '</p>'; }
            echo '<table class="widefat striped"><thead><tr><th>Ошибочный аккаунт</th><th>Настоящий владелец (создан точным переносом)</th><th>Что в аккаунте</th><th>Состояние</th><th>Действие</th></tr></thead><tbody>';
            echo $rowsHtml ?: '<tr><td colspan="5">Ошибочных аккаунтов нет.</td></tr>';
            echo '</tbody></table></section>';
            return;
        }
        if ($view === 'duplicates') {
            $ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_possible_duplicate' ORDER BY user_id DESC LIMIT 200");
            echo '<p class="description">Перенос создал аккаунт по email со старого сайта, а на новом сайте уже есть аккаунт с тем же ФИО и другим email. Если это один человек — «Объединить»: заявления, документы и данные старого аккаунта перейдут в существующий аккаунт, его email не изменится, а созданный аккаунт станет скрытым дублем без email. Если это однофамильцы — «Это разные люди».</p>';
            echo '<table class="widefat striped"><thead><tr><th>Создан переносом</th><th>Уже был на новом сайте</th><th>Действия</th></tr></thead><tbody>';
            if (!$ids) { echo '<tr><td colspan="3">Возможных дублей нет.</td></tr>'; }
            foreach ((array)$ids as $id) {
                $user = get_user_by('id', (int)$id);
                if (!$user) { continue; }
                $cands = (array)get_user_meta((int)$id, 'zau_exact_possible_duplicate', true);
                $subs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE user_id=%d", (int)$id));
                $cell = ''; $actions = '';
                foreach ($cands as $cid) {
                    $c = get_user_by('id', (int)$cid);
                    if (!$c) { continue; }
                    $csubs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE user_id=%d", (int)$cid));
                    $cell .= '<div><a href="' . esc_url(get_edit_user_link((int)$cid)) . '">#' . (int)$cid . '</a> ' . esc_html($c->display_name) . '<br><small>' . esc_html($c->user_email) . ' · заявлений: ' . $csubs . ' · с ' . esc_html(mysql2date('d.m.Y', $c->user_registered)) . '</small></div>';
                    $actions .= $this->queue_form('merge', 'Объединить с #' . (int)$cid, ['user_id'=>(int)$id, 'into'=>(int)$cid, 'view'=>$view], '', 'button button-primary');
                }
                $actions .= $this->queue_form('not_duplicate', 'Это разные люди', ['user_id'=>(int)$id, 'view'=>$view]);
                echo '<tr><td><a href="' . esc_url(get_edit_user_link((int)$id)) . '">#' . (int)$id . '</a> ' . esc_html($user->display_name) . '<br><small>' . esc_html($user->user_email) . ' · старый аккаунт #' . (int)get_user_meta((int)$id, 'zau_exact_legacy_user_id', true) . ' · заявлений: ' . $subs . '</small></td><td>' . $cell . '</td><td>' . $actions . '</td></tr>';
            }
            echo '</tbody></table></section>';
            return;
        }
        if ($view === 'profiles') {
            $ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE (meta_key='zau_exact_profile_dispute' AND meta_value LIKE '%\"status\":\"open\"%') OR meta_key='zau_exact_prior_mismatch' ORDER BY user_id DESC LIMIT 200");
            echo '<p class="description">Пользователь сообщил о чужих данных в профиле, или прежний перенос связывал аккаунт с другим старым пользователем. «Пересобрать профиль» заменит ФИО, телефон, ИИН, организацию и карточку данными из старого аккаунта и собственных заявлений; прежние значения сохраняются и возвращаются кнопкой «Вернуть как было».</p>';
            echo '<table class="widefat striped"><thead><tr><th>Аккаунт</th><th>Сейчас в профиле</th><th>Старый аккаунт</th><th>Отметка</th><th>Действия</th></tr></thead><tbody>';
            if (!$ids) { echo '<tr><td colspan="5">Нет профилей на проверку.</td></tr>'; }
            foreach ((array)$ids as $id) {
                $user = get_user_by('id', (int)$id);
                if (!$user) { continue; }
                $raw = $this->user_json_meta((int)$id, 'zau_exact_legacy_raw');
                $dispute = $this->user_json_meta((int)$id, 'zau_exact_profile_dispute');
                $prior = (string)get_user_meta((int)$id, 'zau_exact_prior_mismatch', true);
                $card = json_decode((string)get_user_meta((int)$id, 'zau_member_card_data', true), true);
                $now = esc_html($user->display_name) . '<br><small>' . esc_html(trim(get_user_meta((int)$id, 'zau_phone', true) . ' · ИИН ' . ($card['iin'] ?? get_user_meta((int)$id, 'zau_profile_iin', true)) . ' · ' . get_user_meta((int)$id, 'zau_organization_name', true), ' ·')) . '</small>';
                $old = $raw ? esc_html('#' . (int)$raw['id'] . ' ' . trim($this->meta_first((array)$raw['meta'], 'last_name') . ' ' . $this->meta_first((array)$raw['meta'], 'first_name')) . ' / ' . ($raw['display_name'] ?? '') . ' · ' . ($raw['user_email'] ?? '')) : '<em>нет копии — сначала выполните точный перенос</em>';
                $mark = [];
                if (($dispute['status'] ?? '') === 'open') { $mark[] = 'Пользователь: «' . esc_html((string)($dispute['comment'] ?? '')) . '» (' . esc_html((string)($dispute['at'] ?? '')) . ')'; }
                if ($prior !== '') { $mark[] = ctype_digit($prior) ? 'Прежний перенос связывал со старым #' . esc_html($prior) : 'ФИО в аккаунте отличается от старого аккаунта'; }
                $actions = '';
                if ($raw) { $actions .= $this->queue_form('restore_profile', 'Пересобрать профиль', ['user_id'=>(int)$id, 'view'=>$view], '', 'button button-primary'); }
                if ($this->user_json_meta((int)$id, 'zau_exact_profile_backups')) { $actions .= $this->queue_form('undo_restore', 'Вернуть как было', ['user_id'=>(int)$id, 'view'=>$view]); }
                $actions .= $this->queue_form('close_profile_dispute', 'Данные верны', ['user_id'=>(int)$id, 'view'=>$view]);
                echo '<tr><td><a href="' . esc_url(get_edit_user_link((int)$id)) . '">#' . (int)$id . '</a> ' . esc_html($user->user_email) . '</td><td>' . $now . '</td><td>' . $old . '</td><td>' . implode('<br>', $mark) . '</td><td>' . $actions . '</td></tr>';
            }
            echo '</tbody></table></section>';
            return;
        }
        $status = ['disputed'=>self::S_DISPUTED, 'unassigned'=>self::S_UNASSIGNED, 'other'=>self::S_OTHER][$view];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT s.*,u.display_name,u.user_email FROM {$this->submissions_table} s LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id WHERE s.status=%s ORDER BY s.id DESC LIMIT 200", $status));
        $hint = [
            'disputed'=>'Пользователь нажал «Это не моё заявление». Передайте заявку настоящему владельцу (ID, email или логин) либо снимите отметку.',
            'unassigned'=>'Данные записи полностью сохранены, но старый аккаунт не перенесён или запись подана без входа. Назначьте владельца вручную.',
            'other'=>'ФИО в заявлении не совпало с владельцем аккаунта: заявку подавали за другого человека. Она видна подавшему с пометкой и не заполняет его профиль. При желании передайте её аккаунту самого заявителя.',
        ][$view];
        echo '<p class="description">' . esc_html($hint) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Заявка</th><th>Форма / дата</th><th>ФИО в заявлении</th><th>Сейчас у аккаунта</th><th>Отметка</th><th>Действия</th></tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="6">Пусто.</td></tr>'; }
        foreach ((array)$rows as $row) {
            $data = json_decode((string)$row->data_json, true) ?: [];
            $dispute = (array)($data['legacy_dispute'] ?? []);
            $assign = $this->queue_form('assign', 'Передать', ['submission_id'=>(int)$row->id, 'view'=>$view], '<input type="text" name="target" placeholder="ID, email или логин" size="18" required> ', 'button button-primary');
            $actions = $assign;
            if ($view === 'disputed') {
                $actions .= $this->queue_form('keep_owner', 'Оставить владельцу', ['submission_id'=>(int)$row->id, 'view'=>$view]);
                $actions .= $this->queue_form('mark_other', 'Подано за другого', ['submission_id'=>(int)$row->id, 'view'=>$view]);
            }
            $fieldsHtml = '';
            foreach ((array)($data['legacy_fields'] ?? []) as $f) { if (($f['value'] ?? '') !== '') { $fieldsHtml .= '<div><strong>' . esc_html($f['label']) . ':</strong> ' . esc_html(wp_trim_words((string)$f['value'], 30)) . '</div>'; } }
            echo '<tr><td>#' . (int)$row->id . '<br><small>WPForms #' . (int)($data['legacy_entry_id'] ?? 0) . '</small><details><summary>Поля</summary>' . $fieldsHtml . '</details></td>';
            echo '<td>' . esc_html((string)($data['legacy_form_title'] ?? '')) . '<br><small>' . esc_html((string)$row->created_at) . '</small></td>';
            echo '<td>' . esc_html((string)($data['legacy_applicant_name'] ?? '')) . '</td>';
            echo '<td>' . ((int)$row->user_id ? '#' . (int)$row->user_id . ' ' . esc_html((string)$row->display_name) . '<br><small>' . esc_html((string)$row->user_email) . '</small>' : '—') . '</td>';
            echo '<td>' . ($dispute ? esc_html('«' . ($dispute['comment'] ?? '') . '» ' . ($dispute['at'] ?? '')) : '') . '</td>';
            echo '<td>' . $actions . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    public function page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        $s = $this->settings();
        $job = (array)get_option(self::JOB_OPT, []);
        ?>
        <div class="wrap zau-remote-wrap zau-exact-wrap">
            <?php if (function_exists('zau_admin_hub_nav')) { zau_admin_hub_nav('import'); } ?>
            <h1>Точный перенос 1:1 со старого сайта</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div><?php endif; ?>
            <section class="zau-rb-card zau-exact-principles">
                <h2>Как работает и чем отличается от прежних инструментов</h2>
                <ol>
                    <li><strong>Один старый аккаунт = один аккаунт нового сайта.</strong> Связь — только по старому User ID; существующий аккаунт используется лишь при точном совпадении email. Никаких объединений по ФИО, телефону или ИИН.</li>
                    <li><strong>Профиль — только из самого аккаунта</strong> (Ultimate Member). Из заявлений заполняются лишь пустые поля и только если ФИО в заявлении совпадает с владельцем. Данные, введённые на новом сайте, не перезаписываются.</li>
                    <li><strong>Каждая запись WPForms — отдельная архивная заявка</strong>, без «выбора последней» и без удаления дублей. Заявка, поданная с аккаунта за другого человека, остаётся у подавшего с пометкой и не влияет на его профиль.</li>
                    <li><strong>Сверка по SHA-256</strong> сравнивает сохранённую копию каждой записи с живыми данными старого сайта и показывает, перенесено ли 100%.</li>
                    <li>В личном кабинете пользователь видит все свои старые заявления, может <strong>пересоздать</strong> любое из них, отметить <strong>«Это не моё»</strong> и сообщить о <strong>чужих данных в профиле</strong>. Отметки попадают в очередь ниже.</li>
                </ol>
                <p class="description">Старый сайт: установите плагин <code>old-site-bridge/zau-legacy-exact-export.php</code> (из архива этого плагина) → Инструменты → ZAU точный экспорт → включите и скопируйте ключ.</p>
            </section>

            <section class="zau-rb-card">
                <h2>1. Подключение</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="zau_exact_save">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <div class="zau-rb-grid">
                        <label>Адрес старого сайта<input type="url" name="old_url" value="<?php echo esc_attr($s['old_url']); ?>" placeholder="https://uchet.zdravunion.kz" required></label>
                        <label>Ключ моста 2.0<input type="password" name="secret" value="" autocomplete="new-password" placeholder="<?php echo $s['secret'] !== '' ? 'сохранён — оставьте пустым' : 'вставьте ключ'; ?>"></label>
                        <label>Записей за запрос<input type="number" name="batch_size" min="10" max="100" step="10" value="<?php echo (int)$s['batch_size']; ?>"></label>
                        <label>Статус одобренных аккаунтов<select name="default_status"><?php foreach (['Состоит в профсоюзе', 'Заявление подано', 'На рассмотрении'] as $status): ?><option <?php selected($s['default_status'], $status); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label>
                    </div>
                    <div class="zau-rb-checks">
                        <label><input type="checkbox" name="create_users" value="1" <?php checked(!empty($s['create_users'])); ?>> Создавать аккаунты, которых нет на новом сайте</label>
                        <label><input type="checkbox" name="preserve_password" value="1" <?php checked(!empty($s['preserve_password'])); ?>> Сохранять старый пароль у новых аккаунтов (можно войти прежним паролем)</label>
                        <label><input type="checkbox" name="skip_admins" value="1" <?php checked(!empty($s['skip_admins'])); ?>> Не переносить администраторов старого сайта</label>
                        <label><input type="checkbox" name="download_files" value="1" <?php checked(!empty($s['download_files'])); ?>> Скачивать файлы из заявлений (подписи, вложения) со старого сайта</label>
                    </div>
                    <p><button class="button button-primary">Сохранить</button> <button type="button" class="button" data-zau-exact-test>Проверить подключение</button></p>
                    <div class="zau-rb-message" data-zau-exact-message></div>
                </form>
            </section>

            <?php $pc = $this->progress_counts(); ?>
            <div class="notice <?php echo ($pc['user'] || $pc['entry']) ? 'notice-info' : 'notice-warning'; ?> inline"><p>
                <?php if ($pc['user'] || $pc['entry']): ?>
                    <strong>Уже перенесено с этого старого сайта:</strong> аккаунтов <?php echo (int)$pc['user']; ?>, записей WPForms <?php echo (int)$pc['entry']; ?>.
                <?php else: ?>
                    <strong>Настоящий перенос с этого сайта ещё не выполнялся.</strong> Пробный перенос ничего не записывает — после него нажмите «Перенести», и только потом «Сверка 100%».
                <?php endif; ?>
                <?php if ($pc['other_sources']): ?> В базе есть ещё <?php echo (int)$pc['other_sources']; ?> записей, перенесённых с другого адреса старого сайта — проверьте поле «Адрес старого сайта».<?php endif; ?>
            </p></div>
            <section class="zau-rb-card">
                <h2>2. Перенос и 3. Сверка</h2>
                <p>Сначала — <strong>пробный перенос</strong> (ничего не меняет) и отчёт. Затем <strong>перенос</strong>. После — <strong>сверка</strong>: она должна показать 100%. Перенос можно запускать повторно: уже перенесённое не дублируется, изменённое на старом сайте обновляется, ручные решения сохраняются.</p>
                <p>
                    <button class="button button-hero" data-zau-exact-start="dry">Пробный перенос</button>
                    <button class="button button-primary button-hero" data-zau-exact-start="import" data-confirm="Сделайте резервную копию базы. Начать перенос?">Перенести</button>
                    <button class="button button-hero" data-zau-exact-start="verify">Сверка 100%</button>
                    <button class="button" data-zau-exact-resume hidden>Продолжить</button>
                    <button class="button" data-zau-exact-reset>Сбросить задание</button>
                </p>
                <div class="zau-rb-progress" data-zau-exact-progress hidden><span></span></div>
                <p data-zau-exact-progress-text></p>
                <div data-zau-exact-summary></div>
                <div class="zau-rb-stats" data-zau-exact-stats></div>
                <pre data-zau-exact-log><?php echo esc_html(implode("\n", (array)($job['log'] ?? []))); ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_exact_report'), self::NONCE)); ?>">Скачать отчёт последнего задания (CSV)</a></p>
                <details><summary>Что означают строки отчёта</summary><ul class="zau-exact-legend">
                    <li><code>created</code>/<code>linked_existing</code> — аккаунт создан / связан по точному email;</li>
                    <li><code>conflict</code> — email уже связан с другим старым аккаунтом, нужен ручной разбор;</li>
                    <li><code>own</code> — заявление владельца; <code>other_person</code> — подано с аккаунта за другого человека; <code>unassigned</code> — владелец не определён;</li>
                    <li><code>unchanged</code>/<code>updated</code> — уже перенесено / обновлено при повторном запуске;</li>
                    <li>сверка: <code>ok</code>, <code>missing</code>, <code>changed_on_old_site</code>, <code>stored_mismatch</code>, <code>deleted_on_old_site</code>.</li>
                </ul></details>
            </section>

            <section class="zau-rb-card">
                <h2>4. Результаты прежних переносов</h2>
                <p>Прежние инструменты могли прикрепить заявку не к тому аккаунту. После точного переноса такие заявки можно <strong>скрыть</strong>: заявка получает статус «заменена точной копией», а её PDF-документы переходят к владельцу точной копии. Ничего не удаляется, действие откатывается.</p>
                <p>
                    <button class="button" data-zau-exact-start="cleanup_preview">Проверить</button>
                    <button class="button button-primary" data-zau-exact-start="cleanup_apply" data-confirm="Скрыть заявки прежних переносов, у которых есть точная копия?">Скрыть дубли прежних переносов</button>
                    <button class="button" data-zau-exact-start="cleanup_rollback" data-confirm="Вернуть скрытые заявки и документы как было?">Откатить скрытие</button>
                </p>
            </section>

            <?php $this->render_queue(); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Проверка ФИО и подписей                                            */
    /* ------------------------------------------------------------------ */

    /** yes / no / none — совпадают ли два набора слов ФИО (порядок и написание не важны). */
    private function tokens_match(array $a, array $b) {
        if (count($a) < 1 || count($b) < 1) { return 'none'; }
        $common = count(array_intersect($a, $b));
        if ($common >= 2) { return 'yes'; }
        if ($common === 1 && (count($a) === 1 || count($b) === 1)) { return 'yes'; }
        return 'no';
    }

    private function first_signature_url(array $fields, array $files = []) {
        foreach ($fields as $f) {
            $isSig = ($f['type'] ?? '') === 'signature' || preg_match('/подпис|signature|қолы/u', $this->lower($f['label'] ?? ''));
            if (!$isSig) { continue; }
            if (preg_match('#https?://[^\s,"\'<>]+#iu', (string)($f['value'] ?? ''), $m)) {
                return !empty($files[$m[0]]) ? (string)$files[$m[0]] : $m[0];
            }
        }
        return '';
    }

    /** Считает и сохраняет результат проверки одного участника. */
    private function audit_user($userId) {
        global $wpdb;
        $user = get_user_by('id', (int)$userId);
        if (!$user) { return null; }
        $raw = $this->user_json_meta((int)$userId, 'zau_exact_legacy_raw');
        $newTokens = array_values(array_unique(array_merge($this->name_tokens($user->last_name . ' ' . $user->first_name), $this->name_tokens($user->display_name))));
        $oldName = $raw ? $this->old_display_name($raw) : '';
        $oldTokens = $raw ? $this->old_user_name_tokens($raw) : [];

        $appName = ''; $sigOld = ''; $appDate = '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT data_json,created_at FROM {$this->submissions_table} WHERE user_id=%d AND status=%s ORDER BY created_at DESC,id DESC LIMIT 20", (int)$userId, self::S_OWN));
        foreach ((array)$rows as $row) {
            $d = json_decode((string)$row->data_json, true);
            if (!is_array($d)) { continue; }
            if ($appName === '' && ($d['legacy_applicant_name'] ?? '') !== '') { $appName = (string)$d['legacy_applicant_name']; $appDate = substr((string)$row->created_at, 0, 10); }
            if ($sigOld === '') { $sigOld = $this->first_signature_url((array)($d['legacy_fields'] ?? []), (array)($d['legacy_files'] ?? [])); }
            if ($appName !== '' && $sigOld !== '') { break; }
        }
        $sigNew = ''; $newAppName = '';
        $ph = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $newRows = $wpdb->get_results($wpdb->prepare("SELECT data_json,signature_urls_json FROM {$this->submissions_table} WHERE user_id=%d AND status NOT IN ($ph) ORDER BY id DESC LIMIT 10", array_merge([(int)$userId], self::archive_statuses())));
        foreach ((array)$newRows as $row) {
            $d = json_decode((string)$row->data_json, true);
            // Копии прежних переносов (со ссылками на файлы старого сайта) — не новые заявления: подпись и ФИО берём только из поданных на новом сайте.
            if (!is_array($d) || !empty($d['legacy_entry_id']) || !empty($d['legacy_wpforms_entry_id'])) { continue; }
            $sigs = json_decode((string)$row->signature_urls_json, true);
            if ($sigNew === '' && is_array($sigs)) { foreach ($sigs as $url) { if (is_string($url) && preg_match('#^https?://#', $url)) { $sigNew = $url; break; } } }
            if ($newAppName === '') {
                $newAppName = trim((string)($d['full_name'] ?? trim(($d['last_name'] ?? '') . ' ' . ($d['first_name'] ?? '') . ' ' . ($d['middle_name'] ?? ''))));
            }
        }
        $result = [
            'name_old'=>$this->tokens_match($newTokens, $oldTokens),
            'name_app'=>$this->tokens_match($newTokens, $this->name_tokens($appName)),
            'name_new_app'=>$newAppName !== '' ? $this->tokens_match($newTokens, $this->name_tokens($newAppName)) : 'none',
            'old_name'=>$oldName, 'app_name'=>$appName, 'app_date'=>$appDate, 'new_app_name'=>$newAppName,
            'sig_old'=>$sigOld, 'sig_new'=>$sigNew,
            'at'=>current_time('mysql'),
        ];
        $result['status'] = in_array('no', [$result['name_old'], $result['name_app'], $result['name_new_app']], true) ? 'diff' : 'ok';
        $prev = $this->user_json_meta((int)$userId, 'zau_exact_audit');
        if (!empty($prev['reviewed'])) { $result['reviewed'] = $prev['reviewed']; }
        $this->update_user_json_meta((int)$userId, 'zau_exact_audit', $result);
        return $result;
    }

    public function ajax_audit_run() {
        $this->require_access();
        global $wpdb;
        $cursor = absint($_POST['cursor'] ?? 0);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_legacy_user_id' AND user_id>%d ORDER BY user_id ASC LIMIT 60", $cursor));
        $total = (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_legacy_user_id'");
        $done = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_legacy_user_id' AND user_id<=%d", $cursor));
        foreach ((array)$ids as $id) { $this->audit_user((int)$id); $cursor = (int)$id; }
        wp_send_json_success(['cursor'=>$cursor, 'done'=>min($total, $done + count((array)$ids)), 'total'=>$total, 'finished'=>count((array)$ids) < 60]);
    }

    private function audit_where($filter, $search) {
        global $wpdb;
        $join = "JOIN {$wpdb->usermeta} a ON a.user_id=u.ID AND a.meta_key='zau_exact_audit'";
        $where = '1=1';
        if ($filter === 'diff') { $where .= " AND a.meta_value LIKE '%\"status\":\"diff\"%' AND a.meta_value NOT LIKE '%\"reviewed\"%'"; }
        elseif ($filter === 'nosig') { $where .= " AND a.meta_value LIKE '%\"sig_old\":\"\"%' AND a.meta_value LIKE '%\"sig_new\":\"\"%'"; }
        elseif ($filter === 'bothsig') { $where .= " AND a.meta_value NOT LIKE '%\"sig_old\":\"\"%' AND a.meta_value NOT LIKE '%\"sig_new\":\"\"%'"; }
        elseif ($filter === 'reviewed') { $where .= " AND a.meta_value LIKE '%\"reviewed\"%'"; }
        elseif (in_array($filter, ['fix_strong', 'fix_plain', 'fix_review'], true)) { $where .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} f WHERE f.user_id=u.ID AND f.meta_key='zau_exact_namefix' AND f.meta_value LIKE %s)", '%"cat":"' . substr($filter, 4) . '"%'); }
        elseif ($filter === 'fix_done') { $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} f WHERE f.user_id=u.ID AND f.meta_key='zau_exact_namefix_done')"; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR a.meta_value LIKE %s)', $like, $like, $like);
        }
        return [$join, $where];
    }

    private function audit_badge($value) {
        $map = ['yes'=>['ok', 'совпадает'], 'no'=>['bad', 'отличается'], 'none'=>['none', 'нет данных']];
        $v = $map[$value] ?? $map['none'];
        return '<span class="zau-audit-badge is-' . $v[0] . '">' . $v[1] . '</span>';
    }

    private function audit_img($url, $label) {
        if ($url === '') { return '<span class="zau-audit-nosig">нет</span>'; }
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" title="' . esc_attr($label) . '"><img class="zau-audit-sig" src="' . esc_url($url) . '" alt="' . esc_attr($label) . '" loading="lazy"></a>';
    }

    private function status_label($status) {
        $map = [
            self::S_OWN=>['ok', 'Со старого сайта — своё'],
            self::S_OTHER=>['warn', 'Со старого сайта — подано за другого'],
            self::S_DISPUTED=>['bad', 'Со старого сайта — участник отметил «не моё»'],
            self::S_UNASSIGNED=>['warn', 'Со старого сайта — без владельца'],
            self::S_SUPERSEDED=>['none', 'Прежний перенос — скрыта (заменена точной копией)'],
            self::S_HIDDEN=>['none', 'Со старого сайта — спам/корзина'],
        ];
        return $map[$status] ?? ['ok', 'Подано на новом сайте (' . $status . ')'];
    }

    private function key_fields_html(array $fields) {
        $out = [];
        foreach ($fields as $f) {
            $label = $this->lower($f['label'] ?? '');
            if (($f['value'] ?? '') === '' || !preg_match('/фио|имя|фамил|иин|место работы|организац|должност|филиал|телефон/u', $label) || preg_match('/председател|руководител|бухгалтер/u', $label)) { continue; }
            $out[] = '<div><small>' . esc_html($f['label']) . '</small> ' . esc_html(wp_trim_words((string)$f['value'], 12)) . '</div>';
            if (count($out) >= 6) { break; }
        }
        return implode('', $out);
    }

    /** Одна карточка «старый сайт ↔ новый сайт» по конкретному участнику. */
    private function audit_person_page($userId) {
        global $wpdb;
        $user = get_user_by('id', $userId);
        $back = admin_url('admin.php?page=zau-exact-audit');
        echo '<div class="wrap zau-remote-wrap zau-exact-wrap">';
        if (function_exists('zau_admin_hub_nav')) { zau_admin_hub_nav('import'); }
        echo '<p><a href="' . esc_url(wp_get_referer() ?: $back) . '">← к списку</a></p>';
        if (!$user) { echo '<p>Аккаунт не найден.</p></div>'; return; }
        $a = $this->audit_user($userId) ?: [];
        $raw = $this->user_json_meta($userId, 'zau_exact_legacy_raw');
        $oldId = (int)get_user_meta($userId, 'zau_exact_legacy_user_id', true);
        $card = json_decode((string)get_user_meta($userId, 'zau_member_card_data', true), true) ?: [];
        echo '<h1>Сверка участника: ' . esc_html($user->display_name) . '</h1>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(['page'=>'zau-exact-regen', 'users'=>(int)$user->ID], admin_url('admin.php'))) . '">Пересоздать его перенесённые заявления по новым шаблонам</a></p>';

        // Итог простыми словами
        $checks = [];
        $checks[] = [$a['name_old'] ?? 'none', 'ФИО в аккаунте и в старом аккаунте', $a['old_name'] ?? ''];
        $checks[] = [$a['name_app'] ?? 'none', 'ФИО в аккаунте и в его старом заявлении', $a['app_name'] ?? ''];
        if (($a['new_app_name'] ?? '') !== '') { $checks[] = [$a['name_new_app'], 'ФИО в аккаунте и в новом заявлении', $a['new_app_name']]; }
        $flags = [];
        if (get_user_meta($userId, 'zau_exact_prior_mismatch', true)) { $flags[] = 'Прежний перенос связывал аккаунт с другим старым пользователем — проверьте профиль («Профили на проверку»).'; }
        if (get_user_meta($userId, 'zau_exact_possible_duplicate', true)) { $flags[] = 'Есть возможный дубль аккаунта («Возможные дубли»).'; }
        if (get_user_meta($userId, 'zau_exact_prior_wrong', true)) { $flags[] = 'Это ошибочный аккаунт прежнего переноса (очередь «Ошибочные аккаунты»).'; }
        $dispute = $this->user_json_meta($userId, 'zau_exact_profile_dispute');
        if (($dispute['status'] ?? '') === 'open') { $flags[] = 'Участник сообщил о чужих данных в профиле: «' . ($dispute['comment'] ?? '') . '».'; }
        echo '<section class="zau-rb-card"><h2>Итог</h2><ul class="zau-person-checks">';
        foreach ($checks as $c) { echo '<li>' . $this->audit_badge($c[0]) . ' ' . esc_html($c[1]) . ($c[2] !== '' ? ' <small>(' . esc_html($c[2]) . ')</small>' : '') . '</li>'; }
        foreach ($flags as $f) { echo '<li><span class="zau-audit-badge is-bad">внимание</span> ' . esc_html($f) . '</li>'; }
        if (!$oldId) { echo '<li><span class="zau-audit-badge is-none">нет данных</span> Этот аккаунт не связан со старым сайтом точным переносом.</li>'; }
        echo '</ul></section>';

        // Аккаунт: новый и старый рядом
        $meta = (array)($raw['meta'] ?? []);
        $oldPhone = '';
        foreach (['mobile_number', 'phone_number', 'phone'] as $k) { if ($this->meta_first($meta, $k) !== '') { $oldPhone = $this->meta_first($meta, $k); break; } }
        $pairs = [
            ['ФИО', $user->display_name . ' (' . trim($user->last_name . ' ' . $user->first_name) . ')', $raw ? $this->old_display_name($raw) : ''],
            ['Email', $user->user_email, (string)($raw['user_email'] ?? '')],
            ['Логин', $user->user_login, (string)($raw['user_login'] ?? '')],
            ['Телефон', (string)get_user_meta($userId, 'zau_phone', true), $oldPhone],
            ['Дата регистрации', $user->user_registered, (string)($raw['user_registered'] ?? '')],
            ['Статус', (string)get_user_meta($userId, 'zau_member_status', true), $this->meta_first($meta, 'account_status')],
            ['Организация', (string)get_user_meta($userId, 'zau_organization_name', true), ''],
            ['ИИН (карточка)', (string)($card['iin'] ?? get_user_meta($userId, 'zau_profile_iin', true)), ''],
        ];
        echo '<section class="zau-rb-card"><h2>Аккаунт</h2><table class="widefat striped"><thead><tr><th></th><th>Новый сайт (#' . (int)$userId . ')</th><th>Старый сайт' . ($oldId ? ' (#' . $oldId . ')' : '') . '</th></tr></thead><tbody>';
        foreach ($pairs as $p) { echo '<tr><th>' . esc_html($p[0]) . '</th><td>' . esc_html($p[1]) . '</td><td>' . esc_html($p[2]) . '</td></tr>'; }
        echo '</tbody></table><p><a class="button" href="' . esc_url(get_edit_user_link($userId)) . '">Открыть профиль пользователя</a></p></section>';

        // Заявления этого аккаунта
        $subs = $wpdb->get_results($wpdb->prepare("SELECT s.*,f.name form_name FROM {$this->submissions_table} s LEFT JOIN {$this->forms_table} f ON f.id=s.form_id WHERE s.user_id=%d ORDER BY s.created_at DESC,s.id DESC LIMIT 200", $userId));
        echo '<section class="zau-rb-card"><h2>Заявления у этого аккаунта (' . count((array)$subs) . ')</h2><table class="widefat striped"><thead><tr><th>Дата / форма</th><th>Что это</th><th>ФИО заявителя и главное из заявления</th><th>Подпись</th></tr></thead><tbody>';
        if (!$subs) { echo '<tr><td colspan="4">Заявлений нет.</td></tr>'; }
        foreach ((array)$subs as $s) {
            $d = json_decode((string)$s->data_json, true) ?: [];
            list($cls, $label) = $this->status_label((string)$s->status);
            $fields = (array)($d['legacy_fields'] ?? []);
            $sig = $fields ? $this->first_signature_url($fields, (array)($d['legacy_files'] ?? [])) : '';
            if ($sig === '') { $sigs = json_decode((string)$s->signature_urls_json, true); if (is_array($sigs)) { foreach ($sigs as $u) { if (is_string($u) && preg_match('#^https?://#', $u)) { $sig = $u; break; } } } }
            $who = (string)($d['legacy_applicant_name'] ?? ($d['full_name'] ?? ''));
            $match = $who !== '' ? $this->tokens_match($this->name_tokens($who), array_values(array_unique(array_merge($this->name_tokens($user->last_name . ' ' . $user->first_name), $this->name_tokens($user->display_name))))) : 'none';
            echo '<tr><td>' . esc_html(substr((string)$s->created_at, 0, 10)) . '<br><small>' . esc_html(($d['legacy_form_title'] ?? '') ?: (string)$s->form_name) . ' · #' . (int)$s->id . (!empty($d['legacy_entry_id']) ? ' · WPForms #' . (int)$d['legacy_entry_id'] : '') . '</small></td>';
            echo '<td><span class="zau-audit-badge is-' . esc_attr($cls === 'warn' ? 'none' : $cls) . '">' . esc_html($label) . '</span></td>';
            echo '<td><strong>' . esc_html($who) . '</strong> ' . $this->audit_badge($match) . $this->key_fields_html($fields) . '</td>';
            echo '<td>' . $this->audit_img($sig, 'Подпись') . '</td></tr>';
        }
        echo '</tbody></table></section>';

        // Записи старого аккаунта, которые сейчас у других аккаунтов
        if ($oldId) {
            $elsewhere = $wpdb->get_results($wpdb->prepare(
                "SELECT s.id,s.user_id,s.status,s.created_at,s.data_json FROM {$this->submissions_table} s WHERE s.user_id<>%d AND s.data_json LIKE %s AND s.data_json LIKE %s LIMIT 100",
                $userId, '%"legacy_exact":1%', '%"legacy_user_id":' . $oldId . ',%'
            ));
            if ($elsewhere) {
                echo '<section class="zau-rb-card"><h2>Записи, поданные с этого старого аккаунта, но находящиеся у других</h2><p class="description">Например, заявления, поданные за коллег: они у самих заявителей или ждут владельца.</p><table class="widefat striped"><thead><tr><th>Дата</th><th>Заявитель</th><th>Сейчас у аккаунта</th><th>Что это</th></tr></thead><tbody>';
                foreach ($elsewhere as $s) {
                    $d = json_decode((string)$s->data_json, true) ?: [];
                    $owner = (int)$s->user_id ? get_user_by('id', (int)$s->user_id) : false;
                    list($cls, $label) = $this->status_label((string)$s->status);
                    echo '<tr><td>' . esc_html(substr((string)$s->created_at, 0, 10)) . '</td><td>' . esc_html((string)($d['legacy_applicant_name'] ?? '')) . '</td><td>' . ($owner ? '<a href="' . esc_url(add_query_arg(['page'=>'zau-exact-audit', 'person'=>(int)$owner->ID], admin_url('admin.php'))) . '">#' . (int)$owner->ID . ' ' . esc_html($owner->display_name) . '</a>' : '— без владельца') . '</td><td>' . esc_html($label) . '</td></tr>';
                }
                echo '</tbody></table></section>';
            }
        }

        // Документы
        $docs = $wpdb->get_results($wpdb->prepare("SELECT id,document_no,document_title,issue_date,record_status,pdf_url,source_submission_id FROM {$this->docs_table} WHERE user_id=%d ORDER BY id DESC LIMIT 100", $userId));
        echo '<section class="zau-rb-card"><h2>Документы PDF (' . count((array)$docs) . ')</h2><table class="widefat striped"><thead><tr><th>Номер</th><th>Документ</th><th>Дата</th><th>Статус</th><th>Из заявки</th><th></th></tr></thead><tbody>';
        if (!$docs) { echo '<tr><td colspan="6">Документов нет.</td></tr>'; }
        foreach ((array)$docs as $doc) {
            echo '<tr><td>' . esc_html((string)$doc->document_no) . '</td><td>' . esc_html((string)$doc->document_title) . '</td><td>' . esc_html((string)$doc->issue_date) . '</td><td>' . esc_html($doc->record_status === 'active' ? 'действует' : (string)$doc->record_status) . '</td><td>#' . (int)$doc->source_submission_id . '</td><td>' . ($doc->pdf_url ? '<a href="' . esc_url((string)$doc->pdf_url) . '" target="_blank" rel="noopener">PDF</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table></section></div>';
    }

    public function audit_page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        global $wpdb;
        if (!empty($_GET['person'])) { $this->audit_person_page(absint($_GET['person'])); return; }
        $filter = sanitize_key((string)($_GET['filter'] ?? 'diff'));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per = 50;
        list($join, $where) = $this->audit_where($filter, $search);
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u $join WHERE $where");
        $rows = $filter === 'random'
            ? $wpdb->get_results("SELECT u.ID,a.meta_value audit FROM {$wpdb->users} u $join WHERE $where ORDER BY RAND() LIMIT 20")
            : $wpdb->get_results("SELECT u.ID,a.meta_value audit FROM {$wpdb->users} u $join WHERE $where ORDER BY u.display_name ASC LIMIT $per OFFSET " . (($paged - 1) * $per));
        if ($filter === 'random') { $total = count((array)$rows); }
        $migrated = (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_legacy_user_id'");
        $checked = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_audit'");
        $diff = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_audit' AND meta_value LIKE '%\"status\":\"diff\"%' AND meta_value NOT LIKE '%\"reviewed\"%'");
        $base = admin_url('admin.php?page=zau-exact-audit');
        ?>
        <div class="wrap zau-remote-wrap zau-exact-wrap">
            <?php if (function_exists('zau_admin_hub_nav')) { zau_admin_hub_nav('import'); } ?>
            <h1>Проверка ФИО и подписей перенесённых участников</h1>
            <?php if (!empty($_GET['msg'])): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['msg'])))); ?></p></div><?php endif; ?>
            <section class="zau-rb-card">
                <p>Для каждого участника, перенесённого точным переносом, сравниваются три ФИО: <strong>в аккаунте на новом сайте</strong>, <strong>в старом аккаунте</strong> (Ultimate Member) и <strong>в его последнем старом заявлении</strong>, а также ФИО в новом заявлении, если он уже подавал его на новом сайте. Порядок слов, регистр, «ё», казахские буквы и латиница не считаются расхождением. Подписи показываются рядом — старая из заявления со старого сайта и новая с нового сайта — для проверки на глаз.</p>
                <p><strong>Перенесено участников:</strong> <?php echo (int)$migrated; ?> · <strong>проверено:</strong> <?php echo (int)$checked; ?> · <strong>с расхождениями ФИО:</strong> <?php echo (int)$diff; ?></p>
                <p><button class="button button-primary" data-zau-audit-run>Проверить всех заново</button> <span data-zau-audit-progress></span></p>
                <p class="description">Проверка ничего не меняет — только сравнивает и запоминает результат. Запускайте после переноса и после исправлений.</p>
            </section>
            <?php echo $this->namefix_section(); ?>
            <form method="get" class="zau-audit-filter">
                <input type="hidden" name="page" value="zau-exact-audit">
                <?php foreach (['random'=>'Случайные 20 для проверки', 'diff'=>'Расхождения ФИО', 'all'=>'Все', 'bothsig'=>'Есть старая и новая подпись', 'nosig'=>'Нет ни одной подписи', 'reviewed'=>'Отмечены «всё верно»'] as $key => $label): ?>
                    <a class="button<?php echo $filter === $key ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(['filter'=>$key, 's'=>$search], $base)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="ФИО или email">
                <button class="button">Найти</button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'zau_exact_audit_csv', 'filter'=>$filter, 's'=>$search], admin_url('admin-post.php')), self::NONCE)); ?>">Скачать CSV</a>
            </form>
            <?php $fixTitles = ['fix_strong'=>'Группа «Чужое ФИО — доказано»', 'fix_plain'=>'Группа «Чужое ФИО — без доп. признаков»', 'fix_review'=>'Группа «Только вручную»', 'fix_done'=>'Исправлено массово']; ?>
            <p><?php echo isset($fixTitles[$filter]) ? '<strong>' . esc_html($fixTitles[$filter]) . '</strong> · ' : ''; ?>Найдено: <?php echo (int)$total; ?></p>
            <table class="widefat striped zau-audit-table">
                <thead><tr><th>Аккаунт на новом сайте</th><th>ФИО в старом аккаунте</th><th>ФИО в старом заявлении</th><th>ФИО в новом заявлении</th><th>Подпись: старая</th><th>Подпись: новая</th><th>Действия</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7"><?php echo $checked ? 'Нет участников по этому фильтру.' : 'Проверка ещё не запускалась — нажмите «Проверить всех заново».'; ?></td></tr><?php endif; ?>
                <?php foreach ((array)$rows as $row):
                    $a = json_decode((string)$row->audit, true) ?: [];
                    $user = get_user_by('id', (int)$row->ID);
                    if (!$user) { continue; }
                    $hidden = ['user_id'=>(int)$row->ID, 'filter'=>$filter, 's'=>$search, 'paged'=>$paged];
                ?>
                    <tr class="<?php echo ($a['status'] ?? '') === 'diff' && empty($a['reviewed']) ? 'is-diff' : ''; ?>">
                        <td><a href="<?php echo esc_url(get_edit_user_link((int)$row->ID)); ?>">#<?php echo (int)$row->ID; ?></a> <strong><?php echo esc_html($user->display_name); ?></strong> · <a href="<?php echo esc_url(add_query_arg(['page'=>'zau-exact-audit', 'person'=>(int)$row->ID], admin_url('admin.php'))); ?>">Подробнее</a><br><small><?php echo esc_html(trim($user->last_name . ' ' . $user->first_name)); ?> · <?php echo esc_html($user->user_email); ?></small><?php if (!empty($a['reviewed'])): ?><br><span class="zau-audit-badge is-ok">отмечено: всё верно</span><?php endif; ?><?php echo $this->namefix_cell((int)$row->ID); ?></td>
                        <td><?php echo esc_html($a['old_name'] ?? ''); ?><br><?php echo $this->audit_badge($a['name_old'] ?? 'none'); ?></td>
                        <td><?php echo esc_html($a['app_name'] ?? ''); ?><?php if (!empty($a['app_date'])): ?> <small>(<?php echo esc_html($a['app_date']); ?>)</small><?php endif; ?><br><?php echo $this->audit_badge($a['name_app'] ?? 'none'); ?></td>
                        <td><?php echo esc_html($a['new_app_name'] ?? ''); ?><br><?php echo $this->audit_badge($a['name_new_app'] ?? 'none'); ?></td>
                        <td><?php echo $this->audit_img((string)($a['sig_old'] ?? ''), 'Подпись со старого сайта'); ?></td>
                        <td><?php echo $this->audit_img((string)($a['sig_new'] ?? ''), 'Подпись с нового сайта'); ?></td>
                        <td>
                            <?php if (($a['old_name'] ?? '') !== '' && ($a['name_old'] ?? '') === 'no') { echo $this->audit_form('fix_name', 'ФИО как в старом аккаунте', $hidden, 'button button-primary'); } ?>
                            <?php echo empty($a['reviewed']) ? $this->audit_form('mark_ok', 'Всё верно', $hidden) : $this->audit_form('unmark', 'Снять отметку', $hidden); ?>
                            <?php if ($this->user_json_meta((int)$row->ID, 'zau_exact_profile_backups')) { echo $this->audit_form('undo_name', 'Вернуть прежнее ФИО', $hidden); } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php $pages = max(1, (int)ceil($total / $per)); if ($pages > 1): ?>
                <p class="zau-audit-pages"><?php for ($i = 1; $i <= $pages; $i++): if ($i > 3 && $i < $pages - 2 && abs($i - $paged) > 2) { if ($i === 4 || $i === $pages - 3) { echo '… '; } continue; } ?>
                    <a class="button<?php echo $i === $paged ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(['filter'=>$filter, 's'=>$search, 'paged'=>$i], $base)); ?>"><?php echo (int)$i; ?></a>
                <?php endfor; ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function audit_form($do, $label, array $hidden, $class = 'button') {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zau-exact-inline"><input type="hidden" name="action" value="zau_exact_audit_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false);
        foreach ($hidden as $k => $v) { $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">'; }
        return $html . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    public function audit_action() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $userId = absint($_POST['user_id'] ?? 0);
        $do = sanitize_key((string)($_POST['do'] ?? ''));
        $msg = 'Готово.';
        $user = get_user_by('id', $userId);
        if ($user && $do === 'fix_name') {
            $raw = $this->user_json_meta($userId, 'zau_exact_legacy_raw');
            $meta = (array)($raw['meta'] ?? []);
            $first = $this->meta_first($meta, 'first_name');
            $last = $this->meta_first($meta, 'last_name');
            $display = trim((string)($raw['display_name'] ?? ''));
            if ($display === '' || $display === (string)($raw['user_login'] ?? '')) { $display = trim($last . ' ' . $first); }
            if ($display === '' && $first === '' && $last === '') { $msg = 'В старом аккаунте нет ФИО.'; }
            else {
                $backups = $this->user_json_meta($userId, 'zau_exact_profile_backups');
                $backups[] = ['at'=>current_time('mysql'), 'by'=>get_current_user_id(), 'reason'=>'fix_name', 'user'=>['first_name'=>$user->first_name, 'last_name'=>$user->last_name, 'display_name'=>$user->display_name], 'meta'=>[]];
                $this->update_user_json_meta($userId, 'zau_exact_profile_backups', array_slice($backups, -5));
                wp_update_user(['ID'=>$userId, 'first_name'=>$first, 'last_name'=>$last, 'display_name'=>$display ?: $user->display_name]);
                $msg = 'ФИО участника #' . $userId . ' заменено на данные старого аккаунта: ' . $display . '. Прежнее значение: ' . $user->display_name . '.';
            }
        } elseif ($user && $do === 'undo_name') {
            $msg = $this->undo_restore($userId) ? 'Прежнее ФИО участника #' . $userId . ' возвращено.' : 'Резервной копии нет.';
        } elseif ($user && in_array($do, ['mark_ok', 'unmark'], true)) {
            $a = $this->user_json_meta($userId, 'zau_exact_audit');
            if ($do === 'mark_ok') { $a['reviewed'] = ['by'=>get_current_user_id(), 'at'=>current_time('mysql')]; } else { unset($a['reviewed']); }
            $this->update_user_json_meta($userId, 'zau_exact_audit', $a);
            $msg = $do === 'mark_ok' ? 'Отмечено: всё верно.' : 'Отметка снята.';
        }
        if ($user) { $this->audit_user($userId); }
        wp_safe_redirect(add_query_arg(['page'=>'zau-exact-audit', 'filter'=>sanitize_key((string)($_POST['filter'] ?? 'diff')), 's'=>sanitize_text_field(wp_unslash($_POST['s'] ?? '')), 'paged'=>absint($_POST['paged'] ?? 1), 'msg'=>rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Пересоздание перенесённых заявлений по новым шаблонам              */
    /* ------------------------------------------------------------------ */

    const REGEN_OPT = 'zau_exact_regen_settings';
    const REGEN_STATE_OPT = 'zau_exact_regen_state';

    private function regen_settings() {
        return wp_parse_args((array)get_option(self::REGEN_OPT, []), ['map'=>[], 'skip_nosig'=>1, 'recreate'=>0, 'activate'=>1, 'approve_card'=>1, 'activate_forms'=>null]);
    }

    /** Архивные формы перенесённых заявлений (по одной на форму WPForms) с количеством своих заявлений. */
    private function regen_source_forms() {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT f.id,f.name,COUNT(s.id) qty FROM {$this->forms_table} f JOIN {$this->submissions_table} s ON s.form_id=f.id AND s.status=%s WHERE f.slug LIKE %s GROUP BY f.id,f.name ORDER BY qty DESC",
            self::S_OWN, 'legacy-exact-%'
        ));
    }

    /** Файл подписи лежит в загрузках этого сайта и существует на диске (иначе PDF не нарисуется). */
    private function is_local_url($url) {
        $url = (string)$url;
        if ($url === '') { return false; }
        $up = wp_upload_dir();
        if (!empty($up['error'])) { return false; }
        $path = rawurldecode((string)wp_parse_url($url, PHP_URL_PATH));
        $base = rawurldecode((string)wp_parse_url((string)$up['baseurl'], PHP_URL_PATH));
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        if ($host !== '' && $host !== strtolower((string)wp_parse_url((string)$up['baseurl'], PHP_URL_HOST))) { return false; }
        if ($base === '' || strpos($path, trailingslashit($base)) !== 0) { return false; }
        $relative = substr($path, strlen(trailingslashit($base)));
        if (strpos($relative, '..') !== false) { return false; }
        return is_file(trailingslashit((string)$up['basedir']) . $relative);
    }

    /** Данные для новой формы из перенесённого заявления: поля, подпись, исходная дата. */
    private function regen_data($row, array $targetForm) {
        $data = json_decode((string)$row->data_json, true);
        if (!is_array($data)) { return new WP_Error('data', 'Данные заявления повреждены.'); }
        $src = $this->legacy_source($data);
        $signature = $this->first_signature_url((array)($data['legacy_fields'] ?? []), (array)($data['legacy_files'] ?? []));
        if (!$this->is_local_url($signature)) { $signature = ''; }
        $out = [];
        $sigKeys = [];
        foreach ((array)$targetForm['fields'] as $field) {
            $key = (string)($field['key'] ?? '');
            $type = (string)($field['type'] ?? '');
            if ($key === '' || $type === 'heading') { continue; }
            if ($type === 'signature') { $sigKeys[] = $key; continue; }
            if ($type === 'checkbox') { $label = $this->normalize_label($field['label'] ?? ''); $out[$key] = ($label !== '' && !empty($src['by_label'][$label])) ? '1' : ''; continue; }
            $out[$key] = $this->legacy_field_value($field, $src);
        }
        // Всё, что распознано в старом заявлении (ИИН, должность, организация, руководитель…), — важнее текущего профиля:
        // профиль заполнит только то, чего в заявлении не было.
        foreach ((array)$src['extracted'] as $k => $v) {
            if (is_scalar($v) && trim((string)$v) !== '' && (!isset($out[$k]) || $out[$k] === '')) { $out[$k] = (string)$v; }
        }
        if ($signature !== '') {
            foreach ($sigKeys as $k) { $out[$k] = $signature; }
            $out['signature_url'] = $signature;
        }
        // Филиал и реквизиты — из самого старого заявления (ИИК/БИН филиала), а не из профиля.
        $branchKey = '';
        foreach ((array)$targetForm['fields'] as $field) { if (($field['type'] ?? '') === 'branch_select') { $branchKey = (string)$field['key']; break; } }
        $req = $this->legacy_requisites((array)($data['legacy_fields'] ?? []));
        $branchId = $req['branch_id'];
        $source = $branchId ? $req['by'] : '';
        if (!$branchId && $branchKey !== '' && !empty($out[$branchKey])) { $branchId = (int)$out[$branchKey]; $source = 'name'; }
        if ($branchId) {
            $out['regen_branch_id'] = $branchId;
            if ($branchKey !== '') { $out[$branchKey] = (string)$branchId; }
        } elseif ($req['text'] !== '') {
            // Филиала с такими реквизитами в справочнике нет — печатаем реквизиты ровно как в старом заявлении.
            foreach (['branch_requisites', 'branch_full_details', 'branch_bank_details', 'branch_bank_requisites', 'branch_snapshot_full_details', 'branch_snapshot_bank_details', 'branch_full_text'] as $k) { $out[$k] = $req['text']; }
            $source = 'text';
        }
        $out['regen_branch_source'] = $source !== '' ? $source : 'profile';
        $user = get_user_by('id', (int)$row->user_id);
        $fullName = trim(implode(' ', array_filter([$out['last_name'] ?? '', $out['first_name'] ?? '', $out['middle_name'] ?? ''])));
        if ($fullName === '') { $fullName = trim((string)($out['full_name'] ?? '')); }
        if ($fullName === '') { $fullName = trim((string)($data['legacy_applicant_name'] ?? '')); }
        if ($fullName === '' && $user) { $fullName = (string)$user->display_name; }
        $date = mysql2date('d.m.Y', (string)$row->created_at);
        $out['full_name'] = $fullName;
        $out['member_name_header'] = $fullName;
        $out['submission_date'] = $date;
        $out['issue_date'] = $date;
        $out['legacy_entry_id'] = (int)($data['legacy_entry_id'] ?? 0);
        $out['legacy_entry_date'] = (string)$row->created_at;
        return $out;
    }

    private function regen_coverage() {
        global $wpdb;
        $docs = $wpdb->prefix . 'zau_certificates';
        $settings = $this->regen_settings();
        $forms = array_values(array_unique(array_merge(array_keys(array_filter(array_map('absint', (array)$settings['map']))), array_keys(array_filter((array)$settings['activate_forms'])))));
        $out = ['members'=>0, 'members_ready'=>0, 'members_draft'=>0, 'members_none'=>0, 'docs'=>0, 'ready'=>0, 'drafts'=>0];
        $t = $wpdb->get_row("SELECT COUNT(*) docs, SUM(d.pdf_url IS NOT NULL AND d.pdf_url<>'') ready FROM {$this->docs_index_table} i JOIN {$docs} d ON d.id=i.doc_id");
        $out['docs'] = (int)($t->docs ?? 0); $out['ready'] = (int)($t->ready ?? 0); $out['drafts'] = $out['docs'] - $out['ready'];
        if (!$forms) { return $out; }
        $in = implode(',', array_map('intval', $forms));
        $out['members'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$this->submissions_table} WHERE status=%s AND form_id IN ($in) AND user_id>0", self::S_OWN));
        $out['members_draft'] = (int)$wpdb->get_var("SELECT COUNT(DISTINCT i.user_id) FROM {$this->docs_index_table} i JOIN {$docs} d ON d.id=i.doc_id WHERE d.pdf_url IS NULL OR d.pdf_url=''");
        $withDocs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT s.user_id) FROM {$this->submissions_table} s JOIN {$this->docs_index_table} i ON i.user_id=s.user_id WHERE s.status=%s AND s.form_id IN ($in)", self::S_OWN));
        $out['members_ready'] = max(0, $withDocs - $out['members_draft']);
        $out['members_none'] = max(0, $out['members'] - $withDocs);
        return $out;
    }

    public function regen_csv() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        global $wpdb;
        $docs = $wpdb->prefix . 'zau_certificates';
        $settings = $this->regen_settings();
        $forms = array_values(array_unique(array_merge(array_keys(array_filter(array_map('absint', (array)$settings['map']))), array_keys(array_filter((array)$settings['activate_forms'])))));
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-perenesennye-dokumenty-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        zau_fputcsv($out, ['ID', 'ФИО', 'Email', 'Своих старых заявлений', 'Первое заявление', 'Документов готово', 'Черновиков', 'Номера документов', 'Итог', 'Статус участника', 'Дата вступления', 'Сделан действительным', 'Филиал в документах', 'Откуда взят филиал'], ';');
        if ($forms) {
            $in = implode(',', array_map('intval', $forms));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT s.user_id, COUNT(*) apps, MIN(s.created_at) first_at FROM {$this->submissions_table} s WHERE s.status=%s AND s.form_id IN ($in) AND s.user_id>0 GROUP BY s.user_id ORDER BY s.user_id ASC", self::S_OWN));
            foreach ((array)$rows as $r) {
                $u = get_user_by('id', (int)$r->user_id);
                if (!$u) { continue; }
                $d = $wpdb->get_results($wpdb->prepare("SELECT d.document_no, d.pdf_url FROM {$this->docs_index_table} i JOIN {$docs} d ON d.id=i.doc_id WHERE i.user_id=%d ORDER BY d.id ASC", (int)$r->user_id));
                $srcNames = ['iban'=>'по ИИК из старого заявления', 'bin'=>'по БИН филиала из старого заявления', 'name'=>'по названию филиала из старого заявления', 'text'=>'реквизиты дословно из старого заявления', 'profile'=>'из профиля (в заявлении не найдено) — проверьте'];
                $branches = []; $sources = [];
                foreach ((array)$wpdb->get_col($wpdb->prepare("SELECT d.data_json FROM {$this->docs_index_table} i JOIN {$docs} d ON d.id=i.doc_id WHERE i.user_id=%d", (int)$r->user_id)) as $json) {
                    $x = json_decode((string)$json, true) ?: [];
                    $bn = (string)($x['branch_name'] ?? ($x['branch_snapshot_name'] ?? ''));
                    if ($bn !== '') { $branches[$bn] = 1; }
                    $sc = $srcNames[(string)($x['regen_branch_source'] ?? '')] ?? '';
                    if ($sc !== '') { $sources[$sc] = 1; }
                }
                $branchName = implode(' | ', array_keys($branches));
                $branchSrc = implode(' | ', array_keys($sources));
                $ready = 0; $draft = 0; $nos = [];
                foreach ((array)$d as $x) { if (!empty($x->pdf_url)) { $ready++; } else { $draft++; } $nos[] = $x->document_no; }
                $result = !$d ? 'нет документов' : ($draft ? 'есть черновики' : 'готово');
                zau_fputcsv($out, [$u->ID, $u->display_name, $u->user_email, (int)$r->apps, mysql2date('d.m.Y', (string)$r->first_at), $ready, $draft, implode(', ', $nos), $result,
                    (string)get_user_meta($u->ID, 'zau_member_status', true), (string)get_user_meta($u->ID, 'zau_membership_date', true), get_user_meta($u->ID, 'zau_exact_activated', true) ? 'да' : '', $branchName, $branchSrc], ';');
            }
        }
        fclose($out);
        exit;
    }

    /** Дата вступления — дата самого раннего своего перенесённого заявления о вступлении. */
    private function regen_membership_date($userId, array $formIds) {
        global $wpdb;
        if (!$formIds) { return ''; }
        $min = $wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM {$this->submissions_table} WHERE user_id=%d AND status=%s AND form_id IN (" . implode(',', array_map('intval', $formIds)) . ")", (int)$userId, self::S_OWN));
        return $min ? mysql2date('d.m.Y', (string)$min) : '';
    }

    /**
     * Реквизиты филиала из полей старого заявления: текст поля с расчётным счётом,
     * филиал справочника по ИИК (IBAN) или по БИН филиала из этого текста.
     */
    private function legacy_requisites(array $fields) {
        global $wpdb;
        $out = ['text'=>'', 'branch_id'=>0, 'by'=>''];
        $ibans = []; $bins = [];
        foreach ($fields as $f) {
            $value = trim($this->scalar_text($f['value'] ?? ''));
            if ($value === '') { continue; }
            $label = $this->normalize_label($f['label'] ?? '');
            $compact = strtoupper(preg_replace('/\s+/u', '', $value));
            $found = preg_match_all('/KZ\d{2}[0-9A-Z]{16}/', $compact, $m);
            $looks = (bool)preg_match('/расч[её]тн|реквизит|иик|iban|сч[её]т филиал/u', $label);
            if (!$found && !$looks) { continue; }
            if ($found) { $ibans = array_merge($ibans, $m[0]); }
            if (preg_match_all('/(?:БИН|BIN)\D{0,3}(\d{12})/u', $value, $b)) { $bins = array_merge($bins, $b[1]); }
            if ($out['text'] === '' && ($found || $looks) && mb_strlen($value) >= 15) { $out['text'] = $value; }
        }
        $table = $wpdb->prefix . 'zau_union_branches';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return $out; }
        static $branches = null;
        if ($branches === null) { $branches = (array)$wpdb->get_results("SELECT id,iban,union_bin,requisites,bank_details,full_details FROM {$table} WHERE active=1"); }
        foreach (array_unique($ibans) as $iban) {
            foreach ($branches as $br) {
                $hay = strtoupper(preg_replace('/\s+/u', '', (string)$br->iban . ' ' . $br->requisites . ' ' . $br->bank_details . ' ' . $br->full_details));
                if (strpos($hay, $iban) !== false) { $out['branch_id'] = (int)$br->id; $out['by'] = 'iban'; return $out; }
            }
        }
        foreach (array_unique($bins) as $bin) {
            $hits = array_values(array_filter($branches, function ($br) use ($bin) { return preg_replace('/\D+/', '', (string)$br->union_bin) === $bin; }));
            if (count($hits) === 1) { $out['branch_id'] = (int)$hits[0]->id; $out['by'] = 'bin'; return $out; }
        }
        return $out;
    }

    public function regen_save() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $map = [];
        foreach ((array)($_POST['map'] ?? []) as $from => $to) { $map[absint($from)] = absint($to); }
        $activateForms = [];
        foreach (array_keys($map) as $from) { $activateForms[$from] = empty($_POST['activate_forms'][$from]) ? 0 : 1; }
        update_option(self::REGEN_OPT, ['map'=>$map, 'skip_nosig'=>empty($_POST['skip_nosig']) ? 0 : 1, 'recreate'=>empty($_POST['recreate']) ? 0 : 1,
            'activate'=>empty($_POST['activate']) ? 0 : 1, 'approve_card'=>empty($_POST['approve_card']) ? 0 : 1, 'activate_forms'=>$activateForms], false);
        wp_safe_redirect(add_query_arg(['page'=>'zau-exact-regen', 'users'=>sanitize_text_field(wp_unslash($_POST['users'] ?? '')), 'msg'=>rawurlencode('Настройки сохранены.')], admin_url('admin.php')));
        exit;
    }

    public function ajax_regen_run() {
        $this->require_access();
        global $wpdb;
        @set_time_limit(120);
        $mode = sanitize_key((string)($_POST['mode'] ?? 'prepare'));
        $cursor = absint($_POST['cursor'] ?? 0);
        $state = (array)get_option(self::REGEN_STATE_OPT, []);
        if (!class_exists('ZAU_Union_Module')) { wp_send_json_error(['message'=>'Модуль профсоюза не загружен.'], 500); }
        $union = ZAU_Union_Module::instance();
        if ($mode === 'queue_drafts') {
            $docsTable = $wpdb->prefix . 'zau_certificates';
            $ids = $wpdb->get_col("SELECT i.doc_id FROM {$this->docs_index_table} i JOIN {$docsTable} d ON d.id=i.doc_id WHERE d.pdf_url IS NULL OR d.pdf_url='' ORDER BY i.doc_id ASC");
            $jobId = ($ids && class_exists('ZAU_Bulk_Regeneration')) ? ZAU_Bulk_Regeneration::instance()->create_job_for_documents($ids, ['update_number'=>0, 'keep_old_files'=>0, 'skip_without_signature'=>0, 'delay_ms'=>100, 'max_retries'=>2, 'server'=>1]) : 0;
            wp_send_json_success(['cursor'=>0, 'processed'=>count($ids), 'done'=>count($ids), 'total'=>0, 'finished'=>true, 'stats'=>['queued'=>count($ids)], 'job_url'=>$jobId ? admin_url('admin.php?page=zau-cert-bulk-regenerate&job=' . $jobId) : '']);
        }
        if ($mode === 'delete') {
            $ids = array_map('intval', (array)$wpdb->get_col("SELECT doc_id FROM {$this->docs_index_table} ORDER BY doc_id ASC LIMIT 200"));
            $n = $union->regen_delete_documents($ids);
            if ($ids) { $wpdb->query("DELETE FROM {$this->docs_index_table} WHERE doc_id IN (" . implode(',', $ids) . ")"); }
            if ($n < 200) { $n += $union->regen_delete_documents_legacy(200); }
            $done = absint($_POST['processed'] ?? 0) + $n;
            if ($n < 200) { delete_option(self::REGEN_STATE_OPT); }
            wp_send_json_success(['cursor'=>0, 'processed'=>$done, 'done'=>$done, 'total'=>0, 'finished'=>$n < 200, 'stats'=>['deleted'=>$done]]);
        }
        if ($mode === 'deactivate') {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_activated' AND user_id>%d ORDER BY user_id ASC LIMIT 50", $cursor));
            $stats = array_map('absint', (array)($_POST['stats'] ?? []));
            foreach ((array)$ids as $id) { $cursor = (int)$id; $r = $union->regen_deactivate_member((int)$id); $stats[$r] = ($stats[$r] ?? 0) + 1; }
            $done = absint($_POST['processed'] ?? 0) + count((array)$ids);
            wp_send_json_success(['cursor'=>$cursor, 'processed'=>$done, 'done'=>$done, 'total'=>0, 'finished'=>count((array)$ids) < 50, 'stats'=>$stats]);
        }
        $settings = $this->regen_settings();
        $map = array_filter(array_map('absint', (array)$settings['map']));
        $targets = $union->regen_target_forms();
        foreach ($map as $from => $to) { if (empty($targets[$to]['templates'])) { unset($map[$from]); } }
        $activateForms = !empty($settings['activate']) ? array_keys(array_filter((array)$settings['activate_forms'])) : [];
        $forms = array_values(array_unique(array_merge(array_keys($map), array_map('intval', $activateForms))));
        if (!$forms) { wp_send_json_error(['message'=>'Выберите хотя бы для одной старой формы новую форму с PDF-шаблонами (или отметьте «заявление о вступлении») и сохраните.'], 400); }
        $users = array_filter(array_map('absint', preg_split('/[\s,;]+/', (string)wp_unslash($_POST['users'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
        $formIn = implode(',', array_map('intval', $forms));
        $userSql = $users ? ' AND user_id IN (' . implode(',', array_map('intval', $users)) . ')' : '';
        if ($cursor === 0) {
            $state = ['run'=>'r' . gmdate('YmdHis') . wp_generate_password(4, false, false), 'at'=>current_time('mysql'), 'users'=>implode(',', $users), 'finished'=>false, 'job_id'=>0];
            update_option(self::REGEN_STATE_OPT, $state, false);
        }
        $limit = 25;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id>%d AND status=%s AND form_id IN ($formIn)$userSql ORDER BY id ASC LIMIT %d", $cursor, self::S_OWN, $limit));
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE status=%s AND form_id IN ($formIn)$userSql", self::S_OWN));
        $stats = array_map('absint', (array)($_POST['stats'] ?? []));
        $errors = [];
        $started = microtime(true);
        foreach ((array)$rows as $row) {
            if (microtime(true) - $started > 20) { break; }
            $cursor = (int)$row->id;
            $handled = ($handled ?? 0) + 1;
            if (!get_user_by('id', (int)$row->user_id)) { $stats['no_user'] = ($stats['no_user'] ?? 0) + 1; continue; }
            if (in_array((int)$row->form_id, $activateForms, true)) {
                $a = $union->regen_activate_member((int)$row->user_id, $this->regen_membership_date((int)$row->user_id, $activateForms),
                    'Перенесён со старого сайта: заявление № ' . (int)(json_decode((string)$row->data_json, true)['legacy_entry_id'] ?? 0) . ' от ' . mysql2date('d.m.Y', (string)$row->created_at), !empty($settings['approve_card']));
                if ($a !== 'already') { $stats['member_' . $a] = ($stats['member_' . $a] ?? 0) + 1; }
            }
            if (empty($map[(int)$row->form_id])) { continue; }
            $target = $targets[$map[(int)$row->form_id]];
            $data = $this->regen_data($row, $target);
            if (is_wp_error($data)) { $stats['error'] = ($stats['error'] ?? 0) + 1; $errors[] = '#' . $row->id . ': ' . $data->get_error_message(); continue; }
            if (empty($data['signature_url']) && !empty($settings['skip_nosig'])) { $stats['no_signature'] = ($stats['no_signature'] ?? 0) + 1; continue; }
            $data['legacy_exact_regen_run'] = (string)$state['run'];
            $result = $union->regen_prepare_documents($row, (int)$target['id'], $data, !empty($settings['recreate']));
            if (is_wp_error($result)) { $stats['error'] = ($stats['error'] ?? 0) + 1; $errors[] = '#' . $row->id . ': ' . $result->get_error_message(); continue; }
            foreach ($result as $r) {
                $stats[$r['state']] = ($stats[$r['state']] ?? 0) + 1;
                $wpdb->query($wpdb->prepare("INSERT INTO {$this->docs_index_table} (doc_id,user_id,submission_id,run,created_at) VALUES (%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE run=IF(%s='ready',run,VALUES(run))",
                    (int)$r['id'], (int)$row->user_id, (int)$row->id, $r['state'] === 'ready' ? '' : (string)$state['run'], current_time('mysql'), $r['state']));
            }
            $stats['submissions'] = ($stats['submissions'] ?? 0) + 1;
        }
        $processed = absint($_POST['processed'] ?? 0) + (int)($handled ?? 0);
        $finished = count((array)$rows) < $limit && (!$rows || $cursor === (int)end($rows)->id);
        $jobUrl = '';
        if ($finished) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT doc_id FROM {$this->docs_index_table} WHERE run=%s ORDER BY doc_id ASC", (string)$state['run']));
            $jobId = ($ids && class_exists('ZAU_Bulk_Regeneration')) ? ZAU_Bulk_Regeneration::instance()->create_job_for_documents($ids, ['update_number'=>0, 'keep_old_files'=>0, 'skip_without_signature'=>0, 'delay_ms'=>100, 'max_retries'=>2, 'server'=>1]) : 0;
            $state['finished'] = true; $state['job_id'] = $jobId; $state['stats'] = $stats; $state['queued'] = count((array)$ids);
            update_option(self::REGEN_STATE_OPT, $state, false);
            if ($jobId) { $jobUrl = admin_url('admin.php?page=zau-cert-bulk-regenerate&job=' . $jobId); }
        }
        wp_send_json_success(['cursor'=>$cursor, 'processed'=>$processed, 'done'=>min($total, $processed), 'total'=>$total, 'finished'=>$finished, 'stats'=>$stats, 'errors'=>$errors, 'job_url'=>$jobUrl]);
    }

    public function regen_page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        global $wpdb;
        $settings = $this->regen_settings();
        $sources = $this->regen_source_forms();
        $targets = class_exists('ZAU_Union_Module') ? ZAU_Union_Module::instance()->regen_target_forms() : [];
        $state = (array)get_option(self::REGEN_STATE_OPT, []);
        $docsTable = $wpdb->prefix . 'zau_certificates';
        $cov = $this->regen_coverage();
        $made = $cov['docs']; $withPdf = $cov['ready'];
        $users = sanitize_text_field(wp_unslash($_GET['users'] ?? ''));
        $activated = (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_activated'");
        ?>
        <div class="wrap zau-remote-wrap zau-exact-wrap">
            <?php if (function_exists('zau_admin_hub_nav')) { zau_admin_hub_nav('import'); } ?>
            <h1>Пересоздание перенесённых заявлений и активация аккаунтов</h1>
            <?php if (!empty($_GET['msg'])): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['msg'])))); ?></p></div><?php endif; ?>
            <section class="zau-rb-card">
                <p>Для каждого заявления, перенесённого со старого сайта и закреплённого за своим владельцем, создаётся документ <strong>по шаблону новой формы</strong>: поля заполняются из старого заявления, ставится <strong>его подпись</strong>, дата документа и дата создания — <strong>дата старого заявления</strong>. Документ появляется в личном кабинете участника и в реестре.</p>
                <p><strong>Не затрагиваются:</strong> заявления, поданные на новом сайте, и их документы; чужие, спорные и скрытые записи старого сайта; сами перенесённые заявления (архив остаётся как есть). Повторный запуск не создаёт дублей.</p>
                <p>Создано этим инструментом документов: <strong><?php echo (int)$made; ?></strong>, из них с готовым PDF: <strong><?php echo (int)$withPdf; ?></strong>.</p>
            </section>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zau-rb-card">
                <input type="hidden" name="action" value="zau_exact_regen_save"><?php wp_nonce_field(self::NONCE); ?>
                <h2>1. Какая форма старого сайта — по какой новой форме пересоздавать</h2>
                <?php if (!$targets): ?><p class="zau-rb-message error">На новом сайте нет активных форм. Создайте форму и привяжите к ней PDF-шаблоны.</p><?php endif; ?>
                <table class="widefat striped">
                    <thead><tr><th>Форма старого сайта</th><th>Своих заявлений</th><th>Новая форма (её PDF-шаблоны)</th><th>Это заявление о вступлении?</th></tr></thead>
                    <tbody>
                    <?php if (!$sources): ?><tr><td colspan="4">Перенесённых заявлений нет.</td></tr><?php endif; ?>
                    <?php foreach ($sources as $f): $sel = (int)($settings['map'][(int)$f->id] ?? 0);
                        $isJoin = is_array($settings['activate_forms']) ? !empty($settings['activate_forms'][(int)$f->id]) : (bool)preg_match('/вступ|кабылдау|қабылдау|членств/ui', (string)$f->name) && !preg_match('/выход|шығу|выбыт|исключ/ui', (string)$f->name); ?>
                        <tr><td><?php echo esc_html($f->name); ?></td><td><?php echo (int)$f->qty; ?></td><td>
                            <select name="map[<?php echo (int)$f->id; ?>]">
                                <option value="0">— не пересоздавать —</option>
                                <?php foreach ($targets as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php selected($sel, (int)$t['id']); ?> <?php disabled(!$t['templates']); ?>><?php echo esc_html($t['name'] . ' (' . ($t['templates'] ? implode(', ', wp_list_pluck($t['templates'], 'name')) : 'нет шаблонов') . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td><td><label><input type="checkbox" name="activate_forms[<?php echo (int)$f->id; ?>]" value="1" <?php checked($isJoin); ?>> да, делает участника действительным</label></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><label><input type="checkbox" name="skip_nosig" value="1" <?php checked(!empty($settings['skip_nosig'])); ?>> Пропускать заявления без подписи (или если файл подписи не скопирован на новый сайт)</label></p>
                <p><label><input type="checkbox" name="activate" value="1" <?php checked(!empty($settings['activate'])); ?>> <strong>Сразу сделать аккаунты действительными</strong>: статус «Состоит в профсоюзе», вступление одобрено, дата вступления — дата старого заявления (только по формам, отмеченным «заявление о вступлении»)</label><br>
                    <label><input type="checkbox" name="approve_card" value="1" <?php checked(!empty($settings['approve_card'])); ?>> …и отметить личную карточку проверенной</label><br>
                    <small>Не трогает участников со статусом «Выбыл из профсоюза», «Заявление отклонено», «Членство приостановлено». Прежние статусы сохраняются — их можно вернуть кнопкой в разделе «Отмена».</small></p>
                <p><label><input type="checkbox" name="recreate" value="1" <?php checked(!empty($settings['recreate'])); ?>> Пересоздать и те, у которых документ уже сформирован этим инструментом (номер сохраняется, файл заменяется)</label></p>
                <input type="hidden" name="users" value="<?php echo esc_attr($users); ?>">
                <p><button class="button button-primary">Сохранить</button></p>
            </form>
            <section class="zau-rb-card">
                <h2>2. Подготовить документы и сформировать PDF</h2>
                <p><label>Только для участников (ID через запятую; пусто — для всех):<br><input type="text" class="regular-text" data-zau-regen-users value="<?php echo esc_attr($users); ?>" placeholder="например: 125, 5486"></label></p>
                <p class="description">Сначала проверьте на 1–3 участниках: укажите их ID, подготовьте, сформируйте PDF и откройте документы в реестре или в «Подробнее» на странице проверки.</p>
                <p><button class="button button-primary" data-zau-regen="prepare">Подготовить документы</button> <span data-zau-regen-progress></span></p>
                <div data-zau-regen-result>
                    <?php if (!empty($state['finished']) && !empty($state['job_id'])): ?>
                        <p>Последняя подготовка (<?php echo esc_html($state['at']); ?><?php echo $state['users'] ? ', участники: ' . esc_html($state['users']) : ''; ?>): в очереди <?php echo (int)($state['queued'] ?? 0); ?> документов.
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-bulk-regenerate&job=' . (int)$state['job_id'])); ?>">Открыть очередь и сформировать PDF</a></p>
                    <?php endif; ?>
                </div>
                <p class="description">PDF формируется очередью «Массовое пересоздание». Если сервер это поддерживает (PHP GD со шрифтами) — <strong>на сервере в фоне</strong>: очередь запускается сразу, вкладку можно закрыть. Иначе — в браузере: откройте очередь и нажмите «Продолжить».</p>
            </section>
            <section class="zau-rb-card">
                <h2>Итог: у кого документы готовы</h2>
                <table class="widefat striped zau-regen-coverage"><tbody>
                    <tr><th>Перенесённых участников со своими заявлениями (по выбранным формам)</th><td><?php echo (int)$cov['members']; ?></td></tr>
                    <tr><th>Все документы готовы (есть PDF)</th><td><?php echo (int)$cov['members_ready']; ?></td></tr>
                    <tr><th>Есть черновики — PDF ещё не нарисован</th><td><?php echo (int)$cov['members_draft']; ?> <small>(черновиков: <?php echo (int)$cov['drafts']; ?>)</small></td></tr>
                    <tr><th>Документов нет (пропущены: нет подписи, форма не выбрана или ещё не запускали)</th><td><?php echo (int)$cov['members_none']; ?></td></tr>
                    <tr><th>Сделаны действительными</th><td><?php echo (int)$activated; ?></td></tr>
                </tbody></table>
                <p>
                    <button class="button button-primary" data-zau-regen="queue_drafts" <?php disabled(!$cov['drafts']); ?>>Дорисовать PDF для всех черновиков (<?php echo (int)$cov['drafts']; ?>)</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_exact_regen_csv'), self::NONCE)); ?>">Скачать CSV по участникам</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-registry&source=exact')); ?>">Открыть в реестре</a>
                </p>
                <p class="description">Черновик = запись и номер документа созданы, но PDF ещё не нарисован (очередь не запускали, прервали или была ошибка). Кнопка «Дорисовать» ставит все такие черновики в очередь.</p>
            </section>
            <section class="zau-rb-card">
                <h2>3. Отмена</h2>
                <p><button class="button" data-zau-regen="deactivate" data-confirm="Вернуть статусы, которые были до активации, всем участникам, ставшим действительными через этот инструмент? Кому статус после этого меняли вручную — не тронем.">Вернуть прежние статусы участников</button> <small>Сделано действительными этим инструментом: <?php echo (int)$activated; ?></small></p>
                <p><button class="button button-link-delete" data-zau-regen="delete" data-confirm="Удалить ВСЕ документы, созданные этим инструментом (записи и PDF)? Перенесённые заявления и документы новых заявлений не затрагиваются.">Удалить документы, созданные этим инструментом</button></p>
            </section>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Массовое исправление ФИО, записанных прежними переносами           */
    /* ------------------------------------------------------------------ */

    const NAMEFIX_SHARED_OPT = 'zau_exact_namefix_shared';
    const NAMEFIX_STATE_OPT = 'zau_exact_namefix_state';

    private function namefix_labels() {
        return [
            'strong'=>'Чужое ФИО — доказано',
            'plain'=>'Чужое ФИО — без доп. признаков',
            'review'=>'Только вручную',
        ];
    }

    private function name_key($value) {
        $tokens = $this->name_tokens($value);
        sort($tokens);
        return implode(' ', $tokens);
    }

    /** ФИО, которое стоит сразу в нескольких перенесённых аккаунтах (руководитель, кадровик), считается чужим. */
    private function namefix_build_shared() {
        global $wpdb;
        $names = (array)$wpdb->get_col("SELECT u.display_name FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='zau_exact_legacy_user_id'");
        $counts = [];
        foreach ($names as $name) { $key = $this->name_key($name); if ($key !== '') { $counts[$key] = ($counts[$key] ?? 0) + 1; } }
        $shared = array_filter($counts, function ($c) { return $c >= 3; });
        update_option(self::NAMEFIX_SHARED_OPT, $shared, false);
        return $shared;
    }

    /** ФИО из старого аккаунта; слова берутся из старого заявления того же человека, если оно совпадает (кириллица, отчество). */
    private function namefix_target(array $raw, $appName) {
        $meta = (array)($raw['meta'] ?? []);
        $first = trim($this->meta_first($meta, 'first_name'));
        $last = trim($this->meta_first($meta, 'last_name'));
        $middle = '';
        if ($first === '' && $last === '') {
            $display = trim((string)($raw['display_name'] ?? ''));
            if ($display === '' || $display === (string)($raw['user_login'] ?? '') || strpos($display, '@') !== false) { return null; }
            $parts = preg_split('/\s+/u', $display);
            $last = (string)array_shift($parts); $first = trim(implode(' ', $parts));
        }
        $appWords = [];
        foreach (preg_split('/\s+/u', trim(wp_strip_all_tags((string)$appName))) as $word) {
            $t = $this->name_tokens($word);
            if ($word !== '' && $t) { $appWords[] = ['word'=>$word, 'key'=>$t[0]]; }
        }
        $used = [];
        $pick = function ($value) use (&$appWords, &$used) {
            $t = $this->name_tokens($value);
            if (count($t) !== 1) { return $value; }
            foreach ($appWords as $i => $w) {
                if (isset($used[$i]) || $w['key'] !== $t[0]) { continue; }
                $used[$i] = 1;
                // Кириллица из аккаунта не заменяется латиницей из заявления.
                $cyr = '/[\x{0400}-\x{04FF}]/u';
                return (preg_match($cyr, $value) && !preg_match($cyr, $w['word'])) ? $value : $w['word'];
            }
            return $value;
        };
        if ($appWords) {
            $last = $pick($last); $first = $pick($first);
            $rest = array_values(array_diff_key($appWords, $used));
            if (count($used) === 2 && count($rest) === 1 && preg_match('/(вич|вна|ұлы|улы|қызы|кызы|vich|vna|uly|kyzy)$/iu', $rest[0]['word'])) { $middle = $rest[0]['word']; }
        }
        $fix = function ($v) {
            $v = trim((string)$v);
            if ($v !== '' && function_exists('mb_convert_case') && ($v === mb_strtoupper($v, 'UTF-8') || $v === mb_strtolower($v, 'UTF-8'))) { $v = mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'); }
            return $v;
        };
        $first = $fix($first); $last = $fix($last); $middle = $fix($middle);
        $display = trim(implode(' ', array_filter([$last, $first, $middle])));
        return $display === '' ? null : ['first'=>$first, 'last'=>$last, 'middle'=>$middle, 'display'=>$display];
    }

    /** Решение по одному участнику: strong / plain — можно исправить массово, review — только вручную, null — исправлять нечего. */
    private function namefix_plan($userId, array $shared) {
        global $wpdb;
        $a = $this->audit_user((int)$userId);
        $user = get_user_by('id', (int)$userId);
        if (!$a || !$user || ($a['status'] ?? '') !== 'diff' || !empty($a['reviewed'])) { return null; }
        $raw = $this->user_json_meta((int)$userId, 'zau_exact_legacy_raw');
        $current = trim((string)$user->display_name);
        $plan = ['cat'=>'review', 'reasons'=>[], 'evidence'=>[], 'from'=>$current, 'to'=>null, 'at'=>current_time('mysql')];
        if (($a['name_old'] ?? '') !== 'no') {
            $plan['reasons'][] = 'ФИО аккаунта совпадает со старым, но новое заявление подано на другое ФИО (' . ($a['new_app_name'] ?? '') . ')';
            return $plan;
        }
        $target = $raw ? $this->namefix_target($raw, (string)($a['app_name'] ?? '')) : null;
        $plan['to'] = $target;
        if (!$target) { $plan['reasons'][] = 'в старом аккаунте нет ФИО'; }
        if (user_can($user, 'edit_posts') || $this->can_manage_user($user)) { $plan['reasons'][] = 'служебный аккаунт (администратор/редактор)'; }
        $oldTokens = $this->old_user_name_tokens($raw);
        if (($a['name_new_app'] ?? 'none') !== 'none') {
            if (($a['name_new_app'] ?? '') === 'no' && $this->tokens_match($oldTokens, $this->name_tokens($a['new_app_name'] ?? '')) === 'yes') { $plan['evidence'][] = 'на новом сайте участник подал заявление на ФИО из старого аккаунта'; }
            else { $plan['reasons'][] = 'участник уже подал заявление на новом сайте на ФИО «' . ($a['new_app_name'] ?? '') . '»'; }
        }
        if (($a['app_name'] ?? '') !== '' && $this->tokens_match($oldTokens, $this->name_tokens($a['app_name'])) === 'no') { $plan['reasons'][] = 'старый аккаунт и старое заявление — на разные ФИО'; }
        $curTokens = array_values(array_unique(array_merge($this->name_tokens($user->last_name . ' ' . $user->first_name), $this->name_tokens($current))));
        if (array_intersect($curTokens, $oldTokens)) { $plan['reasons'][] = 'часть ФИО совпадает (возможна смена фамилии или опечатка)'; }
        foreach ((array)$this->user_json_meta((int)$userId, 'zau_exact_profile_backups') as $b) {
            if (($b['reason'] ?? '') !== 'bulk_fix_name') { $plan['reasons'][] = 'ФИО этого участника уже меняли вручную'; break; }
        }
        // Признаки того, что текущее ФИО чужое.
        $key = $this->name_key($current);
        if ($key !== '' && ($shared[$key] ?? 0) >= 3) { $plan['evidence'][] = 'это же ФИО стоит ещё в ' . ((int)$shared[$key] - 1) . ' аккаунтах'; }
        if ($current === '' || preg_match('/\d|@|^[^\p{L}]/u', $current) || $current === $user->user_login) { $plan['evidence'][] = 'в ФИО цифры, email или лишние знаки'; }
        if ($curTokens) {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT data_json FROM {$this->submissions_table} WHERE user_id=%d AND status=%s ORDER BY id DESC LIMIT 5", (int)$userId, self::S_OWN));
            foreach ((array)$rows as $json) {
                $d = json_decode((string)$json, true);
                foreach ((array)($d['legacy_fields'] ?? []) as $f) {
                    $v = $this->scalar_text($f['value'] ?? '');
                    if ($v === '' || strlen($v) > 200) { continue; }
                    $vt = $this->name_tokens($v);
                    if (count($vt) >= 2 && $this->tokens_match($curTokens, $vt) === 'yes' && $this->tokens_match($oldTokens, $vt) !== 'yes') {
                        $plan['evidence'][] = 'это ФИО из поля «' . wp_strip_all_tags((string)($f['label'] ?? '')) . '» его старого заявления';
                        break 2;
                    }
                }
            }
        }
        if (!$plan['reasons']) { $plan['cat'] = $plan['evidence'] ? 'strong' : 'plain'; }
        return $plan;
    }

    private function can_manage_user($user) {
        return user_can($user, 'manage_options') || (class_exists('ZAU_Certificate_PDF_Generator') && user_can($user, ZAU_Certificate_PDF_Generator::CAP_MANAGE));
    }

    private function namefix_apply($userId, array $plan, $batch) {
        $user = get_user_by('id', (int)$userId);
        $to = (array)($plan['to'] ?? []);
        if (!$user || empty($to['display'])) { return false; }
        $middleKey = 'zau_profile_middle_name';
        $middleOld = (string)get_user_meta((int)$userId, $middleKey, true);
        $backups = $this->user_json_meta((int)$userId, 'zau_exact_profile_backups');
        $backups[] = ['at'=>current_time('mysql'), 'by'=>get_current_user_id(), 'reason'=>'bulk_fix_name', 'batch'=>$batch,
            'user'=>['first_name'=>$user->first_name, 'last_name'=>$user->last_name, 'display_name'=>$user->display_name],
            'meta'=>($to['middle'] !== '' && $middleOld === '') ? [$middleKey=>''] : []];
        $this->update_user_json_meta((int)$userId, 'zau_exact_profile_backups', array_slice($backups, -5));
        wp_update_user(['ID'=>(int)$userId, 'first_name'=>$to['first'], 'last_name'=>$to['last'], 'display_name'=>$to['display']]);
        if ($to['middle'] !== '' && $middleOld === '') { update_user_meta((int)$userId, $middleKey, $to['middle']); }
        $this->update_user_json_meta((int)$userId, 'zau_exact_namefix_done', ['batch'=>$batch, 'at'=>current_time('mysql'), 'by'=>get_current_user_id(), 'from'=>$user->display_name, 'to'=>$to['display'], 'cat'=>$plan['cat'], 'evidence'=>$plan['evidence']]);
        delete_user_meta((int)$userId, 'zau_exact_namefix');
        $this->audit_user((int)$userId);
        return true;
    }

    private function namefix_rollback($userId) {
        $done = $this->user_json_meta((int)$userId, 'zau_exact_namefix_done');
        $user = get_user_by('id', (int)$userId);
        if (!$done || !$user) { return 'none'; }
        // ФИО изменили уже после массового исправления (участник или администратор) — не трогаем.
        if ((string)$user->display_name !== (string)($done['to'] ?? '')) { return 'changed'; }
        $backups = $this->user_json_meta((int)$userId, 'zau_exact_profile_backups');
        $last = end($backups);
        if (!is_array($last) || ($last['reason'] ?? '') !== 'bulk_fix_name') { return 'changed'; }
        $this->undo_restore((int)$userId);
        delete_user_meta((int)$userId, 'zau_exact_namefix_done');
        $this->audit_user((int)$userId);
        return 'restored';
    }

    public function ajax_namefix_run() {
        $this->require_access();
        global $wpdb;
        @set_time_limit(120);
        $mode = sanitize_key((string)($_POST['mode'] ?? 'preview'));
        $cursor = absint($_POST['cursor'] ?? 0);
        $cats = array_values(array_intersect(array_map('sanitize_key', (array)($_POST['cats'] ?? [])), ['strong', 'plain']));
        $state = (array)get_option(self::NAMEFIX_STATE_OPT, []);
        $limit = 40;
        if ($mode === 'preview') {
            if ($cursor === 0) {
                $this->namefix_build_shared();
                $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_namefix'");
                $state = ['preview_at'=>current_time('mysql'), 'preview_by'=>get_current_user_id()] + $state;
                $state['preview_done'] = false;
                update_option(self::NAMEFIX_STATE_OPT, $state, false);
            }
            $shared = (array)get_option(self::NAMEFIX_SHARED_OPT, []);
            $metaKey = 'zau_exact_legacy_user_id';
        } elseif ($mode === 'apply') {
            if (!$cats) { wp_send_json_error(['message'=>'Отметьте хотя бы одну группу.'], 400); }
            if (empty($state['preview_done'])) { wp_send_json_error(['message'=>'Сначала выполните просмотр до конца.'], 400); }
            if ($cursor === 0) { $state['batch'] = 'b' . gmdate('YmdHis'); update_option(self::NAMEFIX_STATE_OPT, $state, false); }
            $shared = (array)get_option(self::NAMEFIX_SHARED_OPT, []);
            $metaKey = 'zau_exact_namefix';
        } elseif ($mode === 'rollback') {
            $metaKey = 'zau_exact_namefix_done';
        } else { wp_send_json_error(['message'=>'Неизвестное действие.'], 400); }

        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id>%d ORDER BY user_id ASC LIMIT %d", $metaKey, $cursor, $limit));
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s", $metaKey));
        $done = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id<=%d", $metaKey, $cursor));
        $stats = (array)($_POST['stats'] ?? []);
        $stats = array_map('absint', is_array($stats) ? $stats : []);
        foreach ((array)$ids as $id) {
            $id = (int)$id; $cursor = $id;
            if ($mode === 'preview') {
                $plan = $this->namefix_plan($id, $shared);
                if ($plan) { $this->update_user_json_meta($id, 'zau_exact_namefix', $plan); $stats[$plan['cat']] = ($stats[$plan['cat']] ?? 0) + 1; }
            } elseif ($mode === 'apply') {
                $saved = $this->user_json_meta($id, 'zau_exact_namefix');
                if (!in_array($saved['cat'] ?? '', $cats, true)) { continue; }
                $plan = $this->namefix_plan($id, $shared); // пересчёт: вдруг ФИО уже поменяли после просмотра
                $user = get_user_by('id', $id);
                if (!$plan || $plan['cat'] !== $saved['cat'] || !$user || (string)$user->display_name !== (string)($saved['from'] ?? '')) { $stats['skipped'] = ($stats['skipped'] ?? 0) + 1; if ($plan) { $this->update_user_json_meta($id, 'zau_exact_namefix', $plan); } else { delete_user_meta($id, 'zau_exact_namefix'); } continue; }
                $k = $this->namefix_apply($id, $plan, (string)$state['batch']) ? 'fixed' : 'skipped';
                $stats[$k] = ($stats[$k] ?? 0) + 1;
            } else {
                $r = $this->namefix_rollback($id);
                $stats[$r] = ($stats[$r] ?? 0) + 1;
            }
        }
        $finished = count((array)$ids) < $limit;
        if ($finished) {
            $state = (array)get_option(self::NAMEFIX_STATE_OPT, []);
            if ($mode === 'preview') { $state['preview_done'] = true; }
            $state['last_' . $mode] = ['at'=>current_time('mysql'), 'stats'=>$stats];
            update_option(self::NAMEFIX_STATE_OPT, $state, false);
        }
        $processed = absint($_POST['processed'] ?? 0) + count((array)$ids);
        wp_send_json_success(['cursor'=>$cursor, 'done'=>$mode === 'preview' ? min($total, $done + count((array)$ids)) : $processed, 'processed'=>$processed, 'total'=>$mode === 'preview' ? $total : 0, 'finished'=>$finished, 'stats'=>$stats]);
    }

    private function namefix_counts() {
        global $wpdb;
        $out = [];
        foreach (array_keys($this->namefix_labels()) as $cat) {
            $out[$cat] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_namefix' AND meta_value LIKE %s", '%"cat":"' . $cat . '"%'));
        }
        $out['done'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='zau_exact_namefix_done'");
        return $out;
    }

    private function namefix_section() {
        $counts = $this->namefix_counts();
        $state = (array)get_option(self::NAMEFIX_STATE_OPT, []);
        $labels = $this->namefix_labels();
        $base = admin_url('admin.php?page=zau-exact-audit');
        ob_start(); ?>
        <section class="zau-rb-card zau-namefix">
            <h2>Массовое исправление чужих ФИО</h2>
            <p>Прежние переносы записали в часть аккаунтов ФИО руководителей и кадровиков из заявлений. Инструмент заменяет его на ФИО <strong>из старого аккаунта этого же человека</strong> (тот же email), а отчество и написание берёт из его старого заявления.</p>
            <p><strong>Не трогает:</strong> участников, подавших заявление на новом сайте; тех, у кого совпадает часть ФИО (смена фамилии); служебные аккаунты; отмеченных «Всё верно»; тех, кому ФИО уже правили вручную. Заявления и документы не меняются. Прежнее ФИО сохраняется — всё можно откатить.</p>
            <ol class="zau-namefix-steps">
                <li><button class="button" data-zau-namefix="preview">Просмотр (ничего не меняет)</button> <?php if (!empty($state['preview_at'])): ?><small>последний: <?php echo esc_html($state['preview_at']); ?><?php echo empty($state['preview_done']) ? ' — не завершён' : ''; ?></small><?php endif; ?></li>
                <li>Проверьте группы и скачайте CSV:
                    <?php foreach ($labels as $cat => $label): ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(['filter'=>'fix_' . $cat], $base)); ?>"><?php echo esc_html($label); ?>: <?php echo (int)$counts[$cat]; ?></a>
                    <?php endforeach; ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['filter'=>'fix_done'], $base)); ?>">Уже исправлено: <?php echo (int)$counts['done']; ?></a>
                </li>
                <li>Исправить:
                    <label><input type="checkbox" data-zau-namefix-cat value="strong" checked> <?php echo esc_html($labels['strong']); ?> (<?php echo (int)$counts['strong']; ?>)</label>
                    <label><input type="checkbox" data-zau-namefix-cat value="plain"> <?php echo esc_html($labels['plain']); ?> (<?php echo (int)$counts['plain']; ?>)</label>
                    <button class="button button-primary" data-zau-namefix="apply" data-confirm="Заменить ФИО у отмеченных групп на ФИО из старых аккаунтов? Прежние ФИО сохраняются, откат — кнопкой «Отменить массовое исправление».">Исправить выбранные группы</button>
                </li>
                <li>Если что-то не так: <button class="button" data-zau-namefix="rollback" data-confirm="Вернуть прежние ФИО всем, кому их заменило массовое исправление? Кого после этого уже правили вручную — не тронем.">Отменить массовое исправление</button></li>
            </ol>
            <p><span data-zau-namefix-progress></span></p>
            <?php foreach (['apply'=>'Последнее исправление', 'rollback'=>'Последняя отмена'] as $m => $t): if (!empty($state['last_' . $m])): $st = (array)$state['last_' . $m]['stats']; ?>
                <p class="description"><?php echo esc_html($t . ' (' . $state['last_' . $m]['at'] . '): '); ?>
                <?php echo esc_html(implode(' · ', array_map(function ($k, $v) { $n = ['fixed'=>'исправлено', 'skipped'=>'пропущено (изменилось после просмотра)', 'restored'=>'возвращено', 'changed'=>'не тронуто (правили после исправления)', 'none'=>'нет данных']; return ($n[$k] ?? $k) . ': ' . (int)$v; }, array_keys($st), $st))); ?></p>
            <?php endif; endforeach; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private function namefix_cell($userId) {
        $plan = $this->user_json_meta((int)$userId, 'zau_exact_namefix');
        $done = $this->user_json_meta((int)$userId, 'zau_exact_namefix_done');
        $html = '';
        if ($plan) {
            $labels = $this->namefix_labels();
            $html .= '<div class="zau-namefix-plan is-' . esc_attr($plan['cat']) . '"><strong>' . esc_html($labels[$plan['cat']] ?? $plan['cat']) . '</strong>';
            if (!empty($plan['to']['display']) && $plan['cat'] !== 'review') { $html .= '<br>станет: <strong>' . esc_html($plan['to']['display']) . '</strong>'; }
            foreach (array_merge((array)$plan['evidence'], (array)$plan['reasons']) as $r) { $html .= '<br><small>• ' . esc_html($r) . '</small>'; }
            $html .= '</div>';
        }
        if ($done) { $html .= '<div class="zau-namefix-plan is-done"><strong>Исправлено массово</strong><br><small>было: ' . esc_html($done['from'] ?? '') . ' (' . esc_html($done['at'] ?? '') . ')</small></div>'; }
        return $html;
    }

    public function audit_csv() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        global $wpdb;
        list($join, $where) = $this->audit_where(sanitize_key((string)($_GET['filter'] ?? 'all')), sanitize_text_field(wp_unslash($_GET['s'] ?? '')));
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-proverka-fio-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        zau_fputcsv($out, ['ID', 'ФИО на новом сайте', 'Email', 'ФИО в старом аккаунте', 'Совпадение', 'ФИО в старом заявлении', 'Совпадение', 'ФИО в новом заявлении', 'Совпадение', 'Подпись старая', 'Подпись новая', 'Итог', 'Проверено вручную', 'Массовое исправление', 'Станет', 'Причины', 'Уже исправлено (было)'], ';');
        $rows = $wpdb->get_results("SELECT u.ID,u.display_name,u.user_email,a.meta_value audit FROM {$wpdb->users} u $join WHERE $where ORDER BY u.display_name ASC LIMIT 50000");
        $w = ['yes'=>'совпадает', 'no'=>'отличается', 'none'=>'нет данных'];
        $fixLabels = $this->namefix_labels();
        foreach ((array)$rows as $r) {
            $a = json_decode((string)$r->audit, true) ?: [];
            $plan = $this->user_json_meta((int)$r->ID, 'zau_exact_namefix');
            $done = $this->user_json_meta((int)$r->ID, 'zau_exact_namefix_done');
            zau_fputcsv($out, [$r->ID, $r->display_name, $r->user_email, $a['old_name'] ?? '', $w[$a['name_old'] ?? 'none'], $a['app_name'] ?? '', $w[$a['name_app'] ?? 'none'], $a['new_app_name'] ?? '', $w[$a['name_new_app'] ?? 'none'], $a['sig_old'] ?? '', $a['sig_new'] ?? '', ($a['status'] ?? '') === 'diff' ? 'расхождение' : 'ок', empty($a['reviewed']) ? '' : 'да',
                $plan ? ($fixLabels[$plan['cat']] ?? $plan['cat']) : '', $plan && $plan['cat'] !== 'review' ? (string)($plan['to']['display'] ?? '') : '', $plan ? implode('; ', array_merge((array)$plan['evidence'], (array)$plan['reasons'])) : '', $done ? (string)($done['from'] ?? '') : ''], ';');
        }
        fclose($out);
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Личный кабинет                                                     */
    /* ------------------------------------------------------------------ */

    public function public_assets() {
        if (!is_user_logged_in()) { return; }
        $path = dirname(__DIR__) . '/assets/js/exact-migration-public.js';
        wp_enqueue_script('zau-exact-public', plugins_url('../assets/js/exact-migration-public.js', __FILE__), [], self::VERSION . (is_file($path) ? '.' . filemtime($path) : ''), true);
        wp_localize_script('zau-exact-public', 'ZAUExactPublic', ['ajaxUrl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce(self::PUBLIC_NONCE)]);
    }

    private function user_archive_rows($userId) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->submissions_table} WHERE user_id=%d AND status IN (%s,%s,%s) ORDER BY created_at DESC,id DESC",
            (int)$userId, self::S_OWN, self::S_OTHER, self::S_DISPUTED
        ));
    }

    private function is_migrated_user($userId) {
        return (bool)get_user_meta($userId, 'zau_exact_legacy_user_id', true) || (bool)get_user_meta($userId, 'zau_imported_from_legacy_site', true);
    }

    private function form_url() {
        $settings = (array)get_option('zau_union_settings', []);
        $page = absint($settings['form_page_id'] ?? 0);
        return $page ? get_permalink($page) : home_url('/registraciya-v-profsoyuz/');
    }

    private function value_html($value) {
        $value = (string)$value;
        if (preg_match('#^https?://\S+$#i', $value)) { return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">Открыть файл</a>'; }
        return nl2br(esc_html($value));
    }

    public function profile_dispute_html($userId) {
        if (!$this->is_migrated_user($userId)) { return ''; }
        $dispute = $this->user_json_meta($userId, 'zau_exact_profile_dispute');
        if (($dispute['status'] ?? '') === 'open') {
            return '<div class="zau-legacy-profile-note is-open"><strong>Вы сообщили о чужих данных в профиле.</strong> Администратор проверит и исправит профиль по данным вашего старого аккаунта.</div>';
        }
        return '<div class="zau-legacy-profile-note"><span>Данные перенесены со старого сайта. Если в профиле или карточке чужие ФИО, ИИН, телефон или организация — сообщите, и администратор пересоберёт профиль только из вашего старого аккаунта.</span> <button type="button" class="zau-union-button zau-secondary-button zau-small-button" data-zau-exact-profile-dispute>В профиле чужие данные</button></div>';
    }

    public function cabinet_archive_html($html, $userId) {
        $userId = (int)$userId;
        if (!$userId) { return $html; }
        $rows = $this->user_archive_rows($userId);
        if (!$rows && !$this->is_migrated_user($userId)) { return $html; }
        $formUrl = $this->form_url();
        ob_start();
        ?>
        <div class="zau-legacy-archive" data-zau-legacy-archive>
            <div class="zau-section-head"><div><h3>Заявления со старого сайта (<?php echo count($rows); ?>)</h3><p>Перенесены без изменений. Любое можно пересоздать на новом сайте — поля заполнятся из старого заявления.</p></div></div>
            <?php echo $this->profile_dispute_html($userId); ?>
            <?php if (!$rows): ?><div class="zau-empty-state">Старых заявлений не найдено.</div><?php endif; ?>
            <?php foreach ($rows as $row):
                $data = json_decode((string)$row->data_json, true) ?: [];
                $files = (array)($data['legacy_files'] ?? []);
                $badge = ['legacy'=>'', 'legacy_other'=>'Подано за другого человека', 'legacy_disputed'=>'Отмечено: не моё — на проверке'][$row->status] ?? '';
            ?>
                <details class="zau-legacy-item is-<?php echo esc_attr($row->status); ?>">
                    <summary><strong><?php echo esc_html(($data['legacy_form_title'] ?? '') ?: 'Заявление'); ?></strong> <small>№ <?php echo (int)($data['legacy_entry_id'] ?? 0); ?> · <?php echo esc_html(mysql2date('d.m.Y', (string)$row->created_at)); ?><?php if (($data['legacy_applicant_name'] ?? '') !== ''): ?> · <?php echo esc_html($data['legacy_applicant_name']); ?><?php endif; ?></small><?php if ($badge): ?> <span class="zau-legacy-badge"><?php echo esc_html($badge); ?></span><?php endif; ?></summary>
                    <?php if ($row->status === self::S_OTHER): ?><p class="zau-legacy-hint">Это заявление подано с вашего аккаунта, но в нём указан другой человек (<?php echo esc_html((string)($data['legacy_applicant_name'] ?? '')); ?>). Оно не используется в вашем профиле. Если заявление всё же ваше — нажмите «Это моё заявление».</p><?php endif; ?>
                    <dl class="zau-legacy-fields">
                        <?php foreach ((array)($data['legacy_fields'] ?? []) as $f): if ((string)($f['value'] ?? '') === '') { continue; }
                            $value = (string)$f['value'];
                            foreach ($files as $remote => $local) { if ($local && strpos($value, $remote) !== false) { $value = $local; } }
                        ?>
                            <div><dt><?php echo esc_html($f['label']); ?></dt><dd><?php echo $this->value_html($value); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <div class="zau-legacy-actions" data-submission-id="<?php echo (int)$row->id; ?>">
                        <?php if ($row->status === self::S_OWN): ?>
                            <a class="zau-union-button zau-small-button" href="<?php echo esc_url(add_query_arg('zau_prefill_from', (int)$row->id, $formUrl)); ?>">Пересоздать заявление</a>
                            <button type="button" class="zau-union-button zau-secondary-button zau-small-button" data-zau-exact-action="dispute">Это не моё заявление</button>
                        <?php elseif ($row->status === self::S_OTHER): ?>
                            <button type="button" class="zau-union-button zau-small-button" data-zau-exact-action="claim">Это моё заявление</button>
                            <button type="button" class="zau-union-button zau-secondary-button zau-small-button" data-zau-exact-action="dispute">Сообщить администратору</button>
                        <?php else: ?>
                            <button type="button" class="zau-union-button zau-secondary-button zau-small-button" data-zau-exact-action="undo">Отменить отметку</button>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            <div class="zau-legacy-message" data-zau-exact-message aria-live="polite"></div>
        </div>
        <?php
        return $html . ob_get_clean();
    }

    public function member_card_after_html($html, $memberId, $viewerId) {
        if ((int)$memberId !== (int)$viewerId) { return $html; }
        $note = $this->profile_dispute_html((int)$memberId);
        return $note ? $html . '<div class="zau-legacy-archive">' . $note . '<div class="zau-legacy-message" data-zau-exact-message aria-live="polite"></div></div>' : $html;
    }

    public function ajax_submission_action() {
        check_ajax_referer(self::PUBLIC_NONCE, 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message'=>'Сначала войдите.'], 401); }
        global $wpdb;
        $uid = get_current_user_id();
        $id = absint($_POST['submission_id'] ?? 0);
        $action = sanitize_key((string)($_POST['do'] ?? ''));
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d", $id));
        if (!$row || (int)$row->user_id !== $uid) { wp_send_json_error(['message'=>'Заявление не найдено.'], 404); }
        $data = json_decode((string)$row->data_json, true);
        if (!is_array($data) || empty($data['legacy_exact'])) { wp_send_json_error(['message'=>'Это не архивное заявление.'], 400); }
        if ($action === 'dispute' && in_array($row->status, [self::S_OWN, self::S_OTHER], true)) {
            $this->set_submission_state($id, self::S_DISPUTED, null, ['legacy_dispute'=>['by'=>$uid, 'at'=>current_time('mysql'), 'comment'=>$comment, 'prev_status'=>$row->status]]);
            wp_send_json_success(['message'=>'Спасибо. Заявление убрано из вашего профиля и передано администратору на проверку.']);
        }
        if ($action === 'claim' && $row->status === self::S_OTHER) {
            $this->set_submission_state($id, self::S_OWN, null, ['legacy_claimed'=>['by'=>$uid, 'at'=>current_time('mysql')]]);
            wp_send_json_success(['message'=>'Заявление отмечено как ваше.']);
        }
        if ($action === 'undo' && $row->status === self::S_DISPUTED) {
            $prev = (string)($data['legacy_dispute']['prev_status'] ?? self::S_OWN);
            $this->set_submission_state($id, in_array($prev, [self::S_OWN, self::S_OTHER], true) ? $prev : self::S_OWN, null, ['legacy_dispute'=>null]);
            wp_send_json_success(['message'=>'Отметка отменена.']);
        }
        wp_send_json_error(['message'=>'Это действие сейчас недоступно.'], 400);
    }

    public function ajax_profile_dispute() {
        check_ajax_referer(self::PUBLIC_NONCE, 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message'=>'Сначала войдите.'], 401); }
        $uid = get_current_user_id();
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
        if ($comment === '') { wp_send_json_error(['message'=>'Опишите, какие данные чужие.'], 400); }
        $this->update_user_json_meta($uid, 'zau_exact_profile_dispute', ['status'=>'open', 'at'=>current_time('mysql'), 'comment'=>$comment]);
        wp_send_json_success(['message'=>'Сообщение отправлено администратору. Профиль будет исправлен по данным вашего старого аккаунта.']);
    }

    /* ------------------------------------------------------------------ */
    /* Пересоздание заявления                                             */
    /* ------------------------------------------------------------------ */

    private function prefill_source() {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $cache = [];
        $id = absint($_GET['zau_prefill_from'] ?? 0);
        if (!$id || !is_user_logged_in()) { return $cache; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d AND user_id=%d AND status=%s", $id, get_current_user_id(), self::S_OWN));
        if (!$row) { return $cache; }
        $data = json_decode((string)$row->data_json, true);
        if (!is_array($data) || empty($data['legacy_exact'])) { return $cache; }
        $cache = ['id'=>$id] + $this->legacy_source($data);
        return $cache;
    }

    /** Поля старого заявления в удобном для сопоставления виде: по подписи поля и по распознанному смыслу. */
    private function legacy_source(array $data) {
        $byLabel = [];
        foreach ((array)($data['legacy_fields'] ?? []) as $f) {
            if ((string)($f['value'] ?? '') !== '' && !in_array($f['type'] ?? '', ['signature', 'file-upload'], true)) { $byLabel[$this->normalize_label($f['label'])] = (string)$f['value']; }
        }
        return ['data'=>$data, 'by_label'=>$byLabel, 'extracted'=>$this->extracted_entry_data((array)($data['legacy_fields'] ?? []))];
    }

    public function prefill_field_value($value, $field, $user) {
        $src = $this->prefill_source();
        if (!$src || !is_array($field)) { return $value; }
        $type = (string)($field['type'] ?? '');
        if (in_array($type, ['signature', 'hidden', 'heading', 'checkbox'], true)) { return $value; }
        $found = $this->legacy_field_value($field, $src);
        return $found !== '' ? $found : $value;
    }

    /** Значение поля новой формы из старого заявления ('' — не найдено). */
    private function legacy_field_value(array $field, array $src) {
        $type = (string)($field['type'] ?? '');
        $key = (string)($field['key'] ?? '');
        $data = $src['data'];
        $label = $this->normalize_label($field['label'] ?? '');
        $found = '';
        if ($label !== '' && isset($src['by_label'][$label])) { $found = $src['by_label'][$label]; }
        elseif ($key === 'full_name' && !empty($data['legacy_applicant_name'])) { $found = (string)$data['legacy_applicant_name']; }
        elseif (in_array($key, ['iin', 'birth_date', 'address', 'position', 'department', 'organization', 'organization_bin', 'organization_director', 'organization_address', 'phone', 'email'], true) && !empty($src['extracted'][$key])) { $found = (string)$src['extracted'][$key]; }
        elseif (in_array($key, ['last_name', 'first_name', 'middle_name'], true)) {
            // Поле WPForms «Имя» хранит части отдельно (first/middle/last) — берём их как есть.
            $part = ['last_name'=>'last', 'first_name'=>'first', 'middle_name'=>'middle'][$key];
            foreach ((array)($data['legacy_raw']['fields'] ?? []) as $raw) {
                if (is_array($raw) && ($raw['type'] ?? '') === 'name' && trim((string)($raw['first'] ?? '') . (string)($raw['last'] ?? '')) !== '') { $found = trim((string)($raw[$part] ?? '')); break; }
            }
            if ($found === '' && !empty($data['legacy_applicant_name'])) {
                $parts = preg_split('/\s+/u', trim((string)$data['legacy_applicant_name']));
                $found = (string)($parts[['last_name'=>0, 'first_name'=>1, 'middle_name'=>2][$key]] ?? '');
            }
        }
        if ($type === 'branch_select' && $found === '' && !empty($src['extracted']['branch_name'])) { $found = (string)$src['extracted']['branch_name']; }
        if ($type === 'branch_select' && $found !== '' && class_exists('ZAU_Remote_Profile_Repair')) {
            $branch = ZAU_Remote_Profile_Repair::instance()->match_branch($found);
            $found = !empty($branch['id']) ? (string)(int)$branch['id'] : '';
        }
        if ($type === 'date' && $found !== '') { $ts = strtotime($found); $found = $ts ? gmdate('Y-m-d', $ts) : ''; }
        return (string)$found;
    }

    public function form_prefill_notice($html, $form, $user) {
        $src = $this->prefill_source();
        if (!$src) { return $html; }
        return $html . '<div class="zau-union-note zau-legacy-prefill-note"><strong>Поля заполнены из заявления № ' . (int)($src['data']['legacy_entry_id'] ?? 0) . ' со старого сайта.</strong> Проверьте данные, при необходимости исправьте, поставьте подпись и отправьте — будет создано новое заявление, старое останется в архиве.</div>';
    }
}

ZAU_Exact_Migration::instance();
