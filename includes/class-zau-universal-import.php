<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Universal CSV importer for accounts and legacy applications from another site.
 * It never deletes source data and processes rows in small AJAX batches.
 */
final class ZAU_Universal_Import {
    const VERSION = '2.18.2';
    const DB_VERSION = '1.2.0';
    const OPT_DB_VERSION = 'zau_universal_import_db_version';
    const NONCE = 'zau_universal_import_nonce';

    private static $instance = null;
    private $map_table;
    private $forms_table;
    private $submissions_table;
    private $orgs_table;
    private $branches_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->map_table = $wpdb->prefix . 'zau_external_import_map';
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->orgs_table = $wpdb->prefix . 'zau_union_organizations';
        $this->branches_table = $wpdb->prefix . 'zau_union_branches';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 38);
        add_action('admin_menu', [$this, 'admin_menu'], 38);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('wp_ajax_zau_universal_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_zau_universal_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_universal_process', [$this, 'ajax_process']);
        add_action('wp_ajax_zau_universal_reset', [$this, 'ajax_reset']);
        add_action('admin_post_zau_universal_download_report', [$this, 'download_report']);
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
        dbDelta("CREATE TABLE {$this->map_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_token varchar(64) NOT NULL,
            source_hash varchar(64) NOT NULL,
            row_number bigint(20) unsigned NOT NULL DEFAULT 0,
            legacy_user_id varchar(100) NULL,
            legacy_entry_id varchar(100) NULL,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'imported',
            message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_hash (source_hash),
            KEY import_token (import_token),
            KEY target_user_id (target_user_id),
            KEY target_submission_id (target_submission_id),
            KEY status (status)
        ) $charset;");
    }

    public function admin_menu() {
        add_submenu_page(
            'zau-certificates',
            'Перенос аккаунтов и заявлений',
            'Импорт аккаунтов',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-universal-import',
            [$this, 'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-universal-import') === false) { return; }
        wp_enqueue_style('zau-universal-import', plugins_url('../assets/css/universal-import.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('zau-universal-import', plugins_url('../assets/js/universal-import.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('zau-universal-import', 'ZAUUniversalImport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'reportUrl' => wp_nonce_url(admin_url('admin-post.php?action=zau_universal_download_report'), self::NONCE),
            'targetFields' => $this->target_fields(),
        ]);
    }

    private function can_manage() {
        return current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) || current_user_can('manage_options');
    }

    private function require_access() {
        if (!$this->can_manage()) { wp_send_json_error(['message'=>'Недостаточно прав.'], 403); }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function upload_option_key() { return 'zau_universal_upload_' . get_current_user_id(); }
    private function job_option_key() { return 'zau_universal_job_' . get_current_user_id(); }

    private function target_fields() {
        return [
            '' => '— не сопоставлять, сохранить в архивных данных —',
            'legacy_user_id' => 'Старый ID пользователя',
            'legacy_entry_id' => 'Старый ID заявления/записи',
            'user_login' => 'Логин пользователя',
            'email' => 'Email',
            'phone' => 'Телефон',
            'full_name' => 'ФИО одной строкой',
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'middle_name' => 'Отчество',
            'iin' => 'ИИН',
            'birth_date' => 'Дата рождения',
            'address' => 'Адрес проживания',
            'position' => 'Должность',
            'department' => 'Подразделение',
            'organization' => 'Организация / место работы',
            'organization_bin' => 'БИН организации',
            'organization_director' => 'Руководитель организации',
            'organization_address' => 'Адрес организации',
            'branch_name' => 'Филиал профсоюза',
            'registration_date' => 'Дата регистрации аккаунта',
            'submission_date' => 'Дата подачи заявления',
            'application_no' => 'Номер старого заявления',
            'signature_url' => 'Ссылка/путь к подписи',
            'pdf_url' => 'Ссылка/путь к старому PDF',
            'notes' => 'Примечание',
        ];
    }

    private function auto_target($header) {
        $h = $this->normalize_label($header);
        $rules = [
            'legacy_user_id' => ['userid','user id','id пользователя','старый id пользователя','id юзера'],
            'legacy_entry_id' => ['entry id','id записи','id заявки','id заявления','номер записи'],
            'email' => ['email','e mail','электронная почта','почта'],
            'phone' => ['телефон','мобильный','номер телефона','phone'],
            'full_name' => ['фио','ф и о','полное имя','full name','имя пользователя'],
            'last_name' => ['фамилия','last name'],
            'first_name' => ['имя','first name'],
            'middle_name' => ['отчество','middle name'],
            'iin' => ['иин','iin'],
            'birth_date' => ['дата рождения','день рождения','birth date'],
            'address' => ['адрес проживания','домашний адрес','адрес участника'],
            'position' => ['должность','position'],
            'department' => ['подразделение','отдел','department'],
            'organization_bin' => ['бин организации','бин предприятия','бин работодателя'],
            'organization_director' => ['руководитель','директор','фио руководителя'],
            'organization_address' => ['адрес организации','юридический адрес организации','юр адрес организации'],
            'organization' => ['организация','место работы','предприятие','наименование организации'],
            'branch_name' => ['филиал','область','регион профсоюза','филиал профсоюза'],
            'registration_date' => ['дата регистрации','registered','user registered'],
            'submission_date' => ['дата подачи','дата заявления','дата заявки','created at','дата создания'],
            'application_no' => ['номер заявления','номер заявки','application number'],
            'signature_url' => ['подпись','signature','ссылка на подпись'],
            'pdf_url' => ['pdf','документ pdf','ссылка на pdf','файл заявления'],
            'notes' => ['примечание','комментарий','notes'],
            'user_login' => ['логин','user login','username'],
        ];
        foreach ($rules as $target=>$variants) {
            foreach ($variants as $variant) {
                if ($h === $variant || strpos($h, $variant) !== false) { return $target; }
            }
        }
        return '';
    }

    private function normalize_label($value) {
        $value = wp_strip_all_tags((string)$value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = str_replace(['ё','_','-','/','\\','.','(',')','[',']',':'], ['е',' ',' ',' ',' ',' ',' ',' ',' ',' ',' '], $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function private_dir() {
        $dir = WP_CONTENT_DIR . '/zau-private-imports';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        if (is_dir($dir)) {
            if (!is_file($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n"); }
            if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Deny from all\n"); }
        }
        return $dir;
    }

    private function convert_to_utf8($path) {
        $sample = @file_get_contents($path, false, null, 0, 200000);
        if ($sample === false || $sample === '') { return; }
        if (substr($sample, 0, 3) === "\xEF\xBB\xBF") {
            $all = file_get_contents($path);
            file_put_contents($path, substr($all, 3));
            return;
        }
        if (!function_exists('mb_detect_encoding')) { return; }
        $enc = mb_detect_encoding($sample, ['UTF-8','Windows-1251','CP1251','ISO-8859-1'], true);
        if ($enc && strtoupper($enc) !== 'UTF-8') {
            $all = file_get_contents($path);
            $utf = mb_convert_encoding($all, 'UTF-8', $enc);
            file_put_contents($path, $utf);
        }
    }

    private function detect_delimiter($path) {
        $line = '';
        $fh = fopen($path, 'rb');
        if ($fh) { $line = (string)fgets($fh); fclose($fh); }
        $counts = [','=>substr_count($line, ','), ';'=>substr_count($line, ';'), "\t"=>substr_count($line, "\t"), '|'=>substr_count($line, '|')];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ',';
    }

    private function inspect_csv($path, $delimiter) {
        $fh = fopen($path, 'rb');
        if (!$fh) { return new WP_Error('csv_open', 'Не удалось открыть CSV.'); }
        $headers = fgetcsv($fh, 0, $delimiter);
        if (!is_array($headers) || !$headers) { fclose($fh); return new WP_Error('csv_header', 'В CSV не найдена строка заголовков.'); }
        $headers = array_map(function($v){ return trim((string)$v); }, $headers);
        $dataOffset = ftell($fh);
        $preview = [];
        $total = 0;
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($this->row_is_empty($row)) { continue; }
            $total++;
            if (count($preview) < 5) { $preview[] = array_pad(array_slice($row, 0, count($headers)), count($headers), ''); }
        }
        fclose($fh);
        return ['headers'=>$headers, 'preview'=>$preview, 'total'=>$total, 'data_offset'=>$dataOffset];
    }

    private function row_is_empty($row) {
        foreach ((array)$row as $value) { if (trim((string)$value) !== '') { return false; } }
        return true;
    }

    public function ajax_upload() {
        $this->require_access();
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) { wp_send_json_error(['message'=>'Выберите CSV-файл.'], 400); }
        $file = $_FILES['file'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { wp_send_json_error(['message'=>'Ошибка загрузки файла. Код: '.(int)$file['error']], 400); }
        if ((int)($file['size'] ?? 0) < 2 || (int)$file['size'] > 50 * 1024 * 1024) { wp_send_json_error(['message'=>'Размер CSV должен быть от 2 байт до 50 МБ.'], 400); }
        $name = sanitize_file_name((string)($file['name'] ?? 'import.csv'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'], true)) { wp_send_json_error(['message'=>'На первом этапе поддерживаются CSV и TXT. Экспортируйте Excel как CSV UTF-8.'], 400); }
        $dir = $this->private_dir();
        if (!is_dir($dir) || !is_writable($dir)) { wp_send_json_error(['message'=>'Папка приватного импорта недоступна для записи.'], 500); }
        $token = wp_generate_password(24, false, false);
        $path = trailingslashit($dir) . 'import-' . get_current_user_id() . '-' . $token . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $path)) { wp_send_json_error(['message'=>'Не удалось сохранить загруженный CSV.'], 500); }
        @chmod($path, 0600);
        $this->convert_to_utf8($path);
        $delimiter = $this->detect_delimiter($path);
        $info = $this->inspect_csv($path, $delimiter);
        if (is_wp_error($info)) { @unlink($path); wp_send_json_error(['message'=>$info->get_error_message()], 400); }
        $auto = [];
        foreach ($info['headers'] as $i=>$header) { $auto[(string)$i] = $this->auto_target($header); }
        $upload = [
            'path'=>$path,
            'original_name'=>$name,
            'token'=>$token,
            'delimiter'=>$delimiter,
            'headers'=>$info['headers'],
            'total'=>(int)$info['total'],
            'data_offset'=>(int)$info['data_offset'],
            'uploaded_at'=>time(),
        ];
        update_option($this->upload_option_key(), $upload, false);
        delete_option($this->job_option_key());
        wp_send_json_success([
            'message'=>'Файл прочитан. Сопоставьте колонки.',
            'headers'=>$info['headers'],
            'preview'=>$info['preview'],
            'total'=>(int)$info['total'],
            'auto_mapping'=>$auto,
            'file_name'=>$name,
        ]);
    }

    public function ajax_start() {
        $this->require_access();
        $upload = (array)get_option($this->upload_option_key(), []);
        if (!$upload || empty($upload['path']) || !is_file($upload['path'])) { wp_send_json_error(['message'=>'Сначала загрузите CSV-файл.'], 400); }
        $mappingRaw = json_decode((string)wp_unslash($_POST['mapping'] ?? ''), true);
        if (!is_array($mappingRaw)) { wp_send_json_error(['message'=>'Не получено сопоставление колонок.'], 400); }
        $allowed = array_keys($this->target_fields());
        $mapping = [];
        foreach ($mappingRaw as $index=>$target) {
            $index = absint($index);
            $target = sanitize_key((string)$target);
            if (in_array($target, $allowed, true)) { $mapping[$index] = $target; }
        }
        $used = array_values(array_filter($mapping));
        if (!array_intersect($used, ['email','phone','iin','legacy_user_id','full_name','first_name','last_name'])) {
            wp_send_json_error(['message'=>'Сопоставьте хотя бы email, телефон, ИИН, старый ID или ФИО.'], 400);
        }
        $mode = sanitize_key((string)($_POST['mode'] ?? 'both'));
        if (!in_array($mode, ['both','accounts','applications'], true)) { $mode = 'both'; }
        $dryRun = !empty($_POST['dry_run']);
        $options = [
            'mode'=>$mode,
            'dry_run'=>$dryRun ? 1 : 0,
            'create_users'=>!empty($_POST['create_users']) ? 1 : 0,
            'update_existing'=>!empty($_POST['update_existing']) ? 1 : 0,
            'overwrite'=>!empty($_POST['overwrite']) ? 1 : 0,
            'default_status'=>sanitize_text_field((string)($_POST['default_status'] ?? 'Заявление подано')),
            'batch_size'=>max(10, min(200, absint($_POST['batch_size'] ?? 50))),
        ];
        $job = [
            'token'=>$upload['token'],
            'file_path'=>$upload['path'],
            'file_name'=>$upload['original_name'],
            'delimiter'=>$upload['delimiter'],
            'headers'=>$upload['headers'],
            'total'=>(int)$upload['total'],
            'data_offset'=>(int)$upload['data_offset'],
            'byte_position'=>(int)$upload['data_offset'],
            'processed'=>0,
            'mapping'=>$mapping,
            'options'=>$options,
            'stats'=>['created_users'=>0,'updated_users'=>0,'existing_users'=>0,'submissions'=>0,'duplicates'=>0,'skipped'=>0,'errors'=>0,'would_create'=>0,'would_update'=>0,'would_submit'=>0],
            'report'=>[],
            'log'=>[],
            'status'=>'running',
            'started_at'=>current_time('mysql'),
            'finished_at'=>'',
        ];
        update_option($this->job_option_key(), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    public function ajax_process() {
        $this->require_access();
        $job = (array)get_option($this->job_option_key(), []);
        if (!$job || empty($job['file_path']) || !is_file($job['file_path'])) { wp_send_json_error(['message'=>'Задание импорта не найдено.'], 404); }
        if (($job['status'] ?? '') === 'finished') { wp_send_json_success($this->public_job($job)); }
        $fh = fopen($job['file_path'], 'rb');
        if (!$fh) { wp_send_json_error(['message'=>'Не удалось повторно открыть CSV.'], 500); }
        fseek($fh, (int)$job['byte_position']);
        $limit = (int)$job['options']['batch_size'];
        $handled = 0;
        while ($handled < $limit && ($row = fgetcsv($fh, 0, $job['delimiter'])) !== false) {
            $job['byte_position'] = ftell($fh);
            if ($this->row_is_empty($row)) { continue; }
            $job['processed']++;
            $handled++;
            $result = $this->process_row($row, $job);
            $this->apply_result($job, $result);
        }
        $eof = feof($fh);
        fclose($fh);
        if ($eof || (int)$job['processed'] >= (int)$job['total']) {
            $job['status'] = 'finished';
            $job['finished_at'] = current_time('mysql');
            $job['log'][] = 'Импорт завершён: ' . $job['processed'] . ' строк.';
        }
        if (count($job['report']) > 2000) { $job['report'] = array_slice($job['report'], -2000); }
        if (count($job['log']) > 100) { $job['log'] = array_slice($job['log'], -100); }
        update_option($this->job_option_key(), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    private function process_row($row, $job) {
        $headers = (array)$job['headers'];
        $mapping = (array)$job['mapping'];
        $mapped = [];
        $legacy = [];
        foreach ($headers as $i=>$header) {
            $value = isset($row[$i]) ? trim((string)$row[$i]) : '';
            if ($value !== '') { $legacy[(string)$header] = $value; }
            $target = $mapping[$i] ?? '';
            if ($target !== '' && $value !== '') { $mapped[$target] = $value; }
        }
        $mapped = $this->normalize_mapped($mapped);
        $rowNumber = (int)$job['processed'] + 1; // + заголовок
        $sourceHash = hash('sha256', ($mapped['legacy_entry_id'] ?? '') . '|' . ($mapped['legacy_user_id'] ?? '') . '|' . wp_json_encode($legacy, JSON_UNESCAPED_UNICODE));
        global $wpdb;
        $existingMap = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->map_table} WHERE source_hash=%s", $sourceHash));
        if ($existingMap && empty($job['options']['dry_run'])) {
            return ['kind'=>'duplicate','row'=>$rowNumber,'message'=>'Строка уже импортировалась ранее.','user_id'=>(int)$existingMap->target_user_id,'submission_id'=>(int)$existingMap->target_submission_id,'mapped'=>$mapped];
        }

        $forcedUserId = absint($options['forced_user_id'] ?? 0);
        $user = $forcedUserId ? get_user_by('id', $forcedUserId) : $this->find_user($mapped);
        $userId = $user ? (int)$user->ID : 0;
        $mode = $job['options']['mode'];
        $dry = !empty($job['options']['dry_run']);
        $willCreate = !$userId && !empty($job['options']['create_users']) && $mode !== 'applications';
        if (!$userId && !$willCreate && $mode !== 'accounts') {
            return ['kind'=>'skipped','row'=>$rowNumber,'message'=>'Не найден аккаунт и создание новых отключено.','mapped'=>$mapped];
        }
        if (!$userId && $willCreate && !$this->has_identity($mapped)) {
            return ['kind'=>'error','row'=>$rowNumber,'message'=>'Недостаточно данных для создания аккаунта: нужен email, телефон, ИИН или старый ID.','mapped'=>$mapped];
        }

        if ($dry) {
            $kind = $userId ? 'would_update' : 'would_create';
            $submission = ($mode !== 'accounts') ? 1 : 0;
            return ['kind'=>$kind,'row'=>$rowNumber,'message'=>($userId?'Будет найден/обновлён существующий аккаунт.':'Будет создан новый аккаунт.').($submission?' Будет добавлено архивное заявление.':''),'user_id'=>$userId,'would_submit'=>$submission,'mapped'=>$mapped];
        }

        if (!$userId) {
            $created = $this->create_user($mapped, $identityMode);
            if (is_wp_error($created)) { return ['kind'=>'error','row'=>$rowNumber,'message'=>$created->get_error_message(),'mapped'=>$mapped]; }
            $userId = (int)$created;
            $user = get_user_by('id', $userId);
            $userKind = 'created';
        } else {
            $userKind = 'existing';
        }

        if (!empty($job['options']['update_existing']) || $userKind === 'created') {
            $updated = $this->update_user($userId, $mapped, !empty($job['options']['overwrite']), $job['options']['default_status']);
            if (is_wp_error($updated)) { return ['kind'=>'error','row'=>$rowNumber,'message'=>$updated->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
            if ($userKind === 'existing') { $userKind = 'updated'; }
        }

        $submissionId = 0;
        if ($mode !== 'accounts') {
            $submissionId = $this->create_submission($userId, $mapped, $legacy, (string)($options['submission_status'] ?? 'submitted'));
            if (is_wp_error($submissionId)) { return ['kind'=>'error','row'=>$rowNumber,'message'=>$submissionId->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
        }

        $now = current_time('mysql');
        $wpdb->replace($this->map_table, [
            'import_token'=>$job['token'],
            'source_hash'=>$sourceHash,
            'row_number'=>$rowNumber,
            'legacy_user_id'=>sanitize_text_field($mapped['legacy_user_id'] ?? ''),
            'legacy_entry_id'=>sanitize_text_field($mapped['legacy_entry_id'] ?? ''),
            'target_user_id'=>$userId,
            'target_submission_id'=>(int)$submissionId,
            'status'=>'imported',
            'message'=>'OK',
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
        return ['kind'=>$userKind,'row'=>$rowNumber,'message'=>'Аккаунт обработан'.($submissionId?' и заявление добавлено.':'.'),'user_id'=>$userId,'submission_id'=>(int)$submissionId,'mapped'=>$mapped];
    }

    private function normalize_mapped($data) {
        $out = [];
        foreach ((array)$data as $key=>$value) {
            $value = trim(wp_strip_all_tags((string)$value));
            if ($key === 'email') { $value = sanitize_email($value); }
            elseif ($key === 'phone') { $value = $this->normalize_phone($value); }
            elseif (in_array($key, ['iin','organization_bin'], true)) { $value = preg_replace('/\D+/', '', $value); }
            elseif (in_array($key, ['registration_date','submission_date','birth_date'], true)) { $value = $this->normalize_date($value); }
            $out[$key] = $value;
        }
        if (empty($out['full_name'])) { $out['full_name'] = trim(implode(' ', array_filter([$out['last_name'] ?? '', $out['first_name'] ?? '', $out['middle_name'] ?? '']))); }
        if (!empty($out['full_name']) && (empty($out['first_name']) || empty($out['last_name']))) {
            $parts = preg_split('/\s+/u', trim($out['full_name']));
            if (count($parts) >= 2) {
                if (empty($out['last_name'])) { $out['last_name'] = array_shift($parts); }
                if (empty($out['first_name'])) { $out['first_name'] = array_shift($parts); }
                if (empty($out['middle_name'])) { $out['middle_name'] = implode(' ', $parts); }
            }
        }
        return $out;
    }

    private function normalize_phone($value) {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (strlen($digits) === 10) { $digits = '7' . $digits; }
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '8') { $digits = '7' . substr($digits, 1); }
        return $digits ? '+' . $digits : '';
    }

    private function normalize_date($value) {
        $value = trim((string)$value);
        if ($value === '') { return ''; }
        $formats = ['Y-m-d H:i:s','Y-m-d','d.m.Y H:i:s','d.m.Y','d/m/Y','m/d/Y'];
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $value, wp_timezone());
            if ($dt instanceof DateTime) { return $dt->format(strpos($format, 'H') !== false ? 'Y-m-d H:i:s' : 'Y-m-d'); }
        }
        $ts = strtotime($value);
        return $ts ? wp_date('Y-m-d', $ts) : $value;
    }

    private function name_email_identity_key($mapped) {
        $name = $this->normalize_label((string)($mapped['full_name'] ?? ''));
        $email = strtolower(trim((string)($mapped['email'] ?? '')));
        if (!$name || !is_email($email)) { return ''; }
        return hash('sha256', $name . '|' . $email);
    }

    private function find_user_name_email($mapped) {
        global $wpdb;
        $key = $this->name_email_identity_key($mapped);
        if ($key) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_name_email_key' AND meta_value=%s ORDER BY user_id ASC LIMIT 3",
                $key
            ));
            $ids = array_values(array_unique(array_map('intval',(array)$ids)));
            if (count($ids) === 1) { return get_user_by('id',$ids[0]); }
            if (count($ids) > 1) { return new WP_Error('name_email_duplicate','На новом сайте уже найдено несколько аккаунтов с одинаковой парой ФИО + email.'); }
        }
        $email = strtolower(trim((string)($mapped['email'] ?? '')));
        $wantedName = $this->normalize_label((string)($mapped['full_name'] ?? ''));
        if ($email && is_email($email)) {
            $candidateIds = [];
            $owner = get_user_by('email',$email);
            if ($owner) { $candidateIds[(int)$owner->ID] = 1; }
            $metaIds = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_original_email' AND meta_value=%s ORDER BY user_id ASC LIMIT 20",
                $email
            ));
            foreach ((array)$metaIds as $id) { $candidateIds[(int)$id] = 1; }
            $matches = [];
            foreach (array_keys($candidateIds) as $id) {
                $candidate = get_user_by('id',$id);
                if ($candidate && $wantedName && $this->normalize_label((string)$candidate->display_name) === $wantedName) {
                    $matches[(int)$id] = $candidate;
                }
            }
            if (count($matches) === 1) { return reset($matches); }
            if (count($matches) > 1) { return new WP_Error('name_email_duplicate','На новом сайте уже найдено несколько аккаунтов с одинаковой парой ФИО + email.'); }
        }
        return false;
    }

    private function has_identity($mapped) {
        return !empty($mapped['email']) || !empty($mapped['phone']) || !empty($mapped['iin']) || !empty($mapped['legacy_user_id']) || (!empty($mapped['full_name']) && (!empty($mapped['birth_date']) || !empty($mapped['organization']) || !empty($mapped['branch_name'])));
    }

    private function find_user($mapped) {
        global $wpdb;
        if (!empty($mapped['iin'])) {
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('zau_profile_iin','zau_legacy_iin') AND meta_value=%s ORDER BY user_id ASC LIMIT 1", $mapped['iin']));
            if ($id) { return get_user_by('id', $id); }
            $like = '%"iin":"' . $wpdb->esc_like($mapped['iin']) . '"%';
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_member_card_data' AND meta_value LIKE %s ORDER BY user_id ASC LIMIT 1", $like));
            if ($id) { return get_user_by('id', $id); }
        }
        if (!empty($mapped['email']) && is_email($mapped['email'])) {
            $user = get_user_by('email', $mapped['email']);
            if ($user) { return $user; }
        }
        if (!empty($mapped['phone'])) {
            $digits = preg_replace('/\D+/', '', (string)$mapped['phone']);
            $variants = array_values(array_unique(array_filter([
                (string)$mapped['phone'], $digits, $digits ? '+' . $digits : '',
                (strlen($digits)===11 && substr($digits,0,1)==='7') ? '8'.substr($digits,1) : '',
            ])));
            if ($variants) {
                $placeholders = implode(',', array_fill(0,count($variants),'%s'));
                $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_phone' AND meta_value IN ($placeholders) ORDER BY user_id ASC LIMIT 2", $variants));
                if (count($ids) === 1) { return get_user_by('id', (int)$ids[0]); }
            }
        }
        if (!empty($mapped['legacy_user_id'])) {
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_user_id' AND meta_value=%s ORDER BY user_id ASC LIMIT 1", $mapped['legacy_user_id']));
            if ($id) { return get_user_by('id', $id); }
        }
        if (!empty($mapped['full_name']) && !empty($mapped['birth_date'])) {
            $birth = substr($this->mysql_date($mapped['birth_date']),0,10);
            if ($birth) {
                $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE (meta_key='zau_profile_birth_date' AND meta_value=%s) OR (meta_key='zau_member_card_data' AND meta_value LIKE %s) LIMIT 50", $birth, '%"birth_date":"'.$wpdb->esc_like($birth).'"%'));
                $wanted = $this->normalize_label($mapped['full_name']);
                $matches=[];
                foreach ((array)$ids as $candidateId) {
                    $candidate=get_user_by('id',(int)$candidateId);
                    if ($candidate && $this->normalize_label($candidate->display_name)===$wanted) { $matches[(int)$candidateId]=$candidate; }
                }
                if (count($matches)===1) { return reset($matches); }
            }
        }
        return false;
    }

    private function existing_identity_conflict($mapped) {
        global $wpdb;
        $checks=[];
        if (!empty($mapped['iin'])) {
            $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('zau_profile_iin','zau_legacy_iin') AND meta_value=%s LIMIT 3",$mapped['iin']));
            $like='%"iin":"'.$wpdb->esc_like($mapped['iin']).'"%';
            $ids=array_merge((array)$ids,(array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_member_card_data' AND meta_value LIKE %s LIMIT 3",$like)));
            if (count(array_unique(array_map('intval',$ids)))>1) { $checks[]='ИИН'; }
        }
        if (!empty($mapped['phone'])) {
            $digits=preg_replace('/\D+/','',(string)$mapped['phone']);
            $variants=array_values(array_unique(array_filter([(string)$mapped['phone'],$digits,$digits?'+'.$digits:'',(strlen($digits)===11&&substr($digits,0,1)==='7')?'8'.substr($digits,1):''])));
            if ($variants) {
                $ph=implode(',',array_fill(0,count($variants),'%s'));
                $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_phone' AND meta_value IN ($ph) LIMIT 3",$variants));
                if (count(array_unique(array_map('intval',(array)$ids)))>1) { $checks[]='телефон'; }
            }
        }
        if (!empty($mapped['legacy_user_id'])) {
            $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_user_id' AND meta_value=%s LIMIT 3",$mapped['legacy_user_id']));
            if (count(array_unique(array_map('intval',(array)$ids)))>1) { $checks[]='старый User ID'; }
        }
        return $checks ? ('На новом сайте найдено несколько аккаунтов с одинаковым идентификатором: '.implode(', ',$checks).'. Требуется ручное объединение.') : '';
    }

    /** Public read-only duplicate lookup used by the smart remote bridge. */
    public function find_external_user($mapped, $identityMode = '') {
        $mapped = $this->normalize_mapped((array)$mapped);
        if (sanitize_key((string)$identityMode) === 'name_email') { return $this->find_user_name_email($mapped); }
        return $this->find_user($mapped);
    }

    private function unique_login($mapped) {
        $base = '';
        if (!empty($mapped['user_login'])) { $base = sanitize_user($mapped['user_login'], true); }
        if (!$base && !empty($mapped['email'])) { $base = sanitize_user(strtok($mapped['email'], '@'), true); }
        if (!$base && !empty($mapped['phone'])) { $base = 'member_' . preg_replace('/\D+/', '', $mapped['phone']); }
        if (!$base && !empty($mapped['iin'])) { $base = 'member_' . $mapped['iin']; }
        if (!$base && !empty($mapped['legacy_user_id'])) { $base = 'legacy_' . sanitize_user($mapped['legacy_user_id'], true); }
        if (!$base) { $base = 'legacy_member'; }
        $login = $base; $i = 1;
        while (username_exists($login)) { $login = $base . '_' . $i++; }
        return $login;
    }

    private function create_user($mapped, $identityMode = '') {
        $display = trim((string)($mapped['full_name'] ?? ''));
        if (!$display) { $display = $mapped['email'] ?? ($mapped['phone'] ?? 'Участник профсоюза'); }
        $originalEmail = (!empty($mapped['email']) && is_email($mapped['email'])) ? strtolower(trim((string)$mapped['email'])) : '';
        $email = ($originalEmail && !email_exists($originalEmail)) ? $originalEmail : '';
        $uid = wp_insert_user([
            'user_login'=>$this->unique_login($mapped),
            'user_pass'=>wp_generate_password(32, true, true),
            'user_email'=>$email,
            'display_name'=>$display,
            'first_name'=>$mapped['first_name'] ?? '',
            'last_name'=>$mapped['last_name'] ?? '',
            'role'=>'subscriber',
            'user_registered'=>$this->mysql_date($mapped['registration_date'] ?? '') ?: current_time('mysql'),
        ]);
        if (!is_wp_error($uid) && $identityMode === 'name_email') {
            if ($originalEmail) { update_user_meta((int)$uid,'zau_legacy_original_email',$originalEmail); }
            $key = $this->name_email_identity_key($mapped);
            if ($key) { update_user_meta((int)$uid,'zau_legacy_name_email_key',$key); }
            if ($originalEmail && !$email) {
                update_user_meta((int)$uid,'zau_legacy_shared_email',1);
            }
        }
        return $uid;
    }

    private function mysql_date($value) {
        if (!$value) { return ''; }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return $value; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return $value . ' 00:00:00'; }
        $ts = strtotime($value);
        return $ts ? wp_date('Y-m-d H:i:s', $ts) : '';
    }

    private function set_meta($userId, $key, $value, $overwrite) {
        if ($value === '' || $value === null) { return; }
        $old = get_user_meta($userId, $key, true);
        if ($overwrite || $old === '' || $old === null) { update_user_meta($userId, $key, $value); }
    }

    private function update_user($userId, $mapped, $overwrite, $defaultStatus) {
        $user = get_user_by('id', $userId);
        if (!$user) { return new WP_Error('user_missing', 'Пользователь не найден после создания.'); }
        $update = ['ID'=>$userId];
        foreach (['first_name','last_name'] as $field) {
            if (!empty($mapped[$field]) && ($overwrite || empty($user->$field))) { $update[$field] = $mapped[$field]; }
        }
        if (!empty($mapped['full_name']) && ($overwrite || !$user->display_name || $user->display_name === $user->user_login)) { $update['display_name'] = $mapped['full_name']; }
        if (!empty($mapped['email']) && is_email($mapped['email'])) {
            $owner = email_exists($mapped['email']);
            if ((!$owner || (int)$owner === $userId) && ($overwrite || !$user->user_email)) { $update['user_email'] = $mapped['email']; }
        }
        if (count($update) > 1) {
            $result = wp_update_user($update);
            if (is_wp_error($result)) { return $result; }
        }
        $profileMap = [
            'iin'=>'iin','birth_date'=>'birth_date','address'=>'address','position'=>'position','department'=>'department',
            'organization'=>'organization','organization_bin'=>'organization_bin','organization_director'=>'organization_director',
            'organization_address'=>'organization_address','branch_name'=>'branch_name','middle_name'=>'middle_name',
            'application_no'=>'legacy_application_no','pdf_url'=>'legacy_pdf_url','signature_url'=>'legacy_signature_url','notes'=>'legacy_notes'
        ];
        if (!empty($mapped['phone'])) { $this->set_meta($userId, 'zau_phone', $mapped['phone'], $overwrite); }
        if (!empty($mapped['legacy_user_id'])) { $this->set_meta($userId, 'zau_legacy_user_id', $mapped['legacy_user_id'], false); }
        foreach ($profileMap as $source=>$target) {
            if (!empty($mapped[$source])) { $this->set_meta($userId, 'zau_profile_' . $target, $mapped[$source], $overwrite); }
        }
        if (!empty($mapped['iin'])) { $this->set_meta($userId, 'zau_legacy_iin', $mapped['iin'], false); }
        $this->assign_organization($userId, $mapped, $overwrite);
        $this->assign_branch($userId, $mapped, $overwrite);
        $existingStatus = get_user_meta($userId, 'zau_member_status', true);
        if (!$existingStatus || $overwrite) { update_user_meta($userId, 'zau_member_status', $defaultStatus ?: 'Заявление подано'); }
        $this->update_member_card($userId, $mapped, $overwrite);
        update_user_meta($userId, 'zau_imported_from_legacy_site', 1);
        update_user_meta($userId, 'zau_legacy_imported_at', current_time('mysql'));
        return true;
    }

    private function assign_organization($userId, $mapped, $overwrite) {
        global $wpdb;
        $bin = preg_replace('/\D+/', '', (string)($mapped['organization_bin'] ?? ''));
        $name = trim((string)($mapped['organization'] ?? ''));
        if (!$bin && !$name) { return; }
        $org = null;
        if ($bin) { $org = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE bin=%s LIMIT 1", $bin)); }
        if (!$org && $name) { $org = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE name=%s LIMIT 1", $name)); }
        if (!$org && $name) {
            // The old site often stored the organization name without a BIN. The
            // organizations table accepts NULL BIN values from 2.18.1, so such
            // records can still be linked instead of leaving every member unassigned.
            if ($bin) {
                $wpdb->insert($this->orgs_table, [
                    'bin'=>$bin,'name'=>$name,'director'=>$mapped['organization_director'] ?? '',
                    'address'=>$mapped['organization_address'] ?? '','region'=>'','source'=>'legacy_import','updated_at'=>current_time('mysql')
                ]);
            } else {
                $existingByName = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE name=%s LIMIT 1", $name));
                if ($existingByName) { $org = $existingByName; }
                else {
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$this->orgs_table} (bin,name,director,address,region,source,updated_at) VALUES (NULL,%s,%s,%s,'','legacy_import_name_only',%s)",
                        $name,(string)($mapped['organization_director'] ?? ''),(string)($mapped['organization_address'] ?? ''),current_time('mysql')
                    ));
                }
            }
            if (!$org && $wpdb->insert_id) { $org = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE id=%d", $wpdb->insert_id)); }
        }
        if ($org) {
            $this->set_meta($userId, 'zau_organization_id', (int)$org->id, $overwrite);
            if (!empty($org->bin)) { $this->set_meta($userId, 'zau_organization_bin', (string)$org->bin, $overwrite); }
            $this->set_meta($userId, 'zau_organization_name', (string)$org->name, $overwrite);
        } else {
            if ($bin) { $this->set_meta($userId, 'zau_organization_bin', $bin, $overwrite); }
            if ($name) { $this->set_meta($userId, 'zau_organization_name', $name, $overwrite); }
        }
    }

    private function assign_branch($userId, $mapped, $overwrite) {
        global $wpdb;
        $name = trim((string)($mapped['branch_name'] ?? ''));
        if ($name === '') { return; }
        $norm = $this->normalize_label($name);
        $rows = $wpdb->get_results("SELECT id,name,region FROM {$this->branches_table} WHERE active=1 ORDER BY sort_order ASC,id ASC");
        $match = null;
        foreach ((array)$rows as $row) {
            $rowNorm = $this->normalize_label($row->name);
            $regionNorm = $this->normalize_label($row->region);
            if ($norm === $rowNorm || ($regionNorm && $norm === $regionNorm) || strpos($rowNorm, $norm) !== false || ($regionNorm && strpos($norm, $regionNorm) !== false)) { $match = $row; break; }
        }
        $this->set_meta($userId, 'zau_profile_branch_name', $name, $overwrite);
        if ($match) {
            $this->set_meta($userId, 'zau_profile_branch_id', (int)$match->id, $overwrite);
            $this->set_meta($userId, 'zau_profile_branch_name', (string)$match->name, $overwrite);
        }
    }

    private function update_member_card($userId, $mapped, $overwrite) {
        $card = json_decode((string)get_user_meta($userId, 'zau_member_card_data', true), true);
        if (!is_array($card)) { $card = []; }
        $fields = ['iin','birth_date','address','position','department'];
        foreach ($fields as $field) {
            if (!empty($mapped[$field]) && ($overwrite || empty($card[$field]))) { $card[$field] = $mapped[$field]; }
        }
        if (!empty($mapped['middle_name']) && ($overwrite || empty($card['middle_name']))) { $card['middle_name'] = $mapped['middle_name']; }
        update_user_meta($userId, 'zau_member_card_data', wp_json_encode($card, JSON_UNESCAPED_UNICODE));
        if (!get_user_meta($userId, 'zau_member_card_review_status', true)) { update_user_meta($userId, 'zau_member_card_review_status', 'pending'); }
    }

    private function ensure_legacy_form() {
        global $wpdb;
        $slug = 'legacy-external-import';
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->forms_table} WHERE slug=%s LIMIT 1", $slug));
        if ($id) { return $id; }
        $now = current_time('mysql');
        $wpdb->insert($this->forms_table, [
            'name'=>'Архивные заявления со старого сайта',
            'slug'=>$slug,
            'description'=>'Заявления, перенесённые универсальным CSV-импортом.',
            'fields_json'=>'[]',
            'template_ids_json'=>'[]',
            'require_auth'=>1,
            'active'=>0,
            'created_by'=>get_current_user_id(),
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
        return (int)$wpdb->insert_id;
    }

    private function create_submission($userId, $mapped, $legacy, $submissionStatus = 'submitted', $existingSubmissionId = 0) {
        global $wpdb;
        $formId = $this->ensure_legacy_form();
        if (!$formId) { return new WP_Error('legacy_form', 'Не удалось создать архивную форму.'); }
        $user = get_user_by('id', $userId);
        $data = $mapped;
        $data['full_name'] = $mapped['full_name'] ?? ($user ? $user->display_name : '');
        $data['email'] = $mapped['email'] ?? ($user ? $user->user_email : '');
        $data['phone'] = $mapped['phone'] ?? get_user_meta($userId, 'zau_phone', true);
        $data['member_status'] = get_user_meta($userId, 'zau_member_status', true) ?: 'Заявление подано';
        $data['legacy_source'] = !empty($legacy['source_site']) ? 'remote_bridge' : 'external_csv';
        $data['legacy_imported_at'] = current_time('mysql');
        $data['legacy_fields'] = $legacy;
        if (!empty($legacy['archive_summary'])) { $data['legacy_archive_summary'] = $legacy['archive_summary']; }
        if (!empty($legacy['form_id'])) { $data['legacy_form_id'] = (int)$legacy['form_id']; }
        if (!empty($legacy['form_title'])) { $data['legacy_form_title'] = (string)$legacy['form_title']; }
        if (!empty($mapped['pdf_url'])) { $data['legacy_pdf_url'] = $mapped['pdf_url']; }
        if (!empty($mapped['signature_url'])) { $data['signature_url'] = $mapped['signature_url']; }
        $createdAt = $this->mysql_date($mapped['submission_date'] ?? '') ?: current_time('mysql');
        $signatures = !empty($mapped['signature_url']) ? ['signature'=>$mapped['signature_url']] : [];
        $existingSubmissionId = absint($existingSubmissionId);
        if ($existingSubmissionId) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d LIMIT 1", $existingSubmissionId));
            if (!$old || (int)$old->user_id !== (int)$userId) {
                return new WP_Error('submission_revision_mismatch', 'Нельзя обновить старую заявку: её владелец не совпадает с найденным аккаунтом.');
            }
            $oldData = json_decode((string)$old->data_json, true);
            if (!is_array($oldData)) { $oldData = []; }
            $history = is_array($oldData['legacy_revision_history'] ?? null) ? $oldData['legacy_revision_history'] : [];
            $history[] = [
                'replaced_at'=>current_time('mysql'),
                'previous_created_at'=>(string)$old->created_at,
                'previous_legacy_entry_id'=>(string)($oldData['legacy_entry_id'] ?? ''),
                'reason'=>'Источник на старом сайте был изменён после предыдущего переноса.',
            ];
            if (count($history) > 20) { $history = array_slice($history, -20); }
            $data['legacy_revision_history'] = $history;
            $ok = $wpdb->update($this->submissions_table, [
                'form_id'=>$formId,
                'status'=>sanitize_key($submissionStatus ?: 'submitted'),
                'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),
                'ip'=>'legacy-import',
                'created_at'=>$createdAt,
                'updated_at'=>current_time('mysql'),
            ], ['id'=>$existingSubmissionId,'user_id'=>$userId]);
            if ($ok === false) { return new WP_Error('submission_update', 'Не удалось обновить ранее перенесённое заявление.'); }
            return $existingSubmissionId;
        }
        $wpdb->insert($this->submissions_table, [
            'form_id'=>$formId,
            'user_id'=>$userId,
            'status'=>sanitize_key($submissionStatus ?: 'submitted'),
            'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),
            'ip'=>'legacy-import',
            'created_at'=>$createdAt,
            'updated_at'=>current_time('mysql'),
        ]);
        if (!$wpdb->insert_id) { return new WP_Error('submission_insert', 'Не удалось сохранить архивное заявление.'); }
        return (int)$wpdb->insert_id;
    }

    /**
     * Imports one record received from the protected old-site bridge.
     * This method intentionally reuses the same duplicate detection and profile mapping
     * as the CSV importer, so both migration methods produce identical new-cabinet data.
     */
    public function import_external_record($record, $options = []) {
        global $wpdb;
        $record = is_array($record) ? $record : [];
        $options = wp_parse_args((array)$options, [
            'mode' => (($record['type'] ?? '') === 'application' ? 'applications' : 'accounts'),
            'dry_run' => 0,
            'create_users' => 1,
            'update_existing' => 1,
            'overwrite' => 0,
            'default_status' => 'Состоит в профсоюзе',
            'preserve_password' => 0,
            'source_site' => '',
            'import_token' => 'remote-bridge',
            'forced_user_id' => 0,
            'existing_submission_id' => 0,
            'submission_status' => 'submitted',
            'legacy_latest_profile' => 0,
            'identity_mode' => '',
        ]);

        $mapped = (array)($record['canonical'] ?? []);
        if (!empty($record['legacy_user_id'])) { $mapped['legacy_user_id'] = (string)$record['legacy_user_id']; }
        if (!empty($record['legacy_entry_id'])) { $mapped['legacy_entry_id'] = (string)$record['legacy_entry_id']; }
        if (!empty($record['password_hash'])) { $mapped['password_hash'] = (string)$record['password_hash']; }
        $mapped = $this->normalize_mapped($mapped);

        $legacy = [];
        if (!empty($record['raw_meta']) && is_array($record['raw_meta'])) { $legacy['user_meta'] = $record['raw_meta']; }
        if (!empty($record['fields']) && is_array($record['fields'])) { $legacy['application_fields'] = $record['fields']; }
        if (!empty($record['file_refs']) && is_array($record['file_refs'])) { $legacy['file_refs'] = $record['file_refs']; }
        foreach (['form_id','form_title','source_status'] as $key) {
            if (isset($record[$key]) && $record[$key] !== '') { $legacy[$key] = $record[$key]; }
        }
        if (!empty($record['legacy_archive_summary']) && is_array($record['legacy_archive_summary'])) {
            $legacy['archive_summary'] = $record['legacy_archive_summary'];
        }
        $legacy['source_site'] = esc_url_raw((string)$options['source_site']);
        $legacy['source_type'] = sanitize_key((string)($record['type'] ?? 'record'));

        if (empty($mapped['pdf_url']) && !empty($record['file_refs'])) {
            foreach ((array)$record['file_refs'] as $file) {
                if (($file['type'] ?? '') === 'pdf' && !empty($file['url'])) { $mapped['pdf_url'] = (string)$file['url']; break; }
            }
        }
        if (empty($mapped['signature_url']) && !empty($record['file_refs'])) {
            foreach ((array)$record['file_refs'] as $file) {
                $name = (string)($file['basename'] ?? '');
                if (($file['type'] ?? '') === 'image' && preg_match('/sign|signature|podpis|подпис/ui', $name) && !empty($file['url'])) {
                    $mapped['signature_url'] = (string)$file['url']; break;
                }
            }
        }

        $sourceVersion = (string)($record['source_record_hash'] ?? '');
        if ($sourceVersion === '') {
            $sourceVersion = hash('sha256', wp_json_encode([
                $record['canonical'] ?? [],
                $record['fields'] ?? [],
                $record['raw_meta'] ?? [],
                $record['source_modified_at'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $sourceIdentity = implode('|', [
            (string)$options['source_site'],
            (string)($record['type'] ?? ''),
            (string)($mapped['legacy_user_id'] ?? ''),
            (string)($mapped['legacy_entry_id'] ?? ''),
            (string)($record['form_id'] ?? ''),
            (string)($record['source_person_key'] ?? ''),
            $sourceVersion,
        ]);
        if (trim(str_replace('|', '', $sourceIdentity)) === '') {
            $sourceIdentity .= '|' . wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $sourceHash = hash('sha256', 'zau-bridge|' . $sourceIdentity);
        $existingMap = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->map_table} WHERE source_hash=%s", $sourceHash));
        if ($existingMap && empty($options['dry_run'])) {
            return [
                'kind'=>'duplicate','message'=>'Запись уже перенесена ранее.',
                'user_id'=>(int)$existingMap->target_user_id,
                'submission_id'=>(int)$existingMap->target_submission_id,
                'mapped'=>$mapped,
            ];
        }

        $forcedUserId = absint($options['forced_user_id']);
        $identityMode = sanitize_key((string)($options['identity_mode'] ?? ''));
        if (!$forcedUserId && $identityMode !== 'name_email') {
            $identityConflict = $this->existing_identity_conflict($mapped);
            if ($identityConflict) { return ['kind'=>'error','message'=>$identityConflict,'mapped'=>$mapped]; }
        }
        $user = $forcedUserId ? get_user_by('id', $forcedUserId) : ($identityMode === 'name_email' ? $this->find_user_name_email($mapped) : $this->find_user($mapped));
        if (is_wp_error($user)) { return ['kind'=>'error','message'=>$user->get_error_message(),'mapped'=>$mapped]; }
        if ($forcedUserId && !$user) {
            return ['kind'=>'error','message'=>'Ранее связанный аккаунт нового сайта больше не существует.','mapped'=>$mapped];
        }
        $userId = $user ? (int)$user->ID : 0;
        $mode = in_array($options['mode'], ['accounts','applications','both'], true) ? $options['mode'] : 'accounts';
        $willCreate = !$userId && !empty($options['create_users']);
        if (!$userId && !$willCreate) {
            return ['kind'=>'skipped','message'=>'Аккаунт не найден, а создание новых отключено.','mapped'=>$mapped];
        }
        if (!$userId && $willCreate && !$this->has_identity($mapped)) {
            return ['kind'=>'error','message'=>'Недостаточно данных для создания аккаунта.','mapped'=>$mapped];
        }

        if (!empty($options['dry_run'])) {
            return [
                'kind'=>$userId ? 'would_update' : 'would_create',
                'message'=>$userId ? 'Будет использован существующий аккаунт.' : 'Будет создан новый аккаунт.',
                'user_id'=>$userId,
                'would_submit'=>$mode !== 'accounts' ? 1 : 0,
                'mapped'=>$mapped,
            ];
        }

        $createdNow = false;
        if (!$userId) {
            $created = $this->create_user($mapped, $identityMode);
            if (is_wp_error($created)) { return ['kind'=>'error','message'=>$created->get_error_message(),'mapped'=>$mapped]; }
            $userId = (int)$created;
            $createdNow = true;
            update_user_meta($userId, 'zau_legacy_bridge_created', 1);
            update_user_meta($userId, 'zau_legacy_bridge_created_at', current_time('mysql'));
        }

        if (!empty($options['update_existing']) || $createdNow) {
            // The profile was already merged from the newest legacy rows before this call.
            // Without the explicit overwrite switch, fill only empty values. This prevents
            // a later migration rerun from erasing changes made manually in the new cabinet.
            $overwriteProfile = !empty($options['overwrite']);
            $updated = $this->update_user($userId, $mapped, $overwriteProfile, (string)$options['default_status']);
            if (is_wp_error($updated)) { return ['kind'=>'error','message'=>$updated->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
            if ($identityMode === 'name_email') {
                $identityKey = $this->name_email_identity_key($mapped);
                if ($identityKey) { update_user_meta($userId,'zau_legacy_name_email_key',$identityKey); }
                if (!empty($mapped['email']) && is_email($mapped['email'])) { update_user_meta($userId,'zau_legacy_original_email',strtolower(trim((string)$mapped['email']))); }
            }
            if (!empty($mapped['_legacy_latest_at'])) {
                $incomingAt = sanitize_text_field($mapped['_legacy_latest_at']);
                $previousAt = (string)get_user_meta($userId, 'zau_legacy_profile_latest_at', true);
                if (!$previousAt || $incomingAt > $previousAt) { update_user_meta($userId, 'zau_legacy_profile_latest_at', $incomingAt); }
            }
        }

        if ($createdNow && !empty($options['preserve_password']) && !empty($mapped['password_hash']) && $this->valid_wordpress_password_hash($mapped['password_hash'])) {
            $wpdb->update($wpdb->users, ['user_pass'=>(string)$mapped['password_hash']], ['ID'=>$userId], ['%s'], ['%d']);
            clean_user_cache($userId);
            update_user_meta($userId, 'zau_legacy_password_preserved', 1);
        }

        $submissionId = 0;
        $updatedExistingSubmission = false;
        if ($mode !== 'accounts') {
            $existingSubmissionId = absint($options['existing_submission_id']);
            $submissionId = $this->create_submission($userId, $mapped, $legacy, (string)$options['submission_status'], $existingSubmissionId);
            if (is_wp_error($submissionId)) { return ['kind'=>'error','message'=>$submissionId->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
            $updatedExistingSubmission = $existingSubmissionId && (int)$submissionId === $existingSubmissionId;
        }

        $now = current_time('mysql');
        $wpdb->replace($this->map_table, [
            'import_token'=>sanitize_text_field((string)$options['import_token']),
            'source_hash'=>$sourceHash,
            'row_number'=>0,
            'legacy_user_id'=>sanitize_text_field($mapped['legacy_user_id'] ?? ''),
            'legacy_entry_id'=>sanitize_text_field($mapped['legacy_entry_id'] ?? ''),
            'target_user_id'=>$userId,
            'target_submission_id'=>(int)$submissionId,
            'status'=>'imported',
            'message'=>'remote bridge',
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);

        $resultKind = $updatedExistingSubmission ? 'updated_submission' : ($createdNow ? 'created' : (!empty($options['update_existing']) ? 'updated' : 'existing'));
        return [
            'kind'=>$resultKind,
            'message'=>$mode === 'accounts' ? 'Аккаунт сопоставлен; заполнены только пустые поля либо явно разрешённая замена.' : ($updatedExistingSubmission ? 'Ранее перенесённое заявление обновлено без создания дубля.' : 'Актуальное заявление перенесено.'),
            'user_id'=>$userId,
            'submission_id'=>(int)$submissionId,
            'mapped'=>$mapped,
        ];
    }

    private function valid_wordpress_password_hash($hash) {
        $hash = (string)$hash;
        if (strlen($hash) < 20 || strlen($hash) > 255) { return false; }
        if (preg_match('/^\$P\$[\.\/0-9A-Za-z]{31}$/', $hash)) { return true; }
        if (preg_match('/^\$H\$[\.\/0-9A-Za-z]{31}$/', $hash)) { return true; }
        if (preg_match('/^\$2[ayb]\$\d{2}\$[\.\/0-9A-Za-z]{53}$/', $hash)) { return true; }
        if (strpos($hash, '$wp$') === 0 || strpos($hash, '$argon2') === 0) { return true; }
        return (bool)preg_match('/^[a-f0-9]{32}$/i', $hash);
    }

    private function apply_result(&$job, $result) {
        $kind = $result['kind'] ?? 'error';
        switch ($kind) {
            case 'created': $job['stats']['created_users']++; if (!empty($result['submission_id'])) $job['stats']['submissions']++; break;
            case 'updated': $job['stats']['updated_users']++; if (!empty($result['submission_id'])) $job['stats']['submissions']++; break;
            case 'existing': $job['stats']['existing_users']++; if (!empty($result['submission_id'])) $job['stats']['submissions']++; break;
            case 'duplicate': $job['stats']['duplicates']++; break;
            case 'skipped': $job['stats']['skipped']++; break;
            case 'would_create': $job['stats']['would_create']++; if (!empty($result['would_submit'])) $job['stats']['would_submit']++; break;
            case 'would_update': $job['stats']['would_update']++; if (!empty($result['would_submit'])) $job['stats']['would_submit']++; break;
            default: $job['stats']['errors']++; break;
        }
        $job['report'][] = [
            'row'=>(int)($result['row'] ?? 0),
            'result'=>$kind,
            'message'=>(string)($result['message'] ?? ''),
            'user_id'=>(int)($result['user_id'] ?? 0),
            'submission_id'=>(int)($result['submission_id'] ?? 0),
            'legacy_user_id'=>(string)($result['mapped']['legacy_user_id'] ?? ''),
            'legacy_entry_id'=>(string)($result['mapped']['legacy_entry_id'] ?? ''),
            'full_name'=>(string)($result['mapped']['full_name'] ?? ''),
            'email'=>(string)($result['mapped']['email'] ?? ''),
            'phone'=>(string)($result['mapped']['phone'] ?? ''),
        ];
        if (in_array($kind, ['error','skipped'], true)) { $job['log'][] = 'Строка ' . ($result['row'] ?? '?') . ': ' . ($result['message'] ?? 'ошибка'); }
    }

    private function public_job($job) {
        return [
            'status'=>$job['status'] ?? 'idle',
            'processed'=>(int)($job['processed'] ?? 0),
            'total'=>(int)($job['total'] ?? 0),
            'stats'=>$job['stats'] ?? [],
            'log'=>array_slice((array)($job['log'] ?? []), -20),
            'dry_run'=>!empty($job['options']['dry_run']) ? 1 : 0,
            'finished_at'=>$job['finished_at'] ?? '',
        ];
    }

    public function ajax_reset() {
        $this->require_access();
        $upload = (array)get_option($this->upload_option_key(), []);
        if (!empty($upload['path']) && is_file($upload['path'])) { @unlink($upload['path']); }
        delete_option($this->upload_option_key());
        delete_option($this->job_option_key());
        wp_send_json_success(['message'=>'Загрузка и прогресс сброшены. Импортированные пользователи и заявления не удалены.']);
    }

    public function download_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $job = (array)get_option($this->job_option_key(), []);
        $rows = (array)($job['report'] ?? []);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-import-report-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Строка','Результат','Сообщение','Новый User ID','Новая заявка ID','Старый User ID','Старый Entry ID','ФИО','Email','Телефон'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [$row['row'],$row['result'],$row['message'],$row['user_id'],$row['submission_id'],$row['legacy_user_id'],$row['legacy_entry_id'],$row['full_name'],$row['email'],$row['phone']], ';');
        }
        fclose($out);
        exit;
    }

    public function page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        $job = (array)get_option($this->job_option_key(), []);
        ?>
        <div class="wrap zau-universal-wrap">
            <h1>Перенос аккаунтов и старых заявлений</h1>
            <div class="notice notice-warning inline"><p><strong>Перед импортом сделайте резервную копию базы данных.</strong> Импортёр не удаляет пользователей и не заменяет существующие данные без отдельной галочки.</p></div>

            <section class="zau-ui-card">
                <h2>1. Подготовьте CSV</h2>
                <p>Со старого сайта выгрузите пользователей или заявления в CSV UTF-8. Можно загружать два файла по очереди: сначала аккаунты, затем заявления.</p>
                <form id="zau-universal-upload-form" enctype="multipart/form-data">
                    <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                    <button type="submit" class="button button-primary">Загрузить и прочитать CSV</button>
                </form>
                <p class="description">Excel откройте и сохраните как «CSV UTF-8». Максимальный размер — 50 МБ.</p>
            </section>

            <section class="zau-ui-card" data-zau-mapping hidden>
                <h2>2. Сопоставьте колонки</h2>
                <p data-zau-file-summary></p>
                <div class="zau-ui-table-wrap"><table class="widefat striped" data-zau-mapping-table><thead><tr><th>Колонка старого файла</th><th>Пример</th><th>Поле нового кабинета</th></tr></thead><tbody></tbody></table></div>

                <h3>Режим переноса</h3>
                <div class="zau-ui-options">
                    <label><input type="radio" name="mode" value="both" checked> Аккаунты и заявления из одного файла</label>
                    <label><input type="radio" name="mode" value="accounts"> Только аккаунты</label>
                    <label><input type="radio" name="mode" value="applications"> Только заявления — привязать к уже созданным аккаунтам</label>
                </div>
                <div class="zau-ui-options">
                    <label><input type="checkbox" name="create_users" value="1" checked> Создавать отсутствующие аккаунты</label>
                    <label><input type="checkbox" name="update_existing" value="1" checked> Дополнять существующие аккаунты</label>
                    <label><input type="checkbox" name="overwrite" value="1"> Заменять уже заполненные поля новыми значениями</label>
                </div>
                <div class="zau-ui-grid">
                    <label>Статус для перенесённых участников
                        <select name="default_status">
                            <?php foreach (['Заявление подано','На рассмотрении','Состоит в профсоюзе','Регистрация не завершена'] as $status): ?>
                                <option <?php selected($status, 'Заявление подано'); ?>><?php echo esc_html($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Строк в одной партии
                        <input type="number" name="batch_size" min="10" max="200" step="10" value="50">
                    </label>
                </div>
                <div class="zau-ui-actions">
                    <button type="button" class="button button-secondary button-hero" data-zau-start="dry">Только проверить</button>
                    <button type="button" class="button button-primary button-hero" data-zau-start="import">Начать перенос</button>
                    <button type="button" class="button" data-zau-reset>Сбросить загрузку</button>
                </div>
            </section>

            <section class="zau-ui-card" data-zau-progress <?php echo $job ? '' : 'hidden'; ?>>
                <h2>3. Ход переноса</h2>
                <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                <p data-zau-progress-text><?php echo $job ? esc_html(($job['processed'] ?? 0) . ' из ' . ($job['total'] ?? 0)) : 'Ожидание'; ?></p>
                <div class="zau-ui-stats" data-zau-stats></div>
                <pre data-zau-log><?php echo $job ? esc_html(implode("\n", (array)($job['log'] ?? []))) : ''; ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_universal_download_report'), self::NONCE)); ?>">Скачать отчёт CSV</a></p>
            </section>

            <section class="zau-ui-card">
                <h2>Что переносится</h2>
                <p>Аккаунт WordPress, ФИО, email, телефон, ИИН, дата рождения, адрес, организация, БИН, должность, подразделение, филиал и дата регистрации. Для каждой строки заявления создаётся архивная заявка, а все исходные колонки сохраняются внутри неё в блоке <code>legacy_fields</code>.</p>
                <p><strong>Пароли на первом этапе не переносятся.</strong> Старые пользователи смогут войти по коду или восстановить PIN. После образца старой базы можно отдельно добавить безопасный перенос совместимых WordPress-хешей паролей.</p>
            </section>
        </div>
        <?php
    }
}

ZAU_Universal_Import::instance();
