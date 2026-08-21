<?php
if (!defined('ABSPATH')) { exit; }

final class ZAU_Union_Module {
    const VERSION = '2.18.2';
    const DB_VERSION = '2.18.2';
    const PIN_DEVICE_COOKIE = 'zau_pin_device';
    const OPT_DB_VERSION = 'zau_union_db_version';
    const OPT_SETTINGS = 'zau_union_settings';
    const NONCE = 'zau_union_nonce';

    private static $instance = null;
    private $forms_table;
    private $submissions_table;
    private $orgs_table;
    private $branches_table;
    private $otp_table;
    private $templates_table;
    private $docs_table;
    private $logs_table;
    private $benefit_render_context = [];

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->forms_table = $wpdb->prefix . 'zau_union_forms';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->orgs_table = $wpdb->prefix . 'zau_union_organizations';
        $this->branches_table = $wpdb->prefix . 'zau_union_branches';
        $this->otp_table = $wpdb->prefix . 'zau_union_otp';
        $this->templates_table = $wpdb->prefix . 'zau_cert_templates';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';
        $this->logs_table = $wpdb->prefix . 'zau_cert_logs';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 20);
        add_action('init', [$this, 'register_info_post_type']);
        add_action('init', [$this, 'register_benefit_post_type']);
        add_action('init', [$this, 'register_cabinet_tab_post_type']);
        add_action('init', [$this, 'maybe_bind_current_pin_device'], 20);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('add_meta_boxes_zau_union_tab', [$this, 'add_cabinet_tab_meta_boxes']);
        add_action('add_meta_boxes_zau_union_benefit', [$this, 'add_benefit_meta_boxes']);
        add_action('save_post_zau_union_benefit', [$this, 'save_benefit_meta'], 10, 2);
        add_action('save_post_zau_union_tab', [$this, 'save_cabinet_tab_meta'], 10, 2);
        add_filter('manage_zau_union_tab_posts_columns', [$this, 'cabinet_tab_columns']);
        add_action('manage_zau_union_tab_posts_custom_column', [$this, 'cabinet_tab_column_content'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'public_assets']);
        add_filter('show_admin_bar', [$this, 'filter_admin_bar']);
        add_action('admin_init', [$this, 'restrict_wp_admin'], 1);
        add_filter('login_redirect', [$this, 'filter_wordpress_login_redirect'], 20, 3);
        add_action('template_redirect', [$this, 'redirect_logged_in_auth_page'], 1);
        add_filter('option_elementor_cpt_support', [$this, 'elementor_cpt_support']);
        add_filter('default_option_elementor_cpt_support', [$this, 'elementor_cpt_support']);
        add_action('show_user_profile', [$this, 'user_profile_fields']);
        add_action('edit_user_profile', [$this, 'user_profile_fields']);
        add_action('personal_options_update', [$this, 'save_user_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_fields']);

        add_action('admin_post_zau_union_save_form', [$this, 'save_form']);
        add_action('admin_post_zau_union_delete_form', [$this, 'delete_form']);
        add_action('admin_post_zau_union_save_settings', [$this, 'save_settings']);
        add_action('admin_post_zau_union_save_org', [$this, 'save_organization']);
        add_action('admin_post_zau_union_delete_org', [$this, 'delete_organization']);
        add_action('admin_post_zau_union_import_orgs', [$this, 'import_organizations']);
        add_action('admin_post_zau_union_save_member_access', [$this, 'save_member_access']);
        add_action('admin_post_zau_union_bulk_member_status', [$this, 'bulk_member_status_action']);
        add_action('admin_post_zau_union_repair_exit_documents', [$this, 'repair_exit_documents_action']);
        add_action('admin_post_zau_union_save_branch', [$this, 'save_branch']);
        add_action('admin_post_zau_union_delete_branch', [$this, 'delete_branch']);
        add_action('admin_post_zau_union_import_branches_text', [$this, 'import_branches_text']);
        add_action('admin_post_zau_union_save_branch_order', [$this, 'save_branch_order']);
        add_action('admin_post_zau_union_export_org_registry_excel', [$this, 'export_org_registry_excel']);
        add_action('admin_post_zau_union_download_org_documents_zip', [$this, 'download_org_documents_zip']);
        add_action('admin_post_zau_union_download_org_mismatch_report', [$this, 'download_org_mismatch_report']);
        add_action('admin_post_zau_union_apply_org_fix', [$this, 'apply_organization_fix']);

        add_action('wp_ajax_nopriv_zau_union_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_zau_union_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_zau_union_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_zau_union_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_zau_union_password_login', [$this, 'ajax_password_login']);
        add_action('wp_ajax_zau_union_password_login', [$this, 'ajax_password_login']);
        add_action('wp_ajax_nopriv_zau_union_pin_login', [$this, 'ajax_pin_login']);
        add_action('wp_ajax_zau_union_pin_login', [$this, 'ajax_pin_login']);
        add_action('wp_ajax_nopriv_zau_union_request_pin_reset', [$this, 'ajax_request_pin_reset']);
        add_action('wp_ajax_zau_union_request_pin_reset', [$this, 'ajax_request_pin_reset']);
        add_action('wp_ajax_nopriv_zau_union_reset_pin', [$this, 'ajax_reset_pin']);
        add_action('wp_ajax_zau_union_reset_pin', [$this, 'ajax_reset_pin']);
        add_action('wp_ajax_nopriv_zau_union_lookup_bin', [$this, 'ajax_lookup_bin']);
        add_action('wp_ajax_zau_union_lookup_bin', [$this, 'ajax_lookup_bin']);
        add_action('wp_ajax_nopriv_zau_union_suggest_party_kz', [$this, 'ajax_suggest_party_kz']);
        add_action('wp_ajax_zau_union_suggest_party_kz', [$this, 'ajax_suggest_party_kz']);
        add_action('wp_ajax_nopriv_zau_union_submit_form', [$this, 'ajax_submit_form']);
        add_action('wp_ajax_zau_union_submit_form', [$this, 'ajax_submit_form']);
        add_action('wp_ajax_nopriv_zau_union_refresh_nonce', [$this, 'ajax_refresh_nonce']);
        add_action('wp_ajax_zau_union_refresh_nonce', [$this, 'ajax_refresh_nonce']);
        add_action('wp_ajax_zau_union_finalize_document', [$this, 'ajax_finalize_document']);
        add_action('wp_ajax_zau_union_prepare_submission_documents', [$this, 'ajax_prepare_submission_documents']);
        add_action('wp_ajax_zau_union_cabinet_documents', [$this, 'ajax_cabinet_documents']);
        add_action('wp_ajax_zau_union_manager_update_member', [$this, 'ajax_manager_update_member']);
        add_action('wp_ajax_zau_union_manager_bulk_update_members', [$this, 'ajax_manager_bulk_update_members']);
        add_action('wp_ajax_zau_union_membership_decision', [$this, 'ajax_membership_decision']);
        add_action('wp_ajax_zau_union_save_member_card', [$this, 'ajax_save_member_card']);
        add_action('wp_ajax_zau_union_review_member_card', [$this, 'ajax_review_member_card']);
        add_action('wp_ajax_zau_union_reveal_sensitive', [$this, 'ajax_reveal_sensitive']);
        add_action('wp_ajax_nopriv_zau_union_get_branch', [$this, 'ajax_get_branch']);
        add_action('wp_ajax_zau_union_get_branch', [$this, 'ajax_get_branch']);
        add_action('wp_ajax_zau_union_export_org_registry_pdf', [$this, 'ajax_export_org_registry_pdf']);
        add_action('wp_logout', [$this, 'log_logout'], 10, 1);
        add_action('zau_cert_template_saved', [$this, 'auto_link_saved_template'], 10, 2);
        add_action('zau_union_repair_exit_documents_cron', [$this, 'repair_exit_documents_cron']);

        add_shortcode('zau_union_auth', [$this, 'auth_shortcode']);
        add_shortcode('zau_union_form', [$this, 'form_shortcode']);
        add_shortcode('zau_union_portal', [$this, 'portal_shortcode']);
        add_shortcode('zau_union_cabinet', [$this, 'cabinet_shortcode']);
        add_shortcode('zau_union_org_members', [$this, 'organization_members_shortcode']);
        add_shortcode('zau_union_cabinet_section', [$this, 'cabinet_section_shortcode']);
        add_shortcode('zau_union_org_registry', [$this, 'organization_registry_shortcode']);
        add_shortcode('zau_union_custom_tab', [$this, 'custom_tab_shortcode']);
        add_shortcode('zau_union_member_card', [$this, 'member_card_shortcode']);
        add_shortcode('zau_union_benefits', [$this, 'benefits_shortcode']);

        add_filter('zau_cert_field_definitions', [$this, 'certificate_field_definitions']);
        add_filter('zau_cert_field_types', [$this, 'certificate_field_types']);
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            $this->install_defaults();
            $this->initialize_branch_order();
            $cert_settings = wp_parse_args((array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS, []), ['manager_pdf_view'=>1]);
            $cert_settings['manager_pdf_view'] = 1;
            update_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS, $cert_settings, false);
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
            delete_option('zau_union_exit_repair_cursor');
            if (!wp_next_scheduled('zau_union_repair_exit_documents_cron')) {
                wp_schedule_single_event(time() + 10, 'zau_union_repair_exit_documents_cron');
            }
        }
    }

    private function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->forms_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description text NULL,
            fields_json longtext NULL,
            template_ids_json text NULL,
            require_auth tinyint(1) NOT NULL DEFAULT 1,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY active (active)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->submissions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'submitted',
            data_json longtext NULL,
            signature_urls_json longtext NULL,
            ip varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->orgs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bin varchar(20) NULL,
            name varchar(255) NOT NULL,
            director varchar(255) NULL,
            address text NULL,
            region varchar(190) NULL,
            source varchar(50) NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY bin (bin),
            KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->branches_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(80) NOT NULL,
            name varchar(255) NOT NULL,
            region varchar(190) NULL,
            union_bin varchar(20) NULL,
            iban varchar(80) NULL,
            bik varchar(40) NULL,
            bank_name varchar(255) NULL,
            legal_address text NULL,
            chairman varchar(255) NULL,
            chairman_phone varchar(100) NULL,
            accountant varchar(255) NULL,
            accountant_phone varchar(100) NULL,
            email varchar(190) NULL,
            phone varchar(100) NULL,
            requisites longtext NULL,
            details_mode varchar(20) NOT NULL DEFAULT 'text',
            full_details longtext NULL,
            identity_details longtext NULL,
            bank_details longtext NULL,
            extra_json longtext NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY active (active),
            KEY sort_order (sort_order),
            KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->otp_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            destination_hash varchar(64) NOT NULL,
            channel varchar(20) NOT NULL,
            code_hash varchar(255) NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            verified tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            ip varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY destination_hash (destination_hash),
            KEY expires_at (expires_at)
        ) $charset;");

        // Расширение реестра документов выполняется без удаления старых строк.
        dbDelta("CREATE TABLE {$this->docs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            template_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            source_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            full_name varchar(255) NOT NULL,
            document_title varchar(255) NULL,
            organization varchar(255) NULL,
            issue_date varchar(50) NULL,
            document_no varchar(100) NOT NULL,
            member_status varchar(100) NULL,
            extra1 text NULL,
            extra2 text NULL,
            extra3 text NULL,
            extra4 text NULL,
            signature_url text NULL,
            signature2_url text NULL,
            stamp_url text NULL,
            data_json longtext NULL,
            orientation varchar(20) NOT NULL DEFAULT 'landscape',
            verify_token varchar(64) NOT NULL,
            image_url text NULL,
            pdf_url text NULL,
            record_status varchar(20) NOT NULL DEFAULT 'draft',
            revoked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY verify_token (verify_token),
            KEY document_no (document_no),
            KEY user_id (user_id),
            KEY source_submission_id (source_submission_id),
            KEY record_status (record_status)
        ) $charset;");
    }

    private function install_defaults() {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->forms_table}");
        if ($count === 0) {
            $fields = [
                ['key'=>'registration_heading','label'=>'Регистрационные данные','type'=>'heading','required'=>0,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'first_name','label'=>'Имя','type'=>'text','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'last_name','label'=>'Фамилия','type'=>'text','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'middle_name','label'=>'Отчество','type'=>'text','required'=>0,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'region','label'=>'Регион','type'=>'text','required'=>1,'placeholder'=>'город, Астана','options'=>'','default'=>''],
                ['key'=>'phone','label'=>'Телефон','type'=>'phone','required'=>1,'placeholder'=>'+7 700 000 00 00','options'=>'','default'=>''],
                ['key'=>'email','label'=>'Email адрес','type'=>'email','required'=>1,'placeholder'=>'name@example.kz','options'=>'','default'=>''],
                ['key'=>'privacy_consent','label'=>'Согласен(на) с политикой конфиденциальности и обработкой персональных данных','type'=>'checkbox','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'contribution_heading','label'=>'Заявление на безналичную уплату профсоюзного взноса','type'=>'heading','required'=>0,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'organization_bin','label'=>'БИН/ИИН организации','type'=>'bin_lookup','required'=>1,'placeholder'=>'12 цифр','options'=>'','default'=>''],
                ['key'=>'organization','label'=>'Наименование предприятия, организации','type'=>'text','required'=>1,'placeholder'=>'Заполняется после поиска по БИН','options'=>'','default'=>''],
                ['key'=>'organization_director','label'=>'Ф.И.О. руководителя','type'=>'text','required'=>0,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'organization_address','label'=>'Адрес организации','type'=>'text','required'=>0,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'branch','label'=>'Филиал Профсоюза','type'=>'branch_select','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'contribution_consent','label'=>'Прошу ежемесячно удерживать членский профсоюзный взнос в установленном размере','type'=>'checkbox','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
                ['key'=>'signature','label'=>'Подпись','type'=>'signature','required'=>1,'placeholder'=>'','options'=>'','default'=>''],
            ];
            $now = current_time('mysql');
            $wpdb->insert($this->forms_table, [
                'name'=>'Регистрация и заявления в профсоюз',
                'slug'=>'registraciya-i-zayavleniya',
                'description'=>'Регистрация участника, поиск организации по БИН, заявление на вступление и уплату взносов.',
                'fields_json'=>wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
                'template_ids_json'=>'[]',
                'require_auth'=>1,
                'active'=>1,
                'created_by'=>get_current_user_id(),
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            $settings = $this->settings();
            $settings['default_form_id'] = (int) $wpdb->insert_id;
            update_option(self::OPT_SETTINGS, $settings, false);
        }
        $this->ensure_default_branch();
        $this->backfill_branch_text_blocks();
        $this->ensure_default_form_fields();
        $this->install_bundled_statement_templates();
        $this->ensure_pages();
        $this->repair_empty_form_template_links();
    }

    private function ensure_default_branch() {
        global $wpdb;
        $exists = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->branches_table}");
        if ($exists > 0) { return; }
        $now = current_time('mysql');
        $requisites = 'ИИК KZ688560000004352129; БИК KCJBKZKX; БИН 110241010418; АО «Банк ЦентрКредит»; юридический адрес: г. Астана, Күлтегін 11/1; председатель Тәжібай Бақытжан Арынұлы, 87015440289; главный бухгалтер Есько Любовь Владимировна, 87021838342.';
        $wpdb->insert($this->branches_table, [
            'code'=>'astana',
            'name'=>'Филиал Астана',
            'region'=>'г. Астана',
            'union_bin'=>'110241010418',
            'iban'=>'KZ688560000004352129',
            'bik'=>'KCJBKZKX',
            'bank_name'=>'АО «Банк ЦентрКредит»',
            'legal_address'=>'г. Астана, Күлтегін 11/1',
            'chairman'=>'Тәжібай Бақытжан Арынұлы',
            'chairman_phone'=>'87015440289',
            'accountant'=>'Есько Любовь Владимировна',
            'accountant_phone'=>'87021838342',
            'requisites'=>$requisites,
            'details_mode'=>'text',
            'identity_details'=>"Филиал Астана Казахстанского отраслевого профсоюза работников здравоохранения и общественного обслуживания «AQNİET»\nЮридический адрес: г. Астана, Күлтегін 11/1\nБИН: 110241010418",
            'bank_details'=>"ИИК: KZ688560000004352129\nБИК: KCJBKZKX\nБанк: АО «Банк ЦентрКредит»",
            'full_details'=>"Филиал Астана Казахстанского отраслевого профсоюза работников здравоохранения и общественного обслуживания «AQNİET»\nЮридический адрес: г. Астана, Күлтегін 11/1\nБИН: 110241010418\nИИК: KZ688560000004352129\nБИК: KCJBKZKX\nБанк: АО «Банк ЦентрКредит»\nПредседатель: Тәжібай Бақытжан Арынұлы, 87015440289\nГлавный бухгалтер: Есько Любовь Владимировна, 87021838342",
            'active'=>1,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
    }

    private function ensure_default_form_fields() {
        global $wpdb;
        $settings = $this->settings();
        $form = $this->get_form(absint($settings['default_form_id']));
        if (!$form) { $form = $this->get_form(); }
        if (!$form) { return; }
        $fields = $this->decode_form_fields($form->fields_json);
        $keys = [];
        foreach ($fields as &$field) {
            $keys[$field['key']] = true;
            if ($field['key'] === 'branch' && $field['type'] !== 'branch_select') {
                $field['type'] = 'branch_select';
                $field['options'] = '';
            }
        }
        unset($field);
        $membership = [];
        if (empty($keys['membership_heading'])) {
            $membership[] = ['key'=>'membership_heading','label'=>'Заявление о вступлении в Профсоюз «AQNİET»','type'=>'heading','required'=>0,'placeholder'=>'','options'=>'','default'=>''];
        }
        if (empty($keys['membership_consent'])) {
            $membership[] = ['key'=>'membership_consent','label'=>'Прошу принять меня в члены Профсоюза «AQNİET» и поставить на учёт','type'=>'checkbox','required'=>1,'placeholder'=>'','options'=>'','default'=>''];
        }
        if ($membership) {
            $insertIndex = count($fields);
            foreach ($fields as $index=>$field) {
                if ($field['key'] === 'contribution_heading' || $field['type'] === 'signature') { $insertIndex = $index; break; }
            }
            array_splice($fields, $insertIndex, 0, $membership);
        }
        if (empty($keys['branch_requisites'])) {
            $requisites = [[
                'key'=>'branch_requisites',
                'label'=>'Банковские реквизиты филиала Профсоюза',
                'type'=>'hidden',
                'required'=>0,
                'placeholder'=>'',
                'options'=>'',
                'default'=>'ИИК KZ688560000004352129 БИК KCJBKZKX БИН 110241010418 АО «Банк ЦентрКредит». Юридический адрес: г. Астана, Күлтегін 11/1. Контактные данные: председатель Тәжібай Бақытжан Арынұлы 87015440289; главный бухгалтер Есько Любовь Владимировна 87021838342.'
            ]];
            $insertIndex = count($fields);
            foreach ($fields as $index=>$field) { if ($field['type'] === 'signature') { $insertIndex = $index; break; } }
            array_splice($fields, $insertIndex, 0, $requisites);
        }
        $wpdb->update($this->forms_table, [
            'fields_json'=>wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql'),
        ], ['id'=>(int)$form->id]);
    }

    private function disabled_certificate_fields() {
        $keys = ['full_name','document_title','organization','issue_date','document_no','member_status','extra1','extra2','extra3','extra4','signature_url','signature2_url','stamp_url','verify_url','qr'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ['enabled'=>0,'x'=>0,'y'=>0,'width'=>40,'height'=>10,'fontSize'=>28,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.15,'maxLines'=>2,'fit'=>'contain','opacity'=>1];
        }
        return $out;
    }

    private function bundled_membership_fields() {
        $f = $this->disabled_certificate_fields();
        $f['document_no'] = ['enabled'=>1,'x'=>39.5,'y'=>10.1,'width'=>48,'fontSize'=>29,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>1,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['organization'] = ['enabled'=>1,'x'=>26,'y'=>17.3,'width'=>63.5,'fontSize'=>28,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.12,'maxLines'=>3];
        $f['full_name'] = ['enabled'=>1,'x'=>26,'y'=>28.2,'width'=>63.5,'fontSize'=>31,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['submission_date'] = ['enabled'=>1,'x'=>6.3,'y'=>52.4,'width'=>30.8,'fontSize'=>27,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['signature_url'] = ['enabled'=>1,'x'=>51.8,'y'=>51.7,'width'=>38.3,'height'=>9.6,'fit'=>'contain','opacity'=>1,'fontSize'=>8,'fontFamily'=>'Arial','color'=>'#111111','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1];
        $f['qr'] = ['enabled'=>1,'x'=>6.2,'y'=>79.7,'width'=>13.8,'fontSize'=>0,'fontFamily'=>'Arial','color'=>'#000000','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1];
        $f['verify_url'] = ['enabled'=>1,'x'=>5.3,'y'=>94.0,'width'=>38,'fontSize'=>17,'fontFamily'=>'Arial','color'=>'#555555','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.15,'maxLines'=>2];
        return $f;
    }

    private function bundled_contribution_fields() {
        $f = $this->disabled_certificate_fields();
        $f['document_no'] = ['enabled'=>1,'x'=>39.5,'y'=>10.1,'width'=>26,'fontSize'=>27,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>1,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['submission_date'] = ['enabled'=>1,'x'=>72.0,'y'=>10.1,'width'=>20,'fontSize'=>27,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>1,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['member_name_header'] = ['enabled'=>1,'x'=>26,'y'=>12.4,'width'=>65,'fontSize'=>31,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>1,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['organization'] = ['enabled'=>1,'x'=>26,'y'=>19.7,'width'=>64,'fontSize'=>27,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.12,'maxLines'=>4];
        $f['organization_director'] = ['enabled'=>1,'x'=>26,'y'=>29.1,'width'=>64,'fontSize'=>29,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['full_name'] = ['enabled'=>1,'x'=>26,'y'=>35.0,'width'=>64,'fontSize'=>29,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1];
        $f['branch_requisites'] = ['enabled'=>1,'x'=>5.4,'y'=>58.0,'width'=>43.5,'fontSize'=>24,'fontFamily'=>'Arial','color'=>'#3f444a','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>9];
        $f['signature_url'] = ['enabled'=>1,'x'=>50.5,'y'=>58.0,'width'=>41,'height'=>10.0,'fit'=>'contain','opacity'=>1,'fontSize'=>8,'fontFamily'=>'Arial','color'=>'#111111','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1];
        $f['qr'] = ['enabled'=>1,'x'=>70.0,'y'=>79.5,'width'=>13.5,'fontSize'=>0,'fontFamily'=>'Arial','color'=>'#000000','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1];
        $f['verify_url'] = ['enabled'=>1,'x'=>55.5,'y'=>93.8,'width'=>39,'fontSize'=>17,'fontFamily'=>'Arial','color'=>'#555555','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.15,'maxLines'=>2];
        return $f;
    }

    private function install_bundled_statement_templates() {
        global $wpdb;
        $now = current_time('mysql');
        $definitions = [
            [
                'name'=>'AQNİET — Заявление о вступлении в Профсоюз',
                'background_url'=>plugins_url('assets/templates/zau-membership-application-bg.png', dirname(__DIR__) . '/zau-certificate-generator.php'),
                'fields'=>$this->bundled_membership_fields(),
            ],
            [
                'name'=>'AQNİET — Заявление на безналичную уплату профсоюзного взноса',
                'background_url'=>plugins_url('assets/templates/zau-contribution-application-bg.png', dirname(__DIR__) . '/zau-certificate-generator.php'),
                'fields'=>$this->bundled_contribution_fields(),
            ],
        ];
        $templateIds = [];
        foreach ($definitions as $definition) {
            $existingId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->templates_table} WHERE name=%s ORDER BY id ASC LIMIT 1", $definition['name']));
            $row = [
                'name'=>$definition['name'],
                'orientation'=>'portrait',
                'page_width'=>1754,
                'page_height'=>2480,
                'background_id'=>0,
                'background_url'=>$definition['background_url'],
                'background_mode'=>'stretch',
                'fields_json'=>wp_json_encode($definition['fields'], JSON_UNESCAPED_UNICODE),
                'updated_at'=>$now,
            ];
            if ($existingId) {
                // Существующий шаблон мог быть вручную настроен: не перезаписываем фон и координаты при обновлении.
                $templateIds[] = $existingId;
            } else {
                $row['created_by'] = get_current_user_id();
                $row['created_at'] = $now;
                if ($wpdb->insert($this->templates_table, $row)) { $templateIds[] = (int)$wpdb->insert_id; }
            }
        }
        if (!$templateIds) { return; }
        $settings = $this->settings();
        $form = $this->get_form(absint($settings['default_form_id']));
        if (!$form) { $form = $this->get_form(); }
        if (!$form) { return; }
        $selected = array_values(array_unique(array_filter(array_map('absint', json_decode((string)$form->template_ids_json, true) ?: []))));
        foreach ($templateIds as $templateId) { if (!in_array($templateId, $selected, true)) { $selected[] = $templateId; } }
        $wpdb->update($this->forms_table, [
            'template_ids_json'=>wp_json_encode($selected),
            'updated_at'=>$now,
        ], ['id'=>(int)$form->id]);
    }

    private function ensure_pages() {
        $settings = $this->settings();
        $pages = [
            'login_page_id' => ['Вход по коду', 'vhod-po-kodu', '[zau_union_auth]'],
            'form_page_id' => ['Регистрация в профсоюз', 'registraciya-v-profsoyuz', '[zau_union_form]'],
            'cabinet_page_id' => ['Личный кабинет профсоюза', 'lk-profsoyuz', '[zau_union_cabinet]'],
            'org_registry_page_id' => ['Реестр участников организации', 'reestr-organizacii', '[zau_union_org_registry]'],
        ];
        foreach ($pages as $key => $def) {
            $id = absint($settings[$key] ?? 0);
            if ($id && get_post($id)) { continue; }
            $existing = get_page_by_path($def[1]);
            $id = $existing ? (int)$existing->ID : wp_insert_post([
                'post_title'=>$def[0], 'post_name'=>$def[1], 'post_content'=>$def[2],
                'post_status'=>'publish', 'post_type'=>'page'
            ]);
            if (!is_wp_error($id) && $id) { $settings[$key] = (int)$id; }
        }
        update_option(self::OPT_SETTINGS, $settings, false);
    }

    public function register_info_post_type() {
        register_post_type('zau_union_info', [
            'labels'=>[
                'name'=>'Материалы для кабинета', 'singular_name'=>'Материал',
                'add_new_item'=>'Добавить материал', 'edit_item'=>'Редактировать материал'
            ],
            'public'=>false, 'show_ui'=>true, 'show_in_menu'=>false,
            'supports'=>['title','editor','thumbnail'], 'menu_icon'=>'dashicons-media-document',
            'capability_type'=>'post', 'map_meta_cap'=>true,
        ]);
    }


    public function register_benefit_post_type() {
        register_post_type('zau_union_benefit', [
            'labels'=>[
                'name'=>'Акции и скидки', 'singular_name'=>'Акция или скидка',
                'add_new'=>'Добавить', 'add_new_item'=>'Добавить акцию или скидку',
                'edit_item'=>'Редактировать акцию или скидку', 'new_item'=>'Новая акция',
                'search_items'=>'Найти акцию', 'not_found'=>'Акции и скидки не найдены',
            ],
            'public'=>false, 'show_ui'=>true, 'show_in_menu'=>false,
            'show_in_rest'=>true, 'supports'=>['title','editor','excerpt','thumbnail','page-attributes'],
            'menu_icon'=>'dashicons-tickets-alt', 'capability_type'=>'post', 'map_meta_cap'=>true,
        ]);
    }

    public function add_benefit_meta_boxes() {
        add_meta_box('zau-union-benefit-settings', 'Параметры акции или скидки', [$this, 'render_benefit_meta_box'], 'zau_union_benefit', 'normal', 'high');
    }

    public function render_benefit_meta_box($post) {
        wp_nonce_field('zau_union_benefit_meta', 'zau_union_benefit_nonce');
        $partner=get_post_meta($post->ID,'_zau_benefit_partner',true);
        $discount=get_post_meta($post->ID,'_zau_benefit_discount',true);
        $from=get_post_meta($post->ID,'_zau_benefit_from',true);
        $to=get_post_meta($post->ID,'_zau_benefit_to',true);
        $promo=get_post_meta($post->ID,'_zau_benefit_promo',true);
        $button=get_post_meta($post->ID,'_zau_benefit_button',true)?:'Подробнее';
        $url=get_post_meta($post->ID,'_zau_benefit_url',true);
        $statuses=(array)get_post_meta($post->ID,'_zau_benefit_statuses',true);
        $orgIds=array_map('absint',(array)get_post_meta($post->ID,'_zau_benefit_org_ids',true));
        $branchIds=array_map('absint',(array)get_post_meta($post->ID,'_zau_benefit_branch_ids',true));
        $showAll=(bool)get_post_meta($post->ID,'_zau_benefit_show_all',true);
        $organizations=$this->organization_rows();
        $branches=$this->branch_rows(false);
        ?>
        <div class="zau-benefit-meta-grid">
            <p><label><strong>Партнёр / организация</strong><br><input class="widefat" name="zau_benefit_partner" value="<?php echo esc_attr($partner);?>"></label></p>
            <p><label><strong>Размер скидки или короткий бейдж</strong><br><input class="widefat" name="zau_benefit_discount" value="<?php echo esc_attr($discount);?>" placeholder="Например: −20% или Бесплатно"></label></p>
            <p><label><strong>Действует с</strong><br><input type="date" name="zau_benefit_from" value="<?php echo esc_attr($from);?>"></label></p>
            <p><label><strong>Действует до</strong><br><input type="date" name="zau_benefit_to" value="<?php echo esc_attr($to);?>"></label></p>
            <p><label><strong>Промокод</strong><br><input class="widefat" name="zau_benefit_promo" value="<?php echo esc_attr($promo);?>"></label></p>
            <p><label><strong>Текст кнопки</strong><br><input class="widefat" name="zau_benefit_button" value="<?php echo esc_attr($button);?>"></label></p>
            <p style="grid-column:1/-1"><label><strong>Ссылка кнопки</strong><br><input class="widefat" type="url" name="zau_benefit_url" value="<?php echo esc_attr($url);?>" placeholder="https://..."></label></p>
        </div>
        <hr>
        <p><strong>Кому показывать</strong></p>
        <p><label><input type="checkbox" name="zau_benefit_show_all" value="1" <?php checked($showAll);?>> <strong>Показывать всем зарегистрированным пользователям</strong></label><br><small>Если включено, ограничения по статусу, организации и филиалу ниже не применяются. Администратор в любом случае видит опубликованную действующую акцию как предпросмотр.</small></p>
        <p><label>Статусы участника<br><select name="zau_benefit_statuses[]" multiple size="7" style="min-width:360px"><?php foreach($this->membership_statuses() as $status):?><option value="<?php echo esc_attr($status);?>" <?php selected(in_array($status,$statuses,true),true);?>><?php echo esc_html($status);?></option><?php endforeach;?></select></label><br><small>Если ничего не выбрано — показывается всем статусам.</small></p>
        <p><label>Организации<br><select name="zau_benefit_org_ids[]" multiple size="7" style="min-width:520px"><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected(in_array((int)$org->id,$orgIds,true),true);?>><?php echo esc_html($org->name.($org->bin?' · БИН '.$org->bin:' · БИН не указан'));?></option><?php endforeach;?></select></label><br><small>Пустой список — все организации.</small></p>
        <p><label>Филиалы<br><select name="zau_benefit_branch_ids[]" multiple size="7" style="min-width:520px"><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected(in_array((int)$branch->id,$branchIds,true),true);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select></label><br><small>Пустой список — все филиалы.</small></p>
        <p class="description">Содержимое карточки редактируется в основном редакторе. При включённом Elementor для этого типа записи его можно оформлять через Elementor.</p>
        <?php
    }

    public function save_benefit_meta($postId, $post) {
        if(!isset($_POST['zau_union_benefit_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['zau_union_benefit_nonce'])),'zau_union_benefit_meta'))return;
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
        if(!$post||$post->post_type!=='zau_union_benefit'||!current_user_can('edit_post',$postId))return;
        update_post_meta($postId,'_zau_benefit_partner',sanitize_text_field(wp_unslash($_POST['zau_benefit_partner']??'')));
        update_post_meta($postId,'_zau_benefit_discount',sanitize_text_field(wp_unslash($_POST['zau_benefit_discount']??'')));
        update_post_meta($postId,'_zau_benefit_from',sanitize_text_field(wp_unslash($_POST['zau_benefit_from']??'')));
        update_post_meta($postId,'_zau_benefit_to',sanitize_text_field(wp_unslash($_POST['zau_benefit_to']??'')));
        update_post_meta($postId,'_zau_benefit_promo',sanitize_text_field(wp_unslash($_POST['zau_benefit_promo']??'')));
        update_post_meta($postId,'_zau_benefit_button',sanitize_text_field(wp_unslash($_POST['zau_benefit_button']??'Подробнее')));
        update_post_meta($postId,'_zau_benefit_url',esc_url_raw(wp_unslash($_POST['zau_benefit_url']??'')));
        update_post_meta($postId,'_zau_benefit_show_all',empty($_POST['zau_benefit_show_all'])?0:1);
        $statuses=array_values(array_intersect($this->membership_statuses(),array_map('sanitize_text_field',(array)($_POST['zau_benefit_statuses']??[]))));
        update_post_meta($postId,'_zau_benefit_statuses',$statuses);
        update_post_meta($postId,'_zau_benefit_org_ids',array_values(array_unique(array_filter(array_map('absint',(array)($_POST['zau_benefit_org_ids']??[]))))));
        update_post_meta($postId,'_zau_benefit_branch_ids',array_values(array_unique(array_filter(array_map('absint',(array)($_POST['zau_benefit_branch_ids']??[]))))));
    }


    public function register_cabinet_tab_post_type() {
        register_post_type('zau_union_tab', [
            'labels'=>[
                'name'=>'Вкладки кабинета', 'singular_name'=>'Вкладка кабинета',
                'add_new'=>'Добавить вкладку', 'add_new_item'=>'Добавить вкладку кабинета',
                'edit_item'=>'Редактировать вкладку', 'new_item'=>'Новая вкладка',
                'search_items'=>'Найти вкладку', 'not_found'=>'Вкладки не найдены',
            ],
            'public'=>false, 'show_ui'=>true, 'show_in_menu'=>false,
            'show_in_rest'=>true, 'supports'=>['title','editor','page-attributes'],
            'menu_icon'=>'dashicons-index-card', 'capability_type'=>'post', 'map_meta_cap'=>true,
        ]);
    }

    public function add_cabinet_tab_meta_boxes() {
        add_meta_box('zau-union-tab-settings', 'Настройки вкладки', [$this, 'render_cabinet_tab_meta_box'], 'zau_union_tab', 'side', 'high');
        add_meta_box('zau-union-tab-content-source', 'Источник содержимого', [$this, 'render_cabinet_tab_source_meta_box'], 'zau_union_tab', 'normal', 'high');
    }

    public function render_cabinet_tab_meta_box($post) {
        wp_nonce_field('zau_union_tab_meta', 'zau_union_tab_nonce');
        $enabled=get_post_meta($post->ID,'_zau_tab_enabled',true);
        $slug=get_post_meta($post->ID,'_zau_tab_slug',true);
        $icon=get_post_meta($post->ID,'_zau_tab_icon',true);
        $access=get_post_meta($post->ID,'_zau_tab_access',true)?:'members';
        $roles=get_post_meta($post->ID,'_zau_tab_roles',true);
        ?>
        <p><label><input type="checkbox" name="zau_tab_enabled" value="1" <?php checked($enabled!== '0');?>> Вкладка включена</label></p>
        <p><label><strong>Ключ вкладки</strong><br><input class="widefat" type="text" name="zau_tab_slug" value="<?php echo esc_attr($slug);?>" placeholder="naprimer-spravki"></label><small>Латиница, цифры и дефисы. Если оставить пустым, ключ создастся из названия.</small></p>
        <p><label><strong>Значок</strong><br><input class="widefat" type="text" name="zau_tab_icon" value="<?php echo esc_attr($icon);?>" placeholder="📁 или dashicons-book"></label><small>Можно указать эмодзи либо класс Dashicons.</small></p>
        <p><label><strong>Кому показывать</strong><br><select class="widefat" name="zau_tab_access">
            <option value="members" <?php selected($access,'members');?>>Всем участникам</option>
            <option value="org_manager" <?php selected($access,'org_manager');?>>Только ответственным организаций</option>
            <option value="admin" <?php selected($access,'admin');?>>Только администраторам</option>
            <option value="roles" <?php selected($access,'roles');?>>Выбранным ролям WordPress</option>
        </select></label></p>
        <p><label><strong>Роли WordPress</strong><br><input class="widefat" type="text" name="zau_tab_roles" value="<?php echo esc_attr($roles);?>" placeholder="subscriber,editor"></label><small>Используется только при выборе «Выбранным ролям».</small></p>
        <p><strong>Порядок</strong><br><small>Задаётся полем «Порядок» в блоке «Атрибуты» редактора. Меньшее число показывается раньше.</small></p>
        <?php
    }

    public function render_cabinet_tab_source_meta_box($post) {
        $source=get_post_meta($post->ID,'_zau_tab_source',true)?:'editor';
        $shortcode=get_post_meta($post->ID,'_zau_tab_shortcode',true);
        $templateId=absint(get_post_meta($post->ID,'_zau_tab_elementor_template',true));
        $templates=get_posts(['post_type'=>'elementor_library','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        ?>
        <p><label><strong>Что выводить во вкладке</strong><br><select name="zau_tab_source" class="widefat" data-zau-tab-source>
            <option value="editor" <?php selected($source,'editor');?>>Содержимое редактора выше</option>
            <option value="elementor" <?php selected($source,'elementor');?>>Сохранённый шаблон Elementor</option>
            <option value="shortcode" <?php selected($source,'shortcode');?>>Шорткод</option>
        </select></label></p>
        <p><label><strong>Шаблон Elementor</strong><br><select name="zau_tab_elementor_template" class="widefat"><option value="0">— Не выбран —</option><?php foreach($templates as $template):?><option value="<?php echo (int)$template->ID;?>" <?php selected($templateId,$template->ID);?>><?php echo esc_html($template->post_title.' (#'.$template->ID.')');?></option><?php endforeach;?></select></label><br><small>Создайте шаблон через «Шаблоны → Сохранённые шаблоны», затем выберите его здесь.</small></p>
        <p><label><strong>Шорткод</strong><br><textarea class="widefat" rows="4" name="zau_tab_shortcode" placeholder="[my_shortcode]"><?php echo esc_textarea($shortcode);?></textarea></label></p>
        <p><small>При выборе «Содержимое редактора» используйте основной редактор страницы. В нём разрешены обычный текст, изображения, ссылки и шорткоды.</small></p>
        <?php
    }

    public function save_cabinet_tab_meta($postId, $post) {
        if(!$post || $post->post_type!=='zau_union_tab')return;
        if(!isset($_POST['zau_union_tab_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['zau_union_tab_nonce'])),'zau_union_tab_meta'))return;
        if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)return;
        if(!current_user_can('edit_post',$postId))return;
        $slug=sanitize_title((string)wp_unslash($_POST['zau_tab_slug']??''));
        if(!$slug)$slug=sanitize_title($post->post_title);
        if(in_array($slug,['home','documents','submissions','members','registry','info','logins'],true))$slug='custom-'.$slug;
        update_post_meta($postId,'_zau_tab_enabled',isset($_POST['zau_tab_enabled'])?'1':'0');
        update_post_meta($postId,'_zau_tab_slug',$slug?:'custom-'.$postId);
        update_post_meta($postId,'_zau_tab_icon',sanitize_text_field((string)wp_unslash($_POST['zau_tab_icon']??'')));
        $access=sanitize_key((string)wp_unslash($_POST['zau_tab_access']??'members'));
        update_post_meta($postId,'_zau_tab_access',in_array($access,['members','org_manager','admin','roles'],true)?$access:'members');
        update_post_meta($postId,'_zau_tab_roles',sanitize_text_field((string)wp_unslash($_POST['zau_tab_roles']??'')));
        $source=sanitize_key((string)wp_unslash($_POST['zau_tab_source']??'editor'));
        update_post_meta($postId,'_zau_tab_source',in_array($source,['editor','elementor','shortcode'],true)?$source:'editor');
        update_post_meta($postId,'_zau_tab_elementor_template',absint($_POST['zau_tab_elementor_template']??0));
        update_post_meta($postId,'_zau_tab_shortcode',wp_kses_post((string)wp_unslash($_POST['zau_tab_shortcode']??'')));
    }

    public function cabinet_tab_columns($columns) {
        return ['cb'=>$columns['cb']??'<input type="checkbox">','title'=>'Название','zau_tab_slug'=>'Ключ','zau_tab_access'=>'Доступ','zau_tab_source'=>'Содержимое','zau_tab_enabled'=>'Состояние','date'=>$columns['date']??'Дата'];
    }

    public function cabinet_tab_column_content($column, $postId) {
        if($column==='zau_tab_slug')echo '<code>'.esc_html(get_post_meta($postId,'_zau_tab_slug',true)).'</code>';
        elseif($column==='zau_tab_access'){
            $labels=['members'=>'Все участники','org_manager'=>'Ответственные','admin'=>'Администраторы','roles'=>'Выбранные роли'];
            $value=get_post_meta($postId,'_zau_tab_access',true)?:'members'; echo esc_html($labels[$value]??$value);
        } elseif($column==='zau_tab_source'){
            $labels=['editor'=>'Редактор','elementor'=>'Elementor','shortcode'=>'Шорткод']; $value=get_post_meta($postId,'_zau_tab_source',true)?:'editor'; echo esc_html($labels[$value]??$value);
        } elseif($column==='zau_tab_enabled')echo get_post_meta($postId,'_zau_tab_enabled',true)==='0'?'<span style="color:#9b2c2c">Выключена</span>':'<span style="color:#08783e">Включена</span>';
    }

    private function custom_tab_is_allowed($post, $userId=0, $canManageOrg=null) {
        if(!$post || get_post_meta($post->ID,'_zau_tab_enabled',true)==='0')return false;
        $userId=$userId?:get_current_user_id();
        if(!$userId)return false;
        $access=get_post_meta($post->ID,'_zau_tab_access',true)?:'members';
        if($access==='members')return true;
        if($access==='admin')return current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options');
        if($access==='org_manager'){
            if($canManageOrg===null)$canManageOrg=current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE)||current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options');
            return (bool)$canManageOrg;
        }
        if($access==='roles'){
            $wanted=array_values(array_filter(array_map('sanitize_key',explode(',',(string)get_post_meta($post->ID,'_zau_tab_roles',true)))));
            $user=get_userdata($userId); return $user && (bool)array_intersect($wanted,(array)$user->roles);
        }
        return false;
    }

    private function available_custom_tabs($userId=0, $canManageOrg=null) {
        $posts=get_posts(['post_type'=>'zau_union_tab','post_status'=>'publish','numberposts'=>-1,'orderby'=>['menu_order'=>'ASC','date'=>'ASC']]);
        $result=[]; $used=[];
        foreach($posts as $post){
            if(!$this->custom_tab_is_allowed($post,$userId,$canManageOrg))continue;
            $slug=sanitize_title((string)get_post_meta($post->ID,'_zau_tab_slug',true));
            if(!$slug)$slug='custom-'.$post->ID;
            if(isset($used[$slug]))$slug.='-'.$post->ID;
            $used[$slug]=1;
            $result[$slug]=['post'=>$post,'label'=>$post->post_title,'icon'=>sanitize_text_field((string)get_post_meta($post->ID,'_zau_tab_icon',true))];
        }
        return $result;
    }

    private function render_custom_tab_post($post) {
        $source=get_post_meta($post->ID,'_zau_tab_source',true)?:'editor';
        if($source==='elementor'){
            $templateId=absint(get_post_meta($post->ID,'_zau_tab_elementor_template',true));
            if($templateId && did_action('elementor/loaded') && class_exists('\\Elementor\\Plugin')){
                return \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($templateId,true);
            }
            return '<div class="zau-empty-state">Шаблон Elementor не выбран или Elementor недоступен.</div>';
        }
        if($source==='shortcode'){
            $shortcode=(string)get_post_meta($post->ID,'_zau_tab_shortcode',true);
            return $shortcode!==''?do_shortcode($shortcode):'<div class="zau-empty-state">Шорткод не указан.</div>';
        }
        return apply_filters('the_content',$post->post_content);
    }

    public function custom_tab_shortcode($atts=[]) {
        if(!is_user_logged_in())return $this->auth_shortcode($atts);
        $atts=shortcode_atts(['slug'=>''],(array)$atts,'zau_union_custom_tab');
        $slug=sanitize_title((string)$atts['slug']);
        foreach($this->available_custom_tabs(get_current_user_id()) as $key=>$tab){
            if($key===$slug)return '<section class="zau-cabinet-section zau-custom-tab-standalone">'.$this->render_custom_tab_post($tab['post']).'</section>';
        }
        return '<div class="zau-empty-state">Вкладка недоступна или выключена.</div>';
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT_SETTINGS, []), [
            'default_form_id'=>0,
            'login_page_id'=>0,
            'form_page_id'=>0,
            'cabinet_page_id'=>0,
            'org_registry_page_id'=>0,
            'otp_expiry'=>300,
            'otp_resend'=>60,
            'otp_max_attempts'=>5,
            'auth_otp_email'=>1,
            'auth_otp_phone'=>1,
            'auth_pin_enabled'=>1,
            'auth_password_enabled'=>1,
            'auth_default_method'=>'otp',
            'auth_show_tabs'=>1,
            'auth_show_recovery'=>1,
            'registration_mode'=>'direct',
            'redirect_after_registration'=>1,
            'redirect_after_login'=>1,
            'pin_setup_mode'=>'optional',
            'pin_min_length'=>4,
            'pin_max_length'=>8,
            'pin_max_attempts'=>5,
            'pin_lock_minutes'=>15,
            'pin_reset_expiry'=>600,
            'pin_device_only'=>1,
            'pin_device_days'=>365,
            'sms_webhook_url'=>'',
            'sms_webhook_token'=>'',
            'dadata_enabled'=>0,
            'dadata_token'=>'',
            'dadata_autocomplete'=>1,
            'dadata_language'=>'ru',
            'dadata_cache_hours'=>24,
            'dadata_min_chars'=>3,
            'bin_api_url'=>'',
            'bin_api_headers'=>'{}',
            'bin_api_data_path'=>'',
            'bin_api_name_path'=>'name',
            'bin_api_director_path'=>'director',
            'bin_api_address_path'=>'address',
            'bin_api_region_path'=>'region',
            'design_primary'=>'#1565C0',
            'design_mobile_steps'=>1,
            'design_mobile_bottom_nav'=>1,
            'design_quick_actions'=>1,
            'design_document_search'=>1,
            'auto_member_status_on_approval'=>1,
            'member_card_member_edit'=>1,
            'member_card_tab_enabled'=>1,
            'hide_admin_for_non_admin'=>1,
            'member_card_sensitive_fields'=>'iin,birth_date,address,emergency_contact',
            'sensitive_reveal_seconds'=>30,
            'benefits_tab_enabled'=>1,
            'tab_role_hidden'=>'{}',
        ]);
    }

    /**
     * Cabinet tabs that admins can restrict per role, and the roles they can
     * restrict them for. Administrators always see every tab regardless of
     * this setting, so they can never lock themselves out.
     */
    private function tab_visibility_matrix_options() {
        $tabs = [
            'home'=>'Главная','documents'=>'Документы','submissions'=>'Заявления','card'=>'Личная карточка',
            'benefits'=>'Акции и скидки','members'=>'Участники','registry'=>'Реестр организации',
            'info'=>'Материалы','logins'=>'История входов',
        ];
        $roles = [];
        foreach ((array) get_editable_roles() as $slug=>$role) {
            if ($slug==='administrator') { continue; }
            $roles[$slug] = translate_user_role($role['name']);
        }
        return [$tabs, $roles];
    }

    private function tab_role_hidden_map() {
        $decoded = json_decode((string) $this->settings()['tab_role_hidden'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tab_allowed_for_current_user($tab) {
        if (current_user_can('manage_options')) { return true; }
        $hidden = $this->tab_role_hidden_map();
        if (empty($hidden[$tab]) || !is_array($hidden[$tab])) { return true; }
        $user = wp_get_current_user();
        foreach ((array) $user->roles as $role) {
            if (!empty($hidden[$tab][$role])) { return false; }
        }
        return true;
    }

    public function filter_admin_bar($show) {
        if (!is_user_logged_in()) return $show;
        if (empty($this->settings()['hide_admin_for_non_admin'])) return $show;
        return current_user_can('manage_options') ? $show : false;
    }

    public function restrict_wp_admin() {
        if (!is_user_logged_in() || current_user_can('manage_options')) return;
        if (empty($this->settings()['hide_admin_for_non_admin'])) return;
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if (in_array($script, ['admin-ajax.php','admin-post.php','async-upload.php'], true)) return;
        wp_safe_redirect($this->cabinet_url());
        exit;
    }

    private function cabinet_url() {
        $pageId = absint($this->settings()['cabinet_page_id'] ?? 0);
        $url = $pageId ? get_permalink($pageId) : home_url('/lk-profsoyuz/');
        return $url ?: home_url('/');
    }

    public function filter_wordpress_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!$user || is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        if (user_can($user, 'manage_options') || user_can($user, ZAU_Certificate_PDF_Generator::CAP_MANAGE)) return $redirect_to;
        if (empty($this->settings()['redirect_after_login'])) return $redirect_to;
        return $this->cabinet_url();
    }

    public function redirect_logged_in_auth_page() {
        if (!is_user_logged_in() || current_user_can('manage_options')) return;
        if (empty($this->settings()['redirect_after_login'])) return;
        $loginPageId = absint($this->settings()['login_page_id'] ?? 0);
        $cabinetPageId = absint($this->settings()['cabinet_page_id'] ?? 0);
        if (!$loginPageId || $loginPageId === $cabinetPageId || !is_page($loginPageId)) return;
        wp_safe_redirect($this->cabinet_url());
        exit;
    }

    public function admin_menu() {
        // Consolidated hubs (visible): each is the landing tab of its group,
        // the rest of the group is registered with a null parent below so
        // it stays reachable at the same URL without cluttering the menu.
        add_submenu_page('zau-certificates', 'Пользователи и доступ', 'Пользователи', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-members', [$this, 'page_members_access']);
        add_submenu_page('zau-certificates', 'Оформление и данные', 'Оформление и данные', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-settings', [$this, 'page_settings']);

        add_submenu_page(null, 'Формы регистрации', 'Формы регистрации', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-forms', [$this, 'page_forms']);
        add_submenu_page(null, 'Заявки участников', 'Заявки участников', ZAU_Certificate_PDF_Generator::CAP_CREATE, 'zau-union-submissions', [$this, 'page_submissions']);
        add_submenu_page(null, 'Статусы и одобрение', 'Статусы и одобрение', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-member-statuses', [$this, 'page_member_statuses']);
        add_submenu_page(null, 'Организации и БИН', 'Организации и БИН', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-organizations', [$this, 'page_organizations']);
        add_submenu_page(null, 'Проверка организаций', 'Проверка организаций', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-org-audit', [$this, 'page_organization_audit']);
        add_submenu_page(null, 'Филиалы и реквизиты', 'Филиалы и реквизиты', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-branches', [$this, 'page_branches']);
        add_submenu_page(null, 'История входов', 'История входов', ZAU_Certificate_PDF_Generator::CAP_MANAGE, 'zau-union-login-history', [$this, 'page_login_history']);
    }

    public function admin_assets($hook) {
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        $isBenefit=$screen&&$screen->post_type==='zau_union_benefit';
        if (strpos((string)$hook, 'zau-union') === false && !$isBenefit) { return; }
        wp_enqueue_style('zau-union-admin', plugins_url('../assets/css/union-admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('zau-union-admin', plugins_url('../assets/js/union-admin.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('zau-union-admin', 'ZAUUnionAdmin', [
            'fieldTypes'=>$this->field_type_options(),
            'nonce'=>wp_create_nonce(self::NONCE),
        ]);
    }

    public function elementor_cpt_support($types) {
        $types=is_array($types)?$types:['page','post'];
        foreach(['zau_union_benefit','zau_union_info','zau_union_tab'] as $type){if(!in_array($type,$types,true))$types[]=$type;}
        return $types;
    }

    public function public_assets() {
        $cssPath=dirname(__DIR__).'/assets/css/union-public.css';
        $jsPath=dirname(__DIR__).'/assets/js/union-public.js';
        $designCssPath=dirname(__DIR__).'/assets/css/aqniet-blue.css';
        $designJsPath=dirname(__DIR__).'/assets/js/aqniet-blue.js';
        $cssVersion=self::VERSION.(is_file($cssPath)?'.'.(string)filemtime($cssPath):'');
        $jsVersion=self::VERSION.(is_file($jsPath)?'.'.(string)filemtime($jsPath):'');
        $designCssVersion=self::VERSION.(is_file($designCssPath)?'.'.(string)filemtime($designCssPath):'');
        $designJsVersion=self::VERSION.(is_file($designJsPath)?'.'.(string)filemtime($designJsPath):'');
        wp_enqueue_style('zau-union-public', plugins_url('../assets/css/union-public.css', __FILE__), [], $cssVersion);
        wp_enqueue_style('zau-aqniet-blue', plugins_url('../assets/css/aqniet-blue.css', __FILE__), ['zau-union-public'], $designCssVersion);
        wp_enqueue_script('zau-union-qrcode', plugins_url('../assets/js/qrcode.min.js', __FILE__), [], '1.0.0', true);
        wp_enqueue_script('zau-union-public', plugins_url('../assets/js/union-public.js', __FILE__), ['zau-union-qrcode'], $jsVersion, true);
        wp_enqueue_script('zau-aqniet-blue', plugins_url('../assets/js/aqniet-blue.js', __FILE__), ['zau-union-public'], $designJsVersion, true);
        $settings = $this->settings();
        wp_localize_script('zau-union-public', 'ZAUUnionData', [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
            'isLoggedIn'=>is_user_logged_in(),
            'formUrl'=>$settings['form_page_id'] ? get_permalink((int)$settings['form_page_id']) : home_url('/registraciya-v-profsoyuz/'),
            'cabinetUrl'=>$settings['cabinet_page_id'] ? get_permalink((int)$settings['cabinet_page_id']) : home_url('/lk-profsoyuz/'),
            'orgRegistryUrl'=>$settings['org_registry_page_id'] ? get_permalink((int)$settings['org_registry_page_id']) : home_url('/reestr-organizacii/'),
            'fieldTypes'=>$this->certificate_field_types([]),
            'dadataAutocomplete'=>!empty($settings['dadata_enabled']) && !empty($settings['dadata_autocomplete']) && !empty($settings['dadata_token']),
            'dadataMinChars'=>max(3, min(12, (int)$settings['dadata_min_chars'])),
            'pinMinLength'=>max(4, (int)$settings['pin_min_length']),
            'pinMaxLength'=>max(max(4, (int)$settings['pin_min_length']), (int)$settings['pin_max_length']),
            'designPrimary'=>sanitize_hex_color($settings['design_primary']??'')?:'#1565C0',
            'designMobileSteps'=>!empty($settings['design_mobile_steps']),
            'designMobileBottomNav'=>!empty($settings['design_mobile_bottom_nav']),
            'designQuickActions'=>!empty($settings['design_quick_actions']),
            'designDocumentSearch'=>!empty($settings['design_document_search']),
        ]);
    }

    private function require_cap($cap) {
        if (!current_user_can($cap)) { wp_die('Недостаточно прав.'); }
    }

    private function field_type_options() {
        return [
            'text'=>'Однострочный текст', 'email'=>'Email', 'phone'=>'Телефон', 'number'=>'Число',
            'date'=>'Дата', 'textarea'=>'Многострочный текст', 'select'=>'Выпадающий список',
            'checkbox'=>'Флажок согласия', 'bin_lookup'=>'Поиск организации по БИН',
            'branch_select'=>'Выбор филиала с автоподстановкой реквизитов',
            'signature'=>'Рисуемая подпись', 'heading'=>'Заголовок раздела', 'hidden'=>'Скрытое поле'
        ];
    }

    private function sanitize_field_key($key) {
        $key = strtolower((string)$key);
        $key = preg_replace('/[^a-z0-9_\-]/', '_', $key);
        $key = trim($key, '_-');
        return substr($key ?: 'field_' . wp_rand(100, 999), 0, 80);
    }

    private function decode_form_fields($json) {
        $items = json_decode((string)$json, true);
        if (!is_array($items)) { return []; }
        $allowed = array_keys($this->field_type_options());
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $type = sanitize_key($item['type'] ?? 'text');
            if (!in_array($type, $allowed, true)) { $type = 'text'; }
            $out[] = [
                'key'=>$this->sanitize_field_key($item['key'] ?? ''),
                'label'=>sanitize_text_field($item['label'] ?? ''),
                'type'=>$type,
                'required'=>empty($item['required']) ? 0 : 1,
                'placeholder'=>sanitize_text_field($item['placeholder'] ?? ''),
                'options'=>sanitize_textarea_field($item['options'] ?? ''),
                'default'=>sanitize_text_field($item['default'] ?? ''),
            ];
        }
        return $out;
    }

    private function get_forms($active_only = false) {
        global $wpdb;
        $where = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results("SELECT * FROM {$this->forms_table} $where ORDER BY updated_at DESC, id DESC");
    }

    private function get_form($id_or_slug = 0) {
        global $wpdb;
        if (is_numeric($id_or_slug) && (int)$id_or_slug > 0) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE id=%d", (int)$id_or_slug));
        }
        if ($id_or_slug) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE slug=%s", sanitize_title($id_or_slug)));
        }
        $settings = $this->settings();
        $id = absint($settings['default_form_id']);
        if ($id) { return $this->get_form($id); }
        return $wpdb->get_row("SELECT * FROM {$this->forms_table} WHERE active=1 ORDER BY id ASC LIMIT 1");
    }

    private function available_template_ids() {
        global $wpdb;
        return array_values(array_filter(array_map('absint', (array)$wpdb->get_col("SELECT id FROM {$this->templates_table} ORDER BY id ASC"))));
    }

    private function resolve_form_template_ids($form) {
        if (!$form) { return []; }
        $selected = array_values(array_unique(array_filter(array_map('absint', json_decode((string)$form->template_ids_json, true) ?: []))));
        $available = $this->available_template_ids();
        if (!$available) { return []; }
        $selected = array_values(array_intersect($selected, $available));
        // Пустой список раньше приводил к сохранению заявки без документов.
        // В таком случае используем все существующие шаблоны и сохраняем связь.
        if (!$selected) {
            $selected = $available;
            global $wpdb;
            $wpdb->update($this->forms_table, [
                'template_ids_json'=>wp_json_encode($selected),
                'updated_at'=>current_time('mysql'),
            ], ['id'=>(int)$form->id]);
            $form->template_ids_json = wp_json_encode($selected);
        }
        return $selected;
    }

    private function repair_empty_form_template_links() {
        global $wpdb;
        $templates = $this->available_template_ids();
        if (!$templates) { return; }
        $forms = $wpdb->get_results("SELECT * FROM {$this->forms_table} WHERE active=1");
        foreach ((array)$forms as $form) {
            $ids = array_values(array_unique(array_filter(array_map('absint', json_decode((string)$form->template_ids_json, true) ?: []))));
            if ($ids) { continue; }
            $wpdb->update($this->forms_table, [
                'template_ids_json'=>wp_json_encode($templates),
                'updated_at'=>current_time('mysql'),
            ], ['id'=>(int)$form->id]);
        }
    }

    public function auto_link_saved_template($template_id, $action = '') {
        $template_id = absint($template_id);
        if (!$template_id) { return; }
        global $wpdb;
        $settings = $this->settings();
        $form = $this->get_form(absint($settings['default_form_id']));
        if (!$form) { $form = $this->get_form(); }
        if (!$form) { return; }
        $ids = array_values(array_unique(array_filter(array_map('absint', json_decode((string)$form->template_ids_json, true) ?: []))));
        if (!in_array($template_id, $ids, true)) {
            $ids[] = $template_id;
            $wpdb->update($this->forms_table, [
                'template_ids_json'=>wp_json_encode($ids),
                'updated_at'=>current_time('mysql'),
            ], ['id'=>(int)$form->id]);
        }
    }

    private function human_field_label($key) {
        $label = str_replace(['__','_','-'], [' / ',' ',' '], (string)$key);
        $label = preg_replace('/\s+/', ' ', trim($label));
        if ($label === '') { return 'Поле'; }
        return function_exists('mb_convert_case') ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : ucwords($label);
    }

    private function discovered_submission_fields() {
        global $wpdb;
        $keys = [];
        $rows = $wpdb->get_col("SELECT data_json FROM {$this->submissions_table} WHERE data_json IS NOT NULL AND data_json<>'' ORDER BY id DESC LIMIT 200");
        foreach ((array)$rows as $json) {
            $data = json_decode((string)$json, true);
            if (!is_array($data)) { continue; }
            foreach ($data as $key=>$value) {
                $key = $this->sanitize_field_key($key);
                if ($key && (is_scalar($value) || $value === null)) { $keys[$key] = $this->human_field_label($key); }
            }
        }
        return $keys;
    }

    private function discovered_profile_fields() {
        global $wpdb;
        $keys = [];
        $metaKeys = $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'zau_profile\\_%' ESCAPE '\\\\' ORDER BY meta_key ASC LIMIT 500");
        foreach ((array)$metaKeys as $metaKey) {
            $key = substr((string)$metaKey, strlen('zau_profile_'));
            $key = $this->sanitize_field_key($key);
            if ($key) { $keys[$key] = $this->human_field_label($key); }
        }
        return $keys;
    }

    private function hydrate_document_data($data, $user_id) {
        $data = is_array($data) ? $data : [];
        $original = $data;
        foreach ($original as $key=>$value) {
            if (is_scalar($value) || $value === null) { $data['submission__'.$this->sanitize_field_key($key)] = $value; }
        }
        $user = get_user_by('id', (int)$user_id);
        $profile = [];
        if ($user) {
            $profile['first_name'] = $user->first_name;
            $profile['last_name'] = $user->last_name;
            $profile['email'] = $user->user_email;
            $profile['display_name'] = $user->display_name;
            $profile['phone'] = get_user_meta($user->ID, 'zau_phone', true);
            $profile['member_status'] = get_user_meta($user->ID, 'zau_member_status', true);
            $allMeta = get_user_meta($user->ID);
            foreach ((array)$allMeta as $metaKey=>$values) {
                if (strpos((string)$metaKey, 'zau_profile_') !== 0) { continue; }
                $key = $this->sanitize_field_key(substr((string)$metaKey, strlen('zau_profile_')));
                $value = is_array($values) ? reset($values) : $values;
                if ($key && is_scalar($value)) { $profile[$key] = maybe_unserialize($value); }
            }
        }
        foreach ($profile as $key=>$value) {
            if (!is_scalar($value) && $value !== null) { continue; }
            $safeKey = $this->sanitize_field_key($key);
            $data['profile__'.$safeKey] = $value;
            if ((!array_key_exists($safeKey, $data) || $data[$safeKey] === '') && $value !== '') { $data[$safeKey] = $value; }
        }
        $currentBranchId = absint($data['branch_id'] ?? ($profile['branch_id'] ?? get_user_meta((int)$user_id, 'zau_profile_branch_id', true)));
        if ($currentBranchId) {
            $currentBranch = $this->get_branch($currentBranchId);
            if ($currentBranch) {
                foreach ($this->branch_data($currentBranch) as $key=>$value) {
                    if (!is_scalar($value) && $value !== null) { continue; }
                    $safeKey = $this->sanitize_field_key($key);
                    $data['branch_current__'.$safeKey] = $value;
                }
            }
        }
        $card=$this->get_member_card_data((int)$user_id);
        foreach($card as $key=>$value){
            if(!is_scalar($value)&&$value!==null)continue;
            $safeKey=$this->sanitize_field_key($key);if(!$safeKey)continue;
            $data['card__'.$safeKey]=$value;
            if((!array_key_exists($safeKey,$data)||$data[$safeKey]==='')&&$value!=='')$data[$safeKey]=$value;
        }
        return $data;
    }

    public function certificate_field_definitions($defs) {
        $defs = is_array($defs) ? $defs : [];
        $system = [
            'first_name'=>'Имя', 'last_name'=>'Фамилия', 'middle_name'=>'Отчество',
            'phone'=>'Телефон', 'email'=>'Email', 'region'=>'Регион',
            'organization_bin'=>'БИН/ИИН организации', 'organization_director'=>'Ф.И.О. руководителя',
            'organization_address'=>'Адрес организации', 'organization_name_ru'=>'Название организации на русском',
            'organization_name_kz'=>'Название организации на казахском', 'organization_address_ru'=>'Адрес на русском',
            'organization_address_kz'=>'Адрес на казахском', 'organization_status'=>'Статус организации',
            'organization_status_code'=>'Код статуса организации', 'organization_type'=>'Тип организации',
            'organization_type_code'=>'Код типа организации', 'organization_registration_date'=>'Дата регистрации организации',
            'organization_oked'=>'Код ОКЭД', 'organization_oked_name'=>'Наименование ОКЭД', 'organization_kato'=>'КАТО',
            'branch'=>'Филиал', 'branch_id'=>'ID филиала', 'branch_name'=>'Название филиала',
            'branch_code'=>'Код филиала', 'branch_region'=>'Регион филиала',
            'branch_union_bin'=>'БИН филиала Профсоюза', 'branch_iban'=>'ИИК / IBAN филиала',
            'branch_bik'=>'БИК филиала', 'branch_bank_name'=>'Банк филиала',
            'branch_legal_address'=>'Юридический адрес филиала', 'branch_chairman'=>'Председатель филиала',
            'branch_chairman_phone'=>'Телефон председателя филиала', 'branch_accountant'=>'Главный бухгалтер филиала',
            'branch_accountant_phone'=>'Телефон бухгалтера филиала', 'branch_email'=>'Email филиала',
            'branch_phone'=>'Телефон филиала', 'branch_requisites'=>'Банковские реквизиты филиала',
            'branch_bank_details'=>'[Филиал] Банковские реквизиты единым текстом',
            'branch_identity_details'=>'[Филиал] Название, адрес и БИН единым текстом',
            'branch_full_details'=>'[Филиал] Полные данные и реквизиты единым текстом',
            'branch_snapshot_bank_details'=>'[Заявка] Банковские реквизиты филиала на дату подачи',
            'branch_snapshot_identity_details'=>'[Заявка] Название и адрес филиала на дату подачи',
            'branch_snapshot_full_details'=>'[Заявка] Полные данные филиала на дату подачи',
            'branch_snapshot_date'=>'[Заявка] Дата фиксации реквизитов филиала',
            'branch_current__branch_bank_details'=>'[Филиал сейчас] Актуальные банковские реквизиты',
            'branch_current__branch_identity_details'=>'[Филиал сейчас] Актуальное название и адрес',
            'branch_current__branch_full_details'=>'[Филиал сейчас] Актуальные полные данные',
            'submission_date'=>'Дата подачи заявления', 'membership_date'=>'Дата вступления',
            'member_name_header'=>'ФИО участника в шапке документа',
            'signature'=>'Подпись участника из формы'
        ];
        foreach ($system as $key=>$label) {
            $defs[$key] = $label;
            $defs['submission__'.$key] = '[Заявка] '.$label;
            $defs['profile__'.$key] = '[Профиль] '.$label;
        }
        foreach ($this->get_forms() as $form) {
            foreach ($this->decode_form_fields($form->fields_json) as $field) {
                if (!in_array($field['type'], ['heading','hidden'], true)) {
                    $label = $field['label'] ?: $field['key'];
                    $defs[$field['key']] = $label;
                    $defs['submission__'.$field['key']] = '[Заявка] '.$label;
                    $defs['profile__'.$field['key']] = '[Профиль] '.$label;
                }
            }
        }
        foreach ($this->discovered_submission_fields() as $key=>$label) {
            if (!isset($defs[$key])) { $defs[$key] = $label; }
            $defs['submission__'.$key] = '[Заявка] '.$label;
        }
        foreach ($this->discovered_profile_fields() as $key=>$label) {
            if (!isset($defs[$key])) { $defs[$key] = $label; }
            $defs['profile__'.$key] = '[Профиль] '.$label;
        }
        foreach($this->member_card_fields() as $field){
            $key=$this->sanitize_field_key($field['key']);$label=$field['label']??$key;
            if(!isset($defs[$key]))$defs[$key]=$label;
            $defs['card__'.$key]='[Личная карточка] '.$label;
        }
        return $defs;
    }

    public function certificate_field_types($types) {
        $types = is_array($types) ? $types : [];
        $types['signature_url'] = 'image';
        $types['signature2_url'] = 'image';
        $types['stamp_url'] = 'image';
        $types['signature'] = 'image';
        foreach ($this->get_forms() as $form) {
            foreach ($this->decode_form_fields($form->fields_json) as $field) {
                $kind = $field['type'] === 'signature' ? 'image' : 'text';
                $types[$field['key']] = $kind;
                $types['submission__'.$field['key']] = $kind;
                $types['profile__'.$field['key']] = $kind;
            }
        }
        return $types;
    }

    private function initialize_branch_order() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, sort_order FROM {$this->branches_table} ORDER BY name ASC, id ASC");
        if (!$rows) { return; }
        $has_custom = false;
        foreach ($rows as $row) {
            if ((int)$row->sort_order !== 0) { $has_custom = true; break; }
        }
        if ($has_custom) { return; }
        $order = 10;
        foreach ($rows as $row) {
            $wpdb->update($this->branches_table, ['sort_order'=>$order], ['id'=>(int)$row->id]);
            $order += 10;
        }
    }

    private function next_branch_order() {
        global $wpdb;
        $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$this->branches_table}");
        return $max > 0 ? $max + 10 : 10;
    }

    private function branch_rows($active_only = false) {
        global $wpdb;
        $where = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results("SELECT * FROM {$this->branches_table} $where ORDER BY sort_order ASC, name ASC, id ASC");
    }

    private function get_branch($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->branches_table} WHERE id=%d", $id));
    }

    private function compose_branch_identity_details($row) {
        if (!$row) { return ''; }
        if (!empty($row->identity_details)) { return trim((string)$row->identity_details); }
        $parts = [];
        if (!empty($row->name)) { $parts[] = (string)$row->name; }
        if (!empty($row->legal_address)) { $parts[] = 'Юридический адрес: '.$row->legal_address; }
        if (!empty($row->union_bin)) { $parts[] = 'БИН: '.$row->union_bin; }
        if (!empty($row->region) && empty($row->legal_address)) { $parts[] = 'Регион: '.$row->region; }
        return implode("\n", $parts);
    }

    private function compose_branch_bank_details($row) {
        if (!$row) { return ''; }
        if (!empty($row->bank_details)) { return trim((string)$row->bank_details); }
        $parts = [];
        if (!empty($row->iban)) { $parts[] = 'ИИК / IBAN: '.$row->iban; }
        if (!empty($row->bik)) { $parts[] = 'БИК: '.$row->bik; }
        if (!empty($row->bank_name)) { $parts[] = 'Банк: '.$row->bank_name; }
        if (!$parts && !empty($row->requisites)) { $parts[] = trim((string)$row->requisites); }
        return implode("\n", array_values(array_unique(array_filter($parts))));
    }

    private function compose_branch_full_details($row) {
        if (!$row) { return ''; }
        if (!empty($row->full_details)) { return trim((string)$row->full_details); }
        $parts = [];
        $identity = $this->compose_branch_identity_details($row);
        $bank = $this->compose_branch_bank_details($row);
        if ($identity !== '') { $parts[] = $identity; }
        if ($bank !== '') { $parts[] = $bank; }
        if (!empty($row->chairman)) { $parts[] = 'Председатель: '.$row->chairman.(!empty($row->chairman_phone)?', '.$row->chairman_phone:''); }
        if (!empty($row->accountant)) { $parts[] = 'Главный бухгалтер: '.$row->accountant.(!empty($row->accountant_phone)?', '.$row->accountant_phone:''); }
        if (!empty($row->email)) { $parts[] = 'Email: '.$row->email; }
        if (!empty($row->phone)) { $parts[] = 'Телефон филиала: '.$row->phone; }
        return implode("\n", array_values(array_unique(array_filter($parts))));
    }

    private function backfill_branch_text_blocks() {
        global $wpdb;
        $rows = $this->branch_rows(false);
        foreach ((array)$rows as $row) {
            $update = [];
            if (empty($row->details_mode)) { $update['details_mode'] = 'text'; }
            if (empty($row->identity_details)) { $update['identity_details'] = $this->compose_branch_identity_details($row); }
            if (empty($row->bank_details)) { $update['bank_details'] = $this->compose_branch_bank_details($row); }
            if (empty($row->full_details)) { $update['full_details'] = $this->compose_branch_full_details($row); }
            if ($update) {
                $update['updated_at'] = current_time('mysql');
                $wpdb->update($this->branches_table, $update, ['id'=>(int)$row->id]);
            }
        }
    }

    private function compose_branch_requisites($row) {
        if (!$row) { return ''; }
        $bank = $this->compose_branch_bank_details($row);
        if ($bank !== '') { return $bank; }
        if (!empty($row->requisites)) { return trim((string)$row->requisites); }
        return '';
    }

    private function branch_data($row) {
        if (!$row) { return []; }
        $data = [
            'branch_id'=>(int)$row->id,
            'branch'=>(string)$row->name,
            'branch_name'=>(string)$row->name,
            'branch_code'=>(string)$row->code,
            'branch_region'=>(string)$row->region,
            'branch_union_bin'=>(string)$row->union_bin,
            'branch_iban'=>(string)$row->iban,
            'branch_bik'=>(string)$row->bik,
            'branch_bank_name'=>(string)$row->bank_name,
            'branch_legal_address'=>(string)$row->legal_address,
            'branch_chairman'=>(string)$row->chairman,
            'branch_chairman_phone'=>(string)$row->chairman_phone,
            'branch_accountant'=>(string)$row->accountant,
            'branch_accountant_phone'=>(string)$row->accountant_phone,
            'branch_email'=>(string)$row->email,
            'branch_phone'=>(string)$row->phone,
            'branch_requisites'=>$this->compose_branch_requisites($row),
            'branch_bank_details'=>$this->compose_branch_bank_details($row),
            'branch_bank_requisites'=>$this->compose_branch_bank_details($row),
            'branch_identity_details'=>$this->compose_branch_identity_details($row),
            'branch_address_details'=>$this->compose_branch_identity_details($row),
            'branch_full_details'=>$this->compose_branch_full_details($row),
            'branch_full_text'=>$this->compose_branch_full_details($row),
            'branch_details_mode'=>(string)($row->details_mode ?: 'text'),
        ];
        $extra = json_decode((string)$row->extra_json, true);
        if (is_array($extra)) {
            foreach ($extra as $key=>$value) {
                $key = 'branch_'.$this->sanitize_field_key($key);
                if (is_scalar($value) || $value === null) { $data[$key] = (string)$value; }
            }
        }
        return $data;
    }

    public function ajax_get_branch() {
        check_ajax_referer(self::NONCE, 'nonce');
        $row = $this->get_branch(absint($_POST['branch_id'] ?? 0));
        if (!$row || empty($row->active)) { wp_send_json_error(['message'=>'Филиал не найден или отключён.'], 404); }
        wp_send_json_success(['branch'=>$this->branch_data($row)]);
    }

    public function page_branches() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        $editId = absint($_GET['edit'] ?? 0);
        $edit = $editId ? $this->get_branch($editId) : null;
        $items = $this->branch_rows(false);
        ?>
        <div class="wrap zau-union-admin">
            <?php zau_admin_hub_nav('design'); ?>
            <div class="zau-union-head"><div><h1>Филиалы и реквизиты</h1><p>При выборе филиала в форме все данные автоматически подставляются в заявку и становятся доступными в PDF-шаблоне.</p></div></div>
            <form class="zau-union-card zau-branch-text-import" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="action" value="zau_union_import_branches_text">
                <h2>Массовая загрузка филиалов текстом</h2>
                <p><strong>Рекомендуемый режим:</strong> один филиал — один текстовый блок. Заголовок филиала укажите между знаками <code>===</code>, а ниже вставьте полное название, юридический адрес и банковские реквизиты с переносами строк.</p>
                <textarea name="branches_text" rows="13" class="large-text code" placeholder="=== Астанинский филиал ===
Астанинский филиал Казахстанского отраслевого профсоюза «AQNİET»
Юридический адрес: г. Астана, ул. Күлтегін, 11/1
БИН: 110241010418
ИИК: KZ688560000004352129
БИК: KCJBKZKX
Банк: АО «Банк ЦентрКредит»

=== Алматинский филиал ===
Алматинский филиал Казахстанского отраслевого профсоюза «AQNİET»
Юридический адрес: г. Алматы, ...
БИН: ...
ИИК: ...
БИК: ...
Банк: ..."></textarea>
                <details><summary>Другие поддерживаемые форматы</summary><p>Можно вставить только названия через запятую либо использовать старый формат строки с разделителем «точка с запятой».</p><p><code>Название; Код; Регион; БИН; ИИК/IBAN; БИК; Банк; Юридический адрес; Председатель; Телефон председателя; Бухгалтер; Телефон бухгалтера; Email; Телефон филиала; Полные реквизиты</code></p></details>
                <?php submit_button('Импортировать филиалы', 'secondary', 'submit', false); ?>
            </form>
            <div class="zau-union-columns">
                <form class="zau-union-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="action" value="zau_union_save_branch">
                    <input type="hidden" name="branch_id" value="<?php echo (int)$editId; ?>">
                    <h2><?php echo $edit ? 'Изменить филиал' : 'Добавить филиал'; ?></h2>
                    <div class="zau-union-grid-2">
                        <label>Название филиала<input type="text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>" placeholder="Филиал Астана"></label>
                        <label>Код<input type="text" name="code" required value="<?php echo esc_attr($edit->code ?? ''); ?>" placeholder="astana"></label>
                        <label>Регион <small>(для фильтрации)</small><input type="text" name="region" value="<?php echo esc_attr($edit->region ?? ''); ?>"></label>
                        <label>Режим данных<select name="details_mode"><option value="text" <?php selected(($edit->details_mode ?? 'text'),'text'); ?>>Единые текстовые блоки</option><option value="legacy" <?php selected(($edit->details_mode ?? 'text'),'legacy'); ?>>Раздельные поля — совместимость</option></select></label>
                        <label class="zau-span-2"><strong>Полные данные филиала для формы и документа</strong><textarea name="full_details" rows="10" placeholder="Полное название филиала
Юридический адрес: ...
БИН: ...
ИИК: ...
БИК: ...
Банк: ...
Председатель: ..."><?php echo esc_textarea($edit ? $this->compose_branch_full_details($edit) : ''); ?></textarea><small>Сохраняются переносы строк. В PDF используйте поле <code>branch_full_details</code>.</small></label>
                        <label class="zau-span-2">Название, юридический адрес и БИН<textarea name="identity_details" rows="5" placeholder="Название филиала
Юридический адрес: ...
БИН: ..."><?php echo esc_textarea($edit ? $this->compose_branch_identity_details($edit) : ''); ?></textarea><small>Поле PDF: <code>branch_identity_details</code>.</small></label>
                        <label class="zau-span-2">Банковские реквизиты<textarea name="bank_details" rows="5" placeholder="ИИК: ...
БИК: ...
Банк: ..."><?php echo esc_textarea($edit ? $this->compose_branch_bank_details($edit) : ''); ?></textarea><small>Поля PDF: <code>branch_bank_details</code> и старое <code>branch_requisites</code>.</small></label>
                        <details class="zau-span-2"><summary><strong>Раздельные поля старого формата</strong></summary><div class="zau-union-grid-2" style="margin-top:12px">
                        <label>БИН Профсоюза<input type="text" name="union_bin" value="<?php echo esc_attr($edit->union_bin ?? ''); ?>"></label>
                        <label>ИИК / IBAN<input type="text" name="iban" value="<?php echo esc_attr($edit->iban ?? ''); ?>"></label>
                        <label>БИК<input type="text" name="bik" value="<?php echo esc_attr($edit->bik ?? ''); ?>"></label>
                        <label>Банк<input type="text" name="bank_name" value="<?php echo esc_attr($edit->bank_name ?? ''); ?>"></label>
                        <label>Юридический адрес<input type="text" name="legal_address" value="<?php echo esc_attr($edit->legal_address ?? ''); ?>"></label>
                        <label>Председатель<input type="text" name="chairman" value="<?php echo esc_attr($edit->chairman ?? ''); ?>"></label>
                        <label>Телефон председателя<input type="text" name="chairman_phone" value="<?php echo esc_attr($edit->chairman_phone ?? ''); ?>"></label>
                        <label>Главный бухгалтер<input type="text" name="accountant" value="<?php echo esc_attr($edit->accountant ?? ''); ?>"></label>
                        <label>Телефон бухгалтера<input type="text" name="accountant_phone" value="<?php echo esc_attr($edit->accountant_phone ?? ''); ?>"></label>
                        <label>Email филиала<input type="email" name="email" value="<?php echo esc_attr($edit->email ?? ''); ?>"></label>
                        <label>Телефон филиала<input type="text" name="phone" value="<?php echo esc_attr($edit->phone ?? ''); ?>"></label>
                        <label class="zau-span-2">Старое поле «Полные реквизиты»<textarea name="requisites" rows="4"><?php echo esc_textarea($edit->requisites ?? ''); ?></textarea></label>
                        </div></details>
                        <label class="zau-span-2">Дополнительные поля JSON<textarea class="code" name="extra_json" rows="5" placeholder='{"contract_number":"123","city":"Астана"}'><?php echo esc_textarea($edit->extra_json ?? ''); ?></textarea><small>Каждое значение станет полем PDF с префиксом <code>branch_</code>.</small></label>
                        <label>Порядок в выпадающем списке<input type="number" min="0" step="1" name="sort_order" value="<?php echo esc_attr($edit ? (int)$edit->sort_order : $this->next_branch_order()); ?>"><small>Меньшее число показывается выше. Рекомендуется 10, 20, 30…</small></label>
                        <label><input type="checkbox" name="active" value="1" <?php checked($edit ? $edit->active : 1, 1); ?>> Филиал доступен в форме</label>
                    </div>
                    <?php submit_button($edit ? 'Сохранить филиал' : 'Добавить филиал'); ?>
                    <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-union-branches')); ?>">Отмена</a><?php endif; ?>
                </form>
                <div class="zau-union-card">
                    <h2>Список филиалов</h2>
                    <?php if (!$items): ?><p>Филиалов пока нет.</p><?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="action" value="zau_union_save_branch_order">
                    <p><small>Чем меньше число, тем выше филиал. Используйте 10, 20, 30 — так между ними можно вставлять новые позиции.</small></p>
                    <table class="widefat striped"><thead><tr><th style="width:90px">Порядок</th><th>Филиал</th><th>Реквизиты</th><th>Статус</th><th></th></tr></thead><tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><input type="number" min="0" step="1" name="branch_order[<?php echo (int)$item->id; ?>]" value="<?php echo (int)$item->sort_order; ?>" style="width:75px"></td>
                            <td><strong><?php echo esc_html($item->name); ?></strong><br><small><?php echo esc_html($item->region.' · '.$item->code); ?></small></td>
                            <td><small><?php echo esc_html(wp_trim_words($this->compose_branch_full_details($item), 26)); ?></small></td>
                            <td><?php echo $item->active ? 'Активен' : 'Отключён'; ?></td>
                            <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-union-branches&edit='.(int)$item->id)); ?>">Изменить</a> <a class="button button-link-delete" onclick="return confirm('Удалить филиал? Ранее созданные заявления сохранят свои данные.');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_union_delete_branch&id='.(int)$item->id), self::NONCE)); ?>">Удалить</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p style="margin-top:12px"><?php submit_button('Сохранить порядок филиалов', 'secondary', 'submit', false); ?></p>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_branch() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb;
        $id = absint($_POST['branch_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $code = sanitize_title(wp_unslash($_POST['code'] ?? '')) ?: sanitize_title($name);
        if ($name === '' || $code === '') { wp_die('Укажите название и код филиала.'); }
        $extra = wp_unslash($_POST['extra_json'] ?? '');
        if ($extra !== '') {
            json_decode($extra, true);
            if (json_last_error() !== JSON_ERROR_NONE) { wp_die('В дополнительных полях указан некорректный JSON.'); }
        }
        $data = [
            'code'=>$code,
            'name'=>$name,
            'region'=>sanitize_text_field(wp_unslash($_POST['region'] ?? '')),
            'union_bin'=>preg_replace('/\D/', '', (string)wp_unslash($_POST['union_bin'] ?? '')),
            'iban'=>sanitize_text_field(wp_unslash($_POST['iban'] ?? '')),
            'bik'=>sanitize_text_field(wp_unslash($_POST['bik'] ?? '')),
            'bank_name'=>sanitize_text_field(wp_unslash($_POST['bank_name'] ?? '')),
            'legal_address'=>sanitize_textarea_field(wp_unslash($_POST['legal_address'] ?? '')),
            'chairman'=>sanitize_text_field(wp_unslash($_POST['chairman'] ?? '')),
            'chairman_phone'=>sanitize_text_field(wp_unslash($_POST['chairman_phone'] ?? '')),
            'accountant'=>sanitize_text_field(wp_unslash($_POST['accountant'] ?? '')),
            'accountant_phone'=>sanitize_text_field(wp_unslash($_POST['accountant_phone'] ?? '')),
            'email'=>sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone'=>sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'requisites'=>sanitize_textarea_field(wp_unslash($_POST['requisites'] ?? '')),
            'details_mode'=>in_array(sanitize_key(wp_unslash($_POST['details_mode'] ?? 'text')), ['text','legacy'], true) ? sanitize_key(wp_unslash($_POST['details_mode'] ?? 'text')) : 'text',
            'full_details'=>sanitize_textarea_field(wp_unslash($_POST['full_details'] ?? '')),
            'identity_details'=>sanitize_textarea_field(wp_unslash($_POST['identity_details'] ?? '')),
            'bank_details'=>sanitize_textarea_field(wp_unslash($_POST['bank_details'] ?? '')),
            'extra_json'=>$extra,
            'sort_order'=>max(0, (int)($_POST['sort_order'] ?? ($id ? 0 : $this->next_branch_order()))),
            'active'=>empty($_POST['active']) ? 0 : 1,
            'updated_at'=>current_time('mysql'),
        ];
        if ($id) { $wpdb->update($this->branches_table, $data, ['id'=>$id]); }
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->branches_table, $data); $id=(int)$wpdb->insert_id; }
        $this->log('union_branch_saved', 'branch', $id, $name);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-branches&zau_notice='.rawurlencode('Филиал сохранён.'))); exit;
    }

    public function save_branch_order() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb;
        $orders = (array)($_POST['branch_order'] ?? []);
        $updated = 0;
        foreach ($orders as $id => $order) {
            $id = absint($id);
            if (!$id) { continue; }
            $result = $wpdb->update($this->branches_table, ['sort_order'=>max(0, (int)$order)], ['id'=>$id]);
            if ($result !== false) { $updated++; }
        }
        $this->log('union_branch_order_saved', 'branch', 0, 'updated='.$updated);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-branches&zau_notice='.rawurlencode('Порядок филиалов сохранён.'))); exit;
    }

    public function import_branches_text() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb;
        $raw = trim((string)wp_unslash($_POST['branches_text'] ?? ''));
        if ($raw === '') { wp_die('Вставьте список филиалов.'); }
        $created = 0; $updated = 0; $skipped = 0;
        $rows = [];
        if (preg_match('/^\s*(?:={3,}|#{2,})\s*.+?\s*(?:={3,})?\s*$/m', $raw)) {
            $currentName = ''; $buffer = [];
            $flush = function() use (&$rows, &$currentName, &$buffer) {
                if ($currentName === '') { $buffer = []; return; }
                $text = trim(implode("\n", $buffer));
                $rows[] = ['name'=>$currentName, 'code'=>sanitize_title($currentName), 'full_details'=>$text, 'details_mode'=>'text'];
                $currentName = ''; $buffer = [];
            };
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                if (preg_match('/^\s*(?:={3,}|#{2,})\s*(.*?)\s*(?:={3,})?\s*$/u', trim($line), $m)) {
                    $flush(); $currentName = sanitize_text_field(trim($m[1])); continue;
                }
                if ($currentName !== '') { $buffer[] = rtrim($line); }
            }
            $flush();
        } elseif (strpos($raw, ';') === false && strpos($raw, "\t") === false) {
            foreach (preg_split('/[,\r\n]+/', $raw) as $name) {
                $name = sanitize_text_field(trim($name));
                if ($name !== '') { $rows[] = ['name'=>$name, 'code'=>sanitize_title($name)]; }
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $header = null;
            foreach ($lines as $line_no=>$line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $delimiter = strpos($line, "\t") !== false ? "\t" : (strpos($line, ';') !== false ? ';' : ',');
                $cols = array_map('trim', str_getcsv($line, $delimiter));
                if ($header === null && preg_match('/назван|филиал|name|код|code|регион|region/i', implode(' ', $cols))) {
                    $maybe_header = [];
                    foreach ($cols as $index=>$label) {
                        $key = $this->branch_import_header_key($label);
                        if ($key) { $maybe_header[$index] = $key; }
                    }
                    if (isset($maybe_header[0]) && count($maybe_header) >= 2) { $header = $maybe_header; continue; }
                }
                $row = [];
                if ($header) {
                    foreach ($header as $index=>$key) { $row[$key] = $cols[$index] ?? ''; }
                } else {
                    $keys = ['name','code','region','union_bin','iban','bik','bank_name','legal_address','chairman','chairman_phone','accountant','accountant_phone','email','phone','requisites','full_details','identity_details','bank_details'];
                    foreach ($keys as $index=>$key) { $row[$key] = $cols[$index] ?? ''; }
                }
                $rows[] = $row;
            }
        }
        foreach ($rows as $row) {
            $name = sanitize_text_field($row['name'] ?? '');
            if ($name === '') { $skipped++; continue; }
            $code = sanitize_title($row['code'] ?? '') ?: sanitize_title($name);
            if ($code === '') { $code = 'branch-'.wp_generate_password(6, false, false); }
            $data = [
                'code'=>$code, 'name'=>$name,
                'region'=>sanitize_text_field($row['region'] ?? ''),
                'union_bin'=>preg_replace('/\D/', '', (string)($row['union_bin'] ?? '')),
                'iban'=>sanitize_text_field($row['iban'] ?? ''),
                'bik'=>sanitize_text_field($row['bik'] ?? ''),
                'bank_name'=>sanitize_text_field($row['bank_name'] ?? ''),
                'legal_address'=>sanitize_textarea_field($row['legal_address'] ?? ''),
                'chairman'=>sanitize_text_field($row['chairman'] ?? ''),
                'chairman_phone'=>sanitize_text_field($row['chairman_phone'] ?? ''),
                'accountant'=>sanitize_text_field($row['accountant'] ?? ''),
                'accountant_phone'=>sanitize_text_field($row['accountant_phone'] ?? ''),
                'email'=>sanitize_email($row['email'] ?? ''),
                'phone'=>sanitize_text_field($row['phone'] ?? ''),
                'requisites'=>sanitize_textarea_field($row['requisites'] ?? ''),
                'details_mode'=>in_array(sanitize_key($row['details_mode'] ?? 'text'), ['text','legacy'], true) ? sanitize_key($row['details_mode'] ?? 'text') : 'text',
                'full_details'=>sanitize_textarea_field($row['full_details'] ?? ''),
                'identity_details'=>sanitize_textarea_field($row['identity_details'] ?? ''),
                'bank_details'=>sanitize_textarea_field($row['bank_details'] ?? ''),
                'extra_json'=>'', 'active'=>1, 'updated_at'=>current_time('mysql'),
            ];
            $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->branches_table} WHERE code=%s LIMIT 1", $code));
            if (!$existing) { $data['sort_order'] = $this->next_branch_order(); }
            if ($existing) { $wpdb->update($this->branches_table, $data, ['id'=>$existing]); $updated++; }
            else { $data['created_at']=current_time('mysql'); if ($wpdb->insert($this->branches_table, $data)) { $created++; } else { $skipped++; } }
        }
        $this->log('union_branches_text_imported','branch',0,'created='.$created.' updated='.$updated.' skipped='.$skipped);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-branches&zau_notice='.rawurlencode("Филиалы обработаны. Добавлено: $created, обновлено: $updated, пропущено: $skipped."))); exit;
    }

    private function branch_import_header_key($label) {
        $label = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$label), 'UTF-8') : strtolower(trim((string)$label));
        $map = [
            'название'=>'name','название филиала'=>'name','филиал'=>'name','name'=>'name',
            'код'=>'code','code'=>'code','slug'=>'code','регион'=>'region','region'=>'region',
            'бин'=>'union_bin','бин профсоюза'=>'union_bin','union bin'=>'union_bin',
            'иик'=>'iban','iban'=>'iban','иик / iban'=>'iban','бик'=>'bik','bik'=>'bik',
            'банк'=>'bank_name','bank'=>'bank_name','юридический адрес'=>'legal_address','адрес'=>'legal_address','legal address'=>'legal_address',
            'председатель'=>'chairman','chairman'=>'chairman','телефон председателя'=>'chairman_phone','chairman phone'=>'chairman_phone',
            'бухгалтер'=>'accountant','главный бухгалтер'=>'accountant','accountant'=>'accountant','телефон бухгалтера'=>'accountant_phone','accountant phone'=>'accountant_phone',
            'email'=>'email','почта'=>'email','телефон филиала'=>'phone','телефон'=>'phone','phone'=>'phone','реквизиты'=>'requisites','полные реквизиты'=>'requisites','requisites'=>'requisites',
            'полные данные филиала'=>'full_details','единый текст'=>'full_details','full details'=>'full_details',
            'название и адрес'=>'identity_details','юрадрес и название'=>'identity_details','identity details'=>'identity_details',
            'банковские реквизиты'=>'bank_details','bank details'=>'bank_details',
        ];
        return $map[$label] ?? '';
    }

    public function delete_branch() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb;
        $id = absint($_GET['id'] ?? 0);
        $wpdb->delete($this->branches_table, ['id'=>$id]);
        $this->log('union_branch_deleted', 'branch', $id);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-branches')); exit;
    }

    public function page_login_history() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        global $wpdb;
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where = "l.action IN ('otp_login','pin_login','password_login','pin_reset_login','logout')";
        $params = [];
        if ($search !== '') {
            $where .= " AND (u.display_name LIKE %s OR u.user_email LIKE %s OR l.ip LIKE %s)";
            $like = '%'.$wpdb->esc_like($search).'%';
            $params = [$like,$like,$like];
        }
        $sql = "SELECT l.*,u.display_name,u.user_email FROM {$this->logs_table} l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id WHERE $where ORDER BY l.id DESC LIMIT 1000";
        if ($params) { $sql = $wpdb->prepare($sql, ...$params); }
        $items = $wpdb->get_results($sql);
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('users'); ?><div class="zau-union-head"><div><h1>История входов</h1><p>Входы по одноразовому коду и выходы из личного кабинета.</p></div></div>
        <form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-login-history"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="ФИО, email или IP"><button class="button">Найти</button></form>
        <table class="widefat striped"><thead><tr><th>Дата</th><th>Пользователь</th><th>Событие</th><th>Устройство / канал</th><th>IP</th></tr></thead><tbody>
        <?php if (!$items): ?><tr><td colspan="5">Записей пока нет.</td></tr><?php endif; ?>
        <?php foreach ($items as $item): ?><tr><td><?php echo esc_html($item->created_at); ?></td><td><?php echo esc_html(($item->display_name ?: '#'.$item->user_id).($item->user_email?' · '.$item->user_email:'')); ?></td><td><?php echo esc_html($this->login_action_label($item->action)); ?></td><td><?php echo esc_html($item->details); ?></td><td><?php echo esc_html($item->ip); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    public function page_forms() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        $edit_id = absint($_GET['edit'] ?? 0);
        if ($edit_id || isset($_GET['new'])) { $this->render_form_editor($edit_id); return; }
        $items = $this->get_forms();
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('design'); ?><div class="zau-union-head"><div><h1>Формы регистрации и заявлений</h1><p>Создавайте разные наборы полей и привязывайте к одной форме несколько PDF-шаблонов.</p></div><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-union-forms&new=1')); ?>">Добавить форму</a></div>
        <table class="widefat striped"><thead><tr><th>ID</th><th>Название</th><th>Шорткод</th><th>Полей</th><th>PDF-шаблонов</th><th>Статус</th><th></th></tr></thead><tbody>
        <?php if (!$items): ?><tr><td colspan="7">Форм пока нет.</td></tr><?php endif; ?>
        <?php foreach ($items as $item): $fields=$this->decode_form_fields($item->fields_json); $templates=json_decode($item->template_ids_json,true) ?: []; ?>
            <tr><td><?php echo (int)$item->id; ?></td><td><strong><?php echo esc_html($item->name); ?></strong><br><small><?php echo esc_html($item->description); ?></small></td><td><code>[zau_union_form id="<?php echo (int)$item->id; ?>"]</code></td><td><?php echo count($fields); ?></td><td><?php echo count($templates); ?></td><td><?php echo $item->active ? 'Активна' : 'Отключена'; ?></td><td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-union-forms&edit='.(int)$item->id)); ?>">Изменить</a> <a class="button button-link-delete" onclick="return confirm('Удалить форму? Заявки и документы сохранятся.');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_union_delete_form&id='.(int)$item->id), self::NONCE)); ?>">Удалить</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function render_form_editor($id) {
        global $wpdb;
        $form = $id ? $this->get_form($id) : null;
        $fields = $form ? $this->decode_form_fields($form->fields_json) : [];
        $selected_templates = $form ? (json_decode($form->template_ids_json, true) ?: []) : [];
        $templates = $wpdb->get_results("SELECT id,name,orientation FROM {$this->templates_table} ORDER BY name ASC");
        ?>
        <div class="wrap zau-union-admin" id="zau-union-form-editor"><div class="zau-union-head"><div><h1><?php echo $form ? 'Редактирование формы' : 'Новая форма'; ?></h1><p>Ключ поля используется для подстановки в PDF-шаблон. После сохранения поле появится в редакторе расположения шаблона.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-union-forms')); ?>">Назад</a></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="action" value="zau_union_save_form"><input type="hidden" name="form_id" value="<?php echo (int)$id; ?>"><input type="hidden" name="fields_json" id="zau-union-fields-json" value="<?php echo esc_attr(wp_json_encode($fields, JSON_UNESCAPED_UNICODE)); ?>">
            <div class="zau-union-card zau-union-grid-2"><label>Название формы<input type="text" name="name" required value="<?php echo esc_attr($form->name ?? ''); ?>"></label><label>Слаг<input type="text" name="slug" value="<?php echo esc_attr($form->slug ?? ''); ?>" placeholder="registraciya"></label><label class="zau-span-2">Описание<textarea name="description" rows="2"><?php echo esc_textarea($form->description ?? ''); ?></textarea></label><label><input type="checkbox" name="require_auth" value="1" <?php checked($form ? $form->require_auth : 1, 1); ?>> Требовать аккаунт участника (при прямой регистрации создаётся автоматически)</label><label><input type="checkbox" name="active" value="1" <?php checked($form ? $form->active : 1, 1); ?>> Форма активна</label></div>
            <div class="zau-union-card"><div class="zau-union-card-head"><h2>Поля формы</h2><button type="button" class="button button-primary" id="zau-add-form-field">Добавить поле</button></div><div id="zau-form-fields"></div></div>
            <div class="zau-union-card"><h2>Какие документы создавать после отправки</h2><p>Сначала загрузите фон и расставьте поля в разделе «Шаблоны». Можно выбрать одновременно заявление на вступление, заявление на взносы и сертификат.</p><div class="zau-template-checks">
            <?php if (!$templates): ?><p>Шаблонов пока нет. Создайте их в разделе «Шаблоны».</p><?php endif; ?>
            <?php foreach ($templates as $tpl): ?><label><input type="checkbox" name="template_ids[]" value="<?php echo (int)$tpl->id; ?>" <?php checked(in_array((int)$tpl->id, array_map('intval',$selected_templates), true)); ?>> <strong><?php echo esc_html($tpl->name); ?></strong> — <?php echo $tpl->orientation==='portrait'?'книжная':'альбомная'; ?></label><?php endforeach; ?>
            </div></div>
            <?php submit_button('Сохранить форму'); ?>
        </form></div>
        <?php
    }

    public function save_form() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb;
        $id = absint($_POST['form_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') { wp_die('Укажите название формы.'); }
        $slug = sanitize_title(wp_unslash($_POST['slug'] ?? '')) ?: sanitize_title($name);
        $fields = $this->decode_form_fields(wp_unslash($_POST['fields_json'] ?? '[]'));
        $templates = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['template_ids'] ?? [])))));
        $data = [
            'name'=>$name, 'slug'=>$slug,
            'description'=>sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'fields_json'=>wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
            'template_ids_json'=>wp_json_encode($templates),
            'require_auth'=>empty($_POST['require_auth']) ? 0 : 1,
            'active'=>empty($_POST['active']) ? 0 : 1,
            'updated_at'=>current_time('mysql'),
        ];
        if ($id) { $wpdb->update($this->forms_table, $data, ['id'=>$id]); }
        else { $data['created_by']=get_current_user_id(); $data['created_at']=current_time('mysql'); $wpdb->insert($this->forms_table, $data); $id=(int)$wpdb->insert_id; }
        $settings = $this->settings();
        if (empty($settings['default_form_id'])) { $settings['default_form_id']=$id; update_option(self::OPT_SETTINGS,$settings,false); }
        $this->log('union_form_saved','form',$id,$name);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-forms&edit='.$id.'&zau_notice='.rawurlencode('Форма сохранена.'))); exit;
    }

    public function delete_form() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        global $wpdb; $id=absint($_GET['id']??0);
        $wpdb->delete($this->forms_table,['id'=>$id]);
        $this->log('union_form_deleted','form',$id);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-forms')); exit;
    }

    public function page_members_access() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));
        $perPage=max(25,min(200,absint($_GET['per_page']??100)));
        $paged=max(1,absint($_GET['paged']??1));
        $offset=($paged-1)*$perPage;
        $args=['number'=>$perPage,'offset'=>$offset,'count_total'=>true,'orderby'=>'display_name','order'=>'ASC','meta_query'=>[['key'=>'zau_hidden_from_registry','compare'=>'NOT EXISTS']]];
        if($search!==''){$args['search']='*'.$search.'*';$args['search_columns']=['user_login','user_email','display_name'];}
        $query=new WP_User_Query($args);$users=$query->get_results();$total=(int)$query->get_total();$pages=max(1,(int)ceil($total/$perPage));
        $organizations=$this->organization_rows();$branches=$this->branch_rows(false);
        $pagination=paginate_links(['base'=>add_query_arg(['page'=>'zau-union-members','s'=>$search,'per_page'=>$perPage,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'type'=>'list','prev_text'=>'‹ Назад','next_text'=>'Вперёд ›']);?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('users'); ?><div class="zau-union-head"><div><h1>Участники и доступ организаций/филиалов</h1><p>Привязка участника и назначение ответственных. Ответственный может работать по организации, по филиалу или по обоим условиям.</p></div></div>
        <form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-members"><input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="ФИО или email"><select name="per_page"><option value="50" <?php selected($perPage,50);?>>50</option><option value="100" <?php selected($perPage,100);?>>100</option><option value="200" <?php selected($perPage,200);?>>200</option></select><button class="button">Найти</button></form>
        <p><strong>Всего участников: <?php echo number_format_i18n($total);?></strong> · показано <?php echo number_format_i18n(count($users));?> · страница <?php echo (int)$paged;?> из <?php echo (int)$pages;?></p>
        <?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?>
        <table class="widefat striped"><thead><tr><th>Участник</th><th>Организация участника</th><th>Филиал участника</th><th>Статус</th><th>Область ответственности</th><th></th></tr></thead><tbody><?php if(!$users):?><tr><td colspan="6">Пользователи не найдены.</td></tr><?php endif;?>
        <?php foreach($users as $user):$orgId=$this->member_org_id($user->ID);$branchId=$this->member_branch_id($user->ID);$status=get_user_meta($user->ID,'zau_member_status',true);$isManager=user_can($user,ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE);$managedOrgs=$this->managed_org_ids($user->ID);$managedBranches=$this->managed_branch_ids($user->ID);?>
        <tr><td><strong><?php echo esc_html($user->display_name);?></strong><br><small><?php echo esc_html($user->user_email.' '.get_user_meta($user->ID,'zau_phone',true));?></small></td>
        <td><select form="zau-member-access-<?php echo (int)$user->ID;?>" name="organization_id" style="max-width:360px"><option value="0">— не назначена —</option><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected($orgId,$org->id);?>><?php echo esc_html($org->name.($org->bin?' · БИН '.$org->bin:' · БИН не указан'));?></option><?php endforeach;?></select></td>
        <td><select form="zau-member-access-<?php echo (int)$user->ID;?>" name="branch_id" style="max-width:280px"><option value="0">— не назначен —</option><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected($branchId,$branch->id);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select></td>
        <td><?php echo esc_html($status?:'Регистрация не завершена');?></td>
        <td><label><input form="zau-member-access-<?php echo (int)$user->ID;?>" type="checkbox" name="is_org_manager" value="1" <?php checked($isManager);?>> Ответственный</label><details><summary>Организации и филиалы</summary><p><strong>Организации</strong></p><select form="zau-member-access-<?php echo (int)$user->ID;?>" name="managed_org_ids[]" multiple size="6" style="min-width:360px"><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected(in_array((int)$org->id,$managedOrgs,true),true);?>><?php echo esc_html($org->name);?></option><?php endforeach;?></select><p><strong>Филиалы</strong></p><select form="zau-member-access-<?php echo (int)$user->ID;?>" name="managed_branch_ids[]" multiple size="6" style="min-width:360px"><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected(in_array((int)$branch->id,$managedBranches,true),true);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select></details></td>
        <td><form id="zau-member-access-<?php echo (int)$user->ID;?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_save_member_access"><input type="hidden" name="user_id" value="<?php echo (int)$user->ID;?>"><button class="button button-primary">Сохранить</button> <a class="button" href="<?php echo esc_url(get_edit_user_link($user->ID));?>">Профиль</a></form></td></tr>
        <?php endforeach;?></tbody></table>
        <?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?></div><?php
    }

    public function save_member_access() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);check_admin_referer(self::NONCE);$userId=absint($_POST['user_id']??0);$user=get_user_by('id',$userId);if(!$user)wp_die('Пользователь не найден.');global $wpdb;
        $orgId=absint($_POST['organization_id']??0);if($orgId){$org=$wpdb->get_row($wpdb->prepare("SELECT id,bin,name FROM {$this->orgs_table} WHERE id=%d",$orgId));if($org){update_user_meta($userId,'zau_organization_id',(int)$org->id);if(!empty($org->bin))update_user_meta($userId,'zau_organization_bin',$org->bin);else delete_user_meta($userId,'zau_organization_bin');update_user_meta($userId,'zau_organization_name',$org->name);}}else delete_user_meta($userId,'zau_organization_id');
        $branchId=absint($_POST['branch_id']??0);if($branchId){$branch=$this->get_branch($branchId);update_user_meta($userId,'zau_profile_branch_id',$branchId);if($branch)update_user_meta($userId,'zau_profile_branch_name',$branch->name);}else delete_user_meta($userId,'zau_profile_branch_id');
        $managedOrgs=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['managed_org_ids']??[])))));$managedBranches=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['managed_branch_ids']??[])))));
        update_user_meta($userId,'zau_managed_org_ids',$managedOrgs);update_user_meta($userId,'zau_managed_branch_ids',$managedBranches);$wpUser=new WP_User($userId);
        if(!empty($_POST['is_org_manager'])&&($managedOrgs||$managedBranches))$wpUser->add_cap(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE);else $wpUser->remove_cap(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE);
        $this->log('member_access_saved','user',$userId,'organization='.$orgId.'; branch='.$branchId.'; managed_orgs='.implode(',',$managedOrgs).'; managed_branches='.implode(',',$managedBranches));
        wp_safe_redirect(admin_url('admin.php?page=zau-union-members&zau_notice='.rawurlencode('Доступ участника сохранён.')));exit;
    }

    public function page_submissions() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_CREATE);
        global $wpdb;
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));$perPage=max(25,min(200,absint($_GET['per_page']??100)));$paged=max(1,absint($_GET['paged']??1));$offset=($paged-1)*$perPage;
        $where='1=1';$args=[];
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=' AND (s.data_json LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';$args=[$like,$like,$like];}
        $countSql="SELECT COUNT(*) FROM {$this->submissions_table} s LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id WHERE $where";$total=(int)$wpdb->get_var($args?$wpdb->prepare($countSql,$args):$countSql);$pages=max(1,(int)ceil($total/$perPage));
        $sql="SELECT s.*,f.name form_name,u.display_name,u.user_email FROM {$this->submissions_table} s LEFT JOIN {$this->forms_table} f ON f.id=s.form_id LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id WHERE $where ORDER BY s.id DESC LIMIT %d OFFSET %d";
        $queryArgs=array_merge($args,[$perPage,$offset]);$items=$wpdb->get_results($wpdb->prepare($sql,$queryArgs));
        $pagination=paginate_links(['base'=>add_query_arg(['page'=>'zau-union-submissions','s'=>$search,'per_page'=>$perPage,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'type'=>'list','prev_text'=>'‹ Назад','next_text'=>'Вперёд ›']);
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('users'); ?><div class="zau-union-head"><div><h1>Заявки участников</h1><p>Данные формы, связанные документы и изображения подписей.</p></div></div><form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-submissions"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="ФИО, email, БИН, организация"><select name="per_page"><option value="50" <?php selected($perPage,50);?>>50</option><option value="100" <?php selected($perPage,100);?>>100</option><option value="200" <?php selected($perPage,200);?>>200</option></select><button class="button">Найти</button></form>
        <p><strong>Всего заявлений: <?php echo number_format_i18n($total);?></strong> · показано <?php echo number_format_i18n(count($items));?> · страница <?php echo (int)$paged;?> из <?php echo (int)$pages;?></p><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?>
        <table class="widefat striped"><thead><tr><th>ID/дата</th><th>Форма</th><th>Участник</th><th>Организация</th><th>Статус</th><th>Документы</th><th>Данные</th></tr></thead><tbody>
        <?php if(!$items):?><tr><td colspan="7">Заявок пока нет.</td></tr><?php endif;?>
        <?php foreach($items as $item): $data=json_decode($item->data_json,true)?:[]; $docs=$wpdb->get_results($wpdb->prepare("SELECT document_no,pdf_url,verify_token,record_status FROM {$this->docs_table} WHERE source_submission_id=%d ORDER BY id",$item->id)); ?>
        <tr><td><strong>#<?php echo (int)$item->id;?></strong><br><small><?php echo esc_html($item->created_at);?></small></td><td><?php echo esc_html($item->form_name);?></td><td><strong><?php echo esc_html($data['full_name']??$item->display_name);?></strong><br><small><?php echo esc_html($data['phone']??'');?> <?php echo esc_html($data['email']??$item->user_email);?></small></td><td><?php echo esc_html($data['organization']??'');?><br><small><?php echo esc_html($data['organization_bin']??'');?></small></td><td><?php echo esc_html($item->status);?></td><td><?php foreach($docs as $doc):?><div><?php echo esc_html($doc->document_no);?>: <?php if($doc->pdf_url):?><a target="_blank" href="<?php echo esc_url($doc->pdf_url);?>">PDF</a><?php else:?>готовится<?php endif;?></div><?php endforeach;?></td><td><details><summary>Показать</summary><pre class="zau-json-view"><?php echo esc_html(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));?></pre></details></td></tr>
        <?php endforeach;?></tbody></table><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?></div>
        <?php
    }

    public function page_organizations() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        global $wpdb;
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));$perPage=max(25,min(200,absint($_GET['per_page']??100)));$paged=max(1,absint($_GET['paged']??1));$offset=($paged-1)*$perPage;
        $where='1=1';$args=[];if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=' AND (bin LIKE %s OR name LIKE %s OR director LIKE %s)';$args=[$like,$like,$like];}
        $countSql="SELECT COUNT(*) FROM {$this->orgs_table} WHERE $where";$total=(int)$wpdb->get_var($args?$wpdb->prepare($countSql,$args):$countSql);$pages=max(1,(int)ceil($total/$perPage));
        $sql="SELECT * FROM {$this->orgs_table} WHERE $where ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d";$items=$wpdb->get_results($wpdb->prepare($sql,array_merge($args,[$perPage,$offset])));
        $linkedUsers=(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_organization_id' AND meta_value<>'' AND meta_value<>'0'");
        $importedNoOrg=(int)$wpdb->get_var("SELECT COUNT(DISTINCT imported.user_id) FROM {$wpdb->usermeta} imported LEFT JOIN {$wpdb->usermeta} org ON org.user_id=imported.user_id AND org.meta_key='zau_organization_id' AND org.meta_value<>'' AND org.meta_value<>'0' WHERE imported.meta_key='zau_imported_from_legacy_site' AND imported.meta_value='1' AND org.umeta_id IS NULL");
        $pagination=paginate_links(['base'=>add_query_arg(['page'=>'zau-union-organizations','s'=>$search,'per_page'=>$perPage,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'type'=>'list','prev_text'=>'‹ Назад','next_text'=>'Вперёд ›']);
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('design'); ?><div class="zau-union-head"><div><h1>Организации и поиск по БИН</h1><p>Справочник теперь выводится постранично. Ранее страница показывала только первые 500 записей, из-за чего казалось, что большая часть организаций отсутствует.</p></div></div>
        <div class="zau-union-columns"><form class="zau-union-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_save_org"><h2>Добавить организацию</h2><label>БИН<input name="bin" required maxlength="20"></label><label>Наименование<input name="name" required></label><label>Руководитель<input name="director"></label><label>Адрес<textarea name="address"></textarea></label><label>Регион<input name="region"></label><?php submit_button('Сохранить');?></form>
        <form class="zau-union-card" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_import_orgs"><h2>Импорт CSV</h2><p>Колонки: <code>БИН;Наименование;Руководитель;Адрес;Регион</code>.</p><input type="file" name="org_file" accept=".csv,text/csv" required><?php submit_button('Импортировать');?></form></div>
        <div class="notice notice-info inline"><p><strong>Всего организаций в справочнике: <?php echo number_format_i18n($total);?>.</strong> Пользователей с назначенной организацией: <?php echo number_format_i18n($linkedUsers);?>. Перенесённых пользователей без назначенной организации: <?php echo number_format_i18n($importedNoOrg);?>.</p><?php if($importedNoOrg>0):?><p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-remote-profile-repair'));?>">Открыть «Исправить профили»</a> — этот инструмент создаёт недостающие организации из всех перенесённых заявлений и привязывает к ним аккаунты без дублей.</p><?php endif;?></div>
        <form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-organizations"><input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="БИН, название, руководитель"><select name="per_page"><option value="50" <?php selected($perPage,50);?>>50</option><option value="100" <?php selected($perPage,100);?>>100</option><option value="200" <?php selected($perPage,200);?>>200</option></select><button class="button">Найти</button></form>
        <p>Показано <?php echo number_format_i18n(count($items));?> · страница <?php echo (int)$paged;?> из <?php echo (int)$pages;?></p><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?>
        <table class="widefat striped"><thead><tr><th>БИН</th><th>Организация</th><th>Руководитель</th><th>Адрес/регион</th><th>Источник</th><th></th></tr></thead><tbody><?php if(!$items):?><tr><td colspan="6">Справочник пуст.</td></tr><?php endif;?><?php foreach($items as $item):?><tr><td><strong><?php echo esc_html($item->bin?:'не указан');?></strong></td><td><?php echo esc_html($item->name);?></td><td><?php echo esc_html($item->director);?></td><td><?php echo esc_html($item->address);?><br><small><?php echo esc_html($item->region);?></small></td><td><?php echo esc_html($item->source);?></td><td><a class="button button-link-delete" onclick="return confirm('Удалить организацию?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_union_delete_org&id='.(int)$item->id),self::NONCE));?>">Удалить</a></td></tr><?php endforeach;?></tbody></table><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?></div>
        <?php
    }

    public function save_organization() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE); check_admin_referer(self::NONCE);
        $this->upsert_organization([
            'bin'=>$this->normalize_bin($_POST['bin']??''), 'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),
            'director'=>sanitize_text_field(wp_unslash($_POST['director']??'')), 'address'=>sanitize_textarea_field(wp_unslash($_POST['address']??'')),
            'region'=>sanitize_text_field(wp_unslash($_POST['region']??'')), 'source'=>'manual'
        ]);
        wp_safe_redirect(admin_url('admin.php?page=zau-union-organizations')); exit;
    }

    public function delete_organization() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE); check_admin_referer(self::NONCE); global $wpdb;
        $wpdb->delete($this->orgs_table,['id'=>absint($_GET['id']??0)]); wp_safe_redirect(admin_url('admin.php?page=zau-union-organizations')); exit;
    }

    public function import_organizations() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE); check_admin_referer(self::NONCE);
        if(empty($_FILES['org_file']['tmp_name']) || !is_uploaded_file($_FILES['org_file']['tmp_name']))wp_die('Файл не загружен.');
        $raw=file_get_contents($_FILES['org_file']['tmp_name']); if($raw===false)wp_die('Не удалось прочитать файл.');
        $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw); $first=strtok($raw,"\r\n"); $delimiter=substr_count((string)$first,';')>=substr_count((string)$first,',')?';':',';
        $fh=fopen('php://temp','r+'); fwrite($fh,$raw); rewind($fh); $count=0; $rowNo=0;
        while(($row=fgetcsv($fh,0,$delimiter))!==false){$rowNo++; if($rowNo===1 && preg_match('/бин/i',implode(' ', $row)))continue; $bin=$this->normalize_bin($row[0]??''); $name=sanitize_text_field($row[1]??''); if(!$bin||!$name)continue; $this->upsert_organization(['bin'=>$bin,'name'=>$name,'director'=>sanitize_text_field($row[2]??''),'address'=>sanitize_textarea_field($row[3]??''),'region'=>sanitize_text_field($row[4]??''),'source'=>'csv']); $count++;}
        fclose($fh); wp_safe_redirect(admin_url('admin.php?page=zau-union-organizations&zau_notice='.rawurlencode('Импортировано: '.$count))); exit;
    }

    private function upsert_organization($data) {
        global $wpdb; $bin=$this->normalize_bin($data['bin']??''); $name=sanitize_text_field($data['name']??''); if(!$bin||!$name)return false;
        $row=['bin'=>$bin,'name'=>$name,'director'=>sanitize_text_field($data['director']??''),'address'=>sanitize_textarea_field($data['address']??''),'region'=>sanitize_text_field($data['region']??''),'source'=>sanitize_key($data['source']??'local'),'updated_at'=>current_time('mysql')];
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->orgs_table} WHERE bin=%s",$bin));
        if($existing){$wpdb->update($this->orgs_table,$row,['id'=>$existing]);return $existing;} $wpdb->insert($this->orgs_table,$row); return (int)$wpdb->insert_id;
    }

    private function organization_mismatch_rows($limit = 3000) {
        global $wpdb;
        $limit = max(1, min(20000, (int) $limit));
        $userIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_organization_id' AND meta_value<>'' AND meta_value<>'0' LIMIT %d",
            $limit
        ));
        $userIds = array_values(array_unique(array_filter(array_map('absint', (array) $userIds))));
        if (!$userIds) { return []; }
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

        $orgIdByUser = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key='zau_organization_id' AND user_id IN ($placeholders)", $userIds)) as $row) {
            $orgIdByUser[(int) $row->user_id] = absint($row->meta_value);
        }
        $orgIds = array_values(array_unique(array_filter($orgIdByUser)));
        $orgById = [];
        if ($orgIds) {
            $orgPlaceholders = implode(',', array_fill(0, count($orgIds), '%d'));
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id,name,bin FROM {$this->orgs_table} WHERE id IN ($orgPlaceholders)", $orgIds)) as $org) {
                $orgById[(int) $org->id] = $org;
            }
        }

        // For each user, find the newest submission that actually carries an
        // organization name — some forms (e.g. dues-only) have no such field.
        $declaredByUser = [];
        $subRows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id,data_json,created_at,id FROM {$this->submissions_table} WHERE user_id IN ($placeholders) ORDER BY user_id ASC,id DESC",
            $userIds
        ));
        foreach ($subRows as $row) {
            $uid = (int) $row->user_id;
            if (isset($declaredByUser[$uid])) { continue; }
            $data = json_decode((string) $row->data_json, true);
            if (!is_array($data) || empty($data['organization'])) { continue; }
            $declaredByUser[$uid] = [
                'organization' => sanitize_text_field((string) $data['organization']),
                'organization_bin' => sanitize_text_field((string) ($data['organization_bin'] ?? '')),
                'submission_id' => (int) $row->id,
                'submission_date' => (string) $row->created_at,
            ];
        }

        $userById = [];
        foreach (get_users(['include' => $userIds, 'fields' => ['ID', 'display_name', 'user_email']]) as $u) {
            $userById[(int) $u->ID] = $u;
        }

        $rows = [];
        foreach ($userIds as $uid) {
            $declared = $declaredByUser[$uid] ?? null;
            if (!$declared) { continue; }
            $orgId = $orgIdByUser[$uid] ?? 0;
            $org = $orgId ? ($orgById[$orgId] ?? null) : null;
            if (!$org || $org->name === '') { continue; }
            $registryNorm = $this->normalize_audience_text($org->name);
            $declaredNorm = $this->normalize_audience_text($declared['organization']);
            if ($registryNorm === '' || $declaredNorm === '' || $registryNorm === $declaredNorm) { continue; }
            $user = $userById[$uid] ?? null;
            $proposed = class_exists('ZAU_Remote_Profile_Repair')
                ? ZAU_Remote_Profile_Repair::instance()->resolve_organization_by_name($declared['organization'], true, true)
                : ['id' => 0, 'name' => $declared['organization'], 'action' => 'unresolved'];
            $rows[] = [
                'user_id' => $uid,
                'display_name' => $user ? $user->display_name : ('#' . $uid),
                'email' => $user ? $user->user_email : '',
                'registry_org' => $org->name,
                'registry_bin' => (string) $org->bin,
                'declared_org' => $declared['organization'],
                'declared_bin' => $declared['organization_bin'],
                'submission_id' => $declared['submission_id'],
                'submission_date' => $declared['submission_date'],
                'proposed_org_id' => (int) ($proposed['id'] ?? 0),
                'proposed_org_name' => (string) ($proposed['name'] ?? $declared['organization']),
                'proposed_action' => (string) ($proposed['action'] ?? 'unresolved'),
            ];
        }
        usort($rows, function ($a, $b) { return strcasecmp($a['display_name'], $b['display_name']); });
        return $rows;
    }

    private function organization_mismatch_action_label($action) {
        $labels = [
            'matched_name' => 'Найдена существующая организация',
            'would_create_bin' => 'Будет создана новая организация с этим БИН',
            'would_create_name' => 'Будет создана отдельная запись без БИН (БИН уже занят другим учреждением)',
            'ambiguous' => 'Несколько похожих организаций — нужно выбрать вручную',
            'unresolved' => 'Не удалось подобрать — потребуется ручное исправление',
        ];
        return $labels[$action] ?? $action;
    }

    public function page_organization_audit() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        $limit = max(200, min(20000, absint($_GET['scan_limit'] ?? 3000)));
        $rows = $this->organization_mismatch_rows($limit);
        $fixed = isset($_GET['fixed']) ? absint($_GET['fixed']) : null;
        ?>
        <div class="wrap zau-union-admin">
        <?php zau_admin_hub_nav('import'); ?>
        <div class="zau-union-head"><div><h1>Проверка организаций</h1><p>Сравнивает организацию, назначенную участнику в реестре, с организацией из его последнего заявления. Расхождение возможно, если несколько разных учреждений используют один и тот же БИН (например, подчинены одному управлению здравоохранения) — тогда автоматическая привязка по БИН могла выбрать не то учреждение, и в реестре показывалась чужая организация. Начиная с этой версии новые и пересданные заявления с общим БИН больше не привязываются к чужому учреждению автоматически — но уже возникшие расхождения нужно поправить здесь вручную.</p></div>
        <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_union_download_org_mismatch_report&scan_limit='.$limit),self::NONCE));?>">Скачать CSV</a></div>
        <?php if($fixed!==null):?><div class="notice notice-success is-dismissible"><p>Исправлено участников: <strong><?php echo (int)$fixed;?></strong>.</p></div><?php endif;?>
        <form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-org-audit"><label>Проверить участников (максимум): <select name="scan_limit"><?php foreach([1000,3000,5000,10000,20000] as $option):?><option value="<?php echo (int)$option;?>" <?php selected($limit,$option);?>><?php echo number_format_i18n($option);?></option><?php endforeach;?></select></label><button class="button">Проверить</button></form>
        <p><strong>Найдено расхождений: <?php echo number_format_i18n(count($rows));?></strong> среди проверенных участников с назначенной организацией (проверено не более <?php echo number_format_i18n($limit);?>).</p>
        <?php if($rows):?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
        <?php wp_nonce_field(self::NONCE);?>
        <input type="hidden" name="action" value="zau_union_apply_org_fix">
        <input type="hidden" name="scan_limit" value="<?php echo (int)$limit;?>">
        <p><button type="submit" class="button button-primary" onclick="return confirm('Применить предложенное исправление для отмеченных участников? Для каждого будет подобрана или создана организация по названию из его заявления.');">Исправить отмеченные</button></p>
        <table class="widefat striped"><thead><tr><th style="width:24px"><input type="checkbox" onclick="jQuery(this).closest('table').find('tbody input[type=checkbox]').prop('checked',this.checked);"></th><th>Участник</th><th>Организация в реестре (сейчас)</th><th>Организация в последнем заявлении</th><th>Предлагаемое исправление</th><th>Заявление</th><th></th></tr></thead><tbody>
        <?php foreach($rows as $row):?><tr>
            <td><input type="checkbox" name="user_ids[]" value="<?php echo (int)$row['user_id'];?>" checked></td>
            <td><strong><?php echo esc_html($row['display_name']);?></strong><br><small><?php echo esc_html($row['email']);?></small></td>
            <td><?php echo esc_html($row['registry_org']);?><?php if($row['registry_bin']):?><br><small>БИН <?php echo esc_html($row['registry_bin']);?> (общий на несколько учреждений)</small><?php endif;?></td>
            <td><?php echo esc_html($row['declared_org']);?><?php if($row['declared_bin']):?><br><small>БИН <?php echo esc_html($row['declared_bin']);?></small><?php endif;?></td>
            <td><?php echo esc_html($row['proposed_org_name']);?><br><small><?php echo esc_html($this->organization_mismatch_action_label($row['proposed_action']));?></small></td>
            <td>#<?php echo (int)$row['submission_id'];?><br><small><?php echo esc_html($row['submission_date']);?></small></td>
            <td><a class="button" href="<?php echo esc_url(admin_url('user-edit.php?user_id='.(int)$row['user_id']));?>">Открыть профиль</a></td>
        </tr><?php endforeach;?>
        </tbody></table>
        <p><button type="submit" class="button button-primary" onclick="return confirm('Применить предложенное исправление для отмеченных участников? Для каждого будет подобрана или создана организация по названию из его заявления.');">Исправить отмеченные</button></p>
        </form>
        <?php else: ?>
        <table class="widefat striped"><thead><tr><th>Участник</th><th>Организация в реестре</th><th>Организация в последнем заявлении</th><th>Заявление</th><th></th></tr></thead><tbody><tr><td colspan="5">Расхождений не найдено.</td></tr></tbody></table>
        <?php endif;?>
        </div>
        <?php
    }

    public function apply_organization_fix() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        $limit = max(200, min(20000, absint($_POST['scan_limit'] ?? 3000)));
        $requestedIds = array_values(array_unique(array_filter(array_map('absint', (array) ($_POST['user_ids'] ?? [])))));
        $count = 0;
        if ($requestedIds) {
            // Re-derive the mismatch set fresh rather than trusting hidden
            // form values, so a fix always reflects the member's current data.
            $currentRows = $this->organization_mismatch_rows($limit);
            $selected = array_flip($requestedIds);
            foreach ($currentRows as $row) {
                if (!isset($selected[$row['user_id']])) { continue; }
                if ($row['declared_org'] === '') { continue; }
                $match = class_exists('ZAU_Remote_Profile_Repair')
                    ? ZAU_Remote_Profile_Repair::instance()->resolve_organization_by_name($row['declared_org'], true, false)
                    : ['id' => $this->resolve_organization_by_name($row['declared_org'])];
                if (empty($match['id'])) { continue; }
                update_user_meta($row['user_id'], 'zau_organization_id', (int) $match['id']);
                if (!empty($match['name'])) { update_user_meta($row['user_id'], 'zau_organization_name', (string) $match['name']); }
                $count++;
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=zau-union-org-audit&scan_limit='.$limit.'&fixed='.$count));
        exit;
    }

    public function download_org_mismatch_report() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        $limit = max(200, min(20000, absint($_GET['scan_limit'] ?? 3000)));
        $rows = $this->organization_mismatch_rows($limit);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name('zau-org-mismatch-'.wp_date('Y-m-d-H-i').'.csv').'"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['User ID','ФИО','Email','Организация в реестре','БИН в реестре','Организация в заявлении','БИН в заявлении','ID заявления','Дата заявления','Предлагаемое исправление','Действие'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [$row['user_id'],$row['display_name'],$row['email'],$row['registry_org'],$row['registry_bin'],$row['declared_org'],$row['declared_bin'],$row['submission_id'],$row['submission_date'],$row['proposed_org_name'],$this->organization_mismatch_action_label($row['proposed_action'])], ';');
        }
        fclose($out);
        exit;
    }

    public function page_settings() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE); $s=$this->settings();
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('design'); ?><h1>Вход, PIN, дизайн и доступ к вкладкам</h1><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" class="zau-union-card"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_save_settings">
        <h2>Страницы</h2><table class="form-table"><tr><th>Форма по умолчанию</th><td><select name="default_form_id"><?php foreach($this->get_forms() as $f):?><option value="<?php echo (int)$f->id;?>" <?php selected($s['default_form_id'],$f->id);?>><?php echo esc_html($f->name);?></option><?php endforeach;?></select></td></tr><tr><th>Страница входа</th><td><?php wp_dropdown_pages(['name'=>'login_page_id','selected'=>(int)$s['login_page_id'],'show_option_none'=>'— выберите —']);?></td></tr><tr><th>Страница формы</th><td><?php wp_dropdown_pages(['name'=>'form_page_id','selected'=>(int)$s['form_page_id'],'show_option_none'=>'— выберите —']);?></td></tr><tr><th>Личный кабинет</th><td><?php wp_dropdown_pages(['name'=>'cabinet_page_id','selected'=>(int)$s['cabinet_page_id'],'show_option_none'=>'— выберите —']);?></td></tr><tr><th>Реестр организации</th><td><?php wp_dropdown_pages(['name'=>'org_registry_page_id','selected'=>(int)$s['org_registry_page_id'],'show_option_none'=>'— выберите —']);?><p class="description">Страница с шорткодом <code>[zau_union_org_registry]</code> для ответственных организаций.</p></td></tr></table>
        <h2>Регистрация новых участников</h2><table class="form-table">
        <tr><th>Сценарий регистрации</th><td><select name="registration_mode"><option value="direct" <?php selected($s['registration_mode'],'direct');?>>Сразу показывать форму — без подтверждающего кода</option><option value="otp" <?php selected($s['registration_mode'],'otp');?>>Сначала подтверждать email или телефон одноразовым кодом</option></select><p class="description">В прямом режиме аккаунт создаётся после отправки анкеты, пользователь автоматически входит в личный кабинет. Одноразовый код остаётся доступен только для входа и восстановления, если соответствующие способы включены ниже.</p></td></tr>
        <tr><th>После регистрации</th><td><label><input type="checkbox" name="redirect_after_registration" value="1" <?php checked(!empty($s['redirect_after_registration']));?>> После сохранения заявки и формирования документов сразу открыть личный кабинет</label><p class="description">Переход выполняется только для нового пользователя. Повторная отправка документов из кабинета остаётся на текущей странице.</p></td></tr>
        <tr><th>После входа</th><td><label><input type="checkbox" name="redirect_after_login" value="1" <?php checked(!empty($s['redirect_after_login']));?>> После входа по PIN, паролю, одноразовому коду или стандартной форме WordPress всегда открывать личный кабинет</label><p class="description">Администраторы не перенаправляются и могут входить в панель WordPress как обычно.</p></td></tr>
        </table>
        <h2>Способы входа</h2><table class="form-table">
        <tr><th>Разрешённые способы</th><td>
            <label><input type="checkbox" name="auth_password_enabled" value="1" <?php checked($s['auth_password_enabled'],1);?>> Логин/email + пароль WordPress</label><br>
            <label><input type="checkbox" name="auth_pin_enabled" value="1" <?php checked($s['auth_pin_enabled'],1);?>> Постоянный цифровой PIN участника</label><br>
            <label><input type="checkbox" name="auth_otp_email" value="1" <?php checked($s['auth_otp_email'],1);?>> Одноразовый код на email</label><br>
            <label><input type="checkbox" name="auth_otp_phone" value="1" <?php checked($s['auth_otp_phone'],1);?>> Одноразовый код по SMS</label>
            <p class="description">В Elementor каждый виджет входа может дополнительно скрыть отдельные способы, но не может включить способ, запрещённый здесь.</p>
        </td></tr>
        <tr><th>Способ по умолчанию</th><td><select name="auth_default_method"><option value="otp" <?php selected($s['auth_default_method'],'otp');?>>Одноразовый код</option><option value="pin" <?php selected($s['auth_default_method'],'pin');?>>Постоянный PIN</option><option value="password" <?php selected($s['auth_default_method'],'password');?>>Логин и пароль</option></select></td></tr>
        <tr><th>Вкладки способов входа</th><td><label><input type="checkbox" name="auth_show_tabs" value="1" <?php checked($s['auth_show_tabs'],1);?>> Показывать переключатели способов входа</label></td></tr>
        <tr><th>Ссылки восстановления</th><td><label><input type="checkbox" name="auth_show_recovery" value="1" <?php checked($s['auth_show_recovery'],1);?>> Показывать восстановление PIN и пароля</label></td></tr>
        </table>
        <h2>Постоянный PIN участника</h2><table class="form-table">
        <tr><th>Создание PIN при регистрации</th><td><select name="pin_setup_mode"><option value="off" <?php selected($s['pin_setup_mode'],'off');?>>Отключено</option><option value="optional" <?php selected($s['pin_setup_mode'],'optional');?>>Предлагать, но не требовать</option><option value="required" <?php selected($s['pin_setup_mode'],'required');?>>Обязательно для новых участников</option></select><p class="description">PIN не отправляется с сервера при каждом входе. Пользователь придумывает его сам, а система хранит только защищённый хеш.</p></td></tr>
        <tr><th>Длина PIN</th><td>от <input type="number" min="4" max="10" name="pin_min_length" value="<?php echo (int)$s['pin_min_length'];?>" style="width:80px"> до <input type="number" min="4" max="12" name="pin_max_length" value="<?php echo (int)$s['pin_max_length'];?>" style="width:80px"> цифр</td></tr>
        <tr><th>Защита от перебора</th><td><input type="number" min="3" max="15" name="pin_max_attempts" value="<?php echo (int)$s['pin_max_attempts'];?>" style="width:80px"> попыток, затем блокировка на <input type="number" min="1" max="1440" name="pin_lock_minutes" value="<?php echo (int)$s['pin_lock_minutes'];?>" style="width:90px"> минут</td></tr>
        <tr><th>Срок кода восстановления</th><td><input type="number" min="300" max="3600" name="pin_reset_expiry" value="<?php echo (int)$s['pin_reset_expiry'];?>"> секунд</td></tr>
        <tr><th>Вход только по PIN</th><td><label><input type="checkbox" name="pin_device_only" value="1" <?php checked($s['pin_device_only'],1);?>> На уже привязанном устройстве показывать только поле PIN, без email, телефона и логина</label><p class="description">Устройство безопасно привязывается после регистрации, восстановления PIN либо первого входа по одноразовому коду/паролю. На новом устройстве нужно один раз подтвердить аккаунт.</p></td></tr>
        <tr><th>Срок привязки устройства</th><td><input type="number" min="30" max="3650" name="pin_device_days" value="<?php echo (int)$s['pin_device_days'];?>"> дней</td></tr>
        </table>
        <h2>Одноразовый код</h2><table class="form-table"><tr><th>Срок действия, секунд</th><td><input type="number" min="60" max="1800" name="otp_expiry" value="<?php echo (int)$s['otp_expiry'];?>"></td></tr><tr><th>Повторная отправка, секунд</th><td><input type="number" min="30" max="600" name="otp_resend" value="<?php echo (int)$s['otp_resend'];?>"></td></tr><tr><th>Максимум попыток</th><td><input type="number" min="3" max="10" name="otp_max_attempts" value="<?php echo (int)$s['otp_max_attempts'];?>"></td></tr><tr><th>SMS webhook URL</th><td><input type="url" class="large-text" name="sms_webhook_url" value="<?php echo esc_attr($s['sms_webhook_url']);?>"><p class="description">Плагин отправляет POST JSON: <code>{"phone":"+770...","code":"123456","message":"..."}</code>. Без webhook вход по телефону не отправит SMS; вход по email работает через wp_mail.</p></td></tr><tr><th>Bearer-токен SMS</th><td><input type="password" class="large-text" name="sms_webhook_token" value="<?php echo esc_attr($s['sms_webhook_token']);?>"></td></tr></table>
        <h2>Статусы, согласование и личная карточка</h2><table class="form-table">
        <tr><th>Автоматический статус после одобрения</th><td><label><input type="checkbox" name="auto_member_status_on_approval" value="1" <?php checked(!empty($s['auto_member_status_on_approval']));?>> После решения ответственного «Одобрить» автоматически установить «Состоит в профсоюзе»</label></td></tr>
        <tr><th>Редактирование личной карточки участником</th><td><label><input type="checkbox" name="member_card_member_edit" value="1" <?php checked(!empty($s['member_card_member_edit']));?>> Участник может дополнять свою карточку; изменения получают статус «Ожидает проверки»</label><p class="description">Полные чувствительные значения не вставляются в HTML страницы. Для изменения пользователь вводит новое значение, а старое раскрывается только по защищённой кнопке.</p></td></tr>
        <tr><th>Вкладка «Личная карточка»</th><td><label><input type="checkbox" name="member_card_tab_enabled" value="1" <?php checked(!empty($s['member_card_tab_enabled']));?>> Разрешить встроенную вкладку личной карточки</label><p class="description">В Elementor вкладку можно дополнительно скрыть для конкретной страницы или виджета.</p></td></tr>
        <tr><th>Скрыть панель WordPress</th><td><label><input type="checkbox" name="hide_admin_for_non_admin" value="1" <?php checked(!empty($s['hide_admin_for_non_admin']));?>> Скрывать верхнюю админ-панель и закрывать <code>/wp-admin/</code> всем, кроме администраторов</label><p class="description">AJAX, загрузка файлов и служебные действия плагина продолжают работать.</p></td></tr>
        <tr><th>Чувствительные поля карточки</th><td><input type="text" class="large-text" name="member_card_sensitive_fields" value="<?php echo esc_attr($s['member_card_sensitive_fields']);?>"><p class="description">Ключи через запятую. По умолчанию: <code>iin,birth_date,address,emergency_contact</code>. Полное значение доступно только владельцу карточки и администратору — после нажатия «Показать» или «Копировать».</p></td></tr>
        <tr><th>Автоматически скрыть через</th><td><input type="number" min="10" max="300" name="sensitive_reveal_seconds" value="<?php echo (int)$s['sensitive_reveal_seconds'];?>"> секунд</td></tr>
        <tr><th>Вкладка акций и скидок</th><td><label><input type="checkbox" name="benefits_tab_enabled" value="1" <?php checked(!empty($s['benefits_tab_enabled']));?>> Показывать встроенную вкладку «Акции и скидки»</label><p class="description">Предложения добавляются через «Профсоюз → Акции и скидки».</p></td></tr>
        </table>
        <h2>AQNIET — дизайн и мобильная эргономика</h2>
        <table class="form-table">
            <tr><th>Основной цвет</th><td><input type="color" name="design_primary" value="<?php echo esc_attr($s['design_primary']?:'#1565C0');?>"> <code><?php echo esc_html($s['design_primary']?:'#1565C0');?></code><p class="description">Базовый цвет готового интерфейса. В Elementor его можно переопределить отдельно для каждого виджета.</p></td></tr>
            <tr><th>Пошаговая мобильная форма</th><td><label><input type="checkbox" name="design_mobile_steps" value="1" <?php checked(!empty($s['design_mobile_steps']));?>> Разбивать регистрацию на понятные шаги</label></td></tr>
            <tr><th>Нижняя навигация</th><td><label><input type="checkbox" name="design_mobile_bottom_nav" value="1" <?php checked(!empty($s['design_mobile_bottom_nav']));?>> Закреплять вкладки кабинета внизу телефона</label></td></tr>
            <tr><th>Быстрые действия</th><td><label><input type="checkbox" name="design_quick_actions" value="1" <?php checked(!empty($s['design_quick_actions']));?>> Показывать карточки быстрых действий на главной</label></td></tr>
            <tr><th>Поиск документов</th><td><label><input type="checkbox" name="design_document_search" value="1" <?php checked(!empty($s['design_document_search']));?>> Показывать поиск и фильтр во вкладке «Документы»</label></td></tr>
        </table>
        <h2>DaData — компании Казахстана</h2><p>Встроенная интеграция обращается к DaData с сервера WordPress, поэтому API-ключ не передаётся в браузер посетителя. Поиск работает по полному или частичному БИН; при выборе организации данные автоматически вставляются в поля формы.</p><table class="form-table">
        <tr><th>Использовать DaData</th><td><label><input type="checkbox" name="dadata_enabled" value="1" <?php checked($s['dadata_enabled'],1);?>> Включить поиск организаций Казахстана</label></td></tr>
        <tr><th>API-ключ DaData</th><td><input type="password" class="large-text" name="dadata_token" value="<?php echo esc_attr($s['dadata_token']);?>" autocomplete="new-password"><p class="description">Нужен API-ключ аккаунта DaData. Используется метод <code>party_kz</code>. Ключ хранится в настройках WordPress и отправляется только сервером.</p></td></tr>
        <tr><th>Подсказки при вводе</th><td><label><input type="checkbox" name="dadata_autocomplete" value="1" <?php checked($s['dadata_autocomplete'],1);?>> Показывать список найденных организаций во время ввода БИН</label></td></tr>
        <tr><th>Начинать поиск после</th><td><input type="number" min="3" max="12" name="dadata_min_chars" value="<?php echo (int)$s['dadata_min_chars'];?>"> символов</td></tr>
        <tr><th>Язык данных</th><td><select name="dadata_language"><option value="ru" <?php selected($s['dadata_language'],'ru');?>>Русский, при отсутствии — казахский</option><option value="kz" <?php selected($s['dadata_language'],'kz');?>>Казахский, при отсутствии — русский</option></select></td></tr>
        <tr><th>Кэширование</th><td><input type="number" min="1" max="168" name="dadata_cache_hours" value="<?php echo (int)$s['dadata_cache_hours'];?>"> часов<p class="description">Уменьшает количество запросов к API. Найденная организация также сохраняется во внутренний справочник.</p></td></tr></table>
        <details class="zau-union-card" style="padding:14px;margin:18px 0"><summary><strong>Резервный универсальный API поиска БИН</strong></summary><p>Используется, если DaData отключена или не вернула результат. Укажите URL с заменителем <code>{bin}</code> и пути к значениям в JSON.</p><table class="form-table"><tr><th>URL API</th><td><input type="text" class="large-text" name="bin_api_url" value="<?php echo esc_attr($s['bin_api_url']);?>" placeholder="https://api.example.kz/company/{bin}"></td></tr><tr><th>HTTP-заголовки JSON</th><td><textarea class="large-text code" rows="4" name="bin_api_headers"><?php echo esc_textarea($s['bin_api_headers']);?></textarea></td></tr><tr><th>Путь к объекту данных</th><td><input class="regular-text" name="bin_api_data_path" value="<?php echo esc_attr($s['bin_api_data_path']);?>" placeholder="data.company"></td></tr><tr><th>Путь к названию</th><td><input class="regular-text" name="bin_api_name_path" value="<?php echo esc_attr($s['bin_api_name_path']);?>"></td></tr><tr><th>Путь к руководителю</th><td><input class="regular-text" name="bin_api_director_path" value="<?php echo esc_attr($s['bin_api_director_path']);?>"></td></tr><tr><th>Путь к адресу</th><td><input class="regular-text" name="bin_api_address_path" value="<?php echo esc_attr($s['bin_api_address_path']);?>"></td></tr><tr><th>Путь к региону</th><td><input class="regular-text" name="bin_api_region_path" value="<?php echo esc_attr($s['bin_api_region_path']);?>"></td></tr></table></details>
        <h2>Видимость вкладок кабинета по ролям</h2>
        <?php [$tabOptions,$roleOptions]=$this->tab_visibility_matrix_options(); $hiddenMap=$this->tab_role_hidden_map(); ?>
        <p class="description">По умолчанию вкладка видна всем. Снимите галочку, чтобы скрыть вкладку кабинета для конкретной роли. Администраторы всегда видят все вкладки, независимо от этих настроек.</p>
        <div class="zau-tab-visibility-scroll" style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Вкладка</th><?php foreach($roleOptions as $roleSlug=>$roleLabel):?><th><?php echo esc_html($roleLabel);?></th><?php endforeach;?></tr></thead><tbody>
        <?php foreach($tabOptions as $tabSlug=>$tabLabel):?><tr><td><strong><?php echo esc_html($tabLabel);?></strong></td><?php foreach($roleOptions as $roleSlug=>$roleLabel):$isHidden=!empty($hiddenMap[$tabSlug][$roleSlug]);?><td><label><input type="checkbox" name="tab_visible[<?php echo esc_attr($tabSlug);?>][<?php echo esc_attr($roleSlug);?>]" value="1" <?php checked(!$isHidden);?>></label></td><?php endforeach;?></tr><?php endforeach;?>
        </tbody></table></div>
        <?php submit_button('Сохранить настройки');?></form></div>
        <?php
    }

    public function save_settings() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE); check_admin_referer(self::NONCE);
        $s=$this->settings();
        foreach(['default_form_id','login_page_id','form_page_id','cabinet_page_id','org_registry_page_id','otp_expiry','otp_resend','otp_max_attempts'] as $k)$s[$k]=absint($_POST[$k]??0);
        foreach(['auth_otp_email','auth_otp_phone','auth_pin_enabled','auth_password_enabled','auth_show_tabs','auth_show_recovery','pin_device_only','design_mobile_steps','design_mobile_bottom_nav','design_quick_actions','design_document_search','auto_member_status_on_approval','member_card_member_edit','member_card_tab_enabled','hide_admin_for_non_admin','benefits_tab_enabled','redirect_after_registration','redirect_after_login'] as $k)$s[$k]=empty($_POST[$k])?0:1;
        $primary=sanitize_hex_color(wp_unslash($_POST['design_primary']??''));
        $s['design_primary']=$primary?:'#1565C0';
        $allowedSensitive=[];
        foreach(explode(',',(string)wp_unslash($_POST['member_card_sensitive_fields']??'')) as $sensitiveKey){$sensitiveKey=sanitize_key(trim($sensitiveKey));if($sensitiveKey)$allowedSensitive[]=$sensitiveKey;}
        $s['member_card_sensitive_fields']=implode(',',array_values(array_unique($allowedSensitive)));
        $s['sensitive_reveal_seconds']=max(10,min(300,absint($_POST['sensitive_reveal_seconds']??30)));
        $s['registration_mode']=in_array(($_POST['registration_mode']??'direct'),['direct','otp'],true)?sanitize_key($_POST['registration_mode']):'direct';
        $s['auth_default_method']=in_array(($_POST['auth_default_method']??'otp'),['otp','pin','password'],true)?sanitize_key($_POST['auth_default_method']):'otp';
        $s['pin_setup_mode']=in_array(($_POST['pin_setup_mode']??'optional'),['off','optional','required'],true)?sanitize_key($_POST['pin_setup_mode']):'optional';
        $s['pin_min_length']=max(4,min(10,absint($_POST['pin_min_length']??4)));
        $s['pin_max_length']=max($s['pin_min_length'],min(12,absint($_POST['pin_max_length']??8)));
        $s['pin_max_attempts']=max(3,min(15,absint($_POST['pin_max_attempts']??5)));
        $s['pin_lock_minutes']=max(1,min(1440,absint($_POST['pin_lock_minutes']??15)));
        $s['pin_reset_expiry']=max(300,min(3600,absint($_POST['pin_reset_expiry']??600)));
        $s['pin_device_days']=max(30,min(3650,absint($_POST['pin_device_days']??365)));
        $s['sms_webhook_url']=esc_url_raw(wp_unslash($_POST['sms_webhook_url']??''));
        $s['sms_webhook_token']=sanitize_text_field(wp_unslash($_POST['sms_webhook_token']??''));
        $s['dadata_enabled']=empty($_POST['dadata_enabled'])?0:1;
        $s['dadata_token']=sanitize_text_field(wp_unslash($_POST['dadata_token']??''));
        $s['dadata_autocomplete']=empty($_POST['dadata_autocomplete'])?0:1;
        $s['dadata_language']=in_array(($_POST['dadata_language']??'ru'),['ru','kz'],true)?$_POST['dadata_language']:'ru';
        $s['dadata_cache_hours']=max(1,min(168,absint($_POST['dadata_cache_hours']??24)));
        $s['dadata_min_chars']=max(3,min(12,absint($_POST['dadata_min_chars']??3)));
        $s['bin_api_url']=sanitize_text_field(wp_unslash($_POST['bin_api_url']??''));
        $headers=wp_unslash($_POST['bin_api_headers']??'{}'); json_decode($headers,true); $s['bin_api_headers']=json_last_error()===JSON_ERROR_NONE?$headers:'{}';
        foreach(['bin_api_data_path','bin_api_name_path','bin_api_director_path','bin_api_address_path','bin_api_region_path'] as $k)$s[$k]=sanitize_text_field(wp_unslash($_POST[$k]??''));
        [$tabOptions,$roleOptions]=$this->tab_visibility_matrix_options();
        $submittedVisible=(array)($_POST['tab_visible']??[]);
        $hiddenMap=[];
        foreach($tabOptions as $tabSlug=>$tabLabel){
            foreach($roleOptions as $roleSlug=>$roleLabel){
                if(empty($submittedVisible[$tabSlug][$roleSlug]))$hiddenMap[$tabSlug][$roleSlug]=1;
            }
        }
        $s['tab_role_hidden']=wp_json_encode($hiddenMap,JSON_UNESCAPED_UNICODE);
        update_option(self::OPT_SETTINGS,$s,false); $this->ensure_pages();
        wp_safe_redirect(admin_url('admin.php?page=zau-union-settings&zau_notice='.rawurlencode('Настройки сохранены.'))); exit;
    }

    private function shortcode_switch($value, $fallback = true) {
        $value = strtolower(trim((string)$value));
        if ($value === '' || $value === 'inherit') { return (bool)$fallback; }
        return in_array($value, ['1','yes','on','true'], true);
    }

    private function auth_config($atts = []) {
        $s = $this->settings();
        $global = [];
        if (!empty($s['auth_otp_email']) || !empty($s['auth_otp_phone'])) { $global[] = 'otp'; }
        if (!empty($s['auth_pin_enabled'])) { $global[] = 'pin'; }
        if (!empty($s['auth_password_enabled'])) { $global[] = 'password'; }
        $requested = strtolower(trim((string)($atts['methods'] ?? 'inherit')));
        if ($requested && $requested !== 'inherit') {
            $wanted = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', $requested)))));
            $methods = array_values(array_intersect($global, $wanted));
        } else { $methods = $global; }
        $channels = [];
        if (!empty($s['auth_otp_email'])) { $channels[] = 'email'; }
        if (!empty($s['auth_otp_phone'])) { $channels[] = 'phone'; }
        $requestedChannels = strtolower(trim((string)($atts['otp_channels'] ?? 'inherit')));
        if ($requestedChannels && $requestedChannels !== 'inherit') {
            $wantedChannels = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', $requestedChannels)))));
            $channels = array_values(array_intersect($channels, $wantedChannels));
        }
        if (!$channels) { $methods = array_values(array_diff($methods, ['otp'])); }
        $default = sanitize_key((string)($atts['default_method'] ?? ''));
        if (!$default || !in_array($default, $methods, true)) { $default = sanitize_key((string)$s['auth_default_method']); }
        if (!$default || !in_array($default, $methods, true)) { $default = $methods ? reset($methods) : ''; }
        $flow = sanitize_key((string)($atts['flow'] ?? 'combined'));
        if (!in_array($flow, ['login','register','combined'], true)) { $flow = 'combined'; }
        $showFlowTabs = $this->shortcode_switch($atts['show_flow_tabs'] ?? 'yes', true);
        $startScreen = $this->shortcode_switch($atts['start_screen'] ?? 'yes', true);
        $defaultFlow = sanitize_key((string)($atts['default_flow'] ?? ''));
        if (!in_array($defaultFlow, ['login','register'], true)) { $defaultFlow = $flow === 'login' ? 'login' : 'register'; }
        if ($flow === 'login') { $defaultFlow = 'login'; $startScreen = false; }
        if ($flow === 'register') { $defaultFlow = 'register'; $startScreen = false; }
        if ($flow === 'combined' && !$showFlowTabs) { $startScreen = false; }
        return [
            'methods'=>$methods,
            'otp_channels'=>$channels,
            'default'=>$default,
            'flow'=>$flow,
            'default_flow'=>$defaultFlow,
            'show_flow_tabs'=>$showFlowTabs,
            'start_screen'=>$startScreen,
            'show_tabs'=>$this->shortcode_switch($atts['show_tabs'] ?? 'inherit', !empty($s['auth_show_tabs'])),
            'show_recovery'=>!empty($s['auth_show_recovery']) && $this->shortcode_switch($atts['show_recovery'] ?? 'inherit', true),
            'show_heading'=>$this->shortcode_switch($atts['show_heading'] ?? 'yes', true),
            'show_description'=>$this->shortcode_switch($atts['show_description'] ?? 'yes', true),
            'show_remember'=>$this->shortcode_switch($atts['show_remember'] ?? 'yes', true),
            'pin_device_only'=>!empty($s['pin_device_only']) && $this->shortcode_switch($atts['pin_device_only'] ?? 'inherit', true),
        ];
    }

    public function auth_shortcode($atts=[]) {
        $atts=shortcode_atts([
            'id'=>0,'slug'=>'','pin_setup'=>'inherit','mobile_steps'=>'off',
            'return_url'=>'','login_return_url'=>'','register_return_url'=>'','methods'=>'inherit','otp_channels'=>'inherit','default_method'=>'',
            'flow'=>'combined','default_flow'=>'','show_flow_tabs'=>'yes','start_screen'=>'yes',
            'show_tabs'=>'inherit','show_recovery'=>'inherit','show_heading'=>'yes','show_description'=>'yes','show_remember'=>'yes','pin_device_only'=>'inherit'
        ],$atts,'zau_union_auth');
        $returnUrl=esc_url_raw($atts['return_url']);
        $loginReturnUrl=esc_url_raw($atts['login_return_url']);
        $registerReturnUrl=esc_url_raw($atts['register_return_url']);
        if(!$loginReturnUrl)$loginReturnUrl=$returnUrl?:$this->front_login_redirect('');
        if(!$registerReturnUrl)$registerReturnUrl=$returnUrl;
        if (is_user_logged_in()) {
            $s=$this->settings(); $url=$s['cabinet_page_id']?get_permalink((int)$s['cabinet_page_id']):home_url('/lk-profsoyuz/');
            return '<div class="zau-union-panel"><h2>Вы уже вошли</h2><p><a class="zau-union-button" href="'.esc_url($url).'">Открыть личный кабинет</a></p></div>';
        }
        $cfg=$this->auth_config($atts);
        if (!$cfg['methods'] && $cfg['flow'] !== 'register') { return '<div class="zau-union-panel zau-auth-disabled"><strong>Способы входа отключены.</strong><p>Обратитесь к администратору сайта.</p></div>'; }
        $registrationMode=sanitize_key((string)$this->settings()['registration_mode']);
        if($registrationMode!=='otp')$registrationMode='direct';
        if ($registrationMode==='otp' && !in_array('otp',$cfg['methods'],true) && in_array($cfg['flow'],['register','combined'],true)) {
            return '<div class="zau-union-panel zau-auth-disabled"><strong>Регистрация временно недоступна.</strong><p>Для регистрации с подтверждением должен быть включён одноразовый код на email или телефон.</p></div>';
        }
        $methodLabels=['otp'=>'Код из email или SMS','pin'=>'Постоянный PIN','password'=>'Логин и пароль'];
        $otpLabel=count($cfg['otp_channels'])===1?($cfg['otp_channels'][0]==='email'?'Email':'Телефон'):'Email или телефон';
        $otpPlaceholder=count($cfg['otp_channels'])===1?($cfg['otp_channels'][0]==='email'?'name@example.kz':'+7 700 000 00 00'):'name@example.kz или +7 700 000 00 00';
        $activeFlow=$cfg['flow']==='combined'&&$cfg['start_screen']?'choice':($cfg['flow']==='combined'?$cfg['default_flow']:$cfg['flow']);
        if(!in_array($activeFlow,['choice','register','login'],true))$activeFlow=$cfg['flow']==='login'?'login':'register';
        if(isset($_GET['zau_auth_flow'])){
            $requestedFlow=sanitize_key(wp_unslash($_GET['zau_auth_flow']));
            if($cfg['flow']==='combined' && in_array($requestedFlow,['register','login'],true))$activeFlow=$requestedFlow;
        }
        $activeMethod=$cfg['default'];
        if(!in_array($activeMethod,$cfg['methods'],true))$activeMethod=$cfg['methods'][0]??'otp';
        ob_start(); ?>
        <div class="zau-union-panel zau-auth<?php echo $activeFlow==='choice'?' is-choosing':' is-flow-selected';?>" data-zau-auth data-return-url="<?php echo esc_attr($returnUrl);?>" data-login-return-url="<?php echo esc_attr($loginReturnUrl);?>" data-register-return-url="<?php echo esc_attr($registerReturnUrl);?>" data-default-method="<?php echo esc_attr($cfg['default']);?>" data-methods="<?php echo esc_attr(implode(',',$cfg['methods']));?>" data-otp-channels="<?php echo esc_attr(implode(',',$cfg['otp_channels']));?>" data-auth-flow="<?php echo esc_attr($cfg['flow']);?>" data-default-flow="<?php echo esc_attr($activeFlow);?>" data-start-screen="<?php echo $cfg['start_screen']?'1':'0';?>" data-pin-device-only="<?php echo $cfg['pin_device_only']?'1':'0';?>">
            <?php if($cfg['show_heading']):?><h2><?php echo $cfg['flow']==='register'?'Регистрация в профсоюз':($cfg['flow']==='combined'?'Личный кабинет профсоюза':'Вход в личный кабинет');?></h2><?php endif;?>
            <?php if($cfg['show_description']):?><p class="zau-auth-main-description"><?php echo $cfg['flow']==='login'?'Выберите способ входа для уже созданного аккаунта.':($cfg['flow']==='combined'?'Сначала выберите: создать новый кабинет или войти в существующий.':($registrationMode==='direct'?'Заполните регистрационную анкету. Подтверждающий код не требуется.':'Сначала подтвердите email или телефон, затем заполните заявление.'));?></p><?php endif;?>

            <?php if($cfg['flow']==='combined' && $cfg['show_flow_tabs']):?>
                <div class="zau-auth-choice-intro" data-zau-auth-choice-intro><strong>Что вы хотите сделать?</strong><span>После выбора откроется только нужный раздел.</span></div>
                <div class="zau-auth-flow-tabs" role="tablist" aria-label="Регистрация или вход">
                    <button type="button" class="zau-auth-flow-tab<?php echo $activeFlow==='register'?' is-active':'';?>" data-zau-auth-flow-tab="register" role="tab" aria-selected="<?php echo $activeFlow==='register'?'true':'false';?>"><span class="zau-auth-flow-number">+</span><span><strong>Зарегистрироваться</strong><small>Создать кабинет и заполнить заявление</small></span></button>
                    <button type="button" class="zau-auth-flow-tab<?php echo $activeFlow==='login'?' is-active':'';?>" data-zau-auth-flow-tab="login" role="tab" aria-selected="<?php echo $activeFlow==='login'?'true':'false';?>"><span class="zau-auth-flow-icon">↪</span><span><strong>Войти</strong><small>Открыть существующий личный кабинет</small></span></button>
                </div>
            <?php endif;?>

            <?php if(in_array($cfg['flow'],['register','combined'],true)):?>
            <section class="zau-auth-flow-panel zau-register-flow<?php echo $activeFlow==='register'?' is-active':'';?>" data-zau-auth-flow-panel="register"<?php echo $activeFlow==='register'?'':' hidden';?>>
                <?php if($cfg['flow']==='combined'):?><button type="button" class="zau-auth-back" data-zau-back-to-auth-choice>← Вернуться к выбору</button><?php endif;?>
                <?php if($registrationMode==='direct'):
                    echo $this->form_shortcode([
                        'id'=>absint($atts['id']??0),
                        'slug'=>sanitize_title((string)($atts['slug']??'')),
                        'pin_setup'=>$atts['pin_setup']??'inherit',
                        'mobile_steps'=>'off',
                        'embedded_auth'=>'yes',
                    ]);
                else:?>
                    <div class="zau-auth-steps" aria-label="Этапы регистрации"><span class="is-active">1. Подтвердить контакт</span><span>2. Заполнить заявление</span><span>3. Получить документы</span></div>
                    <h3>Создание личного кабинета</h3>
                    <p>Введите действующий email или телефон. Мы отправим шестизначный код. После подтверждения откроется анкета регистрации.</p>
                    <div class="zau-otp-step" data-register-step="destination">
                        <label><?php echo esc_html($otpLabel);?><input type="text" data-zau-register-destination autocomplete="username" placeholder="<?php echo esc_attr($otpPlaceholder);?>"></label>
                        <button type="button" class="zau-union-button zau-auth-submit" data-zau-register-send-code><span>Получить код регистрации</span></button>
                    </div>
                    <div class="zau-otp-step" data-register-step="code" hidden>
                        <div class="zau-auth-sent-note">Код отправлен на <strong data-zau-register-destination-label></strong>.</div>
                        <label>Шестизначный код<input type="text" inputmode="numeric" maxlength="6" data-zau-register-code autocomplete="one-time-code" placeholder="000000"></label>
                        <button type="button" class="zau-union-button zau-auth-submit" data-zau-register-verify-code><span>Подтвердить и открыть анкету</span></button>
                        <button type="button" class="zau-link-button" data-zau-register-change-destination>Изменить email или телефон</button>
                    </div>
                <?php endif;?>
            </section>
            <?php endif;?>

            <?php if(in_array($cfg['flow'],['login','combined'],true)):?>
            <section class="zau-auth-flow-panel zau-login-flow<?php echo $activeFlow==='login'?' is-active':'';?>" data-zau-auth-flow-panel="login"<?php echo $activeFlow==='login'?'':' hidden';?>>
                <?php if($cfg['flow']==='combined'):?><button type="button" class="zau-auth-back" data-zau-back-to-auth-choice>← Вернуться к выбору</button><?php endif;?>
                <h3>Вход для зарегистрированных участников</h3>
                <p class="zau-auth-login-help">Постоянный PIN работает только после того, как он был создан в анкете или восстановлен по email.</p>
                <?php if($cfg['show_tabs'] && count($cfg['methods'])>1):?><div class="zau-auth-tabs" role="tablist"><?php foreach($cfg['methods'] as $method):?><button type="button" class="zau-auth-tab<?php echo $activeMethod===$method?' is-active':'';?>" data-zau-auth-tab="<?php echo esc_attr($method);?>" role="tab" aria-selected="<?php echo $activeMethod===$method?'true':'false';?>"><?php echo esc_html($methodLabels[$method]);?></button><?php endforeach;?></div><?php endif;?>

                <?php if(in_array('otp',$cfg['methods'],true)):?><section class="zau-auth-method<?php echo $activeMethod==='otp'?' is-active':'';?>" data-zau-auth-panel="otp"<?php echo $activeMethod==='otp'?'':' hidden';?>>
                    <p class="zau-auth-method-note">Получите новый одноразовый код. Этот способ подходит и тем, кто ещё не создавал постоянный PIN.</p>
                    <div class="zau-otp-step" data-step="destination"><label><?php echo esc_html($otpLabel);?><input type="text" data-zau-destination autocomplete="username" placeholder="<?php echo esc_attr($otpPlaceholder);?>"></label><button type="button" class="zau-union-button zau-auth-submit" data-zau-send-code><span>Получить код для входа</span></button></div>
                    <div class="zau-otp-step" data-step="code" hidden><label>Код из сообщения<input type="text" inputmode="numeric" maxlength="6" data-zau-code autocomplete="one-time-code" placeholder="000000"></label><button type="button" class="zau-union-button zau-auth-submit" data-zau-verify-code><span>Войти в кабинет</span></button><button type="button" class="zau-link-button" data-zau-change-destination>Изменить <?php echo esc_html(function_exists('mb_strtolower')?mb_strtolower($otpLabel,'UTF-8'):strtolower($otpLabel));?></button></div>
                </section><?php endif;?>

                <?php if(in_array('pin',$cfg['methods'],true)):?><section class="zau-auth-method<?php echo $activeMethod==='pin'?' is-active':'';?>" data-zau-auth-panel="pin"<?php echo $activeMethod==='pin'?'':' hidden';?>>
                    <?php if($cfg['pin_device_only']):?>
                        <p class="zau-auth-method-note">Введите только постоянный PIN. Email, телефон и логин не требуются.</p>
                        <div data-zau-pin-login><label>Постоянный PIN<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" data-zau-pin autocomplete="current-password" placeholder="Введите PIN"></label><div class="zau-auth-actions"><button type="button" class="zau-union-button zau-auth-submit" data-zau-pin-login-button><span>Войти</span></button><?php if($cfg['show_recovery']):?><button type="button" class="zau-link-button" data-zau-open-pin-reset>Не помню PIN</button><?php endif;?></div></div>
                        <?php if(in_array('otp',$cfg['methods'],true) || in_array('password',$cfg['methods'],true)):?><div class="zau-pin-device-help"><small>Первый вход на новом устройстве:</small><div class="zau-auth-actions"><?php if(in_array('otp',$cfg['methods'],true)):?><button type="button" class="zau-link-button" data-zau-switch-method="otp">Войти одноразовым кодом</button><?php endif;?><?php if(in_array('password',$cfg['methods'],true)):?><button type="button" class="zau-link-button" data-zau-switch-method="password">Войти с паролем</button><?php endif;?></div></div><?php endif;?>
                    <?php else:?>
                        <p class="zau-auth-method-note">Введите email, телефон или логин и PIN, который вы придумали при регистрации.</p>
                        <div data-zau-pin-login><label>Email, телефон или логин<input type="text" data-zau-pin-identifier autocomplete="username" placeholder="Email, телефон или логин"></label><label>Постоянный PIN<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" data-zau-pin autocomplete="current-password" placeholder="Ваш цифровой PIN"></label><div class="zau-auth-actions"><button type="button" class="zau-union-button zau-auth-submit" data-zau-pin-login-button><span>Войти по PIN</span></button><?php if($cfg['show_recovery']):?><button type="button" class="zau-link-button" data-zau-open-pin-reset>Не помню PIN</button><?php endif;?></div></div>
                    <?php endif;?>
                    <?php if($cfg['show_recovery']):?><div class="zau-pin-reset" data-zau-pin-reset hidden><h4>Создать новый PIN</h4><p>Укажите email аккаунта. Мы отправим одноразовый код для создания нового PIN.</p><div data-zau-pin-reset-request><label>Email<input type="email" data-zau-pin-reset-email autocomplete="email"></label><button type="button" class="zau-union-button zau-auth-submit" data-zau-request-pin-reset><span>Отправить код восстановления</span></button></div><div data-zau-pin-reset-confirm hidden><label>Код из письма<input type="text" inputmode="numeric" maxlength="6" data-zau-pin-reset-code autocomplete="one-time-code"></label><label>Новый PIN<input type="password" inputmode="numeric" maxlength="12" data-zau-new-pin autocomplete="new-password"></label><label>Повторите PIN<input type="password" inputmode="numeric" maxlength="12" data-zau-new-pin-confirm autocomplete="new-password"></label><button type="button" class="zau-union-button zau-auth-submit" data-zau-reset-pin><span>Сохранить новый PIN и войти</span></button></div><button type="button" class="zau-link-button" data-zau-close-pin-reset>Вернуться ко входу</button></div><?php endif;?>
                </section><?php endif;?>

                <?php if(in_array('password',$cfg['methods'],true)):?><section class="zau-auth-method<?php echo $activeMethod==='password'?' is-active':'';?>" data-zau-auth-panel="password"<?php echo $activeMethod==='password'?'':' hidden';?>>
                    <p class="zau-auth-method-note">Используйте пароль WordPress, если он у вас установлен.</p>
                    <label>Логин или email<input type="text" data-zau-password-identifier autocomplete="username"></label><label>Пароль<input type="password" data-zau-password autocomplete="current-password"></label><?php if($cfg['show_remember']):?><label class="zau-checkbox zau-auth-remember"><input type="checkbox" data-zau-password-remember value="1" checked> <span>Запомнить меня</span></label><?php endif;?><button type="button" class="zau-union-button zau-auth-submit" data-zau-password-login><span>Войти с паролем</span></button><?php if($cfg['show_recovery']):?><a class="zau-link-button zau-password-reset-link" href="<?php echo esc_url(wp_lostpassword_url($returnUrl));?>">Восстановить пароль по email</a><?php endif;?>
                </section><?php endif;?>
            </section>
            <?php endif;?>
            <div class="zau-message" data-zau-auth-message aria-live="polite"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function portal_shortcode($atts=[]) {
        $atts = is_array($atts) ? $atts : [];
        if (is_user_logged_in()) { return $this->form_shortcode($atts); }
        $atts['flow'] = 'combined';
        $atts['start_screen'] = $atts['start_screen'] ?? 'yes';
        if (empty($atts['register_return_url']) && is_singular()) { $atts['register_return_url'] = get_permalink(); }
        if (empty($atts['login_return_url'])) { $atts['login_return_url'] = $this->front_login_redirect(''); }
        return $this->auth_shortcode($atts);
    }

    public function form_shortcode($atts=[]) {
        $atts=shortcode_atts([
            'id'=>0,'slug'=>'','pin_setup'=>'inherit','mobile_steps'=>'inherit','embedded_auth'=>'no',
            'return_url'=>'','login_return_url'=>'','register_return_url'=>'','methods'=>'inherit','otp_channels'=>'inherit','default_method'=>'',
            'flow'=>'combined','default_flow'=>'','show_flow_tabs'=>'yes','start_screen'=>'yes',
            'show_tabs'=>'inherit','show_recovery'=>'inherit','show_heading'=>'yes','show_description'=>'yes','show_remember'=>'yes','pin_device_only'=>'inherit'
        ],$atts,'zau_union_form');
        $form=$this->get_form($atts['id']?:$atts['slug']);
        if(!$form || !$form->active)return '<div class="zau-union-panel">Форма не найдена или отключена.</div>';
        $settings=$this->settings();
        $directGuest=!is_user_logged_in() && sanitize_key((string)$settings['registration_mode'])==='direct';
        $embeddedAuth=$this->shortcode_switch($atts['embedded_auth']??'no', false);
        if($directGuest && !$embeddedAuth){
            $authAtts=$atts;
            $authAtts['id']=(int)$form->id;
            $authAtts['flow']='combined';
            $authAtts['start_screen']='yes';
            $authAtts['mobile_steps']='off';
            return $this->auth_shortcode($authAtts);
        }
        // Страница прямой регистрации содержит гостевой nonce и не должна отдаваться из длительного HTML-кэша.
        if($directGuest){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}
        if($form->require_auth && !is_user_logged_in() && !$directGuest){
            $atts['flow']='combined';
            if(empty($atts['default_flow']))$atts['default_flow']='register';
            if(empty($atts['register_return_url']) && is_singular())$atts['register_return_url']=get_permalink();
            if(empty($atts['login_return_url']))$atts['login_return_url']=$this->front_login_redirect('');
            return $this->auth_shortcode($atts).'<div class="zau-union-note"><strong>Как пройти регистрацию:</strong> выберите «Впервые здесь», подтвердите email или телефон кодом. После подтверждения эта страница откроет анкету автоматически.</div>';
        }
        $fields=$this->decode_form_fields($form->fields_json); $user=wp_get_current_user();
        $pinMode=sanitize_key((string)$atts['pin_setup']);
        if(!in_array($pinMode,['off','optional','required'],true))$pinMode=in_array($settings['pin_setup_mode'],['off','optional','required'],true)?$settings['pin_setup_mode']:'optional';
        $showPinSetup=$pinMode!=='off' && ($directGuest || (is_user_logged_in() && !get_user_meta(get_current_user_id(),'zau_login_pin_hash',true)));
        $mobileSteps=$this->shortcode_switch($atts['mobile_steps']??'inherit',!empty($settings['design_mobile_steps']));
        if($directGuest){$mobileSteps=false;} // Прямая регистрация всегда одной цельной формой.
        ob_start(); ?>
        <form class="zau-union-form zau-aqniet-form<?php echo $mobileSteps?' zau-aqniet-step-form':'';?><?php echo $directGuest?' zau-single-page-registration':'';?>" data-zau-union-form data-form-id="<?php echo (int)$form->id;?>" data-zau-aqniet-steps="<?php echo $mobileSteps?'1':'0';?>"><div class="zau-union-form-head"><span class="zau-aqniet-kicker">AQNIET · Регистрация</span><h2><?php echo esc_html($form->name);?></h2><?php if($form->description):?><p><?php echo esc_html($form->description);?></p><?php endif;?><?php if($directGuest):?><div class="zau-direct-registration-note"><strong>Подтверждающий код не требуется.</strong> Заполните анкету — личный кабинет будет создан автоматически. <?php if($embeddedAuth):?><button type="button" class="zau-link-button" data-zau-open-auth-flow="login">Уже зарегистрированы? Войти</button><?php else:$loginPage=absint($settings['login_page_id']??0);$loginUrl=$loginPage?get_permalink($loginPage):wp_login_url();?><a href="<?php echo esc_url($loginUrl);?>">Уже зарегистрированы? Войти</a><?php endif;?></div><?php endif;?></div><input type="hidden" name="form_id" value="<?php echo (int)$form->id;?>"><?php if($directGuest):?><input type="hidden" name="zau_direct_registration" value="1"><input type="hidden" name="zau_started_at" value="<?php echo esc_attr(time());?>"><input type="hidden" name="zau_human_form" value="0" data-zau-human-form><div class="zau-registration-trap" aria-hidden="true"><label>Оставьте поле пустым<input type="search" name="zau_company_website" value="" tabindex="-1" autocomplete="new-password" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true"></label></div><?php endif;?>
        <?php foreach($fields as $field): $this->render_public_field($field,$user); endforeach; ?>
        <?php if($showPinSetup):?><div class="zau-registration-security" data-zau-registration-security><h3>Код входа в личный кабинет</h3><p>Придумайте постоянный цифровой PIN. Его не нужно получать с сервера при каждом входе; восстановить PIN можно будет через email.</p><input type="hidden" name="zau_pin_setup_mode" value="<?php echo esc_attr($pinMode);?>"><div class="zau-security-grid"><label>Новый PIN<?php if($pinMode==='required'):?> <span class="zau-required-mark">*</span><?php endif;?><input type="password" name="zau_login_pin" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="new-password" placeholder="От <?php echo (int)$settings['pin_min_length'];?> до <?php echo (int)$settings['pin_max_length'];?> цифр"<?php echo $pinMode==='required'?' required':'';?>></label><label>Повторите PIN<?php if($pinMode==='required'):?> <span class="zau-required-mark">*</span><?php endif;?><input type="password" name="zau_login_pin_confirm" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="new-password"<?php echo $pinMode==='required'?' required':'';?>></label></div></div><?php else:?><input type="hidden" name="zau_pin_setup_mode" value="off"><?php endif;?>
        <div class="zau-form-actions"><button type="submit" class="zau-union-button">Отправить и сформировать документы</button><span class="zau-form-progress" data-zau-form-progress></span></div><div data-zau-form-result></div><div class="zau-offscreen-render" data-zau-render-holder></div></form>
        <?php return ob_get_clean();
    }

    private function render_public_field($field,$user) {
        $key=$field['key']; $type=$field['type']; $label=$field['label']; $required=$field['required'];
        if($type==='heading'){echo '<div class="zau-form-heading"><h3>'.esc_html($label).'</h3></div>';return;}
        $value=$this->prefill_value($key,$field['default'],$user); $req=$required?' required':''; $star=$required?' <span class="zau-required-mark">*</span>':'';
        echo '<div class="zau-form-field zau-field-'.esc_attr($type).'" data-field-key="'.esc_attr($key).'">';
        if($type==='hidden'){echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';echo '</div>';return;}
        if($type==='checkbox'){echo '<label class="zau-checkbox"><input type="checkbox" name="'.esc_attr($key).'" value="1"'.$req.'> <span>'.esc_html($label).$star.'</span></label>';echo '</div>';return;}
        echo '<label>'.esc_html($label).$star;
        if($type==='textarea')echo '<textarea name="'.esc_attr($key).'" placeholder="'.esc_attr($field['placeholder']).'"'.$req.'>'.esc_textarea($value).'</textarea>';
        elseif($type==='select'){echo '<select name="'.esc_attr($key).'"'.$req.'><option value="">— Выберите вариант —</option>';foreach(preg_split('/\r?\n/',(string)$field['options']) as $option){$option=trim($option);if($option==='')continue;$parts=array_map('trim',explode('|',$option,2));$val=$parts[0];$text=$parts[1]??$parts[0];echo '<option value="'.esc_attr($val).'" '.selected($value,$val,false).'>'.esc_html($text).'</option>';}echo '</select>';}
        elseif($type==='branch_select'){$branches=$this->branch_rows(true);echo '<select name="'.esc_attr($key).'"'.$req.' data-zau-branch-select><option value="">— Выберите филиал —</option>';foreach($branches as $branch){echo '<option value="'.(int)$branch->id.'" '.selected((int)$value,(int)$branch->id,false).'>'.esc_html($branch->name.($branch->region?' · '.$branch->region:'')).'</option>';}echo '</select></label><div class="zau-branch-summary" data-zau-branch-summary hidden></div>';}
        elseif($type==='signature'){echo '</label><div class="zau-signature-wrap"><canvas class="zau-signature-canvas" width="900" height="240"></canvas><input type="hidden" name="'.esc_attr($key).'" data-zau-signature-value'.$req.'><div><button type="button" class="zau-link-button" data-zau-clear-signature>Очистить подпись</button></div></div>';}
        elseif($type==='bin_lookup'){echo '<div class="zau-bin-control"><div class="zau-bin-row"><input type="text" inputmode="numeric" maxlength="12" autocomplete="off" name="'.esc_attr($key).'" value="'.esc_attr($value).'" placeholder="'.esc_attr($field['placeholder']).'"'.$req.' data-zau-bin-input><button type="button" class="zau-bin-button" data-zau-bin-lookup>Найти</button></div><div class="zau-bin-suggestions" data-zau-bin-suggestions hidden></div></div><small class="zau-bin-status" data-zau-bin-status>Введите 12-значный БИН организации или ИИН индивидуального предпринимателя и нажмите «Найти».</small>';}
        else { $htmlType=['email'=>'email','phone'=>'tel','number'=>'number','date'=>'date'][$type]??'text'; echo '<input type="'.$htmlType.'" name="'.esc_attr($key).'" value="'.esc_attr($value).'" placeholder="'.esc_attr($field['placeholder']).'"'.$req.'>'; }
        if(!in_array($type,['signature','branch_select'],true))echo '</label>'; echo '</div>';
    }

    private function prefill_value($key,$default,$user) {
        if(!$user || !$user->ID)return $default;
        $map=['first_name'=>$user->first_name,'last_name'=>$user->last_name,'email'=>$user->user_email,'phone'=>get_user_meta($user->ID,'zau_phone',true),'branch'=>get_user_meta($user->ID,'zau_profile_branch_id',true)];
        if(isset($map[$key]) && $map[$key] !== '')return $map[$key];
        $saved=get_user_meta($user->ID,'zau_profile_'.$key,true); return $saved!==''?$saved:$default;
    }

    private function latest_user_documents($uid) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.user_id=%d ORDER BY d.id DESC", $uid));
        $latest = [];
        foreach ($rows as $row) {
            $templateId = (int) $row->template_id;
            $label = $row->template_name ?: $row->document_title;
            $key = $templateId > 0 ? ('tpl_' . $templateId) : ('title_' . sanitize_title((string) $label));
            if (isset($latest[$key])) continue;
            $latest[$key] = $row;
        }
        return array_values($latest);
    }

    private function latest_user_submissions($uid) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT s.*,f.name form_name FROM {$this->submissions_table} s LEFT JOIN {$this->forms_table} f ON f.id=s.form_id WHERE s.user_id=%d ORDER BY s.id DESC", $uid));
        $latest = [];
        foreach ($rows as $row) {
            $key = (int) $row->form_id;
            if (isset($latest[$key])) continue;
            $latest[$key] = $row;
        }
        return array_values($latest);
    }

    private function member_form_url() {
        $designSettings = $this->settings();
        return $designSettings['form_page_id'] ? get_permalink((int) $designSettings['form_page_id']) : home_url('/registraciya-v-profsoyuz/');
    }

    public function cabinet_shortcode($atts=[]) {
        if(!is_user_logged_in())return $this->auth_shortcode($atts);
        if(!defined('DONOTCACHEPAGE')){ define('DONOTCACHEPAGE', true); }
        nocache_headers();
        $atts=shortcode_atts([
            'default_tab'=>'home',
            'tabs'=>'home,documents,submissions,card,benefits,members,registry,info,logins',
            'custom_tabs'=>'yes',
            'quick_actions'=>'inherit',
            'document_search'=>'inherit',
            'mobile_bottom_nav'=>'inherit',
            'member_card'=>'inherit',
        ],(array)$atts,'zau_union_cabinet');
        global $wpdb;
        $uid=get_current_user_id();
        $user=wp_get_current_user();
        $status=get_user_meta($uid,'zau_member_status',true)?:'Заявление ещё не рассмотрено';
        $phone=get_user_meta($uid,'zau_phone',true);
        $submissions=$this->latest_user_submissions($uid);
        $docs=$this->latest_user_documents($uid);
        foreach((array)$docs as $index=>$doc){$docs[$index]=$this->normalize_document_for_display($doc,false);}
        $allDocsForSubmissions=$wpdb->get_results($wpdb->prepare("SELECT source_submission_id,pdf_url FROM {$this->docs_table} WHERE user_id=%d",$uid));
        $docsBySubmission=[];
        foreach((array)$allDocsForSubmissions as $doc){$docsBySubmission[(int)$doc->source_submission_id][]=$doc;}
        $info=get_posts(['post_type'=>'zau_union_info','post_status'=>'publish','numberposts'=>20,'orderby'=>'date','order'=>'DESC']);
        $benefits=$this->available_benefits($uid);
        $loginHistory=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->logs_table} WHERE user_id=%d AND action IN ('otp_login','pin_login','password_login','pin_reset_login','logout') ORDER BY id DESC LIMIT 20",$uid));
        $orgId=$this->member_org_id($uid);
        $organization=$orgId?$wpdb->get_row($wpdb->prepare("SELECT name,bin FROM {$this->orgs_table} WHERE id=%d",$orgId)):null;
        $certSettings=wp_parse_args((array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS,[]),['owner_pdf_view'=>1,'cabinet_document_mode'=>'both']);
        $documentMode=in_array($certSettings['cabinet_document_mode'],['popup','tab','both'],true)?$certSettings['cabinet_document_mode']:'both';
        $canView=!empty($certSettings['owner_pdf_view']);
        $lastLogin='';
        foreach($loginHistory as $event){if($this->is_login_action($event->action)){$lastLogin=$event->created_at;break;}}
        $canManageOrg=current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE)||current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options');
        $customTabs=$this->available_custom_tabs($uid,$canManageOrg);
        $customTabIcons=[];
        $tabLabels=[
            'home'=>'Главная',
            'documents'=>'Документы',
            'submissions'=>'Заявления',
            'card'=>'Личная карточка',
            'benefits'=>'Акции и скидки',
            'members'=>'Участники',
            'registry'=>'Реестр организации',
            'info'=>'Материалы',
            'logins'=>'История входов',
        ];
        foreach($customTabs as $slug=>$customTab){$tabLabels[$slug]=$customTab['label'];$customTabIcons[$slug]=$customTab['icon'];}
        $requestedTabs=array_values(array_unique(array_filter(array_map('sanitize_key',explode(',',(string)$atts['tabs'])))));
        if(($atts['custom_tabs']??'yes')!=='no')$requestedTabs=array_values(array_unique(array_merge($requestedTabs,array_keys($customTabs))));
        $enabledTabs=[];
        foreach($requestedTabs as $tab){
            if(!isset($tabLabels[$tab]))continue;
            if($tab==='benefits'&&empty($this->settings()['benefits_tab_enabled']))continue;
            if($tab==='card'&&empty($this->settings()['member_card_tab_enabled'])&&($atts['member_card']??'inherit')==='inherit')continue;
            if(in_array($tab,['members','registry'],true)&&!$canManageOrg)continue;
            if(!$this->tab_allowed_for_current_user($tab))continue;
            $enabledTabs[]=$tab;
        }
        if(!$enabledTabs)$enabledTabs=['home','documents','submissions','card','benefits','info','logins'];
        $requestedActive=sanitize_key((string)wp_unslash($_GET['zau_tab']??$atts['default_tab']));
        $activeTab=in_array($requestedActive,$enabledTabs,true)?$requestedActive:$enabledTabs[0];
        $currentUrl=remove_query_arg('zau_tab',home_url(wp_unslash($_SERVER['REQUEST_URI']??'/')));
        $autoAssigned=false;
        $designSettings=$this->settings();
        $showQuickActions=$this->shortcode_switch($atts['quick_actions']??'inherit',!empty($designSettings['design_quick_actions']));
        $showDocumentSearch=$this->shortcode_switch($atts['document_search']??'inherit',!empty($designSettings['design_document_search']));
        $mobileBottomNav=$this->shortcode_switch($atts['mobile_bottom_nav']??'inherit',!empty($designSettings['design_mobile_bottom_nav']));
        $showMemberCard=$this->shortcode_switch($atts['member_card']??'inherit',!empty($designSettings['member_card_tab_enabled']));
        if(!$showMemberCard)$enabledTabs=array_values(array_diff($enabledTabs,['card']));
        if(!$enabledTabs)$enabledTabs=['home','documents','submissions','info','logins'];
        if(!in_array($activeTab,$enabledTabs,true))$activeTab=$enabledTabs[0];
        $formUrl=$designSettings['form_page_id']?get_permalink((int)$designSettings['form_page_id']):home_url('/registraciya-v-profsoyuz/');
        ob_start(); ?>
        <div class="zau-cabinet zau-aqniet-cabinet<?php echo $mobileBottomNav?' has-mobile-bottom-nav':'';?>" data-zau-cabinet data-zau-cabinet-tabs data-zau-active-tab="<?php echo esc_attr($activeTab);?>">
            <header class="zau-cabinet-hero">
                <div class="zau-cabinet-person">
                    <span class="zau-cabinet-avatar"><?php echo get_avatar($uid,72,'','', ['class'=>'zau-cabinet-avatar-image']);?></span>
                    <div><span class="zau-aqniet-kicker">Личный кабинет участника</span><h2><?php echo esc_html($user->display_name);?></h2><p><?php echo esc_html(trim($user->user_email.' '.$phone));?></p></div>
                </div>
                <div class="zau-cabinet-hero-actions">
                    <div class="zau-member-status"><span>Статус членства</span><strong><?php echo esc_html($status);?></strong></div>
                    <a class="zau-cabinet-logout" href="<?php echo esc_url(wp_logout_url(home_url('/')));?>">Выйти</a>
                </div>
            </header>

            <nav class="zau-cabinet-nav" role="tablist" aria-label="Разделы личного кабинета">
                <?php foreach($enabledTabs as $tab):
                    $isActive=$tab===$activeTab;
                    $url=add_query_arg('zau_tab',$tab,$currentUrl);
                ?>
                    <a id="zau-tab-<?php echo esc_attr($tab);?>" role="tab" aria-selected="<?php echo $isActive?'true':'false';?>" aria-controls="zau-<?php echo esc_attr($tab);?>" tabindex="<?php echo $isActive?'0':'-1';?>" class="<?php echo $isActive?'is-active':'';?>" data-zau-tab="<?php echo esc_attr($tab);?>" href="<?php echo esc_url($url);?>"><?php $icon=$customTabIcons[$tab]??''; if($icon):?><span class="zau-tab-icon<?php echo strpos($icon,'dashicons-')===0?' dashicons '.esc_attr($icon):'';?>"><?php if(strpos($icon,'dashicons-')!==0)echo esc_html($icon);?></span><?php endif;?><?php echo esc_html($tabLabels[$tab]);?></a>
                <?php endforeach;?>
            </nav>

            <?php if(in_array('home',$enabledTabs,true)):?>
            <section id="zau-home" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-home" data-zau-tab-panel="home"<?php echo $activeTab==='home'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Главная</h3><p>Краткая информация о членстве и личном кабинете.</p></div></div>
                <?php if($showQuickActions):?>
                <div class="zau-aqniet-quick-actions" aria-label="Быстрые действия">
                    <a class="zau-aqniet-action-card is-primary" href="<?php echo esc_url($formUrl);?>"><span class="zau-aqniet-action-icon">＋</span><strong>Подать заявление</strong><small>Новая регистрация или форма</small></a>
                    <?php if(in_array('documents',$enabledTabs,true)):?><a class="zau-aqniet-action-card" href="#zau-documents" data-zau-open-tab="documents"><span class="zau-aqniet-action-icon">▤</span><strong>Мои документы</strong><small><?php echo count($docs);?> файлов</small></a><?php endif;?>
                    <?php if(in_array('submissions',$enabledTabs,true)):?><a class="zau-aqniet-action-card" href="#zau-submissions" data-zau-open-tab="submissions"><span class="zau-aqniet-action-icon">✓</span><strong>Мои заявления</strong><small>Статусы и формирование PDF</small></a><?php endif;?>
                    <?php if(in_array('card',$enabledTabs,true)):?><a class="zau-aqniet-action-card" href="#zau-card" data-zau-open-tab="card"><span class="zau-aqniet-action-icon">▣</span><strong>Личная карточка</strong><small>Данные члена профсоюза</small></a><?php endif;?>
                    <?php if(in_array('benefits',$enabledTabs,true)):?><a class="zau-aqniet-action-card" href="#zau-benefits" data-zau-open-tab="benefits"><span class="zau-aqniet-action-icon">%</span><strong>Акции и скидки</strong><small><?php echo count($benefits);?> предложений</small></a><?php endif;?>
                    <?php if(in_array('info',$enabledTabs,true)):?><a class="zau-aqniet-action-card" href="#zau-info" data-zau-open-tab="info"><span class="zau-aqniet-action-icon">i</span><strong>Материалы</strong><small>Новости и полезные файлы</small></a><?php endif;?>
                </div>
                <?php endif;?>
                <div class="zau-cabinet-stats">
                    <div><span>Документов</span><strong><?php echo count($docs);?></strong></div>
                    <div><span>Заявлений</span><strong><?php echo count($submissions);?></strong></div>
                    <div><span>Организация</span><strong><?php echo esc_html($organization?$organization->name:'Не назначена');?></strong><?php if($organization):?><small>БИН <?php echo esc_html($organization->bin);?></small><?php endif;?></div>
                    <div><span>Последний вход</span><strong><?php echo esc_html($lastLogin?:'Нет данных');?></strong></div>
                </div>
            </section>
            <?php endif;?>

            <?php if(in_array('documents',$enabledTabs,true)):?>
            <section id="zau-documents" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-documents" data-zau-tab-panel="documents"<?php echo $activeTab==='documents'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Мои документы</h3><p>Предпросмотр открывается внутри кабинета. Страница проверки QR никогда не показывает сам PDF.</p></div><button type="button" class="zau-union-button zau-secondary-button" data-zau-refresh-documents>Обновить</button></div>
                <?php if($showDocumentSearch):?>
                <div class="zau-aqniet-document-toolbar" data-zau-document-toolbar>
                    <label class="zau-aqniet-search"><span class="screen-reader-text">Поиск документов</span><input type="search" placeholder="Поиск по документам" data-zau-document-search></label>
                    <label class="zau-aqniet-filter"><span class="screen-reader-text">Фильтр документов</span><select data-zau-document-filter><option value="">Все статусы</option><option value="active">Действующие</option><option value="revoked">Отозванные</option><option value="pending">Готовятся</option></select></label>
                    <span class="zau-aqniet-doc-count" data-zau-document-count></span>
                </div>
                <?php endif;?>
                <div class="zau-doc-grid" data-zau-doc-grid>
                <?php if(!$docs):?><div class="zau-empty-state"><strong>Документов пока нет</strong><span>После формирования они появятся в этой вкладке.</span></div><?php endif;?>
                <?php foreach($docs as $doc):
                    $controller=ZAU_Certificate_PDF_Generator::instance();
                    $pdfUrl=$canView&&$doc->pdf_url?$controller->secure_document_url($doc,'pdf'):'';
                    $imageUrl=$canView&&$doc->image_url?$controller->secure_document_url($doc,'image'):'';
                ?>
                    <article class="zau-doc-card" data-zau-document-card="<?php echo (int)$doc->id;?>" data-document-search="<?php echo esc_attr(function_exists('mb_strtolower')?mb_strtolower(($doc->template_name?:$doc->document_title).' '.$doc->document_no,'UTF-8'):strtolower(($doc->template_name?:$doc->document_title).' '.$doc->document_no));?>" data-document-state="<?php echo esc_attr(!$doc->pdf_url?'pending':($doc->record_status==='active'?'active':'revoked'));?>">
                        <div class="zau-doc-card-top"><span class="zau-doc-icon">▤</span><span class="zau-doc-state <?php echo $doc->record_status==='active'?'is-active':'is-revoked';?>"><?php echo esc_html($doc->record_status==='active'?'Действует':'Отозван');?></span></div>
                        <strong><?php echo esc_html($doc->template_name?:$doc->document_title);?></strong>
                        <dl><div><dt>Номер</dt><dd><?php echo esc_html($doc->document_no);?></dd></div><div><dt>Дата</dt><dd><?php echo esc_html($doc->issue_date);?></dd></div></dl>
                        <div class="zau-doc-actions">
                            <?php if(!$doc->pdf_url):?><span class="zau-muted">PDF ещё не сформирован</span><?php else:?>
                                <?php if(($imageUrl||$pdfUrl)&&in_array($documentMode,['popup','both'],true)):?><button type="button" class="zau-union-button zau-secondary-button" data-zau-document-preview data-preview-url="<?php echo esc_url($imageUrl);?>" data-preview-pdf-url="<?php echo esc_url($pdfUrl);?>" data-preview-title="<?php echo esc_attr($doc->template_name?:$doc->document_title);?>">Предпросмотр</button><?php endif;?>
                                <?php if($pdfUrl&&in_array($documentMode,['tab','both'],true)):?><a class="zau-union-button" target="_blank" rel="noopener" href="<?php echo esc_url($pdfUrl);?>">Открыть PDF</a><?php endif;?>
                            <?php endif;?>
                        </div>
                    </article>
                <?php endforeach;?>
                </div>
            </section>
            <?php endif;?>

            <?php if(in_array('submissions',$enabledTabs,true)):?>
            <section id="zau-submissions" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-submissions" data-zau-tab-panel="submissions"<?php echo $activeTab==='submissions'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Мои заявления</h3><p>Показывается только последнее заявление по каждой форме и состояние его документов.</p></div><a class="zau-union-button zau-secondary-button" href="<?php echo esc_url($formUrl);?>">Пересдать / подать новое заявление</a></div>
                <div class="zau-cabinet-generation" data-zau-cabinet-generation hidden><span data-zau-cabinet-progress></span><div data-zau-cabinet-result></div></div>
                <?php if(!$submissions):?><div class="zau-empty-state">Отправленных форм пока нет.</div><?php endif;?>
                <div class="zau-submission-list">
                <?php foreach($submissions as $s):
                    $submissionDocs=$docsBySubmission[(int)$s->id]??[];
                    $needsPdf=!$submissionDocs;
                    foreach($submissionDocs as $submissionDoc){if(empty($submissionDoc->pdf_url)){$needsPdf=true;break;}}
                    $auto=$needsPdf&&!$autoAssigned; if($auto)$autoAssigned=true;
                ?>
                    <article class="zau-submission-row">
                        <div><strong>#<?php echo (int)$s->id;?> — <?php echo esc_html($s->form_name);?></strong><small><?php echo esc_html($s->created_at);?></small></div>
                        <span class="zau-submission-status"><?php echo esc_html($s->status==='submitted'?'Отправлено':$s->status);?></span>
                        <?php if($needsPdf):?><button type="button" class="zau-union-button zau-small-button" data-zau-recover-submission="<?php echo (int)$s->id;?>" data-auto="<?php echo $auto?'1':'0';?>">Сформировать PDF</button><?php endif;?>
                    </article>
                <?php endforeach;?>
                </div>
            </section>
            <?php endif;?>

            <?php if(in_array('card',$enabledTabs,true)):?>
            <section id="zau-card" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-card" data-zau-tab-panel="card"<?php echo $activeTab==='card'?'':' hidden';?>>
                <?php $cardTarget=absint($_GET['member_id']??0);if(!$cardTarget)$cardTarget=$uid;echo $this->render_member_card($cardTarget,$uid);?>
            </section>
            <?php endif;?>

            <?php if(in_array('benefits',$enabledTabs,true)):?>
            <section id="zau-benefits" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-benefits" data-zau-tab-panel="benefits"<?php echo $activeTab==='benefits'?'':' hidden';?>>
                <?php echo $this->benefits_shortcode();?>
            </section>
            <?php endif;?>

            <?php if(in_array('members',$enabledTabs,true)):?>
            <section id="zau-members" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-members" data-zau-tab-panel="members"<?php echo $activeTab==='members'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Участники моей организации</h3><p>Список доступен только назначенному ответственному организации.</p></div></div>
                <?php echo $this->organization_members_shortcode(['show_heading'=>'no']); ?>
            </section>
            <?php endif;?>

            <?php if(in_array('registry',$enabledTabs,true)):?>
            <section id="zau-registry" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-registry" data-zau-tab-panel="registry"<?php echo $activeTab==='registry'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Реестр организации</h3><p>Фильтрация, просмотр документов и экспорт участников вашей организации.</p></div></div>
                <?php echo $this->organization_registry_shortcode(['embedded'=>'yes']); ?>
            </section>
            <?php endif;?>

            <?php if(in_array('info',$enabledTabs,true)):?>
            <section id="zau-info" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-info" data-zau-tab-panel="info"<?php echo $activeTab==='info'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>Материалы</h3><p>Новости, инструкции и материалы Профсоюза находятся только в этой вкладке.</p></div></div>
                <?php if(!$info):?><div class="zau-empty-state">Новых материалов пока нет.</div><?php endif;?>
                <div class="zau-info-list"><?php foreach($info as $post):?><article class="zau-info-card"><h4><?php echo esc_html(get_the_title($post));?></h4><div><?php echo wp_kses_post(apply_filters('the_content',$post->post_content));?></div></article><?php endforeach;?></div>
            </section>
            <?php endif;?>

            <?php if(in_array('logins',$enabledTabs,true)):?>
            <section id="zau-logins" class="zau-cabinet-section zau-cabinet-tab-panel" role="tabpanel" aria-labelledby="zau-tab-logins" data-zau-tab-panel="logins"<?php echo $activeTab==='logins'?'':' hidden';?>>
                <div class="zau-section-head"><div><h3>История входов</h3><p>В этой вкладке отображаются только события входа и выхода.</p></div></div>
                <?php if(!$loginHistory):?><div class="zau-empty-state">История входов пока пуста.</div><?php else:?><div class="zau-login-history">
                <?php foreach($loginHistory as $event):?><article><span class="zau-login-dot <?php echo $this->is_login_action($event->action)?'is-login':'is-logout';?>"></span><div><strong><?php echo $this->is_login_action($event->action)?$this->login_action_label($event->action):'Выход из кабинета';?></strong><small><?php echo esc_html($event->details);?></small></div><time><?php echo esc_html($event->created_at);?></time><code><?php echo esc_html($event->ip);?></code></article><?php endforeach;?>
                </div><?php endif;?>
            </section>
            <?php endif;?>


            <?php foreach($customTabs as $slug=>$customTab): if(!in_array($slug,$enabledTabs,true))continue;?>
            <section id="zau-<?php echo esc_attr($slug);?>" class="zau-cabinet-section zau-cabinet-tab-panel zau-custom-tab-panel" role="tabpanel" aria-labelledby="zau-tab-<?php echo esc_attr($slug);?>" data-zau-tab-panel="<?php echo esc_attr($slug);?>"<?php echo $activeTab===$slug?'':' hidden';?>>
                <?php echo $this->render_custom_tab_post($customTab['post']);?>
            </section>
            <?php endforeach;?>

            <div class="zau-document-modal" data-zau-document-modal hidden>
                <div class="zau-document-modal-backdrop" data-zau-modal-close></div>
                <div class="zau-document-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="zau-document-modal-title">
                    <div class="zau-document-modal-head"><strong id="zau-document-modal-title" data-zau-modal-title>Предпросмотр документа</strong><button type="button" data-zau-modal-close aria-label="Закрыть">×</button></div>
                    <div class="zau-document-modal-body"><div class="zau-preview-loading" data-zau-preview-loading>Загружаем документ…</div><img data-zau-modal-image alt="Предпросмотр документа" hidden><iframe data-zau-modal-pdf title="Предпросмотр PDF" hidden></iframe></div>
                </div>
            </div>
            <div data-zau-cabinet-render-holder aria-hidden="true"></div>
        </div>
        <?php return ob_get_clean();
    }


    private function cabinet_section_heading($section, $title = '', $subtitle = '') {
        $defaults = [
            'documents'=>['Мои документы','Предпросмотр открывается в личном кабинете. Проверка QR не показывает сам PDF.'],
            'submissions'=>['Мои заявления','Сохранённые отправки форм и состояние сформированных документов.'],
            'card'=>['Личная карточка','Персональные и служебные данные члена профсоюза.'],
            'benefits'=>['Акции и скидки','Актуальные предложения Профсоюза и партнёров.'],
            'members'=>['Участники моей организации или филиала','Список участников, доступный назначенному ответственному.'],
            'info'=>['Информация для ознакомления','Новости, инструкции и материалы Профсоюза.'],
            'logins'=>['История входов','Последние входы по одноразовому коду и выходы из кабинета.'],
        ];
        $d = $defaults[$section] ?? ['', ''];
        return [trim((string)$title) !== '' ? $title : $d[0], trim((string)$subtitle) !== '' ? $subtitle : $d[1]];
    }

    public function cabinet_section_shortcode($atts = []) {
        if (!is_user_logged_in()) { return $this->auth_shortcode($atts); }
        if(!defined('DONOTCACHEPAGE')){ define('DONOTCACHEPAGE', true); }
        nocache_headers();
        $a = shortcode_atts([
            'section'=>'documents',
            'title'=>'',
            'subtitle'=>'',
            'show_heading'=>'yes',
            'nav_items'=>'documents,submissions,card,benefits,members,info,logins',
        ], $atts, 'zau_union_cabinet_section');
        $section = sanitize_key($a['section']);
        $allowed = ['profile','navigation','stats','documents','submissions','card','benefits','members','info','logins','logout'];
        if (!in_array($section, $allowed, true)) { $section = 'documents'; }
        if (in_array($section, ['documents','submissions','card','benefits','members','registry','info','logins'], true) && !$this->tab_allowed_for_current_user($section)) { return ''; }
        global $wpdb;
        $uid = get_current_user_id();
        $user = wp_get_current_user();
        $status = get_user_meta($uid,'zau_member_status',true) ?: 'Заявление ещё не рассмотрено';
        $phone = get_user_meta($uid,'zau_phone',true);
        [$heading,$subtitle] = $this->cabinet_section_heading($section, sanitize_text_field($a['title']), sanitize_text_field($a['subtitle']));
        $showHeading = $a['show_heading'] !== 'no';
        ob_start();
        ?>
        <div class="zau-cabinet zau-cabinet-builder-block zau-cabinet-block-<?php echo esc_attr($section);?>" data-zau-cabinet>
        <?php if ($section === 'profile'): ?>
            <header class="zau-cabinet-hero">
                <div class="zau-cabinet-person"><span class="zau-cabinet-avatar"><?php echo esc_html(function_exists('mb_substr')?mb_substr($user->display_name,0,1,'UTF-8'):substr($user->display_name,0,1));?></span><div><h2><?php echo esc_html($user->display_name);?></h2><p><?php echo esc_html(trim($user->user_email.' '.$phone));?></p></div></div>
                <div class="zau-cabinet-hero-actions"><div class="zau-member-status"><span>Статус членства</span><strong><?php echo esc_html($status);?></strong></div><a class="zau-cabinet-logout" href="<?php echo esc_url(wp_logout_url(home_url('/')));?>">Выйти</a></div>
            </header>
        <?php elseif ($section === 'navigation'):
            $labels=['documents'=>'Документы','submissions'=>'Заявления','card'=>'Личная карточка','benefits'=>'Акции и скидки','members'=>'Участники','info'=>'Материалы','logins'=>'Входы'];
            $items=array_filter(array_map('sanitize_key',explode(',',(string)$a['nav_items'])));
            ?>
            <nav class="zau-cabinet-nav" aria-label="Разделы личного кабинета" data-zau-section-nav><?php foreach($items as $item): if(!isset($labels[$item]))continue; if($item==='members'&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))continue; if(!$this->tab_allowed_for_current_user($item))continue;?><a href="#zau-<?php echo esc_attr($item);?>" data-zau-tab="<?php echo esc_attr($item);?>"><?php echo esc_html($labels[$item]);?></a><?php endforeach;?></nav>
        <?php elseif ($section === 'stats'):
            $docCount=count($this->latest_user_documents($uid));
            $submissionCount=count($this->latest_user_submissions($uid));
            $orgId=$this->member_org_id($uid); $organization=$orgId?$wpdb->get_row($wpdb->prepare("SELECT name,bin FROM {$this->orgs_table} WHERE id=%d",$orgId)):null;
            $lastLogin=$wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$this->logs_table} WHERE user_id=%d AND action IN ('otp_login','pin_login','password_login','pin_reset_login') ORDER BY id DESC LIMIT 1",$uid));
            ?>
            <div class="zau-cabinet-stats"><div><span>Документов</span><strong><?php echo $docCount;?></strong></div><div><span>Заявлений</span><strong><?php echo $submissionCount;?></strong></div><div><span>Организация</span><strong><?php echo esc_html($organization?$organization->name:'Не назначена');?></strong><?php if($organization):?><small>БИН <?php echo esc_html($organization->bin);?></small><?php endif;?></div><div><span>Последний вход</span><strong><?php echo esc_html($lastLogin?:'Нет данных');?></strong></div></div>
        <?php elseif ($section === 'documents'):
            $docs=$this->latest_user_documents($uid);
            foreach((array)$docs as $index=>$doc){$docs[$index]=$this->normalize_document_for_display($doc,false);}
            $certSettings=wp_parse_args((array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS,[]),['owner_pdf_view'=>1,'cabinet_document_mode'=>'both']);
            $documentMode=in_array($certSettings['cabinet_document_mode'],['popup','tab','both'],true)?$certSettings['cabinet_document_mode']:'both'; $canView=!empty($certSettings['owner_pdf_view']);
            ?>
            <section id="zau-documents" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="documents"><?php if($showHeading):?><div class="zau-section-head"><div><h3><?php echo esc_html($heading);?></h3><?php if($subtitle):?><p><?php echo esc_html($subtitle);?></p><?php endif;?></div><button type="button" class="zau-union-button zau-secondary-button" data-zau-refresh-documents>Обновить документы</button></div><?php endif;?><div class="zau-doc-grid" data-zau-doc-grid>
            <?php if(!$docs):?><div class="zau-empty-state"><strong>Документов пока нет</strong><span>После формирования они появятся в этом разделе.</span></div><?php endif;?>
            <?php foreach($docs as $doc): $controller=ZAU_Certificate_PDF_Generator::instance();$pdfUrl=$canView&&$doc->pdf_url?$controller->secure_document_url($doc,'pdf'):'';$imageUrl=$canView&&$doc->image_url?$controller->secure_document_url($doc,'image'):'';?>
                <article class="zau-doc-card"><div class="zau-doc-card-top"><span class="zau-doc-icon">▤</span><span class="zau-doc-state <?php echo $doc->record_status==='active'?'is-active':'is-revoked';?>"><?php echo esc_html($doc->record_status==='active'?'Действует':'Отозван');?></span></div><strong><?php echo esc_html($doc->template_name?:$doc->document_title);?></strong><dl><div><dt>Номер</dt><dd><?php echo esc_html($doc->document_no);?></dd></div><div><dt>Дата</dt><dd><?php echo esc_html($doc->issue_date);?></dd></div></dl><div class="zau-doc-actions"><?php if(!$doc->pdf_url):?><span class="zau-muted">PDF ещё не сформирован</span><?php else:?><?php if(($imageUrl||$pdfUrl)&&in_array($documentMode,['popup','both'],true)):?><button type="button" class="zau-union-button zau-secondary-button" data-zau-document-preview data-preview-url="<?php echo esc_url($imageUrl);?>" data-preview-pdf-url="<?php echo esc_url($pdfUrl);?>" data-preview-title="<?php echo esc_attr($doc->template_name?:$doc->document_title);?>">Предпросмотр</button><?php endif;?><?php if($pdfUrl&&in_array($documentMode,['tab','both'],true)):?><a class="zau-union-button" target="_blank" rel="noopener" href="<?php echo esc_url($pdfUrl);?>">Открыть PDF</a><?php endif;?><?php endif;?></div></article>
            <?php endforeach;?></div></section>
            <div class="zau-document-modal" data-zau-document-modal hidden><div class="zau-document-modal-backdrop" data-zau-modal-close></div><div class="zau-document-modal-dialog" role="dialog" aria-modal="true"><div class="zau-document-modal-head"><strong data-zau-modal-title>Предпросмотр документа</strong><button type="button" data-zau-modal-close aria-label="Закрыть">×</button></div><div class="zau-document-modal-body"><div class="zau-preview-loading" data-zau-preview-loading>Загружаем документ…</div><img data-zau-modal-image alt="Предпросмотр документа" hidden><iframe data-zau-modal-pdf title="Предпросмотр PDF" hidden></iframe></div></div></div><div data-zau-cabinet-render-holder aria-hidden="true"></div>
        <?php elseif ($section === 'submissions'):
            $submissions=$this->latest_user_submissions($uid);
            $docs=$wpdb->get_results($wpdb->prepare("SELECT source_submission_id,pdf_url FROM {$this->docs_table} WHERE user_id=%d",$uid));$docsBySubmission=[];foreach($docs as $doc){$docsBySubmission[(int)$doc->source_submission_id][]=$doc;}$autoAssigned=false;
            $formUrl=$this->member_form_url();
            ?>
            <section id="zau-submissions" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="submissions"><?php if($showHeading):?><div class="zau-section-head"><div><h3><?php echo esc_html($heading);?></h3><?php if($subtitle):?><p><?php echo esc_html($subtitle);?></p><?php endif;?></div><a class="zau-union-button zau-secondary-button" href="<?php echo esc_url($formUrl);?>">Пересдать / подать новое заявление</a></div><?php endif;?><div class="zau-cabinet-generation" data-zau-cabinet-generation hidden><span data-zau-cabinet-progress></span><div data-zau-cabinet-result></div></div><?php if(!$submissions):?><div class="zau-empty-state">Отправленных форм пока нет.</div><?php endif;?><div class="zau-submission-list">
            <?php foreach($submissions as $row):$submissionDocs=$docsBySubmission[(int)$row->id]??[];$needsPdf=!$submissionDocs;foreach($submissionDocs as $submissionDoc){if(empty($submissionDoc->pdf_url)){$needsPdf=true;break;}}$auto=$needsPdf&&!$autoAssigned;if($auto)$autoAssigned=true;?><article class="zau-submission-row"><div><strong>#<?php echo (int)$row->id;?> — <?php echo esc_html($row->form_name);?></strong><small><?php echo esc_html($row->created_at);?></small></div><span class="zau-submission-status"><?php echo esc_html($row->status==='submitted'?'Отправлено':$row->status);?></span><?php if($needsPdf):?><button type="button" class="zau-union-button zau-small-button" data-zau-recover-submission="<?php echo (int)$row->id;?>" data-auto="<?php echo $auto?'1':'0';?>">Сформировать PDF</button><?php endif;?></article><?php endforeach;?></div></section><div data-zau-cabinet-render-holder aria-hidden="true"></div>
        <?php elseif ($section === 'card'): ?>
            <section id="zau-card" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="card"><?php echo $this->member_card_shortcode();?></section>
        <?php elseif ($section === 'benefits'): ?>
            <section id="zau-benefits" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="benefits"><?php echo $this->benefits_shortcode();?></section>
        <?php elseif ($section === 'members'): ?>
            <section id="zau-members" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="members"><?php if($showHeading):?><div class="zau-section-head"><div><h3><?php echo esc_html($heading);?></h3><?php if($subtitle):?><p><?php echo esc_html($subtitle);?></p><?php endif;?></div></div><?php endif;?><?php echo $this->organization_members_shortcode(['show_heading'=>'no']);?></section>
        <?php elseif ($section === 'info'):
            $info=get_posts(['post_type'=>'zau_union_info','post_status'=>'publish','numberposts'=>20,'orderby'=>'date','order'=>'DESC']);?>
            <section id="zau-info" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="info"><?php if($showHeading):?><div class="zau-section-head"><div><h3><?php echo esc_html($heading);?></h3><?php if($subtitle):?><p><?php echo esc_html($subtitle);?></p><?php endif;?></div></div><?php endif;?><?php if(!$info):?><div class="zau-empty-state">Новых материалов пока нет.</div><?php endif;?><div class="zau-info-list"><?php foreach($info as $post):?><article class="zau-info-card"><h4><?php echo esc_html(get_the_title($post));?></h4><div><?php echo wp_kses_post(apply_filters('the_content',$post->post_content));?></div></article><?php endforeach;?></div></section>
        <?php elseif ($section === 'logins'):
            $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->logs_table} WHERE user_id=%d AND action IN ('otp_login','pin_login','password_login','pin_reset_login','logout') ORDER BY id DESC LIMIT 20",$uid));?>
            <section id="zau-logins" class="zau-cabinet-section zau-cabinet-tab-panel" data-zau-tab-panel="logins"><?php if($showHeading):?><div class="zau-section-head"><div><h3><?php echo esc_html($heading);?></h3><?php if($subtitle):?><p><?php echo esc_html($subtitle);?></p><?php endif;?></div></div><?php endif;?><?php if(!$events):?><div class="zau-empty-state">История входов пока пуста.</div><?php else:?><div class="zau-login-history"><?php foreach($events as $event):?><article><span class="zau-login-dot <?php echo $this->is_login_action($event->action)?'is-login':'is-logout';?>"></span><div><strong><?php echo $this->is_login_action($event->action)?$this->login_action_label($event->action):'Выход из кабинета';?></strong><small><?php echo esc_html($event->details);?></small></div><time><?php echo esc_html($event->created_at);?></time><code><?php echo esc_html($event->ip);?></code></article><?php endforeach;?></div><?php endif;?></section>
        <?php elseif ($section === 'logout'): ?>
            <a class="zau-union-button zau-cabinet-builder-logout" href="<?php echo esc_url(wp_logout_url(home_url('/')));?>">Выйти из личного кабинета</a>
        <?php endif;?>
        </div>
        <?php return ob_get_clean();
    }

    public function ajax_send_otp() {
        check_ajax_referer(self::NONCE,'nonce');
        $raw=trim((string)wp_unslash($_POST['destination']??'')); $parsed=$this->parse_destination($raw);
        if(!$parsed)wp_send_json_error(['message'=>'Введите корректный email или номер телефона.'],400);
        $hash=hash('sha256',$parsed['channel'].'|'.$parsed['value']); $settings=$this->settings();
        if(($parsed['channel']==='email' && empty($settings['auth_otp_email'])) || ($parsed['channel']==='phone' && empty($settings['auth_otp_phone'])))wp_send_json_error(['message'=>'Этот способ получения одноразового кода отключён.'],403);
        $rateKey='zau_otp_send_'.substr($hash,0,32); if(get_transient($rateKey))wp_send_json_error(['message'=>'Код уже отправлен. Подождите перед повторной отправкой.'],429);
        $ipKey='zau_otp_ip_'.md5($this->client_ip()); $ipCount=(int)get_transient($ipKey); if($ipCount>=15)wp_send_json_error(['message'=>'Слишком много запросов с этого устройства. Повторите позже.'],429);
        $purpose=sanitize_key((string)wp_unslash($_POST['purpose']??'login')); $isRegistration=$purpose==='register';
        $code=(string)wp_rand(100000,999999); $sent=false; $message=($isRegistration?'Код регистрации':'Код входа').' на '.wp_parse_url(home_url(),PHP_URL_HOST).': '.$code.'. Никому не сообщайте этот код.';
        if($parsed['channel']==='email'){$sent=wp_mail($parsed['value'],$isRegistration?'Код регистрации в профсоюзе':'Код входа в личный кабинет',$message);}
        else {$sent=$this->send_sms_webhook($parsed['value'],$code,$message);}
        if(!$sent)wp_send_json_error(['message'=>$parsed['channel']==='phone'?'SMS-шлюз не настроен или вернул ошибку. Можно использовать email либо настроить webhook.':'Не удалось отправить письмо. Проверьте SMTP сайта.'],500);
        global $wpdb; $wpdb->insert($this->otp_table,['destination_hash'=>$hash,'channel'=>$parsed['channel'],'code_hash'=>wp_hash_password($code),'attempts'=>0,'verified'=>0,'expires_at'=>gmdate('Y-m-d H:i:s',time()+max(60,(int)$settings['otp_expiry'])),'ip'=>$this->client_ip(),'created_at'=>current_time('mysql',true)]);
        set_transient($rateKey,1,max(30,(int)$settings['otp_resend'])); set_transient($ipKey,$ipCount+1,HOUR_IN_SECONDS);
        wp_send_json_success(['message'=>'Код отправлен. Он действует '.ceil((int)$settings['otp_expiry']/60).' мин.','channel'=>$parsed['channel']]);
    }

    public function ajax_verify_otp() {
        check_ajax_referer(self::NONCE,'nonce'); global $wpdb;
        $parsed=$this->parse_destination(trim((string)wp_unslash($_POST['destination']??''))); $code=preg_replace('/\D/','',(string)($_POST['code']??''));
        if(!$parsed||strlen($code)!==6)wp_send_json_error(['message'=>'Проверьте адрес/телефон и шестизначный код.'],400);
        $settings=$this->settings();
        if(($parsed['channel']==='email' && empty($settings['auth_otp_email'])) || ($parsed['channel']==='phone' && empty($settings['auth_otp_phone'])))wp_send_json_error(['message'=>'Этот способ входа отключён.'],403);
        $hash=hash('sha256',$parsed['channel'].'|'.$parsed['value']);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->otp_table} WHERE destination_hash=%s AND channel=%s AND verified=0 AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",$hash,$parsed['channel']));
        if(!$row)wp_send_json_error(['message'=>'Код не найден или срок его действия истёк. Получите новый код.'],400);
        if((int)$row->attempts>=(int)$settings['otp_max_attempts'])wp_send_json_error(['message'=>'Превышено количество попыток. Получите новый код.'],429);
        $wpdb->query($wpdb->prepare("UPDATE {$this->otp_table} SET attempts=attempts+1 WHERE id=%d",$row->id));
        if(!wp_check_password($code,$row->code_hash))wp_send_json_error(['message'=>'Неверный код.'],400);
        $wpdb->update($this->otp_table,['verified'=>1],['id'=>$row->id]);
        $intent=sanitize_key((string)wp_unslash($_POST['intent']??'login'));
        if(!in_array($intent,['login','register'],true))$intent='login';
        if($intent==='register'){
            $user=$this->find_or_create_user($parsed);
        } else {
            $user=$parsed['channel']==='email'?get_user_by('email',$parsed['value']):false;
            if(!$user && $parsed['channel']==='phone'){
                $users=get_users(['meta_key'=>'zau_phone','meta_value'=>$parsed['value'],'number'=>1,'count_total'=>false]);
                $user=$users?$users[0]:false;
            }
            if(!$user)wp_send_json_error(['message'=>'Аккаунт не найден. Выберите «Впервые здесь» и пройдите регистрацию.'],404);
        }
        if(is_wp_error($user))wp_send_json_error(['message'=>$user->get_error_message()],400);
        if(user_can($user,'manage_options') || user_can($user,ZAU_Certificate_PDF_Generator::CAP_MANAGE))wp_send_json_error(['message'=>'Для административной учётной записи используйте стандартный защищённый вход WordPress.'],403);
        $this->complete_front_login($user,true,'otp_login',($parsed['channel']==='email'?'Email':'Телефон').' · '.$this->device_summary(),wp_unslash($_POST['return_url']??''));
    }

    private function find_user_by_identifier($identifier) {
        $identifier=trim((string)$identifier);
        if($identifier==='')return false;
        if(is_email($identifier))return get_user_by('email',strtolower(sanitize_email($identifier)));
        $parsed=$this->parse_destination($identifier);
        if($parsed && $parsed['channel']==='phone'){$users=get_users(['meta_key'=>'zau_phone','meta_value'=>$parsed['value'],'number'=>1,'count_total'=>false]);return $users?$users[0]:false;}
        return get_user_by('login',sanitize_user($identifier,true));
    }

    private function reject_front_admin_login($user) {
        return $user && (user_can($user,'manage_options') || user_can($user,ZAU_Certificate_PDF_Generator::CAP_MANAGE));
    }

    private function front_login_redirect($requested='', $context='login') {
        $s=$this->settings();
        $cabinet=$this->cabinet_url();
        $force = $context === 'registration' ? !empty($s['redirect_after_registration']) : !empty($s['redirect_after_login']);
        if ($force) return $cabinet;
        $requested=esc_url_raw((string)$requested);
        return $requested?wp_validate_redirect($requested,home_url('/')):home_url('/');
    }

    private function pin_device_hash($token) {
        return hash_hmac('sha256', (string)$token, wp_salt('auth'));
    }

    private function pin_device_cookie_options($expires) {
        return [
            'expires'=>(int)$expires,
            'path'=>defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'=>defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'=>is_ssl(),
            'httponly'=>true,
            'samesite'=>'Lax',
        ];
    }

    private function set_pin_device_cookie($user_id, $reset_devices = false) {
        $user_id=absint($user_id);
        if(!$user_id || !get_user_meta($user_id,'zau_login_pin_hash',true) || headers_sent())return false;
        if($reset_devices)delete_user_meta($user_id,'zau_pin_devices');
        try{$token=bin2hex(random_bytes(32));}catch(Exception $e){$token=wp_generate_password(64,false,false);}
        $hash=$this->pin_device_hash($token);
        $devices=get_user_meta($user_id,'zau_pin_devices',true);
        if(!is_array($devices))$devices=[];
        $now=time();
        $devices[$hash]=['created'=>$now,'last_used'=>$now,'ua'=>substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']??''),0,190)];
        uasort($devices,function($a,$b){return (int)($b['last_used']??0)<=>(int)($a['last_used']??0);});
        $devices=array_slice($devices,0,8,true);
        update_user_meta($user_id,'zau_pin_devices',$devices);
        $days=max(30,min(3650,(int)$this->settings()['pin_device_days']));
        $expires=$now+$days*DAY_IN_SECONDS;
        setcookie(self::PIN_DEVICE_COOKIE,$user_id.'.'.$token,$this->pin_device_cookie_options($expires));
        $_COOKIE[self::PIN_DEVICE_COOKIE]=$user_id.'.'.$token;
        return true;
    }

    private function clear_pin_device_cookie() {
        if(!headers_sent())setcookie(self::PIN_DEVICE_COOKIE,'',$this->pin_device_cookie_options(time()-DAY_IN_SECONDS));
        unset($_COOKIE[self::PIN_DEVICE_COOKIE]);
    }

    private function pin_device_user($touch = true) {
        $raw=(string)($_COOKIE[self::PIN_DEVICE_COOKIE]??'');
        if(!preg_match('/^(\d+)\.([a-f0-9]{64})$/',$raw,$m))return false;
        $user_id=absint($m[1]); $token=$m[2];
        $user=get_user_by('id',$user_id);
        if(!$user || $this->reject_front_admin_login($user) || !get_user_meta($user_id,'zau_login_pin_hash',true)){$this->clear_pin_device_cookie();return false;}
        $devices=get_user_meta($user_id,'zau_pin_devices',true);
        if(!is_array($devices)){$this->clear_pin_device_cookie();return false;}
        $hash=$this->pin_device_hash($token);
        if(!isset($devices[$hash])){$this->clear_pin_device_cookie();return false;}
        if($touch){$devices[$hash]['last_used']=time();update_user_meta($user_id,'zau_pin_devices',$devices);}
        return $user;
    }

    public function maybe_bind_current_pin_device() {
        if(!is_user_logged_in() || headers_sent())return;
        $user=wp_get_current_user();
        if(!$user || !$user->ID || $this->reject_front_admin_login($user) || !get_user_meta($user->ID,'zau_login_pin_hash',true))return;
        $bound=$this->pin_device_user(false);
        if(!$bound || (int)$bound->ID!==(int)$user->ID)$this->set_pin_device_cookie($user->ID,false);
    }

    private function complete_front_login($user,$remember,$action,$details,$requested='') {
        if(!$user || is_wp_error($user))wp_send_json_error(['message'=>'Не удалось выполнить вход.'],400);
        if($this->reject_front_admin_login($user))wp_send_json_error(['message'=>'Для административной учётной записи используйте стандартный защищённый вход WordPress.'],403);
        wp_set_current_user($user->ID); wp_set_auth_cookie($user->ID,(bool)$remember,is_ssl()); do_action('wp_login',$user->user_login,$user);
        if(get_user_meta($user->ID,'zau_login_pin_hash',true))$this->set_pin_device_cookie($user->ID,false);
        $this->log($action,'user',$user->ID,$details);
        wp_send_json_success(['message'=>'Вход выполнен.','redirect'=>$this->front_login_redirect($requested)]);
    }

    private function validate_pin_value($pin) {
        $settings=$this->settings();
        $pin=preg_replace('/\D/','',(string)$pin);
        $min=max(4,(int)$settings['pin_min_length']); $max=max($min,(int)$settings['pin_max_length']);
        if(strlen($pin)<$min || strlen($pin)>$max)return new WP_Error('pin_length','PIN должен содержать от '.$min.' до '.$max.' цифр.');
        return $pin;
    }

    public function ajax_password_login() {
        check_ajax_referer(self::NONCE,'nonce');
        $settings=$this->settings(); if(empty($settings['auth_password_enabled']))wp_send_json_error(['message'=>'Вход по паролю отключён.'],403);
        $identifier=sanitize_text_field(wp_unslash($_POST['identifier']??'')); $password=(string)wp_unslash($_POST['password']??'');
        $user=$this->find_user_by_identifier($identifier);
        if(!$user || $password==='')wp_send_json_error(['message'=>'Неверный логин или пароль.'],400);
        $authenticated=wp_authenticate($user->user_login,$password);
        if(is_wp_error($authenticated))wp_send_json_error(['message'=>'Неверный логин или пароль.'],400);
        $remember=!empty($_POST['remember']);
        $this->complete_front_login($authenticated,$remember,'password_login','Логин и пароль · '.$this->device_summary(),wp_unslash($_POST['return_url']??''));
    }

    public function ajax_pin_login() {
        check_ajax_referer(self::NONCE,'nonce');
        $settings=$this->settings(); if(empty($settings['auth_pin_enabled']))wp_send_json_error(['message'=>'Вход по постоянному PIN отключён.'],403);
        $identifier=sanitize_text_field(wp_unslash($_POST['identifier']??'')); $pin=preg_replace('/\D/','',(string)wp_unslash($_POST['pin']??''));
        if(!empty($settings['pin_device_only'])){
            $user=$this->pin_device_user(false);
            if(!$user)wp_send_json_error(['message'=>'Это устройство ещё не привязано. Войдите один раз одноразовым кодом или паролем.'],409);
            $lockIdentity='device:'.$user->ID;
        }else{
            $user=$this->find_user_by_identifier($identifier);
            if(!$user)wp_send_json_error(['message'=>'Аккаунт не найден. Для новой регистрации выберите «Впервые здесь».'],404);
            $lockIdentity=strtolower($identifier);
        }
        $key='zau_pin_lock_'.md5($lockIdentity.'|'.$this->client_ip());
        $state=get_transient($key); if(is_array($state)&&!empty($state['locked']))wp_send_json_error(['message'=>'Слишком много попыток. Повторите вход позже или восстановите PIN по email.'],429);
        $hash=get_user_meta($user->ID,'zau_login_pin_hash',true);
        if(!$hash)wp_send_json_error(['message'=>'Для этого аккаунта постоянный PIN ещё не создан. Войдите по одноразовому коду, затем создайте PIN в анкете или восстановите его по email.'],409);
        if(!$pin || !wp_check_password($pin,$hash)){
            $attempts=is_array($state)?(int)($state['attempts']??0):0; $attempts++;
            $max=max(3,(int)$settings['pin_max_attempts']); $ttl=max(1,(int)$settings['pin_lock_minutes'])*MINUTE_IN_SECONDS;
            set_transient($key,['attempts'=>$attempts,'locked'=>$attempts>=$max],$ttl);
            wp_send_json_error(['message'=>'PIN не подходит. Проверьте цифры или нажмите «Не помню PIN».'],400);
        }
        delete_transient($key);
        $this->complete_front_login($user,true,'pin_login','Постоянный PIN · '.$this->device_summary(),wp_unslash($_POST['return_url']??''));
    }

    public function ajax_request_pin_reset() {
        check_ajax_referer(self::NONCE,'nonce'); global $wpdb;
        $settings=$this->settings(); if(empty($settings['auth_pin_enabled']) || empty($settings['auth_show_recovery']))wp_send_json_error(['message'=>'Восстановление PIN отключено.'],403);
        $email=strtolower(sanitize_email(wp_unslash($_POST['email']??''))); if(!is_email($email))wp_send_json_error(['message'=>'Введите корректный email.'],400);
        $rate='zau_pin_reset_send_'.md5($email.'|'.$this->client_ip()); if(get_transient($rate))wp_send_json_error(['message'=>'Письмо уже запрошено. Подождите перед повторной отправкой.'],429);
        $user=get_user_by('email',$email);
        if($user && !$this->reject_front_admin_login($user)){
            $code=(string)wp_rand(100000,999999); $hash=hash('sha256','pin_reset|'.$email);
            $sent=wp_mail($email,'Восстановление PIN личного кабинета','Код восстановления PIN: '.$code."\n\nКод действует ограниченное время. Если вы не запрашивали восстановление, проигнорируйте письмо.");
            if($sent)$wpdb->insert($this->otp_table,['destination_hash'=>$hash,'channel'=>'pin_reset','code_hash'=>wp_hash_password($code),'attempts'=>0,'verified'=>0,'expires_at'=>gmdate('Y-m-d H:i:s',time()+max(300,(int)$settings['pin_reset_expiry'])),'ip'=>$this->client_ip(),'created_at'=>current_time('mysql',true)]);
        }
        set_transient($rate,1,60);
        wp_send_json_success(['message'=>'Если аккаунт с таким email существует, код восстановления отправлен.']);
    }

    public function ajax_reset_pin() {
        check_ajax_referer(self::NONCE,'nonce'); global $wpdb;
        $settings=$this->settings(); if(empty($settings['auth_pin_enabled']) || empty($settings['auth_show_recovery']))wp_send_json_error(['message'=>'Восстановление PIN отключено.'],403);
        $email=strtolower(sanitize_email(wp_unslash($_POST['email']??''))); $code=preg_replace('/\D/','',(string)wp_unslash($_POST['code']??''));
        $pin=$this->validate_pin_value(wp_unslash($_POST['pin']??'')); if(is_wp_error($pin))wp_send_json_error(['message'=>$pin->get_error_message()],400);
        $confirm=preg_replace('/\D/','',(string)wp_unslash($_POST['confirm']??'')); if($pin!==$confirm)wp_send_json_error(['message'=>'PIN и подтверждение не совпадают.'],400);
        if(!is_email($email)||strlen($code)!==6)wp_send_json_error(['message'=>'Проверьте email и код восстановления.'],400);
        $hash=hash('sha256','pin_reset|'.$email);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->otp_table} WHERE destination_hash=%s AND channel='pin_reset' AND verified=0 AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",$hash));
        if(!$row)wp_send_json_error(['message'=>'Код восстановления не найден или истёк.'],400);
        if((int)$row->attempts>=max(3,(int)$settings['otp_max_attempts']))wp_send_json_error(['message'=>'Превышено количество попыток. Запросите новый код.'],429);
        $wpdb->query($wpdb->prepare("UPDATE {$this->otp_table} SET attempts=attempts+1 WHERE id=%d",$row->id));
        if(!wp_check_password($code,$row->code_hash))wp_send_json_error(['message'=>'Неверный код восстановления.'],400);
        $user=get_user_by('email',$email); if(!$user || $this->reject_front_admin_login($user))wp_send_json_error(['message'=>'Не удалось восстановить PIN.'],400);
        update_user_meta($user->ID,'zau_login_pin_hash',wp_hash_password($pin)); update_user_meta($user->ID,'zau_pin_set_at',current_time('mysql')); delete_user_meta($user->ID,'zau_pin_devices');
        $wpdb->update($this->otp_table,['verified'=>1],['id'=>$row->id]);
        $this->complete_front_login($user,true,'pin_reset_login','Восстановление PIN по email · '.$this->device_summary(),wp_unslash($_POST['return_url']??''));
    }

    private function parse_destination($raw) {
        if(is_email($raw))return ['channel'=>'email','value'=>strtolower(sanitize_email($raw))];
        $digits=preg_replace('/\D/','',$raw); if(strlen($digits)===10)$digits='7'.$digits; if(strlen($digits)===11 && $digits[0]==='8')$digits='7'.substr($digits,1); if(strlen($digits)===11 && $digits[0]==='7')return ['channel'=>'phone','value'=>'+'.$digits]; return null;
    }

    private function find_or_create_user($parsed) {
        if($parsed['channel']==='email')$user=get_user_by('email',$parsed['value']);
        else {$users=get_users(['meta_key'=>'zau_phone','meta_value'=>$parsed['value'],'number'=>1,'count_total'=>false]);$user=$users?$users[0]:false;}
        if($user)return $user;
        $base=$parsed['channel']==='email'?sanitize_user(strtok($parsed['value'],'@'),true):'phone_'.preg_replace('/\D/','',$parsed['value']); if($base==='')$base='member'; $login=$base; $i=1; while(username_exists($login)){$login=$base.'_'.$i++;}
        $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$parsed['channel']==='email'?$parsed['value']:'','display_name'=>$parsed['channel']==='email'?$parsed['value']:$parsed['value'],'role'=>'subscriber']);
        if(is_wp_error($uid))return $uid; if($parsed['channel']==='phone')update_user_meta($uid,'zau_phone',$parsed['value']); update_user_meta($uid,'zau_member_status','Регистрация не завершена'); return get_user_by('id',$uid);
    }

    private function send_sms_webhook($phone,$code,$message) {
        $s=$this->settings(); if(empty($s['sms_webhook_url']))return false; $headers=['Content-Type'=>'application/json']; if(!empty($s['sms_webhook_token']))$headers['Authorization']='Bearer '.$s['sms_webhook_token'];
        $response=wp_remote_post($s['sms_webhook_url'],['timeout'=>15,'redirection'=>2,'headers'=>$headers,'body'=>wp_json_encode(['phone'=>$phone,'code'=>$code,'message'=>$message],JSON_UNESCAPED_UNICODE)]); if(is_wp_error($response))return false; $status=wp_remote_retrieve_response_code($response); return $status>=200&&$status<300;
    }

    public function ajax_lookup_bin() {
        check_ajax_referer(self::NONCE,'nonce');
        $bin=$this->normalize_bin($_POST['bin']??'');
        if(strlen($bin)!==12)wp_send_json_error(['message'=>'БИН или ИИН должен содержать ровно 12 цифр.'],400);
        $lookupError=null;
        $org=$this->lookup_organization($bin,$lookupError);
        if(!$org){
            $settings=$this->settings();
            if($lookupError instanceof WP_Error){
                $message=$lookupError->get_error_message().' Данные организации можно заполнить вручную.';
                $status=502;
            }elseif(empty($settings['dadata_enabled']) || empty($settings['dadata_token'])){
                $message='Внешний поиск по БИН/ИИН не настроен. Включите DaData и укажите API-ключ либо добавьте организацию во внутренний справочник. Пока данные можно заполнить вручную.';
                $status=503;
            }else{
                $message='По указанному БИН/ИИН организация или ИП не найдены. Проверьте номер либо заполните данные вручную.';
                $status=404;
            }
            wp_send_json_error(['message'=>$message,'manual'=>1],$status);
        }
        wp_send_json_success($org);
    }

    public function ajax_suggest_party_kz() {
        check_ajax_referer(self::NONCE,'nonce');
        $settings=$this->settings();
        if(empty($settings['dadata_enabled']) || empty($settings['dadata_token']))wp_send_json_error(['message'=>'Поиск DaData не настроен.'],503);
        $query=trim(sanitize_text_field(wp_unslash($_POST['query']??'')));
        $length=function_exists('mb_strlen')?mb_strlen($query,'UTF-8'):strlen($query);
        if($length<max(3,(int)$settings['dadata_min_chars']))wp_send_json_success(['suggestions'=>[]]);
        if($length>100)wp_send_json_error(['message'=>'Слишком длинный запрос.'],400);
        if(!$this->allow_bin_api_request('suggest',60,MINUTE_IN_SECONDS))wp_send_json_error(['message'=>'Слишком много запросов. Повторите через минуту.'],429);
        $response=$this->dadata_party_request('suggest',$query,10);
        if(is_wp_error($response))wp_send_json_error(['message'=>$response->get_error_message()],502);
        $items=[];
        foreach($response as $suggestion){$org=$this->map_dadata_suggestion($suggestion);if($org)$items[]=$org;}
        wp_send_json_success(['suggestions'=>$items]);
    }

    private function normalize_bin($value){return substr(preg_replace('/\D/','',(string)$value),0,12);}

    private function lookup_organization($bin,&$lookupError=null) {
        global $wpdb;
        $lookupError=null;
        $settings=$this->settings();
        if(!empty($settings['dadata_enabled']) && !empty($settings['dadata_token'])){
            $response=$this->dadata_party_request('find',$bin,1);
            if(is_wp_error($response)){$lookupError=$response;}
            elseif(!empty($response[0])){
                $org=$this->map_dadata_suggestion($response[0]);
                if($org){$this->upsert_organization($org+['source'=>'dadata']);$org['source']='dadata';return $org;}
            }
        }
        $local=$wpdb->get_row($wpdb->prepare("SELECT bin,name,director,address,region,source FROM {$this->orgs_table} WHERE bin=%s",$bin),ARRAY_A);
        if($local)return $local;
        if(empty($settings['bin_api_url']))return null;
        $url=str_replace('{bin}',rawurlencode($bin),$settings['bin_api_url']);
        if(!wp_http_validate_url($url))return null;
        $headers=json_decode((string)$settings['bin_api_headers'],true); if(!is_array($headers))$headers=[];
        $response=wp_remote_get($url,['timeout'=>15,'redirection'=>2,'headers'=>$headers]);
        if(is_wp_error($response)){$lookupError=new WP_Error('bin_api_connection','Не удалось подключиться к внешнему сервису поиска: '.$response->get_error_message());return null;}
        $apiStatus=(int)wp_remote_retrieve_response_code($response);
        if($apiStatus<200||$apiStatus>=300){$lookupError=new WP_Error('bin_api_http','Внешний сервис поиска вернул ошибку HTTP '.$apiStatus.'.');return null;}
        $json=json_decode(wp_remote_retrieve_body($response),true); if(!is_array($json))return null;
        $root=$settings['bin_api_data_path']?$this->array_path($json,$settings['bin_api_data_path']):$json; if(!is_array($root))return null;
        $org=['bin'=>$bin,'name'=>(string)$this->array_path($root,$settings['bin_api_name_path']),'director'=>(string)$this->array_path($root,$settings['bin_api_director_path']),'address'=>(string)$this->array_path($root,$settings['bin_api_address_path']),'region'=>(string)$this->array_path($root,$settings['bin_api_region_path'])];
        if(trim($org['name'])==='')return null;
        $org=array_map('sanitize_text_field',$org); $this->upsert_organization($org+['source'=>'api']); return $org;
    }

    private function dadata_party_request($method,$query,$count=10) {
        $settings=$this->settings();
        if(empty($settings['dadata_token']))return new WP_Error('dadata_token','Не указан API-ключ DaData.');
        $method=$method==='find'?'find':'suggest';
        $cacheKey='zau_dd_kz_'.md5($method.'|'.$query.'|'.($settings['dadata_language']??'ru').'|'.$count);
        $cached=get_transient($cacheKey);
        if(is_array($cached))return $cached;
        $endpoint=$method==='find'?'findById':'suggest';
        $url='https://suggestions.dadata.ru/suggestions/api/4_1/rs/'.$endpoint.'/party_kz';
        $payload=['query'=>$query]; if($method==='suggest')$payload['count']=max(1,min(10,(int)$count));
        $response=wp_remote_post($url,['timeout'=>18,'redirection'=>0,'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Token '.$settings['dadata_token']],'body'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE)]);
        if(is_wp_error($response))return new WP_Error('dadata_connection','Не удалось подключиться к DaData: '.$response->get_error_message());
        $status=(int)wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response); $json=json_decode($body,true);
        if($status<200||$status>=300){$message=is_array($json)&&!empty($json['message'])?sanitize_text_field($json['message']):'HTTP '.$status;return new WP_Error('dadata_http','DaData вернула ошибку: '.$message);}
        $suggestions=is_array($json)&&isset($json['suggestions'])&&is_array($json['suggestions'])?$json['suggestions']:[];
        set_transient($cacheKey,$suggestions,max(HOUR_IN_SECONDS,min(WEEK_IN_SECONDS,(int)$settings['dadata_cache_hours']*HOUR_IN_SECONDS)));
        return $suggestions;
    }

    private function map_dadata_suggestion($suggestion) {
        if(!is_array($suggestion))return null; $data=is_array($suggestion['data']??null)?$suggestion['data']:[];
        $bin=$this->normalize_bin($data['bin']??''); if(strlen($bin)!==12)return null;
        $preferKz=($this->settings()['dadata_language']??'ru')==='kz';
        $nameRu=sanitize_text_field($data['name_ru']??''); $nameKz=sanitize_text_field($data['name_kz']??'');
        $addressRu=sanitize_text_field($data['address_ru']??''); $addressKz=sanitize_text_field($data['address_kz']??'');
        $settlementRu=sanitize_text_field($data['address_settlement_ru']??''); $settlementKz=sanitize_text_field($data['address_settlement_kz']??'');
        $name=$preferKz?($nameKz?:$nameRu):($nameRu?:$nameKz); $address=$preferKz?($addressKz?:$addressRu):($addressRu?:$addressKz); $region=$preferKz?($settlementKz?:$settlementRu):($settlementRu?:$settlementKz);
        if($name==='')$name=sanitize_text_field($suggestion['value']??''); if($name==='')return null;
        $statusCode=sanitize_key($data['status']??''); $statusLabels=['active'=>'Действующая','inactive'=>'Бездействующая','liquidating'=>'Ликвидируется','liquidated'=>'Ликвидирована','suspended'=>'Деятельность приостановлена'];
        $typeCode=sanitize_key($data['type']??''); $typeLabels=['legal'=>'Юридическое лицо','branch'=>'Филиал','individual'=>'ИП','individual_joint_venture'=>'Совместное ИП','foreign_branch'=>'Филиал иностранного юридического лица'];
        $registration=$data['registration_date']??''; if(is_numeric($registration)){ $timestamp=(int)$registration; if($timestamp>20000000000)$timestamp=(int)floor($timestamp/1000); $registration=$timestamp>0?wp_date('d.m.Y',$timestamp):''; } else $registration=sanitize_text_field($registration);
        return [
            'bin'=>$bin,'name'=>$name,'name_ru'=>$nameRu,'name_kz'=>$nameKz,'director'=>sanitize_text_field($data['fio']??''),'address'=>$address,'address_ru'=>$addressRu,'address_kz'=>$addressKz,'region'=>$region,
            'status'=>$statusLabels[$statusCode]??strtoupper($statusCode),'status_code'=>strtoupper($statusCode),'type'=>$typeLabels[$typeCode]??strtoupper($typeCode),'type_code'=>strtoupper($typeCode),'registration_date'=>$registration,
            'oked'=>sanitize_text_field($data['oked']??''),'oked_name'=>sanitize_text_field(($preferKz?($data['oked_name_kz']??''):($data['oked_name_ru']??''))?:($data['oked_name_ru']??($data['oked_name_kz']??''))),'kato'=>sanitize_text_field($data['kato']??''),'value'=>sanitize_text_field($suggestion['value']??($name.' — '.$bin))
        ];
    }

    private function merge_organization_data($data,$org) {
        $map=['organization_bin'=>'bin','organization'=>'name','organization_name_ru'=>'name_ru','organization_name_kz'=>'name_kz','organization_director'=>'director','organization_address'=>'address','organization_address_ru'=>'address_ru','organization_address_kz'=>'address_kz','organization_status'=>'status','organization_status_code'=>'status_code','organization_type'=>'type','organization_type_code'=>'type_code','organization_registration_date'=>'registration_date','organization_oked'=>'oked','organization_oked_name'=>'oked_name','organization_kato'=>'kato'];
        foreach($map as $field=>$source){if(isset($org[$source]) && $org[$source]!=='')$data[$field]=sanitize_text_field((string)$org[$source]);}
        if(empty($data['region'])&&!empty($org['region']))$data['region']=sanitize_text_field($org['region']); return $data;
    }

    private function allow_bin_api_request($scope,$limit,$ttl) {
        $key='zau_bin_rate_'.md5($scope.'|'.$this->client_ip()); $count=(int)get_transient($key); if($count>=$limit)return false; set_transient($key,$count+1,$ttl); return true;
    }

    private function array_path($array,$path) { if($path==='')return $array; foreach(explode('.',$path) as $part){if(!is_array($array)||!array_key_exists($part,$array))return ''; $array=$array[$part];} return $array; }

    private function direct_registration_identity($data) {
        $email='';
        foreach(['email','user_email','contact_email'] as $key){if(!empty($data[$key])&&is_email($data[$key])){$email=strtolower(sanitize_email($data[$key]));break;}}
        $phone='';
        foreach(['phone','user_phone','contact_phone','mobile_phone'] as $key){if(empty($data[$key]))continue;$parsed=$this->parse_destination((string)$data[$key]);if($parsed&&$parsed['channel']==='phone'){$phone=$parsed['value'];break;}}
        return ['email'=>$email,'phone'=>$phone];
    }

    private function create_direct_registration_user($data) {
        $identity=$this->direct_registration_identity($data);
        if(!$identity['email']&&!$identity['phone'])return new WP_Error('direct_registration_contact','Укажите корректный email или номер телефона для создания личного кабинета.');
        if($identity['email']){
            $existing=get_user_by('email',$identity['email']);
            if($existing)return new WP_Error('direct_registration_exists','Аккаунт с таким email уже существует. Используйте раздел входа в личный кабинет.');
        }
        if($identity['phone']){
            $users=get_users(['meta_key'=>'zau_phone','meta_value'=>$identity['phone'],'number'=>1,'count_total'=>false]);
            if($users)return new WP_Error('direct_registration_exists','Аккаунт с таким номером телефона уже существует. Используйте раздел входа в личный кабинет.');
        }
        $first=sanitize_text_field($data['first_name']??'');$last=sanitize_text_field($data['last_name']??'');$middle=sanitize_text_field($data['middle_name']??'');
        $display=trim(implode(' ',array_filter([$last,$first,$middle])));
        if(!$display)$display=$identity['email']?:$identity['phone'];
        $base=$identity['email']?sanitize_user(strtok($identity['email'],'@'),true):'member_'.preg_replace('/\D/','',$identity['phone']);
        if($base==='')$base='member';$login=$base;$i=1;while(username_exists($login))$login=$base.'_'.($i++);
        $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$identity['email'],'display_name'=>$display,'first_name'=>$first,'last_name'=>$last,'role'=>'subscriber']);
        if(is_wp_error($uid))return $uid;
        if($identity['phone'])update_user_meta($uid,'zau_phone',$identity['phone']);
        update_user_meta($uid,'zau_member_status','Регистрация не завершена');
        update_user_meta($uid,'zau_registration_mode','direct');
        update_user_meta($uid,'zau_registered_at',current_time('mysql'));
        $user=get_user_by('id',$uid);
        if(!$user)return new WP_Error('direct_registration_user','Не удалось открыть созданный аккаунт.');
        wp_set_current_user($uid);wp_set_auth_cookie($uid,true,is_ssl());do_action('wp_login',$user->user_login,$user);
        $this->log('direct_registration','user',$uid,'Регистрация без подтверждающего кода · '.$this->device_summary());
        return $user;
    }

    private function validate_direct_registration_request() {
        $trap=trim((string)wp_unslash($_POST['zau_company_website']??''));
        $human=sanitize_key((string)wp_unslash($_POST['zau_human_form']??''));
        // Менеджеры паролей иногда ошибочно заполняют скрытое поле. Настоящая форма очищает его
        // и выставляет маркер взаимодействия перед отправкой, поэтому не создаём ложный отказ.
        if($trap!=='' && $human!=='1')return new WP_Error('registration_spam','Сработала защита формы от автоматической отправки. Обновите страницу и нажмите кнопку регистрации вручную.',['field'=>'_form']);
        $started=absint($_POST['zau_started_at']??0);$elapsed=$started?time()-$started:0;
        if($elapsed<2)return new WP_Error('registration_fast','Форма отправлена слишком быстро. Подождите несколько секунд и повторите.',['field'=>'_form']);
        if($elapsed>DAY_IN_SECONDS)return new WP_Error('registration_expired','Страница регистрации устарела. Обновите её и заполните форму повторно.',['field'=>'_form']);
        $key='zau_direct_registration_v2_'.md5($this->client_ip());$count=(int)get_transient($key);
        if($count>=100)return new WP_Error('registration_rate','С этого устройства уже выполнено более 100 успешных регистраций за час. Повторите позже или обратитесь к администратору.',['field'=>'_form']);
        return true;
    }

    private function record_direct_registration_success() {
        $key='zau_direct_registration_v2_'.md5($this->client_ip());
        $count=(int)get_transient($key);
        set_transient($key,$count+1,HOUR_IN_SECONDS);
    }

    private function registration_json_error($message,$field='',$code='registration_error',$status=400,$extra=[]) {
        $payload=array_merge(['message'=>(string)$message,'field'=>(string)$field,'code'=>(string)$code],is_array($extra)?$extra:[]);
        wp_send_json_error($payload,(int)$status);
    }

    public function ajax_refresh_nonce() {
        nocache_headers();
        wp_send_json_success([
            'nonce' => wp_create_nonce(self::NONCE),
            'logged_in' => is_user_logged_in() ? 1 : 0,
        ]);
    }

    public function ajax_submit_form() {
        check_ajax_referer(self::NONCE,'nonce');
        global $wpdb;
        $settings=$this->settings();
        $directGuest=!is_user_logged_in() && sanitize_key((string)$settings['registration_mode'])==='direct' && !empty($_POST['zau_direct_registration']);
        if(!is_user_logged_in()&&!$directGuest)$this->registration_json_error('Сначала войдите в личный кабинет.','_form','login_required',401);
        if($directGuest){$requestCheck=$this->validate_direct_registration_request();if(is_wp_error($requestCheck)){$errorData=$requestCheck->get_error_data();$this->registration_json_error($requestCheck->get_error_message(),is_array($errorData)?($errorData['field']??'_form'):'_form',$requestCheck->get_error_code(),400);}}
        $form=$this->get_form(absint($_POST['form_id']??0));
        if(!$form||!$form->active)$this->registration_json_error('Форма не найдена или отключена. Обновите страницу.','_form','form_not_found',404);
        $fields=$this->decode_form_fields($form->fields_json);$data=[];$signatureData=[];
        foreach($fields as $field){
            $key=$field['key'];$type=$field['type'];if($type==='heading')continue;$raw=wp_unslash($_POST[$key]??'');$rawText=is_scalar($raw)?trim((string)$raw):'';
            if($type==='signature'){
                $uploadKey='zau_signature_file__'.$key;
                $signatureData[$key]=(!empty($_FILES[$uploadKey])&&is_array($_FILES[$uploadKey]))?['upload'=>$_FILES[$uploadKey]]:(string)$raw;
                $value='';
            }
            elseif($type==='checkbox')$value=empty($raw)?'':'1';
            elseif($type==='email'){
                if($rawText!==''&&!is_email($rawText))$this->registration_json_error('Введите корректный email, например name@example.kz.',$key,'invalid_email',400);
                $value=strtolower(sanitize_email($rawText));
            }
            elseif($type==='phone'){
                $parsedPhone=$this->parse_destination($rawText);
                if($rawText!==''&&(!$parsedPhone||$parsedPhone['channel']!=='phone'))$this->registration_json_error('Введите корректный номер телефона Казахстана.',$key,'invalid_phone',400);
                $value=$parsedPhone&&$parsedPhone['channel']==='phone'?$parsedPhone['value']:'';
            }
            elseif($type==='textarea')$value=sanitize_textarea_field($raw);
            elseif($type==='number'){
                if($rawText!==''&&!is_numeric($rawText))$this->registration_json_error('В этом поле разрешено только число.',$key,'invalid_number',400);
                $value=is_numeric($rawText)?(string)$rawText:'';
            }
            elseif($type==='bin_lookup'){
                $value=$this->normalize_bin($rawText);
                if($rawText!==''&&strlen($value)!==12)$this->registration_json_error('БИН или ИИН должен содержать ровно 12 цифр.',$key,'invalid_bin',400);
            }
            elseif($type==='branch_select'){
                $value=(string)absint($rawText);
                if($value!==''&&(int)$value>0){$branchCheck=$this->get_branch((int)$value);if(!$branchCheck||empty($branchCheck->active))$this->registration_json_error('Выбранный филиал недоступен. Выберите филиал ещё раз.',$key,'invalid_branch',400);}
            }
            else$value=sanitize_text_field($raw);
            $signatureMissing=$type==='signature' && (empty($signatureData[$key]) || (is_array($signatureData[$key]) && empty($signatureData[$key]['upload']['tmp_name'])));
            if($field['required'] && (($type==='signature' && $signatureMissing) || ($type!=='signature' && $value==='')))$this->registration_json_error('Заполните обязательное поле «'.$field['label'].'».',$key,'required_field',400);
            if($type!=='signature')$data[$key]=$value;
        }
        // Проверяем PIN до создания WordPress-пользователя, чтобы ошибка в PIN не оставляла незавершённый аккаунт.
        $pinMode=sanitize_key((string)wp_unslash($_POST['zau_pin_setup_mode']??'off'));if(!in_array($pinMode,['off','optional','required'],true))$pinMode='off';
        $globalPinMode=sanitize_key((string)$settings['pin_setup_mode']);if($globalPinMode==='required')$pinMode='required';
        $pinRaw=preg_replace('/\D/','',(string)wp_unslash($_POST['zau_login_pin']??''));$pinConfirm=preg_replace('/\D/','',(string)wp_unslash($_POST['zau_login_pin_confirm']??''));$pinToSet='';
        $currentUserId=get_current_user_id();$hasPin=$currentUserId?(bool)get_user_meta($currentUserId,'zau_login_pin_hash',true):false;
        if($pinRaw!==''||$pinConfirm!==''){$validated=$this->validate_pin_value($pinRaw);if(is_wp_error($validated))$this->registration_json_error($validated->get_error_message(),'zau_login_pin','invalid_pin',400);if($validated!==$pinConfirm)$this->registration_json_error('PIN и подтверждение не совпадают.','zau_login_pin_confirm','pin_mismatch',400);$pinToSet=$validated;}
        elseif($pinMode==='required'&&!$hasPin)$this->registration_json_error('Создайте постоянный PIN для входа в личный кабинет.','zau_login_pin','pin_required',400);
        if($directGuest){
            $user=$this->create_direct_registration_user($data);
            if(is_wp_error($user)){
                $errorCode=$user->get_error_code();$errorField='_form';
                if($errorCode==='direct_registration_contact'){$errorField='email';foreach($fields as $candidate){if(in_array($candidate['type'],['email','phone'],true)){$errorField=$candidate['key'];break;}}}
                elseif($errorCode==='direct_registration_exists'){$identity=$this->direct_registration_identity($data);$errorField=$identity['email']?'email':'phone';foreach($fields as $candidate){if(($identity['email']&&$candidate['type']==='email')||(!$identity['email']&&$candidate['type']==='phone')){$errorField=$candidate['key'];break;}}}
                $this->registration_json_error($user->get_error_message(),$errorField,$errorCode,409);
            }
            $this->record_direct_registration_success();
        }
        $userId=get_current_user_id();
        if(!$userId)$this->registration_json_error('Не удалось создать или открыть личный кабинет. Повторите отправку.','_form','user_session_failed',500);
        $selectedBranchId=0;foreach($fields as $field){if($field['type']==='branch_select'){$selectedBranchId=absint($_POST[$field['key']]??0);break;}}
        $currentUser=get_userdata($userId);$fullName=trim(implode(' ',array_filter([$data['last_name']??'',$data['first_name']??'',$data['middle_name']??''])));if(!$fullName)$fullName=sanitize_text_field($data['full_name']??($currentUser?$currentUser->display_name:''));
        $data['full_name']=$fullName;$data['member_name_header']=$fullName;$data['submission_date']=wp_date('d.m.Y');$data['issue_date']=$data['submission_date'];$data['member_status']=get_user_meta($userId,'zau_member_status',true)?:'Заявление подано';
        if(!empty($data['organization_bin'])){$org=$this->lookup_organization($data['organization_bin']);if($org)$data=$this->merge_organization_data($data,$org);}
        if($selectedBranchId){$branchRow=$this->get_branch($selectedBranchId);if($branchRow&&!empty($branchRow->active)){$branchData=$this->branch_data($branchRow);$data=array_merge($data,$branchData);$data['branch_snapshot_full_details']=$branchData['branch_full_details']??'';$data['branch_snapshot_identity_details']=$branchData['branch_identity_details']??'';$data['branch_snapshot_bank_details']=$branchData['branch_bank_details']??'';$data['branch_snapshot_name']=$branchData['branch_name']??'';$data['branch_snapshot_date']=wp_date('d.m.Y H:i');}}
        $now=current_time('mysql');$wpdb->insert($this->submissions_table,['form_id'=>(int)$form->id,'user_id'=>$userId,'status'=>'submitted','data_json'=>'{}','signature_urls_json'=>'{}','ip'=>$this->client_ip(),'created_at'=>$now,'updated_at'=>$now]);$submissionId=(int)$wpdb->insert_id;
        if(!$submissionId)$this->registration_json_error('Не удалось сохранить заявку в базе данных. Повторите отправку или сообщите администратору.','_form','submission_save_failed',500);
        $signatureUrls=[];foreach($signatureData as $key=>$signaturePayload){$url=is_array($signaturePayload)&&!empty($signaturePayload['upload'])?$this->save_signature_upload($signaturePayload['upload'],$submissionId,$key):$this->save_signature_image($signaturePayload,$submissionId,$key);if(is_wp_error($url))wp_send_json_error(['message'=>$url->get_error_message()],400);$signatureUrls[$key]=$url;$data[$key]=$url;}
        if(!empty($signatureUrls)){$first=reset($signatureUrls);$data['signature_url']=$first;}
        $wpdb->update($this->submissions_table,['data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'signature_urls_json'=>wp_json_encode($signatureUrls,JSON_UNESCAPED_UNICODE)],['id'=>$submissionId]);
        $this->update_user_from_submission($data);
        if($pinToSet!==''){update_user_meta($userId,'zau_login_pin_hash',wp_hash_password($pinToSet));update_user_meta($userId,'zau_pin_set_at',current_time('mysql'));delete_user_meta($userId,'zau_pin_devices');$this->set_pin_device_cookie($userId,false);}
        $documentData=$this->hydrate_document_data($data,$userId);$templates=$this->resolve_form_template_ids($form);$jobs=[];foreach($templates as $templateId){$job=$this->prepare_public_document($templateId,$submissionId,$documentData);if($job)$jobs[]=$job;}
        $this->log('union_submission_created','submission',$submissionId,$fullName);
        wp_send_json_success(['message'=>$jobs?'Регистрация завершена, заявка сохранена. Формируются документы.':'Регистрация завершена, заявка сохранена. PDF-шаблоны к форме пока не привязаны.','submission_id'=>$submissionId,'documents'=>$jobs,'data'=>$documentData,'registered_directly'=>$directGuest?1:0,'auto_redirect'=>($directGuest&&!empty($settings['redirect_after_registration']))?1:0,'redirect'=>$this->cabinet_url()]);
    }

    private function update_user_from_submission($data) {
        $uid=get_current_user_id(); $update=['ID'=>$uid]; if(!empty($data['first_name']))$update['first_name']=$data['first_name']; if(!empty($data['last_name']))$update['last_name']=$data['last_name']; if(!empty($data['full_name']))$update['display_name']=$data['full_name']; if(!empty($data['email'])&&is_email($data['email'])){$existing=email_exists($data['email']);if(!$existing||$existing==$uid)$update['user_email']=$data['email'];} wp_update_user($update);
        if(!empty($data['phone']))update_user_meta($uid,'zau_phone',$data['phone']);
        foreach($data as $key=>$value){if(is_scalar($value)&&strlen((string)$value)<10000)update_user_meta($uid,'zau_profile_'.$this->sanitize_field_key($key),(string)$value);}
        $declaredOrgName=sanitize_text_field((string)($data['organization']??''));
        $orgIdAssigned=0;
        if(!empty($data['organization_bin'])){
            $bin=preg_replace('/\D/','',(string)$data['organization_bin']);
            update_user_meta($uid,'zau_organization_bin',$bin);
            global $wpdb;
            $org=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->orgs_table} WHERE bin=%s LIMIT 1",$bin));
            if($org){
                if($declaredOrgName===''||$this->org_names_plausibly_match($declaredOrgName,$org->name)){
                    update_user_meta($uid,'zau_organization_id',(int)$org->id);
                    $orgIdAssigned=(int)$org->id;
                }
                // Names clearly differ: this BIN is legitimately shared by a
                // different institution (e.g. several facilities under one
                // health department). Do not misattribute this member to it —
                // fall back to matching/creating by name below instead.
            }
        }
        if($declaredOrgName!==''){
            update_user_meta($uid,'zau_organization_name',$declaredOrgName);
            if(!$orgIdAssigned){
                $orgId=$this->resolve_organization_by_name($declaredOrgName);
                if($orgId)update_user_meta($uid,'zau_organization_id',$orgId);
            }
        }
        update_user_meta($uid,'zau_member_status','Заявление подано');
        update_user_meta($uid,'zau_membership_approval_status','pending');
        update_user_meta($uid,'zau_membership_approval_date','');
        $card=$this->get_member_card_data($uid);
        foreach($this->member_card_fields() as $field){$key=$field['key'];if(isset($data[$key])&&is_scalar($data[$key])&&$data[$key]!==''&&empty($card[$key]))$card[$key]=(string)$data[$key];}
        update_user_meta($uid,'zau_member_card_data',wp_json_encode($card,JSON_UNESCAPED_UNICODE));
        update_user_meta($uid,'zau_member_card_review_status','pending');
    }

    private function save_signature_upload($file,$submissionId,$key) {
        if(!is_array($file))return new WP_Error('signature','Файл подписи не получен.');
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)return new WP_Error('signature','Не удалось загрузить подпись. Код ошибки: '.$error.'.');
        $tmp=(string)($file['tmp_name']??'');$bytes=(int)($file['size']??0);
        if($tmp===''||!is_uploaded_file($tmp))return new WP_Error('signature','Временный файл подписи недоступен.');
        if($bytes<100||$bytes>4*1024*1024)return new WP_Error('signature','Пустая или слишком большая подпись.');
        $png=@file_get_contents($tmp);if($png===false)return new WP_Error('signature','Не удалось прочитать подпись.');
        $size=@getimagesizefromstring($png);if(!$size||($size[2]??0)!==IMAGETYPE_PNG)return new WP_Error('signature','Подпись должна быть изображением PNG.');
        $up=wp_upload_dir();if(!empty($up['error']))return new WP_Error('upload',$up['error']);
        $sub='zau-signatures/'.wp_date('Y/m');$dir=trailingslashit($up['basedir']).$sub;if(!wp_mkdir_p($dir))return new WP_Error('upload','Не удалось создать папку подписей.');
        $name='signature-'.$submissionId.'-'.$this->sanitize_field_key($key).'-'.wp_generate_password(6,false,false).'.png';
        if(file_put_contents(trailingslashit($dir).$name,$png,LOCK_EX)===false)return new WP_Error('upload','Не удалось сохранить подпись.');
        return trailingslashit($up['baseurl']).$sub.'/'.$name;
    }

    private function save_signature_image($dataUrl,$submissionId,$key) {
        if(!preg_match('#^data:image/png;base64,(.+)$#s',(string)$dataUrl,$m))return new WP_Error('signature','Подпись не распознана. Нарисуйте её заново.'); $png=base64_decode(str_replace(' ','+',$m[1]),true); if(!$png||strlen($png)<100)return new WP_Error('signature','Пустое изображение подписи.'); if(strlen($png)>4*1024*1024)return new WP_Error('signature','Изображение подписи слишком большое.'); $size=@getimagesizefromstring($png); if(!$size||($size[2]??0)!==IMAGETYPE_PNG)return new WP_Error('signature','Неверный формат подписи.');
        $up=wp_upload_dir(); if(!empty($up['error']))return new WP_Error('upload',$up['error']); $sub='zau-signatures/'.wp_date('Y/m'); $dir=trailingslashit($up['basedir']).$sub; if(!wp_mkdir_p($dir))return new WP_Error('upload','Не удалось создать папку подписей.'); $name='signature-'.$submissionId.'-'.$this->sanitize_field_key($key).'-'.wp_generate_password(6,false,false).'.png'; if(file_put_contents(trailingslashit($dir).$name,$png,LOCK_EX)===false)return new WP_Error('upload','Не удалось сохранить подпись.'); return trailingslashit($up['baseurl']).$sub.'/'.$name;
    }

    private function prepare_public_document($templateId,$submissionId,$data) {
        global $wpdb;
        $templateId=absint($templateId); $submissionId=absint($submissionId);
        $tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id=%d",$templateId));
        if(!$tpl)return null;
        $existing=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->docs_table} WHERE source_submission_id=%d AND template_id=%d AND user_id=%d ORDER BY id DESC LIMIT 1",
            $submissionId,$templateId,get_current_user_id()
        ));
        if($existing){
            if(!empty($existing->pdf_url))return null;
            return $this->document_job($existing,$tpl,$data);
        }
        $token=bin2hex(random_bytes(24)); $now=current_time('mysql'); $signature=$data['signature_url']??($data['signature']??'');
        $row=['template_id'=>$templateId,'user_id'=>get_current_user_id(),'created_by'=>get_current_user_id(),'source_submission_id'=>$submissionId,'full_name'=>sanitize_text_field($data['full_name']??''),'document_title'=>$tpl->name,'organization'=>sanitize_text_field($data['organization']??''),'issue_date'=>sanitize_text_field($data['issue_date']??wp_date('d.m.Y')),'document_no'=>'PENDING-'.$token,'member_status'=>sanitize_text_field($data['member_status']??'Заявление подано'),'extra1'=>sanitize_textarea_field($data['extra1']??''),'extra2'=>sanitize_textarea_field($data['extra2']??''),'extra3'=>sanitize_textarea_field($data['extra3']??''),'extra4'=>sanitize_textarea_field($data['extra4']??''),'signature_url'=>esc_url_raw($signature),'signature2_url'=>'','stamp_url'=>'','data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'orientation'=>$tpl->orientation,'verify_token'=>$token,'record_status'=>'draft','created_at'=>$now,'updated_at'=>$now];
        if(!$wpdb->insert($this->docs_table,$row))return null;
        $documentId=(int)$wpdb->insert_id;
        $number=class_exists('ZAU_Certificate_PDF_Generator')?ZAU_Certificate_PDF_Generator::instance()->format_document_number($tpl,$documentId):$this->next_document_number($tpl);
        $wpdb->update($this->docs_table,['document_no'=>$number],['id'=>$documentId]);
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$documentId));
        return $doc?$this->document_job($doc,$tpl,$data):null;
    }

    private function document_job($row,$tpl,$data=[]) {
        if(!is_array($data)||!$data){$data=json_decode((string)$row->data_json,true)?:[];}
        $data=$this->hydrate_document_data($data,(int)$row->user_id);
        $signature=$data['signature_url']??($data['signature']??$row->signature_url??'');
        $data['document_no']=$row->document_no;
        $data['member_name_header']=$data['member_name_header']??($data['full_name']??$row->full_name);
        $data['submission_date']=$data['submission_date']??($data['issue_date']??$row->issue_date);
        if (empty($data['branch_requisites']) && !empty($data['branch_bank_details'])) { $data['branch_requisites']=$data['branch_bank_details']; }
        if (empty($data['branch_full_details']) && !empty($data['branch_snapshot_full_details'])) { $data['branch_full_details']=$data['branch_snapshot_full_details']; }
        $data['verify_url']=$this->verify_url($row->verify_token);
        $data['document_title']=$tpl->name?:$row->document_title;
        $data['signature_url']=$signature;
        $data['issue_date']=$data['issue_date']??$row->issue_date;
        $data['member_status']=$data['member_status']??$row->member_status;
        return ['id'=>(int)$row->id,'document_no'=>$row->document_no,'verify_url'=>$data['verify_url'],'template'=>$this->template_for_js($tpl),'values'=>$data];
    }

    public function ajax_cabinet_documents() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message'=>'Сессия входа завершена. Войдите снова.'], 401); }
        global $wpdb;
        $uid = get_current_user_id();
        $docs = $this->latest_user_documents($uid);
        $settings = wp_parse_args((array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS, []), ['owner_pdf_view'=>1,'cabinet_document_mode'=>'both']);
        $mode = in_array($settings['cabinet_document_mode'], ['popup','tab','both'], true) ? $settings['cabinet_document_mode'] : 'both';
        $can_view = !empty($settings['owner_pdf_view']);
        $controller = ZAU_Certificate_PDF_Generator::instance();
        $items = [];
        foreach ((array)$docs as $doc) {
            $doc = $this->normalize_document_for_display($doc, true);
            $items[] = [
                'id'=>(int)$doc->id,
                'title'=>sanitize_text_field($doc->template_name ?: $doc->document_title),
                'document_no'=>sanitize_text_field($doc->document_no),
                'issue_date'=>sanitize_text_field($doc->issue_date),
                'record_status'=>sanitize_key($doc->record_status),
                'status_label'=>$doc->record_status === 'active' ? 'Действует' : ($doc->record_status === 'revoked' ? (((string)$doc->member_status==='Выбыл из профсоюза')?'Отозван · выбыл из профсоюза':'Отозван') : 'Черновик'),
                'pdf_ready'=>!empty($doc->pdf_url),
                'pdf_url'=>$can_view && !empty($doc->pdf_url) ? $controller->secure_document_url($doc, 'pdf') : '',
                'image_url'=>$can_view && !empty($doc->image_url) ? $controller->secure_document_url($doc, 'image') : '',
                'revision'=>(int)($doc->file_revision ?? 0),
                'updated_at'=>sanitize_text_field($doc->updated_at),
            ];
        }
        nocache_headers();
        wp_send_json_success(['documents'=>$items,'mode'=>$mode,'can_view'=>$can_view,'generated_at'=>current_time('mysql')]);
    }

    public function ajax_prepare_submission_documents() {
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in())wp_send_json_error(['message'=>'Сессия входа завершена. Войдите снова.'],401);
        global $wpdb;
        $submissionId=absint($_POST['submission_id']??0);
        $submission=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->submissions_table} WHERE id=%d",$submissionId));
        if(!$submission)wp_send_json_error(['message'=>'Заявка не найдена.'],404);
        if((int)$submission->user_id!==get_current_user_id())wp_send_json_error(['message'=>'Нет доступа к этой заявке.'],403);
        $form=$this->get_form((int)$submission->form_id);
        if(!$form)wp_send_json_error(['message'=>'Форма этой заявки больше не найдена.'],404);
        $templateIds=$this->resolve_form_template_ids($form);
        if(!$templateIds)wp_send_json_error(['message'=>'Не создан ни один PDF-шаблон. Администратору нужно открыть «Профсоюз → Шаблоны», загрузить подложку заявления и сохранить шаблон.'],400);
        $data=json_decode((string)$submission->data_json,true)?:[];
        $data=$this->hydrate_document_data($data,(int)$submission->user_id);
        $jobs=[]; $ready=[];
        foreach($templateIds as $templateId){
            $existing=$wpdb->get_row($wpdb->prepare(
                "SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.source_submission_id=%d AND d.template_id=%d AND d.user_id=%d ORDER BY d.id DESC LIMIT 1",
                $submissionId,$templateId,get_current_user_id()
            ));
            if($existing&&!empty($existing->pdf_url)){
                $secure=class_exists('ZAU_Certificate_PDF_Generator')?ZAU_Certificate_PDF_Generator::instance()->secure_document_url($existing):''; $ready[]=['id'=>(int)$existing->id,'name'=>$existing->template_name?:$existing->document_title,'pdf_url'=>$secure,'document_no'=>$existing->document_no];
                continue;
            }
            $job=$this->prepare_public_document($templateId,$submissionId,$data);
            if($job)$jobs[]=$job;
        }
        if(!$jobs&&!$ready)wp_send_json_error(['message'=>'Не удалось подготовить документы. Проверьте шаблоны и журнал ошибок WordPress.'],500);
        wp_send_json_success(['message'=>$jobs?'Документы подготовлены к формированию.':'Все документы уже сформированы.','documents'=>$jobs,'ready'=>$ready,'submission_id'=>$submissionId]);
    }

    private function template_for_js($tpl) { $fields=json_decode((string)$tpl->fields_json,true);if(!is_array($fields))$fields=[];return ['id'=>(int)$tpl->id,'name'=>$tpl->name,'orientation'=>$tpl->orientation,'page_width'=>(int)$tpl->page_width,'page_height'=>(int)$tpl->page_height,'background_url'=>$tpl->background_url,'background_mode'=>$tpl->background_mode,'fields'=>$fields]; }

    public function ajax_finalize_document() {
        check_ajax_referer(self::NONCE,'nonce'); if(!is_user_logged_in())wp_send_json_error(['message'=>'Сессия входа завершена. Войдите снова.'],401); global $wpdb; $id=absint($_POST['document_id']??0); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$id)); if(!$row)wp_send_json_error(['message'=>'Документ не найден.'],404); if((int)$row->user_id!==get_current_user_id()||(int)$row->source_submission_id===0)wp_send_json_error(['message'=>'Нет доступа к документу.'],403); if($row->record_status!=='draft'&&$row->pdf_url){$secure=class_exists('ZAU_Certificate_PDF_Generator')?ZAU_Certificate_PDF_Generator::instance()->secure_document_url($row):'';wp_send_json_success(['pdf_url'=>$secure,'verify_url'=>$this->verify_url($row->verify_token)]);}
        $jpeg='';
        if(!empty($_FILES['image_file'])&&is_array($_FILES['image_file'])){
            $file=$_FILES['image_file'];$error=(int)($file['error']??UPLOAD_ERR_NO_FILE);$tmp=(string)($file['tmp_name']??'');$bytes=(int)($file['size']??0);
            if($error!==UPLOAD_ERR_OK)wp_send_json_error(['message'=>'Не удалось загрузить изображение документа. Код ошибки: '.$error.'.'],400);
            if($tmp===''||!is_uploaded_file($tmp))wp_send_json_error(['message'=>'Временный файл документа недоступен.'],400);
            if($bytes<1000||$bytes>15*1024*1024)wp_send_json_error(['message'=>'Повреждённые или слишком большие данные документа.'],400);
            $jpeg=@file_get_contents($tmp);if($jpeg===false)wp_send_json_error(['message'=>'Не удалось прочитать изображение документа.'],400);
        }else{
            $dataUrl=wp_unslash($_POST['image_data']??'');if(!preg_match('#^data:image/jpeg;base64,(.+)$#s',$dataUrl,$m))wp_send_json_error(['message'=>'Ожидалось JPEG-изображение документа.'],400);$jpeg=base64_decode(str_replace(' ','+',$m[1]),true);
        }
        if(!$jpeg||strlen($jpeg)<1000||strlen($jpeg)>15*1024*1024)wp_send_json_error(['message'=>'Повреждённые или слишком большие данные документа.'],400);$size=@getimagesizefromstring($jpeg);if(!$size||($size[2]??0)!==IMAGETYPE_JPEG)wp_send_json_error(['message'=>'Неверный формат изображения.'],400);
        $up=wp_upload_dir(); if(!empty($up['error']))wp_send_json_error(['message'=>$up['error']],500); $sub='zau-certificates/'.wp_date('Y/m');$dir=trailingslashit($up['basedir']).$sub;if(!wp_mkdir_p($dir))wp_send_json_error(['message'=>'Не удалось создать папку документов.'],500);$revision=max(1,(int)($row->file_revision??0)+1);$safe=sanitize_file_name($row->document_no.'-'.$id.'-r'.$revision);$jpgPath=trailingslashit($dir).$safe.'.jpg';$pdfPath=trailingslashit($dir).$safe.'.pdf';if(file_put_contents($jpgPath,$jpeg,LOCK_EX)===false)wp_send_json_error(['message'=>'Не удалось сохранить JPG.'],500);$memberStatus=(string)get_user_meta((int)$row->user_id,'zau_member_status',true);$isExited=$memberStatus==='Выбыл из профсоюза';$pdf=$isExited?$this->jpeg_to_exit_watermarked_pdf($jpeg,(int)$size[0],(int)$size[1]):$this->jpeg_to_pdf($jpeg,(int)$size[0],(int)$size[1]);if(file_put_contents($pdfPath,$pdf,LOCK_EX)===false){@unlink($jpgPath);wp_send_json_error(['message'=>'Не удалось сохранить PDF.'],500);} $base=trailingslashit($up['baseurl']).$sub;$jpgUrl=$base.'/'.$safe.'.jpg';$pdfUrl=$base.'/'.$safe.'.pdf';$docData=json_decode((string)$row->data_json,true);if(!is_array($docData))$docData=[];if($isExited){$docData['member_status']='Выбыл из профсоюза';$docData['profile__member_status']='Выбыл из профсоюза';$docData['document_revocation_reason']='Выбыл из профсоюза';$docData['document_revoked_at']=current_time('mysql');$docData['_zau_member_exit_revocation']=['active'=>1,'reason'=>'Выбыл из профсоюза','revoked_at'=>current_time('mysql'),'previous_record_status'=>'active','original_pdf_url'=>'','original_image_url'=>$jpgUrl,'original_revision'=>$revision,'watermarked_pdf_url'=>$pdfUrl,'watermarked_revision'=>$revision];}$updated=$wpdb->update($this->docs_table,['image_url'=>$jpgUrl,'pdf_url'=>$pdfUrl,'file_revision'=>$revision,'member_status'=>$memberStatus?:$row->member_status,'record_status'=>$isExited?'revoked':'active','revoked_at'=>$isExited?current_time('mysql'):null,'data_json'=>wp_json_encode($docData,JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')],['id'=>$id]);if($updated===false){@unlink($jpgPath);@unlink($pdfPath);wp_send_json_error(['message'=>'Не удалось обновить запись документа.'],500);}if(!empty($row->pdf_url)&&$row->pdf_url!==$pdfUrl)$this->delete_upload_url($row->pdf_url);if(!empty($row->image_url)&&$row->image_url!==$jpgUrl)$this->delete_upload_url($row->image_url);$fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$id));$this->log('union_document_created','document',$id,$row->document_no.' revision '.$revision);$controller=class_exists('ZAU_Certificate_PDF_Generator')?ZAU_Certificate_PDF_Generator::instance():null;$secure=$controller?$controller->secure_document_url($fresh,'pdf'):'';$imageSecure=$controller?$controller->secure_document_url($fresh,'image'):'';wp_send_json_success(['pdf_url'=>$secure,'image_url'=>$imageSecure,'verify_url'=>$this->verify_url($row->verify_token),'document_no'=>$row->document_no,'revision'=>$revision]);
    }

    private function next_document_number($tpl=null){
        if(class_exists('ZAU_Certificate_PDF_Generator'))return ZAU_Certificate_PDF_Generator::instance()->format_document_number($tpl);
        global $wpdb;$max=(int)$wpdb->get_var("SELECT MAX(id) FROM {$this->docs_table}");$settings=(array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS,[]);$prefix=sanitize_text_field($settings['prefix']??'ZAU');return $prefix.'-'.wp_date('Y').'-'.str_pad((string)($max+1),6,'0',STR_PAD_LEFT);
    }
    private function verify_url($token){$settings=(array)get_option(ZAU_Certificate_PDF_Generator::OPT_SETTINGS,[]);$page=absint($settings['verify_page_id']??0);$base=$page?get_permalink($page):home_url('/proverka-dokumenta/');return add_query_arg('zau_verify',rawurlencode($token),$base);}
    private function jpeg_to_exit_watermarked_pdf($jpeg,$pxW,$pxH){
        $landscape=$pxW>$pxH;
        $asset=dirname(__DIR__).'/assets/images/watermark-exit-'.($landscape?'landscape':'portrait').'.jpg';
        if(!is_file($asset))return $this->jpeg_to_pdf($jpeg,$pxW,$pxH);
        $wm=@file_get_contents($asset);$wmSize=$wm?@getimagesizefromstring($wm):false;
        if(!$wm||!$wmSize||($wmSize[2]??0)!==IMAGETYPE_JPEG)return $this->jpeg_to_pdf($jpeg,$pxW,$pxH);
        $w=$landscape?841.8898:595.2756;$h=$landscape?595.2756:841.8898;
        $objects=[];
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /XObject << /Im0 4 0 R /Wm0 5 0 R >> /ExtGState << /GS1 6 0 R >> >> /Contents 7 0 R >>";
        $objects[4]="<< /Type /XObject /Subtype /Image /Width $pxW /Height $pxH /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>
stream
".$jpeg."
endstream";
        $objects[5]="<< /Type /XObject /Subtype /Image /Width ".(int)$wmSize[0]." /Height ".(int)$wmSize[1]." /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($wm)." >>
stream
".$wm."
endstream";
        $objects[6]='<< /Type /ExtGState /ca 0.42 /CA 0.42 /BM /Multiply >>';
        $stream="q
$w 0 0 $h 0 0 cm
/Im0 Do
Q
q
/GS1 gs
$w 0 0 $h 0 0 cm
/Wm0 Do
Q
";
        $objects[7]="<< /Length ".strlen($stream)." >>
stream
$stream".'endstream';
        $pdf="%PDF-1.4
%âãÏÓ
";$offsets=[0];
        foreach($objects as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj
".$obj."
endobj
";}
        $xref=strlen($pdf);$pdf.="xref
0 8
0000000000 65535 f 
";
        for($i=1;$i<=7;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."
";
        $pdf.="trailer
<< /Size 8 /Root 1 0 R >>
startxref
$xref
%%EOF";
        return $pdf;
    }

    private function jpeg_to_pdf($jpeg,$pxW,$pxH){$landscape=$pxW>$pxH;$w=$landscape?841.8898:595.2756;$h=$landscape?595.2756:841.8898;$objects=[];$objects[1]="<< /Type /Catalog /Pages 2 0 R >>";$objects[2]="<< /Type /Pages /Kids [3 0 R] /Count 1 >>";$objects[3]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>";$objects[4]="<< /Type /XObject /Subtype /Image /Width $pxW /Height $pxH /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream";$stream="q\n$w 0 0 $h 0 0 cm\n/Im0 Do\nQ\n";$objects[5]="<< /Length ".strlen($stream)." >>\nstream\n$stream"."endstream";$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";$pdf.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";return $pdf;}

    private function organization_rows() {
        global $wpdb;
        return $wpdb->get_results("SELECT id,bin,name,region FROM {$this->orgs_table} ORDER BY name ASC LIMIT 5000");
    }

    private function managed_org_ids($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        $ids = get_user_meta((int)$user_id, 'zau_managed_org_ids', true);
        if (!is_array($ids)) { $ids = preg_split('/[,;\s]+/', (string)$ids); }
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    private function managed_branch_ids($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        $ids = get_user_meta((int)$user_id, 'zau_managed_branch_ids', true);
        if (!is_array($ids)) { $ids = preg_split('/[,;\s]+/', (string)$ids); }
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    private function normalize_audience_text($value) {
        $value=wp_strip_all_tags((string)$value);
        $value=trim(preg_replace('/\s+/u',' ',$value));
        if(function_exists('mb_strtolower'))$value=mb_strtolower($value,'UTF-8');else $value=strtolower($value);
        $value=str_replace(['ё','«','»','„','“','”','"',"'"],['е','','','','','','',''],$value);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
    }

    private function org_names_plausibly_match($a,$b) {
        $normA=$this->normalize_audience_text($a); $normB=$this->normalize_audience_text($b);
        if($normA===''||$normB==='')return false;
        if($normA===$normB)return true;
        $stop=['гккп','гкп','кгп','кгу','гу','ргп','ргу','тоо','ао','ип','на','праве','хозяйственного','ведения','оперативного','управления','акимата','города','коммунальное','государственное','предприятие','учреждение','общественное','объединение'];
        $tokA=array_values(array_diff(array_filter(explode(' ',$normA),function($w){return (function_exists('mb_strlen')?mb_strlen($w,'UTF-8'):strlen($w))>2;}),$stop));
        $tokB=array_values(array_diff(array_filter(explode(' ',$normB),function($w){return (function_exists('mb_strlen')?mb_strlen($w,'UTF-8'):strlen($w))>2;}),$stop));
        if(!$tokA||!$tokB)return false;
        $intersect=count(array_intersect($tokA,$tokB)); $union=count(array_unique(array_merge($tokA,$tokB)));
        return $union>0 && ($intersect/$union)>=0.6;
    }

    private function resolve_organization_by_name($name) {
        global $wpdb;
        $name=sanitize_text_field((string)$name); if($name==='')return 0;
        if(class_exists('ZAU_Remote_Profile_Repair')){
            $match=ZAU_Remote_Profile_Repair::instance()->resolve_organization_by_name($name,true,false);
            if(!empty($match['id']))return (int)$match['id'];
        }
        $exact=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->orgs_table} WHERE name=%s LIMIT 1",$name));
        if($exact)return $exact;
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->orgs_table} ORDER BY id ASC LIMIT 5000") as $row){
            if($this->org_names_plausibly_match($name,$row->name))return (int)$row->id;
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->orgs_table} (bin,name,director,address,region,source,updated_at) VALUES (NULL,%s,'','','','submission_name_only',%s)",
            $name,current_time('mysql')
        ));
        return (int)$wpdb->insert_id;
    }

    private function member_org_id($user_id) {
        $user_id=(int)$user_id;
        $orgId = absint(get_user_meta($user_id, 'zau_organization_id', true));
        if ($orgId) { return $orgId; }
        $bin = preg_replace('/\D/', '', (string)get_user_meta($user_id, 'zau_organization_bin', true));
        if (!$bin) { $bin = preg_replace('/\D/', '', (string)get_user_meta($user_id, 'zau_profile_organization_bin', true)); }
        global $wpdb;
        if($bin){
            $found=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->orgs_table} WHERE bin=%s LIMIT 1", $bin));
            if($found)return $found;
        }
        $name='';
        foreach(['zau_organization_name','zau_profile_organization','zau_organization','organization','company'] as $key){
            $value=trim((string)get_user_meta($user_id,$key,true));
            if($value!==''){$name=$value;break;}
        }
        if($name==='')return 0;
        $exact=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->orgs_table} WHERE name=%s LIMIT 1",$name));
        if($exact)return $exact;
        $needle=$this->normalize_audience_text($name);
        if($needle==='')return 0;
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->orgs_table} ORDER BY id ASC LIMIT 5000") as $row){
            if($this->normalize_audience_text($row->name)===$needle)return (int)$row->id;
        }
        return 0;
    }

    private function member_branch_id($user_id) {
        $user_id=(int)$user_id;
        foreach (['zau_profile_branch_id','zau_branch_id','zau_profile_branch'] as $key) {
            $value=get_user_meta($user_id,$key,true);
            if (is_numeric($value) && absint($value)) { return absint($value); }
        }
        $branchName='';
        foreach(['zau_profile_branch_name','zau_profile_branch','zau_branch_name','branch','filial'] as $key){
            $value=trim((string)get_user_meta($user_id,$key,true));
            if($value!==''){$branchName=$value;break;}
        }
        if($branchName==='')return 0;
        global $wpdb;
        $exact=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->branches_table} WHERE name=%s LIMIT 1",$branchName));
        if($exact)return $exact;
        $needle=$this->normalize_audience_text($branchName);
        if($needle==='')return 0;
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$this->branches_table} ORDER BY id ASC LIMIT 5000") as $row){
            if($this->normalize_audience_text($row->name)===$needle)return (int)$row->id;
        }
        return 0;
    }

    private function can_manage_member($manager_id, $member_id) {
        if ((int)$manager_id === (int)$member_id) { return false; }
        if (user_can($manager_id, ZAU_Certificate_PDF_Generator::CAP_MANAGE) || user_can($manager_id, 'manage_options')) { return true; }
        if (!user_can($manager_id, ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE)) { return false; }
        $orgId = $this->member_org_id($member_id);
        if ($orgId && in_array($orgId, $this->managed_org_ids($manager_id), true)) { return true; }
        $branchId = $this->member_branch_id($member_id);
        return $branchId && in_array($branchId, $this->managed_branch_ids($manager_id), true);
    }

    private function manager_member_ids($manager_id) {
        $orgIds = $this->managed_org_ids($manager_id);
        $branchIds = $this->managed_branch_ids($manager_id);
        if (!$orgIds && !$branchIds) { return []; }
        global $wpdb;
        $ids=[];
        if($orgIds){
            $placeholders = implode(',', array_fill(0, count($orgIds), '%d'));
            $sql = $wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_organization_id' AND CAST(meta_value AS UNSIGNED) IN ($placeholders)", $orgIds);
            $ids = array_map('absint', (array)$wpdb->get_col($sql));
            $orgSql = $wpdb->prepare("SELECT bin FROM {$this->orgs_table} WHERE id IN ($placeholders)", $orgIds);
            $bins = array_values(array_filter(array_map(function($v){ return preg_replace('/\D/','',(string)$v); }, (array)$wpdb->get_col($orgSql))));
            if ($bins) {
                $binPlaceholders = implode(',', array_fill(0, count($bins), '%s'));
                $metaSql = $wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('zau_organization_bin','zau_profile_organization_bin') AND meta_value IN ($binPlaceholders)", $bins);
                $ids = array_merge($ids, array_map('absint', (array)$wpdb->get_col($metaSql)));
            }
        }
        if($branchIds){
            $branchPh=implode(',',array_fill(0,count($branchIds),'%d'));
            $branchSql=$wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('zau_profile_branch_id','zau_branch_id','zau_profile_branch') AND CAST(meta_value AS UNSIGNED) IN ($branchPh)",$branchIds);
            $ids=array_merge($ids,array_map('absint',(array)$wpdb->get_col($branchSql)));
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function membership_statuses() {
        return ['Регистрация не завершена','Заявление подано','На рассмотрении','Состоит в профсоюзе','Членство приостановлено','Выбыл из профсоюза','Заявление отклонено'];
    }

    private function is_login_action($action) {
        return in_array((string)$action,['otp_login','pin_login','password_login','pin_reset_login'],true);
    }

    private function login_action_label($action) {
        $labels=['otp_login'=>'Вход по одноразовому коду','pin_login'=>'Вход по постоянному PIN','password_login'=>'Вход по логину и паролю','pin_reset_login'=>'Восстановление PIN и вход','logout'=>'Выход'];
        return $labels[(string)$action]??(string)$action;
    }

    public function user_profile_fields($user) {
        if(!current_user_can('edit_user',$user->ID))return;
        $status=get_user_meta($user->ID,'zau_member_status',true);
        $phone=get_user_meta($user->ID,'zau_phone',true);
        $orgId=$this->member_org_id($user->ID);$branchId=$this->member_branch_id($user->ID);
        $managed=$this->managed_org_ids($user->ID);$managedBranches=$this->managed_branch_ids($user->ID);
        $organizations=$this->organization_rows();$branches=$this->branch_rows(false);
        $canAssign=current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options'); ?>
        <h2>Профсоюз</h2><table class="form-table">
        <tr><th><label for="zau_phone">Телефон</label></th><td><input class="regular-text" id="zau_phone" name="zau_phone" value="<?php echo esc_attr($phone);?>"></td></tr>
        <tr><th><label for="zau_member_status">Статус членства</label></th><td><select id="zau_member_status" name="zau_member_status"><?php foreach($this->membership_statuses() as $option):?><option <?php selected($status,$option);?>><?php echo esc_html($option);?></option><?php endforeach;?></select><p class="description">Одобрение через кабинет ответственного автоматически устанавливает статус «Состоит в профсоюзе».</p></td></tr>
        <tr><th>Постоянный PIN</th><td><?php if(get_user_meta($user->ID,'zau_login_pin_hash',true)):?><strong style="color:#167044">Установлен</strong><br><label><input type="checkbox" name="zau_clear_login_pin" value="1"> Сбросить PIN, чтобы участник создал новый через восстановление по email</label><?php else:?><span>Не установлен</span><?php endif;?><p class="description">Сам PIN не хранится и не показывается. В базе находится только защищённый хеш.</p></td></tr>
        <?php if($canAssign):?>
        <tr><th><label for="zau_organization_id">Организация участника</label></th><td><select id="zau_organization_id" name="zau_organization_id" style="min-width:420px"><option value="0">— не назначена —</option><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected($orgId,$org->id);?>><?php echo esc_html($org->name.($org->bin?' · БИН '.$org->bin:' · БИН не указан'));?></option><?php endforeach;?></select></td></tr>
        <tr><th><label for="zau_branch_id">Филиал участника</label></th><td><select id="zau_branch_id" name="zau_branch_id" style="min-width:420px"><option value="0">— не назначен —</option><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected($branchId,$branch->id);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select></td></tr>
        <tr><th>Ответственный организации/филиала</th><td><label><input type="checkbox" name="zau_is_org_manager" value="1" <?php checked(user_can($user,ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE));?>> Разрешить управление участниками назначенных организаций и филиалов</label><h4>Организации</h4><select name="zau_managed_org_ids[]" multiple size="8" style="min-width:520px"><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected(in_array((int)$org->id,$managed,true),true);?>><?php echo esc_html($org->name.($org->bin?' · БИН '.$org->bin:' · БИН не указан'));?></option><?php endforeach;?></select><h4>Филиалы</h4><select name="zau_managed_branch_ids[]" multiple size="8" style="min-width:520px"><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected(in_array((int)$branch->id,$managedBranches,true),true);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select><p class="description">Ответственный увидит участников, совпадающих хотя бы по одной назначенной организации или филиалу.</p></td></tr>
        <?php endif;?>
        </table><?php
    }

    public function save_user_profile_fields($userId){
        if(!current_user_can('edit_user',$userId))return;
        update_user_meta($userId,'zau_phone',sanitize_text_field(wp_unslash($_POST['zau_phone']??'')));
        $status=sanitize_text_field(wp_unslash($_POST['zau_member_status']??''));
        if(in_array($status,$this->membership_statuses(),true))$this->set_member_status($userId,$status,'profile_edit');
        if(!empty($_POST['zau_clear_login_pin'])){delete_user_meta($userId,'zau_login_pin_hash');delete_user_meta($userId,'zau_pin_set_at');delete_user_meta($userId,'zau_pin_devices');}
        if(current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options')){
            $orgId=absint($_POST['zau_organization_id']??0);global $wpdb;
            if($orgId){update_user_meta($userId,'zau_organization_id',$orgId);$org=$wpdb->get_row($wpdb->prepare("SELECT bin,name FROM {$this->orgs_table} WHERE id=%d",$orgId));if($org){if(!empty($org->bin))update_user_meta($userId,'zau_organization_bin',$org->bin);else delete_user_meta($userId,'zau_organization_bin');update_user_meta($userId,'zau_organization_name',$org->name);}}
            else delete_user_meta($userId,'zau_organization_id');
            $branchId=absint($_POST['zau_branch_id']??0);if($branchId){$branch=$this->get_branch($branchId);update_user_meta($userId,'zau_profile_branch_id',$branchId);if($branch)update_user_meta($userId,'zau_profile_branch_name',$branch->name);}else delete_user_meta($userId,'zau_profile_branch_id');
            $managed=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['zau_managed_org_ids']??[])))));update_user_meta($userId,'zau_managed_org_ids',$managed);
            $managedBranches=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['zau_managed_branch_ids']??[])))));update_user_meta($userId,'zau_managed_branch_ids',$managedBranches);
            $wpUser=new WP_User($userId);if(!empty($_POST['zau_is_org_manager'])&&($managed||$managedBranches))$wpUser->add_cap(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE);else $wpUser->remove_cap(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE);
        }
    }

    private function organization_registry_url() {
        $settings = $this->settings();
        return !empty($settings['org_registry_page_id']) ? get_permalink((int)$settings['org_registry_page_id']) : home_url('/reestr-organizacii/');
    }

    private function require_org_registry_access() {
        if (!is_user_logged_in()) { auth_redirect(); }
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE) && !current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) && !current_user_can('manage_options')) {
            wp_die('У вас нет доступа к реестру организации.', 'Доступ запрещён', ['response'=>403]);
        }
    }

    private function allowed_registry_member_ids($manager_id = 0) {
        $manager_id = $manager_id ?: get_current_user_id();
        if (user_can($manager_id, ZAU_Certificate_PDF_Generator::CAP_MANAGE) || user_can($manager_id, 'manage_options')) {
            return array_map('absint', get_users(['fields'=>'ids','number'=>5000,'orderby'=>'display_name','order'=>'ASC','meta_query'=>[['key'=>'zau_hidden_from_registry','compare'=>'NOT EXISTS']]]));
        }
        return array_values(array_unique(array_filter(array_map('absint', $this->manager_member_ids($manager_id)))));
    }

    private function requested_registry_member_ids() {
        $raw = wp_unslash($_POST['member_ids'] ?? $_GET['member_ids'] ?? '');
        if (is_array($raw)) { $ids = $raw; }
        else { $ids = preg_split('/[,;\s]+/', (string)$raw); }
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    private function authorized_registry_member_ids($requested = []) {
        $allowed = $this->allowed_registry_member_ids(get_current_user_id());
        $requested = array_values(array_unique(array_filter(array_map('absint', (array)$requested))));
        return $requested ? array_values(array_intersect($requested, $allowed)) : $allowed;
    }

    private function organization_registry_rows($member_ids) {
        global $wpdb;
        $member_ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array)$member_ids)))), 0, 5000);
        if (!$member_ids) { return []; }
        $users = get_users(['include'=>$member_ids,'number'=>5000,'orderby'=>'display_name','order'=>'ASC','meta_query'=>[['key'=>'zau_hidden_from_registry','compare'=>'NOT EXISTS']]]);
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
        $submission_sql = $wpdb->prepare("SELECT user_id,MIN(created_at) registration_date,COUNT(*) submission_count FROM {$this->submissions_table} WHERE user_id IN ($placeholders) GROUP BY user_id", $member_ids);
        $submission_map = [];
        foreach ((array)$wpdb->get_results($submission_sql) as $item) { $submission_map[(int)$item->user_id] = $item; }
        $docs_sql = $wpdb->prepare("SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.user_id IN ($placeholders) ORDER BY d.user_id ASC,d.id DESC", $member_ids);
        $docs_map = [];
        foreach ((array)$wpdb->get_results($docs_sql) as $doc) { $docs_map[(int)$doc->user_id][] = $doc; }
        $org_ids = [];
        foreach ($users as $user) { $org_ids[] = $this->member_org_id($user->ID); }
        $org_ids = array_values(array_unique(array_filter(array_map('absint', $org_ids))));
        $org_map = [];
        if ($org_ids) {
            $org_ph = implode(',', array_fill(0, count($org_ids), '%d'));
            $org_sql = $wpdb->prepare("SELECT id,bin,name,region FROM {$this->orgs_table} WHERE id IN ($org_ph)", $org_ids);
            foreach ((array)$wpdb->get_results($org_sql) as $org) { $org_map[(int)$org->id] = $org; }
        }
        $controller = ZAU_Certificate_PDF_Generator::instance();
        $rows = [];
        foreach ($users as $user) {
            $org_id = $this->member_org_id($user->ID);
            $org = $org_map[$org_id] ?? null;
            $docs = [];
            foreach ((array)($docs_map[$user->ID] ?? []) as $doc) {
                $can_access = $controller->user_can_access_document($doc, true);
                $docs[] = [
                    'id'=>(int)$doc->id,
                    'name'=>(string)($doc->template_name ?: $doc->document_title),
                    'number'=>(string)$doc->document_no,
                    'date'=>(string)$doc->issue_date,
                    'status'=>(string)$doc->record_status,
                    'ready'=>!empty($doc->pdf_url),
                    'view_url'=>$can_access && $doc->pdf_url ? $controller->secure_document_url($doc, 'pdf', false) : '',
                    'download_url'=>$can_access && $doc->pdf_url ? $controller->secure_document_url($doc, 'pdf', true) : '',
                ];
            }
            $submission = $submission_map[$user->ID] ?? null;
            $registration_date = $submission && $submission->registration_date ? $submission->registration_date : $user->user_registered;
            $rows[] = [
                'user_id'=>(int)$user->ID,
                'full_name'=>(string)$user->display_name,
                'registration_date'=>(string)$registration_date,
                'registration_display'=>mysql2date('d.m.Y H:i', $registration_date),
                'organization_id'=>$org_id,
                'organization'=>(string)($org->name ?? get_user_meta($user->ID,'zau_organization_name',true)),
                'organization_bin'=>(string)($org->bin ?? get_user_meta($user->ID,'zau_organization_bin',true)),
                'phone'=>(string)get_user_meta($user->ID,'zau_phone',true),
                'email'=>(string)$user->user_email,
                'status'=>(string)(get_user_meta($user->ID,'zau_member_status',true) ?: 'Регистрация не завершена'),
                'submission_count'=>(int)($submission->submission_count ?? 0),
                'documents'=>$docs,
            ];
        }
        return $rows;
    }

    public function organization_registry_shortcode($atts = []) {
        $atts=shortcode_atts(['embedded'=>'no'],(array)$atts,'zau_union_org_registry');
        $embedded=$atts['embedded']==='yes';
        if (!is_user_logged_in()) { return $this->auth_shortcode(array_merge((array)$atts,['return_url'=>$this->organization_registry_url()])); }
        if (!current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE) && !current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE) && !current_user_can('manage_options')) {
            return '<div class="zau-form-error">У вас нет доступа к реестру организации.</div>';
        }
        $rows = $this->organization_registry_rows($this->allowed_registry_member_ids(get_current_user_id()));
        $organizations = []; $document_types = [];
        foreach ($rows as $row) {
            if ($row['organization_id']) { $organizations[$row['organization_id']] = $row['organization']; }
            foreach ($row['documents'] as $doc) { if ($doc['name']) { $document_types[$doc['name']] = $doc['name']; } }
        }
        asort($organizations); asort($document_types);
        ob_start(); ?>
        <div class="zau-org-registry<?php echo $embedded?' is-embedded':'';?>" data-zau-org-registry data-pdf-action="zau_union_export_org_registry_pdf">
            <?php if(!$embedded):?><div class="zau-org-registry-head"><div><h2>Реестр участников организации</h2><p>Доступны только участники организаций, назначенных вам администратором.</p></div><a class="zau-union-button zau-secondary-button" href="<?php echo esc_url($this->settings()['cabinet_page_id'] ? get_permalink((int)$this->settings()['cabinet_page_id']) : home_url('/lk-profsoyuz/')); ?>">Личный кабинет</a></div><?php endif;?>
            <div class="zau-org-registry-filters">
                <label>ФИО<input type="search" data-filter="name" placeholder="Введите ФИО"></label>
                <label>Дата от<input type="date" data-filter="date_from"></label>
                <label>Дата до<input type="date" data-filter="date_to"></label>
                <label>Организация<select data-filter="organization"><option value="">Все организации</option><?php foreach($organizations as $id=>$name):?><option value="<?php echo (int)$id; ?>"><?php echo esc_html($name); ?></option><?php endforeach;?></select></label>
                <label>Телефон<input type="search" data-filter="phone" placeholder="Телефон"></label>
                <label>Email<input type="search" data-filter="email" placeholder="Email"></label>
                <label>Документы<select data-filter="document"><option value="">Все документы</option><option value="__has__">Есть документы</option><option value="__none__">Нет документов</option><?php foreach($document_types as $name):?><option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option><?php endforeach;?></select></label>
                <button type="button" class="zau-link-button" data-zau-registry-reset>Сбросить фильтры</button>
            </div>
            <div class="zau-org-registry-toolbar">
                <label class="zau-registry-select-all"><input type="checkbox" data-zau-registry-select-all> Выбрать видимые</label>
                <span data-zau-registry-count>Показано: <?php echo count($rows); ?></span>
                <div class="zau-org-registry-actions">
                    <button type="button" class="zau-union-button" data-zau-registry-excel>Экспорт Excel</button>
                    <button type="button" class="zau-union-button" data-zau-registry-pdf>Экспорт PDF</button>
                    <button type="button" class="zau-union-button zau-secondary-button" data-zau-registry-zip>Скачать документы ZIP</button>
                </div>
            </div>
            <p class="zau-registry-hint">Если отмечены строки — экспортируются только они. Если ничего не отмечено — экспортируются все строки, оставшиеся после фильтрации.</p>
            <div class="zau-org-registry-message" data-zau-registry-message></div>
            <div class="zau-org-registry-table-wrap"><table class="zau-org-registry-table"><thead><tr><th></th><th>ФИО</th><th>Дата регистрации</th><th>Наименование предприятия, организации</th><th>Телефон</th><th>Email</th><th>Документы</th></tr></thead><tbody>
            <?php if(!$rows):?><tr><td colspan="7">Участники не найдены.</td></tr><?php endif;?>
            <?php foreach($rows as $row):
                $doc_names = array_values(array_filter(array_map(function($doc){ return $doc['name']; }, $row['documents'])));
                $search_name = function_exists('mb_strtolower') ? mb_strtolower($row['full_name'],'UTF-8') : strtolower($row['full_name']);
                $search_phone = preg_replace('/\D/','',$row['phone']);
                $search_email = strtolower($row['email']);
            ?>
                <tr data-zau-registry-row data-user-id="<?php echo (int)$row['user_id'];?>" data-name="<?php echo esc_attr($search_name);?>" data-date="<?php echo esc_attr(substr($row['registration_date'],0,10));?>" data-organization="<?php echo (int)$row['organization_id'];?>" data-phone="<?php echo esc_attr($search_phone);?>" data-email="<?php echo esc_attr($search_email);?>" data-documents="<?php echo esc_attr(wp_json_encode($doc_names,JSON_UNESCAPED_UNICODE));?>">
                    <td data-label="Выбор"><input type="checkbox" data-zau-registry-check value="<?php echo (int)$row['user_id'];?>"></td>
                    <td data-label="ФИО"><strong><?php echo esc_html($row['full_name']);?></strong><small><?php echo esc_html($row['status']);?></small></td>
                    <td data-label="Дата регистрации"><?php echo esc_html($row['registration_display']);?></td>
                    <td data-label="Организация"><strong><?php echo esc_html($row['organization'] ?: 'Не назначена');?></strong><?php if($row['organization_bin']):?><small>БИН <?php echo esc_html($row['organization_bin']);?></small><?php endif;?></td>
                    <td data-label="Телефон"><a href="tel:<?php echo esc_attr(preg_replace('/[^+0-9]/','',$row['phone']));?>"><?php echo esc_html($row['phone'] ?: '—');?></a></td>
                    <td data-label="Email"><a href="mailto:<?php echo esc_attr($row['email']);?>"><?php echo esc_html($row['email'] ?: '—');?></a></td>
                    <td data-label="Документы"><div class="zau-registry-docs"><?php if(!$row['documents']):?><span class="zau-muted">Нет документов</span><?php endif;?><?php foreach($row['documents'] as $doc):?><div class="zau-registry-doc"><span><strong><?php echo esc_html($doc['name']);?></strong><small><?php echo esc_html($doc['number']);?></small></span><?php if($doc['ready']&&$doc['view_url']):?><a class="zau-registry-doc-button" target="_blank" rel="noopener" href="<?php echo esc_url($doc['view_url']);?>">Просмотр</a><a class="zau-registry-doc-button" href="<?php echo esc_url($doc['download_url']);?>">Скачать</a><?php else:?><em>готовится</em><?php endif;?></div><?php endforeach;?></div></td>
                </tr>
            <?php endforeach;?></tbody></table></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" data-zau-registry-server-form hidden>
                <?php wp_nonce_field('zau_union_org_registry_export');?><input type="hidden" name="action" value=""><input type="hidden" name="member_ids" value="">
            </form>
        </div>
        <?php return ob_get_clean();
    }

    public function export_org_registry_excel() {
        $this->require_org_registry_access();
        check_admin_referer('zau_union_org_registry_export');
        $rows = $this->organization_registry_rows($this->authorized_registry_member_ids($this->requested_registry_member_ids()));
        $filename = 'reestr-organizacii-'.wp_date('Y-m-d-His').'.xls';
        nocache_headers();
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<?mso-application progid="Excel.Sheet"?>';
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#DDEFE5" ss:Pattern="Solid"/><Alignment ss:WrapText="1"/></Style><Style ss:ID="Wrap"><Alignment ss:WrapText="1" ss:Vertical="Top"/></Style></Styles><Worksheet ss:Name="Реестр"><Table>';
        $headers = ['ФИО','Дата регистрации','Наименование предприятия, организации','БИН','Телефон','Email','Статус','Документы'];
        echo '<Row>'; foreach($headers as $header){echo '<Cell ss:StyleID="Header"><Data ss:Type="String">'.$this->xml_escape($header).'</Data></Cell>';} echo '</Row>';
        foreach($rows as $row){
            $docs=[]; foreach($row['documents'] as $doc){$docs[]=$doc['name'].' — '.$doc['number'].($doc['ready']?'':' (готовится)');}
            $values=[$row['full_name'],$row['registration_display'],$row['organization'],$row['organization_bin'],$row['phone'],$row['email'],$row['status'],implode("\n",$docs)];
            echo '<Row>'; foreach($values as $value){echo '<Cell ss:StyleID="Wrap"><Data ss:Type="String">'.$this->xml_escape($value).'</Data></Cell>';} echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        $this->log('organization_registry_excel_exported','registry',0,'rows='.count($rows));
        exit;
    }

    private function xml_escape($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | (defined('ENT_XML1') ? ENT_XML1 : 0), 'UTF-8');
    }

    public function download_org_documents_zip() {
        $this->require_org_registry_access();
        check_admin_referer('zau_union_org_registry_export');
        if (!class_exists('ZipArchive')) { wp_die('На сервере не установлено расширение PHP ZipArchive. Отдельные PDF можно скачать кнопками в реестре.'); }
        $rows = $this->organization_registry_rows($this->authorized_registry_member_ids($this->requested_registry_member_ids()));
        $tmp = wp_tempnam('zau-org-documents.zip');
        if (!$tmp) { wp_die('Не удалось создать временный ZIP-файл.'); }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($tmp); wp_die('Не удалось открыть ZIP-файл.'); }
        $count = 0;
        foreach($rows as $row){
            foreach($row['documents'] as $doc){
                if(!$doc['ready'])continue;
                global $wpdb;
                $dbdoc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",(int)$doc['id']));
                if(!$dbdoc||!ZAU_Certificate_PDF_Generator::instance()->user_can_access_document($dbdoc,true))continue;
                $path=$this->upload_url_to_path($dbdoc->pdf_url);
                if(!$path||!is_file($path))continue;
                $folder=sanitize_file_name($row['full_name'].'-'.$row['user_id']);
                $name=sanitize_file_name($doc['number'].'-'.$doc['id'].'.pdf');
                $zip->addFile($path,$folder.'/'.$name);$count++;
            }
        }
        $zip->close();
        if(!$count){@unlink($tmp);wp_die('У выбранных участников нет доступных PDF-файлов.');}
        $filename='dokumenty-organizacii-'.wp_date('Y-m-d-His').'.zip';
        nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);
        $this->log('organization_registry_zip_downloaded','registry',0,'files='.$count);exit;
    }

    private function upload_url_to_path($url) {
        if(!$url)return '';$up=wp_upload_dir();$base=trailingslashit($up['baseurl']);if(strpos($url,$base)!==0)return '';$relative=ltrim(substr($url,strlen($base)),'/');$path=wp_normalize_path(trailingslashit($up['basedir']).$relative);$root=wp_normalize_path(trailingslashit($up['basedir']));return strpos($path,$root)===0?$path:'';
    }

    private function delete_upload_url($url) {
        $path=$this->upload_url_to_path($url);
        if($path && is_file($path)){ @unlink($path); }
    }

    public function ajax_export_org_registry_pdf() {
        check_ajax_referer(self::NONCE,'nonce');
        $this->require_org_registry_access();
        $raw_pages = (array)($_POST['pages'] ?? []);
        if(!$raw_pages||count($raw_pages)>100){status_header(400);echo 'Некорректное количество страниц.';exit;}
        $pages=[];
        foreach($raw_pages as $data_url){
            $data_url=wp_unslash($data_url);
            if(!preg_match('#^data:image/jpeg;base64,(.+)$#s',$data_url,$m))continue;
            $jpeg=base64_decode(str_replace(' ','+',$m[1]),true);
            if(!$jpeg||strlen($jpeg)<1000||strlen($jpeg)>6*1024*1024)continue;
            $size=@getimagesizefromstring($jpeg);if(!$size||($size[2]??0)!==IMAGETYPE_JPEG)continue;
            $pages[]=['jpeg'=>$jpeg,'width'=>(int)$size[0],'height'=>(int)$size[1]];
        }
        if(!$pages){status_header(400);echo 'Страницы PDF не распознаны.';exit;}
        $pdf=$this->jpeg_pages_to_pdf($pages);
        nocache_headers();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="reestr-organizacii-'.wp_date('Y-m-d-His').'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;$this->log('organization_registry_pdf_exported','registry',0,'pages='.count($pages));exit;
    }

    private function jpeg_pages_to_pdf($pages) {
        $objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$kids=[];
        foreach($pages as $index=>$page){$page_obj=3+$index*3;$image_obj=$page_obj+1;$content_obj=$page_obj+2;$kids[]=$page_obj.' 0 R';$w=841.8898;$h=595.2756;$objects[$page_obj]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /XObject << /Im$index $image_obj 0 R >> >> /Contents $content_obj 0 R >>";$jpeg=$page['jpeg'];$objects[$image_obj]="<< /Type /XObject /Subtype /Image /Width {$page['width']} /Height {$page['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream";$stream="q\n$w 0 0 $h 0 0 cm\n/Im$index Do\nQ\n";$objects[$content_obj]="<< /Length ".strlen($stream)." >>\nstream\n$stream".'endstream';}
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$obj."\nendobj\n";}$max=max(array_keys($objects));$xref=strlen($pdf);$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++){$pdf.=isset($offsets[$i])?sprintf('%010d 00000 n ',$offsets[$i])."\n":"0000000000 00000 f \n";}$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";return $pdf;
    }

    public function organization_members_shortcode($atts=[]) {
        $atts=shortcode_atts([
            'show_heading'=>'yes','return_url'=>'','methods'=>'inherit','otp_channels'=>'inherit','default_method'=>'',
            'show_tabs'=>'inherit','show_recovery'=>'inherit','show_description'=>'yes','show_remember'=>'yes'
        ],$atts,'zau_union_org_members');
        $showHeading=$atts['show_heading']!=='no';
        if(!is_user_logged_in())return $this->auth_shortcode(array_merge((array)$atts,['return_url'=>home_url(wp_unslash($_SERVER['REQUEST_URI']??'/'))]));
        $uid=get_current_user_id();
        if(!current_user_can(ZAU_Certificate_PDF_Generator::CAP_ORG_MANAGE)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))return '';
        $memberIds=$this->manager_member_ids($uid);
        if((current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)||current_user_can('manage_options'))&&!$memberIds)$memberIds=get_users(['fields'=>'ids','number'=>1000,'orderby'=>'display_name','order'=>'ASC']);
        $memberIds=array_values(array_diff(array_map('absint',(array)$memberIds),[$uid]));
        if(!$memberIds)return '<div class="zau-org-members">'.($showHeading?'<h3>Участники моей организации или филиала</h3>':'').'<p>Пока нет привязанных участников. Администратор должен назначить вам организации или филиалы.</p></div>';
        $users=get_users(['include'=>$memberIds,'number'=>1000,'orderby'=>'display_name','order'=>'ASC']);global $wpdb;$cabinetUrl=$this->settings()['cabinet_page_id']?get_permalink((int)$this->settings()['cabinet_page_id']):home_url('/lk-profsoyuz/');
        ob_start(); ?>
        <section class="zau-org-members" data-zau-org-members><?php if($showHeading):?><div class="zau-org-members-head"><div><h3>Участники моей организации или филиала</h3><p>Статусы, согласование вступления и личные карточки.</p></div><div class="zau-org-members-tools"><a class="zau-union-button zau-secondary-button" href="<?php echo esc_url($this->organization_registry_url());?>">Открыть полный реестр</a><input type="search" data-zau-member-search placeholder="Найти по ФИО, телефону или email"></div></div><?php endif;?>
        <div class="zau-org-members-message" data-zau-manager-message></div>
        <div class="zau-org-members-bulk" data-zau-member-bulk-bar><label><input type="checkbox" data-zau-select-all-members> Выбрать видимых</label><select data-zau-bulk-member-action><option value="set_status">Установить статус</option><option value="approve">Одобрить вступление</option><option value="revision">На доработку</option><option value="reject">Отклонить</option></select><select data-zau-bulk-member-status><?php foreach($this->membership_statuses() as $option):?><option><?php echo esc_html($option);?></option><?php endforeach;?></select><input type="text" data-zau-bulk-member-note placeholder="Комментарий"><button type="button" class="zau-union-button" data-zau-apply-member-bulk>Применить</button></div>
        <div class="zau-org-member-list">
        <?php foreach($users as $member):if(!$this->can_manage_member($uid,$member->ID)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))continue;
            $orgId=$this->member_org_id($member->ID);$org=$orgId?$wpdb->get_row($wpdb->prepare("SELECT name,bin FROM {$this->orgs_table} WHERE id=%d",$orgId)):null;$branch=$this->get_branch($this->member_branch_id($member->ID));
            $phone=get_user_meta($member->ID,'zau_phone',true);$status=get_user_meta($member->ID,'zau_member_status',true)?:'Регистрация не завершена';$approval=$this->membership_approval_status($member->ID);$cardStatus=get_user_meta($member->ID,'zau_member_card_review_status',true)?:'draft';
            $submissionCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->submissions_table} WHERE user_id=%d",$member->ID));$docCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->docs_table} WHERE user_id=%d",$member->ID));
            $searchSource=$member->display_name.' '.$member->user_email.' '.$phone.' '.($org->name??'').' '.($branch->name??'');$searchText=function_exists('mb_strtolower')?mb_strtolower($searchSource,'UTF-8'):strtolower($searchSource);
            $cardUrl=add_query_arg(['zau_tab'=>'card','member_id'=>$member->ID],$cabinetUrl);
        ?>
        <article class="zau-org-member-card" data-search="<?php echo esc_attr($searchText);?>" data-member-id="<?php echo (int)$member->ID;?>"><div class="zau-org-member-check"><input type="checkbox" data-zau-member-select value="<?php echo (int)$member->ID;?>"></div><div class="zau-org-member-main"><strong><?php echo esc_html($member->display_name);?></strong><span><?php echo esc_html(trim($member->user_email.' '.$phone));?></span><small><?php echo esc_html($org?($org->name.' · БИН '.$org->bin):'Организация не назначена');?></small><small><?php echo esc_html($branch?'Филиал: '.$branch->name:'Филиал не назначен');?></small><small>Заявок: <?php echo $submissionCount;?> · Документов: <?php echo $docCount;?> · Карточка: <?php echo esc_html($cardStatus);?></small><span class="zau-status-pill"><?php echo esc_html($this->membership_approval_label($approval));?></span></div><div class="zau-org-member-control"><select data-zau-member-status="<?php echo (int)$member->ID;?>"><?php foreach($this->membership_statuses() as $option):?><option <?php selected($status,$option);?>><?php echo esc_html($option);?></option><?php endforeach;?></select><button type="button" class="zau-union-button zau-small-button" data-zau-save-member="<?php echo (int)$member->ID;?>">Сохранить статус</button><button type="button" class="zau-union-button zau-small-button" data-zau-quick-approve="<?php echo (int)$member->ID;?>">Одобрить вступление</button><a class="zau-union-button zau-secondary-button zau-small-button" href="<?php echo esc_url($cardUrl);?>">Личная карточка</a></div></article>
        <?php endforeach;?></div></section><?php return ob_get_clean();
    }

    public function ajax_manager_update_member() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите в систему.'],401);
        $memberId=absint($_POST['member_id']??0);$status=sanitize_text_field(wp_unslash($_POST['status']??''));if(!$memberId||!get_user_by('id',$memberId))wp_send_json_error(['message'=>'Участник не найден.'],404);
        if(!$this->can_manage_member(get_current_user_id(),$memberId)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))wp_send_json_error(['message'=>'Нет доступа к этому участнику.'],403);
        $result=$this->set_member_status($memberId,$status,'organization_manager');if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_message()],400);
        wp_send_json_success(['message'=>'Статус участника обновлён.','status'=>$status]);
    }

    private function membership_approval_status($userId) {
        return (string)(get_user_meta((int)$userId,'zau_membership_approval_status',true) ?: 'pending');
    }

    private function membership_approval_label($status) {
        $labels=['pending'=>'Ожидает решения','approved'=>'Одобрено','revision'=>'На доработке','rejected'=>'Отклонено','manual'=>'Изменено вручную'];
        return $labels[(string)$status]??(string)$status;
    }

    private function document_member_has_exited($doc) {
        if(!is_object($doc))return false;
        if((string)($doc->member_status??'')==='Выбыл из профсоюза')return true;
        $userId=(int)($doc->user_id??0);
        return $userId>0&&(string)get_user_meta($userId,'zau_member_status',true)==='Выбыл из профсоюза';
    }

    private function normalize_document_for_display($doc,$ensureWatermark=false) {
        if(!is_object($doc))return $doc;
        if(!$this->document_member_has_exited($doc))return $doc;
        if($ensureWatermark){$doc=$this->ensure_exit_watermark_for_document($doc);}
        $doc->record_status='revoked';
        $doc->member_status='Выбыл из профсоюза';
        if(empty($doc->revoked_at))$doc->revoked_at=current_time('mysql');
        return $doc;
    }

    private function repair_exit_documents_batch($limit=20,$cursor=0) {
        global $wpdb;
        $limit=max(1,min(100,(int)$limit));$cursor=max(0,(int)$cursor);
        $sql=$wpdb->prepare("SELECT DISTINCT d.* FROM {$this->docs_table} d LEFT JOIN {$wpdb->usermeta} um ON um.user_id=d.user_id AND um.meta_key=%s WHERE d.id>%d AND (um.meta_value=%s OR d.member_status=%s) ORDER BY d.id ASC LIMIT %d",'zau_member_status',$cursor,'Выбыл из профсоюза','Выбыл из профсоюза',$limit);
        $docs=$wpdb->get_results($sql);$processed=0;$last=$cursor;
        foreach((array)$docs as $doc){$last=max($last,(int)$doc->id);$this->ensure_exit_watermark_for_document($doc);$processed++;}
        return ['processed'=>$processed,'last'=>$last,'has_more'=>$processed===$limit];
    }

    public function repair_exit_documents_cron() {
        $cursor=(int)get_option('zau_union_exit_repair_cursor',0);
        $result=$this->repair_exit_documents_batch(15,$cursor);
        if(!empty($result['has_more'])){
            update_option('zau_union_exit_repair_cursor',(int)$result['last'],false);
            if(!wp_next_scheduled('zau_union_repair_exit_documents_cron'))wp_schedule_single_event(time()+30,'zau_union_repair_exit_documents_cron');
        }else{
            delete_option('zau_union_exit_repair_cursor');
        }
    }

    public function repair_exit_documents_action() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);check_admin_referer(self::NONCE);
        delete_option('zau_union_exit_repair_cursor');
        $result=$this->repair_exit_documents_batch(50,0);
        if(!empty($result['has_more'])){
            update_option('zau_union_exit_repair_cursor',(int)$result['last'],false);
            if(!wp_next_scheduled('zau_union_repair_exit_documents_cron'))wp_schedule_single_event(time()+10,'zau_union_repair_exit_documents_cron');
        }
        $message='Синхронизировано документов: '.(int)$result['processed'].'.';
        if(!empty($result['has_more']))$message.=' Остальные будут обработаны автоматически в фоне.';
        wp_safe_redirect(admin_url('admin.php?page=zau-union-member-statuses&zau_notice='.rawurlencode($message)));exit;
    }

    private function sync_member_status_to_documents($memberId,$status) {
        global $wpdb;
        $docs=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE user_id=%d",(int)$memberId));
        foreach((array)$docs as $doc){
            $data=json_decode((string)$doc->data_json,true);if(!is_array($data))$data=[];
            $data['member_status']=$status;$data['profile__member_status']=$status;
            if($status==='Выбыл из профсоюза'){
                $this->revoke_document_for_member_exit($doc,$data);
            }else{
                $this->restore_document_after_member_return($doc,$data,$status);
            }
        }
    }

    /**
     * Автоматически отзывает документ участника и создаёт PDF с водяным знаком.
     * Исходные URL и прежний статус сохраняются в data_json, поэтому при возврате
     * участника документ можно восстановить без потери оригинала.
     */
    private function revoke_document_for_member_exit($doc,$data=[]) {
        global $wpdb;
        if(!is_object($doc))return false;
        $now=current_time('mysql');
        $exit=isset($data['_zau_member_exit_revocation'])&&is_array($data['_zau_member_exit_revocation'])?$data['_zau_member_exit_revocation']:[];
        if(empty($exit['active'])){
            $exit=[
                'active'=>1,
                'reason'=>'Выбыл из профсоюза',
                'revoked_at'=>$now,
                'previous_record_status'=>(string)($doc->record_status?:'active'),
                'original_pdf_url'=>(string)$doc->pdf_url,
                'original_image_url'=>(string)$doc->image_url,
                'original_revision'=>(int)($doc->file_revision??0),
            ];
        }else{
            $exit['active']=1;$exit['reason']='Выбыл из профсоюза';$exit['revoked_at']=$exit['revoked_at']??$now;
        }
        if(!empty($exit['active'])&&$doc->record_status==='revoked'&&!empty($exit['watermarked_pdf_url'])&&(string)$doc->pdf_url===(string)$exit['watermarked_pdf_url']){
            $data['_zau_member_exit_revocation']=$exit;$data['member_status']='Выбыл из профсоюза';$data['profile__member_status']='Выбыл из профсоюза';
            $wpdb->update($this->docs_table,['member_status'=>'Выбыл из профсоюза','record_status'=>'revoked','revoked_at'=>$doc->revoked_at?:$now,'data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'updated_at'=>$now],['id'=>(int)$doc->id]);
            return true;
        }
        $data['_zau_member_exit_revocation']=$exit;
        $data['document_revocation_reason']='Выбыл из профсоюза';
        $data['document_revoked_at']=$now;

        $update=[
            'member_status'=>'Выбыл из профсоюза',
            'record_status'=>'revoked',
            'revoked_at'=>$now,
            'data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),
            'updated_at'=>$now,
        ];

        $imagePath=$this->upload_url_to_path((string)$doc->image_url);
        if($imagePath&&is_file($imagePath)){
            $jpeg=@file_get_contents($imagePath);
            $size=$jpeg?@getimagesizefromstring($jpeg):false;
            if($jpeg&&$size&&($size[2]??0)===IMAGETYPE_JPEG){
                $pdf=$this->jpeg_to_exit_watermarked_pdf($jpeg,(int)$size[0],(int)$size[1]);
                if($pdf!==''){
                    $up=wp_upload_dir();
                    $sub='zau-certificates/'.wp_date('Y/m');
                    $dir=trailingslashit($up['basedir']).$sub;
                    if(wp_mkdir_p($dir)){
                        $revision=max(1,(int)($doc->file_revision??0)+1);
                        $safe=sanitize_file_name(($doc->document_no?:'document').'-'.(int)$doc->id.'-r'.$revision.'-withdrawn');
                        $path=trailingslashit($dir).$safe.'.pdf';
                        if(file_put_contents($path,$pdf,LOCK_EX)!==false){
                            $update['pdf_url']=trailingslashit($up['baseurl']).$sub.'/'.$safe.'.pdf';
                            $update['file_revision']=$revision;
                            $data['_zau_member_exit_revocation']['watermarked_pdf_url']=$update['pdf_url'];
                            $data['_zau_member_exit_revocation']['watermarked_revision']=$revision;
                            $update['data_json']=wp_json_encode($data,JSON_UNESCAPED_UNICODE);
                        }
                    }
                }
            }
        }
        $wpdb->update($this->docs_table,$update,['id'=>(int)$doc->id]);
        $this->log('document_auto_revoked_member_exit','document',(int)$doc->id,'reason=Выбыл из профсоюза');
        return true;
    }

    private function restore_document_after_member_return($doc,$data,$status) {
        global $wpdb;
        if(!is_object($doc))return false;
        $exit=isset($data['_zau_member_exit_revocation'])&&is_array($data['_zau_member_exit_revocation'])?$data['_zau_member_exit_revocation']:[];
        $data['member_status']=$status;$data['profile__member_status']=$status;
        $update=['member_status'=>$status,'data_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')];
        if(!empty($exit['active'])){
            $watermarked=(string)($exit['watermarked_pdf_url']??'');
            $originalPdf=(string)($exit['original_pdf_url']??'');
            $originalImage=(string)($exit['original_image_url']??'');
            $previous=(string)($exit['previous_record_status']??'active');
            if(!in_array($previous,['draft','active','revoked'],true))$previous='active';
            $update['record_status']=$previous;
            $update['revoked_at']=$previous==='revoked'?($doc->revoked_at?:current_time('mysql')):null;
            if($originalPdf!=='')$update['pdf_url']=$originalPdf;
            if($originalImage!=='')$update['image_url']=$originalImage;
            $update['file_revision']=max((int)($doc->file_revision??0)+1,(int)($exit['original_revision']??0)+1);
            $exit['active']=0;$exit['restored_at']=current_time('mysql');
            $data['_zau_member_exit_revocation']=$exit;
            unset($data['document_revocation_reason'],$data['document_revoked_at']);
            $update['data_json']=wp_json_encode($data,JSON_UNESCAPED_UNICODE);
            $wpdb->update($this->docs_table,$update,['id'=>(int)$doc->id]);
            if($watermarked!==''&&$watermarked!==$originalPdf)$this->delete_upload_url($watermarked);
            $this->log('document_auto_restored_member_return','document',(int)$doc->id,'member_status='.$status);
            return true;
        }
        $wpdb->update($this->docs_table,$update,['id'=>(int)$doc->id]);
        return true;
    }

    public function ensure_exit_watermark_for_document($doc) {
        if(!is_object($doc))return $doc;
        $memberStatus=(int)$doc->user_id>0?(string)get_user_meta((int)$doc->user_id,'zau_member_status',true):(string)$doc->member_status;
        if($memberStatus!=='Выбыл из профсоюза')return $doc;
        $data=json_decode((string)$doc->data_json,true);if(!is_array($data))$data=[];
        $exit=$data['_zau_member_exit_revocation']??[];
        if($doc->record_status!=='revoked'||empty($exit['watermarked_pdf_url'])||empty($doc->pdf_url)){
            $this->revoke_document_for_member_exit($doc,$data);
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",(int)$doc->id))?:$doc;
        }
        return $doc;
    }

    private function set_member_status($memberId,$status,$context='manual',$note='') {
        $memberId=absint($memberId);
        if(!$memberId||!get_user_by('id',$memberId)||!in_array($status,$this->membership_statuses(),true))return new WP_Error('invalid_status','Не удалось изменить статус участника.');
        $old=(string)(get_user_meta($memberId,'zau_member_status',true)?:'Регистрация не завершена');
        update_user_meta($memberId,'zau_member_status',$status);
        if($status==='Состоит в профсоюзе'){
            if(!get_user_meta($memberId,'zau_membership_date',true))update_user_meta($memberId,'zau_membership_date',wp_date('d.m.Y'));
            if($context!=='approval')update_user_meta($memberId,'zau_membership_approval_status','manual');
        }
        $this->sync_member_status_to_documents($memberId,$status);
        $this->log('member_status_changed','user',$memberId,'old='.$old.'; new='.$status.'; context='.$context.($note!==''?'; note='.$note:''));
        return true;
    }

    private function apply_membership_decision($memberId,$decision,$note='',$actorId=0) {
        $memberId=absint($memberId);$decision=sanitize_key($decision);$actorId=absint($actorId?:get_current_user_id());
        if(!$memberId||!get_user_by('id',$memberId))return new WP_Error('member_missing','Участник не найден.');
        $map=['approve'=>['approved','Состоит в профсоюзе','approved'],'revision'=>['revision','На рассмотрении','revision'],'reject'=>['rejected','Заявление отклонено','rejected']];
        if(!isset($map[$decision]))return new WP_Error('decision','Неизвестное решение.');
        [$approval,$memberStatus,$submissionStatus]=$map[$decision];
        update_user_meta($memberId,'zau_membership_approval_status',$approval);
        update_user_meta($memberId,'zau_membership_approved_by',$actorId);
        update_user_meta($memberId,'zau_membership_approval_date',current_time('mysql'));
        update_user_meta($memberId,'zau_membership_approval_note',sanitize_textarea_field($note));
        if($decision==='approve'&&empty($this->settings()['auto_member_status_on_approval'])){
            $result=true;
        }else{
            $result=$this->set_member_status($memberId,$memberStatus,'approval',$note);
            if(is_wp_error($result))return $result;
        }
        global $wpdb;
        $latest=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->submissions_table} WHERE user_id=%d ORDER BY id DESC LIMIT 1",$memberId));
        if($latest)$wpdb->update($this->submissions_table,['status'=>$submissionStatus,'updated_at'=>current_time('mysql')],['id'=>$latest]);
        $this->log('membership_'.$approval,'user',$memberId,'decision='.$decision.($note!==''?'; note='.$note:''));
        return true;
    }

    private function member_card_fields() {
        $fields=[
            ['key'=>'iin','label'=>'ИИН','type'=>'text','member_editable'=>1,'placeholder'=>'12 цифр','sensitive'=>1],
            ['key'=>'birth_date','label'=>'Дата рождения','type'=>'date','member_editable'=>1,'placeholder'=>'ГГГГ-ММ-ДД','sensitive'=>1],
            ['key'=>'gender','label'=>'Пол','type'=>'select','member_editable'=>1,'options'=>[''=>'— не указано —','Мужской'=>'Мужской','Женский'=>'Женский']],
            ['key'=>'address','label'=>'Адрес проживания','type'=>'textarea','member_editable'=>1,'placeholder'=>'Введите новый адрес','sensitive'=>1],
            ['key'=>'position','label'=>'Должность','type'=>'text','member_editable'=>1,'placeholder'=>''],
            ['key'=>'department','label'=>'Подразделение','type'=>'text','member_editable'=>1,'placeholder'=>''],
            ['key'=>'employment_date','label'=>'Дата трудоустройства','type'=>'date','member_editable'=>1,'placeholder'=>''],
            ['key'=>'education','label'=>'Образование','type'=>'textarea','member_editable'=>1,'placeholder'=>''],
            ['key'=>'union_experience','label'=>'Профсоюзный стаж','type'=>'text','member_editable'=>1,'placeholder'=>''],
            ['key'=>'emergency_contact','label'=>'Контакт для связи','type'=>'text','member_editable'=>1,'placeholder'=>'Введите новый контакт','sensitive'=>1],
            ['key'=>'member_number','label'=>'Номер члена профсоюза','type'=>'text','member_editable'=>0,'placeholder'=>'Назначается администратором'],
            ['key'=>'membership_date','label'=>'Дата вступления','type'=>'text','member_editable'=>0,'placeholder'=>'дд.мм.гггг'],
            ['key'=>'card_notes','label'=>'Служебная заметка','type'=>'textarea','member_editable'=>0,'placeholder'=>'Видна ответственному и администратору'],
        ];
        return apply_filters('zau_union_member_card_fields',$fields);
    }

    private function sensitive_member_card_keys() {
        $configured=(string)($this->settings()['member_card_sensitive_fields']??'iin,birth_date,address,emergency_contact');
        $keys=[];foreach(explode(',',$configured) as $key){$key=sanitize_key(trim($key));if($key)$keys[]=$key;}
        foreach($this->member_card_fields() as $field){if(!empty($field['sensitive']))$keys[]=$field['key'];}
        return array_values(array_unique($keys));
    }

    private function is_sensitive_member_card_field($key) {
        return in_array(sanitize_key((string)$key),$this->sensitive_member_card_keys(),true);
    }

    private function mask_sensitive_value($value,$key='') {
        $value=trim((string)$value);if($value==='')return '—';
        if($key==='iin'){$digits=preg_replace('/\D/','',$value);return strlen($digits)>4?str_repeat('•',max(4,strlen($digits)-4)).substr($digits,-4):str_repeat('•',max(4,strlen($digits)));}
        if($key==='birth_date'){
            if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m))return '••.••.'.$m[1];
            return '••••••'.(function_exists('mb_substr')?mb_substr($value,-2,null,'UTF-8'):substr($value,-2));
        }
        $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
        $tail=function_exists('mb_substr')?mb_substr($value,-3,null,'UTF-8'):substr($value,-3);
        return str_repeat('•',max(6,min(14,$length-3))).$tail;
    }

    private function latest_submission_data($memberId) {
        global $wpdb;
        $json=$wpdb->get_var($wpdb->prepare("SELECT data_json FROM {$this->submissions_table} WHERE user_id=%d ORDER BY id DESC LIMIT 1",(int)$memberId));
        $data=json_decode((string)$json,true);return is_array($data)?$data:[];
    }

    private function get_member_card_data($memberId) {
        $saved=json_decode((string)get_user_meta((int)$memberId,'zau_member_card_data',true),true);if(!is_array($saved))$saved=[];
        $submission=$this->latest_submission_data($memberId);$user=get_user_by('id',(int)$memberId);
        foreach($this->member_card_fields() as $field){
            $key=$field['key'];if(array_key_exists($key,$saved)&&$saved[$key]!=='')continue;
            $value='';
            if(isset($submission[$key]))$value=$submission[$key];
            if($value===''&&$key==='iin')$value=get_user_meta($memberId,'zau_profile_iin',true);
            if($value===''&&$key==='position')$value=get_user_meta($memberId,'zau_profile_position',true);
            if($value===''&&$key==='department')$value=get_user_meta($memberId,'zau_profile_department',true);
            if($value===''&&$key==='membership_date')$value=get_user_meta($memberId,'zau_membership_date',true);
            if($value!==''&&is_scalar($value))$saved[$key]=(string)$value;
        }
        if($user){$saved['full_name']=$user->display_name;$saved['email']=$user->user_email;$saved['phone']=get_user_meta($memberId,'zau_phone',true);}
        $saved['organization']=get_user_meta($memberId,'zau_organization_name',true)?:get_user_meta($memberId,'zau_profile_organization',true);
        $branch=$this->get_branch($this->member_branch_id($memberId));$saved['branch']=$branch?$branch->name:(string)get_user_meta($memberId,'zau_profile_branch_name',true);
        $saved['member_status']=get_user_meta($memberId,'zau_member_status',true)?:'Регистрация не завершена';
        return $saved;
    }

    private function sanitize_member_card_value($field,$value) {
        $type=$field['type']??'text';
        if($type==='textarea')return sanitize_textarea_field(wp_unslash($value));
        if($type==='date')return preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$value)?sanitize_text_field($value):sanitize_text_field($value);
        if($field['key']==='iin')return substr(preg_replace('/\D/','',(string)$value),0,12);
        return sanitize_text_field(wp_unslash($value));
    }

    public function member_card_shortcode($atts=[]) {
        if(!is_user_logged_in())return $this->auth_shortcode(['return_url'=>home_url(wp_unslash($_SERVER['REQUEST_URI']??'/'))]);
        $viewer=get_current_user_id();$target=absint($atts['member_id']??($_GET['member_id']??0));if(!$target)$target=$viewer;
        if($target!==$viewer&&!$this->can_manage_member($viewer,$target)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))return '<div class="zau-form-error">Нет доступа к личной карточке этого участника.</div>';
        return $this->render_member_card($target,$viewer);
    }

    private function render_member_card($memberId,$viewerId=0) {
        $viewerId=absint($viewerId?:get_current_user_id());$memberId=absint($memberId);$isOwn=$viewerId===$memberId;
        $isAdmin=current_user_can('manage_options');
        $isResponsible=!$isOwn&&($this->can_manage_member($viewerId,$memberId)||current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE));
        $canEdit=$isAdmin||($isOwn&&!empty($this->settings()['member_card_member_edit']));
        $canReview=$isAdmin||$isResponsible;
        $data=$this->get_member_card_data($memberId);$user=get_user_by('id',$memberId);
        $review=(string)(get_user_meta($memberId,'zau_member_card_review_status',true)?:'draft');
        $reviewLabels=['draft'=>'Черновик','pending'=>'Ожидает проверки','approved'=>'Проверена','revision'=>'Нужна доработка','rejected'=>'Отклонена'];
        $approval=$this->membership_approval_status($memberId);
        $history=$this->member_status_history($memberId,12);
        $revealSeconds=max(10,min(300,(int)($this->settings()['sensitive_reveal_seconds']??30)));
        ob_start();?>
        <section class="zau-member-card" data-zau-member-card data-member-id="<?php echo (int)$memberId;?>" data-sensitive-timeout="<?php echo (int)$revealSeconds;?>">
            <div class="zau-member-card-head"><div><h3>Личная карточка члена профсоюза</h3><p><?php echo esc_html($user?$user->display_name:'Участник');?> · <?php echo esc_html($data['organization']??'');?></p></div><div class="zau-member-card-badges"><span class="zau-status-pill is-<?php echo esc_attr($review);?>"><?php echo esc_html($reviewLabels[$review]??$review);?></span><span class="zau-status-pill is-membership"><?php echo esc_html($data['member_status']);?></span></div></div>
            <div class="zau-member-card-privacy"><strong>Персональные данные защищены</strong><span>Чувствительные значения не передаются в HTML полностью. Их может раскрыть только владелец карточки или администратор.</span></div>
            <div class="zau-member-card-system"><div><small>Телефон</small><strong><?php echo esc_html($data['phone']??'—');?></strong></div><div><small>Email</small><strong><?php echo esc_html($data['email']??'—');?></strong></div><div><small>Организация</small><strong><?php echo esc_html($data['organization']??'—');?></strong></div><div><small>Филиал</small><strong><?php echo esc_html($data['branch']??'—');?></strong></div></div>
            <form data-zau-member-card-form>
                <input type="hidden" name="member_id" value="<?php echo (int)$memberId;?>">
                <div class="zau-member-card-grid">
                <?php foreach($this->member_card_fields() as $field):
                    $key=$field['key'];$sensitive=$this->is_sensitive_member_card_field($key);$value=$data[$key]??'';
                    $editable=$canEdit&&($isAdmin||!empty($field['member_editable']));$canReveal=$sensitive&&($isOwn||$isAdmin);
                ?>
                    <label class="zau-member-card-field<?php echo $sensitive?' is-sensitive':'';?>"><span><?php echo esc_html($field['label']);?><?php if(!$editable):?><small> · <?php echo $canReview?'только просмотр':'только администратор';?></small><?php endif;?></span>
                    <?php if($sensitive):?>
                        <div class="zau-sensitive-control" data-zau-sensitive-field="<?php echo esc_attr($key);?>">
                            <div class="zau-sensitive-value"><code data-zau-sensitive-display data-masked="<?php echo esc_attr($this->mask_sensitive_value($value,$key));?>"><?php echo esc_html($this->mask_sensitive_value($value,$key));?></code>
                            <?php if($canReveal&&$value!==''):?><button type="button" class="zau-sensitive-button" data-zau-sensitive-show>Показать</button><button type="button" class="zau-sensitive-button" data-zau-sensitive-copy>Копировать</button><?php endif;?></div>
                            <?php if($editable):?><input type="hidden" name="card_sensitive_keep[<?php echo esc_attr($key);?>]" value="1">
                                <?php if(($field['type']??'text')==='textarea'):?><textarea name="card[<?php echo esc_attr($key);?>]" placeholder="Введите новое значение; пустое поле сохранит прежнее"></textarea>
                                <?php else:?><input type="text" name="card[<?php echo esc_attr($key);?>]" value="" autocomplete="off" placeholder="Введите новое значение; пустое поле сохранит прежнее"><?php endif;?>
                            <?php endif;?>
                        </div>
                    <?php elseif(($field['type']??'text')==='textarea'):?><textarea name="card[<?php echo esc_attr($key);?>]" <?php disabled(!$editable);?> placeholder="<?php echo esc_attr($field['placeholder']??'');?>"><?php echo esc_textarea($value);?></textarea>
                    <?php elseif(($field['type']??'text')==='select'):?><select name="card[<?php echo esc_attr($key);?>]" <?php disabled(!$editable);?>><?php foreach((array)($field['options']??[]) as $optionValue=>$optionLabel):?><option value="<?php echo esc_attr($optionValue);?>" <?php selected($value,$optionValue);?>><?php echo esc_html($optionLabel);?></option><?php endforeach;?></select>
                    <?php else:?><input type="<?php echo esc_attr(($field['type']??'text')==='date'?'date':'text');?>" name="card[<?php echo esc_attr($key);?>]" value="<?php echo esc_attr($value);?>" <?php disabled(!$editable);?> placeholder="<?php echo esc_attr($field['placeholder']??'');?>">
                    <?php endif;?></label>
                <?php endforeach;?></div>
                <?php if($canEdit||$canReview):?><div class="zau-member-card-actions"><?php if($canEdit):?><button type="submit" class="zau-union-button">Сохранить карточку</button><?php endif;?><?php if($canReview):?><button type="button" class="zau-union-button zau-secondary-button" data-zau-review-card="approve">Одобрить карточку</button><button type="button" class="zau-union-button zau-secondary-button" data-zau-review-card="revision">Вернуть на доработку</button><?php endif;?></div><?php endif;?>
                <div data-zau-member-card-message></div>
            </form>
            <?php if($canReview):?><div class="zau-membership-decision"><h4>Решение по вступлению</h4><p>После одобрения статус автоматически станет «Состоит в профсоюзе».</p><textarea data-zau-membership-note placeholder="Комментарий к решению"></textarea><div><button type="button" class="zau-union-button" data-zau-membership-decision="approve">Одобрить вступление</button><button type="button" class="zau-union-button zau-secondary-button" data-zau-membership-decision="revision">На доработку</button><button type="button" class="zau-union-button zau-danger-button" data-zau-membership-decision="reject">Отклонить</button></div><small>Текущее решение: <?php echo esc_html($this->membership_approval_label($approval));?></small></div><?php endif;?>
            <?php if($history):?><details class="zau-member-history"><summary>История статусов и согласований</summary><ul><?php foreach($history as $item):?><li><strong><?php echo esc_html(mysql2date('d.m.Y H:i',$item->created_at));?></strong> — <?php echo esc_html($item->details);?></li><?php endforeach;?></ul></details><?php endif;?>
        </section><?php return ob_get_clean();
    }

    public function ajax_reveal_sensitive() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите.'],401);
        $viewer=get_current_user_id();$memberId=absint($_POST['member_id']??0);if(!$memberId)$memberId=$viewer;
        $field=sanitize_key($_POST['field']??'');$mode=sanitize_key($_POST['mode']??'show');
        if($viewer!==$memberId&&!current_user_can('manage_options'))wp_send_json_error(['message'=>'Полное значение доступно только владельцу карточки или администратору.'],403);
        if(!$this->is_sensitive_member_card_field($field))wp_send_json_error(['message'=>'Поле не относится к защищённым.'],400);
        $data=$this->get_member_card_data($memberId);$value=(string)($data[$field]??'');if($value==='')wp_send_json_error(['message'=>'Значение не заполнено.'],404);
        $this->log('sensitive_field_revealed','user',$memberId,'field='.$field.'; mode='.$mode.'; viewer='.$viewer);
        wp_send_json_success(['value'=>$value,'field'=>$field,'expires_in'=>max(10,min(300,(int)($this->settings()['sensitive_reveal_seconds']??30)))]);
    }

    private function member_status_history($memberId,$limit=20) {
        global $wpdb;$limit=max(1,min(100,(int)$limit));
        return $wpdb->get_results($wpdb->prepare("SELECT action,details,created_at,user_id FROM {$this->logs_table} WHERE object_type='user' AND object_id=%d AND action IN ('member_status_changed','membership_approved','membership_revision','membership_rejected','member_card_saved','member_card_approved','member_card_revision','member_card_rejected') ORDER BY id DESC LIMIT %d",(int)$memberId,$limit));
    }

    public function ajax_save_member_card() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите.'],401);
        $viewer=get_current_user_id();$memberId=absint($_POST['member_id']??0);if(!$memberId)$memberId=$viewer;
        $isOwn=$viewer===$memberId;$isAdmin=current_user_can('manage_options');
        if(!$isOwn&&!$isAdmin)wp_send_json_error(['message'=>'Редактировать личную карточку может только её владелец или администратор.'],403);
        if($isOwn&&empty($this->settings()['member_card_member_edit']))wp_send_json_error(['message'=>'Самостоятельное редактирование отключено.'],403);
        $incoming=(array)($_POST['card']??[]);$keep=(array)($_POST['card_sensitive_keep']??[]);$data=$this->get_member_card_data($memberId);
        foreach($this->member_card_fields() as $field){$key=$field['key'];if(!$isAdmin&&empty($field['member_editable']))continue;if(!array_key_exists($key,$incoming))continue;$raw=is_scalar($incoming[$key])?(string)$incoming[$key]:'';if($this->is_sensitive_member_card_field($key)&&trim($raw)===''&&!empty($keep[$key]))continue;$data[$key]=$this->sanitize_member_card_value($field,$raw);}
        update_user_meta($memberId,'zau_member_card_data',wp_json_encode($data,JSON_UNESCAPED_UNICODE));
        update_user_meta($memberId,'zau_member_card_review_status','pending');
        update_user_meta($memberId,'zau_member_card_updated_at',current_time('mysql'));
        if(!empty($data['membership_date']))update_user_meta($memberId,'zau_membership_date',sanitize_text_field($data['membership_date']));
        $this->log('member_card_saved','user',$memberId,$isOwn?'updated_by_member':'updated_by_manager');
        wp_send_json_success(['message'=>'Карточка сохранена и отправлена на проверку.','review_status'=>'pending']);
    }

    public function ajax_review_member_card() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите.'],401);
        $memberId=absint($_POST['member_id']??0);$decision=sanitize_key($_POST['decision']??'');
        if(!$memberId||(!$this->can_manage_member(get_current_user_id(),$memberId)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options')))wp_send_json_error(['message'=>'Нет доступа к карточке.'],403);
        $map=['approve'=>'approved','revision'=>'revision','reject'=>'rejected'];if(!isset($map[$decision]))wp_send_json_error(['message'=>'Неизвестное решение.'],400);
        $status=$map[$decision];update_user_meta($memberId,'zau_member_card_review_status',$status);update_user_meta($memberId,'zau_member_card_reviewed_by',get_current_user_id());update_user_meta($memberId,'zau_member_card_reviewed_at',current_time('mysql'));
        if($status==='approved')update_user_meta($memberId,'zau_member_card_approved_data',get_user_meta($memberId,'zau_member_card_data',true));
        $this->log('member_card_'.$status,'user',$memberId,$status);wp_send_json_success(['message'=>$status==='approved'?'Карточка одобрена.':'Карточка возвращена на доработку.','review_status'=>$status]);
    }

    public function ajax_membership_decision() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите.'],401);
        $memberId=absint($_POST['member_id']??0);if(!$memberId||(!$this->can_manage_member(get_current_user_id(),$memberId)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options')))wp_send_json_error(['message'=>'Нет доступа к участнику.'],403);
        $result=$this->apply_membership_decision($memberId,sanitize_key($_POST['decision']??''),sanitize_textarea_field(wp_unslash($_POST['note']??'')));
        if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_message()],400);
        wp_send_json_success(['message'=>'Решение сохранено. Статус участника обновлён автоматически.','status'=>get_user_meta($memberId,'zau_member_status',true)]);
    }

    public function ajax_manager_bulk_update_members() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in())wp_send_json_error(['message'=>'Сначала войдите.'],401);
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['member_ids']??[])))));if(!$ids)wp_send_json_error(['message'=>'Выберите участников.'],400);
        $action=sanitize_key($_POST['bulk_action']??'');$status=sanitize_text_field(wp_unslash($_POST['status']??''));$note=sanitize_textarea_field(wp_unslash($_POST['note']??''));$done=0;
        foreach(array_slice($ids,0,500) as $memberId){if(!$this->can_manage_member(get_current_user_id(),$memberId)&&!current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE)&&!current_user_can('manage_options'))continue;
            if($action==='set_status'&&in_array($status,$this->membership_statuses(),true))$result=$this->set_member_status($memberId,$status,'manager_bulk',$note);
            elseif(in_array($action,['approve','revision','reject'],true))$result=$this->apply_membership_decision($memberId,$action,$note);
            else continue;
            if(!is_wp_error($result))$done++;
        }
        wp_send_json_success(['message'=>'Обработано участников: '.$done.'.','updated'=>$done]);
    }

    public function bulk_member_status_action() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);check_admin_referer(self::NONCE);
        $single=absint($_POST['single_user_id']??0);$ids=$single?[$single]:array_values(array_unique(array_filter(array_map('absint',(array)($_POST['user_ids']??[])))));
        if(!$ids)wp_die('Не выбраны участники.');$action=sanitize_key($_POST['bulk_action']??'set_status');$note=sanitize_textarea_field(wp_unslash($_POST['note']??''));$done=0;
        foreach(array_slice($ids,0,1000) as $memberId){$status=$single?sanitize_text_field(wp_unslash($_POST['row_status'][$memberId]??'')):sanitize_text_field(wp_unslash($_POST['bulk_status']??''));
            if($action==='set_status'&&in_array($status,$this->membership_statuses(),true))$result=$this->set_member_status($memberId,$status,'admin_bulk',$note);
            elseif(in_array($action,['approve','revision','reject'],true))$result=$this->apply_membership_decision($memberId,$action,$note);
            else continue;if(!is_wp_error($result))$done++;
        }
        wp_safe_redirect(admin_url('admin.php?page=zau-union-member-statuses&zau_notice='.rawurlencode('Обработано участников: '.$done)));exit;
    }

    private function benefit_visibility($post,$userId,$allowAdminPreview=true) {
        $reasons=[];$audienceReasons=[];$today=wp_date('Y-m-d');
        if(!$post||$post->post_type!=='zau_union_benefit')return ['allowed'=>false,'preview'=>false,'reasons'=>['Запись не является акцией.']];
        if($post->post_status!=='publish')$reasons[]='Запись имеет статус «'.$post->post_status.'», а не «Опубликовано».';
        $from=trim((string)get_post_meta($post->ID,'_zau_benefit_from',true));
        $to=trim((string)get_post_meta($post->ID,'_zau_benefit_to',true));
        if($from!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)&&$today<$from)$reasons[]='Акция начнёт действовать '.mysql2date('d.m.Y',$from).'.';
        if($to!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)&&$today>$to)$reasons[]='Срок действия завершился '.mysql2date('d.m.Y',$to).'.';
        if($reasons)return ['allowed'=>false,'preview'=>false,'reasons'=>$reasons];

        $showAll=(bool)get_post_meta($post->ID,'_zau_benefit_show_all',true);
        if(!$showAll){
            $statuses=array_values(array_filter(array_map('trim',(array)get_post_meta($post->ID,'_zau_benefit_statuses',true))));
            $orgIds=array_values(array_unique(array_filter(array_map('absint',(array)get_post_meta($post->ID,'_zau_benefit_org_ids',true)))));
            $branchIds=array_values(array_unique(array_filter(array_map('absint',(array)get_post_meta($post->ID,'_zau_benefit_branch_ids',true)))));
            $currentStatus=trim((string)get_user_meta($userId,'zau_member_status',true));
            if($statuses){
                $normalized=array_map([$this,'normalize_audience_text'],$statuses);
                if(!in_array($this->normalize_audience_text($currentStatus),$normalized,true))$audienceReasons[]='Статус пользователя «'.($currentStatus?:'не указан').'» не входит в выбранные статусы.';
            }
            if($orgIds){
                $memberOrg=$this->member_org_id($userId);
                if(!$memberOrg||!in_array($memberOrg,$orgIds,true))$audienceReasons[]='Организация пользователя не совпадает с выбранными организациями акции.';
            }
            if($branchIds){
                $memberBranch=$this->member_branch_id($userId);
                if(!$memberBranch||!in_array($memberBranch,$branchIds,true))$audienceReasons[]='Филиал пользователя не совпадает с выбранными филиалами акции.';
            }
        }
        $isAdmin=$allowAdminPreview&&(user_can($userId,'manage_options')||user_can($userId,ZAU_Certificate_PDF_Generator::CAP_MANAGE));
        if($audienceReasons&&$isAdmin)return ['allowed'=>true,'preview'=>true,'reasons'=>$audienceReasons];
        if($audienceReasons)return ['allowed'=>false,'preview'=>false,'reasons'=>$audienceReasons];
        return ['allowed'=>true,'preview'=>false,'reasons'=>[]];
    }

    private function benefit_is_allowed($post,$userId) {
        $result=$this->benefit_visibility($post,$userId,true);
        $this->benefit_render_context[(int)$post->ID]=$result;
        return !empty($result['allowed']);
    }

    private function available_benefits($userId) {
        $this->benefit_render_context=[];
        $posts=get_posts(['post_type'=>'zau_union_benefit','post_status'=>'publish','numberposts'=>100,'orderby'=>['menu_order'=>'ASC','date'=>'DESC']]);
        return array_values(array_filter($posts,function($post)use($userId){return $this->benefit_is_allowed($post,$userId);}));
    }

    private function benefit_admin_diagnostics($userId) {
        if(!(user_can($userId,'manage_options')||user_can($userId,ZAU_Certificate_PDF_Generator::CAP_MANAGE)))return '';
        $posts=get_posts(['post_type'=>'zau_union_benefit','post_status'=>['publish','draft','pending','future','private'],'numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
        if(!$posts)return '<div class="zau-benefit-diagnostics"><strong>Диагностика администратора</strong><p>Ни одной акции ещё не создано. Откройте «Профсоюз → Акции и скидки → Добавить».</p></div>';
        $rows='';
        foreach($posts as $post){
            $check=$this->benefit_visibility($post,$userId,false);
            $reason=!empty($check['reasons'])?implode(' ',array_map('sanitize_text_field',$check['reasons'])):'Акция подходит пользователю.';
            $rows.='<li><strong>'.esc_html(get_the_title($post)?:'(без названия)').'</strong> — '.esc_html($reason).'</li>';
        }
        return '<div class="zau-benefit-diagnostics"><strong>Почему акция не показывается</strong><ul>'.$rows.'</ul><p><small>Этот блок видит только администратор.</small></p></div>';
    }

    public function benefits_shortcode($atts=[]) {
        if(!is_user_logged_in())return $this->auth_shortcode(['return_url'=>home_url(wp_unslash($_SERVER['REQUEST_URI']??'/'))]);
        $userId=get_current_user_id();$benefits=$this->available_benefits($userId);ob_start();?>
        <section class="zau-benefits"><div class="zau-benefits-head"><h3>Акции и скидки</h3><p>Предложения партнёров и Профсоюза, доступные вам.</p></div><?php if(!$benefits):?><div class="zau-empty-state"><strong>Актуальных предложений пока нет</strong><span>Проверьте срок действия и ограничения акции.</span></div><?php echo $this->benefit_admin_diagnostics($userId);?><?php endif;?><div class="zau-benefit-grid">
        <?php foreach($benefits as $post):$discount=get_post_meta($post->ID,'_zau_benefit_discount',true);$partner=get_post_meta($post->ID,'_zau_benefit_partner',true);$promo=get_post_meta($post->ID,'_zau_benefit_promo',true);$button=get_post_meta($post->ID,'_zau_benefit_button',true)?:'Подробнее';$url=get_post_meta($post->ID,'_zau_benefit_url',true);$to=get_post_meta($post->ID,'_zau_benefit_to',true);$context=$this->benefit_render_context[(int)$post->ID]??['preview'=>false,'reasons'=>[]];?>
            <article class="zau-benefit-card"><?php if(has_post_thumbnail($post)):?><div class="zau-benefit-image"><?php echo get_the_post_thumbnail($post,'medium_large');?></div><?php endif;?><div class="zau-benefit-content"><?php if(!empty($context['preview'])):?><div class="zau-benefit-admin-preview"><strong>Предпросмотр администратора</strong><span><?php echo esc_html(implode(' ',(array)$context['reasons']));?></span></div><?php endif;?><?php if($discount):?><span class="zau-benefit-badge"><?php echo esc_html($discount);?></span><?php endif;?><h4><?php echo esc_html(get_the_title($post));?></h4><?php if($partner):?><p class="zau-benefit-partner"><?php echo esc_html($partner);?></p><?php endif;?><div class="zau-benefit-description"><?php echo wp_kses_post(apply_filters('the_content',$post->post_content));?></div><?php if($promo):?><div class="zau-benefit-promo">Промокод: <strong><?php echo esc_html($promo);?></strong></div><?php endif;?><?php if($to):?><small>Действует до <?php echo esc_html(mysql2date('d.m.Y',$to));?></small><?php endif;?><?php if($url):?><a class="zau-union-button" href="<?php echo esc_url($url);?>" target="_blank" rel="noopener"><?php echo esc_html($button);?></a><?php endif;?></div></article>
        <?php endforeach;?></div></section><?php return ob_get_clean();
    }

    public function page_member_statuses() {
        $this->require_cap(ZAU_Certificate_PDF_Generator::CAP_MANAGE);
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));$status=sanitize_text_field(wp_unslash($_GET['status']??''));$orgId=absint($_GET['organization_id']??0);$branchId=absint($_GET['branch_id']??0);
        $perPage=max(25,min(200,absint($_GET['per_page']??100)));$paged=max(1,absint($_GET['paged']??1));$offset=($paged-1)*$perPage;
        $meta=[['key'=>'zau_hidden_from_registry','compare'=>'NOT EXISTS']];if($status!=='')$meta[]=['key'=>'zau_member_status','value'=>$status];if($orgId)$meta[]=['key'=>'zau_organization_id','value'=>$orgId];if($branchId)$meta[]=['key'=>'zau_profile_branch_id','value'=>$branchId];
        $args=['number'=>$perPage,'offset'=>$offset,'count_total'=>true,'orderby'=>'display_name','order'=>'ASC'];if($meta)$args['meta_query']=$meta;if($search!==''){$args['search']='*'.$search.'*';$args['search_columns']=['user_login','user_email','display_name'];}
        $query=new WP_User_Query($args);$users=$query->get_results();$total=(int)$query->get_total();$pages=max(1,(int)ceil($total/$perPage));
        $organizations=$this->organization_rows();$branches=$this->branch_rows(false);global $wpdb;$orgMap=[];foreach($organizations as $org)$orgMap[(int)$org->id]=$org;$branchMap=[];foreach($branches as $branch)$branchMap[(int)$branch->id]=$branch;
        $pagination=paginate_links(['base'=>add_query_arg(['page'=>'zau-union-member-statuses','s'=>$search,'status'=>$status,'organization_id'=>$orgId,'branch_id'=>$branchId,'per_page'=>$perPage,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'type'=>'list','prev_text'=>'‹ Назад','next_text'=>'Вперёд ›']);
        ?>
        <div class="wrap zau-union-admin"><?php zau_admin_hub_nav('users'); ?><div class="zau-union-head"><div><h1>Статусы и одобрение членства</h1><p>Ручное и массовое изменение статусов. Решение «Одобрить» автоматически переводит участника в статус «Состоит в профсоюзе».</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_repair_exit_documents"><button class="button button-secondary">Пересчитать статусы документов</button></form></div>
        <form method="get" class="zau-union-search"><input type="hidden" name="page" value="zau-union-member-statuses"><input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="ФИО или email"><select name="status"><option value="">Все статусы</option><?php foreach($this->membership_statuses() as $item):?><option <?php selected($status,$item);?>><?php echo esc_html($item);?></option><?php endforeach;?></select><select name="organization_id"><option value="0">Все организации</option><?php foreach($organizations as $org):?><option value="<?php echo (int)$org->id;?>" <?php selected($orgId,$org->id);?>><?php echo esc_html($org->name);?></option><?php endforeach;?></select><select name="branch_id"><option value="0">Все филиалы</option><?php foreach($branches as $branch):?><option value="<?php echo (int)$branch->id;?>" <?php selected($branchId,$branch->id);?>><?php echo esc_html($branch->name);?></option><?php endforeach;?></select><select name="per_page"><option value="50" <?php selected($perPage,50);?>>50</option><option value="100" <?php selected($perPage,100);?>>100</option><option value="200" <?php selected($perPage,200);?>>200</option></select><button class="button">Фильтровать</button></form>
        <p><strong>Найдено участников: <?php echo number_format_i18n($total);?></strong> · показано <?php echo number_format_i18n(count($users));?> · страница <?php echo (int)$paged;?> из <?php echo (int)$pages;?></p><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_union_bulk_member_status"><div class="zau-bulk-status-bar"><select name="bulk_action"><option value="set_status">Установить статус</option><option value="approve">Одобрить вступление</option><option value="revision">Вернуть на доработку</option><option value="reject">Отклонить</option></select><select name="bulk_status"><?php foreach($this->membership_statuses() as $item):?><option><?php echo esc_html($item);?></option><?php endforeach;?></select><input name="note" placeholder="Комментарий к изменению"><button class="button button-primary">Применить к выбранным</button></div>
        <table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.zau-status-row-check').forEach(x=>x.checked=this.checked)"></th><th>Участник</th><th>Организация / филиал</th><th>Статус</th><th>Решение</th><th>Карточка</th><th></th></tr></thead><tbody><?php if(!$users):?><tr><td colspan="7">Участники не найдены.</td></tr><?php endif;?><?php foreach($users as $user):$oid=$this->member_org_id($user->ID);$bid=$this->member_branch_id($user->ID);$current=get_user_meta($user->ID,'zau_member_status',true)?:'Регистрация не завершена';$approval=$this->membership_approval_status($user->ID);$card=get_user_meta($user->ID,'zau_member_card_review_status',true)?:'draft';?>
        <tr><td><input class="zau-status-row-check" type="checkbox" name="user_ids[]" value="<?php echo (int)$user->ID;?>"></td><td><strong><?php echo esc_html($user->display_name);?></strong><br><small><?php echo esc_html($user->user_email.' '.get_user_meta($user->ID,'zau_phone',true));?></small></td><td><?php echo esc_html($orgMap[$oid]->name??'—');?><br><small><?php echo esc_html($branchMap[$bid]->name??'—');?></small></td><td><select name="row_status[<?php echo (int)$user->ID;?>]"><?php foreach($this->membership_statuses() as $item):?><option <?php selected($current,$item);?>><?php echo esc_html($item);?></option><?php endforeach;?></select></td><td><?php echo esc_html($this->membership_approval_label($approval));?></td><td><?php echo esc_html($card);?></td><td><button class="button" name="single_user_id" value="<?php echo (int)$user->ID;?>">Сохранить строку</button> <a class="button" href="<?php echo esc_url(add_query_arg(['zau_tab'=>'card','member_id'=>$user->ID],$this->settings()['cabinet_page_id']?get_permalink((int)$this->settings()['cabinet_page_id']):home_url('/lk-profsoyuz/')));?>" target="_blank">Карточка</a></td></tr>
        <?php endforeach;?></tbody></table></form><?php if($pagination):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post($pagination);?></div></div><?php endif;?></div><?php
    }

    private function device_summary() {
        $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') { return 'Неизвестное устройство'; }
        $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'Мобильное устройство' : 'Компьютер';
        $browser = 'Браузер';
        foreach (['Edg'=>'Edge','OPR'=>'Opera','Chrome'=>'Chrome','Firefox'=>'Firefox','Safari'=>'Safari'] as $token=>$name) {
            if (stripos($ua, $token) !== false) { $browser = $name; break; }
        }
        $os = '';
        foreach (['Android'=>'Android','iPhone'=>'iPhone','iPad'=>'iPad','Mac OS X'=>'macOS','Windows'=>'Windows','Linux'=>'Linux'] as $token=>$name) {
            if (stripos($ua, $token) !== false) { $os = $name; break; }
        }
        return trim($device.' · '.$browser.($os?' · '.$os:''));
    }

    public function log_logout($user_id = 0) {
        global $wpdb;
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->logs_table)) !== $this->logs_table) { return; }
        $wpdb->insert($this->logs_table, [
            'user_id'=>$user_id,
            'action'=>'logout',
            'object_type'=>'user',
            'object_id'=>$user_id,
            'details'=>$this->device_summary(),
            'ip'=>$this->client_ip(),
            'created_at'=>current_time('mysql'),
        ]);
    }

    private function client_ip(){foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key){if(!empty($_SERVER[$key])){$ip=sanitize_text_field(wp_unslash($_SERVER[$key]));if($key==='HTTP_X_FORWARDED_FOR')$ip=trim(explode(',',$ip)[0]);return substr($ip,0,64);}}return '';}
    private function log($action,$type='',$id=0,$details=''){global $wpdb;if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->logs_table))!==$this->logs_table)return;$wpdb->insert($this->logs_table,['user_id'=>get_current_user_id(),'action'=>sanitize_key($action),'object_type'=>sanitize_key($type),'object_id'=>(int)$id,'details'=>sanitize_textarea_field($details),'ip'=>$this->client_ip(),'created_at'=>current_time('mysql')]);}
}

ZAU_Union_Module::instance();
