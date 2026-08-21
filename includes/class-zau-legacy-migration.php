<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Safe, repeatable migration assistant for the old uchet.zdravunion.kz stack.
 * It imports WPForms entries and registers existing PDF files without moving
 * or deleting their originals. The importer works in small AJAX batches.
 */
final class ZAU_Legacy_Migration {
    const VERSION = '2.10.0';
    const DB_VERSION = '1.0.0';
    const OPT_DB_VERSION = 'zau_legacy_migration_db_version';
    const OPT_SETTINGS = 'zau_legacy_migration_settings';
    const OPT_STATE = 'zau_legacy_migration_state';
    const OPT_MANIFEST = 'zau_legacy_pdf_manifest';
    const NONCE = 'zau_legacy_migration_nonce';

    private static $instance = null;
    private $map_table;
    private $forms_table;
    private $submissions_table;
    private $orgs_table;
    private $docs_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->map_table = $wpdb->prefix . 'zau_legacy_migration';
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->orgs_table = $wpdb->prefix . 'zau_union_organizations';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 35);
        add_action('admin_menu', [$this, 'admin_menu'], 35);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('wp_ajax_zau_legacy_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_legacy_step_entries', [$this, 'ajax_step_entries']);
        add_action('wp_ajax_zau_legacy_step_documents', [$this, 'ajax_step_documents']);
        add_action('wp_ajax_zau_legacy_reset_state', [$this, 'ajax_reset_state']);
        add_action('admin_post_zau_legacy_download_report', [$this, 'download_report']);
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$this->map_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_type varchar(40) NOT NULL,
                source_id varchar(191) NOT NULL,
                source_hash varchar(64) NULL,
                target_type varchar(40) NULL,
                target_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(30) NOT NULL DEFAULT 'imported',
                details longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY source_unique (source_type,source_id),
                KEY status (status),
                KEY target_lookup (target_type,target_id)
            ) $charset;");
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    public function admin_menu() {
        add_submenu_page(
            'zau-certificates',
            'Перенос старых данных',
            'Перенос старых данных',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-legacy-migration',
            [$this, 'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-legacy-migration') === false) { return; }
        wp_enqueue_style('zau-legacy-migration', plugins_url('../assets/css/legacy-migration.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('zau-legacy-migration', plugins_url('../assets/js/legacy-migration.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('zau-legacy-migration', 'ZAULegacyMigration', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'reportUrl' => wp_nonce_url(admin_url('admin-post.php?action=zau_legacy_download_report'), self::NONCE),
        ]);
    }

    private function require_access() {
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) && !current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Недостаточно прав.'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function wpforms_entries_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpforms_entries';
        return $this->table_exists($table) ? $table : '';
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT_SETTINGS, []), [
            'form_ids' => [],
            'import_entries' => 1,
            'import_pdfs' => 1,
            'create_missing_users' => 0,
            'update_user_profiles' => 1,
            'default_status' => 'Заявление подано',
            'filename_identity' => 'auto',
            'pdf_root_relative' => '',
            'pdf_patterns' => "*zayav*.pdf\n*заяв*.pdf\n*vstupl*.pdf\n*oplata*.pdf\n*vznos*.pdf\n*lichn*.pdf\n*kart*.pdf\n*sert*.pdf\n*certificate*.pdf",
            'batch_size' => 100,
        ]);
    }

    public function page() {
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) && !current_user_can('manage_options')) { wp_die('Недостаточно прав.'); }
        $diag = $this->diagnostics();
        $settings = $this->settings();
        $state = (array)get_option(self::OPT_STATE, []);
        ?>
        <div class="wrap zau-legacy-wrap">
            <h1>Перенос старых пользователей, заявлений и PDF</h1>
            <div class="notice notice-warning inline"><p><strong>Сначала сделайте полную резервную копию базы и папки <code>wp-content/uploads</code>.</strong> Импортёр не удаляет старые записи и файлы, но перенос на рабочем сайте следует сначала проверить в режиме «Только анализ».</p></div>

            <div class="zau-legacy-summary">
                <article><span>Пользователи WordPress</span><strong><?php echo number_format_i18n($diag['users']); ?></strong></article>
                <article><span>Записи WPForms</span><strong><?php echo number_format_i18n($diag['entries']); ?></strong><small><?php echo $diag['entries_table'] ? 'таблица найдена' : 'таблица не найдена'; ?></small></article>
                <article><span>Формы WPForms</span><strong><?php echo number_format_i18n(count($diag['forms'])); ?></strong></article>
                <article><span>Уже перенесено</span><strong><?php echo number_format_i18n($diag['mapped']); ?></strong></article>
            </div>

            <form id="zau-legacy-form" class="zau-legacy-card">
                <h2>1. Выберите источники</h2>
                <div class="zau-legacy-options">
                    <label><input type="checkbox" name="import_entries" value="1" <?php checked(!empty($settings['import_entries'])); ?>> Перенести записи WPForms в новые «Заявки участников»</label>
                    <label><input type="checkbox" name="import_pdfs" value="1" <?php checked(!empty($settings['import_pdfs'])); ?>> Зарегистрировать существующие PDF в новом реестре документов</label>
                    <label><input type="checkbox" name="update_user_profiles" value="1" <?php checked(!empty($settings['update_user_profiles'])); ?>> Заполнить новые профили данными из старых форм</label>
                    <label><input type="checkbox" name="create_missing_users" value="1" <?php checked(!empty($settings['create_missing_users'])); ?>> Создавать пользователя, если его нет, но в записи есть корректный email</label>
                </div>

                <h3>Формы WPForms для переноса</h3>
                <?php if (!$diag['forms']): ?>
                    <p>Формы с записями не найдены.</p>
                <?php else: ?>
                    <div class="zau-legacy-forms">
                    <?php foreach ($diag['forms'] as $form):
                        $checked = !$settings['form_ids'] || in_array((int)$form['form_id'], array_map('intval',(array)$settings['form_ids']), true);
                    ?>
                        <label class="zau-legacy-form-item">
                            <input type="checkbox" name="form_ids[]" value="<?php echo (int)$form['form_id']; ?>" <?php checked($checked); ?>>
                            <span><strong><?php echo esc_html($form['title']); ?></strong><small>ID <?php echo (int)$form['form_id']; ?> · записей: <?php echo number_format_i18n($form['count']); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <details class="zau-legacy-details">
                    <summary>Показать найденные поля форм</summary>
                    <?php foreach ($diag['forms'] as $form): ?>
                        <h4><?php echo esc_html($form['title']); ?> — ID <?php echo (int)$form['form_id']; ?></h4>
                        <p><?php echo $form['fields'] ? esc_html(implode(' · ', $form['fields'])) : 'Не удалось определить поля по образцу записи.'; ?></p>
                    <?php endforeach; ?>
                </details>

                <h2>2. Сопоставление старых PDF</h2>
                <div class="zau-legacy-grid">
                    <label>Число в имени файла означает
                        <select name="filename_identity">
                            <option value="auto" <?php selected($settings['filename_identity'],'auto'); ?>>Определить автоматически: сначала Entry ID, затем User ID</option>
                            <option value="entry_id" <?php selected($settings['filename_identity'],'entry_id'); ?>>ID записи WPForms</option>
                            <option value="user_id" <?php selected($settings['filename_identity'],'user_id'); ?>>ID пользователя WordPress</option>
                        </select>
                    </label>
                    <label>Корневая папка относительно uploads
                        <input type="text" name="pdf_root_relative" value="<?php echo esc_attr($settings['pdf_root_relative']); ?>" placeholder="Оставьте пустым для всей uploads">
                    </label>
                    <label>Статус старого участника
                        <select name="default_status">
                            <?php foreach (['Заявление подано','На рассмотрении','Состоит в профсоюзе','Регистрация не завершена'] as $status): ?>
                                <option <?php selected($settings['default_status'],$status); ?>><?php echo esc_html($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Размер одной партии
                        <input type="number" min="10" max="300" step="10" name="batch_size" value="<?php echo (int)$settings['batch_size']; ?>">
                    </label>
                </div>
                <label class="zau-legacy-wide">Маски имён PDF — одна на строку
                    <textarea name="pdf_patterns" rows="7"><?php echo esc_textarea($settings['pdf_patterns']); ?></textarea>
                </label>
                <p class="description">Старые PDF не перемещаются и не пересоздаются. Они только регистрируются в новом реестре и становятся доступны владельцу в новом кабинете через защищённый обработчик.</p>

                <h2>3. Запуск</h2>
                <div class="zau-legacy-actions">
                    <button type="button" class="button button-secondary button-hero" data-zau-legacy-start="dry">Только анализ</button>
                    <button type="button" class="button button-primary button-hero" data-zau-legacy-start="import">Начать перенос</button>
                    <button type="button" class="button" data-zau-legacy-reset>Сбросить только прогресс</button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_legacy_download_report'), self::NONCE)); ?>">Скачать отчёт CSV</a>
                </div>
            </form>

            <section class="zau-legacy-progress-card" data-zau-legacy-progress-wrap <?php echo $state ? '' : 'hidden'; ?>>
                <h2>Ход переноса</h2>
                <div class="zau-legacy-progress"><span data-zau-legacy-progress-bar></span></div>
                <p data-zau-legacy-progress-text><?php echo esc_html($state['message'] ?? 'Ожидание запуска'); ?></p>
                <pre data-zau-legacy-log><?php echo esc_html(implode("\n", (array)($state['log'] ?? []))); ?></pre>
            </section>

            <div class="zau-legacy-card">
                <h2>Что попадёт в новые кабинеты</h2>
                <ul>
                    <li>Существующие WordPress-пользователи сохраняют свои ID, логины и пароли.</li>
                    <li>Записи WPForms становятся строками в разделе «Заявления».</li>
                    <li>Старые PDF появляются в разделе «Документы» и в реестре ответственного организации.</li>
                    <li>Подписи из WPForms Signatures сохраняются как ссылки на существующие PNG; подписи, уже вшитые в PDF, остаются внутри оригинала.</li>
                    <li>Организация и БИН записываются в новый профиль и справочник организаций.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    private function diagnostics() {
        global $wpdb;
        $entries_table = $this->wpforms_entries_table();
        $forms = [];
        $entries = 0;
        if ($entries_table) {
            $entries = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$entries_table}");
            $rows = $wpdb->get_results("SELECT form_id,COUNT(*) total FROM {$entries_table} GROUP BY form_id ORDER BY total DESC", ARRAY_A);
            foreach ((array)$rows as $row) {
                $form_id = (int)$row['form_id'];
                $post = get_post($form_id);
                $sample = $wpdb->get_var($wpdb->prepare("SELECT fields FROM {$entries_table} WHERE form_id=%d AND fields<>'' ORDER BY entry_id DESC LIMIT 1", $form_id));
                $labels = [];
                foreach ($this->parse_wpforms_fields($sample) as $field) {
                    if ($field['label'] !== '') { $labels[] = '#'.$field['id'].' '.$field['label']; }
                }
                $forms[] = [
                    'form_id'=>$form_id,
                    'title'=>$post ? $post->post_title : 'Форма WPForms #'.$form_id,
                    'count'=>(int)$row['total'],
                    'fields'=>array_slice(array_values(array_unique($labels)),0,40),
                ];
            }
        }
        return [
            'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'entries'=>$entries,
            'entries_table'=>$entries_table,
            'forms'=>$forms,
            'mapped'=>$this->table_exists($this->map_table) ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->map_table} WHERE status='imported'") : 0,
        ];
    }

    public function ajax_start() {
        $this->require_access();
        global $wpdb;
        $mode = sanitize_key($_POST['mode'] ?? 'dry');
        $dry = $mode !== 'import';
        $form_ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['form_ids'] ?? [])))));
        $settings = [
            'form_ids'=>$form_ids,
            'import_entries'=>empty($_POST['import_entries'])?0:1,
            'import_pdfs'=>empty($_POST['import_pdfs'])?0:1,
            'create_missing_users'=>empty($_POST['create_missing_users'])?0:1,
            'update_user_profiles'=>empty($_POST['update_user_profiles'])?0:1,
            'default_status'=>sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'Заявление подано')),
            'filename_identity'=>in_array(sanitize_key($_POST['filename_identity'] ?? 'auto'), ['auto','entry_id','user_id'], true) ? sanitize_key($_POST['filename_identity']) : 'auto',
            'pdf_root_relative'=>$this->clean_relative_path(wp_unslash($_POST['pdf_root_relative'] ?? '')),
            'pdf_patterns'=>sanitize_textarea_field(wp_unslash($_POST['pdf_patterns'] ?? '')),
            'batch_size'=>max(10,min(300,absint($_POST['batch_size'] ?? 100))),
        ];
        update_option(self::OPT_SETTINGS, $settings, false);

        $entries_total = 0;
        $entries_table = $this->wpforms_entries_table();
        if ($settings['import_entries'] && $entries_table && $form_ids) {
            $placeholders = implode(',', array_fill(0,count($form_ids),'%d'));
            $entries_total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$entries_table} WHERE form_id IN ($placeholders)", $form_ids));
        }

        $manifest = [];
        if ($settings['import_pdfs']) { $manifest = $this->scan_pdf_manifest($settings); }
        update_option(self::OPT_MANIFEST, wp_json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), false);

        $state = [
            'dry_run'=>$dry?1:0,
            'phase'=>'entries',
            'entry_cursor'=>0,
            'entry_processed'=>0,
            'entry_total'=>$entries_total,
            'document_offset'=>0,
            'document_processed'=>0,
            'document_total'=>count($manifest),
            'imported_entries'=>0,
            'imported_documents'=>0,
            'created_users'=>0,
            'matched_users'=>0,
            'skipped'=>0,
            'errors'=>0,
            'message'=>$dry?'Анализ начат. Данные не будут записываться.':'Перенос начат.',
            'log'=>[
                ($dry?'Режим анализа':'Режим импорта'),
                'Записей WPForms в очереди: '.$entries_total,
                'PDF в очереди: '.count($manifest),
            ],
            'started_at'=>current_time('mysql'),
        ];
        update_option(self::OPT_STATE, $state, false);
        wp_send_json_success(['state'=>$state]);
    }

    public function ajax_step_entries() {
        $this->require_access();
        global $wpdb;
        $settings = $this->settings();
        $state = (array)get_option(self::OPT_STATE, []);
        if (!$state) { wp_send_json_error(['message'=>'Перенос не запущен.'],400); }
        $entries_table = $this->wpforms_entries_table();
        $form_ids = array_values(array_filter(array_map('absint',(array)$settings['form_ids'])));
        if (!$settings['import_entries'] || !$entries_table || !$form_ids || (int)$state['entry_processed'] >= (int)$state['entry_total']) {
            $state['phase'] = 'documents';
            $state['message'] = 'Записи WPForms обработаны. Переходим к PDF.';
            $this->append_log($state, $state['message']);
            update_option(self::OPT_STATE,$state,false);
            wp_send_json_success(['state'=>$state,'phase_done'=>1]);
        }
        $placeholders = implode(',',array_fill(0,count($form_ids),'%d'));
        $args = array_merge([(int)$state['entry_cursor']],$form_ids,[(int)$settings['batch_size']]);
        $sql = $wpdb->prepare("SELECT * FROM {$entries_table} WHERE entry_id>%d AND form_id IN ($placeholders) ORDER BY entry_id ASC LIMIT %d", $args);
        $rows = $wpdb->get_results($sql);
        if (!$rows) {
            $state['entry_processed'] = (int)$state['entry_total'];
            $state['phase'] = 'documents';
            $state['message'] = 'Записи WPForms обработаны. Переходим к PDF.';
            $this->append_log($state,$state['message']);
            update_option(self::OPT_STATE,$state,false);
            wp_send_json_success(['state'=>$state,'phase_done'=>1]);
        }
        foreach ($rows as $row) {
            $state['entry_cursor'] = (int)$row->entry_id;
            $state['entry_processed']++;
            try {
                $result = $this->process_entry($row,$settings,!empty($state['dry_run']));
                if ($result['status']==='imported') { $state['imported_entries']++; }
                if (!empty($result['created_user'])) { $state['created_users']++; }
                if (!empty($result['matched_user'])) { $state['matched_users']++; }
                if ($result['status']==='skipped') { $state['skipped']++; }
                if ($result['status']==='error') { $state['errors']++; }
            } catch (Throwable $e) {
                $state['errors']++;
                $this->append_log($state,'Ошибка entry #'.(int)$row->entry_id.': '.$e->getMessage());
            }
        }
        $state['message'] = 'WPForms: '.min((int)$state['entry_processed'],(int)$state['entry_total']).' из '.(int)$state['entry_total'];
        update_option(self::OPT_STATE,$state,false);
        wp_send_json_success(['state'=>$state,'phase_done'=>0]);
    }

    public function ajax_step_documents() {
        $this->require_access();
        $settings = $this->settings();
        $state = (array)get_option(self::OPT_STATE, []);
        if (!$state) { wp_send_json_error(['message'=>'Перенос не запущен.'],400); }
        $manifest = json_decode((string)get_option(self::OPT_MANIFEST,''),true);
        if (!is_array($manifest)) { $manifest=[]; }
        if (!$settings['import_pdfs'] || (int)$state['document_offset'] >= count($manifest)) {
            $state['phase']='done';
            $state['message']=!empty($state['dry_run'])?'Анализ завершён. Никакие данные не изменены.':'Перенос завершён.';
            $state['finished_at']=current_time('mysql');
            $this->append_log($state,$state['message']);
            update_option(self::OPT_STATE,$state,false);
            wp_send_json_success(['state'=>$state,'phase_done'=>1]);
        }
        $slice = array_slice($manifest,(int)$state['document_offset'],(int)$settings['batch_size']);
        foreach ($slice as $relative) {
            $state['document_offset']++;
            $state['document_processed']++;
            try {
                $result=$this->process_document($relative,$settings,!empty($state['dry_run']));
                if($result['status']==='imported')$state['imported_documents']++;
                elseif($result['status']==='skipped')$state['skipped']++;
                elseif($result['status']==='error')$state['errors']++;
            } catch (Throwable $e) {
                $state['errors']++;
                $this->append_log($state,'Ошибка PDF '.$relative.': '.$e->getMessage());
            }
        }
        $state['message']='PDF: '.min((int)$state['document_processed'],(int)$state['document_total']).' из '.(int)$state['document_total'];
        update_option(self::OPT_STATE,$state,false);
        wp_send_json_success(['state'=>$state,'phase_done'=>0]);
    }

    public function ajax_reset_state() {
        $this->require_access();
        delete_option(self::OPT_STATE);
        delete_option(self::OPT_MANIFEST);
        wp_send_json_success(['message'=>'Прогресс сброшен. Уже перенесённые данные не удалены.']);
    }

    private function process_entry($row,$settings,$dry) {
        global $wpdb;
        $source_id=(string)(int)$row->entry_id;
        if (!$dry && $this->mapping_exists('wpforms_entry',$source_id)) { return ['status'=>'duplicate']; }
        $fields=$this->parse_wpforms_fields($row->fields ?? '');
        $data=$this->normalize_entry_data($fields);
        $data['legacy_wpforms_entry_id']=(int)$row->entry_id;
        $data['legacy_wpforms_form_id']=(int)$row->form_id;
        $data['legacy_fields']=$fields;
        $created_user=false;
        $matched_user=false;
        $user_id=$this->resolve_user_id($row,$data,$settings,$dry,$created_user,$matched_user);
        if(!$user_id){return ['status'=>'skipped','reason'=>'user_not_found'];}
        $data['user_id']=$user_id;
        if($data['full_name']===''){$user=get_user_by('id',$user_id);$data['full_name']=$user?$user->display_name:'';}
        $legacy_form_id=$this->ensure_legacy_form((int)$row->form_id,$dry);
        $signature_urls=$this->signature_urls($fields);
        $created_at=$this->entry_date($row);
        if($dry){return ['status'=>'imported','created_user'=>$created_user,'matched_user'=>$matched_user];}
        if(!empty($settings['update_user_profiles'])){$this->update_user_profile($user_id,$data,$settings);}
        $wpdb->insert($this->submissions_table,[
            'form_id'=>$legacy_form_id,
            'user_id'=>$user_id,
            'status'=>'submitted',
            'data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'signature_urls_json'=>wp_json_encode($signature_urls,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'ip'=>sanitize_text_field($row->ip_address ?? ''),
            'created_at'=>$created_at,
            'updated_at'=>$created_at,
        ]);
        $submission_id=(int)$wpdb->insert_id;
        if(!$submission_id){return ['status'=>'error'];}
        $this->save_mapping('wpforms_entry',$source_id,md5((string)($row->fields??'')),'submission',$submission_id,['user_id'=>$user_id,'form_id'=>(int)$row->form_id]);
        return ['status'=>'imported','created_user'=>$created_user,'matched_user'=>$matched_user,'submission_id'=>$submission_id];
    }

    private function process_document($relative,$settings,$dry) {
        global $wpdb;
        $up=wp_upload_dir();
        $relative=$this->clean_relative_path($relative);
        if($relative===''||strpos($relative,'..')!==false)return ['status'=>'skipped'];
        $path=wp_normalize_path(trailingslashit($up['basedir']).$relative);
        $root=wp_normalize_path(trailingslashit($up['basedir']));
        if(strpos($path,$root)!==0||!is_file($path))return ['status'=>'skipped'];
        $source_id=sha1($relative);
        if(!$dry&&$this->mapping_exists('legacy_pdf',$source_id))return ['status'=>'duplicate'];
        $identity=$this->filename_identity($relative);
        $resolved=$this->resolve_document_owner($identity,$settings['filename_identity']);
        if(!$resolved['user_id'])return ['status'=>'skipped'];
        $user_id=(int)$resolved['user_id'];
        $submission_id=(int)$resolved['submission_id'];
        if(!$submission_id){$submission_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->submissions_table} WHERE user_id=%d ORDER BY id DESC LIMIT 1",$user_id));}
        $user=get_user_by('id',$user_id);if(!$user)return ['status'=>'skipped'];
        $title=$this->legacy_document_title($relative);
        $document_no=$this->legacy_document_number($relative,$identity);
        $issue_date=wp_date('d.m.Y',(int)@filemtime($path));
        $organization=(string)get_user_meta($user_id,'zau_profile_organization',true);
        if($organization==='')$organization=(string)get_user_meta($user_id,'zau_organization',true);
        if($dry)return ['status'=>'imported'];
        $url=trailingslashit($up['baseurl']).implode('/',array_map('rawurlencode',explode('/',$relative)));
        $token=hash('sha256',wp_generate_uuid4().'|'.$relative.'|'.microtime(true));
        $payload=['legacy_import'=>1,'legacy_relative_path'=>$relative,'legacy_identity'=>$identity,'legacy_source'=>'existing_pdf'];
        $wpdb->insert($this->docs_table,[
            'template_id'=>0,'user_id'=>$user_id,'created_by'=>get_current_user_id(),'source_submission_id'=>$submission_id,
            'full_name'=>$user->display_name,'document_title'=>$title,'organization'=>$organization,'issue_date'=>$issue_date,
            'document_no'=>$document_no,'member_status'=>(string)get_user_meta($user_id,'zau_member_status',true),
            'data_json'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'orientation'=>'portrait',
            'verify_token'=>$token,'image_url'=>'','pdf_url'=>$url,'file_revision'=>max(1,(int)@filemtime($path)),
            'record_status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
        ]);
        $doc_id=(int)$wpdb->insert_id;
        if(!$doc_id)return ['status'=>'error'];
        $this->save_mapping('legacy_pdf',$source_id,md5($relative.'|'.(string)@filesize($path).'|'.(string)@filemtime($path)),'document',$doc_id,['relative'=>$relative,'user_id'=>$user_id,'submission_id'=>$submission_id]);
        return ['status'=>'imported','document_id'=>$doc_id];
    }

    private function resolve_user_id($row,$data,$settings,$dry,&$created,&$matched) {
        $created=false;$matched=false;
        $uid=absint($row->user_id ?? 0);
        if($uid&&get_user_by('id',$uid)){$matched=true;return $uid;}
        if(!empty($data['email'])&&is_email($data['email'])){$user=get_user_by('email',$data['email']);if($user){$matched=true;return (int)$user->ID;}}
        if(!empty($data['phone'])){$uid=$this->find_user_by_phone($data['phone']);if($uid){$matched=true;return $uid;}}
        if(empty($settings['create_missing_users'])||empty($data['email'])||!is_email($data['email']))return 0;
        if($dry){$created=true;return 999999999;}
        $base=sanitize_user(strstr($data['email'],'@',true),true);if($base==='')$base='legacy-user';$login=$base.'-'.$row->entry_id;
        $n=1;while(username_exists($login)){$login=$base.'-'.$row->entry_id.'-'.$n;$n++;}
        $uid=wp_insert_user(['user_login'=>$login,'user_email'=>$data['email'],'user_pass'=>wp_generate_password(24,true,true),'display_name'=>$data['full_name']?:$login,'role'=>'subscriber']);
        if(is_wp_error($uid))return 0;$created=true;return (int)$uid;
    }

    private function find_user_by_phone($phone) {
        global $wpdb;
        $normalized=preg_replace('/\D/','',(string)$phone);if(strlen($normalized)<7)return 0;
        $keys=['zau_phone','phone','phone_member','user_email_6','billing_phone','mobile','mobile_number'];
        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders)",$keys));
        foreach((array)$rows as $row){if(preg_replace('/\D/','',(string)$row->meta_value)===$normalized)return (int)$row->user_id;}
        return 0;
    }

    private function update_user_profile($user_id,$data,$settings) {
        $map=[
            'phone'=>'zau_phone','full_name'=>'zau_profile_full_name','organization'=>'zau_profile_organization',
            'organization_bin'=>'zau_profile_organization_bin','organization_director'=>'zau_profile_organization_director',
            'region'=>'zau_profile_region','branch'=>'zau_profile_branch','position'=>'zau_profile_position'
        ];
        foreach($map as $source=>$meta){if(!empty($data[$source]))update_user_meta($user_id,$meta,sanitize_text_field($data[$source]));}
        if(!empty($data['phone'])){update_user_meta($user_id,'phone_member',sanitize_text_field($data['phone']));}
        if(!empty($data['region'])){update_user_meta($user_id,'regionreg',sanitize_text_field($data['region']));}
        if(!empty($data['first_name']))update_user_meta($user_id,'first_name',sanitize_text_field($data['first_name']));
        if(!empty($data['last_name']))update_user_meta($user_id,'last_name',sanitize_text_field($data['last_name']));
        if(!empty($data['full_name']))wp_update_user(['ID'=>$user_id,'display_name'=>sanitize_text_field($data['full_name'])]);
        $status=(string)get_user_meta($user_id,'zau_member_status',true);if($status==='')update_user_meta($user_id,'zau_member_status',$settings['default_status']);
        if(!empty($data['organization_bin'])&&!empty($data['organization'])){$org_id=$this->upsert_organization($data);if($org_id){update_user_meta($user_id,'zau_organization_id',$org_id);update_user_meta($user_id,'zau_organization_bin',preg_replace('/\D/','',$data['organization_bin']));}}
    }

    private function normalize_org_text($value) {
        $value=wp_strip_all_tags((string)$value);
        $value=trim(preg_replace('/\s+/u',' ',$value));
        $value=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
        $value=str_replace(['ё','«','»','„','“','”','"',"'"],['е','','','','','','',''],$value);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
    }

    private function org_names_plausibly_match($a,$b) {
        $normA=$this->normalize_org_text($a); $normB=$this->normalize_org_text($b);
        if($normA===''||$normB==='')return false;
        if($normA===$normB)return true;
        $stop=['гккп','гкп','кгп','кгу','гу','ргп','ргу','тоо','ао','ип','на','праве','хозяйственного','ведения','оперативного','управления','акимата','города','коммунальное','государственное','предприятие','учреждение','общественное','объединение'];
        $tokA=array_values(array_diff(array_filter(explode(' ',$normA),function($w){return (function_exists('mb_strlen')?mb_strlen($w,'UTF-8'):strlen($w))>2;}),$stop));
        $tokB=array_values(array_diff(array_filter(explode(' ',$normB),function($w){return (function_exists('mb_strlen')?mb_strlen($w,'UTF-8'):strlen($w))>2;}),$stop));
        if(!$tokA||!$tokB)return false;
        $intersect=count(array_intersect($tokA,$tokB)); $union=count(array_unique(array_merge($tokA,$tokB)));
        return $union>0 && ($intersect/$union)>=0.6;
    }

    private function find_or_create_name_only_organization($name,$director='',$address='',$region='') {
        global $wpdb;
        $name=sanitize_text_field($name); if($name==='')return 0;
        $exact=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->orgs_table} WHERE name=%s LIMIT 1",$name));
        if($exact)return $exact;
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->orgs_table} ORDER BY id ASC LIMIT 5000") as $row){
            if($this->org_names_plausibly_match($name,$row->name))return (int)$row->id;
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->orgs_table} (bin,name,director,address,region,source,updated_at) VALUES (NULL,%s,%s,%s,%s,'legacy_migration_name_only',%s)",
            $name,sanitize_text_field($director),sanitize_textarea_field($address),sanitize_text_field($region),current_time('mysql')
        ));
        return (int)$wpdb->insert_id;
    }

    private function upsert_organization($data) {
        global $wpdb;
        $bin=preg_replace('/\D/','',(string)($data['organization_bin']??''));if(strlen($bin)<8)return 0;
        $name=sanitize_text_field($data['organization']??'');
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->orgs_table} WHERE bin=%s LIMIT 1",$bin));
        $row=['bin'=>$bin,'name'=>$name,'director'=>sanitize_text_field($data['organization_director']??''),'address'=>sanitize_textarea_field($data['organization_address']??''),'region'=>sanitize_text_field($data['region']??''),'source'=>'legacy_migration','updated_at'=>current_time('mysql')];
        if($existing){
            if($name===''||$this->org_names_plausibly_match($name,$existing->name)){
                $wpdb->update($this->orgs_table,$row,['id'=>(int)$existing->id]);
                return (int)$existing->id;
            }
            // This BIN is legitimately shared by a different institution (e.g.
            // several facilities under one health department). Renaming the
            // existing row would misattribute every other member already
            // linked to it, so find or create a separate name-only record
            // instead of overwriting it.
            return $this->find_or_create_name_only_organization($name,$row['director'],$row['address'],$row['region']);
        }
        if($row['name']==='')$row['name']='Организация БИН '.$bin;$wpdb->insert($this->orgs_table,$row);return (int)$wpdb->insert_id;
    }

    private function ensure_legacy_form($old_form_id,$dry) {
        global $wpdb;
        $slug='legacy-wpforms-'.$old_form_id;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->forms_table} WHERE slug=%s LIMIT 1",$slug));if($id||$dry)return $id?:$old_form_id;
        $post=get_post($old_form_id);$name=$post?$post->post_title:'Старая форма WPForms #'.$old_form_id;
        $fields=[
            ['key'=>'full_name','label'=>'ФИО','type'=>'text','required'=>0],['key'=>'email','label'=>'Email','type'=>'email','required'=>0],
            ['key'=>'phone','label'=>'Телефон','type'=>'phone','required'=>0],['key'=>'region','label'=>'Регион','type'=>'text','required'=>0],
            ['key'=>'organization_bin','label'=>'БИН организации','type'=>'text','required'=>0],['key'=>'organization','label'=>'Организация','type'=>'text','required'=>0],
            ['key'=>'organization_director','label'=>'Руководитель','type'=>'text','required'=>0],['key'=>'branch','label'=>'Филиал','type'=>'text','required'=>0],
            ['key'=>'signature','label'=>'Подпись','type'=>'signature','required'=>0],
        ];
        $now=current_time('mysql');$wpdb->insert($this->forms_table,['name'=>$name.' — импорт','slug'=>$slug,'description'=>'Автоматически создано при переносе старых записей WPForms.','fields_json'=>wp_json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'template_ids_json'=>'[]','require_auth'=>1,'active'=>0,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);return (int)$wpdb->insert_id;
    }

    private function parse_wpforms_fields($json) {
        $decoded=json_decode((string)$json,true);if(!is_array($decoded))return [];$result=[];
        foreach($decoded as $id=>$field){if(!is_array($field))continue;$label=trim((string)($field['name']??$field['label']??''));$value=$field['value']??$field['value_raw']??'';if(is_array($value))$value=$this->array_to_text($value);$result[]=['id'=>(string)$id,'label'=>$label,'value'=>trim(wp_strip_all_tags((string)$value)),'raw'=>$field];}
        return $result;
    }

    private function array_to_text($value) {
        $flat=[];array_walk_recursive($value,function($v)use(&$flat){if(is_scalar($v)&&trim((string)$v)!=='')$flat[]=trim((string)$v);});return implode(' ',$flat);
    }

    private function normalize_entry_data($fields) {
        $data=['full_name'=>'','first_name'=>'','last_name'=>'','middle_name'=>'','email'=>'','phone'=>'','region'=>'','organization_bin'=>'','organization'=>'','organization_director'=>'','organization_address'=>'','branch'=>'','position'=>'','application_date'=>'','signature'=>''];
        foreach($fields as $field){$label=$this->lower($field['label']);$value=trim((string)$field['value']);if($value==='')continue;
            if($data['email']===''&&preg_match('/email|e-mail|электрон|почт/u',$label)&&is_email($value))$data['email']=sanitize_email($value);
            elseif($data['phone']===''&&preg_match('/телефон|phone|mobile|мобиль/u',$label))$data['phone']=$value;
            elseif($data['last_name']===''&&preg_match('/фамили/u',$label))$data['last_name']=$value;
            elseif($data['middle_name']===''&&preg_match('/отчеств/u',$label))$data['middle_name']=$value;
            elseif($data['first_name']===''&&preg_match('/(^|\s)имя($|\s)|first.?name/u',$label)&&!preg_match('/наименован/u',$label))$data['first_name']=$value;
            elseif($data['full_name']===''&&preg_match('/фио|ф\.и\.о|full.?name|члена профсоюза/u',$label))$data['full_name']=$value;
            elseif($data['organization_bin']===''&&preg_match('/бин|bin/u',$label))$data['organization_bin']=preg_replace('/\D/','',$value);
            elseif($data['organization_director']===''&&preg_match('/руководител|директор/u',$label))$data['organization_director']=$value;
            elseif($data['organization_address']===''&&preg_match('/адрес.*орган|юридическ.*адрес/u',$label))$data['organization_address']=$value;
            elseif($data['organization']===''&&preg_match('/организац|предприяти|учреждени|место работы|наименование/u',$label)&&!preg_match('/бин|руководител|адрес/u',$label))$data['organization']=$value;
            elseif($data['region']===''&&preg_match('/регион|область|город/u',$label))$data['region']=$value;
            elseif($data['branch']===''&&preg_match('/филиал|подраздел/u',$label))$data['branch']=$value;
            elseif($data['position']===''&&preg_match('/должност|position/u',$label))$data['position']=$value;
            elseif($data['application_date']===''&&preg_match('/дата.*заяв|дата.*подач/u',$label))$data['application_date']=$value;
            elseif($data['signature']===''&&preg_match('/подпис|signature/u',$label))$data['signature']=$this->first_url($field['raw'])?:$value;
        }
        if($data['full_name']==='')$data['full_name']=trim(implode(' ',array_filter([$data['last_name'],$data['first_name'],$data['middle_name']])));
        if($data['first_name']===''&&$data['full_name']!==''){$parts=preg_split('/\s+/u',$data['full_name']);if(count($parts)>=2){$data['last_name']=$data['last_name']?:$parts[0];$data['first_name']=$parts[1]??'';$data['middle_name']=$data['middle_name']?:($parts[2]??'');}}
        return $data;
    }

    private function signature_urls($fields) {
        $urls=[];foreach($fields as $field){if(!preg_match('/подпис|signature/u',$this->lower($field['label'])))continue;$url=$this->first_url($field['raw']);if($url)$urls[]=$url;}return array_values(array_unique($urls));
    }

    private function first_url($value) {
        if(is_array($value)){foreach($value as $item){$found=$this->first_url($item);if($found)return $found;}return '';}
        $value=(string)$value;if(preg_match('#https?://[^\s"\'<>]+#iu',$value,$m))return esc_url_raw(html_entity_decode($m[0]));return '';
    }

    private function entry_date($row) {
        foreach(['date','date_created','created_at'] as $key){if(!empty($row->$key)){ $ts=strtotime((string)$row->$key);if($ts)return wp_date('Y-m-d H:i:s',$ts); }}return current_time('mysql');
    }

    private function scan_pdf_manifest($settings) {
        $up=wp_upload_dir();$root=trailingslashit($up['basedir']);if(!empty($settings['pdf_root_relative']))$root.=trailingslashit($settings['pdf_root_relative']);
        $root=wp_normalize_path($root);if(!is_dir($root))return [];$patterns=preg_split('/[\r\n]+/',(string)$settings['pdf_patterns']);$patterns=array_values(array_filter(array_map('trim',$patterns)));if(!$patterns)$patterns=['*.pdf'];$files=[];
        try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $file){if(!$file->isFile()||strtolower($file->getExtension())!=='pdf')continue;$path=wp_normalize_path($file->getPathname());if(strpos($path,'/zau-certificates/')!==false)continue;$name=$file->getFilename();$match=false;foreach($patterns as $pattern){if($this->fnmatch_utf8($pattern,$name)){$match=true;break;}}if(!$match)continue;$relative=ltrim(substr($path,strlen(wp_normalize_path(trailingslashit($up['basedir'])))),'/');if($relative!=='')$files[]=$relative;}}catch(Throwable $e){return [];}
        sort($files,SORT_NATURAL|SORT_FLAG_CASE);return array_values(array_unique($files));
    }

    private function fnmatch_utf8($pattern,$name) {
        if(function_exists('fnmatch')&&fnmatch($pattern,$name,FNM_CASEFOLD))return true;$quoted=preg_quote($pattern,'#');$quoted=str_replace(['\*','\?'],['.*','.'],$quoted);return (bool)preg_match('#^'.$quoted.'$#iu',$name);
    }

    private function filename_identity($relative) {
        $base=pathinfo($relative,PATHINFO_FILENAME);preg_match_all('/\d+/', $base, $m);return $m[0]?(int)end($m[0]):0;
    }

    private function resolve_document_owner($identity,$mode) {
        global $wpdb;$result=['user_id'=>0,'submission_id'=>0];if(!$identity)return $result;
        if($mode==='auto'||$mode==='entry_id'){$map=$wpdb->get_row($wpdb->prepare("SELECT target_id FROM {$this->map_table} WHERE source_type='wpforms_entry' AND source_id=%s AND status='imported' LIMIT 1",(string)$identity));if($map){$submission=$wpdb->get_row($wpdb->prepare("SELECT id,user_id FROM {$this->submissions_table} WHERE id=%d",(int)$map->target_id));if($submission)return ['user_id'=>(int)$submission->user_id,'submission_id'=>(int)$submission->id];}if($mode==='entry_id')return $result;}
        if(($mode==='auto'||$mode==='user_id')&&get_user_by('id',$identity))return ['user_id'=>$identity,'submission_id'=>0];return $result;
    }

    private function legacy_document_title($relative) {
        $name=$this->lower(pathinfo($relative,PATHINFO_FILENAME));if(preg_match('/оплат|взнос|oplata|vznos|beznal/u',$name))return 'Заявление на безналичную уплату профсоюзного взноса';if(preg_match('/вступ|vstupl/u',$name))return 'Заявление о вступлении в Профсоюз';if(preg_match('/личн|карт|lichn|kart/u',$name))return 'Личная карточка участника';if(preg_match('/сертифик|удостовер|sert|certificate/u',$name))return 'Сертификат / удостоверение';return 'Ранее созданный документ';
    }

    private function legacy_document_number($relative,$identity) {
        $base=sanitize_file_name(pathinfo($relative,PATHINFO_FILENAME));if($base==='')$base='legacy-'.$identity;return substr('LEGACY-'.strtoupper($base),0,100);
    }

    private function mapping_exists($type,$id) {global $wpdb;return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->map_table} WHERE source_type=%s AND source_id=%s AND status='imported' LIMIT 1",$type,$id));}
    private function save_mapping($source_type,$source_id,$hash,$target_type,$target_id,$details=[]) {global $wpdb;$now=current_time('mysql');$wpdb->replace($this->map_table,['source_type'=>$source_type,'source_id'=>$source_id,'source_hash'=>$hash,'target_type'=>$target_type,'target_id'=>(int)$target_id,'status'=>'imported','details'=>wp_json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now,'updated_at'=>$now]);}
    private function append_log(&$state,$line){$log=(array)($state['log']??[]);$log[]=wp_strip_all_tags((string)$line);if(count($log)>60)$log=array_slice($log,-60);$state['log']=$log;}
    private function clean_relative_path($value){$value=str_replace('\\','/',trim((string)$value));$value=preg_replace('#/+#','/',$value);return trim($value,'/');}
    private function lower($value){return function_exists('mb_strtolower')?mb_strtolower((string)$value,'UTF-8'):strtolower((string)$value);}

    public function download_report() {
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) && !current_user_can('manage_options')) { wp_die('Недостаточно прав.'); }
        check_admin_referer(self::NONCE);
        global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$this->map_table} ORDER BY id ASC",ARRAY_A);
        nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="zau-migration-report-'.wp_date('Y-m-d-His').'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Источник','ID источника','Цель','ID цели','Статус','Детали','Дата'],';');foreach((array)$rows as $row)fputcsv($out,[$row['source_type'],$row['source_id'],$row['target_type'],$row['target_id'],$row['status'],$row['details'],$row['created_at']],';');fclose($out);exit;
    }
}

ZAU_Legacy_Migration::instance();
