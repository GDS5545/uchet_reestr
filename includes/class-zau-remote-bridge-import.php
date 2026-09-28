<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Protected old-site bridge importer with persistent staging, safe person
 * matching, latest-application selection and an audit archive.
 */
final class ZAU_Remote_Bridge_Import {
    const VERSION = '2.18.2';
    const OPT = 'zau_remote_bridge_settings';
    const JOB_OPT = 'zau_remote_bridge_job';
    const PDF_JOB_OPT = 'zau_remote_bridge_pdf_job';
    const NONCE = 'zau_remote_bridge_nonce';
    const LOCK = 'zau_remote_bridge_process_lock'; // legacy transient from 2.17.0
    const LOCK_OPT = 'zau_remote_bridge_process_lock_v2';
    const LOCK_TTL = 180;

    private static $instance = null;
    private $map_table;
    private $submissions_table;
    private $docs_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->map_table = $wpdb->prefix . 'zau_external_import_map';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';

        add_action('admin_menu', [$this, 'admin_menu'], 39);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_zau_remote_bridge_save', [$this, 'save_settings']);
        add_action('admin_post_zau_remote_bridge_report', [$this, 'download_report']);
        add_action('admin_post_zau_remote_bridge_pdf_report', [$this, 'download_pdf_report']);

        add_action('wp_ajax_zau_remote_bridge_test', [$this, 'ajax_test']);
        add_action('wp_ajax_zau_remote_bridge_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_remote_bridge_process', [$this, 'ajax_process']);
        add_action('wp_ajax_zau_remote_bridge_status', [$this, 'ajax_status']);
        add_action('wp_ajax_zau_remote_bridge_reset', [$this, 'ajax_reset']);
        add_action('wp_ajax_zau_remote_bridge_pdf_start', [$this, 'ajax_pdf_start']);
        add_action('wp_ajax_zau_remote_bridge_pdf_process', [$this, 'ajax_pdf_process']);
        add_action('wp_ajax_zau_remote_bridge_pdf_reset', [$this, 'ajax_pdf_reset']);

        // 2.17.0 used a transient and could leave it set because wp_send_json_* exits
        // before a PHP finally block is executed. The new lock is an atomic option.
        if (get_option('zau_remote_bridge_lock_format') !== '2') {
            delete_transient(self::LOCK);
            delete_option(self::LOCK_OPT);
            update_option('zau_remote_bridge_lock_format', '2', false);
        }
    }

    private function can_manage() {
        return current_user_can('manage_options') || (class_exists('ZAU_Certificate_PDF_Generator') && current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE));
    }

    private function require_access() {
        if (!$this->can_manage()) { wp_send_json_error(['message'=>'Недостаточно прав.'], 403); }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT, []), [
            'old_url'=>'',
            'secret'=>'',
            'batch_size'=>50,
            'default_status'=>'Состоит в профсоюзе',
            'create_users'=>1,
            'update_existing'=>1,
            'overwrite'=>0,
            'preserve_password'=>0,
            'pdf_folder'=>'legacy-user-pdfs',
            'application_policy'=>'latest_only_archive',
            'weak_identity_action'=>'review_skip',
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            null,
            'Перенос со старого сайта',
            'Перенос старого сайта',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-remote-bridge-import',
            [$this, 'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-remote-bridge-import') === false) { return; }
        wp_enqueue_style('zau-remote-bridge', plugins_url('../assets/css/remote-bridge.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('zau-remote-bridge', plugins_url('../assets/js/remote-bridge.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('zau-remote-bridge', 'ZAURemoteBridge', [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
        ]);
    }

    public function save_settings() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $oldUrl = esc_url_raw(trim((string)wp_unslash($_POST['old_url'] ?? '')));
        if ($oldUrl) { $oldUrl = untrailingslashit($oldUrl); }
        $folder = trim((string)wp_unslash($_POST['pdf_folder'] ?? 'legacy-user-pdfs'));
        $folder = trim(str_replace(['..','\\'], ['', '/'], $folder), '/');
        $policy = sanitize_key((string)wp_unslash($_POST['application_policy'] ?? 'latest_only_archive'));
        if (!in_array($policy,['latest_only_archive','all_unique'],true)) { $policy='latest_only_archive'; }
        $weak = sanitize_key((string)wp_unslash($_POST['weak_identity_action'] ?? 'review_skip'));
        if (!in_array($weak,['review_skip','merge_name','create_separate'],true)) { $weak='review_skip'; }
        $settings = [
            'old_url'=>$oldUrl,
            'secret'=>sanitize_text_field(wp_unslash($_POST['secret'] ?? '')),
            'batch_size'=>max(10, min(200, absint($_POST['batch_size'] ?? 50))),
            'default_status'=>sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'Состоит в профсоюзе')),
            'create_users'=>!empty($_POST['create_users']) ? 1 : 0,
            'update_existing'=>!empty($_POST['update_existing']) ? 1 : 0,
            'overwrite'=>!empty($_POST['overwrite']) ? 1 : 0,
            'preserve_password'=>!empty($_POST['preserve_password']) ? 1 : 0,
            'pdf_folder'=>sanitize_text_field($folder ?: 'legacy-user-pdfs'),
            'application_policy'=>$policy,
            'weak_identity_action'=>$weak,
        ];
        update_option(self::OPT, $settings, false);
        wp_safe_redirect(add_query_arg(['page'=>'zau-remote-bridge-import','saved'=>'1'], admin_url('admin.php')));
        exit;
    }

    private function canonical_query(array $query) {
        ksort($query);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function remote_get($route, array $query = []) {
        $s = $this->settings();
        if (empty($s['old_url']) || empty($s['secret'])) {
            return new WP_Error('zau_remote_settings', 'Укажите адрес старого сайта и секретный ключ.');
        }
        $route = '/' . ltrim($route, '/');
        $restRoute = '/zau-legacy/v1' . $route;
        $queryString = $this->canonical_query($query);
        $url = untrailingslashit($s['old_url']) . '/wp-json' . $restRoute;
        if ($queryString !== '') { $url .= '?' . $queryString; }
        $timestamp = (string)time();
        $payload = $timestamp . "\nGET\n" . $restRoute . "\n" . $queryString;
        $signature = hash_hmac('sha256', $payload, (string)$s['secret']);
        $response = wp_remote_get($url, [
            'timeout'=>60,
            'redirection'=>2,
            'sslverify'=>true,
            'headers'=>[
                'X-ZAU-Timestamp'=>$timestamp,
                'X-ZAU-Signature'=>$signature,
                'Accept'=>'application/json',
            ],
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['message']) ? $json['message'] : ('Старый сайт вернул HTTP ' . $code);
            return new WP_Error('zau_remote_http', $message, ['status'=>$code,'body'=>substr($body,0,1000)]);
        }
        if (!is_array($json)) { return new WP_Error('zau_remote_json', 'Старый сайт вернул некорректный JSON.'); }
        return $json;
    }

    public function ajax_test() {
        $this->require_access();
        $result = $this->remote_get('/status');
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], 400); }
        $bridgeVersion = (string)($result['bridge_version'] ?? '0');
        if (version_compare($bridgeVersion,'1.1.0','<')) {
            $result['warning'] = 'На старом сайте установлен мост ' . ($bridgeVersion ?: 'неизвестной версии') . '. Обновите его минимум до 1.1.0: старая версия могла принять ФИО председателя или бухгалтера за ФИО заявителя.';
        } elseif (version_compare($bridgeVersion,'1.2.0','<')) {
            $result['warning'] = 'Подключение работает, но для более точного восстановления организаций и филиалов рекомендуется обновить мост старого сайта до 1.2.0. Новая версия читает названия полей из структуры WPForms, даже когда в записях сохранены только ID полей.';
        }
        wp_send_json_success($result);
    }

    public function ajax_start() {
        $this->require_access();
        if (!class_exists('ZAU_Remote_Smart_Dedup') || !class_exists('ZAU_Universal_Import')) {
            wp_send_json_error(['message'=>'Модуль безопасного анализа не найден.'],500);
        }
        $status = $this->remote_get('/status');
        if (is_wp_error($status)) { wp_send_json_error(['message'=>$status->get_error_message()], 400); }
        $bridgeVersion = (string)($status['bridge_version'] ?? '0');
        if (version_compare($bridgeVersion,'1.1.0','<')) {
            wp_send_json_error(['message'=>'Сначала обновите плагин-мост на старом сайте до версии 1.1.0. Старая версия недостаточно точно различает ФИО заявителя и служебные ФИО в форме.'],409);
        }
        $oldJob = (array)get_option(self::JOB_OPT, []);
        if (!empty($oldJob['token']) && (!empty($oldJob['dry_run']) || ($oldJob['status'] ?? '') !== 'finished')) {
            ZAU_Remote_Smart_Dedup::instance()->clear_job((string)$oldJob['token']);
        }
        $s = $this->settings();
        $dry = !empty($_POST['dry_run']);
        $job = [
            'token'=>'remote-' . wp_generate_uuid4(),
            'status'=>'running',
            'phase'=>'scan_users',
            'cursor'=>0,
            'dry_run'=>$dry ? 1 : 0,
            'totals'=>[
                'users'=>(int)($status['user_count'] ?? 0),
                'applications'=>(int)($status['application_count'] ?? 0),
                'people'=>0,
                'imports'=>0,
            ],
            'processed'=>['users'=>0,'applications'=>0,'people'=>0,'imports'=>0],
            'stats'=>[
                'staged_users'=>0,'staged_applications'=>0,'unique_people'=>0,
                'created'=>0,'updated'=>0,'existing'=>0,'would_create'=>0,'would_update'=>0,
                'submissions'=>0,'updated_submissions'=>0,'would_submit'=>0,'would_update_submission'=>0,'current'=>0,'archived'=>0,
                'exact_duplicates'=>0,'already_imported'=>0,'review_required'=>0,
                'identity_conflicts'=>0,'skipped'=>0,'errors'=>0,
            ],
            'options'=>[
                'create_users'=>!empty($s['create_users']) ? 1 : 0,
                'update_existing'=>!empty($s['update_existing']) ? 1 : 0,
                'overwrite'=>!empty($s['overwrite']) ? 1 : 0,
                'default_status'=>(string)$s['default_status'],
                'preserve_password'=>!empty($s['preserve_password']) ? 1 : 0,
                'batch_size'=>(int)$s['batch_size'],
                'source_site'=>(string)$s['old_url'],
                'application_policy'=>(string)$s['application_policy'],
                'weak_identity_action'=>(string)$s['weak_identity_action'],
            ],
            'log'=>[
                'Подключение установлено.',
                'Этап 1: данные сначала копируются в безопасную промежуточную таблицу. Пользователи и заявления пока не создаются.',
            ],
            'started_at'=>current_time('mysql'),
            'finished_at'=>'',
        ];
        update_option(self::JOB_OPT, $job, false);
        wp_send_json_success($this->public_job($job));
    }

    private function add_stats(&$job,array $stats) {
        foreach ($stats as $key=>$value) {
            if (!is_numeric($value)) { continue; }
            $dest = $key === 'error' ? 'errors' : ($key === 'updated_submission' ? 'updated_submissions' : $key);
            if (!isset($job['stats'][$dest])) { $job['stats'][$dest]=0; }
            $job['stats'][$dest]+=(int)$value;
        }
    }

    private function lock_state() {
        $lock = get_option(self::LOCK_OPT, []);
        return is_array($lock) ? $lock : [];
    }

    private function clear_stale_lock() {
        $lock = $this->lock_state();
        if (!$lock) { return false; }
        $started = isset($lock['started_at']) ? (int)$lock['started_at'] : 0;
        if (!$started || (time() - $started) > self::LOCK_TTL) {
            delete_option(self::LOCK_OPT);
            return true;
        }
        return false;
    }

    private function acquire_lock() {
        $this->clear_stale_lock();
        $job = (array)get_option(self::JOB_OPT, []);
        $token = wp_generate_uuid4();
        $payload = [
            'token'=>$token,
            'started_at'=>time(),
            'job_token'=>(string)($job['token'] ?? ''),
            'user_id'=>get_current_user_id(),
        ];
        if (add_option(self::LOCK_OPT, $payload, '', false)) { return $token; }
        // One more stale check handles a race with a request that just expired.
        if ($this->clear_stale_lock() && add_option(self::LOCK_OPT, $payload, '', false)) { return $token; }
        $lock = $this->lock_state();
        $age = !empty($lock['started_at']) ? max(0, time() - (int)$lock['started_at']) : 0;
        return new WP_Error('zau_remote_busy', 'Предыдущая партия ещё обрабатывается.', [
            'retry_after'=>max(2, min(10, self::LOCK_TTL - $age)),
            'lock_age'=>$age,
        ]);
    }

    private function release_lock($token) {
        $lock = $this->lock_state();
        if (!$lock || empty($lock['token']) || hash_equals((string)$lock['token'], (string)$token)) {
            delete_option(self::LOCK_OPT);
        }
        delete_transient(self::LOCK);
    }

    public function ajax_status() {
        $this->require_access();
        $staleCleared = $this->clear_stale_lock();
        $job = (array)get_option(self::JOB_OPT, []);
        $lock = $this->lock_state();
        wp_send_json_success([
            'job'=>$job ? $this->public_job($job) : null,
            'locked'=>!empty($lock) ? 1 : 0,
            'lock_age'=>!empty($lock['started_at']) ? max(0, time() - (int)$lock['started_at']) : 0,
            'stale_cleared'=>$staleCleared ? 1 : 0,
        ]);
    }

    public function ajax_process() {
        $this->require_access();
        $lockToken = $this->acquire_lock();
        if (is_wp_error($lockToken)) {
            $data = $lockToken->get_error_data();
            $job = (array)get_option(self::JOB_OPT, []);
            wp_send_json_error([
                'message'=>$lockToken->get_error_message() . ' Повтор выполняется автоматически.',
                'retry_after'=>(int)($data['retry_after'] ?? 2),
                'lock_age'=>(int)($data['lock_age'] ?? 0),
                'job'=>$job ? $this->public_job($job) : null,
            ],409);
        }
            $job = (array)get_option(self::JOB_OPT, []);
            if (!$job || ($job['status'] ?? '') !== 'running') {
                $this->release_lock($lockToken);
                wp_send_json_error(['message'=>'Активное задание не найдено.'], 404);
            }
            $jobToken = (string)($job['token'] ?? '');
            $job['last_activity_at'] = current_time('mysql');
            $smart = ZAU_Remote_Smart_Dedup::instance();
            $phase = (string)$job['phase'];

            if ($phase === 'scan_users' || $phase === 'scan_applications') {
                $isApps = $phase === 'scan_applications';
                $route = $isApps ? '/applications' : '/users';
                $query = ['cursor'=>(int)$job['cursor'],'per_page'=>(int)$job['options']['batch_size']];
                $payload = $this->remote_get($route,$query);
                if (is_wp_error($payload)) {
                    $job['log'][]='Ошибка соединения: '.$payload->get_error_message();
                    $currentJob = (array)get_option(self::JOB_OPT, []);
                    if ((string)($currentJob['token'] ?? '') === $jobToken) { update_option(self::JOB_OPT,$job,false); }
                    $this->release_lock($lockToken);
                    wp_send_json_error(['message'=>$payload->get_error_message(),'job'=>$this->public_job($job)],502);
                }
                foreach ((array)($payload['items'] ?? []) as $record) {
                    $result=$smart->stage_record((string)$job['token'],(string)$job['options']['source_site'],(array)$record,$job['options']);
                    if (is_wp_error($result)) { $job['stats']['errors']++; $job['log'][]=$result->get_error_message(); }
                    else {
                        $statKey=$isApps?'staged_applications':'staged_users';
                        $job['stats'][$statKey]++;
                        if (($result['decision'] ?? '')==='already_imported') { $job['stats']['already_imported']++; }
                    }
                    $job['processed'][$isApps?'applications':'users']++;
                }
                $job['cursor']=(int)($payload['next_cursor'] ?? $job['cursor']);
                if (empty($payload['has_more'])) {
                    if (!$isApps) {
                        $job['phase']='scan_applications'; $job['cursor']=0;
                        $job['log'][]='Аккаунты считаны. Этап 2: считываются заявления WPForms.';
                    } else {
                        $prepared=$smart->prepare_people((string)$job['token']);
                        $job['totals']['people']=(int)$prepared['people'];
                        $job['stats']['unique_people']=(int)$prepared['people'];
                        $job['stats']['review_required']=(int)$prepared['review'];
                        $job['stats']['already_imported']=(int)$prepared['already_imported'];
                        $job['phase']='people'; $job['cursor']=0;
                        $job['log'][]='Сканирование завершено. Найдено предполагаемых людей: '.(int)$prepared['people'].'.';
                        $job['log'][]='Этап 3: объединение дублей и выбор самых новых данных по дате.';
                    }
                }
            } elseif ($phase === 'people') {
                $result=$smart->process_people_batch((string)$job['token'],(int)$job['cursor'],max(5,min(30,(int)$job['options']['batch_size'])) ,array_merge($job['options'],['dry_run'=>!empty($job['dry_run'])?1:0]));
                $job['cursor']=(int)$result['next_cursor'];
                $job['processed']['people']+=(int)$result['handled'];
                $this->add_stats($job,(array)$result['stats']);
                if (!empty($result['done'])) {
                    $job['totals']['imports']=$smart->application_total((string)$job['token'],(string)$job['options']['application_policy']);
                    $job['phase']='imports'; $job['cursor']=0;
                    $job['log'][]='Люди сгруппированы. Этап 4: переносится только последняя уникальная заявка каждого вида.';
                    if (!empty($job['dry_run'])) { $job['log'][]='Пробный режим: база пользователей и заявлений не изменяется.'; }
                }
            } elseif ($phase === 'imports') {
                $result=$smart->process_applications_batch((string)$job['token'],(int)$job['cursor'],(int)$job['options']['batch_size'],array_merge($job['options'],['dry_run'=>!empty($job['dry_run'])?1:0]));
                $job['cursor']=(int)$result['next_cursor'];
                $job['processed']['imports']+=(int)$result['handled'];
                foreach ((array)$result['stats'] as $key=>$value) {
                    if ($key==='created' || $key==='updated' || $key==='existing' || $key==='duplicate' || $key==='would_create' || $key==='would_update' || $key==='would_submit' || $key==='would_update_submission' || $key==='submissions' || $key==='updated_submission' || $key==='skipped' || $key==='error') {
                        $dest=$key==='error'?'errors':($key==='updated_submission'?'updated_submissions':$key);
                        if (!isset($job['stats'][$dest])) $job['stats'][$dest]=0;
                        $job['stats'][$dest]+=(int)$value;
                    }
                }
                if (!empty($result['done'])) {
                    $job['status']='finished'; $job['phase']='finished'; $job['finished_at']=current_time('mysql');
                    $counts=$smart->job_counts((string)$job['token']);
                    foreach (['current','archived','exact_duplicate','already_imported'] as $key) {
                        $dest=$key==='exact_duplicate'?'exact_duplicates':$key;
                        if (isset($counts[$key])) $job['stats'][$dest]=(int)$counts[$key];
                    }
                    $job['stats']['review_required']=(int)($counts['review_people'] ?? 0);
                    $job['stats']['identity_conflicts']=(int)($counts['conflict_people'] ?? 0);
                    $job['log'][]='Анализ и перенос завершены. Старые версии не удалены: они сохранены в архиве миграции.';
                }
            }
            if (count($job['log'])>150) $job['log']=array_slice($job['log'],-150);
            $currentJob = (array)get_option(self::JOB_OPT, []);
            if ((string)($currentJob['token'] ?? '') !== $jobToken) {
                $this->release_lock($lockToken);
                wp_send_json_error(['message'=>'Задание было сброшено или заменено в другой вкладке.'],409);
            }
            update_option(self::JOB_OPT,$job,false);
            $publicJob = $this->public_job($job);
            $this->release_lock($lockToken);
            wp_send_json_success($publicJob);
    }

    private function public_job($job) {
        return [
            'status'=>$job['status'] ?? 'idle',
            'phase'=>$job['phase'] ?? '',
            'dry_run'=>!empty($job['dry_run']) ? 1 : 0,
            'totals'=>$job['totals'] ?? ['users'=>0,'applications'=>0,'people'=>0,'imports'=>0],
            'processed'=>$job['processed'] ?? ['users'=>0,'applications'=>0,'people'=>0,'imports'=>0],
            'stats'=>$job['stats'] ?? [],
            'log'=>array_slice((array)($job['log'] ?? []),-30),
            'finished_at'=>$job['finished_at'] ?? '',
            'last_activity_at'=>$job['last_activity_at'] ?? '',
        ];
    }

    public function ajax_reset() {
        $this->require_access();
        $job=(array)get_option(self::JOB_OPT,[]);
        if (!empty($job['token']) && (!empty($job['dry_run']) || ($job['status'] ?? '')!=='finished')) {
            ZAU_Remote_Smart_Dedup::instance()->clear_job((string)$job['token']);
        }
        delete_option(self::JOB_OPT);
        delete_option(self::LOCK_OPT);
        delete_transient(self::LOCK);
        wp_send_json_success(['message'=>'Прогресс сброшен. Завершённый архив настоящего переноса и уже созданные данные не удалены.']);
    }

    private function mask_email($email) {
        $email=(string)$email;
        if (!is_email($email)) return $email;
        list($local,$domain)=explode('@',$email,2);
        return substr($local,0,1).'***@'.$domain;
    }

    private function mask_phone($phone) {
        $digits=preg_replace('/\D+/','',(string)$phone);
        return strlen($digits)>=4 ? '*******'.substr($digits,-4) : '';
    }

    private function mask_iin($iin) {
        $digits=preg_replace('/\D+/','',(string)$iin);
        return strlen($digits)===12 ? '********'.substr($digits,-4) : '';
    }

    public function download_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.',403); }
        check_admin_referer(self::NONCE);
        $job=(array)get_option(self::JOB_OPT,[]);
        $token=(string)($job['token'] ?? '');
        if (!$token) wp_die('Отчёт не найден.');
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-smart-import-report-'.wp_date('Y-m-d-H-i').'.csv"');
        echo "\xEF\xBB\xBF";
        $out=fopen('php://output','w');
        fputcsv($out,['Тип','Старый User ID','Старый Entry ID','Форма','Дата','Решение','Причина','Сила совпадения','ФИО','ИИН','Email','Телефон','Новый User ID','Новая заявка ID'],';');
        $offset=0;
        do {
            $rows=ZAU_Remote_Smart_Dedup::instance()->report_rows($token,$offset,1000);
            foreach ($rows as $row) {
                $canonical=json_decode((string)$row['canonical_json'],true);
                if (!is_array($canonical)) $canonical=[];
                fputcsv($out,[
                    $row['record_type'],$row['legacy_user_id'],$row['legacy_entry_id'],$row['form_title'],$row['record_at'],
                    $row['decision'],$row['message'] ?: $row['person_message'],$row['identity_strength'],
                    $canonical['full_name'] ?? '',$this->mask_iin($canonical['iin'] ?? ''),$this->mask_email($canonical['email'] ?? ''),$this->mask_phone($canonical['phone'] ?? ''),
                    $row['target_user_id'],$row['target_submission_id']
                ],';');
            }
            $offset+=count($rows);
        } while (count($rows)===1000);
        fclose($out); exit;
    }

    private function private_dir() {
        $dir = WP_CONTENT_DIR . '/zau-private-imports';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        if (is_dir($dir) && !is_file($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n"); }
        return $dir;
    }

    public function ajax_pdf_start() {
        $this->require_access();
        $s = $this->settings();
        $up = wp_upload_dir();
        $folder = trim((string)$s['pdf_folder'], '/');
        $root = wp_normalize_path(trailingslashit($up['basedir']) . $folder);
        if (!$folder || !is_dir($root)) {
            wp_send_json_error(['message'=>'Каталог PDF не найден: ' . $root], 404);
        }
        $manifest = trailingslashit($this->private_dir()) . 'pdf-manifest-' . get_current_user_id() . '-' . time() . '.txt';
        $fh = fopen($manifest, 'wb');
        if (!$fh) { wp_send_json_error(['message'=>'Не удалось создать служебный список PDF.'], 500); }
        $total = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'pdf') { continue; }
                $path = wp_normalize_path($file->getPathname());
                fwrite($fh, $path . "\n");
                $total++;
            }
        } catch (Exception $e) {
            fclose($fh); @unlink($manifest);
            wp_send_json_error(['message'=>'Ошибка сканирования: ' . $e->getMessage()], 500);
        }
        fclose($fh);
        $job = [
            'status'=>'running','manifest'=>$manifest,'offset'=>0,'processed'=>0,'total'=>$total,
            'folder'=>$folder,
            'stats'=>['registered'=>0,'existing'=>0,'unmatched'=>0,'ambiguous'=>0,'errors'=>0],
            'report'=>[],'log'=>['Найдено PDF: ' . $total],'started_at'=>current_time('mysql'),'finished_at'=>'',
        ];
        update_option(self::PDF_JOB_OPT, $job, false);
        wp_send_json_success($this->public_pdf_job($job));
    }

    private function find_pdf_owner($basename) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($basename) . '%';
        $submissionRows = $wpdb->get_results($wpdb->prepare("SELECT id,user_id FROM {$this->submissions_table} WHERE data_json LIKE %s ORDER BY id DESC LIMIT 3", $like));
        if (count($submissionRows) === 1) {
            return ['user_id'=>(int)$submissionRows[0]->user_id,'submission_id'=>(int)$submissionRows[0]->id,'method'=>'basename'];
        }
        if (count($submissionRows) > 1) { return ['ambiguous'=>1,'method'=>'basename']; }

        preg_match_all('/\d{2,}/', $basename, $matches);
        $numbers = array_reverse(array_values(array_unique((array)($matches[0] ?? []))));
        foreach ($numbers as $number) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT target_user_id,target_submission_id FROM {$this->map_table} WHERE legacy_entry_id=%s ORDER BY id DESC LIMIT 20", $number));
            $pairs=[];
            foreach ((array)$rows as $r) { $pairs[(int)$r->target_user_id . ':' . (int)$r->target_submission_id]=$r; }
            if (count($pairs) === 1) { $r=reset($pairs); return ['user_id'=>(int)$r->target_user_id,'submission_id'=>(int)$r->target_submission_id,'method'=>'entry_id']; }
            if (count($pairs) > 1) { return ['ambiguous'=>1,'method'=>'entry_id']; }
        }
        foreach ($numbers as $number) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT target_user_id,target_submission_id FROM {$this->map_table} WHERE legacy_user_id=%s ORDER BY id DESC LIMIT 20", $number));
            $userIds = array_values(array_unique(array_filter(array_map(function($r){ return (int)$r->target_user_id; }, (array)$rows))));
            if (count($userIds) === 1) { return ['user_id'=>$userIds[0],'submission_id'=>0,'method'=>'user_id']; }
            if (count($userIds) > 1) { return ['ambiguous'=>1,'method'=>'user_id']; }
        }
        return [];
    }

    private function document_title_from_filename($basename) {
        $name = function_exists('mb_strtolower') ? mb_strtolower($basename, 'UTF-8') : strtolower($basename);
        if (strpos($name, 'vstupl') !== false || strpos($name, 'вступ') !== false) { return 'Архивное заявление о вступлении'; }
        if (strpos($name, 'oplata') !== false || strpos($name, 'vznos') !== false || strpos($name, 'взнос') !== false) { return 'Архивное заявление на уплату взносов'; }
        return 'Архивный документ со старого сайта';
    }

    private function register_pdf($path, $owner) {
        global $wpdb;
        $up = wp_upload_dir();
        $root = wp_normalize_path(trailingslashit($up['basedir']));
        $path = wp_normalize_path($path);
        if (strpos($path, $root) !== 0 || !is_file($path)) { return new WP_Error('unsafe_pdf', 'Небезопасный или отсутствующий путь.'); }
        $relative = ltrim(substr($path, strlen($root)), '/');
        $url = trailingslashit($up['baseurl']) . str_replace('%2F', '/', rawurlencode($relative));
        $url = str_replace('%2F', '/', $url);
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->docs_table} WHERE pdf_url=%s LIMIT 1", $url));
        if ($existing) { return ['kind'=>'existing','document_id'=>$existing]; }
        $userId = (int)($owner['user_id'] ?? 0);
        $submissionId = (int)($owner['submission_id'] ?? 0);
        $user = $userId ? get_user_by('id', $userId) : false;
        if (!$user) { return new WP_Error('pdf_user', 'Владелец PDF не найден.'); }
        $basename = basename($path);
        $docNo = pathinfo($basename, PATHINFO_FILENAME);
        $memberStatus = (string)get_user_meta($userId, 'zau_member_status', true);
        $recordStatus = ($memberStatus === 'Выбыл из профсоюза') ? 'revoked' : 'active';
        try { $verifyToken = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : wp_generate_password(32, false, false); } catch (Exception $e) { $verifyToken = wp_generate_password(32, false, false); }
        $issueDate = wp_date('Y-m-d', filemtime($path));
        if ($submissionId) {
            $created = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$this->submissions_table} WHERE id=%d", $submissionId));
            if ($created) { $issueDate = substr((string)$created, 0, 10); }
        }
        $data = [
            'legacy_bridge_pdf'=>1,
            'legacy_file_basename'=>$basename,
            'legacy_match_method'=>$owner['method'] ?? '',
            'legacy_relative_path'=>$relative,
        ];
        if ($recordStatus === 'revoked') {
            $data['_zau_member_exit_revocation'] = ['active'=>1,'reason'=>'Выбыл из профсоюза','revoked_at'=>current_time('mysql')];
        }
        $insert = [
            'template_id'=>0,'user_id'=>$userId,'created_by'=>get_current_user_id(),
            'source_submission_id'=>$submissionId,'full_name'=>$user->display_name,
            'document_title'=>$this->document_title_from_filename($basename),
            'organization'=>(string)get_user_meta($userId, 'zau_organization_name', true),
            'issue_date'=>$issueDate,'document_no'=>$docNo,'member_status'=>$memberStatus,
            'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'orientation'=>'portrait','verify_token'=>$verifyToken,'image_url'=>'','pdf_url'=>$url,
            'file_revision'=>max(1,(int)filemtime($path)),'record_status'=>$recordStatus,
            'revoked_at'=>$recordStatus === 'revoked' ? current_time('mysql') : null,
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
        ];
        $ok = $wpdb->insert($this->docs_table, $insert);
        if (!$ok) { return new WP_Error('pdf_insert', 'Не удалось зарегистрировать PDF: ' . $wpdb->last_error); }
        return ['kind'=>'registered','document_id'=>(int)$wpdb->insert_id];
    }

    public function ajax_pdf_process() {
        $this->require_access();
        $job = (array)get_option(self::PDF_JOB_OPT, []);
        if (!$job || ($job['status'] ?? '') !== 'running' || empty($job['manifest']) || !is_file($job['manifest'])) {
            wp_send_json_error(['message'=>'Активная проверка PDF не найдена.'], 404);
        }
        $fh = fopen($job['manifest'], 'rb');
        if (!$fh) { wp_send_json_error(['message'=>'Не удалось открыть список PDF.'], 500); }
        fseek($fh, (int)$job['offset']);
        $handled = 0;
        while ($handled < 100 && ($line = fgets($fh)) !== false) {
            $job['offset'] = ftell($fh);
            $path = trim($line);
            if ($path === '') { continue; }
            $handled++; $job['processed']++;
            $basename = basename($path);
            $owner = $this->find_pdf_owner($basename);
            if (!empty($owner['ambiguous'])) {
                $kind = 'ambiguous'; $message = 'Найдено несколько возможных владельцев.';
            } elseif (empty($owner['user_id'])) {
                $kind = 'unmatched'; $message = 'Владелец не определён.';
            } else {
                $result = $this->register_pdf($path, $owner);
                if (is_wp_error($result)) { $kind = 'errors'; $message = $result->get_error_message(); }
                else { $kind = $result['kind']; $message = $kind === 'registered' ? 'PDF зарегистрирован.' : 'PDF уже был зарегистрирован.'; }
            }
            if (!isset($job['stats'][$kind])) { $job['stats'][$kind] = 0; }
            $job['stats'][$kind]++;
            $job['report'][] = ['file'=>$basename,'result'=>$kind,'message'=>$message,'user_id'=>(int)($owner['user_id'] ?? 0),'submission_id'=>(int)($owner['submission_id'] ?? 0)];
        }
        $eof = feof($fh); fclose($fh);
        if ($eof || $job['processed'] >= $job['total']) {
            $job['status'] = 'finished'; $job['finished_at'] = current_time('mysql');
            $job['log'][] = 'Проверка и привязка PDF завершена.';
        }
        if (count($job['report']) > 5000) { $job['report'] = array_slice($job['report'], -5000); }
        update_option(self::PDF_JOB_OPT, $job, false);
        wp_send_json_success($this->public_pdf_job($job));
    }

    private function public_pdf_job($job) {
        return [
            'status'=>$job['status'] ?? 'idle','processed'=>(int)($job['processed'] ?? 0),'total'=>(int)($job['total'] ?? 0),
            'stats'=>$job['stats'] ?? [],'log'=>array_slice((array)($job['log'] ?? []),-20),'finished_at'=>$job['finished_at'] ?? '',
        ];
    }

    public function ajax_pdf_reset() {
        $this->require_access();
        $job = (array)get_option(self::PDF_JOB_OPT, []);
        if (!empty($job['manifest']) && is_file($job['manifest'])) { @unlink($job['manifest']); }
        delete_option(self::PDF_JOB_OPT);
        wp_send_json_success(['message'=>'Проверка PDF сброшена. Зарегистрированные документы не удалены.']);
    }

    public function download_pdf_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $job = (array)get_option(self::PDF_JOB_OPT, []);
        $this->output_csv('zau-pdf-link-report', ['Файл','Результат','Сообщение','User ID','Заявка ID'], (array)($job['report'] ?? []), function($r){
            return [$r['file'] ?? '',$r['result'] ?? '',$r['message'] ?? '',$r['user_id'] ?? 0,$r['submission_id'] ?? 0];
        });
    }

    private function output_csv($prefix, $headers, $rows, $mapper) {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($prefix . '-' . wp_date('Y-m-d-H-i') . '.csv') . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ';');
        foreach ($rows as $row) { fputcsv($out, call_user_func($mapper, $row), ';'); }
        fclose($out); exit;
    }

    public function page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        $s=$this->settings();
        $job=(array)get_option(self::JOB_OPT,[]);
        $pdfJob=(array)get_option(self::PDF_JOB_OPT,[]);
        ?>
        <div class="wrap zau-remote-wrap">
            <?php zau_admin_hub_nav('import'); ?>
            <h1>Перенос со старого сайта — ФИО + email и точная связь заявлений</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Настройки подключения сохранены.</p></div><?php endif; ?>
            <div class="notice notice-warning inline"><p><strong>Перед настоящим переносом сделайте резервные копии обеих баз и каталогов uploads.</strong> Сначала выполните полный пробный анализ и проверьте строки «требуется проверка».</p></div>

            <section class="zau-rb-card">
                <h2>1. Подключение и правило уникальности</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="zau_remote_bridge_save">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <div class="zau-rb-grid">
                        <label>Адрес старого сайта<input type="url" name="old_url" value="<?php echo esc_attr($s['old_url']); ?>" placeholder="https://old-site.kz" required></label>
                        <label>Секретный ключ<input type="password" name="secret" value="<?php echo esc_attr($s['secret']); ?>" autocomplete="new-password" required></label>
                        <label>Записей за запрос<input type="number" name="batch_size" min="10" max="200" step="10" value="<?php echo (int)$s['batch_size']; ?>"></label>
                        <label>Статус перенесённых участников<select name="default_status"><?php foreach (['Состоит в профсоюзе','Заявление подано','На рассмотрении','Регистрация не завершена'] as $status): ?><option <?php selected($s['default_status'],$status); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label>
                        <label>Заявления одного вида
                            <select name="application_policy">
                                <option value="latest_only_archive" <?php selected($s['application_policy'],'latest_only_archive'); ?>>В кабинете только самая новая; старые сохранить в архиве миграции</option>
                                <option value="all_unique" <?php selected($s['application_policy'],'all_unique'); ?>>Импортировать все уникальные заявления</option>
                            </select>
                        </label>
                        <label>Когда есть только ФИО
                            <select name="weak_identity_action">
                                <option value="review_skip" <?php selected($s['weak_identity_action'],'review_skip'); ?>>Не объединять и не создавать — отправить на ручную проверку</option>
                                <option value="merge_name" <?php selected($s['weak_identity_action'],'merge_name'); ?>>Объединять одинаковое ФИО — риск совпадения разных людей</option>
                                <option value="create_separate" <?php selected($s['weak_identity_action'],'create_separate'); ?>>Создавать каждую запись отдельно — риск дублей</option>
                            </select>
                        </label>
                        <label>Каталог PDF после FTP<input type="text" name="pdf_folder" value="<?php echo esc_attr($s['pdf_folder']); ?>" placeholder="legacy-user-pdfs"><small>Относительно wp-content/uploads</small></label>
                    </div>
                    <div class="zau-rb-checks">
                        <label><input type="checkbox" name="create_users" value="1" <?php checked(!empty($s['create_users'])); ?>> Создавать отсутствующие аккаунты</label>
                        <label><input type="checkbox" name="update_existing" value="1" <?php checked(!empty($s['update_existing'])); ?>> Заполнять пустые поля существующих аккаунтов</label>
                        <label><input type="checkbox" name="overwrite" value="1" <?php checked(!empty($s['overwrite'])); ?>> Разрешить замену данных, уже введённых на новом сайте</label>
                        <label><input type="checkbox" name="preserve_password" value="1" <?php checked(!empty($s['preserve_password'])); ?>> Сохранять совместимые WordPress-хеши паролей только для новых аккаунтов</label>
                    </div>
                    <p class="description"><strong>Без галочки замены</strong> при первом переносе профиль собирается из самых новых старых записей, но в уже существующем кабинете заполняются только пустые поля. Повторный перенос не стирает данные, которые пользователь или администратор изменил на новом сайте.</p>
                    <p><button class="button button-primary" type="submit">Сохранить правила</button> <button class="button" type="button" data-zau-rb-test>Проверить подключение</button></p>
                    <div class="zau-rb-message" data-zau-rb-message></div>
                </form>
            </section>

            <section class="zau-rb-card">
                <h2>2. Полный анализ и перенос</h2>
                <p>Система сначала считывает все аккаунты и заявления в промежуточный архив. Один человек определяется по точной нормализованной паре <strong>ФИО + email</strong>. Заявления прикрепляются прежде всего по старому <strong>User ID</strong>, а каждое заявление защищено от дубля своим <strong>Entry ID</strong>. Затем выбирается самая новая уникальная заявка каждого вида.</p>
                <p><button class="button button-secondary button-hero" data-zau-rb-start="dry">Полный пробный анализ</button> <button class="button button-primary button-hero" data-zau-rb-start="import">Начать настоящий перенос</button> <button class="button button-hero" data-zau-rb-resume hidden>Продолжить текущий процесс</button> <button class="button" data-zau-rb-reset>Сбросить текущий прогресс</button></p>
                <div class="zau-rb-progress" data-zau-rb-progress <?php echo $job?'':'hidden'; ?>><span></span></div>
                <p data-zau-rb-progress-text></p>
                <div class="zau-rb-stats" data-zau-rb-stats></div>
                <pre data-zau-rb-log><?php echo esc_html(implode("\n",(array)($job['log'] ?? []))); ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_remote_bridge_report'),self::NONCE)); ?>">Скачать подробный отчёт</a></p>
                <p class="description">В отчёте ИИН, телефон и email маскируются. Строки <code>review_required</code> и <code>identity_conflict</code> не импортируются автоматически.</p>
            </section>

            <section class="zau-rb-card">
                <h2>3. Проверка заполнения перенесённых профилей</h2>
                <p>После завершения переноса отдельно проверьте статус, организацию, филиал и личную карточку. Если аккаунты создались, но привязки пустые, откройте безопасный инструмент восстановления: он не создаёт новых пользователей.</p>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-remote-profile-repair')); ?>">Исправить перенесённые профили</a></p>
            </section>

            <section class="zau-rb-card">
                <h2>4. PDF после копирования по FTP/SFTP</h2>
                <p>Скопируйте старые PDF в <code>wp-content/uploads/<?php echo esc_html(trim($s['pdf_folder'],'/')); ?>/</code>, сохранив имена файлов. Старые Entry ID, включая архивные заявления, сопоставляются с актуальным аккаунтом и выбранной последней заявкой.</p>
                <p><button class="button button-primary" data-zau-rb-pdf-start>Найти и привязать PDF</button> <button class="button" data-zau-rb-pdf-reset>Сбросить проверку PDF</button></p>
                <div class="zau-rb-progress" data-zau-rb-pdf-progress <?php echo $pdfJob?'':'hidden'; ?>><span></span></div>
                <p data-zau-rb-pdf-progress-text></p>
                <div class="zau-rb-stats" data-zau-rb-pdf-stats></div>
                <pre data-zau-rb-pdf-log><?php echo esc_html(implode("\n",(array)($pdfJob['log'] ?? []))); ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_remote_bridge_pdf_report'),self::NONCE)); ?>">Скачать отчёт PDF</a></p>
            </section>

            <section class="zau-rb-card">
                <h2>Что считается дублем</h2>
                <ol>
                    <li>Одинаковые нормализованные ФИО и email — один аккаунт.</li>
                    <li>Одинаковый email при разных ФИО не объединяет людей.</li>
                    <li>Одинаковое ФИО при разных email не объединяет людей.</li>
                    <li>Телефон и ИИН переносятся как данные профиля, но сами по себе не объединяют аккаунты.</li>
                    <li>Заявление с заполненным старым User ID всегда прикрепляется к аккаунту этого старого пользователя.</li>
                    <li>Старый Entry ID является уникальным ID заявления и не позволяет создать его повторно.</li>
                    <li>Если email отсутствует, старый User ID сохраняет отдельный аккаунт; запись без email и без User ID отправляется на проверку.</li>
                    <li>Для каждого вида заявления актуальной становится самая новая запись, а старые остаются в архиве.</li>
                </ol>
            </section>
        </div>
        <?php
    }
}

ZAU_Remote_Bridge_Import::instance();
