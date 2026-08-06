<?php
/**
 * Plugin Name: ZAU — мост экспорта старого кабинета
 * Description: Устанавливается на СТАРЫЙ сайт (uchet.zdravunion.kz). Открывает защищённый API, который читает пользователей Ultimate Member и записи WPForms и отдаёт их новому сайту ZAU Профсоюз по подписанным запросам. Ничего не удаляет и не изменяет на старом сайте.
 * Version: 1.1.0
 * Author: ZAU
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Text Domain: zau-legacy-bridge-companion
 */

if (!defined('ABSPATH')) { exit; }

final class ZAU_Legacy_Bridge_Companion {
    const VERSION = '1.1.0';
    const OPT_SETTINGS = 'zau_legacy_bridge_companion_settings';
    const NONCE = 'zau_legacy_bridge_companion_nonce';
    const NS = 'zau-legacy-bridge/v1';
    /** Допустимый разброс времени между часами старого и нового сайта. */
    const CLOCK_SKEW = 300;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_zau_legacy_bridge_companion_save', [$this, 'save_settings']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public static function activate() {
        $settings = self::instance()->settings();
        if (empty($settings['secret'])) {
            $settings['secret'] = wp_generate_password(48, false, false);
            update_option(self::OPT_SETTINGS, $settings, false);
        }
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT_SETTINGS, []), [
            'enabled' => 0,
            'secret' => '',
            /* Ключ usermeta (или поле Ultimate Member) -> каноническое имя поля, которое понимает новый сайт. */
            'field_map' => [
                'phone' => 'phone_number,user_phone,mobile_number,phone',
                'iin' => 'iin,user_iin',
                'birth_date' => 'birth_date,user_birth_date,birthday',
                'address' => 'address,user_address,user_address_1',
                'position' => 'position,user_position,job_title',
                'department' => 'department,user_department',
                'organization' => 'organization,company,workplace',
                'organization_bin' => 'organization_bin,company_bin,bin',
                'organization_director' => 'organization_director,company_director',
                'organization_address' => 'organization_address,company_address',
                'branch_name' => 'branch,branch_name,region',
            ],
        ]);
    }

    public function admin_menu() {
        add_options_page('Мост экспорта ZAU', 'ZAU экспорт кабинета', 'manage_options', 'zau-legacy-bridge-companion', [$this, 'page']);
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer(self::NONCE);
        $settings = $this->settings();
        $settings['enabled'] = !empty($_POST['enabled']) ? 1 : 0;
        if (!empty($_POST['regenerate_secret'])) {
            $settings['secret'] = wp_generate_password(48, false, false);
        } elseif (isset($_POST['secret']) && trim((string)$_POST['secret']) !== '') {
            $settings['secret'] = sanitize_text_field(wp_unslash($_POST['secret']));
        }
        $map = (array)($_POST['field_map'] ?? []);
        foreach ($settings['field_map'] as $key => $default) {
            if (isset($map[$key])) { $settings['field_map'][$key] = sanitize_text_field(wp_unslash($map[$key])); }
        }
        update_option(self::OPT_SETTINGS, $settings, false);
        wp_safe_redirect(add_query_arg(['page'=>'zau-legacy-bridge-companion','saved'=>1], admin_url('options-general.php')));
        exit;
    }

    public function page() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.'); }
        $s = $this->settings();
        $umActive = class_exists('UM') || function_exists('UM');
        $wpformsActive = function_exists('wpforms');
        ?>
        <div class="wrap">
            <h1>ZAU — мост экспорта старого кабинета</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Настройки сохранены.</p></div><?php endif; ?>
            <p>Этот плагин открывает защищённый API для одного назначения — переноса данных на новый сайт ZAU Профсоюз. Ничего не удаляет и не меняет на этом сайте.</p>
            <table class="widefat" style="max-width:640px;margin-bottom:20px">
                <tr><th style="text-align:left">Ultimate Member</th><td><?php echo $umActive ? 'найден' : 'не найден'; ?></td></tr>
                <tr><th style="text-align:left">WPForms</th><td><?php echo $wpformsActive ? 'найден' : 'не найден'; ?></td></tr>
                <tr><th style="text-align:left">Адрес API</th><td><code><?php echo esc_html(rest_url(self::NS)); ?></code></td></tr>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="zau_legacy_bridge_companion_save">
                <table class="form-table">
                    <tr>
                        <th><label for="zau-enabled">Включить API</label></th>
                        <td><label><input type="checkbox" id="zau-enabled" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Разрешить новому сайту подключаться</label></td>
                    </tr>
                    <tr>
                        <th><label for="zau-secret">Секретный ключ</label></th>
                        <td>
                            <input type="text" id="zau-secret" name="secret" value="<?php echo esc_attr($s['secret']); ?>" class="regular-text" readonly>
                            <label><input type="checkbox" name="regenerate_secret" value="1"> Сгенерировать новый ключ при сохранении</label>
                            <p class="description">Скопируйте этот ключ и адрес API в настройки нового сайта (Профсоюз → Перенос со старого сайта → Подключение по API).</p>
                        </td>
                    </tr>
                </table>
                <h2>Соответствие полей Ultimate Member</h2>
                <p class="description">У каждой установки Ultimate Member свои ключи дополнительных полей. Укажите через запятую все варианты названий поля usermeta, которые встречаются на этом сайте — плагин возьмёт первое непустое значение.</p>
                <table class="form-table">
                    <?php foreach ($s['field_map'] as $key => $value): ?>
                        <tr>
                            <th><label for="zau-map-<?php echo esc_attr($key); ?>"><?php echo esc_html($key); ?></label></th>
                            <td><input type="text" id="zau-map-<?php echo esc_attr($key); ?>" name="field_map[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" class="large-text"></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('Сохранить настройки'); ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------ REST ---- */

    public function register_routes() {
        register_rest_route(self::NS, '/status', ['methods'=>'GET','callback'=>[$this,'route_status'],'permission_callback'=>[$this,'verify_request']]);
        register_rest_route(self::NS, '/forms', ['methods'=>'GET','callback'=>[$this,'route_forms'],'permission_callback'=>[$this,'verify_request']]);
        register_rest_route(self::NS, '/users', ['methods'=>'GET','callback'=>[$this,'route_users'],'permission_callback'=>[$this,'verify_request']]);
        register_rest_route(self::NS, '/entries', ['methods'=>'GET','callback'=>[$this,'route_entries'],'permission_callback'=>[$this,'verify_request']]);
        register_rest_route(self::NS, '/file', ['methods'=>'GET','callback'=>[$this,'route_file'],'permission_callback'=>[$this,'verify_request']]);
    }

    /** HMAC-подпись запроса: timestamp\nGET\n{route}\n{querystring без ?}. Тот же принцип, что и в прежнем мосте. */
    public function verify_request(WP_REST_Request $request) {
        $s = $this->settings();
        if (empty($s['enabled']) || empty($s['secret'])) { return new WP_Error('zau_bridge_disabled', 'API моста отключён.', ['status'=>503]); }
        $timestamp = (string)$request->get_header('x-zau-timestamp');
        $signature = (string)$request->get_header('x-zau-signature');
        if ($timestamp === '' || $signature === '') { return new WP_Error('zau_bridge_auth', 'Отсутствуют заголовки подписи.', ['status'=>401]); }
        if (abs(time() - (int)$timestamp) > self::CLOCK_SKEW) { return new WP_Error('zau_bridge_auth', 'Запрос устарел — проверьте время на серверах.', ['status'=>401]); }
        $query = $request->get_query_params();
        unset($query['rest_route']);
        ksort($query);
        $queryString = http_build_query($query);
        /* $request->get_route() уже включает namespace, например /zau-legacy-bridge/v1/status — должно
           совпадать байт в байт с тем, что подписывает новый сайт в ZAU_Legacy_Import::bridge_request(). */
        $payload = $timestamp . "\nGET\n" . $request->get_route() . "\n" . $queryString;
        $expected = hash_hmac('sha256', $payload, (string)$s['secret']);
        if (!hash_equals($expected, $signature)) { return new WP_Error('zau_bridge_auth', 'Неверная подпись запроса.', ['status'=>401]); }
        return true;
    }

    public function route_status() {
        global $wpdb;
        $userCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        $formsCount = function_exists('wpforms') ? count((array)wpforms()->form->get('', ['fields'=>'ids'])) : 0;
        return rest_ensure_response([
            'ok' => true,
            'site' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'um_active' => class_exists('UM') || function_exists('UM'),
            'wpforms_active' => function_exists('wpforms'),
            'user_count' => $userCount,
            'forms_count' => $formsCount,
        ]);
    }

    private function count_entries($formId) {
        global $wpdb;
        if (!class_exists('WPForms_Entry_Handler')) { return 0; }
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entries WHERE form_id=%d", $formId));
    }

    public function route_forms() {
        if (!function_exists('wpforms')) { return rest_ensure_response(['forms'=>[]]); }
        $forms = wpforms()->form->get();
        $out = [];
        foreach ((array)$forms as $form) {
            $data = function_exists('wpforms_decode') ? wpforms_decode($form->post_content) : json_decode($form->post_content, true);
            $fields = [];
            foreach ((array)($data['fields'] ?? []) as $field) {
                $fields[] = ['id'=>(string)($field['id'] ?? ''), 'type'=>(string)($field['type'] ?? ''), 'label'=>(string)($field['label'] ?? '')];
            }
            $entryCount = $this->count_entries((int)$form->ID);
            $out[] = ['id'=>(string)$form->ID, 'title'=>get_the_title($form->ID), 'field_count'=>count($fields), 'entry_count'=>$entryCount, 'fields'=>$fields];
        }
        return rest_ensure_response(['forms'=>$out]);
    }

    private function resolve_field($map, $canonical) {
        $variants = array_filter(array_map('trim', explode(',', (string)($map[$canonical] ?? ''))));
        return $variants ?: [$canonical];
    }

    private function meta_first($userId, $variants) {
        foreach ($variants as $key) {
            $value = get_user_meta($userId, $key, true);
            if ($value !== '' && $value !== null) { return is_array($value) ? '' : (string)$value; }
        }
        return '';
    }

    /** cursor = ID последнего отданного пользователя (не смещение). Так каждая страница читается
     * по первичному ключу за одинаковое время независимо от того, как далеко продвинулся перенос —
     * OFFSET на большой таблице пользователей на этом месте раньше становился всё медленнее и в
     * какой-то момент упирался в лимит времени выполнения на стороне старого сайта. */
    public function route_users(WP_REST_Request $request) {
        global $wpdb;
        $cursor = max(0, (int)$request->get_param('cursor'));
        $perPage = max(1, min(200, (int)($request->get_param('per_page') ?: 100)));
        $s = $this->settings();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d", $cursor, $perPage));
        $total = (int)count_users()['total_users'];
        $out = [];
        $lastId = $cursor;
        foreach ($ids as $id) {
            $user = get_userdata((int)$id);
            if (!$user) { continue; }
            $lastId = (int)$id;
            $row = [
                'legacy_user_id' => (string)$user->ID,
                'user_login' => $user->user_login,
                'email' => $user->user_email,
                'full_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'registered' => $user->user_registered,
            ];
            foreach ($s['field_map'] as $canonical => $raw) {
                $row[$canonical] = $this->meta_first($user->ID, $this->resolve_field($s['field_map'], $canonical));
            }
            if (empty($row['phone'])) { $row['phone'] = $this->meta_first($user->ID, ['user_phone','phone_number','mobile_number','phone']); }
            $out[] = $row;
        }
        return rest_ensure_response([
            'users' => $out,
            'next_cursor' => $lastId,
            'has_more' => count($ids) === $perPage,
            'total' => $total,
        ]);
    }

    private function find_signature_field_id($formId) {
        if (!function_exists('wpforms')) { return ''; }
        $form = wpforms()->form->get($formId);
        if (!$form) { return ''; }
        $data = function_exists('wpforms_decode') ? wpforms_decode($form->post_content) : json_decode($form->post_content, true);
        foreach ((array)($data['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === 'signature') { return (string)($field['id'] ?? ''); }
        }
        return '';
    }

    /** cursor = entry_id последней отданной записи (не смещение) — та же причина, что и в route_users:
     * читаем прямо по первичному ключу таблицы wpforms_entries, а не через OFFSET, который на больших
     * формах со временем замедляется вплоть до обрыва запроса по таймауту. */
    public function route_entries(WP_REST_Request $request) {
        if (!function_exists('wpforms')) { return rest_ensure_response(['entries'=>[], 'next_cursor'=>0, 'has_more'=>false, 'total'=>0]); }
        global $wpdb;
        $formId = absint($request->get_param('form_id'));
        if (!$formId) { return new WP_Error('zau_bridge_form', 'Не указан form_id.', ['status'=>400]); }
        $cursor = max(0, (int)$request->get_param('cursor'));
        $perPage = max(1, min(200, (int)($request->get_param('per_page') ?: 50)));
        $signatureFieldId = $this->find_signature_field_id($formId);
        $entriesTable = $wpdb->prefix . 'wpforms_entries';
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT entry_id,user_id,status,date,fields FROM {$entriesTable} WHERE form_id=%d AND entry_id>%d ORDER BY entry_id ASC LIMIT %d",
            $formId, $cursor, $perPage
        ));
        $total = $this->count_entries($formId);
        $out = [];
        $lastId = $cursor;
        foreach ((array)$entries as $entry) {
            $lastId = (int)$entry->entry_id;
            $fields = function_exists('wpforms_decode') ? wpforms_decode($entry->fields) : json_decode($entry->fields, true);
            $flat = [];
            $signatureUrl = '';
            foreach ((array)$fields as $fid => $field) {
                $label = (string)($field['name'] ?? ('field_' . $fid));
                $value = $field['value'] ?? '';
                if ((string)$fid === $signatureFieldId && is_string($value) && $value !== '') { $signatureUrl = $value; }
                $flat[$label] = is_scalar($value) ? (string)$value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $out[] = [
                'legacy_entry_id' => (string)$entry->entry_id,
                'legacy_user_id' => $entry->user_id ? (string)$entry->user_id : '',
                'status' => (string)$entry->status,
                'date_created' => (string)$entry->date,
                'fields' => $flat,
                'signature_url' => $signatureUrl,
            ];
        }
        return rest_ensure_response([
            'entries' => $out,
            'next_cursor' => $lastId,
            'has_more' => count($entries) === $perPage,
            'total' => $total,
        ]);
    }

    /** Отдаёт файл строго из wp-content/uploads этого сайта — защита от обхода каталогов и SSRF. */
    public function route_file(WP_REST_Request $request) {
        $url = (string)$request->get_param('url');
        if ($url === '') { return new WP_Error('zau_bridge_file', 'Не указан url.', ['status'=>400]); }
        $uploads = wp_upload_dir();
        if (strpos($url, $uploads['baseurl']) !== 0) { return new WP_Error('zau_bridge_file', 'Разрешены только файлы из uploads этого сайта.', ['status'=>403]); }
        $relative = ltrim(substr($url, strlen($uploads['baseurl'])), '/');
        $relative = str_replace(['..', "\0"], '', $relative);
        $path = trailingslashit($uploads['basedir']) . $relative;
        $real = realpath($path);
        if (!$real || strpos($real, realpath($uploads['basedir'])) !== 0 || !is_file($real)) {
            return new WP_Error('zau_bridge_file', 'Файл не найден.', ['status'=>404]);
        }
        $mime = function_exists('wp_check_filetype') ? (wp_check_filetype($real)['type'] ?: 'application/octet-stream') : 'application/octet-stream';
        /* JSON, а не сырой ответ: REST-сервер WordPress всё равно кодирует тело ответа через
           json_encode, так что вернуть чистые байты минуя JSON здесь нельзя. */
        return rest_ensure_response([
            'name' => basename($real),
            'mime' => $mime,
            'size' => filesize($real),
            'content_base64' => base64_encode((string)file_get_contents($real)),
        ]);
    }
}

register_activation_hook(__FILE__, ['ZAU_Legacy_Bridge_Companion', 'activate']);
ZAU_Legacy_Bridge_Companion::instance();
