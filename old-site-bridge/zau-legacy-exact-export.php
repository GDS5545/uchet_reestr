<?php
/**
 * Plugin Name: ZAU — точный экспорт старого сайта (мост 2.0)
 * Description: Отдаёт новому сайту аккаунты Ultimate Member и записи WPForms без преобразований, с контрольной суммой каждой записи. Устанавливается ТОЛЬКО на старом сайте.
 * Version: 2.0.0
 * Requires PHP: 7.2
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Старый сайт ничего не угадывает и не объединяет: он отдаёт строки базы как
 * есть (аккаунт + все его метаполя, запись WPForms + все её поля) и считает
 * SHA-256 каждой записи. Новый сайт хранит копию и сверяет суммы — так видно,
 * что перенесено 100% данных без искажений.
 */
final class ZAU_Legacy_Exact_Export {
    const VERSION = '2.0.0';
    const OPT = 'zau_exact_export_settings';
    const NS = 'zau-legacy-exact/v1';
    const MAX_CLOCK_SKEW = 300;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_zau_exact_export_save', [$this, 'save_settings']);
    }

    private function settings() {
        return wp_parse_args((array)get_option(self::OPT, []), ['enabled'=>0, 'secret'=>'']);
    }

    public function admin_menu() {
        add_management_page('ZAU точный экспорт', 'ZAU точный экспорт', 'manage_options', 'zau-exact-export', [$this, 'page']);
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.', 403); }
        check_admin_referer('zau_exact_export_save');
        $s = $this->settings();
        $s['enabled'] = !empty($_POST['enabled']) ? 1 : 0;
        if (!empty($_POST['regenerate']) || $s['secret'] === '') { $s['secret'] = wp_generate_password(48, false, false); }
        update_option(self::OPT, $s, false);
        wp_safe_redirect(add_query_arg(['page'=>'zau-exact-export','saved'=>1], admin_url('tools.php')));
        exit;
    }

    public function page() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.'); }
        $s = $this->settings();
        $st = $this->status_payload();
        ?>
        <div class="wrap">
            <h1>ZAU — точный экспорт старого сайта <?php echo esc_html(self::VERSION); ?></h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Сохранено.</p></div><?php endif; ?>
            <p>Этот мост только читает данные. Он ничего не меняет на старом сайте.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="zau_exact_export_save">
                <?php wp_nonce_field('zau_exact_export_save'); ?>
                <p><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Разрешить защищённую выгрузку для нового сайта</label></p>
                <p><label>Секретный ключ (вставьте его на новом сайте)<br><input type="text" class="large-text code" readonly value="<?php echo esc_attr($s['secret']); ?>" onclick="this.select()"></label></p>
                <p><label><input type="checkbox" name="regenerate" value="1"> Создать новый ключ</label></p>
                <p><button class="button button-primary">Сохранить</button></p>
            </form>
            <h2>Что будет выгружено</h2>
            <table class="widefat striped" style="max-width:640px">
                <tr><td>Аккаунтов WordPress / Ultimate Member</td><td><strong><?php echo (int)$st['user_count']; ?></strong></td></tr>
                <tr><td>Записей WPForms</td><td><strong><?php echo $st['entries_supported'] ? (int)$st['entry_count'] : 'таблица записей WPForms не найдена'; ?></strong></td></tr>
                <tr><td>Форм WPForms</td><td><strong><?php echo (int)$st['form_count']; ?></strong></td></tr>
                <tr><td>Ultimate Member</td><td><?php echo $st['um_active'] ? 'активен' : 'не обнаружен'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    public function routes() {
        foreach (['status','users','entries','forms'] as $route) {
            register_rest_route(self::NS, '/' . $route, [
                'methods'=>'GET',
                'callback'=>[$this, 'route_' . $route],
                'permission_callback'=>[$this, 'authorize'],
            ]);
        }
    }

    /** HMAC-подпись: timestamp \n GET \n /zau-legacy-exact/v1/route \n canonical query. */
    public function authorize(WP_REST_Request $request) {
        $s = $this->settings();
        if (empty($s['enabled']) || $s['secret'] === '') { return new WP_Error('zau_disabled', 'Выгрузка отключена на старом сайте.', ['status'=>403]); }
        $ts = (string)$request->get_header('x-zau-timestamp');
        $sig = (string)$request->get_header('x-zau-signature');
        if ($ts === '' || $sig === '' || abs(time() - (int)$ts) > self::MAX_CLOCK_SKEW) {
            return new WP_Error('zau_signature', 'Подпись запроса отсутствует или устарела. Проверьте время на обоих серверах.', ['status'=>401]);
        }
        $query = (array)$request->get_query_params();
        unset($query['rest_route']);
        ksort($query);
        $payload = $ts . "\nGET\n" . $request->get_route() . "\n" . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if (!hash_equals(hash_hmac('sha256', $payload, (string)$s['secret']), $sig)) {
            return new WP_Error('zau_signature', 'Неверный секретный ключ.', ['status'=>401]);
        }
        return true;
    }

    private function entries_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpforms_entries';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : '';
    }

    private function status_payload() {
        global $wpdb;
        $entries = $this->entries_table();
        return [
            'bridge'=>'exact',
            'bridge_version'=>self::VERSION,
            'site_url'=>home_url('/'),
            'user_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'max_user_id'=>(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->users}"),
            'entries_supported'=>$entries !== '',
            'entry_count'=>$entries ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$entries}") : 0,
            'max_entry_id'=>$entries ? (int)$wpdb->get_var("SELECT MAX(entry_id) FROM {$entries}") : 0,
            'form_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='wpforms'"),
            'um_active'=>class_exists('UM') || function_exists('UM'),
            'generated_at'=>gmdate('c'),
        ];
    }

    public function route_status() { return rest_ensure_response($this->status_payload()); }

    private function paging(WP_REST_Request $request) {
        return [max(0, (int)$request->get_param('cursor')), max(1, min(200, (int)($request->get_param('per_page') ?: 50)))];
    }

    private static function canonicalize(&$value) {
        if (!is_array($value)) { return; }
        foreach ($value as &$item) { self::canonicalize($item); }
        unset($item);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
    }

    /** Та же функция есть на новом сайте: JSON → массив → сортировка ключей → JSON → SHA-256. */
    public static function checksum(array $item) {
        unset($item['checksum']);
        $normalized = json_decode(wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        if (!is_array($normalized)) { $normalized = []; }
        self::canonicalize($normalized);
        return hash('sha256', (string)wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function route_users(WP_REST_Request $request) {
        global $wpdb;
        list($cursor, $perPage) = $this->paging($request);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID>%d ORDER BY ID ASC LIMIT %d", $cursor, $perPage), ARRAY_A);
        $ids = array_map(function ($r) { return (int)$r['ID']; }, $rows);
        $metaByUser = [];
        if ($ids) {
            $metaRows = $wpdb->get_results("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id IN (" . implode(',', $ids) . ") ORDER BY umeta_id ASC", ARRAY_A);
            foreach ($metaRows as $m) {
                // Токены сессий не являются данными участника.
                if ($m['meta_key'] === 'session_tokens') { continue; }
                $metaByUser[(int)$m['user_id']][(string)$m['meta_key']][] = (string)$m['meta_value'];
            }
        }
        $items = [];
        foreach ($rows as $row) {
            $uid = (int)$row['ID'];
            $user = get_userdata($uid);
            $item = [
                'type'=>'user',
                'id'=>$uid,
                'user_login'=>(string)$row['user_login'],
                'user_email'=>(string)$row['user_email'],
                'user_registered'=>(string)$row['user_registered'],
                'display_name'=>(string)$row['display_name'],
                'user_nicename'=>(string)$row['user_nicename'],
                'user_url'=>(string)$row['user_url'],
                'user_status'=>(int)$row['user_status'],
                'roles'=>$user ? array_values((array)$user->roles) : [],
                'password_hash'=>(string)$row['user_pass'],
                'meta'=>$metaByUser[$uid] ?? [],
            ];
            $item['checksum'] = self::checksum($item);
            $items[] = $item;
        }
        $next = $ids ? max($ids) : $cursor;
        return rest_ensure_response(['items'=>$items, 'next_cursor'=>$next, 'has_more'=>count($rows) === $perPage]);
    }

    public function route_entries(WP_REST_Request $request) {
        global $wpdb;
        $table = $this->entries_table();
        if (!$table) { return rest_ensure_response(['items'=>[], 'next_cursor'=>0, 'has_more'=>false, 'unsupported'=>1]); }
        list($cursor, $perPage) = $this->paging($request);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE entry_id>%d ORDER BY entry_id ASC LIMIT %d", $cursor, $perPage), ARRAY_A);
        $items = [];
        $next = $cursor;
        foreach ($rows as $row) {
            $entryId = (int)$row['entry_id'];
            $next = max($next, $entryId);
            $fields = json_decode((string)($row['fields'] ?? ''), true);
            $meta = json_decode((string)($row['meta'] ?? ''), true);
            $columns = $row;
            unset($columns['fields'], $columns['meta']);
            $item = [
                'type'=>'entry',
                'id'=>$entryId,
                'form_id'=>(int)($row['form_id'] ?? 0),
                'user_id'=>(int)($row['user_id'] ?? 0),
                'status'=>(string)($row['status'] ?? ''),
                'date'=>(string)($row['date'] ?? ''),
                'date_modified'=>(string)($row['date_modified'] ?? ''),
                'fields'=>is_array($fields) ? $fields : [],
                'fields_raw'=>is_array($fields) ? '' : (string)($row['fields'] ?? ''),
                'meta'=>is_array($meta) ? $meta : (string)($row['meta'] ?? ''),
                'columns'=>$columns,
            ];
            $item['checksum'] = self::checksum($item);
            $items[] = $item;
        }
        return rest_ensure_response(['items'=>$items, 'next_cursor'=>$next, 'has_more'=>count($rows) === $perPage]);
    }

    public function route_forms() {
        $posts = get_posts(['post_type'=>'wpforms', 'post_status'=>'any', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'ASC']);
        $trash = get_posts(['post_type'=>'wpforms', 'post_status'=>'trash', 'numberposts'=>-1]);
        $forms = [];
        foreach (array_merge($posts, $trash) as $post) {
            $content = json_decode((string)$post->post_content, true);
            $fields = [];
            foreach ((array)($content['fields'] ?? []) as $id => $field) {
                if (!is_array($field)) { continue; }
                $fields[] = [
                    'id'=>(string)($field['id'] ?? $id),
                    'type'=>(string)($field['type'] ?? ''),
                    'label'=>(string)($field['label'] ?? ''),
                    'choices'=>isset($field['choices']) && is_array($field['choices']) ? array_values(array_map(function ($c) { return is_array($c) ? (string)($c['label'] ?? '') : (string)$c; }, $field['choices'])) : [],
                ];
            }
            $forms[(int)$post->ID] = ['id'=>(int)$post->ID, 'title'=>(string)$post->post_title, 'status'=>(string)$post->post_status, 'fields'=>$fields];
        }
        return rest_ensure_response(['forms'=>array_values($forms)]);
    }
}

ZAU_Legacy_Exact_Export::instance();
