<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistent browser-assisted queue for bulk PDF regeneration.
 * Rendering stays in the browser because the main plugin uses Canvas,
 * while job state is stored in WordPress so an interrupted run can resume.
 */
final class ZAU_Bulk_Regeneration {
    const VERSION = '2.11.0';
    const DB_VERSION = '1.0.0';
    const OPT_DB_VERSION = 'zau_bulk_regeneration_db_version';
    const NONCE = 'zau_cert_nonce';

    private static $instance = null;
    private $jobs_table;
    private $items_table;
    private $docs_table;
    private $templates_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'zau_cert_bulk_jobs';
        $this->items_table = $wpdb->prefix . 'zau_cert_bulk_job_items';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';
        $this->templates_table = $wpdb->prefix . 'zau_cert_templates';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 20);
        add_action('admin_menu', [$this, 'admin_menu'], 30);
        add_action('wp_ajax_zau_cert_bulk_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_zau_cert_bulk_create_job', [$this, 'ajax_create_job']);
        add_action('wp_ajax_zau_cert_bulk_next_item', [$this, 'ajax_next_item']);
        add_action('wp_ajax_zau_cert_bulk_mark_item', [$this, 'ajax_mark_item']);
        add_action('wp_ajax_zau_cert_bulk_job_status', [$this, 'ajax_job_status']);
        add_action('wp_ajax_zau_cert_bulk_control', [$this, 'ajax_control']);
        add_action('admin_post_zau_cert_bulk_report', [$this, 'download_report']);
        // Фоновая (серверная) генерация PDF.
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action(self::CRON_HOOK, [$this, 'cron_tick']);
        add_action(self::CRON_HOOK . '_now', [$this, 'cron_tick']);
        add_action('init', [$this, 'ensure_cron']);
        add_action('wp_ajax_zau_cert_bulk_server_tick', [$this, 'ajax_server_tick']);
        add_action('wp_ajax_zau_cert_bulk_server_probe', [$this, 'ajax_server_probe']);
        add_action('wp_ajax_nopriv_zau_cert_bulk_bg', [$this, 'ajax_background_chain']);
        add_action('wp_ajax_zau_cert_bulk_bg', [$this, 'ajax_background_chain']);
    }

    const CRON_HOOK = 'zau_cert_bulk_server_tick';
    const LOCK_OPT = 'zau_cert_bulk_server_lock';
    const CHAIN_OPT = 'zau_cert_bulk_chain_key';

    public function cron_schedules($s) {
        $s['zau_every_minute'] = ['interval'=>60, 'display'=>'Каждую минуту (ZAU фоновые PDF)'];
        return $s;
    }

    /** Крон нужен только пока есть серверные очереди. */
    public function ensure_cron() {
        $has = (bool)get_transient('zau_cert_bulk_server_active');
        if ($has && !wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_event(time() + 30, 'zau_every_minute', self::CRON_HOOK); }
        if (!$has && wp_next_scheduled(self::CRON_HOOK)) { wp_clear_scheduled_hook(self::CRON_HOOK); }
    }

    public function server_available(&$reason = '') {
        return class_exists('ZAU_Server_Render') && ZAU_Server_Render::available($reason);
    }

    private function server_jobs() {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE status IN ('running','queued') AND options_json LIKE %s ORDER BY id ASC", '%"server":1%'));
    }

    public function cron_tick() {
        $this->process_server_jobs(45, 'cron');
    }

    /**
     * Обрабатывает серверные очереди в пределах $budget секунд. Один процесс за раз (блокировка на 2 минуты).
     * Возвращает число обработанных документов.
     */
    const STATUS_OPT = 'zau_cert_bulk_server_status';
    private $current_item = 0;
    private $lock_token = '';

    /** Сколько секунд можно работать в этом запросе, чтобы хостинг не оборвал PHP. */
    private function safe_budget($wanted) {
        @set_time_limit((int)$wanted + 60);
        $limit = (int)ini_get('max_execution_time');
        return $limit > 0 ? max(5, min((int)$wanted, $limit - 15)) : (int)$wanted;
    }

    private function save_server_status(array $patch) {
        $st = (array)get_option(self::STATUS_OPT, []);
        update_option(self::STATUS_OPT, array_merge($st, $patch), false);
    }

    /**
     * Обрабатывает серверные очереди в пределах $budget секунд. Один процесс за раз:
     * блокировка с «пульсом» после каждого документа; брошенная (процесс убит хостингом) снимается через 90 с.
     * Возвращает число обработанных документов, -1 — если сейчас работает другой процесс.
     */
    public function process_server_jobs($budget = 40, $context = 'cron') {
        global $wpdb;
        $lock = (int)get_option(self::LOCK_OPT, 0);
        if ($lock && time() - $lock < 90) { return -1; }
        update_option(self::LOCK_OPT, time(), false);
        $this->lock_token = wp_generate_password(8, false, false);
        if (function_exists('wp_raise_memory_limit')) { wp_raise_memory_limit('admin'); }
        $budget = $this->safe_budget($budget);
        $this->save_server_status(['last_start'=>current_time('mysql'), 'context'=>$context, 'budget'=>$budget, 'memory_limit'=>(string)ini_get('memory_limit'), 'time_limit'=>(int)ini_get('max_execution_time')]);
        // Если PHP упадёт (память, время) — записать причину в документ очереди и снять блокировку.
        register_shutdown_function(function () use ($context) {
            if ($this->lock_token === '') { return; }
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                global $wpdb;
                $msg = 'Фатальная ошибка PHP при формировании: ' . $e['message'];
                if ($this->current_item) { $wpdb->update($this->items_table, ['status'=>'failed', 'error_text'=>mb_substr($msg, 0, 4000), 'completed_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')], ['id'=>$this->current_item]); }
                $this->save_server_status(['last_error'=>$msg, 'last_error_at'=>current_time('mysql')]);
            }
            delete_option(self::LOCK_OPT);
        });
        $started = microtime(true); $done = 0;
        try {
            foreach ($this->server_jobs() as $job) {
                while (microtime(true) - $started < $budget) {
                    // Фоновый процесс идёт без пользователя — читаем задание без проверки прав.
                    $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE id=%d", (int)$job->id));
                    if (!$fresh || in_array($fresh->status, ['paused', 'canceled', 'completed'], true)) { break; }
                    if (!$this->process_server_item($fresh)) { break; }
                    $done++;
                    update_option(self::LOCK_OPT, time(), false);
                }
                if (microtime(true) - $started >= $budget) { break; }
            }
        } finally {
            $this->current_item = 0;
            $this->lock_token = '';
            delete_option(self::LOCK_OPT);
        }
        $this->save_server_status(['last_end'=>current_time('mysql'), 'last_done'=>$done, 'last_seconds'=>round(microtime(true) - $started, 1)]);
        $active = (bool)$this->server_jobs_with_work();
        if ($active) { set_transient('zau_cert_bulk_server_active', 1, DAY_IN_SECONDS); } else { delete_transient('zau_cert_bulk_server_active'); }
        return $done;
    }

    private function server_jobs_with_work() {
        global $wpdb;
        foreach ($this->server_jobs() as $job) {
            if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d AND status IN ('queued','processing')", (int)$job->id))) { return true; }
            $this->refresh_job_counts((int)$job->id);
        }
        return false;
    }

    /** Один документ очереди: данные → серверная отрисовка → PDF. false — в очереди больше нечего брать. */
    private function process_server_item($job) {
        global $wpdb;
        $jobId = (int)$job->id;
        $stale = wp_date('Y-m-d H:i:s', time() - 180);
        $wpdb->query($wpdb->prepare("UPDATE {$this->items_table} SET status='queued',updated_at=%s WHERE job_id=%d AND status='processing' AND updated_at<%s", current_time('mysql'), $jobId, $stale));
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->items_table} WHERE job_id=%d AND status='queued' ORDER BY id ASC LIMIT 1", $jobId));
        if (!$item) { $this->refresh_job_counts($jobId); return false; }
        $now = current_time('mysql');
        $claimed = $wpdb->query($wpdb->prepare("UPDATE {$this->items_table} SET status='processing',attempts=attempts+1,started_at=%s,updated_at=%s WHERE id=%d AND status='queued'", $now, $now, (int)$item->id));
        if (!$claimed) { return true; }
        $this->current_item = (int)$item->id;
        $options = json_decode((string)$job->options_json, true) ?: [];
        $wpdb->update($this->jobs_table, ['status'=>'running', 'current_document_id'=>(int)$item->document_id, 'updated_at'=>$now], ['id'=>$jobId]);
        $error = '';
        $docNo = '';
        $gen = class_exists('ZAU_Certificate_PDF_Generator') ? ZAU_Certificate_PDF_Generator::instance() : null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d", (int)$item->document_id));
        if (!$row || !$gen) {
            $wpdb->update($this->items_table, ['status'=>'skipped', 'error_text'=>'Запись документа удалена.', 'completed_at'=>$now, 'updated_at'=>$now], ['id'=>(int)$item->id]);
            return true;
        }
        $wpdb->update($this->items_table, ['old_document_no'=>$row->document_no], ['id'=>(int)$item->id]);
        $payload = $gen->regeneration_payload((int)$row->id, (int)($options['target_template_id'] ?? 0), !empty($options['update_number']));
        if (is_wp_error($payload)) { $error = $payload->get_error_message(); }
        elseif (!empty($options['skip_without_signature']) && empty($payload['values']['signature_url'])) {
            $wpdb->update($this->items_table, ['status'=>'skipped', 'error_text'=>'Подпись отсутствует.', 'completed_at'=>$now, 'updated_at'=>$now], ['id'=>(int)$item->id]);
            return true;
        } else {
            try { $jpeg = ZAU_Server_Render::render((array)$payload['template'], (array)$payload['values']); }
            catch (Throwable $e) { $jpeg = new WP_Error('render', $e->getMessage()); }
            if (is_wp_error($jpeg)) { $error = $jpeg->get_error_message(); }
            else {
                $target = !empty($options['target_template_id']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id=%d", (int)$options['target_template_id'])) : null;
                $stored = $gen->store_document_image($row, $jpeg, (string)$payload['document_no'], $target, !empty($options['keep_old_files']));
                if (is_wp_error($stored)) { $error = $stored->get_error_message(); } else { $docNo = (string)$stored['document_no']; }
            }
        }
        $now = current_time('mysql');
        if ($error === '') {
            $wpdb->update($this->items_table, ['status'=>'success', 'new_document_no'=>$docNo, 'error_text'=>'', 'completed_at'=>$now, 'updated_at'=>$now], ['id'=>(int)$item->id]);
        } else {
            $max = (int)($options['max_retries'] ?? 2);
            $next = ((int)$item->attempts + 1 <= $max) ? 'queued' : 'failed';
            $wpdb->update($this->items_table, ['status'=>$next, 'error_text'=>mb_substr($error, 0, 4000), 'completed_at'=>$next === 'failed' ? $now : null, 'updated_at'=>$now], ['id'=>(int)$item->id]);
        }
        if ($error !== '') { $this->save_server_status(['last_error'=>'#' . (int)$item->document_id . ': ' . $error, 'last_error_at'=>$now]); }
        $this->current_item = 0;
        if ((int)$item->id % 10 === 0) { $this->refresh_job_counts($jobId); }
        return true;
    }

    private function server_info_text() {
        $st = (array)get_option(self::STATUS_OPT, []);
        if (!$st) { return 'Сервер ещё не запускал обработку.'; }
        $ctx = ['page'=>'со страницы очереди', 'cron'=>'по расписанию (WP-Cron)', 'background'=>'в фоне'][$st['context'] ?? ''] ?? '';
        $out = 'Последний запуск: ' . ($st['last_start'] ?? '—') . ' ' . $ctx . (isset($st['last_done']) ? ' · обработано за запуск: ' . (int)$st['last_done'] . ' за ' . ($st['last_seconds'] ?? '?') . ' с' : '') . ' · лимиты PHP: ' . ($st['time_limit'] ? $st['time_limit'] . ' с' : 'без ограничения времени') . ', память ' . ($st['memory_limit'] ?? '?');
        if (!empty($st['last_error'])) { $out .= ' · Последняя ошибка (' . ($st['last_error_at'] ?? '') . '): ' . $st['last_error']; }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) { $out .= ' · WP-Cron отключён на сайте: без открытой страницы очередь пойдёт только при системном cron.'; }
        return $out;
    }

    /** «Проверить сервер»: окружение + пошаговая отрисовка одного документа очереди (ничего не сохраняет). */
    public function ajax_server_probe() {
        $this->require_ajax();
        global $wpdb;
        @set_time_limit(90);
        $jobId = absint($_POST['job_id'] ?? 0);
        $rows = [];
        $add = function ($step, $ok, $info, $ms = null) use (&$rows) { $rows[] = ['step'=>$step, 'ok'=>$ok, 'info'=>$info, 'ms'=>$ms]; };
        $add('PHP', true, 'версия ' . PHP_VERSION . ', лимит времени ' . ((int)ini_get('max_execution_time') ?: 'нет') . ' с, память ' . ini_get('memory_limit') . (function_exists('set_time_limit') && strpos((string)ini_get('disable_functions'), 'set_time_limit') === false ? '' : ', set_time_limit запрещён'));
        $up = wp_upload_dir();
        $add('Папка загрузок', empty($up['error']) && wp_is_writable($up['basedir']), empty($up['error']) ? $up['basedir'] . (wp_is_writable($up['basedir']) ? ' — запись разрешена' : ' — НЕТ прав на запись') : $up['error']);
        $lock = (int)get_option(self::LOCK_OPT, 0);
        $add('Блокировка обработки', !$lock || time() - $lock >= 90, $lock ? 'занята ' . (time() - $lock) . ' с назад' . (time() - $lock >= 90 ? ' (брошена — будет снята)' : ' — сейчас работает другой процесс') : 'свободна');
        $cronOff = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next = wp_next_scheduled(self::CRON_HOOK);
        $doing = get_transient('doing_cron');
        $add('WP-Cron', !$cronOff, ($cronOff ? 'ОТКЛЮЧЁН (DISABLE_WP_CRON) — фон только с открытой страницей или системным cron' : 'включён') . '; следующий запуск очереди: ' . ($next ? wp_date('H:i:s', $next) . ($next < time() - 120 ? ' — ПРОСРОЧЕН (cron не срабатывает)' : '') : 'не запланирован') . ($doing ? '; cron сейчас выполняется с ' . wp_date('H:i:s', (int)$doing) : ''));
        $t = microtime(true);
        $r = wp_remote_post(site_url('wp-cron.php'), ['timeout'=>8, 'blocking'=>true, 'sslverify'=>apply_filters('https_local_ssl_verify', false)]);
        $ms = (int)round((microtime(true) - $t) * 1000);
        $add('Сайт может обратиться сам к себе (wp-cron.php)', !is_wp_error($r) && (int)wp_remote_retrieve_response_code($r) < 400, is_wp_error($r) ? 'ошибка: ' . $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r), $ms);
        $st = (array)get_option(self::STATUS_OPT, []);
        $add('Последний запуск обработки', empty($st['last_error']), $st ? $this->server_info_text() : 'ещё не было');
        $job = $this->get_job($jobId);
        if ($job) {
            $counts = $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) c FROM {$this->items_table} WHERE job_id=%d GROUP BY status", $jobId));
            $add('Очередь #' . $jobId, true, implode(', ', array_map(function ($c) { return $c->status . ': ' . $c->c; }, (array)$counts)) . '; режим: ' . (strpos((string)$job->options_json, '"server":1') !== false ? 'сервер' : 'браузер'));
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->items_table} WHERE job_id=%d AND status IN ('queued','processing','failed') ORDER BY FIELD(status,'processing','queued','failed'), id ASC LIMIT 1", $jobId));
            if ($item && class_exists('ZAU_Certificate_PDF_Generator')) {
                $t = microtime(true);
                $options = json_decode((string)$job->options_json, true) ?: [];
                $payload = ZAU_Certificate_PDF_Generator::instance()->regeneration_payload((int)$item->document_id, (int)($options['target_template_id'] ?? 0), !empty($options['update_number']));
                $add('Данные документа #' . (int)$item->document_id . ' (' . $item->status . ')', !is_wp_error($payload), is_wp_error($payload) ? $payload->get_error_message() : 'шаблон «' . ($payload['template']['name'] ?? '') . '», подложка ' . ($payload['template']['background_url'] ?? ''), (int)round((microtime(true) - $t) * 1000));
                if (!is_wp_error($payload) && class_exists('ZAU_Server_Render')) {
                    foreach (ZAU_Server_Render::probe((array)$payload['template'], (array)$payload['values']) as $p) { $add($p['step'], $p['ok'], $p['info'], $p['ms']); }
                }
                if (!empty($item->error_text)) { $add('Ошибка этого документа в очереди', false, $item->error_text); }
            }
        }
        wp_send_json_success(['rows'=>$rows]);
    }

    /** Вызов со страницы очереди, пока она открыта: обработать порцию и вернуть состояние. */
    public function ajax_server_tick() {
        $this->require_ajax();
        $n = $this->process_server_jobs(15, 'page');
        $job = $this->get_job(absint($_POST['job_id'] ?? 0));
        if ($job) { $this->refresh_job_counts((int)$job->id); }
        $this->kick_background();
        wp_send_json_success(['processed_now'=>$n, 'locked'=>$n === -1 ? 1 : 0]);
    }

    /** Продолжение в фоне без открытой вкладки: сервер сам себе отправляет запрос (если хостинг это позволяет). */
    public function kick_background() {
        if (!get_transient('zau_cert_bulk_server_active')) { return; }
        if (!wp_next_scheduled(self::CRON_HOOK . '_now')) { wp_schedule_single_event(time(), self::CRON_HOOK . '_now'); }
        if (function_exists('spawn_cron')) { spawn_cron(); }
        $key = (string)get_option(self::CHAIN_OPT, '');
        if ($key === '') { $key = wp_generate_password(32, false, false); update_option(self::CHAIN_OPT, $key, false); }
        wp_remote_post(admin_url('admin-ajax.php'), ['timeout'=>0.01, 'blocking'=>false, 'sslverify'=>apply_filters('https_local_ssl_verify', false), 'body'=>['action'=>'zau_cert_bulk_bg', 'key'=>$key]]);
    }

    public function ajax_background_chain() {
        $key = (string)get_option(self::CHAIN_OPT, '');
        if ($key === '' || !hash_equals($key, (string)wp_unslash($_POST['key'] ?? ''))) { wp_die('', '', ['response'=>403]); }
        ignore_user_abort(true);
        $this->process_server_jobs(45, 'background');
        $this->kick_background();
        wp_die('ok');
    }

    /** Отметить, что появилась серверная работа, и сразу запустить обработку в фоне. */
    public function start_background() {
        set_transient('zau_cert_bulk_server_active', 1, DAY_IN_SECONDS);
        $this->ensure_cron();
        $this->kick_background();
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    public function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql1 = "CREATE TABLE {$this->jobs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'queued',
            filters_json longtext NULL,
            options_json longtext NULL,
            total_count bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
            success_count bigint(20) unsigned NOT NULL DEFAULT 0,
            failed_count bigint(20) unsigned NOT NULL DEFAULT 0,
            skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
            current_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_by (created_by),
            KEY created_at (created_at)
        ) $charset;";
        $sql2 = "CREATE TABLE {$this->items_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            document_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts int(11) unsigned NOT NULL DEFAULT 0,
            old_document_no varchar(100) NULL,
            new_document_no varchar(100) NULL,
            error_text text NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_document (job_id,document_id),
            KEY job_status (job_id,status),
            KEY document_id (document_id)
        ) $charset;";
        dbDelta($sql1);
        dbDelta($sql2);
    }

    public function admin_menu() {
        add_submenu_page(
            'zau-certificates',
            'Массовое пересоздание PDF',
            'Массовое пересоздание',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-cert-bulk-regenerate',
            [$this, 'page']
        );
    }

    private function require_manage() {
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)) {
            wp_die('Недостаточно прав.');
        }
    }

    private function require_ajax() {
        if (!is_user_logged_in() || !current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)) {
            wp_send_json_error(['message'=>'Недостаточно прав.'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    public function page() {
        $this->require_manage();
        global $wpdb;
        $templates = $wpdb->get_results("SELECT id,name,document_prefix,orientation FROM {$this->templates_table} ORDER BY name,id");
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->jobs_table} WHERE created_by=%d OR %d=1 ORDER BY id DESC LIMIT 25",
            get_current_user_id(),
            current_user_can('manage_options') ? 1 : 0
        ));
        $active_job_id = absint($_GET['job'] ?? 0);
        ?>
        <div class="wrap zau-wrap zau-bulk-wrap" id="zau-bulk-regenerate-page" data-active-job-id="<?php echo (int)$active_job_id; ?>">
            <div class="zau-page-head">
                <div>
                    <h1>Массовое пересоздание PDF</h1>
                    <p>Документы обрабатываются последовательно через безопасную очередь. Вкладку можно закрыть и позже продолжить тот же запуск.</p>
                </div>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-registry')); ?>">Открыть реестр</a>
            </div>

            <div class="notice notice-info inline"><p><strong>Перед массовым запуском:</strong> пересоздайте сначала 1–3 документа вручную, проверьте поля, подписи, фон, QR и префикс. Старый файл можно сохранять в архиве до успешной проверки нового.</p></div>

            <form id="zau-bulk-regenerate-form" class="zau-card zau-bulk-form">
                <h2>1. Выберите документы</h2>
                <div class="zau-bulk-grid">
                    <label>Поиск по ФИО, номеру или названию
                        <input type="search" name="search" placeholder="Например: Жакупов или AQN-2026">
                    </label>
                    <label>Организация
                        <input type="search" name="organization" placeholder="Часть названия организации">
                    </label>
                    <label>Текущий шаблон
                        <select name="template_id"><option value="0">Все шаблоны</option><?php foreach($templates as $tpl):?><option value="<?php echo (int)$tpl->id; ?>"><?php echo esc_html($tpl->name); ?></option><?php endforeach;?></select>
                    </label>
                    <label>Статус записи
                        <select name="record_status"><option value="">Все статусы</option><option value="active">Действующие</option><option value="draft">Черновики</option><option value="revoked">Отозванные</option></select>
                    </label>
                    <label>Состояние файла
                        <select name="file_state"><option value="all">Все</option><option value="missing">Только без PDF / с потерянным файлом</option><option value="exists">Только с существующим PDF</option></select>
                    </label>
                    <label>Источник
                        <select name="source"><option value="all">Все документы</option><option value="legacy">Только перенесённые старые PDF</option><option value="native">Только созданные новой системой</option><option value="exact">Пересозданные из заявлений старого сайта</option></select>
                    </label>
                    <label>Созданы от
                        <input type="date" name="date_from">
                    </label>
                    <label>Созданы до
                        <input type="date" name="date_to">
                    </label>
                    <label class="zau-bulk-wide">ID документов — необязательно
                        <input type="text" name="document_ids" placeholder="Например: 15, 18, 20-35. При заполнении остальные фильтры также применяются.">
                    </label>
                    <label>Максимум документов
                        <input type="number" name="limit" min="0" max="100000" value="0">
                        <small>0 — все найденные.</small>
                    </label>
                </div>

                <h2>2. Настройте пересоздание</h2>
                <div class="zau-bulk-grid">
                    <label>Шаблон для нового PDF
                        <select name="target_template_id"><option value="0">Оставить текущий шаблон каждого документа</option><?php foreach($templates as $tpl):?><option value="<?php echo (int)$tpl->id; ?>">Применить: <?php echo esc_html($tpl->name); ?></option><?php endforeach;?></select>
                        <small>Для старых PDF без шаблона обязательно выберите новый шаблон.</small>
                    </label>
                    <label>Задержка между документами, мс
                        <input type="number" name="delay_ms" min="0" max="10000" step="50" value="150">
                    </label>
                    <label>Повторных попыток при ошибке
                        <input type="number" name="max_retries" min="0" max="10" value="2">
                    </label>
                    <label class="zau-check-card"><input type="checkbox" name="update_number" value="1" checked> Применить актуальный префикс и формат номера выбранного шаблона</label>
                    <label class="zau-check-card"><input type="checkbox" name="keep_old_files" value="1" checked> Сохранять прежние PDF/JPG в архиве после успешного пересоздания</label>
                    <label class="zau-check-card"><input type="checkbox" name="skip_without_signature" value="1"> Пропускать записи без найденной подписи</label>
                    <?php $srvReason = ''; $srvOk = $this->server_available($srvReason); ?>
                    <label class="zau-check-card"><input type="checkbox" name="server" value="1" <?php checked($srvOk); disabled(!$srvOk); ?>> <strong>Формировать на сервере в фоне</strong> — вкладку можно закрыть, очередь продолжится сама<?php echo $srvOk ? '' : ' (недоступно: ' . esc_html($srvReason) . ')'; ?></label>
                </div>
                <div class="zau-bulk-actions">
                    <button type="button" class="button button-secondary button-large" data-zau-bulk-preview>Проверить выборку</button>
                    <button type="button" class="button button-primary button-large" data-zau-bulk-create>Создать очередь и начать</button>
                </div>
                <div data-zau-bulk-preview-result></div>
            </form>

            <section class="zau-card zau-bulk-runner" data-zau-bulk-runner <?php echo $active_job_id ? '' : 'hidden'; ?>>
                <div class="zau-section-head"><div><h2>Текущая очередь <span data-zau-bulk-job-label></span></h2><p data-zau-bulk-status-text>Загрузка состояния…</p></div><div class="zau-bulk-run-controls"><button type="button" class="button button-primary" data-zau-bulk-start>Продолжить</button><button type="button" class="button" data-zau-bulk-pause>Пауза</button><button type="button" class="button" data-zau-bulk-retry>Повторить ошибки</button><button type="button" class="button button-link-delete" data-zau-bulk-cancel>Отменить</button><button type="button" class="button" data-zau-probe>Проверить сервер</button></div></div>
                <div class="zau-bulk-progress"><span data-zau-bulk-progress-bar></span></div>
                <div class="zau-bulk-stats">
                    <div><strong data-zau-stat-total>0</strong><span>всего</span></div>
                    <div><strong data-zau-stat-processed>0</strong><span>обработано</span></div>
                    <div><strong data-zau-stat-success>0</strong><span>успешно</span></div>
                    <div><strong data-zau-stat-failed>0</strong><span>ошибок</span></div>
                    <div><strong data-zau-stat-skipped>0</strong><span>пропущено</span></div>
                </div>
                <div class="zau-bulk-current" data-zau-bulk-current></div>
                <div class="zau-render-holder" data-zau-bulk-render-holder></div>
                <div data-zau-bulk-result></div>
                <details><summary>Последние ошибки</summary><ol data-zau-bulk-errors></ol></details>
                <p><a class="button" data-zau-bulk-report href="#">Скачать отчёт CSV</a></p>
            </section>

            <div class="zau-card">
                <h2>Последние очереди</h2>
                <table class="widefat striped"><thead><tr><th>ID</th><th>Создана</th><th>Статус</th><th>Прогресс</th><th>Успешно</th><th>Ошибки</th><th></th></tr></thead><tbody>
                <?php if(!$jobs):?><tr><td colspan="7">Очередей ещё нет.</td></tr><?php endif;?>
                <?php foreach($jobs as $job):?>
                    <tr><td>#<?php echo (int)$job->id; ?></td><td><?php echo esc_html($job->created_at); ?></td><td><?php echo esc_html($this->status_label($job->status)); ?></td><td><?php echo (int)$job->processed_count; ?> / <?php echo (int)$job->total_count; ?></td><td><?php echo (int)$job->success_count; ?></td><td><?php echo (int)$job->failed_count; ?></td><td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-bulk-regenerate&job='.(int)$job->id)); ?>">Открыть</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_bulk_report&job='.(int)$job->id), self::NONCE)); ?>">CSV</a></td></tr>
                <?php endforeach;?></tbody></table>
            </div>
        </div>
        <?php
    }

    private function sanitize_filters($source) {
        $filters = [
            'search'=>sanitize_text_field(wp_unslash($source['search'] ?? '')),
            'organization'=>sanitize_text_field(wp_unslash($source['organization'] ?? '')),
            'template_id'=>absint($source['template_id'] ?? 0),
            'record_status'=>sanitize_key($source['record_status'] ?? ''),
            'file_state'=>sanitize_key($source['file_state'] ?? 'all'),
            'source'=>sanitize_key($source['source'] ?? 'all'),
            'date_from'=>sanitize_text_field($source['date_from'] ?? ''),
            'date_to'=>sanitize_text_field($source['date_to'] ?? ''),
            'document_ids'=>sanitize_text_field(wp_unslash($source['document_ids'] ?? '')),
            'limit'=>min(100000, max(0, absint($source['limit'] ?? 0))),
        ];
        if (!in_array($filters['record_status'], ['', 'active','draft','revoked'], true)) { $filters['record_status']=''; }
        if (!in_array($filters['file_state'], ['all','missing','exists'], true)) { $filters['file_state']='all'; }
        if (!in_array($filters['source'], ['all','legacy','native','exact'], true)) { $filters['source']='all'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) { $filters['date_from']=''; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) { $filters['date_to']=''; }
        return $filters;
    }

    private function sanitize_options($source) {
        return [
            'target_template_id'=>absint($source['target_template_id'] ?? 0),
            'update_number'=>empty($source['update_number']) ? 0 : 1,
            'keep_old_files'=>empty($source['keep_old_files']) ? 0 : 1,
            'skip_without_signature'=>empty($source['skip_without_signature']) ? 0 : 1,
            'delay_ms'=>min(10000, max(0, absint($source['delay_ms'] ?? 150))),
            'max_retries'=>min(10, max(0, absint($source['max_retries'] ?? 2))),
            'server'=>(!empty($source['server']) && $this->server_available()) ? 1 : 0,
        ];
    }

    private function parse_ids($raw) {
        $ids=[];
        foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $from=(int)$m[1]; $to=(int)$m[2];
                if ($from>$to) { [$from,$to]=[$to,$from]; }
                if (($to-$from)>5000) { $to=$from+5000; }
                for($i=$from;$i<=$to;$i++){ if($i>0)$ids[$i]=$i; }
            } else {
                $id=absint($part); if($id)$ids[$id]=$id;
            }
            if (count($ids)>=50000) { break; }
        }
        return array_values($ids);
    }

    private function build_selection_sql(array $filters, array $options, $count=false) {
        global $wpdb;
        $select = $count ? 'COUNT(*)' : 'd.id';
        $sql = "SELECT {$select} FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE 1=1";
        $args=[];
        if ($filters['search'] !== '') {
            $like='%'.$wpdb->esc_like($filters['search']).'%';
            $sql.=' AND (d.full_name LIKE %s OR d.document_no LIKE %s OR d.document_title LIKE %s OR t.name LIKE %s)';
            array_push($args,$like,$like,$like,$like);
        }
        if ($filters['organization'] !== '') {
            $sql.=' AND d.organization LIKE %s'; $args[]='%'.$wpdb->esc_like($filters['organization']).'%';
        }
        if ($filters['template_id']) { $sql.=' AND d.template_id=%d'; $args[]=$filters['template_id']; }
        if ($filters['record_status']) { $sql.=' AND d.record_status=%s'; $args[]=$filters['record_status']; }
        if ($filters['file_state']==='missing') { $sql.=" AND (d.pdf_url IS NULL OR d.pdf_url='')"; }
        elseif ($filters['file_state']==='exists') { $sql.=" AND d.pdf_url IS NOT NULL AND d.pdf_url<>''"; }
        if ($filters['source']==='legacy') { $sql.=' AND d.data_json LIKE %s'; $args[]='%\"legacy_import\":1%'; }
        elseif ($filters['source']==='exact') { $sql.=' AND d.data_json LIKE %s'; $args[]='%\"legacy_exact_regen\":1%'; }
        elseif ($filters['source']==='native') { $sql.=' AND (d.data_json IS NULL OR d.data_json NOT LIKE %s)'; $args[]='%\"legacy_import\":1%'; }
        if ($filters['date_from']) { $sql.=' AND d.created_at >= %s'; $args[]=$filters['date_from'].' 00:00:00'; }
        if ($filters['date_to']) { $sql.=' AND d.created_at <= %s'; $args[]=$filters['date_to'].' 23:59:59'; }
        $ids=$this->parse_ids($filters['document_ids']);
        if ($ids) { $sql.=' AND d.id IN ('.implode(',',array_map('intval',$ids)).')'; }
        if (empty($options['target_template_id'])) { $sql.=' AND d.template_id>0 AND t.id IS NOT NULL'; }
        if (!$count) {
            $sql.=' ORDER BY d.id ASC';
            if ($filters['limit']) { $sql.=' LIMIT '.(int)$filters['limit']; }
        }
        if ($args) { $sql=$wpdb->prepare($sql,$args); }
        return $sql;
    }

    public function ajax_preview() {
        $this->require_ajax();
        global $wpdb;
        $filters=$this->sanitize_filters($_POST);
        $options=$this->sanitize_options($_POST);
        $count=(int)$wpdb->get_var($this->build_selection_sql($filters,$options,true));
        if ($filters['limit'] && $count>$filters['limit']) { $count=$filters['limit']; }
        $sample_sql=$this->build_selection_sql(array_merge($filters,['limit'=>min(10,$filters['limit']?:10)]),$options,false);
        $ids=$wpdb->get_col($sample_sql);
        $sample=[];
        if($ids){
            $rows=$wpdb->get_results("SELECT d.id,d.full_name,d.document_no,d.organization,d.template_id,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.id IN (".implode(',',array_map('intval',$ids)).") ORDER BY d.id");
            foreach($rows as $row){$sample[]=['id'=>(int)$row->id,'full_name'=>$row->full_name,'document_no'=>$row->document_no,'organization'=>$row->organization,'template'=>$row->template_name?:'Без шаблона'];}
        }
        wp_send_json_success(['count'=>$count,'sample'=>$sample]);
    }

    public function ajax_create_job() {
        $this->require_ajax();
        global $wpdb;
        $filters=$this->sanitize_filters($_POST);
        $options=$this->sanitize_options($_POST);
        if ($options['target_template_id']) {
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->templates_table} WHERE id=%d",$options['target_template_id']));
            if(!$exists){wp_send_json_error(['message'=>'Выбранный новый шаблон не найден.'],404);}
        }
        $count=(int)$wpdb->get_var($this->build_selection_sql($filters,$options,true));
        if ($filters['limit'] && $count>$filters['limit']) { $count=$filters['limit']; }
        if(!$count){wp_send_json_error(['message'=>'По выбранным условиям документы не найдены.'],400);}
        if($count>100000){wp_send_json_error(['message'=>'За один запуск разрешено не более 100 000 документов. Уточните фильтры.'],400);}
        $now=current_time('mysql');
        $wpdb->insert($this->jobs_table,[
            'created_by'=>get_current_user_id(),'status'=>'queued','filters_json'=>wp_json_encode($filters,JSON_UNESCAPED_UNICODE),'options_json'=>wp_json_encode($options,JSON_UNESCAPED_UNICODE),'total_count'=>$count,'created_at'=>$now,'updated_at'=>$now
        ]);
        $job_id=(int)$wpdb->insert_id;
        if(!$job_id){wp_send_json_error(['message'=>'Не удалось создать очередь.'],500);}
        $ids=$wpdb->get_col($this->build_selection_sql($filters,$options,false));
        if(!$ids){$wpdb->delete($this->jobs_table,['id'=>$job_id]);wp_send_json_error(['message'=>'Не удалось получить список документов.'],500);}
        foreach(array_chunk(array_map('intval',$ids),500) as $chunk){
            $values=[];
            foreach($chunk as $document_id){$values[]=$wpdb->prepare('(%d,%d,%s,0,%s)',$job_id,$document_id,'queued',$now);}
            $wpdb->query("INSERT IGNORE INTO {$this->items_table} (job_id,document_id,status,attempts,updated_at) VALUES ".implode(',',$values));
        }
        $actual=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d",$job_id));
        $wpdb->update($this->jobs_table,['total_count'=>$actual,'status'=>'running','updated_at'=>$now],['id'=>$job_id]);
        if (!empty($options['server'])) { $this->start_background(); }
        wp_send_json_success(['job_id'=>$job_id,'total'=>$actual,'status'=>'running','options'=>$options]);
    }

    /** Очередь по готовому списку документов (для пересоздания перенесённых заявлений). */
    public function create_job_for_documents(array $ids, array $options = []) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) { return 0; }
        $options = $this->sanitize_options($options + ['delay_ms'=>150, 'max_retries'=>2]);
        $now = current_time('mysql');
        $wpdb->insert($this->jobs_table, ['created_by'=>get_current_user_id(), 'status'=>'queued', 'filters_json'=>wp_json_encode(['source'=>'exact_regen', 'document_ids'=>count($ids) . ' шт.'], JSON_UNESCAPED_UNICODE), 'options_json'=>wp_json_encode($options, JSON_UNESCAPED_UNICODE), 'total_count'=>count($ids), 'created_at'=>$now, 'updated_at'=>$now]);
        $jobId = (int)$wpdb->insert_id;
        if (!$jobId) { return 0; }
        foreach (array_chunk($ids, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $documentId) { $values[] = $wpdb->prepare('(%d,%d,%s,0,%s)', $jobId, $documentId, 'queued', $now); }
            $wpdb->query("INSERT IGNORE INTO {$this->items_table} (job_id,document_id,status,attempts,updated_at) VALUES " . implode(',', $values));
        }
        $wpdb->update($this->jobs_table, ['status'=>'running', 'total_count'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d", $jobId))], ['id'=>$jobId]);
        if (!empty($options['server'])) { $this->start_background(); }
        return $jobId;
    }

    private function get_job($job_id) {
        global $wpdb;
        $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE id=%d",$job_id));
        if(!$job)return null;
        if(!current_user_can('manage_options') && (int)$job->created_by!==get_current_user_id())return null;
        return $job;
    }

    private function refresh_job_counts($job_id) {
        global $wpdb;
        $counts=['queued'=>0,'processing'=>0,'success'=>0,'failed'=>0,'skipped'=>0];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) qty FROM {$this->items_table} WHERE job_id=%d GROUP BY status",$job_id));
        foreach($rows as $row){$counts[$row->status]=(int)$row->qty;}
        $processed=$counts['success']+$counts['failed']+$counts['skipped'];
        $job=$wpdb->get_row($wpdb->prepare("SELECT status,total_count FROM {$this->jobs_table} WHERE id=%d",$job_id));
        $status=$job?$job->status:'running';
        $completed_at=null;
        if($job && !in_array($status,['paused','canceled'],true) && ($counts['queued']+$counts['processing'])===0){$status='completed';$completed_at=current_time('mysql');}
        $update=['processed_count'=>$processed,'success_count'=>$counts['success'],'failed_count'=>$counts['failed'],'skipped_count'=>$counts['skipped'],'status'=>$status,'updated_at'=>current_time('mysql')];
        if($completed_at)$update['completed_at']=$completed_at;
        $wpdb->update($this->jobs_table,$update,['id'=>$job_id]);
        return array_merge($counts,['processed'=>$processed,'status'=>$status]);
    }

    public function ajax_next_item() {
        $this->require_ajax();
        global $wpdb;
        $job_id=absint($_POST['job_id']??0);
        $job=$this->get_job($job_id);
        if(!$job){wp_send_json_error(['message'=>'Очередь не найдена.'],404);}
        if(in_array($job->status,['paused','canceled','completed'],true)){wp_send_json_success(['done'=>$job->status==='completed','status'=>$job->status]);}
        $stale=wp_date('Y-m-d H:i:s',time()-900);
        $wpdb->query($wpdb->prepare("UPDATE {$this->items_table} SET status='queued',updated_at=%s WHERE job_id=%d AND status='processing' AND updated_at<%s",current_time('mysql'),$job_id,$stale));
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->items_table} WHERE job_id=%d AND status='queued' ORDER BY id ASC LIMIT 1",$job_id));
        if(!$item){$state=$this->refresh_job_counts($job_id);wp_send_json_success(['done'=>true,'status'=>$state['status']]);}
        $options=json_decode((string)$job->options_json,true)?:[];
        $doc=$wpdb->get_row($wpdb->prepare("SELECT id,full_name,document_no,organization,signature_url,data_json,source_submission_id FROM {$this->docs_table} WHERE id=%d",$item->document_id));
        if(!$doc){
            $wpdb->update($this->items_table,['status'=>'skipped','error_text'=>'Запись документа удалена.','completed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$item->id]);
            $this->refresh_job_counts($job_id);
            wp_send_json_success(['skip'=>true,'message'=>'Документ удалён, запись пропущена.']);
        }
        $values=json_decode((string)$doc->data_json,true)?:[];
        $signature=(string)($doc->signature_url?:($values['signature_url']??''));
        if ($signature==='' && (int)$doc->source_submission_id>0) {
            $submissions_table=$wpdb->prefix.'zau_union_submissions';
            $table_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$submissions_table));
            if($table_exists===$submissions_table){
                $signature_json=$wpdb->get_var($wpdb->prepare("SELECT signature_urls_json FROM {$submissions_table} WHERE id=%d",(int)$doc->source_submission_id));
                $signature_urls=json_decode((string)$signature_json,true);
                if(is_array($signature_urls)&&!empty($signature_urls[0]))$signature=(string)$signature_urls[0];
            }
        }
        if(!empty($options['skip_without_signature']) && $signature===''){
            $wpdb->update($this->items_table,['status'=>'skipped','error_text'=>'Подпись отсутствует.','old_document_no'=>$doc->document_no,'completed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$item->id]);
            $this->refresh_job_counts($job_id);
            wp_send_json_success(['skip'=>true,'message'=>'Подпись отсутствует, документ пропущен.']);
        }
        $now=current_time('mysql');
        $wpdb->update($this->items_table,['status'=>'processing','attempts'=>(int)$item->attempts+1,'old_document_no'=>$doc->document_no,'started_at'=>$now,'updated_at'=>$now],['id'=>$item->id]);
        $wpdb->update($this->jobs_table,['status'=>'running','current_document_id'=>(int)$doc->id,'updated_at'=>$now],['id'=>$job_id]);
        wp_send_json_success([
            'done'=>false,'job_id'=>$job_id,'item_id'=>(int)$item->id,'document_id'=>(int)$doc->id,'full_name'=>$doc->full_name,'document_no'=>$doc->document_no,'organization'=>$doc->organization,
            'target_template_id'=>(int)($options['target_template_id']??0),'update_number'=>!empty($options['update_number'])?1:0,'keep_old_files'=>!empty($options['keep_old_files'])?1:0,'delay_ms'=>(int)($options['delay_ms']??150),'attempt'=>(int)$item->attempts+1
        ]);
    }

    public function ajax_mark_item() {
        $this->require_ajax();
        global $wpdb;
        $job_id=absint($_POST['job_id']??0);$item_id=absint($_POST['item_id']??0);
        $job=$this->get_job($job_id);if(!$job){wp_send_json_error(['message'=>'Очередь не найдена.'],404);}
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->items_table} WHERE id=%d AND job_id=%d",$item_id,$job_id));
        if(!$item){wp_send_json_error(['message'=>'Элемент очереди не найден.'],404);}
        $success=!empty($_POST['success']);$now=current_time('mysql');$options=json_decode((string)$job->options_json,true)?:[];
        if($success){
            $wpdb->update($this->items_table,['status'=>'success','new_document_no'=>sanitize_text_field(wp_unslash($_POST['document_no']??'')),'error_text'=>'','completed_at'=>$now,'updated_at'=>$now],['id'=>$item_id]);
        } else {
            $error=sanitize_textarea_field(wp_unslash($_POST['error']??'Неизвестная ошибка')); $error=function_exists('mb_substr')?mb_substr($error,0,4000):substr($error,0,4000);
            $max_retries=(int)($options['max_retries']??2);
            $next_status=((int)$item->attempts <= $max_retries)?'queued':'failed';
            $wpdb->update($this->items_table,['status'=>$next_status,'error_text'=>$error,'completed_at'=>$next_status==='failed'?$now:null,'updated_at'=>$now],['id'=>$item_id]);
        }
        $state=$this->refresh_job_counts($job_id);
        wp_send_json_success(['status'=>$state['status'],'processed'=>$state['processed'],'success'=>$state['success'],'failed'=>$state['failed'],'queued'=>$state['queued']]);
    }

    public function ajax_job_status() {
        $this->require_ajax();
        global $wpdb;
        $job_id=absint($_POST['job_id']??0);$job=$this->get_job($job_id);if(!$job){wp_send_json_error(['message'=>'Очередь не найдена.'],404);}
        $this->refresh_job_counts($job_id);
        $job=$this->get_job($job_id);
        $errors=$wpdb->get_results($wpdb->prepare("SELECT i.document_id,i.error_text,i.attempts,d.full_name,d.document_no FROM {$this->items_table} i LEFT JOIN {$this->docs_table} d ON d.id=i.document_id WHERE i.job_id=%d AND i.status IN ('failed','skipped') ORDER BY i.id DESC LIMIT 20",$job_id));
        $options=json_decode((string)$job->options_json,true)?:[];
        wp_send_json_success([
            'job_id'=>(int)$job->id,'status'=>$job->status,'status_label'=>$this->status_label($job->status),'total'=>(int)$job->total_count,'processed'=>(int)$job->processed_count,'success'=>(int)$job->success_count,'failed'=>(int)$job->failed_count,'skipped'=>(int)$job->skipped_count,'current_document_id'=>(int)$job->current_document_id,'options'=>$options,
            'errors'=>array_map(function($row){return ['document_id'=>(int)$row->document_id,'full_name'=>$row->full_name,'document_no'=>$row->document_no,'attempts'=>(int)$row->attempts,'error'=>$row->error_text];},$errors),
            'report_url'=>wp_nonce_url(admin_url('admin-post.php?action=zau_cert_bulk_report&job='.(int)$job->id),self::NONCE),
            'server_info'=>$this->server_info_text()
        ]);
    }

    public function ajax_control() {
        $this->require_ajax();
        global $wpdb;
        $job_id=absint($_POST['job_id']??0);$command=sanitize_key($_POST['command']??'');$job=$this->get_job($job_id);if(!$job){wp_send_json_error(['message'=>'Очередь не найдена.'],404);}
        $now=current_time('mysql');
        if($command==='pause'){$wpdb->update($this->jobs_table,['status'=>'paused','updated_at'=>$now],['id'=>$job_id]);}
        elseif($command==='resume'){$wpdb->update($this->jobs_table,['status'=>'running','completed_at'=>null,'updated_at'=>$now],['id'=>$job_id]);$jobRow=$this->get_job($job_id);if($jobRow&&strpos((string)$jobRow->options_json,'"server":1')!==false)$this->start_background();}
        elseif($command==='to_browser'||$command==='to_server'){$jobRow=$this->get_job($job_id);$opts=json_decode((string)$jobRow->options_json,true)?:[];$opts['server']=$command==='to_server'&&$this->server_available()?1:0;$wpdb->update($this->jobs_table,['options_json'=>wp_json_encode($opts,JSON_UNESCAPED_UNICODE),'updated_at'=>$now],['id'=>$job_id]);$wpdb->query($wpdb->prepare("UPDATE {$this->items_table} SET status='queued',updated_at=%s WHERE job_id=%d AND status='processing'",$now,$job_id));if($opts['server'])$this->start_background();}
        elseif($command==='cancel'){$wpdb->update($this->jobs_table,['status'=>'canceled','updated_at'=>$now,'completed_at'=>$now],['id'=>$job_id]);}
        elseif($command==='retry_failed'){
            $wpdb->query($wpdb->prepare("UPDATE {$this->items_table} SET status='queued',error_text='',completed_at=NULL,updated_at=%s WHERE job_id=%d AND status='failed'",$now,$job_id));
            $wpdb->update($this->jobs_table,['status'=>'running','completed_at'=>null,'updated_at'=>$now],['id'=>$job_id]);
            $jobRow=$this->get_job($job_id);if($jobRow&&strpos((string)$jobRow->options_json,'"server":1')!==false)$this->start_background();
        } else {wp_send_json_error(['message'=>'Неизвестная команда.'],400);}
        $this->refresh_job_counts($job_id);
        wp_send_json_success(['status'=>$this->get_job($job_id)->status]);
    }

    public function download_report() {
        $this->require_manage();
        check_admin_referer(self::NONCE);
        global $wpdb;
        $job_id=absint($_GET['job']??0);$job=$this->get_job($job_id);if(!$job){wp_die('Очередь не найдена.');}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT i.*,d.full_name,d.organization,d.document_title,t.name template_name FROM {$this->items_table} i LEFT JOIN {$this->docs_table} d ON d.id=i.document_id LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE i.job_id=%d ORDER BY i.id",$job_id));
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-bulk-regeneration-job-'.$job_id.'.csv"');
        echo "\xEF\xBB\xBF";
        $out=fopen('php://output','w');
        zau_fputcsv($out,['Job','Document ID','ФИО','Организация','Шаблон','Старый номер','Новый номер','Статус','Попыток','Ошибка','Начато','Завершено'],';');
        foreach($rows as $row){zau_fputcsv($out,[$job_id,$row->document_id,$row->full_name,$row->organization,$row->template_name?:$row->document_title,$row->old_document_no,$row->new_document_no,$this->item_status_label($row->status),$row->attempts,$row->error_text,$row->started_at,$row->completed_at],';');}
        fclose($out);exit;
    }

    private function status_label($status) {
        return ['queued'=>'В очереди','running'=>'Выполняется','paused'=>'На паузе','completed'=>'Завершена','canceled'=>'Отменена'][$status]??$status;
    }
    private function item_status_label($status) {
        return ['queued'=>'В очереди','processing'=>'Обрабатывается','success'=>'Успешно','failed'=>'Ошибка','skipped'=>'Пропущено'][$status]??$status;
    }
}
