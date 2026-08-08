<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Единственный инструмент переноса данных со старого сайта (uchet.zdravunion.kz:
 * Ultimate Member + форма заявления). Заменяет собой пять прежних модулей
 * (universal-import, remote-bridge-import, remote-smart-dedup,
 * remote-profile-repair, legacy-migration).
 *
 * Принцип надёжности: сопоставление ведётся ТОЛЬКО по точным старым ID
 * (ID пользователя Ultimate Member и ID записи формы заявления), без
 * нечёткого угадывания по ФИО/email/телефону. Тот же старый ID, что стоит
 * в конце имени PDF-файла заявления и личной карточки, используется, чтобы
 * безошибочно приложить готовый PDF к нужному аккаунту/заявлению.
 */
final class ZAU_Legacy_Import {
    const VERSION = '3.3.0';
    const DB_VERSION = '3.3.0';
    const OPT_DB_VERSION = 'zau_legacy_import_db_version';
    const NONCE = 'zau_legacy_import_nonce';

    private static $instance = null;
    private $map_table;
    private $submissions_table;
    private $forms_table;
    private $orgs_table;
    private $branches_table;
    private $docs_table;
    private $templates_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->map_table = $wpdb->prefix . 'zau_legacy_import';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->orgs_table = $wpdb->prefix . 'zau_union_organizations';
        $this->branches_table = $wpdb->prefix . 'zau_union_branches';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';
        $this->templates_table = $wpdb->prefix . 'zau_cert_templates';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 38);
        add_action('admin_menu', [$this, 'admin_menu'], 38);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('wp_ajax_zau_legacy_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_zau_legacy_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_legacy_process', [$this, 'ajax_process']);
        add_action('wp_ajax_zau_legacy_reset', [$this, 'ajax_reset']);
        add_action('wp_ajax_zau_legacy_scan_pdfs', [$this, 'ajax_scan_pdfs']);
        add_action('wp_ajax_zau_legacy_attach_pdfs', [$this, 'ajax_attach_pdfs']);
        add_action('admin_post_zau_legacy_download_report', [$this, 'download_report']);
        add_action('wp_ajax_zau_legacy_remap_scan', [$this, 'ajax_remap_scan']);
        add_action('wp_ajax_zau_legacy_remap_start', [$this, 'ajax_remap_start']);
        add_action('wp_ajax_zau_legacy_remap_process', [$this, 'ajax_remap_process']);
        add_action('wp_ajax_zau_legacy_remap_reset', [$this, 'ajax_remap_reset']);
        add_action('admin_post_zau_legacy_remap_report', [$this, 'download_remap_report']);

        add_action('wp_ajax_zau_legacy_bridge_save_settings', [$this, 'ajax_bridge_save_settings']);
        add_action('wp_ajax_zau_legacy_bridge_test', [$this, 'ajax_bridge_test']);
        add_action('wp_ajax_zau_legacy_bridge_list_forms', [$this, 'ajax_bridge_list_forms']);
        add_action('wp_ajax_zau_legacy_bridge_save_form_map', [$this, 'ajax_bridge_save_form_map']);
        add_action('wp_ajax_zau_legacy_bridge_start', [$this, 'ajax_bridge_start']);
        add_action('wp_ajax_zau_legacy_bridge_process', [$this, 'ajax_bridge_process']);
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            $this->cleanup_old_modules();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    public function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->map_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(20) NOT NULL,
            legacy_id varchar(100) NOT NULL,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'imported',
            message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY kind_legacy (kind,legacy_id),
            KEY target_user_id (target_user_id),
            KEY target_submission_id (target_submission_id)
        ) $charset;");
    }

    /**
     * Удаляет таблицы и настройки пяти прежних модулей импорта. Сами перенесённые
     * пользователи, заявления и документы не затрагиваются — эти модули хранили
     * только служебные карты сопоставления и очереди задач.
     */
    private function cleanup_old_modules() {
        global $wpdb;
        foreach ([
            'zau_external_import_map',
            'zau_remote_legacy_people',
            'zau_remote_legacy_records',
            'zau_remote_profile_repair_log',
            'zau_legacy_migration',
        ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
        foreach ([
            'zau_universal_import_db_version',
            'zau_remote_bridge_settings',
            'zau_remote_bridge_lock_format',
            'zau_remote_bridge_db_version',
            'zau_remote_smart_dedup_db_version',
            'zau_remote_profile_repair_db_version',
            'zau_legacy_migration_db_version',
        ] as $option) {
            delete_option($option);
        }
    }

    public function admin_menu() {
        add_submenu_page(
            'zau-certificates',
            'Перенос со старого сайта',
            'Перенос со старого сайта',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-legacy-import',
            [$this, 'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-legacy-import') === false) { return; }
        // Файлы меняются чаще, чем эта константа версии плагина-модуля — берём mtime, чтобы
        // браузер не продолжал отдавать старый JS/CSS из кэша после обновления файлов на сервере.
        $cssPath = __DIR__ . '/../assets/css/legacy-import.css';
        $jsPath = __DIR__ . '/../assets/js/legacy-import.js';
        $cssVersion = self::VERSION . (is_file($cssPath) ? '.' . (string)filemtime($cssPath) : '');
        $jsVersion = self::VERSION . (is_file($jsPath) ? '.' . (string)filemtime($jsPath) : '');
        wp_enqueue_style('zau-legacy-import', plugins_url('../assets/css/legacy-import.css', __FILE__), [], $cssVersion);
        wp_enqueue_script('zau-legacy-import', plugins_url('../assets/js/legacy-import.js', __FILE__), ['jquery'], $jsVersion, true);
        wp_localize_script('zau-legacy-import', 'ZAULegacyImport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'reportUrl' => wp_nonce_url(admin_url('admin-post.php?action=zau_legacy_download_report'), self::NONCE),
            'accountFields' => $this->account_fields(),
            'applicationFields' => $this->application_fields(),
            'remapReportUrl' => wp_nonce_url(admin_url('admin-post.php?action=zau_legacy_remap_report'), self::NONCE),
        ]);
    }

    private function can_manage() {
        return current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) || current_user_can('manage_options');
    }

    private function require_access() {
        if (!$this->can_manage()) { wp_send_json_error(['message'=>'Недостаточно прав.'], 403); }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function upload_option_key($scope) { return 'zau_legacy_upload_' . sanitize_key($scope) . '_' . get_current_user_id(); }
    private function job_option_key($scope) { return 'zau_legacy_job_' . sanitize_key($scope) . '_' . get_current_user_id(); }

    private function account_fields() {
        return [
            '' => '— не сопоставлять —',
            'legacy_user_id' => 'ID пользователя Ultimate Member (обязательно)',
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
            'branch_full_details' => 'Полные данные и реквизиты филиала одним текстом (если на старом сайте это было отдельное поле формы)',
            'registration_date' => 'Дата регистрации аккаунта',
            'member_status' => 'Статус участника',
        ];
    }

    private function application_fields() {
        return [
            '' => '— сохранить только в архивных данных —',
            'legacy_entry_id' => 'ID записи заявления (обязательно)',
            'legacy_user_id' => 'ID пользователя Ultimate Member — владельца заявления (обязательно)',
            'full_name' => 'ФИО одной строкой',
            'email' => 'Email',
            'phone' => 'Телефон',
            'organization' => 'Организация / место работы',
            'organization_bin' => 'БИН организации',
            'branch_name' => 'Филиал профсоюза',
            'branch_full_details' => 'Полные данные и реквизиты филиала одним текстом (если на старом сайте это было отдельное поле формы)',
            'submission_date' => 'Дата подачи заявления',
            'status' => 'Статус заявления',
            'document_no' => 'Номер документа (сохранить старую нумерацию)',
            'issue_date' => 'Дата выдачи документа',
            'signature_url' => 'Подпись (ссылка на файл/изображение)',
            'signature2_url' => 'Вторая подпись (ссылка на файл/изображение)',
            'stamp_url' => 'Печать / штамп (ссылка на файл/изображение)',
            'extra1' => 'Доп. поле шаблона 1 (extra1)',
            'extra2' => 'Доп. поле шаблона 2 (extra2)',
            'extra3' => 'Доп. поле шаблона 3 (extra3)',
            'extra4' => 'Доп. поле шаблона 4 (extra4)',
        ];
    }

    private function auto_target($header, $known) {
        $h = $this->normalize_label($header);
        $rules = [
            'legacy_user_id' => ['user id','id пользователя','старый id пользователя','id юзера','um user id','member id'],
            'legacy_entry_id' => ['entry id','id записи','id заявки','id заявления','номер записи'],
            'email' => ['email','e mail','электронная почта','почта'],
            'phone' => ['телефон','мобильный','номер телефона','phone'],
            'full_name' => ['фио','ф и о','полное имя','full name'],
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
            'organization_address' => ['адрес организации','юридический адрес организации'],
            'organization' => ['организация','место работы','предприятие','наименование организации'],
            'branch_full_details' => ['реквизиты филиала','полные данные филиала','данные филиала','филиал реквизиты','branch details','branch requisites'],
            'branch_name' => ['филиал','область','регион профсоюза','филиал профсоюза'],
            'registration_date' => ['дата регистрации','registered','user registered'],
            'submission_date' => ['дата подачи','дата заявления','дата заявки','created at','дата создания'],
            'status' => ['статус'],
            'member_status' => ['статус участника','статус членства'],
            'document_no' => ['номер документа','номер заявления','номер сертификата','document no','application no'],
            'issue_date' => ['дата выдачи','дата документа','issue date'],
            'signature_url' => ['подпись','signature'],
            'signature2_url' => ['вторая подпись','подпись 2','second signature'],
            'stamp_url' => ['печать','штамп','stamp'],
            'extra1' => ['доп поле 1','extra1','дополнительное поле 1'],
            'extra2' => ['доп поле 2','extra2','дополнительное поле 2'],
            'extra3' => ['доп поле 3','extra3','дополнительное поле 3'],
            'extra4' => ['доп поле 4','extra4','дополнительное поле 4'],
        ];
        foreach ($rules as $target => $variants) {
            if (!isset($known[$target])) { continue; }
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

    private function row_is_empty($row) {
        foreach ((array)$row as $value) { if (trim((string)$value) !== '') { return false; } }
        return true;
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

    /** Загрузка и разбор CSV. scope = accounts|applications */
    public function ajax_upload() {
        $this->require_access();
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) { wp_send_json_error(['message'=>'Выберите CSV-файл.'], 400); }
        $file = $_FILES['file'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { wp_send_json_error(['message'=>'Ошибка загрузки файла. Код: '.(int)$file['error']], 400); }
        if ((int)($file['size'] ?? 0) < 2 || (int)$file['size'] > 50 * 1024 * 1024) { wp_send_json_error(['message'=>'Размер CSV должен быть от 2 байт до 50 МБ.'], 400); }
        $name = sanitize_file_name((string)($file['name'] ?? 'import.csv'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'], true)) { wp_send_json_error(['message'=>'Поддерживаются только CSV и TXT. Экспортируйте таблицу как CSV UTF-8.'], 400); }
        $dir = $this->private_dir();
        if (!is_dir($dir) || !is_writable($dir)) { wp_send_json_error(['message'=>'Папка приватного импорта недоступна для записи.'], 500); }
        $token = wp_generate_password(24, false, false);
        $path = trailingslashit($dir) . 'legacy-' . $scope . '-' . get_current_user_id() . '-' . $token . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $path)) { wp_send_json_error(['message'=>'Не удалось сохранить загруженный CSV.'], 500); }
        @chmod($path, 0600);
        $this->convert_to_utf8($path);
        $delimiter = $this->detect_delimiter($path);
        $info = $this->inspect_csv($path, $delimiter);
        if (is_wp_error($info)) { @unlink($path); wp_send_json_error(['message'=>$info->get_error_message()], 400); }
        $known = $scope === 'accounts' ? $this->account_fields() : $this->application_fields();
        $auto = [];
        foreach ($info['headers'] as $i=>$header) { $auto[(string)$i] = $this->auto_target($header, $known); }
        $upload = [
            'scope'=>$scope,
            'path'=>$path,
            'original_name'=>$name,
            'delimiter'=>$delimiter,
            'headers'=>$info['headers'],
            'total'=>(int)$info['total'],
            'data_offset'=>(int)$info['data_offset'],
            'uploaded_at'=>time(),
        ];
        update_option($this->upload_option_key($scope), $upload, false);
        delete_option($this->job_option_key($scope));
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
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        $upload = (array)get_option($this->upload_option_key($scope), []);
        if (!$upload || empty($upload['path']) || !is_file($upload['path'])) { wp_send_json_error(['message'=>'Сначала загрузите CSV-файл.'], 400); }
        $mappingRaw = json_decode((string)wp_unslash($_POST['mapping'] ?? ''), true);
        if (!is_array($mappingRaw)) { wp_send_json_error(['message'=>'Не получено сопоставление колонок.'], 400); }
        $known = $scope === 'accounts' ? $this->account_fields() : $this->application_fields();
        $allowed = array_keys($known);
        $mapping = [];
        foreach ($mappingRaw as $index=>$target) {
            $index = absint($index);
            $target = sanitize_key((string)$target);
            if (in_array($target, $allowed, true) && $target !== '') { $mapping[$index] = $target; }
        }
        $used = array_values($mapping);
        if ($scope === 'accounts' && !in_array('legacy_user_id', $used, true)) {
            wp_send_json_error(['message'=>'Сопоставьте колонку «ID пользователя Ultimate Member» — это обязательный ключ переноса.'], 400);
        }
        if ($scope === 'applications' && (!in_array('legacy_entry_id', $used, true) || !in_array('legacy_user_id', $used, true))) {
            wp_send_json_error(['message'=>'Для заявлений обязательны и «ID записи заявления», и «ID пользователя Ultimate Member» (для привязки к аккаунту).'], 400);
        }
        $formId = 0;
        $templateId = 0;
        if ($scope === 'applications') {
            $formId = absint($_POST['form_id'] ?? 0);
            $templateId = absint($_POST['template_id'] ?? 0);
            global $wpdb;
            if (!$formId || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->forms_table} WHERE id=%d", $formId))) {
                wp_send_json_error(['message'=>'Выберите форму заявления, к которой будут привязаны перенесённые записи.'], 400);
            }
            if ($templateId && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->templates_table} WHERE id=%d", $templateId))) {
                wp_send_json_error(['message'=>'Выбранный PDF-шаблон не найден.'], 400);
            }
        }
        $dryRun = !empty($_POST['dry_run']);
        $options = [
            'dry_run'=>$dryRun ? 1 : 0,
            'overwrite'=>!empty($_POST['overwrite']) ? 1 : 0,
            'default_status'=>sanitize_text_field((string)($_POST['default_status'] ?? 'Заявление подано')),
            'form_id'=>$formId,
            'template_id'=>$templateId,
            'batch_size'=>max(10, min(200, absint($_POST['batch_size'] ?? 50))),
        ];
        $job = [
            'scope'=>$scope,
            'file_path'=>$upload['path'],
            'file_name'=>$upload['original_name'],
            'delimiter'=>$upload['delimiter'],
            'headers'=>$upload['headers'],
            'total'=>(int)$upload['total'],
            'byte_position'=>(int)$upload['data_offset'],
            'processed'=>0,
            'mapping'=>$mapping,
            'options'=>$options,
            'stats'=>['created'=>0,'updated'=>0,'existing'=>0,'linked'=>0,'skipped'=>0,'errors'=>0,'would_create'=>0,'would_update'=>0],
            'report'=>[],
            'log'=>[],
            'status'=>'running',
            'started_at'=>current_time('mysql'),
            'finished_at'=>'',
        ];
        update_option($this->job_option_key($scope), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    public function ajax_process() {
        $this->require_access();
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        $job = (array)get_option($this->job_option_key($scope), []);
        if (!$job || empty($job['file_path']) || !is_file($job['file_path'])) { wp_send_json_error(['message'=>'Задание переноса не найдено.'], 404); }
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
            $result = $scope === 'accounts' ? $this->process_account_row($row, $job) : $this->process_application_row($row, $job);
            $this->apply_result($job, $result);
        }
        $eof = feof($fh);
        fclose($fh);
        if ($eof || (int)$job['processed'] >= (int)$job['total']) {
            $job['status'] = 'finished';
            $job['finished_at'] = current_time('mysql');
            $job['log'][] = 'Перенос завершён: ' . $job['processed'] . ' строк.';
        }
        if (count($job['report']) > 2000) { $job['report'] = array_slice($job['report'], -2000); }
        if (count($job['log']) > 100) { $job['log'] = array_slice($job['log'], -100); }
        update_option($this->job_option_key($scope), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    private function raw_legacy_fields($headers, $row) {
        $legacy = [];
        foreach ($headers as $i=>$header) {
            $value = isset($row[$i]) ? trim((string)$row[$i]) : '';
            if ($value !== '') { $legacy[(string)$header] = $value; }
        }
        return $legacy;
    }

    private function read_mapped_row($row, $job) {
        $headers = (array)$job['headers'];
        $mapping = (array)$job['mapping'];
        $mapped = [];
        foreach ($headers as $i=>$header) {
            $value = isset($row[$i]) ? trim((string)$row[$i]) : '';
            $target = $mapping[$i] ?? '';
            if ($target !== '' && $value !== '') { $mapped[$target] = $value; }
        }
        return $this->normalize_mapped($mapped);
    }

    private function normalize_mapped($data) {
        $out = [];
        foreach ((array)$data as $key=>$value) {
            $value = trim(wp_strip_all_tags((string)$value));
            if ($key === 'email') { $value = sanitize_email($value); }
            elseif ($key === 'phone') { $value = $this->normalize_phone($value); }
            elseif (in_array($key, ['iin','organization_bin'], true)) { $value = preg_replace('/\D+/', '', $value); }
            elseif (in_array($key, ['registration_date','submission_date','birth_date','issue_date'], true)) { $value = $this->normalize_date($value); }
            elseif (in_array($key, ['legacy_user_id','legacy_entry_id'], true)) { $value = sanitize_text_field($value); }
            elseif ($key === 'document_no') { $value = sanitize_text_field(str_replace(["\r","\n","\t"], ' ', $value)); }
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

    private function mysql_date($value) {
        if (!$value) { return ''; }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return $value; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return $value . ' 00:00:00'; }
        $ts = strtotime($value);
        return $ts ? wp_date('Y-m-d H:i:s', $ts) : '';
    }

    /** Точный поиск в карте сопоставления по старому ID. Единственный способ найти цель — без угадывания. */
    private function find_map_row($kind, $legacyId) {
        global $wpdb;
        if ($legacyId === '' || $legacyId === null) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->map_table} WHERE kind=%s AND legacy_id=%s", $kind, (string)$legacyId));
    }

    private function save_map_row($kind, $legacyId, $fields) {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->find_map_row($kind, $legacyId);
        $data = array_merge(['kind'=>$kind,'legacy_id'=>(string)$legacyId,'updated_at'=>$now], $fields);
        if ($existing) {
            $wpdb->update($this->map_table, $data, ['id'=>(int)$existing->id]);
            return (int)$existing->id;
        }
        $data['created_at'] = $now;
        $wpdb->insert($this->map_table, $data);
        return (int)$wpdb->insert_id;
    }

    private function set_meta_fill($userId, $key, $value, $overwrite) {
        if ($value === '' || $value === null) { return; }
        $old = get_user_meta($userId, $key, true);
        if ($overwrite || $old === '' || $old === null) { update_user_meta($userId, $key, $value); }
    }

    private function unique_login($mapped) {
        $base = '';
        if (!empty($mapped['email'])) { $base = sanitize_user(strtok($mapped['email'], '@'), true); }
        if (!$base && !empty($mapped['phone'])) { $base = 'member_' . preg_replace('/\D+/', '', $mapped['phone']); }
        if (!$base && !empty($mapped['legacy_user_id'])) { $base = 'legacy_' . sanitize_user($mapped['legacy_user_id'], true); }
        if (!$base) { $base = 'legacy_member'; }
        $login = $base; $i = 1;
        while (username_exists($login)) { $login = $base . '_' . $i++; }
        return $login;
    }

    private function create_account_user($mapped) {
        $display = trim((string)($mapped['full_name'] ?? ''));
        if (!$display) { $display = $mapped['email'] ?? ($mapped['phone'] ?? ('Участник #' . ($mapped['legacy_user_id'] ?? ''))); }
        $email = (!empty($mapped['email']) && is_email($mapped['email']) && !email_exists($mapped['email'])) ? strtolower(trim((string)$mapped['email'])) : '';
        return wp_insert_user([
            'user_login'=>$this->unique_login($mapped),
            'user_pass'=>wp_generate_password(32, true, true),
            'user_email'=>$email,
            'display_name'=>$display,
            'first_name'=>$mapped['first_name'] ?? '',
            'last_name'=>$mapped['last_name'] ?? '',
            'role'=>'subscriber',
            'user_registered'=>$this->mysql_date($mapped['registration_date'] ?? '') ?: current_time('mysql'),
        ]);
    }

    private function update_account_user($userId, $mapped, $overwrite, $defaultStatus, $overwriteStatus = null) {
        $user = get_user_by('id', $userId);
        if (!$user) { return new WP_Error('user_missing', 'Пользователь не найден.'); }
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
        if (!empty($mapped['phone'])) { $this->set_meta_fill($userId, 'zau_phone', $mapped['phone'], $overwrite); }
        $this->set_meta_fill($userId, 'zau_legacy_user_id', $mapped['legacy_user_id'] ?? '', false);
        $profileMap = ['iin'=>'iin','birth_date'=>'birth_date','address'=>'address','position'=>'position','department'=>'department','middle_name'=>'middle_name'];
        foreach ($profileMap as $source=>$target) {
            if (!empty($mapped[$source])) { $this->set_meta_fill($userId, 'zau_profile_' . $target, $mapped[$source], $overwrite); }
        }
        $this->assign_organization($userId, $mapped, $overwrite);
        $this->assign_branch($userId, $mapped, $overwrite);
        $existingStatus = get_user_meta($userId, 'zau_member_status', true);
        $statusOverwrite = $overwriteStatus === null ? $overwrite : $overwriteStatus;
        if (!$existingStatus || $statusOverwrite) { update_user_meta($userId, 'zau_member_status', $mapped['member_status'] ?: ($defaultStatus ?: 'Заявление подано')); }
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
        if (!$org && $bin) {
            $wpdb->insert($this->orgs_table, ['bin'=>$bin,'name'=>$name ?: $bin,'director'=>$mapped['organization_director'] ?? '','address'=>$mapped['organization_address'] ?? '','region'=>'','source'=>'legacy_import','updated_at'=>current_time('mysql')]);
            if ($wpdb->insert_id) { $org = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE id=%d", $wpdb->insert_id)); }
        } elseif (!$org && $name) {
            $wpdb->query($wpdb->prepare("INSERT INTO {$this->orgs_table} (bin,name,director,address,region,source,updated_at) VALUES (NULL,%s,%s,%s,'','legacy_import_name_only',%s)", $name, (string)($mapped['organization_director'] ?? ''), (string)($mapped['organization_address'] ?? ''), current_time('mysql')));
            if ($wpdb->insert_id) { $org = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orgs_table} WHERE id=%d", $wpdb->insert_id)); }
        }
        if ($org) {
            $this->set_meta_fill($userId, 'zau_organization_id', (int)$org->id, $overwrite);
            if (!empty($org->bin)) { $this->set_meta_fill($userId, 'zau_organization_bin', (string)$org->bin, $overwrite); }
            $this->set_meta_fill($userId, 'zau_organization_name', (string)$org->name, $overwrite);
        } else {
            if ($bin) { $this->set_meta_fill($userId, 'zau_organization_bin', $bin, $overwrite); }
            if ($name) { $this->set_meta_fill($userId, 'zau_organization_name', $name, $overwrite); }
        }
    }

    private function assign_branch($userId, $mapped, $overwrite) {
        global $wpdb;
        $fullDetails = trim((string)($mapped['branch_full_details'] ?? ''));
        if ($fullDetails !== '') { $this->set_meta_fill($userId, 'zau_profile_branch_full_details', $fullDetails, $overwrite); }
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
        $this->set_meta_fill($userId, 'zau_profile_branch_name', $name, $overwrite);
        if ($match) {
            $this->set_meta_fill($userId, 'zau_profile_branch_id', (int)$match->id, $overwrite);
            $this->set_meta_fill($userId, 'zau_profile_branch_name', (string)$match->name, $overwrite);
        }
    }

    private function update_member_card($userId, $mapped, $overwrite) {
        $card = json_decode((string)get_user_meta($userId, 'zau_member_card_data', true), true);
        if (!is_array($card)) { $card = []; }
        foreach (['iin','birth_date','address','position','department','middle_name'] as $field) {
            if (!empty($mapped[$field]) && ($overwrite || empty($card[$field]))) { $card[$field] = $mapped[$field]; }
        }
        update_user_meta($userId, 'zau_member_card_data', wp_json_encode($card, JSON_UNESCAPED_UNICODE));
        if (!get_user_meta($userId, 'zau_member_card_review_status', true)) { update_user_meta($userId, 'zau_member_card_review_status', 'pending'); }
    }

    private function process_account_row($row, $job) {
        $rowNumber = (int)$job['processed'];
        $mapped = $this->read_mapped_row($row, $job);
        $legacyId = (string)($mapped['legacy_user_id'] ?? '');
        if ($legacyId === '') { return ['kind'=>'error','row'=>$rowNumber,'message'=>'Пустой ID пользователя Ultimate Member — строка пропущена.','mapped'=>$mapped]; }
        $dry = !empty($job['options']['dry_run']);
        $overwrite = !empty($job['options']['overwrite']);
        $existingMap = $this->find_map_row('account', $legacyId);
        $userId = 0;
        if ($existingMap && $existingMap->target_user_id && get_user_by('id', (int)$existingMap->target_user_id)) {
            $userId = (int)$existingMap->target_user_id;
        } else {
            global $wpdb;
            $found = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_user_id' AND meta_value=%s ORDER BY user_id ASC LIMIT 1", $legacyId));
            if ($found) { $userId = $found; }
        }
        if ($dry) {
            return ['kind'=>$userId ? 'would_update' : 'would_create','row'=>$rowNumber,'message'=>$userId ? 'Найден ранее перенесённый аккаунт, будет обновлён.' : 'Будет создан новый аккаунт.','user_id'=>$userId,'mapped'=>$mapped];
        }
        $created = false;
        if (!$userId) {
            $newUser = $this->create_account_user($mapped);
            if (is_wp_error($newUser)) { return ['kind'=>'error','row'=>$rowNumber,'message'=>$newUser->get_error_message(),'mapped'=>$mapped]; }
            $userId = (int)$newUser;
            $created = true;
        }
        $updated = $this->update_account_user($userId, $mapped, $overwrite || $created, (string)$job['options']['default_status']);
        if (is_wp_error($updated)) { return ['kind'=>'error','row'=>$rowNumber,'message'=>$updated->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
        $this->save_map_row('account', $legacyId, ['target_user_id'=>$userId,'status'=>'imported','message'=>'OK']);
        return ['kind'=>$created ? 'created' : 'updated','row'=>$rowNumber,'message'=>$created ? 'Аккаунт создан.' : 'Аккаунт обновлён.','user_id'=>$userId,'mapped'=>$mapped];
    }

    private function ensure_form_id($formId) {
        global $wpdb;
        $formId = absint($formId);
        if ($formId && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->forms_table} WHERE id=%d", $formId))) { return $formId; }
        return 0;
    }

    private function process_application_row($row, $job) {
        $rowNumber = (int)$job['processed'];
        $headers = (array)$job['headers'];
        $legacyFields = $this->raw_legacy_fields($headers, $row);
        $mapped = $this->read_mapped_row($row, $job);
        $entryId = (string)($mapped['legacy_entry_id'] ?? '');
        $ownerLegacyId = (string)($mapped['legacy_user_id'] ?? '');
        if ($entryId === '' || $ownerLegacyId === '') { return ['kind'=>'error','row'=>$rowNumber,'message'=>'Пустой ID заявления или ID владельца-аккаунта — строка пропущена.','mapped'=>$mapped]; }
        $accountMap = $this->find_map_row('account', $ownerLegacyId);
        $userId = ($accountMap && $accountMap->target_user_id) ? (int)$accountMap->target_user_id : 0;
        if (!$userId || !get_user_by('id', $userId)) {
            return ['kind'=>'skipped','row'=>$rowNumber,'message'=>'Аккаунт с ID Ultimate Member «'.$ownerLegacyId.'» ещё не перенесён — сначала перенесите аккаунты.','mapped'=>$mapped];
        }
        $dry = !empty($job['options']['dry_run']);
        $existingMap = $this->find_map_row('application', $entryId);
        if ($dry) {
            return ['kind'=>$existingMap ? 'would_update' : 'would_create','row'=>$rowNumber,'message'=>$existingMap ? 'Заявление уже переносилось, будет обновлено.' : 'Будет создана новая заявка.','user_id'=>$userId,'mapped'=>$mapped];
        }
        $formId = $this->ensure_form_id($job['options']['form_id']);
        if (!$formId) { return ['kind'=>'error','row'=>$rowNumber,'message'=>'Форма для заявлений не найдена.','mapped'=>$mapped]; }
        global $wpdb;
        $data = $mapped;
        $data['full_name'] = $mapped['full_name'] ?? '';
        $data['legacy_source'] = 'legacy_csv_import';
        $data['legacy_import'] = 1;
        $data['legacy_entry_id'] = $entryId;
        $data['legacy_user_id'] = $ownerLegacyId;
        $data['legacy_imported_at'] = current_time('mysql');
        $data['legacy_fields'] = $legacyFields;
        $createdAt = $this->mysql_date($mapped['submission_date'] ?? '') ?: current_time('mysql');
        $status = sanitize_key($mapped['status'] ?? 'submitted') ?: 'submitted';
        $signatures = !empty($mapped['signature_url']) ? ['signature'=>esc_url_raw($mapped['signature_url'])] : [];
        $existingDocumentId = $existingMap ? (int)$existingMap->target_document_id : 0;
        $resultKind = '';
        if ($existingMap && $existingMap->target_submission_id) {
            $submissionId = (int)$existingMap->target_submission_id;
            $ok = $wpdb->update($this->submissions_table, ['status'=>$status,'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')], ['id'=>$submissionId]);
            if ($ok === false) { return ['kind'=>'error','row'=>$rowNumber,'message'=>'Не удалось обновить ранее перенесённую заявку.','mapped'=>$mapped]; }
            $resultKind = 'updated';
        } else {
            $wpdb->insert($this->submissions_table, ['form_id'=>$formId,'user_id'=>$userId,'status'=>$status,'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),'ip'=>'legacy-import','created_at'=>$createdAt,'updated_at'=>current_time('mysql')]);
            $submissionId = (int)$wpdb->insert_id;
            if (!$submissionId) { return ['kind'=>'error','row'=>$rowNumber,'message'=>'Не удалось сохранить заявку.','mapped'=>$mapped]; }
            $resultKind = 'created';
        }

        $documentId = 0;
        $collision = '';
        $templateId = absint($job['options']['template_id'] ?? 0);
        if ($templateId) {
            $ownerUser = get_user_by('id', $userId);
            $documentFullName = !empty($mapped['full_name']) ? $mapped['full_name'] : ($ownerUser ? $ownerUser->display_name : '');
            $urls = [
                'signature' => $mapped['signature_url'] ?? '',
                'signature2' => $mapped['signature2_url'] ?? '',
                'stamp' => $mapped['stamp_url'] ?? '',
            ];
            $documentId = $this->upsert_document_for_submission($userId, $submissionId, $templateId, array_merge($data, ['full_name'=>$documentFullName]), $urls, $existingDocumentId, $collision);
            if (is_wp_error($documentId)) { $collision = $documentId->get_error_message(); $documentId = 0; }
        }

        $this->save_map_row('application', $entryId, ['target_user_id'=>$userId,'target_submission_id'=>$submissionId,'target_document_id'=>(int)$documentId,'status'=>'imported','message'=>'OK']);
        $message = $resultKind === 'created' ? 'Заявка создана.' : 'Заявка обновлена.';
        if ($templateId && $documentId) { $message .= ' Документ по шаблону подготовлен.'; }
        if ($collision !== '') { $message .= ' ' . $collision; }
        return ['kind'=>$resultKind,'row'=>$rowNumber,'message'=>$message,'user_id'=>$userId,'submission_id'=>$submissionId,'mapped'=>$mapped];
    }

    private function apply_result(&$job, $result) {
        $kind = $result['kind'] ?? 'error';
        switch ($kind) {
            case 'created': $job['stats']['created']++; break;
            case 'updated': $job['stats']['updated']++; break;
            case 'existing': $job['stats']['existing']++; break;
            case 'skipped': $job['stats']['skipped']++; break;
            case 'would_create': $job['stats']['would_create']++; break;
            case 'would_update': $job['stats']['would_update']++; break;
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
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        $upload = (array)get_option($this->upload_option_key($scope), []);
        if (!empty($upload['path']) && is_file($upload['path'])) { @unlink($upload['path']); }
        delete_option($this->upload_option_key($scope));
        delete_option($this->job_option_key($scope));
        wp_send_json_success(['message'=>'Загрузка и прогресс сброшены. Перенесённые данные не удалены.']);
    }

    public function download_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $scope = sanitize_key((string)($_GET['scope'] ?? 'accounts'));
        if (!in_array($scope, ['accounts','applications'], true)) { $scope = 'accounts'; }
        $job = (array)get_option($this->job_option_key($scope), []);
        $rows = (array)($job['report'] ?? []);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-legacy-import-' . $scope . '-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Строка','Результат','Сообщение','Новый User ID','Новая заявка ID','ID Ultimate Member','ID записи заявления','ФИО'], ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, [$row['row'],$row['result'],$row['message'],$row['user_id'],$row['submission_id'],$row['legacy_user_id'],$row['legacy_entry_id'],$row['full_name']], ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Восстановление полей у уже загруженных заявок ("сырые" ключи вроде
     * "от Ф.И.О." вместо full_name — когда данные попали в базу не через
     * сопоставление колонок этого плагина, а напрямую или другим скриптом).
     * Работает на уже существующих заявках выбранной формы: находит ключи
     * data_json, которые не являются распознаваемыми системными полями,
     * даёт сопоставить их с целевыми полями, и копирует значение в целевой
     * ключ (не удаляя исходный), не трогая заявки, где целевое поле уже
     * заполнено (если не включено "перезаписывать").
     * ------------------------------------------------------------------ */

    private function remap_known_keys() {
        return array_values(array_unique(array_merge(array_keys($this->application_fields()), [
            'full_name','first_name','last_name','middle_name','email','phone','iin','birth_date','address',
            'position','department','organization','organization_bin','organization_director','organization_address',
            'branch_name','branch_id','submission_date','issue_date','document_no','status','member_status',
            'extra1','extra2','extra3','extra4','signature_url','signature2_url','stamp_url','signature',
            'membership_consent','membership_heading','full_name_header','member_name_header',
        ])));
    }

    // Некоторые поля старого сайта (например "application_no") хранят не сами
    // данные, а служебную метку вида "RNa0Ll1o8m7WziJA от 10/28/2025 Имя Фамилия" —
    // случайный код и дата подстановки, за которыми уже идёт настоящее значение.
    // Отрезаем этот мусорный префикс, чтобы в целевое поле (например ФИО)
    // не попадал код и дата вместе с именем.
    private function remap_clean_value($value) {
        $value = (string)$value;
        if (preg_match('/^[A-Za-z0-9]{6,40}\s+от\s+\d{1,2}\/\d{1,2}\/\d{4}\s+(.+)$/u', $value, $m)) {
            return trim($m[1]);
        }
        return $value;
    }

    private function remap_is_candidate_key($key) {
        $key = (string)$key;
        if ($key === '') { return false; }
        foreach (['legacy_','branch_','organization_','profile__','card__','submission__','_zau'] as $prefix) {
            if (strpos($key, $prefix) === 0) { return false; }
        }
        return !in_array($key, $this->remap_known_keys(), true);
    }

    public function ajax_remap_scan() {
        $this->require_access();
        global $wpdb;
        $formId = absint($_POST['form_id'] ?? 0);
        if (!$formId) { wp_send_json_error(['message'=>'Выберите форму для проверки.'], 400); }
        $form = $this->get_form_row($formId);
        if (!$form) { wp_send_json_error(['message'=>'Форма не найдена.'], 404); }
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE form_id=%d", $formId));
        if (!$total) { wp_send_json_error(['message'=>'У этой формы пока нет заявок.'], 400); }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT data_json FROM {$this->submissions_table} WHERE form_id=%d ORDER BY id DESC LIMIT 300", $formId));
        $candidates = [];
        foreach ($rows as $row) {
            $data = json_decode((string)$row->data_json, true);
            if (!is_array($data)) { continue; }
            foreach ($data as $key => $value) {
                if (!is_scalar($value) || trim((string)$value) === '') { continue; }
                if (!$this->remap_is_candidate_key($key)) { continue; }
                if (!isset($candidates[$key])) { $candidates[$key] = ['count'=>0,'examples'=>[]]; }
                $candidates[$key]['count']++;
                if (count($candidates[$key]['examples']) < 2) {
                    $example = $this->remap_clean_value(trim((string)$value));
                    if (mb_strlen($example) > 80) { $example = mb_substr($example, 0, 80) . '…'; }
                    $candidates[$key]['examples'][] = $example;
                }
            }
        }
        uasort($candidates, function($a, $b) { return $b['count'] <=> $a['count']; });
        update_option($this->upload_option_key('remap'), ['form_id'=>$formId, 'scanned_at'=>time()], false);
        delete_option($this->job_option_key('remap'));
        wp_send_json_success([
            'message' => 'Проверено заявок: ' . count($rows) . ' из ' . $total . '. Найдено полей для сопоставления: ' . count($candidates) . '.',
            'form_id' => $formId,
            'form_name' => $form->name,
            'total' => $total,
            'scanned' => count($rows),
            'candidates' => $candidates,
            'targets' => $this->application_fields(),
        ]);
    }

    private function get_form_row($formId) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE id=%d", absint($formId)));
    }

    public function ajax_remap_start() {
        $this->require_access();
        $upload = (array)get_option($this->upload_option_key('remap'), []);
        $formId = absint($upload['form_id'] ?? 0);
        if (!$formId) { wp_send_json_error(['message'=>'Сначала проверьте форму — нажмите «Проверить форму».'], 400); }
        $mappingRaw = json_decode((string)wp_unslash($_POST['mapping'] ?? ''), true);
        if (!is_array($mappingRaw) || !$mappingRaw) { wp_send_json_error(['message'=>'Отметьте хотя бы одно поле для сопоставления.'], 400); }
        // legacy_entry_id/legacy_user_id остаются служебными ключами переноса — их случайная
        // перезапись задним числом сломала бы сопоставление с ID старого сайта при повторном импорте.
        $allowed = array_values(array_diff(array_keys($this->application_fields()), ['legacy_entry_id','legacy_user_id']));
        $mapping = [];
        foreach ($mappingRaw as $rawKey => $target) {
            $rawKey = sanitize_text_field((string)wp_unslash($rawKey));
            $target = sanitize_key((string)$target);
            if ($rawKey !== '' && $target !== '' && in_array($target, $allowed, true)) { $mapping[$rawKey] = $target; }
        }
        if (!$mapping) { wp_send_json_error(['message'=>'Не выбрано ни одного целевого поля.'], 400); }
        global $wpdb;
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE form_id=%d", $formId));
        $job = [
            'form_id' => $formId,
            'mapping' => $mapping,
            'options' => [
                'dry_run' => empty($_POST['dry_run']) ? 0 : 1,
                'overwrite' => empty($_POST['overwrite']) ? 0 : 1,
                'sync_documents' => empty($_POST['sync_documents']) ? 0 : 1,
            ],
            'cursor' => 0,
            'processed' => 0,
            'total' => $total,
            'stats' => ['submissions_updated'=>0,'fields_filled'=>0,'documents_updated'=>0,'unchanged'=>0,'errors'=>0],
            'report' => [],
            'log' => [],
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
        ];
        update_option($this->job_option_key('remap'), $job, false);
        wp_send_json_success($this->public_remap_job($job));
    }

    public function ajax_remap_process() {
        $this->require_access();
        global $wpdb;
        $job = (array)get_option($this->job_option_key('remap'), []);
        if (!$job || empty($job['form_id'])) { wp_send_json_error(['message'=>'Задание не найдено. Начните заново.'], 404); }
        if (($job['status'] ?? '') === 'finished') { wp_send_json_success($this->public_remap_job($job)); }
        $formId = (int)$job['form_id'];
        $dryRun = !empty($job['options']['dry_run']);
        $overwrite = !empty($job['options']['overwrite']);
        $syncDocuments = !empty($job['options']['sync_documents']);
        $batchSize = 150;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,user_id,data_json FROM {$this->submissions_table} WHERE form_id=%d AND id>%d ORDER BY id ASC LIMIT %d",
            $formId, (int)$job['cursor'], $batchSize
        ));
        foreach ($rows as $row) {
            $job['cursor'] = (int)$row->id;
            $job['processed']++;
            $data = json_decode((string)$row->data_json, true);
            if (!is_array($data)) { $job['stats']['errors']++; continue; }
            $changed = false;
            $filled = 0;
            foreach ($job['mapping'] as $rawKey => $target) {
                if (!array_key_exists($rawKey, $data)) { continue; }
                $value = $this->remap_clean_value(trim((string)$data[$rawKey]));
                if ($value === '') { continue; }
                $current = trim((string)($data[$target] ?? ''));
                if ($current !== '' && !$overwrite) { continue; }
                if ($current === $value) { continue; }
                $data[$target] = $value;
                $changed = true;
                $filled++;
            }
            if ($changed) {
                $job['stats']['submissions_updated']++;
                $job['stats']['fields_filled'] += $filled;
                if (!$dryRun) {
                    $wpdb->update($this->submissions_table, ['data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at'=>current_time('mysql')], ['id'=>$row->id]);
                }
            } else {
                $job['stats']['unchanged']++;
            }
            // Синхронизация документов проверяется всегда, а не только когда сама заявка
            // поменялась в этом прогоне — иначе повторный запуск (или запуск после того,
            // как заявка уже была исправлена ранее) никогда не находил бы устаревшее ФИО
            // в уже созданном документе, потому что "изменений в заявке" в этом проходе нет.
            if ($syncDocuments && !empty($data['full_name'])) {
                $docs = $wpdb->get_results($wpdb->prepare("SELECT id,full_name,organization FROM {$this->docs_table} WHERE source_submission_id=%d", $row->id));
                foreach ($docs as $doc) {
                    $newName = trim((string)$data['full_name']);
                    $newOrg = trim((string)($data['organization'] ?? $doc->organization));
                    if ($newName === '' || ((string)$doc->full_name === $newName && (string)$doc->organization === $newOrg)) { continue; }
                    $job['report'][] = [
                        'submission_id'=>(int)$row->id, 'document_id'=>(int)$doc->id,
                        'old_full_name'=>(string)$doc->full_name, 'new_full_name'=>$newName,
                        'old_organization'=>(string)$doc->organization, 'new_organization'=>$newOrg,
                    ];
                    if (!$dryRun) {
                        $wpdb->update($this->docs_table, ['full_name'=>$newName, 'organization'=>$newOrg, 'updated_at'=>current_time('mysql')], ['id'=>$doc->id]);
                    }
                    $job['stats']['documents_updated']++;
                }
            }
        }
        if (count($rows) < $batchSize) {
            $job['status'] = 'finished';
            $job['finished_at'] = current_time('mysql');
            $job['log'][] = 'Готово: обработано ' . $job['processed'] . ' из ' . $job['total'] . '.';
        }
        if (count($job['report']) > 3000) { $job['report'] = array_slice($job['report'], -3000); }
        update_option($this->job_option_key('remap'), $job, false);
        wp_send_json_success($this->public_remap_job($job));
    }

    private function public_remap_job($job) {
        return [
            'status' => $job['status'] ?? 'idle',
            'processed' => (int)($job['processed'] ?? 0),
            'total' => (int)($job['total'] ?? 0),
            'stats' => $job['stats'] ?? [],
            'log' => array_slice((array)($job['log'] ?? []), -20),
            'dry_run' => !empty($job['options']['dry_run']) ? 1 : 0,
            'finished_at' => $job['finished_at'] ?? '',
            'report_count' => count((array)($job['report'] ?? [])),
        ];
    }

    public function ajax_remap_reset() {
        $this->require_access();
        delete_option($this->upload_option_key('remap'));
        delete_option($this->job_option_key('remap'));
        wp_send_json_success(['message'=>'Прогресс сброшен. Уже применённые изменения не отменяются.']);
    }

    public function download_remap_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $job = (array)get_option($this->job_option_key('remap'), []);
        $rows = (array)($job['report'] ?? []);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="zau-legacy-remap-' . wp_date('Y-m-d-H-i') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID заявки','ID документа','Старое ФИО','Новое ФИО','Старая организация','Новая организация'], ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, [$row['submission_id'],$row['document_id'],$row['old_full_name'],$row['new_full_name'],$row['old_organization'],$row['new_organization']], ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Привязка PDF по уникальному ID в конце имени файла.
     * Личная карточка — ID пользователя Ultimate Member; заявление — ID записи формы.
     * ------------------------------------------------------------------- */

    private function pdf_settings() {
        return wp_parse_args((array)get_option('zau_legacy_pdf_settings', []), [
            'folder' => 'zau-legacy-pdfs',
            'card_pattern' => '',
            'card_regex' => '(\\d+)\\D*$',
            'card_template_id' => 0,
            'application_pattern' => '',
            'application_regex' => '(\\d+)\\D*$',
            'application_template_id' => 0,
        ]);
    }

    private function pdf_folder_path($settings) {
        $uploads = wp_upload_dir();
        $folder = trim((string)($settings['folder'] ?? ''), '/\\ ');
        $folder = $folder !== '' ? $folder : 'zau-legacy-pdfs';
        return trailingslashit($uploads['basedir']) . $folder;
    }

    private function extract_legacy_id($filename, $regex) {
        if ($regex === '') { return ''; }
        $delimited = '#' . str_replace('#', '\\#', $regex) . '#u';
        if (@preg_match($delimited, $filename, $m) && isset($m[1]) && $m[1] !== '') { return sanitize_text_field($m[1]); }
        return '';
    }

    private function matches_pattern($filename, $pattern) {
        $pattern = trim((string)$pattern);
        if ($pattern === '') { return true; }
        if (function_exists('mb_stripos')) { return mb_stripos($filename, $pattern) !== false; }
        return stripos($filename, $pattern) !== false;
    }

    /** Классифицирует файл по правилам «личная карточка» / «заявление» и достаёт ID. Первое совпадение побеждает. */
    private function classify_pdf($filename, $settings) {
        if ($this->matches_pattern($filename, $settings['card_pattern'])) {
            $id = $this->extract_legacy_id($filename, $settings['card_regex']);
            if ($id !== '') { return ['role'=>'card','legacy_id'=>$id]; }
        }
        if ($this->matches_pattern($filename, $settings['application_pattern'])) {
            $id = $this->extract_legacy_id($filename, $settings['application_regex']);
            if ($id !== '') { return ['role'=>'application','legacy_id'=>$id]; }
        }
        return null;
    }

    public function ajax_scan_pdfs() {
        $this->require_access();
        $settings = [
            'folder' => sanitize_text_field((string)($_POST['folder'] ?? 'zau-legacy-pdfs')),
            'card_pattern' => sanitize_text_field((string)($_POST['card_pattern'] ?? '')),
            'card_regex' => (string)wp_unslash($_POST['card_regex'] ?? '(\\d+)\\D*$'),
            'card_template_id' => absint($_POST['card_template_id'] ?? 0),
            'application_pattern' => sanitize_text_field((string)($_POST['application_pattern'] ?? '')),
            'application_regex' => (string)wp_unslash($_POST['application_regex'] ?? '(\\d+)\\D*$'),
            'application_template_id' => absint($_POST['application_template_id'] ?? 0),
        ];
        update_option('zau_legacy_pdf_settings', $settings, false);
        $dir = $this->pdf_folder_path($settings);
        if (!is_dir($dir)) { wp_send_json_error(['message'=>'Папка «' . $dir . '» не найдена. Скопируйте PDF по FTP/SFTP и повторите.'], 404); }
        $files = glob(trailingslashit($dir) . '*.pdf');
        if (!is_array($files)) { $files = []; }
        natsort($files);
        $rows = [];
        $counts = ['card'=>0,'application'=>0,'unmatched'=>0,'unresolved'=>0];
        foreach ($files as $path) {
            $filename = basename($path);
            $info = $this->classify_pdf($filename, $settings);
            if (!$info) { $counts['unmatched']++; $rows[] = ['file'=>$filename,'role'=>'','legacy_id'=>'','resolved'=>0]; continue; }
            $counts[$info['role']]++;
            $map = $this->find_map_row($info['role'] === 'card' ? 'account' : 'application', $info['legacy_id']);
            $resolved = $map && (int)($info['role'] === 'card' ? $map->target_user_id : $map->target_submission_id) > 0;
            if (!$resolved) { $counts['unresolved']++; }
            $rows[] = ['file'=>$filename,'role'=>$info['role'],'legacy_id'=>$info['legacy_id'],'resolved'=>$resolved ? 1 : 0];
        }
        wp_send_json_success(['rows'=>array_slice($rows, 0, 500), 'total'=>count($files), 'counts'=>$counts, 'folder'=>$dir]);
    }

    public function ajax_attach_pdfs() {
        $this->require_access();
        $settings = $this->pdf_settings();
        $dir = $this->pdf_folder_path($settings);
        if (!is_dir($dir)) { wp_send_json_error(['message'=>'Папка с PDF не найдена.'], 404); }
        $processedDir = trailingslashit($dir) . 'обработано';
        wp_mkdir_p($processedDir);
        $files = glob(trailingslashit($dir) . '*.pdf');
        if (!is_array($files)) { $files = []; }
        natsort($files);
        $batchSize = 20;
        $batch = array_slice($files, 0, $batchSize);
        $result = ['attached'=>0,'unmatched'=>0,'unresolved'=>0,'errors'=>0,'log'=>[]];
        foreach ($batch as $path) {
            $filename = basename($path);
            $info = $this->classify_pdf($filename, $settings);
            if (!$info) { $result['unmatched']++; $result['log'][] = $filename . ': имя файла не подошло ни под одно правило.'; continue; }
            $outcome = $this->attach_one_pdf($path, $filename, $info, $settings);
            if ($outcome === true) {
                $result['attached']++;
                @rename($path, trailingslashit($processedDir) . $filename);
            } elseif ($outcome === 'unresolved') {
                $result['unresolved']++;
                $result['log'][] = $filename . ': не найдена запись с ' . ($info['role'] === 'card' ? 'ID Ultimate Member' : 'ID заявления') . ' «' . $info['legacy_id'] . '» — сначала перенесите ' . ($info['role'] === 'card' ? 'аккаунты' : 'заявления') . '.';
            } else {
                $result['errors']++;
                $result['log'][] = $filename . ': ' . (string)$outcome;
            }
        }
        $remaining = max(0, count($files) - count($batch));
        wp_send_json_success(array_merge($result, ['remaining'=>$remaining]));
    }

    private function attach_one_pdf($path, $filename, $info, $settings) {
        global $wpdb;
        $kind = $info['role'] === 'card' ? 'account' : 'application';
        $map = $this->find_map_row($kind, $info['legacy_id']);
        if (!$map || !$map->target_user_id) { return 'unresolved'; }
        $userId = (int)$map->target_user_id;
        $submissionId = $info['role'] === 'application' ? (int)$map->target_submission_id : 0;
        if ($info['role'] === 'application' && !$submissionId) { return 'unresolved'; }
        $templateId = absint($info['role'] === 'card' ? $settings['card_template_id'] : $settings['application_template_id']);
        if (!$templateId) { return 'не выбран шаблон PDF для этого типа документа в настройках привязки.'; }
        $tpl = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id=%d", $templateId));
        if (!$tpl) { return 'выбранный шаблон PDF больше не существует.'; }
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 100) { return 'не удалось прочитать файл.'; }

        /* Сначала проверяем, не создан ли уже черновик документа переносом заявлений (шаг Б API или
         * CSV с выбранным шаблоном) — он привязан к этому же legacy_id через основную карту
         * сопоставления ($map, kind=account/application). Только если такого черновика нет, смотрим
         * в собственную историю этого шага (когда шаг 3 применяется отдельно, без переноса заявлений).
         * Иначе на одну и ту же запись создавались бы два документа — черновик от шага Б и ещё один
         * от привязки PDF по имени файла. */
        $mapKind = $info['role'] === 'card' ? 'pdf_account' : 'pdf_application';
        $existingDocumentId = (int)$map->target_document_id;
        if (!$existingDocumentId) {
            $existingDocMap = $this->find_map_row($mapKind, $info['legacy_id']);
            $existingDocumentId = ($existingDocMap && $existingDocMap->target_document_id) ? (int)$existingDocMap->target_document_id : 0;
        }
        $existingDoc = $existingDocumentId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d", $existingDocumentId)) : null;

        $user = get_user_by('id', $userId);
        $fullName = $user ? $user->display_name : '';
        $organization = (string)get_user_meta($userId, 'zau_organization_name', true);
        if ($submissionId) {
            $submission = $wpdb->get_row($wpdb->prepare("SELECT data_json FROM {$this->submissions_table} WHERE id=%d", $submissionId));
            $subData = $submission ? json_decode((string)$submission->data_json, true) : null;
            if (is_array($subData)) {
                if (!empty($subData['full_name'])) { $fullName = $subData['full_name']; }
                if (!empty($subData['organization'])) { $organization = $subData['organization']; }
            }
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) { return (string)$uploads['error']; }
        $sub = 'zau-certificates/legacy';
        $destDir = trailingslashit($uploads['basedir']) . $sub;
        if (!wp_mkdir_p($destDir)) { return 'не удалось создать папку для документов.'; }

        $now = current_time('mysql');
        if ($existingDoc) {
            $documentId = (int)$existingDoc->id;
            $revision = max(1, (int)$existingDoc->file_revision + 1);
            $documentNo = (string)$existingDoc->document_no;
            $token = (string)$existingDoc->verify_token;
        } else {
            $token = bin2hex(random_bytes(24));
            $row = ['template_id'=>$templateId,'user_id'=>$userId,'created_by'=>get_current_user_id(),'source_submission_id'=>$submissionId,'full_name'=>sanitize_text_field($fullName),'document_title'=>$tpl->name,'organization'=>sanitize_text_field($organization),'issue_date'=>wp_date('d.m.Y'),'document_no'=>'PENDING-'.$token,'member_status'=>(string)get_user_meta($userId,'zau_member_status',true),'signature_url'=>'','signature2_url'=>'','stamp_url'=>'','data_json'=>wp_json_encode(['legacy_import'=>1,'legacy_source_file'=>$filename,'legacy_id'=>$info['legacy_id']], JSON_UNESCAPED_UNICODE),'orientation'=>$tpl->orientation,'verify_token'=>$token,'record_status'=>'draft','created_at'=>$now,'updated_at'=>$now];
            if (!$wpdb->insert($this->docs_table, $row)) { return 'не удалось создать запись документа.'; }
            $documentId = (int)$wpdb->insert_id;
            $documentNo = class_exists('ZAU_Certificate_PDF_Generator') ? ZAU_Certificate_PDF_Generator::instance()->format_document_number($tpl, $documentId) : ('DOC-' . $documentId);
            $revision = 1;
        }

        $safe = sanitize_file_name($documentNo . '-' . $documentId . '-r' . $revision);
        $destPath = trailingslashit($destDir) . $safe . '.pdf';
        if (file_put_contents($destPath, $bytes, LOCK_EX) === false) { return 'не удалось сохранить PDF.'; }
        $pdfUrl = trailingslashit($uploads['baseurl']) . $sub . '/' . $safe . '.pdf';

        $wpdb->update($this->docs_table, [
            'document_no'=>$documentNo,
            'pdf_url'=>$pdfUrl,
            'file_revision'=>$revision,
            'record_status'=>'active',
            'updated_at'=>$now,
        ], ['id'=>$documentId]);

        $this->save_map_row($mapKind, $info['legacy_id'], ['target_user_id'=>$userId,'target_submission_id'=>$submissionId,'target_document_id'=>$documentId,'status'=>'imported','message'=>$filename]);
        if (!$existingDoc) {
            /* Документ создан именно этим шагом (черновика от переноса заявлений ещё не было) —
             * записываем его ID и в основную карту (kind=account/application), чтобы более поздний
             * повторный перенос заявлений по API/CSV дозаполнил этот же документ, а не создал второй. */
            $this->save_map_row($kind, $info['legacy_id'], ['target_document_id'=>$documentId]);
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Подключение к старому сайту по API (устанавливается плагин-компаньон
     * zau-legacy-bridge-companion на uchet.zdravunion.kz). Данные считаются
     * достоверными: аккаунты дополняются/заменяются, у заявлений сохраняется
     * настоящая дата подачи и настоящая подпись со старого сайта. Сопоставление
     * — по тем же точным старым ID, что и в CSV-режиме, и обе карты общие,
     * поэтому CSV-перенос и перенос по API никогда не создают дублей друг друга.
     * ------------------------------------------------------------------- */

    const BRIDGE_NS = 'zau-legacy-bridge/v1';

    private function bridge_settings() {
        return wp_parse_args((array)get_option('zau_legacy_bridge_settings', []), ['url'=>'', 'secret'=>'']);
    }

    private function bridge_form_map() {
        return (array)get_option('zau_legacy_bridge_form_map', []);
    }

    /** Подписанный GET-запрос к API старого сайта. Тот же принцип HMAC, что проверяет плагин-компаньон. */
    private function bridge_request($path, $query = []) {
        $s = $this->bridge_settings();
        if (empty($s['url']) || empty($s['secret'])) { return new WP_Error('zau_bridge_settings', 'Укажите адрес старого сайта и секретный ключ.'); }
        $restRoute = '/' . self::BRIDGE_NS . $path;
        $query = (array)$query;
        ksort($query);
        $queryString = http_build_query($query);
        $url = untrailingslashit($s['url']) . '/wp-json' . $restRoute . ($queryString !== '' ? '?' . $queryString : '');
        if (!wp_http_validate_url($url)) { return new WP_Error('zau_bridge_url', 'Некорректный адрес старого сайта.'); }
        $timestamp = (string)time();
        $payload = $timestamp . "\nGET\n" . $restRoute . "\n" . $queryString;
        $signature = hash_hmac('sha256', $payload, (string)$s['secret']);
        $response = wp_remote_get($url, ['timeout'=>60, 'headers'=>[
            'X-ZAU-Timestamp'=>$timestamp,
            'X-ZAU-Signature'=>$signature,
            'Accept'=>'application/json',
        ]]);
        if (is_wp_error($response)) { return new WP_Error('zau_bridge_connection', 'Не удалось подключиться к старому сайту: ' . $response->get_error_message()); }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) && !empty($json['message']) ? sanitize_text_field($json['message']) : ('HTTP ' . $status);
            return new WP_Error('zau_bridge_http', 'Старый сайт ответил ошибкой: ' . $message);
        }
        if (!is_array($json)) { return new WP_Error('zau_bridge_json', 'Старый сайт вернул некорректный ответ.'); }
        return $json;
    }

    public function ajax_bridge_save_settings() {
        $this->require_access();
        $url = esc_url_raw(trim((string)wp_unslash($_POST['url'] ?? '')));
        $secret = sanitize_text_field((string)wp_unslash($_POST['secret'] ?? ''));
        if ($url === '' || $secret === '') { wp_send_json_error(['message'=>'Укажите адрес старого сайта и секретный ключ.'], 400); }
        update_option('zau_legacy_bridge_settings', ['url'=>$url, 'secret'=>$secret], false);
        wp_send_json_success(['message'=>'Настройки подключения сохранены.']);
    }

    public function ajax_bridge_test() {
        $this->require_access();
        $status = $this->bridge_request('/status');
        if (is_wp_error($status)) { wp_send_json_error(['message'=>$status->get_error_message()], 502); }
        wp_send_json_success($status);
    }

    public function ajax_bridge_list_forms() {
        $this->require_access();
        $resp = $this->bridge_request('/forms');
        if (is_wp_error($resp)) { wp_send_json_error(['message'=>$resp->get_error_message()], 502); }
        $map = $this->bridge_form_map();
        $forms = (array)($resp['forms'] ?? []);
        foreach ($forms as &$form) {
            $saved = $map[(string)$form['id']] ?? null;
            $form['target_form_id'] = $saved['target_form_id'] ?? 0;
            $form['template_id'] = $saved['template_id'] ?? 0;
            $form['field_map'] = $saved['field_map'] ?? [];
            if (!$form['field_map']) {
                foreach ((array)$form['fields'] as $field) {
                    $target = $this->auto_target((string)$field['label'], $this->application_fields());
                    if ($target !== '') { $form['field_map'][(string)$field['label']] = $target; }
                }
            }
        }
        unset($form);
        global $wpdb;
        $newForms = $wpdb->get_results("SELECT id,name FROM {$this->forms_table} ORDER BY name ASC");
        $templates = $wpdb->get_results("SELECT id,name FROM {$this->templates_table} ORDER BY name ASC");
        wp_send_json_success(['forms'=>$forms, 'new_forms'=>$newForms, 'templates'=>$templates, 'application_fields'=>$this->application_fields()]);
    }

    public function ajax_bridge_save_form_map() {
        $this->require_access();
        $formId = sanitize_text_field((string)($_POST['form_id'] ?? ''));
        if ($formId === '') { wp_send_json_error(['message'=>'Не указана форма старого сайта.'], 400); }
        $targetFormId = $this->ensure_form_id($_POST['target_form_id'] ?? 0);
        $templateId = absint($_POST['template_id'] ?? 0);
        if (!$targetFormId) { wp_send_json_error(['message'=>'Выберите форму нового кабинета для этой старой формы.'], 400); }
        if (!$templateId) { wp_send_json_error(['message'=>'Выберите PDF-шаблон для этой старой формы.'], 400); }
        $fieldMapRaw = json_decode((string)wp_unslash($_POST['field_map'] ?? ''), true);
        $allowed = array_keys($this->application_fields());
        $fieldMap = [];
        if (is_array($fieldMapRaw)) {
            foreach ($fieldMapRaw as $label=>$target) {
                $target = sanitize_key((string)$target);
                if ($target !== '' && in_array($target, $allowed, true)) { $fieldMap[sanitize_text_field((string)$label)] = $target; }
            }
        }
        $map = $this->bridge_form_map();
        $map[$formId] = ['target_form_id'=>$targetFormId, 'template_id'=>$templateId, 'field_map'=>$fieldMap];
        update_option('zau_legacy_bridge_form_map', $map, false);
        wp_send_json_success(['message'=>'Соответствие для формы сохранено.']);
    }

    public function ajax_bridge_start() {
        $this->require_access();
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        $formId = '';
        if ($scope === 'applications') {
            $formId = sanitize_text_field((string)($_POST['form_id'] ?? ''));
            $map = $this->bridge_form_map();
            if ($formId === '' || empty($map[$formId])) { wp_send_json_error(['message'=>'Сначала настройте соответствие для этой формы старого сайта.'], 400); }
        }
        $jobKey = $scope === 'accounts' ? 'bridge_accounts' : ('bridge_applications_' . $formId);
        $job = [
            'kind'=>'bridge',
            'scope'=>$scope,
            'form_id'=>$formId,
            'cursor'=>0,
            'total'=>0,
            'processed'=>0,
            'options'=>[
                'dry_run'=>!empty($_POST['dry_run']) ? 1 : 0,
                'overwrite'=>!empty($_POST['overwrite']) ? 1 : 0,
                'default_status'=>sanitize_text_field((string)($_POST['default_status'] ?? 'Состоит в профсоюзе')),
            ],
            'stats'=>['created'=>0,'updated'=>0,'existing'=>0,'linked'=>0,'skipped'=>0,'errors'=>0,'would_create'=>0,'would_update'=>0],
            'report'=>[],
            'log'=>[],
            'status'=>'running',
            'started_at'=>current_time('mysql'),
            'finished_at'=>'',
        ];
        update_option($this->job_option_key($jobKey), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    public function ajax_bridge_process() {
        $this->require_access();
        $scope = sanitize_key((string)($_POST['scope'] ?? ''));
        if (!in_array($scope, ['accounts','applications'], true)) { wp_send_json_error(['message'=>'Неизвестный тип переноса.'], 400); }
        $formId = $scope === 'applications' ? sanitize_text_field((string)($_POST['form_id'] ?? '')) : '';
        $jobKey = $scope === 'accounts' ? 'bridge_accounts' : ('bridge_applications_' . $formId);
        $job = (array)get_option($this->job_option_key($jobKey), []);
        if (!$job) { wp_send_json_error(['message'=>'Задание переноса не найдено.'], 404); }
        if (($job['status'] ?? '') === 'finished') { wp_send_json_success($this->public_job($job)); }

        if ($scope === 'accounts') {
            $resp = $this->bridge_request('/users', ['cursor'=>$job['cursor'], 'per_page'=>50]);
            if (is_wp_error($resp)) { wp_send_json_error(['message'=>$resp->get_error_message()], 502); }
            foreach ((array)($resp['users'] ?? []) as $row) {
                $job['processed']++;
                $result = $this->process_bridge_account_row($row, $job);
                $this->apply_result($job, $result);
            }
            $job['total'] = (int)($resp['total'] ?? $job['total']);
            $job['cursor'] = (int)($resp['next_cursor'] ?? $job['cursor']);
            $hasMore = !empty($resp['has_more']);
        } else {
            $map = $this->bridge_form_map();
            $formMap = $map[$formId] ?? null;
            if (!$formMap) { wp_send_json_error(['message'=>'Соответствие для этой формы не настроено.'], 400); }
            $resp = $this->bridge_request('/entries', ['form_id'=>$formId, 'cursor'=>$job['cursor'], 'per_page'=>30]);
            if (is_wp_error($resp)) { wp_send_json_error(['message'=>$resp->get_error_message()], 502); }
            foreach ((array)($resp['entries'] ?? []) as $entry) {
                $job['processed']++;
                $result = $this->process_bridge_application_row($entry, $formMap, $job);
                $this->apply_result($job, $result);
            }
            $job['total'] = (int)($resp['total'] ?? $job['total']);
            $job['cursor'] = (int)($resp['next_cursor'] ?? $job['cursor']);
            $hasMore = !empty($resp['has_more']);
        }
        if (!$hasMore) {
            $job['status'] = 'finished';
            $job['finished_at'] = current_time('mysql');
            $job['log'][] = 'Перенос по API завершён: ' . $job['processed'] . ' записей.';
        }
        if (count($job['report']) > 2000) { $job['report'] = array_slice($job['report'], -2000); }
        if (count($job['log']) > 100) { $job['log'] = array_slice($job['log'], -100); }
        update_option($this->job_option_key($jobKey), $job, false);
        wp_send_json_success($this->public_job($job));
    }

    private function process_bridge_account_row($row, $job) {
        $legacyId = (string)($row['legacy_user_id'] ?? '');
        if ($legacyId === '') { return ['kind'=>'error','row'=>$job['processed'],'message'=>'Старый сайт вернул запись без ID пользователя.']; }
        if (!empty($row['error'])) { return ['kind'=>'error','row'=>$job['processed'],'message'=>'Старый сайт: пропущен пользователь #'.$legacyId.' ('.$row['error'].').']; }
        $mapped = $this->normalize_mapped([
            'legacy_user_id'=>$legacyId,
            'email'=>$row['email'] ?? '',
            'phone'=>$row['phone'] ?? '',
            'full_name'=>$row['full_name'] ?? '',
            'first_name'=>$row['first_name'] ?? '',
            'last_name'=>$row['last_name'] ?? '',
            'iin'=>$row['iin'] ?? '',
            'birth_date'=>$row['birth_date'] ?? '',
            'address'=>$row['address'] ?? '',
            'position'=>$row['position'] ?? '',
            'department'=>$row['department'] ?? '',
            'organization'=>$row['organization'] ?? '',
            'organization_bin'=>$row['organization_bin'] ?? '',
            'organization_director'=>$row['organization_director'] ?? '',
            'organization_address'=>$row['organization_address'] ?? '',
            'branch_name'=>$row['branch_name'] ?? '',
            'registration_date'=>$row['registered'] ?? '',
        ]);
        $dry = !empty($job['options']['dry_run']);
        $overwrite = !empty($job['options']['overwrite']);
        $existingMap = $this->find_map_row('account', $legacyId);
        $userId = 0;
        if ($existingMap && $existingMap->target_user_id && get_user_by('id', (int)$existingMap->target_user_id)) {
            $userId = (int)$existingMap->target_user_id;
        } else {
            global $wpdb;
            $found = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_legacy_user_id' AND meta_value=%s ORDER BY user_id ASC LIMIT 1", $legacyId));
            if ($found) { $userId = $found; }
        }
        if ($dry) {
            return ['kind'=>$userId ? 'would_update' : 'would_create','row'=>$job['processed'],'message'=>$userId ? 'Аккаунт будет дополнен данными со старого сайта.' : 'Будет создан новый аккаунт.','user_id'=>$userId,'mapped'=>$mapped];
        }
        $created = false;
        if (!$userId) {
            $newUser = $this->create_account_user($mapped);
            if (is_wp_error($newUser)) { return ['kind'=>'error','row'=>$job['processed'],'message'=>$newUser->get_error_message(),'mapped'=>$mapped]; }
            $userId = (int)$newUser;
            $created = true;
        }
        /* Данные со старого сайта считаются достоверными — заполняют профиль и, если включена галочка,
           заменяют текущие значения. Статус участника никогда не откатывается назад через этот путь. */
        $updated = $this->update_account_user($userId, $mapped, $overwrite || $created, (string)$job['options']['default_status'], false);
        if (is_wp_error($updated)) { return ['kind'=>'error','row'=>$job['processed'],'message'=>$updated->get_error_message(),'user_id'=>$userId,'mapped'=>$mapped]; }
        $this->save_map_row('account', $legacyId, ['target_user_id'=>$userId,'status'=>'imported','message'=>'OK (API)']);
        return ['kind'=>$created ? 'created' : 'updated','row'=>$job['processed'],'message'=>$created ? 'Аккаунт создан по API.' : 'Аккаунт дополнен по API.','user_id'=>$userId,'mapped'=>$mapped];
    }

    /** Скачивает файл со старого сайта через защищённый /file и сохраняет локально. Возвращает URL или ''. */
    private function download_bridge_file($remoteUrl, $subdir, $prefix) {
        $remoteUrl = (string)$remoteUrl;
        if ($remoteUrl === '') { return ''; }
        $response = $this->bridge_request('/file', ['url'=>$remoteUrl]);
        if (is_wp_error($response) || empty($response['content_base64'])) { return ''; }
        $bytes = base64_decode((string)$response['content_base64'], true);
        if ($bytes === false || strlen($bytes) < 10) { return ''; }
        $mime = (string)($response['mime'] ?? '');
        $ext = 'png';
        if (strpos($mime, 'jpeg') !== false) { $ext = 'jpg'; }
        elseif (strpos($mime, 'pdf') !== false) { $ext = 'pdf'; }
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) { return ''; }
        $sub = trim($subdir, '/') . '/' . wp_date('Y/m');
        $dir = trailingslashit($uploads['basedir']) . $sub;
        if (!wp_mkdir_p($dir)) { return ''; }
        $name = sanitize_file_name($prefix . '-' . wp_generate_password(8, false, false)) . '.' . $ext;
        if (file_put_contents(trailingslashit($dir) . $name, $bytes, LOCK_EX) === false) { return ''; }
        return trailingslashit($uploads['baseurl']) . $sub . '/' . $name;
    }

    private function document_no_taken($no, $excludeId = 0) {
        global $wpdb;
        if ($no === '') { return false; }
        if ($excludeId) {
            return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->docs_table} WHERE document_no=%s AND id<>%d LIMIT 1", $no, $excludeId));
        }
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->docs_table} WHERE document_no=%s LIMIT 1", $no));
    }

    private function display_date($value) {
        $value = (string)$value;
        if ($value === '') { return wp_date('d.m.Y'); }
        $ts = strtotime($value);
        return $ts ? wp_date('d.m.Y', $ts) : wp_date('d.m.Y');
    }

    /** Создаёт (или обновляет данные, подписи и номер у уже существующего черновика) документ для заявления/
     * карточки, перенесённых по CSV или API. Поля document_no/issue_date/подписи/extra1-4 берутся из
     * сопоставленных полей старой формы, если админ их сопоставил — иначе используются значения по умолчанию.
     * PDF ещё не отрисован — это делает либо «Массовое пересоздание», либо шаг 3 «Привязка PDF», если готовый
     * PDF будет скопирован по FTP. Возвращает ID документа или WP_Error; если запрошенный document_no уже
     * занят другим документом, откатывается на автоматическую нумерацию и сообщает об этом в $collision. */
    private function upsert_document_for_submission($userId, $submissionId, $templateId, $data, $urls, $existingDocumentId, &$collision = null) {
        global $wpdb;
        $collision = '';
        $tpl = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id=%d", $templateId));
        if (!$tpl) { return new WP_Error('zau_document_template', 'Выбранный PDF-шаблон больше не существует.'); }
        $now = current_time('mysql');
        $signatureUrl = esc_url_raw((string)($urls['signature'] ?? ''));
        $signature2Url = esc_url_raw((string)($urls['signature2'] ?? ''));
        $stampUrl = esc_url_raw((string)($urls['stamp'] ?? ''));
        $issueDate = !empty($data['issue_date']) ? $this->display_date($data['issue_date']) : wp_date('d.m.Y');
        $extras = [
            'extra1' => sanitize_textarea_field((string)($data['extra1'] ?? '')),
            'extra2' => sanitize_textarea_field((string)($data['extra2'] ?? '')),
            'extra3' => sanitize_textarea_field((string)($data['extra3'] ?? '')),
            'extra4' => sanitize_textarea_field((string)($data['extra4'] ?? '')),
        ];
        $requestedNo = sanitize_text_field((string)($data['document_no'] ?? ''));

        if ($existingDocumentId) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d", $existingDocumentId));
            if ($existing && $existing->record_status === 'draft') {
                $update = [
                    'full_name'=>sanitize_text_field($data['full_name'] ?? ''),
                    'organization'=>sanitize_text_field($data['organization'] ?? ''),
                    'issue_date'=>$issueDate,
                    'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                    'updated_at'=>$now,
                ] + $extras;
                if ($signatureUrl !== '') { $update['signature_url'] = $signatureUrl; }
                if ($signature2Url !== '') { $update['signature2_url'] = $signature2Url; }
                if ($stampUrl !== '') { $update['stamp_url'] = $stampUrl; }
                if ($requestedNo !== '' && $requestedNo !== $existing->document_no) {
                    if ($this->document_no_taken($requestedNo, (int)$existingDocumentId)) {
                        $collision = 'Номер документа «' . $requestedNo . '» уже занят другим документом — оставлен прежний номер «' . $existing->document_no . '».';
                    } else {
                        $update['document_no'] = $requestedNo;
                    }
                }
                $wpdb->update($this->docs_table, $update, ['id'=>$existingDocumentId]);
                return (int)$existingDocumentId;
            }
            if ($existing) { return (int)$existingDocumentId; }
        }

        $token = bin2hex(random_bytes(24));
        $useRequestedNo = $requestedNo !== '' && !$this->document_no_taken($requestedNo);
        if ($requestedNo !== '' && !$useRequestedNo) { $collision = 'Номер документа «' . $requestedNo . '» уже занят другим документом — присвоен автоматический номер.'; }
        $row = [
            'template_id'=>$templateId,'user_id'=>$userId,'created_by'=>get_current_user_id(),'source_submission_id'=>$submissionId,
            'full_name'=>sanitize_text_field($data['full_name'] ?? ''),'document_title'=>$tpl->name,'organization'=>sanitize_text_field($data['organization'] ?? ''),
            'issue_date'=>$issueDate,'document_no'=>$useRequestedNo ? $requestedNo : ('PENDING-'.$token),
            'member_status'=>(string)get_user_meta($userId,'zau_member_status',true),
            'signature_url'=>$signatureUrl,'signature2_url'=>$signature2Url,'stamp_url'=>$stampUrl,
            'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),'orientation'=>$tpl->orientation,'verify_token'=>$token,
            'record_status'=>'draft','created_at'=>$now,'updated_at'=>$now,
        ] + $extras;
        if (!$wpdb->insert($this->docs_table, $row)) { return new WP_Error('zau_document_insert', 'Не удалось создать запись документа.'); }
        $documentId = (int)$wpdb->insert_id;
        if (!$useRequestedNo) {
            $documentNo = class_exists('ZAU_Certificate_PDF_Generator') ? ZAU_Certificate_PDF_Generator::instance()->format_document_number($tpl, $documentId) : ('DOC-' . $documentId);
            $wpdb->update($this->docs_table, ['document_no'=>$documentNo], ['id'=>$documentId]);
        }
        return $documentId;
    }

    /** Пытается скачать вложение со старого сайта через защищённый мост; если это не файл в его uploads
     * (внешняя ссылка), использует значение как обычный URL. Пустая строка на входе — пустая строка на выходе. */
    private function resolve_bridge_asset_url($value, $subdir, $prefix) {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('#^https?://#i', $value)) { return ''; }
        $downloaded = $this->download_bridge_file($value, $subdir, $prefix);
        return $downloaded !== '' ? $downloaded : esc_url_raw($value);
    }

    private function process_bridge_application_row($entry, $formMap, $job) {
        $entryId = (string)($entry['legacy_entry_id'] ?? '');
        $ownerLegacyId = (string)($entry['legacy_user_id'] ?? '');
        if (!empty($entry['error'])) {
            return ['kind'=>'error','row'=>$job['processed'],'message'=>'Старый сайт: пропущено заявление #'.$entryId.' ('.$entry['error'].').'];
        }
        if ($entryId === '' || $ownerLegacyId === '') {
            return ['kind'=>'skipped','row'=>$job['processed'],'message'=>'Запись старого сайта не привязана к аккаунту пользователя (заявление отправлено не под логином) — перенесите её вручную.'];
        }
        $accountMap = $this->find_map_row('account', $ownerLegacyId);
        $userId = ($accountMap && $accountMap->target_user_id) ? (int)$accountMap->target_user_id : 0;
        if (!$userId || !get_user_by('id', $userId)) {
            return ['kind'=>'skipped','row'=>$job['processed'],'message'=>'Аккаунт с ID Ultimate Member «'.$ownerLegacyId.'» ещё не перенесён — сначала перенесите аккаунты по API.','mapped'=>['legacy_user_id'=>$ownerLegacyId,'legacy_entry_id'=>$entryId]];
        }
        $rawFields = (array)($entry['fields'] ?? []);
        $fieldMap = (array)($formMap['field_map'] ?? []);
        $mappedRaw = [];
        foreach ($rawFields as $label=>$value) {
            $target = $fieldMap[$label] ?? '';
            if ($target !== '' && $value !== '') { $mappedRaw[$target] = $value; }
        }
        $mapped = $this->normalize_mapped($mappedRaw);
        $mapped['legacy_user_id'] = $ownerLegacyId;
        $mapped['legacy_entry_id'] = $entryId;
        $dry = !empty($job['options']['dry_run']);
        $existingMap = $this->find_map_row('application', $entryId);
        if ($dry) {
            return ['kind'=>$existingMap ? 'would_update' : 'would_create','row'=>$job['processed'],'message'=>$existingMap ? 'Заявление будет обновлено настоящими данными и подписью со старого сайта.' : 'Будет создана новая заявка с настоящей датой подачи и подписью.','user_id'=>$userId,'mapped'=>$mapped];
        }
        $formId = $this->ensure_form_id($formMap['target_form_id'] ?? 0);
        $templateId = absint($formMap['template_id'] ?? 0);
        if (!$formId || !$templateId) { return ['kind'=>'error','row'=>$job['processed'],'message'=>'Соответствие для этой формы настроено не полностью.','mapped'=>$mapped]; }

        $signatureUrl = '';
        if (!empty($entry['signature_url'])) {
            $signatureUrl = $this->download_bridge_file($entry['signature_url'], 'zau-signatures', 'legacy-' . $entryId);
        } elseif (!empty($mapped['signature_url'])) {
            $signatureUrl = $this->resolve_bridge_asset_url($mapped['signature_url'], 'zau-signatures', 'legacy-' . $entryId);
        }
        $signature2Url = !empty($mapped['signature2_url']) ? $this->resolve_bridge_asset_url($mapped['signature2_url'], 'zau-signatures', 'legacy-' . $entryId . '-2') : '';
        $stampUrl = !empty($mapped['stamp_url']) ? $this->resolve_bridge_asset_url($mapped['stamp_url'], 'zau-stamps', 'legacy-' . $entryId) : '';

        global $wpdb;
        $data = $mapped;
        $data['legacy_source'] = 'legacy_api_bridge';
        $data['legacy_import'] = 1;
        $data['legacy_imported_at'] = current_time('mysql');
        $data['legacy_fields'] = $rawFields;
        if ($signatureUrl !== '') { $data['signature_url'] = $signatureUrl; }
        /* Настоящая дата, когда человек заполнил форму на старом сайте — не «сейчас». */
        $createdAt = $this->mysql_date($entry['date_created'] ?? '') ?: current_time('mysql');
        $status = sanitize_key($entry['status'] ?? 'submitted') ?: 'submitted';
        $signatures = $signatureUrl !== '' ? ['signature'=>$signatureUrl] : [];

        $existingDocumentId = $existingMap ? (int)$existingMap->target_document_id : 0;
        if ($existingMap && $existingMap->target_submission_id) {
            $submissionId = (int)$existingMap->target_submission_id;
            $wpdb->update($this->submissions_table, [
                'status'=>$status,
                'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),
                'created_at'=>$createdAt,
                'updated_at'=>current_time('mysql'),
            ], ['id'=>$submissionId]);
            $resultKind = 'updated';
        } else {
            $wpdb->insert($this->submissions_table, ['form_id'=>$formId,'user_id'=>$userId,'status'=>$status,'data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),'signature_urls_json'=>wp_json_encode($signatures, JSON_UNESCAPED_UNICODE),'ip'=>'legacy-api-bridge','created_at'=>$createdAt,'updated_at'=>current_time('mysql')]);
            $submissionId = (int)$wpdb->insert_id;
            if (!$submissionId) { return ['kind'=>'error','row'=>$job['processed'],'message'=>'Не удалось сохранить заявку.','mapped'=>$mapped]; }
            $resultKind = 'created';
        }

        $ownerUser = get_user_by('id', $userId);
        $documentFullName = !empty($mapped['full_name']) ? $mapped['full_name'] : ($ownerUser ? $ownerUser->display_name : '');
        $collision = '';
        $urls = ['signature'=>$signatureUrl, 'signature2'=>$signature2Url, 'stamp'=>$stampUrl];
        $documentId = $this->upsert_document_for_submission($userId, $submissionId, $templateId, array_merge($data, ['full_name'=>$documentFullName]), $urls, $existingDocumentId, $collision);
        if (is_wp_error($documentId)) { $documentId = 0; }

        $this->save_map_row('application', $entryId, ['target_user_id'=>$userId,'target_submission_id'=>$submissionId,'target_document_id'=>(int)$documentId,'status'=>'imported','message'=>'OK (API)']);
        $message = $resultKind === 'created' ? 'Заявка создана по API.' : 'Заявка обновлена по API.';
        if ($collision !== '') { $message .= ' ' . $collision; }
        return ['kind'=>$resultKind,'row'=>$job['processed'],'message'=>$message,'user_id'=>$userId,'submission_id'=>$submissionId,'mapped'=>$mapped];
    }

    public function page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        global $wpdb;
        $forms = $wpdb->get_results("SELECT id,name FROM {$this->forms_table} ORDER BY name ASC");
        $templates = $wpdb->get_results("SELECT id,name FROM {$this->templates_table} ORDER BY name ASC");
        $pdf = $this->pdf_settings();
        $bridge = $this->bridge_settings();
        ?>
        <div class="wrap zau-legacy-wrap">
            <h1>Перенос со старого сайта</h1>
            <div class="notice notice-warning inline"><p><strong>Перед переносом сделайте резервную копию базы данных.</strong> Сопоставление ведётся только по точным старым ID (Ultimate Member и ID записи заявления) — без угадывания по ФИО, email или телефону, поэтому перенос безопасно повторять.</p></div>

            <section class="zau-ui-card">
                <h2>Как это работает</h2>
                <p>Два способа переноса — выберите один или сочетайте оба:</p>
                <ol>
                    <li><strong>По API (надёжнее и без ручных выгрузок).</strong> На старом сайте устанавливается отдельный плагин-компаньон «ZAU — мост экспорта старого кабинета» (папка <code>zau-legacy-bridge-companion</code> в этом же комплекте). Он отдаёт новому сайту аккаунты Ultimate Member и записи форм напрямую — с реальными подписями и настоящей датой заполнения заявления. Ничего не нужно выгружать и загружать руками, и невозможно перепутать файл с человеком, потому что данные приходят по точному ID, а не по имени файла.</li>
                    <li><strong>Через CSV (если доступа по API нет).</strong> Шаги 1–3 ниже: выгрузите CSV с ID пользователя и ID заявления, загрузите их по очереди, затем привяжите PDF по ID в имени файла.</li>
                </ol>
            </section>

            <section class="zau-ui-card" data-zau-remap>
                <h2>Восстановление полей у уже загруженных заявок</h2>
                <p class="description">Если заявки этой формы попали в базу не через сопоставление колонок этого плагина (например, напрямую в базу данных), их данные могут лежать под «сырыми» названиями старых полей вместо ФИО, организации, руководителя, филиала и т.д. — из-за этого поля не подставляются в PDF-документ и участник не появляется в реестре организации. Здесь можно сопоставить такие поля задним числом, не перезагружая файл заново. Галочка «Обновить ФИО и организацию в уже созданных документах» исправляет и уже сохранённые записи документов — но сам файл PDF нужно пересоздать отдельно через «Профсоюз → Массовое пересоздание», чтобы он отобразил исправленные данные.</p>
                <div class="zau-ui-grid">
                    <label>Форма для проверки<select data-zau-remap-form>
                        <option value="">— выберите форму —</option>
                        <?php foreach ($forms as $form): ?><option value="<?php echo (int)$form->id; ?>"><?php echo esc_html($form->name); ?></option><?php endforeach; ?>
                    </select></label>
                </div>
                <div class="zau-ui-actions">
                    <button type="button" class="button button-primary" data-zau-remap-scan>Проверить форму</button>
                </div>
                <p data-zau-remap-message></p>
                <div data-zau-remap-mapping hidden>
                    <div class="zau-ui-table-wrap"><table class="widefat striped"><thead><tr><th>Поле в заявке</th><th>Примеры значений</th><th>Заявок с этим полем</th><th>Сопоставить с</th></tr></thead><tbody data-zau-remap-table></tbody></table></div>
                    <div class="zau-ui-options">
                        <label><input type="checkbox" data-zau-remap-overwrite> Перезаписывать поле, если оно уже заполнено</label>
                        <label><input type="checkbox" data-zau-remap-sync checked> Обновить ФИО и организацию в уже созданных документах этих заявок</label>
                    </div>
                    <div class="zau-ui-actions">
                        <button type="button" class="button button-secondary" data-zau-remap-start="dry">Только проверить</button>
                        <button type="button" class="button button-primary button-hero" data-zau-remap-start="run">Исправить данные</button>
                    </div>
                </div>
                <div class="zau-ui-progress-wrap" data-zau-remap-progress hidden>
                    <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                    <p data-zau-progress-text></p>
                    <div class="zau-ui-stats" data-zau-stats></div>
                    <pre data-zau-log></pre>
                    <p><a class="button" data-zau-remap-report href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_legacy_remap_report'), self::NONCE)); ?>" target="_blank">Скачать список изменённых документов (CSV)</a></p>
                </div>
            </section>

            <section class="zau-ui-card" data-zau-bridge>
                <h2>Подключение по API</h2>
                <p class="description">Установите на <code>uchet.zdravunion.kz</code> плагин-компаньон из папки <code>zau-legacy-bridge-companion</code>, включите API в его настройках и скопируйте оттуда адрес и секретный ключ сюда.</p>
                <div class="zau-ui-grid">
                    <label>Адрес старого сайта<input type="text" data-zau-bridge-url value="<?php echo esc_attr($bridge['url']); ?>" placeholder="https://uchet.zdravunion.kz"></label>
                    <label>Секретный ключ<input type="text" data-zau-bridge-secret value="<?php echo esc_attr($bridge['secret']); ?>"></label>
                </div>
                <div class="zau-ui-actions">
                    <button type="button" class="button button-primary" data-zau-bridge-save-settings>Сохранить подключение</button>
                    <button type="button" class="button" data-zau-bridge-test>Проверить подключение</button>
                </div>
                <p data-zau-bridge-test-result></p>

                <h3>Шаг А. Аккаунты Ultimate Member по API</h3>
                <p class="description">Данные со старого сайта считаются достоверными: пустые поля заполняются, а с включённой галочкой — заменяют текущие значения. Статус участия никогда не откатывается назад.</p>
                <div class="zau-ui-options">
                    <label><input type="checkbox" data-zau-bridge-overwrite checked> Заменять уже заполненные поля более достоверными данными со старого сайта</label>
                </div>
                <div class="zau-ui-actions">
                    <button type="button" class="button button-secondary" data-zau-bridge-start="accounts:dry">Только проверить</button>
                    <button type="button" class="button button-primary button-hero" data-zau-bridge-start="accounts:import">Перенести аккаунты по API</button>
                </div>
                <div class="zau-ui-progress-wrap" data-zau-bridge-progress="accounts" hidden>
                    <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                    <p data-zau-progress-text></p>
                    <div class="zau-ui-stats" data-zau-stats></div>
                    <pre data-zau-log></pre>
                </div>

                <h3>Шаг Б. Формы старого сайта → шаблоны нового кабинета</h3>
                <p class="description">Для каждой формы старого сайта (заявление, личная карточка и т.д.) выберите, в какую форму нового кабинета сохранять записи и какой PDF-шаблон к ним применять — так данные точно лягут в нужный документ.</p>
                <div class="zau-ui-actions">
                    <button type="button" class="button" data-zau-bridge-load-forms>Загрузить список форм со старого сайта</button>
                </div>
                <div data-zau-bridge-forms></div>
                <div class="zau-ui-progress-wrap" data-zau-bridge-progress="applications" hidden>
                    <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                    <p data-zau-progress-text></p>
                    <div class="zau-ui-stats" data-zau-stats></div>
                    <pre data-zau-log></pre>
                </div>
            </section>

            <section class="zau-ui-card" data-zau-scope="accounts">
                <h2>Шаг 1. Аккаунты (Ultimate Member)</h2>
                <form class="zau-legacy-upload-form" data-scope="accounts" enctype="multipart/form-data">
                    <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                    <button type="submit" class="button button-primary">Загрузить CSV аккаунтов</button>
                </form>
                <div data-zau-mapping hidden>
                    <p data-zau-file-summary></p>
                    <div class="zau-ui-table-wrap"><table class="widefat striped" data-zau-mapping-table><thead><tr><th>Колонка CSV</th><th>Пример</th><th>Поле нового кабинета</th></tr></thead><tbody></tbody></table></div>
                    <div class="zau-ui-options">
                        <label><input type="checkbox" name="overwrite" value="1"> Заменять уже заполненные поля новыми значениями</label>
                    </div>
                    <div class="zau-ui-grid">
                        <label>Статус по умолчанию
                            <select name="default_status">
                                <?php foreach (['Заявление подано','На рассмотрении','Состоит в профсоюзе','Регистрация не завершена'] as $status): ?>
                                    <option <?php selected($status, 'Заявление подано'); ?>><?php echo esc_html($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Строк в одной партии<input type="number" name="batch_size" min="10" max="200" step="10" value="50"></label>
                    </div>
                    <div class="zau-ui-actions">
                        <button type="button" class="button button-secondary button-hero" data-zau-start="dry">Только проверить</button>
                        <button type="button" class="button button-primary button-hero" data-zau-start="import">Перенести аккаунты</button>
                        <button type="button" class="button" data-zau-reset>Сбросить загрузку</button>
                    </div>
                </div>
                <div class="zau-ui-progress-wrap" data-zau-progress hidden>
                    <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                    <p data-zau-progress-text></p>
                    <div class="zau-ui-stats" data-zau-stats></div>
                    <pre data-zau-log></pre>
                    <p><a class="button" data-zau-report="accounts" href="#">Скачать отчёт CSV</a></p>
                </div>
            </section>

            <section class="zau-ui-card" data-zau-scope="applications">
                <h2>Шаг 2. Заявления</h2>
                <p class="description">Каждая строка обязательно содержит ID записи заявления и ID пользователя Ultimate Member — владельца. Если аккаунт с таким ID ещё не перенесён, строка будет пропущена и попадёт в отчёт для ручной проверки.</p>
                <div class="zau-ui-grid">
                    <label>Форма, к которой привязать перенесённые заявления
                        <select data-zau-form-id>
                            <option value="">— выберите форму —</option>
                            <?php foreach ((array)$forms as $form): ?>
                                <option value="<?php echo (int)$form->id; ?>"><?php echo esc_html($form->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>PDF-шаблон документа (необязательно — сопоставьте «Номер документа», «Подпись» и доп. поля выше, чтобы данные попали в документ)
                        <select data-zau-csv-template>
                            <option value="0">— не создавать документ —</option>
                            <?php foreach ((array)$templates as $tpl): ?>
                                <option value="<?php echo (int)$tpl->id; ?>"><?php echo esc_html($tpl->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <form class="zau-legacy-upload-form" data-scope="applications" enctype="multipart/form-data">
                    <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                    <button type="submit" class="button button-primary">Загрузить CSV заявлений</button>
                </form>
                <div data-zau-mapping hidden>
                    <p data-zau-file-summary></p>
                    <div class="zau-ui-table-wrap"><table class="widefat striped" data-zau-mapping-table><thead><tr><th>Колонка CSV</th><th>Пример</th><th>Поле нового кабинета</th></tr></thead><tbody></tbody></table></div>
                    <div class="zau-ui-grid">
                        <label>Строк в одной партии<input type="number" name="batch_size" min="10" max="200" step="10" value="50"></label>
                    </div>
                    <div class="zau-ui-actions">
                        <button type="button" class="button button-secondary button-hero" data-zau-start="dry">Только проверить</button>
                        <button type="button" class="button button-primary button-hero" data-zau-start="import">Перенести заявления</button>
                        <button type="button" class="button" data-zau-reset>Сбросить загрузку</button>
                    </div>
                </div>
                <div class="zau-ui-progress-wrap" data-zau-progress hidden>
                    <div class="zau-ui-progress"><span data-zau-progress-bar></span></div>
                    <p data-zau-progress-text></p>
                    <div class="zau-ui-stats" data-zau-stats></div>
                    <pre data-zau-log></pre>
                    <p><a class="button" data-zau-report="applications" href="#">Скачать отчёт CSV</a></p>
                </div>
            </section>

            <section class="zau-ui-card" data-zau-pdf>
                <h2>Шаг 3. Привязка PDF по уникальному ID в имени файла</h2>
                <p class="description">Скопируйте PDF по FTP/SFTP в указанную папку внутри <code>wp-content/uploads/</code>. Для личной карточки ID в имени файла — это ID пользователя Ultimate Member, для заявления — ID записи заявления (тот же ID, что использовался при переносе на шагах 1–2).</p>
                <div class="zau-ui-grid">
                    <label>Папка внутри uploads/<input type="text" data-zau-pdf-folder value="<?php echo esc_attr($pdf['folder']); ?>"></label>
                </div>
                <h3>Личная карточка</h3>
                <div class="zau-ui-grid">
                    <label>Имя файла содержит (необязательно)<input type="text" data-zau-card-pattern value="<?php echo esc_attr($pdf['card_pattern']); ?>" placeholder="напр. карточка"></label>
                    <label>Регулярное выражение для ID<input type="text" data-zau-card-regex value="<?php echo esc_attr($pdf['card_regex']); ?>"></label>
                    <label>Шаблон PDF в новом кабинете
                        <select data-zau-card-template>
                            <option value="0">— выберите шаблон —</option>
                            <?php foreach ((array)$templates as $tpl): ?>
                                <option value="<?php echo (int)$tpl->id; ?>" <?php selected((int)$pdf['card_template_id'], (int)$tpl->id); ?>><?php echo esc_html($tpl->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <h3>Заявление</h3>
                <div class="zau-ui-grid">
                    <label>Имя файла содержит (необязательно)<input type="text" data-zau-application-pattern value="<?php echo esc_attr($pdf['application_pattern']); ?>" placeholder="напр. заявление"></label>
                    <label>Регулярное выражение для ID<input type="text" data-zau-application-regex value="<?php echo esc_attr($pdf['application_regex']); ?>"></label>
                    <label>Шаблон PDF в новом кабинете
                        <select data-zau-application-template>
                            <option value="0">— выберите шаблон —</option>
                            <?php foreach ((array)$templates as $tpl): ?>
                                <option value="<?php echo (int)$tpl->id; ?>" <?php selected((int)$pdf['application_template_id'], (int)$tpl->id); ?>><?php echo esc_html($tpl->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="zau-ui-actions">
                    <button type="button" class="button button-secondary button-hero" data-zau-pdf-scan>Просканировать папку</button>
                    <button type="button" class="button button-primary button-hero" data-zau-pdf-attach hidden>Привязать найденные PDF</button>
                </div>
                <div data-zau-pdf-result></div>
            </section>
        </div>
        <?php
    }
}

ZAU_Legacy_Import::instance();
