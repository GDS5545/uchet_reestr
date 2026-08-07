<?php
/**
 * Plugin Name: ZAU Профсоюз — регистрация, документы и QR
 * Description: Единый реестр профсоюза с AQNIET Blue UX: регистрация, статусы, филиалы единым текстом, защищённая личная карточка, скрытый wp-admin для участников, акции и скидки, документы/PDF/QR, кабинеты организаций и Elementor.
 * Version: 2.24.10
 * Author: Dauren / ZAU
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: zau-certificate-generator
 */

if (!defined('ABSPATH')) { exit; }

final class ZAU_Certificate_PDF_Generator {
    const VERSION = '2.24.10';
    const DB_VERSION = '2.24.10';
    const OPT_DB_VERSION = 'zau_cert_db_version';
    const OPT_SETTINGS = 'zau_cert_settings';
    const CAP_MANAGE = 'zau_manage_certificates';
    const CAP_CREATE = 'zau_create_certificates';
    const CAP_ORG_MANAGE = 'zau_manage_organization_members';
    const NONCE = 'zau_cert_nonce';

    private static $instance = null;
    private $templates_table;
    private $docs_table;
    private $logs_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->templates_table = $wpdb->prefix . 'zau_cert_templates';
        $this->docs_table = $wpdb->prefix . 'zau_certificates';
        $this->logs_table = $wpdb->prefix . 'zau_cert_logs';

        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_notices', [$this, 'admin_notices']);

        add_action('admin_post_zau_cert_save_template', [$this, 'save_template']);
        add_action('wp_ajax_zau_cert_save_template_ajax', [$this, 'ajax_save_template']);
        add_action('admin_post_zau_cert_delete_template', [$this, 'delete_template']);
        add_action('admin_post_zau_cert_document_action', [$this, 'document_action']);
        add_action('admin_post_zau_cert_save_settings', [$this, 'save_settings']);
        add_action('admin_post_zau_union_secure_document', [$this, 'secure_document']);

        add_action('wp_ajax_zau_cert_prepare_document', [$this, 'ajax_prepare_document']);
        add_action('wp_ajax_zau_cert_finalize_document', [$this, 'ajax_finalize_document']);
        add_action('wp_ajax_zau_cert_parse_import', [$this, 'ajax_parse_import']);
        add_action('wp_ajax_zau_cert_prepare_regeneration', [$this, 'ajax_prepare_regeneration']);

        add_shortcode('zau_certificate_verify', [$this, 'verify_shortcode']);
        add_shortcode('zau_my_certificates', [$this, 'my_certificates_shortcode']);
        add_filter('query_vars', function($vars){ $vars[] = 'zau_verify'; return $vars; });
    }

    public static function activate() {
        $self = self::instance();
        $self->install_tables();
        $self->install_roles();
        $self->ensure_verify_page();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        if (class_exists('ZAU_Union_Module')) { ZAU_Union_Module::instance()->maybe_upgrade(); }
        if (class_exists('ZAU_Legacy_Import')) { ZAU_Legacy_Import::instance()->maybe_upgrade(); }
        if (class_exists('ZAU_Bulk_Regeneration')) { ZAU_Bulk_Regeneration::instance()->install_tables(); update_option(ZAU_Bulk_Regeneration::OPT_DB_VERSION, ZAU_Bulk_Regeneration::DB_VERSION, false); }
        flush_rewrite_rules();
    }

    public static function deactivate() { flush_rewrite_rules(); }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            $this->install_roles();
            $this->ensure_verify_page();
            $privacy = $this->settings();
            $privacy['verification_private'] = 0;
            $privacy['public_pdf'] = 0;
            update_option(self::OPT_SETTINGS, $privacy, false);
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    private function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$this->templates_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            orientation varchar(20) NOT NULL DEFAULT 'landscape',
            page_width int(11) NOT NULL DEFAULT 2480,
            page_height int(11) NOT NULL DEFAULT 1754,
            background_id bigint(20) unsigned NOT NULL DEFAULT 0,
            background_url text NULL,
            background_mode varchar(20) NOT NULL DEFAULT 'stretch',
            document_prefix varchar(60) NULL,
            number_pattern varchar(190) NULL,
            number_digits int(11) NOT NULL DEFAULT 6,
            fields_json longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY orientation (orientation)
        ) $charset;";

        $sql2 = "CREATE TABLE {$this->docs_table} (
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
            file_revision bigint(20) unsigned NOT NULL DEFAULT 0,
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
        ) $charset;";

        $sql3 = "CREATE TABLE {$this->logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(100) NOT NULL,
            object_type varchar(50) NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details text NULL,
            ip varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql1); dbDelta($sql2); dbDelta($sql3);
    }

    private function install_roles() {
        $manager_caps = ['read'=>true, self::CAP_MANAGE=>true, self::CAP_CREATE=>true, 'upload_files'=>true];
        $operator_caps = ['read'=>true, self::CAP_CREATE=>true, 'upload_files'=>true];
        $org_manager_caps = ['read'=>true, self::CAP_ORG_MANAGE=>true];
        add_role('zau_certificate_manager', 'Менеджер сертификатов', $manager_caps);
        add_role('zau_certificate_operator', 'Оператор сертификатов', $operator_caps);
        add_role('zau_organization_manager', 'Ответственный организации', $org_manager_caps);
        $manager = get_role('zau_certificate_manager');
        if ($manager) { foreach ($manager_caps as $cap=>$grant) { if ($grant) { $manager->add_cap($cap); } } }
        $operator = get_role('zau_certificate_operator');
        if ($operator) { foreach ($operator_caps as $cap=>$grant) { if ($grant) { $operator->add_cap($cap); } } }
        $orgManager = get_role('zau_organization_manager');
        if ($orgManager) { foreach ($org_manager_caps as $cap=>$grant) { if ($grant) { $orgManager->add_cap($cap); } } }
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap(self::CAP_MANAGE); $admin->add_cap(self::CAP_CREATE); $admin->add_cap(self::CAP_ORG_MANAGE); }
    }

    private function ensure_verify_page() {
        $settings = $this->settings();
        $page_id = absint($settings['verify_page_id'] ?? 0);
        if ($page_id && get_post($page_id)) { return; }
        $existing = get_page_by_path('proverka-dokumenta');
        if ($existing) { $page_id = $existing->ID; }
        else {
            $page_id = wp_insert_post([
                'post_title' => 'Проверка документа',
                'post_name' => 'proverka-dokumenta',
                'post_content' => '[zau_certificate_verify]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }
        if (!is_wp_error($page_id) && $page_id) {
            $settings['verify_page_id'] = (int)$page_id;
            update_option(self::OPT_SETTINGS, $settings, false);
        }
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT_SETTINGS, []), [
            'prefix' => 'ZAU',
            'verify_page_id' => 0,
            'public_pdf' => 0,
            'public_organization' => 0,
            'public_status' => 0,
            'verification_private' => 0,
            'owner_pdf_view' => 1,
            'manager_pdf_view' => 0,
            'cabinet_document_mode' => 'both',
        ]);
    }

    public function admin_menu() {
        add_menu_page('Профсоюз', 'Профсоюз', self::CAP_CREATE, 'zau-certificates', [$this, 'page_create'], 'dashicons-groups', 56);
        add_submenu_page('zau-certificates', 'Создать документ', 'Создать документ', self::CAP_CREATE, 'zau-certificates', [$this, 'page_create']);
        add_submenu_page('zau-certificates', 'Шаблоны', 'Шаблоны', self::CAP_MANAGE, 'zau-cert-templates', [$this, 'page_templates']);
        add_submenu_page('zau-certificates', 'Массовый импорт', 'Массовый импорт', self::CAP_CREATE, 'zau-cert-import', [$this, 'page_import']);
        add_submenu_page('zau-certificates', 'Реестр', 'Реестр', self::CAP_CREATE, 'zau-cert-registry', [$this, 'page_registry']);
        add_submenu_page('zau-certificates', 'Настройки', 'Настройки', self::CAP_MANAGE, 'zau-cert-settings', [$this, 'page_settings']);
        add_submenu_page('zau-certificates', 'Журнал', 'Журнал', self::CAP_MANAGE, 'zau-cert-logs', [$this, 'page_logs']);
        add_submenu_page(null, 'Пересоздание документа', 'Пересоздание документа', self::CAP_MANAGE, 'zau-cert-regenerate', [$this, 'page_regenerate']);
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'zau-cert') === false && strpos((string)$hook, 'zau_certificate') === false) { return; }
        wp_enqueue_media();
        wp_enqueue_style('zau-cert-admin', plugins_url('assets/css/admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('zau-qrcode', plugins_url('assets/js/qrcode.min.js', __FILE__), [], '1.0.0', true);
        wp_enqueue_script('zau-cert-admin', plugins_url('assets/js/admin.js', __FILE__), ['jquery', 'zau-qrcode'], self::VERSION, true);

        $templates = $this->get_templates();
        foreach ($templates as &$tpl) { $tpl->fields = $this->decode_fields($tpl->fields_json, $tpl->orientation); unset($tpl->fields_json); }
        $regenerate_job = null;
        if (!empty($_GET['page']) && $_GET['page'] === 'zau-cert-regenerate' && !empty($_GET['id'])) {
            $regenerate_job = ['documentId'=>absint($_GET['id'])];
        }
        wp_localize_script('zau-cert-admin', 'ZAUCertData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'templates' => $templates,
            'regenerateJob' => $regenerate_job,
            'fieldDefinitions' => $this->field_definitions(),
            'fieldTypes' => apply_filters('zau_cert_field_types', [
                'signature_url'=>'image', 'signature2_url'=>'image', 'stamp_url'=>'image'
            ]),
            'currentTemplate' => isset($_GET['edit']) ? $this->get_template(absint($_GET['edit'])) : null,
            'defaultPrefix' => $this->settings()['prefix'],
            'strings' => [
                'chooseImage' => 'Выберите подложку',
                'useImage' => 'Использовать подложку',
                'generating' => 'Формируется документ…',
                'error' => 'Не удалось сформировать документ.',
            ],
        ]);
    }

    public function admin_notices() {
        if (!empty($_GET['zau_error'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['zau_error']));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            return;
        }
        if (empty($_GET['zau_notice'])) { return; }
        $msg = sanitize_text_field(wp_unslash($_GET['zau_notice']));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    private function require_cap($cap) {
        if (!current_user_can($cap)) { wp_die('Недостаточно прав.'); }
    }

    private function field_definitions() {
        $definitions = [
            'full_name' => 'ФИО',
            'document_title' => 'Название документа / курса',
            'organization' => 'Организация',
            'issue_date' => 'Дата выдачи / регистрации',
            'document_no' => 'Номер документа',
            'member_status' => 'Статус / членство',
            'extra1' => 'Дополнительное поле 1',
            'extra2' => 'Дополнительное поле 2',
            'extra3' => 'Дополнительное поле 3',
            'extra4' => 'Дополнительное поле 4',
            'signature_url' => 'Подпись участника (изображение)',
            'signature2_url' => 'Вторая подпись (изображение)',
            'stamp_url' => 'Печать / штамп (изображение)',
            'verify_url' => 'Ссылка проверки',
            'qr' => 'QR-код',
        ];
        return apply_filters('zau_cert_field_definitions', $definitions);
    }

    private function default_fields($orientation = 'landscape') {
        $portrait = $orientation === 'portrait';
        return [
            'full_name' => ['enabled'=>1,'x'=>12,'y'=>34,'width'=>76,'fontSize'=>$portrait?74:78,'fontFamily'=>'Georgia','color'=>'#111111','align'=>'center','bold'=>1,'italic'=>0,'lineHeight'=>1.15,'maxLines'=>2],
            'document_title' => ['enabled'=>1,'x'=>16,'y'=>47,'width'=>68,'fontSize'=>$portrait?44:46,'fontFamily'=>'Arial','color'=>'#222222','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>3],
            'organization' => ['enabled'=>1,'x'=>18,'y'=>60,'width'=>64,'fontSize'=>30,'fontFamily'=>'Arial','color'=>'#333333','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2],
            'issue_date' => ['enabled'=>1,'x'=>10,'y'=>79,'width'=>28,'fontSize'=>28,'fontFamily'=>'Arial','color'=>'#222222','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1],
            'document_no' => ['enabled'=>1,'x'=>62,'y'=>79,'width'=>28,'fontSize'=>28,'fontFamily'=>'Arial','color'=>'#222222','align'=>'right','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>1],
            'member_status' => ['enabled'=>0,'x'=>25,'y'=>68,'width'=>50,'fontSize'=>30,'fontFamily'=>'Arial','color'=>'#16713d','align'=>'center','bold'=>1,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>2],
            'extra1' => ['enabled'=>0,'x'=>15,'y'=>72,'width'=>70,'fontSize'=>26,'fontFamily'=>'Arial','color'=>'#222222','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2],
            'extra2' => ['enabled'=>0,'x'=>15,'y'=>76,'width'=>70,'fontSize'=>26,'fontFamily'=>'Arial','color'=>'#222222','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2],
            'extra3' => ['enabled'=>0,'x'=>15,'y'=>80,'width'=>70,'fontSize'=>26,'fontFamily'=>'Arial','color'=>'#222222','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2],
            'extra4' => ['enabled'=>0,'x'=>15,'y'=>84,'width'=>70,'fontSize'=>26,'fontFamily'=>'Arial','color'=>'#222222','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2],
            'signature_url' => ['enabled'=>0,'x'=>12,'y'=>78,'width'=>22,'height'=>9,'fit'=>'contain','opacity'=>1,'fontSize'=>8,'fontFamily'=>'Arial','color'=>'#111111','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1],
            'signature2_url' => ['enabled'=>0,'x'=>39,'y'=>78,'width'=>22,'height'=>9,'fit'=>'contain','opacity'=>1,'fontSize'=>8,'fontFamily'=>'Arial','color'=>'#111111','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1],
            'stamp_url' => ['enabled'=>0,'x'=>66,'y'=>73,'width'=>17,'height'=>17,'fit'=>'contain','opacity'=>0.95,'fontSize'=>8,'fontFamily'=>'Arial','color'=>'#111111','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1],
            'verify_url' => ['enabled'=>0,'x'=>38,'y'=>92,'width'=>50,'fontSize'=>18,'fontFamily'=>'Arial','color'=>'#444444','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1.1,'maxLines'=>2],
            'qr' => ['enabled'=>1,'x'=>83,'y'=>82,'width'=>12,'fontSize'=>0,'fontFamily'=>'Arial','color'=>'#000000','align'=>'center','bold'=>0,'italic'=>0,'lineHeight'=>1,'maxLines'=>1],
        ];
    }

    private function decode_fields($json, $orientation = 'landscape') {
        $defaults = $this->default_fields($orientation);
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) { return $defaults; }
        foreach ($defaults as $key => $def) {
            $decoded[$key] = wp_parse_args(isset($decoded[$key]) && is_array($decoded[$key]) ? $decoded[$key] : [], $def);
        }
        return $decoded;
    }

    private function get_templates() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->templates_table} ORDER BY updated_at DESC, id DESC");
    }

    private function get_template($id) {
        global $wpdb;
        $tpl = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id=%d", $id));
        if ($tpl) { $tpl->fields = $this->decode_fields($tpl->fields_json, $tpl->orientation); }
        return $tpl;
    }

    public function page_templates() {
        $this->require_cap(self::CAP_MANAGE);
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        if ($edit_id || isset($_GET['new'])) { $this->render_template_editor($edit_id); return; }
        $items = $this->get_templates();
        ?>
        <div class="wrap zau-wrap">
            <div class="zau-page-head"><div><h1>Шаблоны документов</h1><p>Подложка, ориентация и расположение полей сохраняются отдельно для каждого шаблона.</p></div><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-templates&new=1')); ?>">Добавить шаблон</a></div>
            <table class="widefat striped zau-table"><thead><tr><th>ID</th><th>Название</th><th>Префикс</th><th>Ориентация</th><th>Размер</th><th>Обновлён</th><th></th></tr></thead><tbody>
            <?php if (!$items): ?><tr><td colspan="7">Шаблонов пока нет.</td></tr><?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo (int)$item->id; ?></td>
                    <td><strong><?php echo esc_html($item->name); ?></strong></td>
                    <td><code><?php echo esc_html($item->document_prefix ?: $this->settings()['prefix']); ?></code></td>
                    <td><span class="zau-badge"><?php echo $item->orientation === 'portrait' ? 'Книжная' : 'Альбомная'; ?></span></td>
                    <td><?php echo (int)$item->page_width . ' × ' . (int)$item->page_height; ?> px</td>
                    <td><?php echo esc_html($item->updated_at); ?></td>
                    <td class="zau-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-templates&edit='.(int)$item->id)); ?>">Изменить</a>
                    <a class="button button-link-delete" onclick="return confirm('Удалить шаблон? Уже созданные документы останутся.');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_delete_template&id='.(int)$item->id), self::NONCE)); ?>">Удалить</a></td>
                </tr>
            <?php endforeach; ?></tbody></table>
        </div>
        <?php
    }

    private function render_template_editor($id = 0) {
        $tpl = $id ? $this->get_template($id) : null;
        $orientation = $tpl ? $tpl->orientation : 'landscape';
        $fields = $tpl ? $tpl->fields : $this->default_fields($orientation);
        ?>
        <div class="wrap zau-wrap" id="zau-template-editor" data-template-id="<?php echo (int)$id; ?>">
            <div class="zau-page-head"><div><h1><?php echo $tpl ? 'Редактирование шаблона' : 'Новый шаблон'; ?></h1><p>Перетаскивайте поля мышью. Точные параметры выбранного поля задаются справа.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-templates')); ?>">Назад</a></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zau-template-form" novalidate>
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="zau_cert_save_template">
                <input type="hidden" name="template_id" value="<?php echo (int)$id; ?>">
                <input type="hidden" name="fields_json" id="zau-fields-json" value="<?php echo esc_attr(wp_json_encode($fields)); ?>">
                <div class="zau-template-top">
                    <label>Название шаблона<input type="text" name="name" class="regular-text" required value="<?php echo esc_attr($tpl->name ?? ''); ?>" placeholder="Например: Заявление на вступление"></label>
                    <label>Ориентация<select name="orientation" id="zau-orientation"><option value="portrait" <?php selected($orientation, 'portrait'); ?>>Книжная (вертикальная)</option><option value="landscape" <?php selected($orientation, 'landscape'); ?>>Альбомная (горизонтальная)</option></select></label>
                    <label>Режим подложки<select name="background_mode" id="zau-background-mode"><option value="stretch" <?php selected($tpl->background_mode ?? 'stretch','stretch'); ?>>Растянуть по странице</option><option value="contain" <?php selected($tpl->background_mode ?? 'stretch','contain'); ?>>Вместить целиком</option><option value="cover" <?php selected($tpl->background_mode ?? 'stretch','cover'); ?>>Заполнить с обрезкой</option></select></label>
                    <div class="zau-bg-picker"><input type="hidden" name="background_id" id="zau-background-id" value="<?php echo (int)($tpl->background_id ?? 0); ?>"><input type="url" name="background_url" id="zau-background-url" value="<?php echo esc_attr($tpl->background_url ?? ''); ?>" placeholder="URL JPG/PNG"><button type="button" class="button" id="zau-select-background">Выбрать подложку</button></div>
                </div>
                <div class="zau-template-numbering">
                    <label>Префикс этого шаблона<input type="text" name="document_prefix" value="<?php echo esc_attr($tpl->document_prefix ?? ''); ?>" placeholder="Пусто = общий префикс <?php echo esc_attr($this->settings()['prefix']); ?>"><small>Например: VST, VZNOS или AQN.</small></label>
                    <label>Формат номера<input type="text" name="number_pattern" value="<?php echo esc_attr($tpl->number_pattern ?? '{prefix}-{year}-{number}'); ?>" placeholder="{prefix}-{year}-{number}"><small>Переменные: {prefix}, {year}, {month}, {day}, {number}, {id}.</small></label>
                    <label>Цифр в порядковом номере<input type="number" name="number_digits" min="1" max="12" value="<?php echo (int)($tpl->number_digits ?? 6); ?>"><small>Например 6 → 000001.</small></label>
                    <div class="zau-number-example"><span>Пример</span><strong data-zau-number-example><?php echo esc_html(($tpl->document_prefix ?: $this->settings()['prefix']).'-'.wp_date('Y').'-000001'); ?></strong></div>
                </div>
                <div class="zau-editor-grid">
                    <div class="zau-stage-column">
                        <div class="zau-stage-toolbar">
                            <button type="submit" class="button button-primary" id="zau-template-save-top">Сохранить</button>
                            <button type="button" class="button" id="zau-stage-fit">По экрану</button>
                            <button type="button" class="button" id="zau-stage-zoom-out" aria-label="Уменьшить масштаб">−</button>
                            <button type="button" class="button" id="zau-stage-zoom-in" aria-label="Увеличить масштаб">+</button>
                            <label class="zau-stage-grid-toggle"><input type="checkbox" id="zau-stage-grid"> Сетка</label>
                            <span id="zau-stage-size-status" class="zau-stage-size-status"></span>
                        </div>
                        <div id="zau-stage-warning" class="zau-stage-warning" hidden></div>
                        <div id="zau-stage-scroll" class="zau-stage-scroll">
                            <div id="zau-stage" class="zau-stage" data-orientation="<?php echo esc_attr($orientation); ?>"><img id="zau-stage-bg" alt="" src="<?php echo esc_url($tpl->background_url ?? ''); ?>"><div id="zau-stage-fields"></div></div>
                        </div>
                        <p class="description">Редактор всегда сохраняет точное соотношение сторон страницы. Рекомендуемые размеры: книжная — 1754×2480 px; альбомная — 2480×1754 px. Если пропорции загруженной подложки отличаются, используйте режим «Вместить целиком» либо подготовьте изображение точного размера.</p>
                    </div>
                    <div class="zau-control-column">
                        <div class="zau-field-list-column">
                            <input type="search" id="zau-field-search" class="zau-field-search" placeholder="Поиск поля, например: филиал, БИК, реквизиты…">
                            <p class="description">Отметьте галочками нужные поля (например ФИО, телефон, email) и добавьте их на шаблон одной кнопкой.</p>
                            <div class="zau-field-bulk-bar" id="zau-field-bulk-bar" hidden>
                                <span id="zau-field-bulk-count"></span>
                                <button type="button" class="button button-primary button-small" id="zau-field-bulk-add">Добавить выбранные</button>
                                <button type="button" class="button button-small" id="zau-field-bulk-clear">Снять отметки</button>
                            </div>
                            <div class="zau-field-list" id="zau-field-list"></div>
                        </div>
                        <div class="zau-field-panel" id="zau-field-panel"><h3>Параметры поля</h3><p>Выберите поле слева или на подложке.</p></div>
                    </div>
                </div>
                <div class="zau-template-save-row"><?php submit_button($tpl ? 'Сохранить изменения' : 'Создать шаблон', 'primary large', 'submit', false); ?><span id="zau-template-save-status" class="zau-template-save-status" aria-live="polite"></span></div>
            </form>
        </div>
        <?php
    }

    private function template_payload_from_request(array $request) {
        $id = absint($request['template_id'] ?? 0);
        $orientation = sanitize_key($request['orientation'] ?? 'landscape');
        if (!in_array($orientation, ['portrait','landscape'], true)) { $orientation = 'landscape'; }
        $width = $orientation === 'portrait' ? 1754 : 2480;
        $height = $orientation === 'portrait' ? 2480 : 1754;
        $mode = sanitize_key($request['background_mode'] ?? 'stretch');
        if (!in_array($mode, ['stretch','contain','cover'], true)) { $mode = 'stretch'; }
        $name = sanitize_text_field(wp_unslash($request['name'] ?? ''));
        if ($name === '') { return new WP_Error('zau_template_name', 'Укажите название шаблона.'); }

        $raw_fields = wp_unslash($request['fields_json'] ?? '');
        if ($raw_fields === '') { return new WP_Error('zau_template_fields_empty', 'Не получены координаты полей. Обновите страницу редактора и повторите сохранение.'); }
        $decoded = json_decode($raw_fields, true);
        if (!is_array($decoded)) {
            return new WP_Error('zau_template_fields_json', 'Не удалось прочитать параметры полей: ' . json_last_error_msg());
        }
        $fields = $this->sanitize_fields($decoded, $orientation);
        if (!$fields) { return new WP_Error('zau_template_fields_invalid', 'После проверки не осталось ни одного поля шаблона.'); }

        $background_url = trim((string)wp_unslash($request['background_url'] ?? ''));
        if ($background_url !== '') {
            $background_url = esc_url_raw($background_url, ['http','https']);
            if ($background_url === '') { return new WP_Error('zau_template_background', 'Адрес подложки некорректен. Выберите изображение заново через медиабиблиотеку.'); }
        }

        return [
            'id' => $id,
            'data' => [
                'name' => $name,
                'orientation' => $orientation,
                'page_width' => $width,
                'page_height' => $height,
                'background_id' => absint($request['background_id'] ?? 0),
                'background_url' => $background_url,
                'background_mode' => $mode,
                'document_prefix' => sanitize_text_field(wp_unslash($request['document_prefix'] ?? '')),
                'number_pattern' => sanitize_text_field(wp_unslash($request['number_pattern'] ?? '{prefix}-{year}-{number}')),
                'number_digits' => max(1, min(12, absint($request['number_digits'] ?? 6))),
                'fields_json' => wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => current_time('mysql'),
            ],
        ];
    }

    private function persist_template_payload(array $payload) {
        global $wpdb;
        $id = (int)$payload['id'];
        $data = $payload['data'];
        $action = $id ? 'template_updated' : 'template_created';

        if ($id) {
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->templates_table} WHERE id=%d", $id));
            if (!$exists) { return new WP_Error('zau_template_missing', 'Шаблон не найден. Возможно, он был удалён в другой вкладке.'); }
            $result = $wpdb->update($this->templates_table, $data, ['id'=>$id]);
            if ($result === false) {
                // На некоторых сайтах обновление ZIP не запускает активационный хук. Повторяем schema upgrade и запись.
                $this->install_tables();
                $result = $wpdb->update($this->templates_table, $data, ['id'=>$id]);
            }
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($this->templates_table, $data);
            if ($result === false) {
                $this->install_tables();
                $result = $wpdb->insert($this->templates_table, $data);
            }
            if ($result !== false) { $id = (int)$wpdb->insert_id; }
        }

        if ($result === false || !$id) {
            $error = $wpdb->last_error ? $wpdb->last_error : 'неизвестная ошибка базы данных';
            return new WP_Error('zau_template_db', 'Не удалось сохранить шаблон: ' . $error);
        }

        $saved = $this->get_template($id);
        if (!$saved) { return new WP_Error('zau_template_verify', 'Запись отправлена в базу, но не удалось повторно открыть сохранённый шаблон.'); }
        $this->log($action, 'template', $id, $data['name'].'; '.$data['orientation']);
        do_action('zau_cert_template_saved', $id, $action);
        return ['id'=>$id, 'action'=>$action, 'template'=>$saved];
    }

    public function save_template() {
        $this->require_cap(self::CAP_MANAGE);
        check_admin_referer(self::NONCE);
        $payload = $this->template_payload_from_request($_POST);
        if (is_wp_error($payload)) {
            $id = absint($_POST['template_id'] ?? 0);
            wp_safe_redirect(admin_url('admin.php?page=zau-cert-templates&'.($id?'edit='.$id:'new=1').'&zau_error='.rawurlencode($payload->get_error_message()))); exit;
        }
        $saved = $this->persist_template_payload($payload);
        if (is_wp_error($saved)) {
            $id = absint($_POST['template_id'] ?? 0);
            wp_safe_redirect(admin_url('admin.php?page=zau-cert-templates&'.($id?'edit='.$id:'new=1').'&zau_error='.rawurlencode($saved->get_error_message()))); exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=zau-cert-templates&edit='.$saved['id'].'&zau_notice='.rawurlencode('Шаблон сохранён. Координаты и параметры полей проверены повторным чтением из базы.'))); exit;
    }

    public function ajax_save_template() {
        $this->require_cap(self::CAP_MANAGE);
        check_ajax_referer(self::NONCE);
        $payload = $this->template_payload_from_request($_POST);
        if (is_wp_error($payload)) { wp_send_json_error(['message'=>$payload->get_error_message()], 400); }
        $saved = $this->persist_template_payload($payload);
        if (is_wp_error($saved)) { wp_send_json_error(['message'=>$saved->get_error_message()], 500); }
        wp_send_json_success([
            'message'=>'Шаблон сохранён.',
            'template_id'=>(int)$saved['id'],
            'edit_url'=>admin_url('admin.php?page=zau-cert-templates&edit='.(int)$saved['id']),
            'updated_at'=>$saved['template']->updated_at ?? current_time('mysql'),
        ]);
    }

    private function sanitize_fields($input, $orientation) {
        $defaults = $this->default_fields($orientation);
        $definitions = $this->field_definitions();
        $keys = array_values(array_unique(array_merge(array_keys($defaults), array_keys($definitions), array_keys((array)$input))));
        $out = [];
        $generic = ['enabled'=>0,'x'=>10,'y'=>10,'width'=>60,'fontSize'=>32,'fontFamily'=>'Arial','color'=>'#111111','align'=>'left','bold'=>0,'italic'=>0,'lineHeight'=>1.2,'maxLines'=>2,'height'=>10,'fit'=>'contain','opacity'=>1];
        $types = apply_filters('zau_cert_field_types', ['signature_url'=>'image','signature2_url'=>'image','stamp_url'=>'image']);

        foreach ($keys as $raw_key) {
            $key = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)$raw_key);
            if ($key === '') { continue; }
            $def = isset($defaults[$key]) && is_array($defaults[$key]) ? wp_parse_args($defaults[$key], $generic) : $generic;
            $v = isset($input[$raw_key]) && is_array($input[$raw_key]) ? $input[$raw_key] : (isset($input[$key]) && is_array($input[$key]) ? $input[$key] : []);
            $is_image = in_array($key, ['signature_url','signature2_url','stamp_url'], true) || (($types[$key] ?? '') === 'image');
            $is_qr = $key === 'qr';
            $out[$key] = [
                'enabled' => empty($v['enabled']) ? 0 : 1,
                'x' => max(0, min(100, (float)($v['x'] ?? $def['x']))),
                'y' => max(0, min(100, (float)($v['y'] ?? $def['y']))),
                'width' => max(2, min(100, (float)($v['width'] ?? $def['width']))),
                'fontSize' => max(8, min(240, (int)($v['fontSize'] ?? $def['fontSize']))),
                'fontFamily' => in_array(($v['fontFamily'] ?? ''), ['Arial','Georgia','Times New Roman','Verdana','Tahoma'], true) ? $v['fontFamily'] : $def['fontFamily'],
                'color' => sanitize_hex_color($v['color'] ?? $def['color']) ?: $def['color'],
                'align' => in_array(($v['align'] ?? ''), ['left','center','right'], true) ? $v['align'] : $def['align'],
                'bold' => empty($v['bold']) ? 0 : 1,
                'italic' => empty($v['italic']) ? 0 : 1,
                'lineHeight' => max(0.8, min(2.5, (float)($v['lineHeight'] ?? $def['lineHeight']))),
                'maxLines' => max(1, min(10, (int)($v['maxLines'] ?? $def['maxLines']))),
                'height' => max(2, min(100, (float)($v['height'] ?? ($def['height'] ?? 10)))),
                'fit' => in_array(($v['fit'] ?? ''), ['contain','cover','stretch'], true) ? $v['fit'] : ($def['fit'] ?? 'contain'),
                'opacity' => max(0.1, min(1, (float)($v['opacity'] ?? ($def['opacity'] ?? 1)))),
            ];
            if ($is_qr || $is_image) { $out[$key]['fontSize'] = 8; }
        }
        return $out;
    }

    public function delete_template() {
        $this->require_cap(self::CAP_MANAGE); check_admin_referer(self::NONCE);
        global $wpdb; $id=absint($_GET['id'] ?? 0); if ($id) { $wpdb->delete($this->templates_table, ['id'=>$id]); $this->log('template_deleted','template',$id); }
        wp_safe_redirect(admin_url('admin.php?page=zau-cert-templates&zau_notice='.rawurlencode('Шаблон удалён.'))); exit;
    }

    public function page_create() {
        $this->require_cap(self::CAP_CREATE);
        $templates = $this->get_templates();
        ?>
        <div class="wrap zau-wrap">
            <div class="zau-page-head"><div><h1>Создать документ</h1><p>Формат PDF автоматически берётся из выбранного шаблона. Подписи и печати можно выбирать из медиабиблиотеки или вставлять URL старых файлов WPForms.</p></div></div>
            <?php if (!$templates): ?><div class="notice notice-warning inline"><p>Сначала создайте шаблон и загрузите подложку. <a href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-templates&new=1')); ?>">Создать шаблон</a></p></div><?php return; endif; ?>
            <form id="zau-create-document" class="zau-card">
                <div class="zau-form-grid">
                    <label>Шаблон<select name="template_id" id="zau-create-template" required><option value="">Выберите шаблон</option><?php foreach($templates as $tpl): ?><option value="<?php echo (int)$tpl->id; ?>"><?php echo esc_html($tpl->name . ' — ' . ($tpl->orientation==='portrait'?'книжный':'альбомный')); ?></option><?php endforeach; ?></select></label>
                    <label>ФИО<input type="text" name="full_name" required></label>
                    <label>Название документа / курса<input type="text" name="document_title"></label>
                    <label>Организация<input type="text" name="organization"></label>
                    <label>Дата<input type="text" name="issue_date" value="<?php echo esc_attr(wp_date('d.m.Y')); ?>"></label>
                    <label>Номер документа<input type="text" name="document_no" placeholder="Оставьте пустым для автоматического номера"></label>
                    <label>Статус / членство<input type="text" name="member_status" placeholder="Например: Состоит в профсоюзе"></label>
                    <label>Привязать к пользователю WordPress<div class="zau-media-field"><input type="number" min="0" name="user_id" id="zau-create-user-id" value="0"><button type="button" class="button" id="zau-create-user-fetch">Подставить данные</button></div><span class="description" id="zau-create-user-status">ID пользователя, 0 — без привязки. «Подставить данные» заполняет ФИО/организацию и добавляет реквизиты филиала, организации и профсоюза (если пользователь регистрировался через форму) во все системные поля шаблона.</span></label>
                    <label>Подпись участника<div class="zau-media-field"><input type="url" name="signature_url" placeholder="URL PNG/JPG подписи"><button type="button" class="button zau-select-document-image">Выбрать</button></div></label>
                    <label>Вторая подпись<div class="zau-media-field"><input type="url" name="signature2_url" placeholder="URL PNG/JPG подписи"><button type="button" class="button zau-select-document-image">Выбрать</button></div></label>
                    <label>Печать / штамп<div class="zau-media-field"><input type="url" name="stamp_url" placeholder="URL PNG/JPG печати"><button type="button" class="button zau-select-document-image">Выбрать</button></div></label>
                    <label>Дополнительное поле 1<input type="text" name="extra1"></label>
                    <label>Дополнительное поле 2<input type="text" name="extra2"></label>
                    <label>Дополнительное поле 3<input type="text" name="extra3"></label>
                    <label>Дополнительное поле 4<input type="text" name="extra4"></label>
                </div>
                <div id="zau-template-summary" class="zau-template-summary"></div>
                <button type="submit" class="button button-primary button-hero">Создать PDF с QR</button>
                <span id="zau-create-progress"></span>
            </form>
            <div id="zau-create-result"></div>
            <div id="zau-render-holder" aria-hidden="true"></div>
        </div>
        <?php
    }

    public function page_import() {
        $this->require_cap(self::CAP_CREATE);
        $templates = $this->get_templates();
        ?>
        <div class="wrap zau-wrap">
            <div class="zau-page-head"><div><h1>Массовый импорт</h1><p>Загрузите CSV или XLSX. Документы будут последовательно сформированы в браузере с выбранной ориентацией шаблона.</p></div></div>
            <?php if (!$templates): ?><div class="notice notice-warning inline"><p>Сначала создайте шаблон.</p></div><?php return; endif; ?>
            <form id="zau-import-form" class="zau-card" enctype="multipart/form-data">
                <div class="zau-form-grid">
                    <label>Шаблон<select name="template_id" required><option value="">Выберите шаблон</option><?php foreach($templates as $tpl): ?><option value="<?php echo (int)$tpl->id; ?>"><?php echo esc_html($tpl->name . ' — ' . ($tpl->orientation==='portrait'?'книжный':'альбомный')); ?></option><?php endforeach; ?></select></label>
                    <label>CSV или XLSX<input type="file" name="import_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
                    <label>Привязать к пользователю WordPress<input type="number" name="user_id" value="0" min="0"><span class="description">Один ID для всей партии, 0 — без привязки.</span></label>
                </div>
                <p>Заголовки: <code>ФИО</code>, <code>Название</code>, <code>Организация</code>, <code>Дата</code>, <code>Номер</code>, <code>Статус</code>, <code>Подпись</code>, <code>Вторая подпись</code>, <code>Печать</code>, <code>Доп. поле 1–4</code>. Для изображений указывается полный URL PNG/JPG. Поддерживаются также английские названия.</p>
                <button type="submit" class="button button-primary button-hero">Начать импорт</button>
                <span id="zau-import-progress"></span>
            </form>
            <div id="zau-import-result"></div><div id="zau-import-render-holder" aria-hidden="true"></div>
        </div>
        <?php
    }

    public function ajax_parse_import() {
        $this->require_ajax_cap(self::CAP_CREATE);
        check_ajax_referer(self::NONCE, 'nonce');
        if (empty($_FILES['import_file']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(['message'=>'Файл не получен.'], 400);
        }
        $name = sanitize_file_name(wp_unslash($_FILES['import_file']['name']));
        $tmp = $_FILES['import_file']['tmp_name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            if ($ext === 'csv') { $matrix = $this->parse_csv_file($tmp); }
            elseif ($ext === 'xlsx') { $matrix = $this->parse_xlsx_file($tmp); }
            else { wp_send_json_error(['message'=>'Поддерживаются только CSV и XLSX.'], 400); }
        } catch (Throwable $e) {
            wp_send_json_error(['message'=>'Ошибка чтения файла: '.$e->getMessage()], 400);
        }
        if (count($matrix) < 2) { wp_send_json_error(['message'=>'В файле нет строк для импорта.'], 400); }
        $headers = array_map([$this, 'normalize_import_header'], array_shift($matrix));
        $rows = [];
        foreach ($matrix as $line) {
            if (count($rows) >= 1000) { break; }
            $row = [];
            foreach ($headers as $i=>$key) { if ($key && isset($line[$i])) { $row[$key] = trim((string)$line[$i]); } }
            if (empty($row['full_name'])) { continue; }
            if (!empty($row['issue_date']) && is_numeric($row['issue_date']) && (float)$row['issue_date'] > 1000) {
                $row['issue_date'] = gmdate('d.m.Y', (int)round(((float)$row['issue_date'] - 25569) * DAY_IN_SECONDS));
            }
            $rows[] = wp_parse_args($row, ['document_title'=>'','organization'=>'','issue_date'=>'','document_no'=>'','member_status'=>'','signature_url'=>'','signature2_url'=>'','stamp_url'=>'','extra1'=>'','extra2'=>'','extra3'=>'','extra4'=>'']);
        }
        if (!$rows) { wp_send_json_error(['message'=>'Не найден столбец «ФИО» или все строки пустые.'], 400); }
        wp_send_json_success(['rows'=>$rows, 'count'=>count($rows)]);
    }

    private function normalize_import_header($header) {
        $h = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$header), 'UTF-8') : strtolower(trim((string)$header));
        $h = preg_replace('/[\s_\-]+/u', ' ', $h);
        $map = [
            'фио'=>'full_name','full name'=>'full_name','name'=>'full_name',
            'название'=>'document_title','название документа'=>'document_title','course title'=>'document_title','document title'=>'document_title',
            'организация'=>'organization','organization'=>'organization','organisation'=>'organization',
            'дата'=>'issue_date','дата выдачи'=>'issue_date','issued date'=>'issue_date','date'=>'issue_date',
            'номер'=>'document_no','номер документа'=>'document_no','certificate no'=>'document_no','document no'=>'document_no',
            'статус'=>'member_status','членство'=>'member_status','status'=>'member_status','membership status'=>'member_status',
            'подпись'=>'signature_url','подпись участника'=>'signature_url','signature'=>'signature_url','signature url'=>'signature_url',
            'вторая подпись'=>'signature2_url','подпись 2'=>'signature2_url','second signature'=>'signature2_url','signature 2'=>'signature2_url',
            'печать'=>'stamp_url','штамп'=>'stamp_url','stamp'=>'stamp_url','seal'=>'stamp_url','stamp url'=>'stamp_url',
            'доп. поле 1'=>'extra1','доп поле 1'=>'extra1','extra 1'=>'extra1','extra1'=>'extra1',
            'доп. поле 2'=>'extra2','доп поле 2'=>'extra2','extra 2'=>'extra2','extra2'=>'extra2',
            'доп. поле 3'=>'extra3','доп поле 3'=>'extra3','extra 3'=>'extra3','extra3'=>'extra3',
            'доп. поле 4'=>'extra4','доп поле 4'=>'extra4','extra 4'=>'extra4','extra4'=>'extra4',
        ];
        return $map[$h] ?? '';
    }

    private function parse_csv_file($path) {
        $raw = file_get_contents($path);
        if ($raw === false) { throw new RuntimeException('Не удалось прочитать CSV.'); }
        if (substr($raw,0,3) === "\xEF\xBB\xBF") { $raw = substr($raw,3); }
        $first = strtok($raw, "\r\n");
        $delimiter = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh); $rows=[];
        while (($row=fgetcsv($fh, 0, $delimiter)) !== false) { $rows[]=$row; }
        fclose($fh); return $rows;
    }

    private function parse_xlsx_file($path) {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('На сервере не включено расширение ZipArchive. Используйте CSV.'); }
        if (!function_exists('simplexml_load_string')) { throw new RuntimeException('На сервере не включено SimpleXML. Используйте CSV.'); }
        $zip = new ZipArchive(); if ($zip->open($path)!==true) { throw new RuntimeException('Не удалось открыть XLSX.'); }
        $shared=[]; $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml) {
            $xml=simplexml_load_string($sharedXml);
            if($xml){
                $main=$xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach($main->si as $si){
                    $parts=[]; $siMain=$si->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    if(isset($siMain->t))$parts[]=(string)$siMain->t;
                    foreach($siMain->r as $r){$rMain=$r->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');$parts[]=(string)$rMain->t;}
                    $shared[]=implode('',$parts);
                }
            }
        }
        $sheetPath='xl/worksheets/sheet1.xml';
        $workbook=$zip->getFromName('xl/workbook.xml'); $rels=$zip->getFromName('xl/_rels/workbook.xml.rels');
        if($workbook && $rels){
            $wb=simplexml_load_string($workbook); $rl=simplexml_load_string($rels);
            if($wb && $rl){
                $wbMain=$wb->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $sheet=$wbMain->sheets->sheet[0] ?? null;
                if($sheet){
                    $attrs=$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships'); $rid=(string)$attrs['id'];
                    $rlMain=$rl->children('http://schemas.openxmlformats.org/package/2006/relationships');
                    foreach($rlMain->Relationship as $rel){
                        if((string)$rel['Id']===$rid){
                            $target=ltrim(str_replace(['../','\\'],['','/'],(string)$rel['Target']),'/');
                            $sheetPath=strpos($target,'xl/')===0 ? $target : 'xl/'.$target;
                            break;
                        }
                    }
                }
            }
        }
        $sheetXml=$zip->getFromName($sheetPath); $zip->close(); if(!$sheetXml)throw new RuntimeException('Первый лист XLSX не найден.');
        $xml=simplexml_load_string($sheetXml); if(!$xml)throw new RuntimeException('Повреждён XML листа.');
        $main=$xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows=[];
        foreach($main->sheetData->row as $row){
            $line=[]; $rowMain=$row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach($rowMain->c as $c){
                $ref=(string)$c['r']; preg_match('/^[A-Z]+/',$ref,$m); $idx=$this->xlsx_col_index($m[0]??'A'); $type=(string)$c['t'];
                $cMain=$c->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $value='';
                if($type==='s')$value=$shared[(int)$cMain->v]??'';
                elseif($type==='inlineStr'){$isMain=$cMain->is->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');$value=(string)$isMain->t;}
                else $value=(string)$cMain->v;
                $line[$idx]=$value;
            }
            if($line){ ksort($line); $max=max(array_keys($line)); $dense=[]; for($i=0;$i<=$max;$i++)$dense[]=$line[$i]??''; $rows[]=$dense; }
        }
        return $rows;
    }

    private function xlsx_col_index($letters) { $n=0; foreach(str_split($letters) as $ch){$n=$n*26+(ord($ch)-64);} return max(0,$n-1); }

    public function ajax_prepare_document() {
        $this->require_ajax_cap(self::CAP_CREATE);
        check_ajax_referer(self::NONCE, 'nonce');
        global $wpdb;
        $template_id = absint($_POST['template_id'] ?? 0);
        $tpl = $this->get_template($template_id);
        if (!$tpl) { wp_send_json_error(['message'=>'Шаблон не найден.'], 404); }
        $full_name = sanitize_text_field(wp_unslash($_POST['full_name'] ?? ''));
        if ($full_name === '') { wp_send_json_error(['message'=>'Укажите ФИО.'], 400); }
        $requested_number = sanitize_text_field(wp_unslash($_POST['document_no'] ?? ''));
        $token = bin2hex(random_bytes(24));
        $now = current_time('mysql');
        $data = [
            'template_id'=>$template_id,
            'user_id'=>absint($_POST['user_id'] ?? 0),
            'created_by'=>get_current_user_id(),
            'full_name'=>$full_name,
            'document_title'=>sanitize_text_field(wp_unslash($_POST['document_title'] ?? '')),
            'organization'=>sanitize_text_field(wp_unslash($_POST['organization'] ?? '')),
            'issue_date'=>sanitize_text_field(wp_unslash($_POST['issue_date'] ?? '')),
            'document_no'=>$requested_number ?: ('PENDING-'.$token),
            'member_status'=>sanitize_text_field(wp_unslash($_POST['member_status'] ?? '')),
            'extra1'=>sanitize_textarea_field(wp_unslash($_POST['extra1'] ?? '')),
            'extra2'=>sanitize_textarea_field(wp_unslash($_POST['extra2'] ?? '')),
            'extra3'=>sanitize_textarea_field(wp_unslash($_POST['extra3'] ?? '')),
            'extra4'=>sanitize_textarea_field(wp_unslash($_POST['extra4'] ?? '')),
            'signature_url'=>esc_url_raw(wp_unslash($_POST['signature_url'] ?? '')),
            'signature2_url'=>esc_url_raw(wp_unslash($_POST['signature2_url'] ?? '')),
            'stamp_url'=>esc_url_raw(wp_unslash($_POST['stamp_url'] ?? '')),
            'orientation'=>$tpl->orientation,
            'verify_token'=>$token,
            'record_status'=>'draft',
            'created_at'=>$now,
            'updated_at'=>$now,
        ];
        $ok = $wpdb->insert($this->docs_table, $data);
        if (!$ok) { wp_send_json_error(['message'=>'Ошибка записи в базу данных.'], 500); }
        $id=(int)$wpdb->insert_id;
        $number = $requested_number ?: $this->format_document_number($tpl, $id);
        if (!$requested_number) { $wpdb->update($this->docs_table, ['document_no'=>$number], ['id'=>$id]); }
        $verify_url = $this->verify_url($token);
        $this->log('document_prepared','document',$id,$number);
        wp_send_json_success(['id'=>$id,'document_no'=>$number,'verify_url'=>$verify_url,'template'=>$this->template_for_js($tpl)]);
    }

    public function ajax_finalize_document() {
        $this->require_ajax_cap(self::CAP_CREATE);
        check_ajax_referer(self::NONCE, 'nonce');
        global $wpdb;
        $id=absint($_POST['document_id'] ?? 0);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$id));
        if (!$row) { wp_send_json_error(['message'=>'Запись документа не найдена.'],404); }
        if (!current_user_can(self::CAP_MANAGE) && (int)$row->created_by !== get_current_user_id()) { wp_send_json_error(['message'=>'Нет доступа.'],403); }
        $final_document_no = (string)$row->document_no;
        $requested_document_no = sanitize_text_field(wp_unslash($_POST['document_no'] ?? ''));
        if ($requested_document_no !== '' && current_user_can(self::CAP_MANAGE)) { $final_document_no = $requested_document_no; }

        $target_template_id = 0;
        $target_template = null;
        if (current_user_can(self::CAP_MANAGE)) {
            $target_template_id = absint($_POST['target_template_id'] ?? 0);
            if ($target_template_id) {
                $target_template = $this->get_template($target_template_id);
                if (!$target_template) { wp_send_json_error(['message'=>'Целевой шаблон больше не существует.'],404); }
            }
        }
        $keep_old_files = current_user_can(self::CAP_MANAGE) && !empty($_POST['keep_old_files']);

        $data_url = wp_unslash($_POST['image_data'] ?? '');
        if (!preg_match('#^data:image/jpeg;base64,(.+)$#s', $data_url, $m)) { wp_send_json_error(['message'=>'Ожидалось JPEG-изображение документа.'],400); }
        $jpeg=base64_decode(str_replace(' ','+',$m[1]),true);
        if (!$jpeg || strlen($jpeg)<1000) { wp_send_json_error(['message'=>'Повреждённые данные изображения.'],400); }
        $size=@getimagesizefromstring($jpeg);
        if (!$size || ($size[2] ?? 0)!==IMAGETYPE_JPEG) { wp_send_json_error(['message'=>'Неверный формат изображения.'],400); }
        $uploads=wp_upload_dir();
        if (!empty($uploads['error'])) { wp_send_json_error(['message'=>$uploads['error']],500); }
        $sub='zau-certificates/'.wp_date('Y/m');
        $dir=trailingslashit($uploads['basedir']).$sub;
        if (!wp_mkdir_p($dir)) { wp_send_json_error(['message'=>'Не удалось создать папку для документов.'],500); }
        $revision=max(1,(int)($row->file_revision ?? 0)+1);
        $safe=sanitize_file_name($final_document_no.'-'.$id.'-r'.$revision);
        $jpg_path=trailingslashit($dir).$safe.'.jpg';
        $pdf_path=trailingslashit($dir).$safe.'.pdf';
        if (file_put_contents($jpg_path,$jpeg,LOCK_EX)===false) { wp_send_json_error(['message'=>'Не удалось сохранить JPG.'],500); }
        $pdf=$this->jpeg_to_pdf($jpeg,(int)$size[0],(int)$size[1]);
        if (file_put_contents($pdf_path,$pdf,LOCK_EX)===false) { @unlink($jpg_path); wp_send_json_error(['message'=>'Не удалось сохранить PDF.'],500); }
        $base=trailingslashit($uploads['baseurl']).$sub;
        $jpg_url=$base.'/'.$safe.'.jpg'; $pdf_url=$base.'/'.$safe.'.pdf';

        $data_json = json_decode((string)$row->data_json, true);
        if (!is_array($data_json)) { $data_json = []; }
        if ($keep_old_files && (!empty($row->pdf_url) || !empty($row->image_url))) {
            $history = isset($data_json['_zau_file_history']) && is_array($data_json['_zau_file_history']) ? $data_json['_zau_file_history'] : [];
            $history[] = [
                'pdf_url'=>(string)$row->pdf_url,
                'image_url'=>(string)$row->image_url,
                'document_no'=>(string)$row->document_no,
                'revision'=>(int)$row->file_revision,
                'archived_at'=>current_time('mysql'),
            ];
            if (count($history)>30) { $history=array_slice($history,-30); }
            $data_json['_zau_file_history']=$history;
        }

        $update=[
            'document_no'=>$final_document_no,
            'image_url'=>$jpg_url,
            'pdf_url'=>$pdf_url,
            'file_revision'=>$revision,
            'record_status'=>'active',
            'data_json'=>wp_json_encode($data_json,JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql')
        ];
        if ($target_template) {
            $update['template_id']=(int)$target_template->id;
            $update['orientation']=$target_template->orientation;
            $update['document_title']=$target_template->name;
        }
        $updated=$wpdb->update($this->docs_table,$update,['id'=>$id]);
        if ($updated===false) { @unlink($jpg_path); @unlink($pdf_path); wp_send_json_error(['message'=>'Не удалось обновить запись документа.'],500); }
        if (!$keep_old_files) {
            if (!empty($row->pdf_url) && $row->pdf_url!==$pdf_url) { $this->delete_upload_url($row->pdf_url); }
            if (!empty($row->image_url) && $row->image_url!==$jpg_url) { $this->delete_upload_url($row->image_url); }
        }
        $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$id));
        if($fresh&&(int)$fresh->user_id>0&&(string)get_user_meta((int)$fresh->user_id,'zau_member_status',true)==='Выбыл из профсоюза'&&class_exists('ZAU_Union_Module')){$fresh=ZAU_Union_Module::instance()->ensure_exit_watermark_for_document($fresh);}
        if ((string)$row->document_no !== $final_document_no) { $this->log('document_number_updated','document',$id,$row->document_no.' → '.$final_document_no); }
        if ($target_template && (int)$row->template_id !== (int)$target_template->id) { $this->log('document_template_updated','document',$id,(int)$row->template_id.' → '.(int)$target_template->id); }
        $this->log('document_created','document',$id,$final_document_no.' revision '.$revision.($keep_old_files?' old files kept':''));
        wp_send_json_success([
            'id'=>$id,
            'document_no'=>$final_document_no,
            'template_id'=>(int)$fresh->template_id,
            'pdf_url'=>$this->secure_document_url($fresh,'pdf'),
            'image_url'=>$this->secure_document_url($fresh,'image'),
            'verify_url'=>$this->verify_url($row->verify_token),
            'revision'=>$revision,
            'old_files_kept'=>$keep_old_files?1:0
        ]);
    }

    private function require_ajax_cap($cap) { if (!is_user_logged_in() || !current_user_can($cap)) { wp_send_json_error(['message'=>'Недостаточно прав.'],403); } }

    public function format_document_number($tpl = null, $sequence = 0) {
        global $wpdb;
        if (!$sequence) { $sequence = (int)$wpdb->get_var("SELECT MAX(id) FROM {$this->docs_table}") + 1; }
        $settings = $this->settings();
        $prefix = sanitize_text_field(($tpl && !empty($tpl->document_prefix)) ? $tpl->document_prefix : $settings['prefix']);
        $pattern = sanitize_text_field(($tpl && !empty($tpl->number_pattern)) ? $tpl->number_pattern : '{prefix}-{year}-{number}');
        $digits = max(1, min(12, (int)(($tpl && !empty($tpl->number_digits)) ? $tpl->number_digits : 6)));
        $values = [
            '{prefix}' => $prefix,
            '{year}' => wp_date('Y'),
            '{month}' => wp_date('m'),
            '{day}' => wp_date('d'),
            '{number}' => str_pad((string)$sequence, $digits, '0', STR_PAD_LEFT),
            '{id}' => (string)$sequence,
        ];
        $number = strtr($pattern ?: '{prefix}-{year}-{number}', $values);
        $number = preg_replace('/\s+/u', '-', trim($number));
        return sanitize_text_field($number ?: ($prefix.'-'.wp_date('Y').'-'.str_pad((string)$sequence,$digits,'0',STR_PAD_LEFT)));
    }

    private function next_number($tpl = null) { return $this->format_document_number($tpl); }

    private function template_for_js($tpl) {
        return [
            'id'=>(int)$tpl->id,'name'=>$tpl->name,'orientation'=>$tpl->orientation,
            'page_width'=>(int)$tpl->page_width,'page_height'=>(int)$tpl->page_height,
            'background_url'=>$tpl->background_url,'background_mode'=>$tpl->background_mode,
            'fields'=>$this->decode_fields($tpl->fields_json,$tpl->orientation),
        ];
    }

    private function jpeg_to_pdf($jpeg,$px_w,$px_h) {
        $landscape=$px_w>$px_h; $w=$landscape?841.8898:595.2756; $h=$landscape?595.2756:841.8898;
        $objects=[];
        $objects[1]="<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2]="<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>";
        $objects[4]="<< /Type /XObject /Subtype /Image /Width $px_w /Height $px_h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream";
        $stream="q\n$w 0 0 $h 0 0 cm\n/Im0 Do\nQ\n";
        $objects[5]="<< /Length ".strlen($stream)." >>\nstream\n$stream"."endstream";
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0];
        foreach($objects as $n=>$obj){ $offsets[$n]=strlen($pdf); $pdf.=$n." 0 obj\n".$obj."\nendobj\n"; }
        $xref=strlen($pdf); $pdf.="xref\n0 6\n0000000000 65535 f \n";
        for($i=1;$i<=5;$i++){ $pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n"; }
        $pdf.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    public function page_registry() {
        $this->require_cap(self::CAP_CREATE); global $wpdb;
        $search=sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where='1=1'; $args=[];
        if($search!==''){ $like='%'.$wpdb->esc_like($search).'%'; $where.=' AND (full_name LIKE %s OR document_no LIKE %s OR organization LIKE %s)'; $args=[$like,$like,$like]; }
        $sql="SELECT d.*, t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE $where ORDER BY d.id DESC LIMIT 500";
        if($args){ $sql=$wpdb->prepare($sql,$args); }
        $items=$wpdb->get_results($sql);
        ?>
        <div class="wrap zau-wrap"><div class="zau-page-head"><div><h1>Реестр документов</h1><p>Проверка QR продолжает работать и после отзыва: на странице будет показан актуальный статус.</p></div></div>
        <form method="get" class="zau-search"><input type="hidden" name="page" value="zau-cert-registry"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="ФИО, номер или организация"><button class="button">Найти</button></form>
        <table class="widefat striped zau-table"><thead><tr><th>ID</th><th>Документ</th><th>ФИО</th><th>Номер</th><th>Ориентация</th><th>Статус</th><th>Файлы</th><th></th></tr></thead><tbody>
        <?php if(!$items):?><tr><td colspan="8">Документы не найдены.</td></tr><?php endif;?>
        <?php foreach($items as $item):$effective_status=$this->document_is_member_exit_revoked($item)?'revoked':$item->record_status;?><tr>
            <td><?php echo (int)$item->id;?></td><td><?php echo esc_html($item->template_name ?: $item->document_title);?><br><small><?php echo esc_html($item->created_at);?></small></td><td><strong><?php echo esc_html($item->full_name);?></strong><br><small><?php echo esc_html($item->organization);?></small></td><td><?php echo esc_html($item->document_no);?></td><td><?php echo $item->orientation==='portrait'?'Книжная':'Альбомная';?></td>
            <td><span class="zau-status zau-status-<?php echo esc_attr($effective_status);?>"><?php echo esc_html($this->status_label($effective_status));?></span></td>
            <td><?php if($item->pdf_url):?><a target="_blank" rel="noopener" href="<?php echo esc_url($this->secure_document_url($item));?>">PDF</a> · <?php endif;?><a target="_blank" rel="noopener" href="<?php echo esc_url($this->verify_url($item->verify_token));?>">Проверка</a></td>
            <td class="zau-actions"><?php if(current_user_can(self::CAP_MANAGE)):?><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-regenerate&id='.(int)$item->id));?>">Пересоздать PDF</a> <?php if($effective_status==='active'):?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_document_action&do=revoke&id='.(int)$item->id),self::NONCE));?>">Отозвать</a><?php elseif($effective_status==='revoked'):?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_document_action&do=restore&id='.(int)$item->id),self::NONCE));?>">Восстановить</a><?php endif;?> <?php if($item->pdf_url||$item->image_url):?><a class="button" onclick="return confirm('Удалить только PDF/JPG, оставив запись, номер, QR и данные заявки?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_document_action&do=clear_files&id='.(int)$item->id),self::NONCE));?>">Удалить файлы</a><?php endif;?> <a class="button button-link-delete" onclick="return confirm('Удалить запись и созданные файлы без возможности восстановления из этой строки?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_cert_document_action&do=delete&id='.(int)$item->id),self::NONCE));?>">Удалить запись</a><?php else:?>—<?php endif;?></td>
        </tr><?php endforeach;?></tbody></table></div>
        <?php
    }

    public function page_regenerate() {
        $this->require_cap(self::CAP_MANAGE);
        global $wpdb;
        $id = absint($_GET['id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.id=%d", $id));
        if (!$row) { wp_die('Документ не найден.'); }
        ?>
        <?php
        $tpl = $this->get_template((int)$row->template_id);
        $number_after_update = $tpl ? $this->format_document_number($tpl, (int)$row->id) : $row->document_no;
        ?>
        <div class="wrap zau-wrap" id="zau-regenerate-document" data-document-id="<?php echo (int)$id; ?>">
            <div class="zau-page-head"><div><h1>Пересоздание PDF</h1><p>QR-токен, владелец и связь с заявкой сохраняются. Новый файл заменит прежний только после успешной генерации.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zau-cert-registry')); ?>">Назад в реестр</a></div>
            <div class="zau-card">
                <table class="form-table"><tr><th>Документ</th><td><strong><?php echo esc_html($row->template_name ?: $row->document_title); ?></strong></td></tr><tr><th>ФИО</th><td><?php echo esc_html($row->full_name); ?></td></tr><tr><th>Текущий номер</th><td><code><?php echo esc_html($row->document_no); ?></code></td></tr><tr><th>Номер по текущему шаблону</th><td><code><?php echo esc_html($number_after_update); ?></code><p class="description">Использует актуальные префикс, формат и количество цифр из шаблона.</p></td></tr><tr><th>Текущий файл</th><td><?php echo $row->pdf_url ? '<a target="_blank" rel="noopener" href="'.esc_url($this->secure_document_url($row)).'">Открыть текущий PDF</a>' : 'Файл отсутствует'; ?></td></tr></table>
                <p><label><input type="checkbox" data-zau-regenerate-number checked> Применить к документу актуальный префикс и формат номера из шаблона</label></p>
                <p><button type="button" class="button button-primary button-large" data-zau-start-regenerate>Пересоздать PDF сейчас</button></p>
                <div id="zau-regenerate-progress" class="zau-progress"></div><div id="zau-regenerate-result"></div><div id="zau-regenerate-render-holder" class="zau-render-holder"></div>
            </div>
        </div>
        <?php
    }

    public function ajax_prepare_regeneration() {
        $this->require_ajax_cap(self::CAP_MANAGE);
        check_ajax_referer(self::NONCE, 'nonce');
        global $wpdb;
        $id = absint($_POST['document_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d", $id));
        if (!$row) { wp_send_json_error(['message'=>'Документ не найден.'], 404); }

        $target_template_id = absint($_POST['target_template_id'] ?? 0);
        $template_id = $target_template_id ?: (int)$row->template_id;
        $tpl = $this->get_template($template_id);
        if (!$tpl) {
            wp_send_json_error(['message'=>$target_template_id ? 'Выбранный новый шаблон удалён или недоступен.' : 'У документа нет доступного шаблона. Выберите целевой шаблон для массового пересоздания.'], 404);
        }

        $old_number = (string)$row->document_no;
        $apply_current_number = !isset($_POST['update_number']) || absint($_POST['update_number']) === 1;
        if ($apply_current_number) {
            $new_number = $this->format_document_number($tpl, (int)$row->id);
            if ($new_number !== '') { $row->document_no = $new_number; }
        }

        $values = json_decode((string)$row->data_json, true);
        if (!is_array($values)) { $values = []; }

        // A migrated legacy PDF may only contain file metadata. When it is linked to
        // an imported submission, merge the complete saved questionnaire first.
        if ((int)$row->source_submission_id > 0) {
            $submissions_table = $wpdb->prefix . 'zau_union_submissions';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $submissions_table));
            if ($table_exists === $submissions_table) {
                $submission_row = $wpdb->get_row($wpdb->prepare("SELECT data_json,signature_urls_json FROM {$submissions_table} WHERE id=%d", (int)$row->source_submission_id));
                if ($submission_row) {
                    $submission_values = json_decode((string)$submission_row->data_json, true);
                    if (is_array($submission_values)) { $values = array_merge($submission_values, $values); }
                    $submission_signatures = json_decode((string)$submission_row->signature_urls_json, true);
                    if (is_array($submission_signatures) && !empty($submission_signatures[0]) && empty($values['signature_url'])) {
                        $values['signature_url'] = $submission_signatures[0];
                    }
                    if (is_array($submission_signatures) && !empty($submission_signatures[1]) && empty($values['signature2_url'])) {
                        $values['signature2_url'] = $submission_signatures[1];
                    }
                }
            }
        }

        // Older/migrated documents (imported, or created before the branch/organization
        // fields existed) may lack branch_full_details and similar system fields entirely.
        // Fill any such gaps from the member's own profile — this is the same branch they
        // picked at registration, saved to their user profile at submission time — without
        // overwriting a real "at registration" snapshot that is already present above.
        if ((int)$row->user_id > 0 && class_exists('ZAU_Union_Module')) {
            $hydrated = ZAU_Union_Module::instance()->document_data_for_user((int)$row->user_id);
            foreach ($hydrated as $hkey => $hvalue) {
                if (!is_scalar($hvalue) || $hvalue === '' || $hvalue === null) { continue; }
                if (!array_key_exists($hkey, $values) || $values[$hkey] === '' || $values[$hkey] === null) {
                    $values[$hkey] = $hvalue;
                }
            }
        }
        if (empty($values['branch_full_details']) && !empty($values['branch_snapshot_full_details'])) { $values['branch_full_details'] = $values['branch_snapshot_full_details']; }
        if (empty($values['branch_identity_details']) && !empty($values['branch_snapshot_identity_details'])) { $values['branch_identity_details'] = $values['branch_snapshot_identity_details']; }
        if (empty($values['branch_bank_details']) && !empty($values['branch_snapshot_bank_details'])) { $values['branch_bank_details'] = $values['branch_snapshot_bank_details']; }
        if (empty($values['branch_requisites']) && !empty($values['branch_bank_details'])) { $values['branch_requisites'] = $values['branch_bank_details']; }

        $signature_url = $row->signature_url ?: ($values['signature_url'] ?? '');
        $signature2_url = $row->signature2_url ?: ($values['signature2_url'] ?? '');
        $stamp_url = $row->stamp_url ?: ($values['stamp_url'] ?? '');
        $values = array_merge($values, [
            'full_name'=>$row->full_name ?: ($values['full_name'] ?? ''),
            'document_title'=>$tpl->name ?: ($row->document_title ?: ($values['document_title'] ?? '')),
            'organization'=>$row->organization ?: ($values['organization'] ?? ''),
            'issue_date'=>$row->issue_date ?: ($values['issue_date'] ?? ''),
            'document_no'=>$row->document_no,
            'member_status'=>$row->member_status ?: ($values['member_status'] ?? ''),
            'extra1'=>$row->extra1 ?: ($values['extra1'] ?? ''),
            'extra2'=>$row->extra2 ?: ($values['extra2'] ?? ''),
            'extra3'=>$row->extra3 ?: ($values['extra3'] ?? ''),
            'extra4'=>$row->extra4 ?: ($values['extra4'] ?? ''),
            'signature_url'=>$this->normalize_upload_asset_url($signature_url),
            'signature2_url'=>$this->normalize_upload_asset_url($signature2_url),
            'stamp_url'=>$this->normalize_upload_asset_url($stamp_url),
            'verify_url'=>$this->verify_url($row->verify_token),
            'union_requisites'=>class_exists('ZAU_Union_Module') ? ZAU_Union_Module::instance()->union_requisites_text() : ($values['union_requisites'] ?? ''),
        ]);
        wp_send_json_success([
            'id'=>(int)$row->id,
            'template_id'=>(int)$tpl->id,
            'target_template_applied'=>$target_template_id ? 1 : 0,
            'document_no'=>$row->document_no,
            'old_document_no'=>$old_number,
            'number_changed'=>$old_number !== (string)$row->document_no,
            'verify_url'=>$values['verify_url'],
            'template'=>$this->template_for_js($tpl),
            'values'=>$values
        ]);
    }

    private function normalize_upload_asset_url($url) {
        $url = esc_url_raw((string)$url);
        if ($url === '') { return ''; }
        $up = wp_upload_dir();
        if (!empty($up['error'])) { return $url; }
        $current_base = trailingslashit($up['baseurl']);
        if (strpos($url, $current_base) === 0) { return $url; }
        $url_path = rawurldecode((string)wp_parse_url($url, PHP_URL_PATH));
        $base_path = trailingslashit(rawurldecode((string)wp_parse_url($up['baseurl'], PHP_URL_PATH)));
        if ($base_path !== '/' && strpos($url_path, $base_path) === 0) {
            $relative = ltrim(substr($url_path, strlen($base_path)), '/');
            $candidate_path = wp_normalize_path(trailingslashit($up['basedir']).$relative);
            if (is_file($candidate_path)) { return $current_base.$relative; }
        }
        return $url;
    }

    private function status_label($status){ return ['draft'=>'Черновик','active'=>'Действителен','revoked'=>'Отозван'][$status] ?? $status; }

    public function document_action(){
        $this->require_cap(self::CAP_MANAGE); check_admin_referer(self::NONCE); global $wpdb;
        $id=absint($_GET['id']??0); $do=sanitize_key($_GET['do']??'');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d",$id));
        if(!$row){ wp_die('Документ не найден.'); }
        if($do==='revoke'){ $wpdb->update($this->docs_table,['record_status'=>'revoked','revoked_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id]); $this->log('document_revoked','document',$id); }
        elseif($do==='restore'){ if((int)$row->user_id>0&&(string)get_user_meta((int)$row->user_id,'zau_member_status',true)==='Выбыл из профсоюза'){wp_safe_redirect(admin_url('admin.php?page=zau-cert-registry&zau_notice='.rawurlencode('Нельзя восстановить документ: участник имеет статус «Выбыл из профсоюза». Сначала измените статус участника.')));exit;} $wpdb->update($this->docs_table,['record_status'=>'active','revoked_at'=>null,'updated_at'=>current_time('mysql')],['id'=>$id]); $this->log('document_restored','document',$id); }
        elseif($do==='clear_files'){ $this->delete_upload_url($row->pdf_url); $this->delete_upload_url($row->image_url); $wpdb->update($this->docs_table,['pdf_url'=>'','image_url'=>'','record_status'=>'draft','updated_at'=>current_time('mysql')],['id'=>$id]); $this->log('document_files_cleared','document',$id,$row->document_no); }
        elseif($do==='delete'){ $this->delete_upload_url($row->pdf_url); $this->delete_upload_url($row->image_url); $wpdb->delete($this->docs_table,['id'=>$id]); $this->log('document_deleted','document',$id); }
        wp_safe_redirect(admin_url('admin.php?page=zau-cert-registry&zau_notice='.rawurlencode('Действие выполнено.'))); exit;
    }

    private function delete_upload_url($url){ if(!$url)return; $up=wp_upload_dir(); if(strpos($url,$up['baseurl'])!==0)return; $path=$up['basedir'].substr($url,strlen($up['baseurl'])); if(is_file($path))@unlink($path); }

    public function page_settings(){
        $this->require_cap(self::CAP_MANAGE); $s=$this->settings();
        ?>
        <div class="wrap zau-wrap"><h1>Настройки документов и QR</h1><div class="notice notice-info inline"><p><strong>Старые документы сохраняются.</strong> Обновление добавляет новые столбцы через dbDelta и не очищает реестр или папку uploads. Подписи, уже встроенные в старые PDF/JPG, остаются внутри файлов.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" class="zau-card">
        <?php wp_nonce_field(self::NONCE);?><input type="hidden" name="action" value="zau_cert_save_settings">
        <table class="form-table"><tr><th>Префикс номера</th><td><input name="prefix" value="<?php echo esc_attr($s['prefix']);?>" class="regular-text"></td></tr>
        <tr><th>Страница проверки</th><td><?php wp_dropdown_pages(['name'=>'verify_page_id','selected'=>(int)$s['verify_page_id'],'show_option_none'=>'— выберите —']);?><p class="description">На странице должен быть шорткод <code>[zau_certificate_verify]</code>.</p></td></tr>
        <tr><th>Проверка по QR</th><td><p><strong>Проверка открывается без входа по коду</strong> и показывает только результат проверки и разрешённые поля. PDF, изображение документа, прямая ссылка на файл и кнопка скачивания на этой странице не выводятся.</p><input type="hidden" name="verification_private" value="0"></td></tr>
        <tr><th>PDF в личном кабинете</th><td><label><input type="checkbox" name="owner_pdf_view" value="1" <?php checked($s['owner_pdf_view'],1);?>> Разрешить владельцу просмотр PDF в личном кабинете</label><br><label><input type="checkbox" name="manager_pdf_view" value="1" <?php checked($s['manager_pdf_view'],1);?>> Разрешить ответственному организации просмотр PDF участников</label><p class="description">Доступ выдаётся через защищённый обработчик WordPress. На странице QR-проверки документы не показываются.</p></td></tr>
        <tr><th>Режим показа документа</th><td><select name="cabinet_document_mode"><option value="popup" <?php selected($s['cabinet_document_mode'],'popup');?>>Только предпросмотр во всплывающем окне</option><option value="tab" <?php selected($s['cabinet_document_mode'],'tab');?>>Только открыть PDF в новой вкладке</option><option value="both" <?php selected($s['cabinet_document_mode'],'both');?>>Предпросмотр и открытие PDF</option></select><p class="description">Всплывающий предпросмотр использует защищённое изображение документа и удобен на телефоне.</p></td></tr>
        <tr><th>Данные после проверки</th><td><label><input type="checkbox" name="public_organization" value="1" <?php checked($s['public_organization'],1);?>> Показывать организацию</label><br><label><input type="checkbox" name="public_status" value="1" <?php checked($s['public_status'],1);?>> Показывать актуальный статус членства</label></td></tr></table>
        <?php submit_button('Сохранить настройки');?></form></div>
        <?php
    }

    public function save_settings(){
        $this->require_cap(self::CAP_MANAGE); check_admin_referer(self::NONCE);
        $s=$this->settings(); $s['prefix']=sanitize_text_field(wp_unslash($_POST['prefix']??'ZAU')); $s['verify_page_id']=absint($_POST['verify_page_id']??0); $s['public_pdf']=0; $s['verification_private']=0; $s['owner_pdf_view']=empty($_POST['owner_pdf_view'])?0:1; $s['manager_pdf_view']=empty($_POST['manager_pdf_view'])?0:1; $mode=sanitize_key($_POST['cabinet_document_mode']??'both'); $s['cabinet_document_mode']=in_array($mode,['popup','tab','both'],true)?$mode:'both'; $s['public_organization']=empty($_POST['public_organization'])?0:1; $s['public_status']=empty($_POST['public_status'])?0:1; update_option(self::OPT_SETTINGS,$s,false);
        wp_safe_redirect(admin_url('admin.php?page=zau-cert-settings&zau_notice='.rawurlencode('Настройки сохранены.'))); exit;
    }

    public function page_logs(){
        $this->require_cap(self::CAP_MANAGE); global $wpdb; $items=$wpdb->get_results("SELECT l.*,u.display_name FROM {$this->logs_table} l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id ORDER BY l.id DESC LIMIT 500");
        ?><div class="wrap zau-wrap"><h1>Журнал действий</h1><table class="widefat striped"><thead><tr><th>Дата</th><th>Пользователь</th><th>Действие</th><th>Объект</th><th>Детали</th><th>IP</th></tr></thead><tbody><?php foreach($items as $i):?><tr><td><?php echo esc_html($i->created_at);?></td><td><?php echo esc_html($i->display_name ?: '#'.$i->user_id);?></td><td><?php echo esc_html($i->action);?></td><td><?php echo esc_html($i->object_type.' #'.$i->object_id);?></td><td><?php echo esc_html($i->details);?></td><td><?php echo esc_html($i->ip);?></td></tr><?php endforeach;?></tbody></table></div><?php
    }

    private function log($action,$type='',$id=0,$details=''){
        global $wpdb; $ip=''; foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k){ if(!empty($_SERVER[$k])){$ip=sanitize_text_field(wp_unslash($_SERVER[$k])); if($k==='HTTP_X_FORWARDED_FOR')$ip=trim(explode(',',$ip)[0]); break;} }
        $wpdb->insert($this->logs_table,['user_id'=>get_current_user_id(),'action'=>sanitize_key($action),'object_type'=>sanitize_key($type),'object_id'=>(int)$id,'details'=>sanitize_textarea_field($details),'ip'=>$ip,'created_at'=>current_time('mysql')]);
    }

    private function verify_url($token){ $s=$this->settings(); $base=$s['verify_page_id']?get_permalink((int)$s['verify_page_id']):home_url('/proverka-dokumenta/'); return add_query_arg('zau_verify',rawurlencode($token),$base); }

    private function member_organization_id($user_id) {
        $id = absint(get_user_meta((int)$user_id, 'zau_organization_id', true));
        if ($id) { return $id; }
        $bin = preg_replace('/\D/', '', (string)get_user_meta((int)$user_id, 'zau_organization_bin', true));
        if (!$bin) { $bin = preg_replace('/\D/', '', (string)get_user_meta((int)$user_id, 'zau_profile_organization_bin', true)); }
        if (!$bin) { return 0; }
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}zau_union_organizations WHERE bin=%s LIMIT 1", $bin));
    }

    private function managed_organization_ids($user_id) {
        $ids = get_user_meta((int)$user_id, 'zau_managed_org_ids', true);
        if (!is_array($ids)) { $ids = array_filter(array_map('absint', preg_split('/[,;\s]+/', (string)$ids))); }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    public function user_can_access_document($row, $for_pdf = false) {
        if (!$row || !is_user_logged_in()) { return false; }
        $uid = get_current_user_id();
        if ((int)$row->user_id === $uid) {
            $s = $this->settings();
            return !$for_pdf || !empty($s['owner_pdf_view']);
        }
        if (current_user_can(self::CAP_MANAGE) || current_user_can('manage_options')) { return true; }
        if (current_user_can(self::CAP_ORG_MANAGE)) {
            $org_id = $this->member_organization_id((int)$row->user_id);
            if ($org_id && in_array($org_id, $this->managed_organization_ids($uid), true)) {
                $s = $this->settings();
                return !$for_pdf || !empty($s['manager_pdf_view']);
            }
        }
        return false;
    }

    private function document_is_member_exit_revoked($row) {
        if(!$row||!is_object($row))return false;
        if((string)$row->member_status==='Выбыл из профсоюза')return true;
        if((int)$row->user_id>0&&(string)get_user_meta((int)$row->user_id,'zau_member_status',true)==='Выбыл из профсоюза')return true;
        $data=json_decode((string)$row->data_json,true);
        return is_array($data)&&!empty($data['_zau_member_exit_revocation']['active']);
    }

    private function serve_revoked_svg_preview($path,$row) {
        $jpeg=@file_get_contents($path);$size=$jpeg?@getimagesizefromstring($jpeg):false;
        if(!$jpeg||!$size){wp_die('Файл предпросмотра не найден.','Документ не найден',['response'=>404]);}
        $w=(int)$size[0];$h=(int)$size[1];$encoded=base64_encode($jpeg);
        nocache_headers();header('Content-Type: image/svg+xml; charset=UTF-8');header('Content-Disposition: inline; filename="'.sanitize_file_name($row->document_no?:'document').'-revoked.svg"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        $font=max(42,(int)(min($w,$h)*0.075));
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><image width="'.$w.'" height="'.$h.'" href="data:image/jpeg;base64,'.$encoded.'"/><g transform="rotate(-28 '.($w/2).' '.($h/2).')" fill="#b42318" fill-opacity="0.30" font-family="Arial,DejaVu Sans,sans-serif" font-size="'.$font.'" font-weight="700" text-anchor="middle"><text x="'.($w/2).'" y="'.($h*0.36).'">ВЫБЫЛ ИЗ ПРОФСОЮЗА</text><text x="'.($w/2).'" y="'.($h*0.56).'">ВЫБЫЛ ИЗ ПРОФСОЮЗА</text><text x="'.($w/2).'" y="'.($h*0.76).'">ДОКУМЕНТ ОТОЗВАН</text></g></svg>';
        exit;
    }

    public function secure_document_url($row, $format = 'pdf', $download = false) {
        global $wpdb;
        $id = is_object($row) ? (int)$row->id : (int)$row;
        if (!$id) { return ''; }
        $format = $format === 'image' ? 'image' : 'pdf';
        if (!is_object($row)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,file_revision,updated_at FROM {$this->docs_table} WHERE id=%d", $id));
        }
        $revision = is_object($row) ? (int)($row->file_revision ?? 0) : 0;
        if (!$revision && is_object($row) && !empty($row->updated_at)) { $revision = max(1, (int)strtotime($row->updated_at)); }
        $url = admin_url('admin-post.php?action=zau_union_secure_document&document_id='.$id.'&format='.$format.($download?'&download=1':''));
        $url = add_query_arg('rev', max(1,$revision), $url);
        // Do not use wp_nonce_url() here: it HTML-escapes ampersands as &amp;.
        // The same URL is also returned through AJAX/JSON, where an escaped ampersand
        // breaks the action and nonce query parameters. Escape only at final HTML output.
        return add_query_arg('_wpnonce', wp_create_nonce('zau_secure_document_'.$id), $url);
    }

    public function secure_document() {
        if (!is_user_logged_in()) { auth_redirect(); }
        global $wpdb;
        $id = absint($_GET['document_id'] ?? 0);
        $format = sanitize_key($_GET['format'] ?? 'pdf');
        if (!in_array($format, ['pdf','image'], true)) { $format = 'pdf'; }
        check_admin_referer('zau_secure_document_'.$id);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->docs_table} WHERE id=%d", $id));
        if (!$row || !$this->user_can_access_document($row, true)) { wp_die('Нет доступа к документу.', 'Доступ запрещён', ['response'=>403]); }
        if($this->document_is_member_exit_revoked($row)&&class_exists('ZAU_Union_Module')){$row=ZAU_Union_Module::instance()->ensure_exit_watermark_for_document($row);}
        $url = $format === 'image' ? $row->image_url : $row->pdf_url;
        if (!$url) { wp_die('Файл документа ещё не сформирован.', 'Документ не готов', ['response'=>404]); }
        $up = wp_upload_dir();
        $baseurl = trailingslashit($up['baseurl']);
        $relative = '';
        if (strpos($url, $baseurl) === 0) {
            $relative = ltrim(substr($url, strlen($baseurl)), '/');
        } else {
            // Support documents created before a domain change (for example new.* → uchet.*).
            // Only the uploads-relative path is accepted; arbitrary external paths remain blocked.
            $url_path = rawurldecode((string)wp_parse_url($url, PHP_URL_PATH));
            $base_path = trailingslashit(rawurldecode((string)wp_parse_url($up['baseurl'], PHP_URL_PATH)));
            if ($base_path !== '/' && strpos($url_path, $base_path) === 0) {
                $relative = ltrim(substr($url_path, strlen($base_path)), '/');
            }
        }
        if ($relative === '') { wp_die('Небезопасный путь документа.', 'Ошибка', ['response'=>400]); }
        $path = wp_normalize_path(trailingslashit($up['basedir']).$relative);
        $root = wp_normalize_path(trailingslashit($up['basedir']));
        if (strpos($path, $root) !== 0 || !is_file($path)) { wp_die('Файл документа не найден.', 'Документ не найден', ['response'=>404]); }
        if($format==='image'&&$this->document_is_member_exit_revoked($row)){$this->serve_revoked_svg_preview($path,$row);}
        $mime = $format === 'image' ? 'image/jpeg' : 'application/pdf';
        $ext = $format === 'image' ? 'jpg' : 'pdf';
        nocache_headers();
        header('Content-Type: '.$mime);
        $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
        header('Content-Disposition: '.$disposition.'; filename="'.sanitize_file_name($row->document_no ?: 'document').'.'.$ext.'"');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Length: '.filesize($path));
        readfile($path);
        exit;
    }

    public function verify_shortcode(){
        global $wpdb;
        $token=sanitize_text_field(wp_unslash($_GET['zau_verify']??get_query_var('zau_verify')));
        if(!$token){ return '<div class="zau-public-card"><h2>Проверка документа</h2><p>Откройте ссылку или отсканируйте QR-код, размещённый на документе.</p></div>'; }
        $row=$wpdb->get_row($wpdb->prepare("SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.verify_token=%s",$token));
        if(!$row){ return '<div class="zau-public-card zau-invalid"><h2>Документ не найден</h2><p>Ссылка недействительна либо документ удалён из реестра.</p></div>'; }
        $s=$this->settings();
        if ((int)$row->user_id > 0) {
            $current_member_status = get_user_meta((int)$row->user_id, 'zau_member_status', true);
            if ($current_member_status !== '') { $row->member_status = $current_member_status; }
        }
        $exitRevoked=$this->document_is_member_exit_revoked($row);$valid=$row->record_status==='active'&&!$exitRevoked;
        ob_start(); ?>
        <style>.zau-public-card{max-width:760px;margin:35px auto;padding:28px;border:1px solid #D9E2EF;border-radius:20px;background:#fff;box-shadow:0 16px 50px rgba(16,40,30,.08);font-family:Arial,sans-serif}.zau-public-head{display:flex;gap:16px;align-items:center;margin-bottom:22px}.zau-public-icon{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;font-size:28px;background:#EAF2FD;color:#1565C0}.zau-invalid .zau-public-icon,.zau-revoked .zau-public-icon{background:#fff1f0;color:#b42318}.zau-public-card h2{margin:0 0 4px}.zau-public-status{font-weight:700;color:#1565C0}.zau-revoked .zau-public-status{color:#b42318}.zau-public-grid{display:grid;grid-template-columns:190px 1fr;gap:10px 18px;padding:18px 0;border-top:1px solid #E8EEF6;border-bottom:1px solid #E8EEF6}.zau-public-grid dt{color:#667085}.zau-public-grid dd{margin:0;font-weight:600}@media(max-width:600px){.zau-public-card{margin:15px;padding:20px}.zau-public-grid{grid-template-columns:1fr;gap:3px}.zau-public-grid dd{margin-bottom:10px}}</style>
        <div class="zau-public-card <?php echo $valid?'':'zau-revoked';?>"><div class="zau-public-head"><div class="zau-public-icon"><?php echo $valid?'✓':'!';?></div><div><h2><?php echo $valid?'Документ действителен':'Документ отозван';?></h2><div class="zau-public-status"><?php echo $valid?'Запись найдена в реестре Профсоюза.':($exitRevoked?'Документ отозван автоматически: участник выбыл из профсоюза.':'Документ был выдан ранее, но сейчас не является действующим.');?></div></div></div>
        <dl class="zau-public-grid"><dt>ФИО</dt><dd><?php echo esc_html($row->full_name);?></dd><dt>Документ</dt><dd><?php echo esc_html($row->template_name ?: $row->document_title);?></dd><dt>Номер</dt><dd><?php echo esc_html($row->document_no);?></dd><dt>Дата</dt><dd><?php echo esc_html($row->issue_date);?></dd><?php if($s['public_organization']&&$row->organization):?><dt>Организация</dt><dd><?php echo esc_html($row->organization);?></dd><?php endif;?><?php if($s['public_status']&&$row->member_status):?><dt>Статус / членство</dt><dd><?php echo esc_html($row->member_status);?></dd><?php endif;?><?php if(!$valid):?><dt>Статус документа</dt><dd>Отозван</dd><?php if($exitRevoked):?><dt>Причина отзыва</dt><dd>Выбыл из профсоюза</dd><?php endif;?><?php if(!empty($row->revoked_at)):?><dt>Дата отзыва</dt><dd><?php echo esc_html($row->revoked_at);?></dd><?php endif;?><?php endif;?></dl>
        <p><small>На странице проверки документ не отображается и не предоставляется для скачивания.</small></p></div>
        <?php return ob_get_clean();
    }

    public function my_certificates_shortcode($atts=[]){
        if(!is_user_logged_in()){
            if(class_exists('ZAU_Union_Module')){return ZAU_Union_Module::instance()->auth_shortcode((array)$atts);}
            return '<p>Войдите в личный кабинет, чтобы увидеть документы.</p>';
        }
        global $wpdb; $uid=get_current_user_id(); $items=$wpdb->get_results($wpdb->prepare("SELECT d.*,t.name template_name FROM {$this->docs_table} d LEFT JOIN {$this->templates_table} t ON t.id=d.template_id WHERE d.user_id=%d ORDER BY d.id DESC",$uid));
        $settings=$this->settings();
        ob_start(); ?><div class="zau-my-docs"><?php if(!$items):?><p>Документов пока нет.</p><?php endif;?><?php foreach($items as $i):$effective_status=$this->document_is_member_exit_revoked($i)?'revoked':$i->record_status;?><div class="zau-my-doc"><strong><?php echo esc_html($i->template_name?:$i->document_title);?></strong><div><?php echo esc_html($i->document_no.' · '.$i->issue_date);?></div><div><?php echo esc_html($this->status_label($effective_status));?></div><?php if($i->pdf_url&&!empty($settings['owner_pdf_view'])):?><a target="_blank" rel="noopener" href="<?php echo esc_url($this->secure_document_url($i));?>">Защищённый просмотр PDF</a><?php endif;?> <a target="_blank" rel="noopener" href="<?php echo esc_url($this->verify_url($i->verify_token));?>">Проверить QR</a></div><?php endforeach;?></div><style>.zau-my-doc{padding:16px;margin:0 0 12px;border:1px solid #D9E2EF;border-radius:12px}.zau-my-doc a{margin-right:10px}</style><?php return ob_get_clean();
    }

}

require_once __DIR__ . '/includes/class-zau-union-module.php';
require_once __DIR__ . '/includes/class-zau-bulk-regeneration.php';
ZAU_Bulk_Regeneration::instance();
require_once __DIR__ . '/includes/class-zau-legacy-import.php';
require_once __DIR__ . '/includes/class-zau-elementor.php';
ZAU_Union_Elementor::init();

register_activation_hook(__FILE__, ['ZAU_Certificate_PDF_Generator','activate']);
register_deactivation_hook(__FILE__, ['ZAU_Certificate_PDF_Generator','deactivate']);
ZAU_Certificate_PDF_Generator::instance();
