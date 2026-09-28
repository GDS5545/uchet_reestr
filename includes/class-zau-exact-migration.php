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
    const VERSION = '2.28.3';
    const DB_VERSION = '1.1.0';
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
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 39);
        add_action('admin_menu', [$this, 'admin_menu'], 40);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_zau_exact_save', [$this, 'save_settings']);
        add_action('admin_post_zau_exact_report', [$this, 'download_report']);
        add_action('admin_post_zau_exact_queue_action', [$this, 'queue_action']);

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

    private function name_tokens($value) {
        $value = str_replace('ё', 'е', $this->lower(wp_strip_all_tags((string)$value)));
        $value = preg_replace('/[^\p{L}]+/u', ' ', $value);
        $tokens = [];
        foreach (preg_split('/\s+/u', trim((string)$value)) as $token) {
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
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-exact-migration') === false) { return; }
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
            $job['phase'] = 'cleanup';
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
                    $job['phase'] = $job['phase'] === 'v_users' ? 'v_entries' : 'entries';
                    $job['cursor'] = 0;
                    $job['log'][] = 'Аккаунты обработаны: ' . $job['processed']['users'] . '. Этап 2: записи WPForms.';
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
        $tokens = ['tokens'=>$this->old_user_name_tokens($item)];
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
            if (empty($settings['create_users'])) {
                $this->add_stat($job, 'skipped_no_create');
                $this->report($job, 'user', $oldId, 'skipped_no_create', 'Аккаунт не найден по email, а создание новых аккаунтов выключено.');
                return;
            }
            if ($dry) {
                $this->add_stat($job, 'would_create');
                $this->report($job, 'user', $oldId, 'would_create', $email ? 'Будет создан новый аккаунт с email ' . $email . '.' : 'Будет создан новый аккаунт без email (на старом сайте email пустой).', 0, 0, $tokens);
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
            "SELECT details FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND {$where}=%d ORDER BY id DESC LIMIT 1",
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
                    "SELECT decision,target_user_id FROM {$this->report_table} WHERE job_token=%s AND source_type='user' AND source_id=%d ORDER BY id DESC LIMIT 1",
                    (string)$job['token'], $oldUser
                ));
                if ($row && in_array($row->decision, ['would_create', 'would_link_existing', 'would_update', 'unchanged'], true)) {
                    return ['user_id'=>(int)$row->target_user_id, 'method'=>'account', 'pending'=>1, 'tokens'=>$this->report_tokens($job, 'source_id', $oldUser)];
                }
            }
            return ['user_id'=>0, 'method'=>'account_not_transferred', 'message'=>'Старый аккаунт #' . $oldUser . ' не перенесён (удалён на старом сайте, администратор или конфликт).'];
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

    private function cleanup_step(&$job, $apply) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count(self::archive_statuses()), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,user_id,status,data_json FROM {$this->submissions_table} WHERE id>%d AND status NOT IN ($placeholders) ORDER BY id ASC LIMIT 200",
            array_merge([(int)$job['cursor']], self::archive_statuses())
        ));
        foreach ((array)$rows as $row) {
            $job['cursor'] = (int)$row->id;
            $data = json_decode((string)$row->data_json, true);
            if (!is_array($data)) { continue; }
            $entryId = $this->prior_entry_id($data);
            if (!$entryId) { continue; }
            $this->add_stat($job, 'prior_submissions');
            $map = $this->get_map('entry', $entryId);
            $exact = ($map && (int)$map->target_submission_id) ? $wpdb->get_row($wpdb->prepare("SELECT id,user_id FROM {$this->submissions_table} WHERE id=%d", (int)$map->target_submission_id)) : null;
            if (!$exact) {
                $this->add_stat($job, 'no_exact_copy');
                $this->report($job, 'prior', (int)$row->id, 'no_exact_copy', 'Нет точной копии записи WPForms #' . $entryId . ' — заявка оставлена как есть.', (int)$row->user_id, 0, ['entry_id'=>$entryId]);
                continue;
            }
            $wrongOwner = (int)$row->user_id !== (int)$exact->user_id;
            if ($wrongOwner) { $this->add_stat($job, 'prior_wrong_owner'); }
            $docs = $wpdb->get_results($wpdb->prepare("SELECT id,user_id,source_submission_id FROM {$this->docs_table} WHERE source_submission_id=%d", (int)$row->id));
            $details = ['entry_id'=>$entryId, 'prev_status'=>(string)$row->status, 'prev_user_id'=>(int)$row->user_id, 'exact_submission_id'=>(int)$exact->id, 'exact_user_id'=>(int)$exact->user_id, 'docs'=>[]];
            foreach ((array)$docs as $doc) { $details['docs'][] = ['id'=>(int)$doc->id, 'user_id'=>(int)$doc->user_id, 'source_submission_id'=>(int)$doc->source_submission_id]; }
            $message = 'Заявка прежнего переноса для записи #' . $entryId . ($wrongOwner ? ' была у ДРУГОГО аккаунта (#' . (int)$row->user_id . ' вместо #' . (int)$exact->user_id . ')' : '') . '; документов: ' . count($details['docs']) . '.';
            if (!$apply) {
                $this->add_stat($job, 'would_supersede');
                $this->report($job, 'prior', (int)$row->id, 'would_supersede', $message, (int)$row->user_id, (int)$exact->id, $details);
                continue;
            }
            $wpdb->update($this->submissions_table, ['status'=>self::S_SUPERSEDED, 'updated_at'=>current_time('mysql')], ['id'=>(int)$row->id]);
            foreach ($details['docs'] as $doc) {
                $update = ['source_submission_id'=>(int)$exact->id];
                if ((int)$exact->user_id) { $update['user_id'] = (int)$exact->user_id; }
                $wpdb->update($this->docs_table, $update, ['id'=>(int)$doc['id']]);
            }
            $this->add_stat($job, 'superseded');
            $this->report($job, 'prior', (int)$row->id, 'superseded', $message, (int)$row->user_id, (int)$exact->id, $details);
        }
        if (count((array)$rows) < 200) {
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
        zau_fputcsv($out, ['Тип', 'Старый ID', 'Решение', 'Пояснение', 'Аккаунт нового сайта', 'Заявка нового сайта', 'ФИО в заявлении', 'Совпадение ФИО'], ';');
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->report_table} WHERE job_token=%s ORDER BY id ASC LIMIT 2000 OFFSET %d", $token, $offset));
            foreach ((array)$rows as $row) {
                $details = json_decode((string)$row->details, true);
                zau_fputcsv($out, [$row->source_type, $row->source_id, $row->decision, $row->message, $row->target_user_id ?: '', $row->target_submission_id ?: '', $details['applicant'] ?? '', $details['match'] ?? ''], ';');
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
        if (!in_array($view, ['disputed', 'profiles', 'unassigned', 'other'], true)) { $view = 'disputed'; }
        $counts = $this->queue_counts();
        $labels = ['disputed'=>'Пользователь отметил «не моё»', 'profiles'=>'Профили на проверку', 'unassigned'=>'Без владельца', 'other'=>'Поданы за другого человека'];
        echo '<section class="zau-rb-card" id="zau-exact-queue"><h2>5. Очередь проверки</h2>';
        if (!empty($_GET['msg'])) { echo '<div class="notice notice-info inline"><p>' . esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['msg'])))) . '</p></div>'; }
        echo '<p class="zau-exact-tabs">';
        foreach ($labels as $key => $label) {
            echo '<a class="button' . ($key === $view ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg(['page'=>'zau-exact-migration', 'view'=>$key], admin_url('admin.php')) . '#zau-exact-queue') . '">' . esc_html($label) . ' (' . (int)$counts[$key] . ')</a> ';
        }
        echo '</p>';
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
                if ($prior !== '') { $mark[] = 'Прежний перенос: старый #' . esc_html($prior); }
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
        $byLabel = [];
        foreach ((array)($data['legacy_fields'] ?? []) as $f) {
            if ((string)($f['value'] ?? '') !== '' && !in_array($f['type'] ?? '', ['signature', 'file-upload'], true)) { $byLabel[$this->normalize_label($f['label'])] = (string)$f['value']; }
        }
        $cache = ['id'=>$id, 'data'=>$data, 'by_label'=>$byLabel, 'extracted'=>$this->extracted_entry_data((array)($data['legacy_fields'] ?? []))];
        return $cache;
    }

    public function prefill_field_value($value, $field, $user) {
        $src = $this->prefill_source();
        if (!$src || !is_array($field)) { return $value; }
        $type = (string)($field['type'] ?? '');
        if (in_array($type, ['signature', 'hidden', 'heading', 'checkbox'], true)) { return $value; }
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
        return $found !== '' ? $found : $value;
    }

    public function form_prefill_notice($html, $form, $user) {
        $src = $this->prefill_source();
        if (!$src) { return $html; }
        return $html . '<div class="zau-union-note zau-legacy-prefill-note"><strong>Поля заполнены из заявления № ' . (int)($src['data']['legacy_entry_id'] ?? 0) . ' со старого сайта.</strong> Проверьте данные, при необходимости исправьте, поставьте подпись и отправьте — будет создано новое заявление, старое останется в архиве.</div>';
    }
}

ZAU_Exact_Migration::instance();
