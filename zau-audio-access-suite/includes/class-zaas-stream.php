<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Encrypted-at-rest audio storage and range-aware streaming.
 *
 * Files live in wp-uploads/zaas-protected-audio/ (created + locked down by
 * ZAAS_Install::ensure_protected_directory()) encrypted with a per-file
 * repeating-key XOR stream cipher, keyed off the site's own auth salt so
 * the ciphertext is worthless without this WordPress install's secrets.
 * XOR is a stream cipher: decrypting an arbitrary byte range only requires
 * knowing that range's absolute offset, so HTTP Range/206 seeking works
 * without ever decrypting the whole file to a temp copy.
 */
final class ZAAS_Stream {

    const CPT = 'zaas_audio';

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_rewrite']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_stream'], -10);
        add_shortcode('zaas_audio', [$this, 'audio_shortcode']);
        add_shortcode('zaas_audio_cover', [$this, 'cover_shortcode']);
        add_shortcode('zaas_audio_title', [$this, 'title_shortcode']);
        add_shortcode('zaas_audio_author', [$this, 'author_shortcode']);
        add_shortcode('zaas_audio_part', [$this, 'part_shortcode']);
        add_shortcode('zaas_audio_badge', [$this, 'badge_shortcode']);
        add_shortcode('zaas_audio_bundle', [$this, 'bundle_shortcode']);

        add_action('admin_post_zaas_upload_audio', [$this, 'handle_upload']);
        add_action('admin_post_zaas_delete_audio', [$this, 'handle_delete']);

        foreach (['zaas_chunk_upload_start', 'zaas_chunk_upload_append', 'zaas_chunk_upload_finish', 'zaas_chunk_upload_cancel'] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    private function p() { return ZAAS_Plugin::instance(); }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'label'   => 'Аудиофайлы',
            'public'  => false,
            'show_ui' => false,
            'supports' => ['title'],
        ]);
    }

    public function register_rewrite() {
        add_rewrite_rule('^zaas-stream/([0-9]+)/?$', 'index.php?zaas_stream_id=$matches[1]', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'zaas_stream_id';
        $vars[] = 'zaas_stream_token';
        $vars[] = 'zaas_stream_expires';
        return $vars;
    }

    /* ------------------------------------------------------------------ *
     *  Storage helpers
     * ------------------------------------------------------------------ */

    private function protected_dir() {
        return ZAAS_Install::ensure_protected_directory();
    }

    private function encryption_key($audio_id) {
        return hash('sha256', 'zaas-audio|' . $audio_id . '|' . wp_salt('auth') . '|' . home_url(), true);
    }

    /**
     * XORs $data (which represents the bytes starting at absolute file
     * offset $offset) against the repeating key, correctly resuming the
     * key cycle mid-stream so arbitrary byte ranges decrypt correctly.
     */
    private function crypt_bytes($data, $key, $offset) {
        $key_len = strlen($key);
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $key_index = ($offset + $i) % $key_len;
            $out .= $data[$i] ^ $key[$key_index];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ *
     *  Upload / delete (admin, "Файлы" tab)
     * ------------------------------------------------------------------ */

    public function handle_upload() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); }
        check_admin_referer(ZAAS_Plugin::NONCE);

        if (empty($_FILES['audio_file']) || !is_uploaded_file($_FILES['audio_file']['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page=zaas-files&zaas_error=' . rawurlencode('Файл не получен.')));
            exit;
        }
        $orig_name = sanitize_file_name(wp_unslash($_FILES['audio_file']['name']));
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'm4a', 'wav', 'ogg'], true)) {
            wp_safe_redirect(admin_url('admin.php?page=zaas-files&zaas_error=' . rawurlencode('Поддерживаются файлы mp3, m4a, wav, ogg.')));
            exit;
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? $orig_name));
        $product_id = absint($_POST['product_id'] ?? 0);
        $part_title = sanitize_text_field(wp_unslash($_POST['part_title'] ?? ''));
        $author = sanitize_text_field(wp_unslash($_POST['author'] ?? ''));
        $cover_url = esc_url_raw(wp_unslash($_POST['cover_url'] ?? ''));

        $post_id = wp_insert_post(['post_type' => self::CPT, 'post_title' => $title, 'post_status' => 'publish']);
        if (is_wp_error($post_id) || !$post_id) {
            wp_safe_redirect(admin_url('admin.php?page=zaas-files&zaas_error=' . rawurlencode('Не удалось создать запись аудиофайла.')));
            exit;
        }

        $data = file_get_contents($_FILES['audio_file']['tmp_name']);
        $key = $this->encryption_key($post_id);
        $encrypted = $this->crypt_bytes($data, $key, 0);

        $filename = 'audio-' . $post_id . '-' . wp_generate_password(8, false) . '.zaas';
        $path = trailingslashit($this->protected_dir()) . $filename;
        file_put_contents($path, $encrypted);

        update_post_meta($post_id, '_zaas_file', $filename);
        update_post_meta($post_id, '_zaas_mime', $ext === 'mp3' ? 'audio/mpeg' : 'audio/' . $ext);
        update_post_meta($post_id, '_zaas_size', strlen($data));
        update_post_meta($post_id, '_zaas_product_id', $product_id);
        update_post_meta($post_id, '_zaas_part_title', $part_title);
        update_post_meta($post_id, '_zaas_author', $author);
        update_post_meta($post_id, '_zaas_cover_url', $cover_url);

        $this->p()->log('audio_uploaded', 'audio', $post_id, $title);
        wp_safe_redirect(admin_url('admin.php?page=zaas-files&zaas_notice=' . rawurlencode('Файл загружен и зашифрован.')));
        exit;
    }

    public function handle_delete() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); }
        check_admin_referer(ZAAS_Plugin::NONCE);
        $id = absint($_GET['id'] ?? 0);
        if ($id) {
            $filename = get_post_meta($id, '_zaas_file', true);
            if ($filename) {
                $path = trailingslashit($this->protected_dir()) . basename($filename);
                if (file_exists($path)) { unlink($path); }
            }
            wp_delete_post($id, true);
            $this->p()->log('audio_deleted', 'audio', $id);
        }
        wp_safe_redirect(admin_url('admin.php?page=zaas-files&zaas_notice=' . rawurlencode('Файл удалён.')));
        exit;
    }

    /* ------------------------------------------------------------------ *
     *  Resumable chunked upload (parity with zau-audiobook-suite's
     *  class-zau-suite-replace.php). Each chunk is XOR-encrypted with the
     *  correct absolute-offset key stream and appended straight to the
     *  final protected file — the plaintext audio is never written to
     *  disk as a whole, only ever held in memory one chunk at a time, so
     *  large audiobook files (hundreds of MB) upload without the PHP
     *  memory spikes a single file_get_contents()+crypt would cause.
     * ------------------------------------------------------------------ */

    private function require_upload_cap() {
        if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_send_json_error(['message' => 'Недостаточно прав.'], 403); }
    }

    /**
     * Creates a draft CPT post (its ID doubles as the upload/audio ID and
     * the encryption key's salt input) and an empty destination file.
     */
    public function ajax_zaas_chunk_upload_start() {
        $this->require_upload_cap();
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');

        $orig_name = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'm4a', 'wav', 'ogg'], true)) {
            wp_send_json_error(['message' => 'Поддерживаются файлы mp3, m4a, wav, ogg.'], 400);
        }
        $total_size = absint($_POST['total_size'] ?? 0);
        if ($total_size < 1) { wp_send_json_error(['message' => 'Некорректный размер файла.'], 400); }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? $orig_name));
        $post_id = wp_insert_post(['post_type' => self::CPT, 'post_title' => $title, 'post_status' => 'draft']);
        if (is_wp_error($post_id) || !$post_id) { wp_send_json_error(['message' => 'Не удалось создать запись аудиофайла.'], 500); }

        $filename = 'audio-' . $post_id . '-' . wp_generate_password(8, false) . '.zaas';
        $path = trailingslashit($this->protected_dir()) . $filename;
        file_put_contents($path, ''); // truncate/create empty

        update_post_meta($post_id, '_zaas_file', $filename);
        update_post_meta($post_id, '_zaas_mime', $ext === 'mp3' ? 'audio/mpeg' : 'audio/' . $ext);
        update_post_meta($post_id, '_zaas_upload_total_bytes', $total_size);
        update_post_meta($post_id, '_zaas_upload_received_bytes', 0);

        wp_send_json_success(['audio_id' => $post_id, 'chunk_size' => 5 * MB_IN_BYTES]);
    }

    /**
     * Appends one chunk. $_POST['offset'] must equal the bytes already on
     * disk — this both orders chunks correctly and rejects a retried
     * chunk from being written twice.
     */
    public function ajax_zaas_chunk_upload_append() {
        $this->require_upload_cap();
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');

        $audio_id = absint($_POST['audio_id'] ?? 0);
        $offset = absint($_POST['offset'] ?? 0);
        if (!$audio_id || get_post_type($audio_id) !== self::CPT || get_post_status($audio_id) !== 'draft') {
            wp_send_json_error(['message' => 'Сессия загрузки не найдена.'], 404);
        }
        if (empty($_FILES['chunk']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            wp_send_json_error(['message' => 'Часть файла не получена.'], 400);
        }

        $filename = get_post_meta($audio_id, '_zaas_file', true);
        $path = trailingslashit($this->protected_dir()) . basename($filename);
        $current_size = file_exists($path) ? filesize($path) : 0;
        if ($offset !== $current_size) {
            wp_send_json_error(['message' => 'Части файла пришли не по порядку, обновите страницу и загрузите заново.', 'expected_offset' => $current_size], 409);
        }

        $chunk = file_get_contents($_FILES['chunk']['tmp_name']);
        if ($chunk === false) { wp_send_json_error(['message' => 'Не удалось прочитать часть файла.'], 500); }

        $key = $this->encryption_key($audio_id);
        $encrypted = $this->crypt_bytes($chunk, $key, $offset);
        $fh = fopen($path, 'ab');
        if (!$fh) { wp_send_json_error(['message' => 'Не удалось записать файл на сервере.'], 500); }
        fwrite($fh, $encrypted);
        fclose($fh);

        $received = $offset + strlen($chunk);
        update_post_meta($audio_id, '_zaas_upload_received_bytes', $received);
        wp_send_json_success(['received' => $received]);
    }

    /**
     * Finalizes the upload: verifies the received byte count matches what
     * the browser announced, attaches the remaining metadata, and
     * publishes the post so it becomes playable.
     */
    public function ajax_zaas_chunk_upload_finish() {
        $this->require_upload_cap();
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');

        $audio_id = absint($_POST['audio_id'] ?? 0);
        if (!$audio_id || get_post_type($audio_id) !== self::CPT) { wp_send_json_error(['message' => 'Сессия загрузки не найдена.'], 404); }

        $total = (int) get_post_meta($audio_id, '_zaas_upload_total_bytes', true);
        $received = (int) get_post_meta($audio_id, '_zaas_upload_received_bytes', true);
        if ($total < 1 || $received !== $total) {
            wp_send_json_error(['message' => 'Загружено не всё: получено ' . $received . ' из ' . $total . ' байт.'], 409);
        }

        update_post_meta($audio_id, '_zaas_size', $total);
        update_post_meta($audio_id, '_zaas_product_id', absint($_POST['product_id'] ?? 0));
        update_post_meta($audio_id, '_zaas_part_title', sanitize_text_field(wp_unslash($_POST['part_title'] ?? '')));
        update_post_meta($audio_id, '_zaas_author', sanitize_text_field(wp_unslash($_POST['author'] ?? '')));
        update_post_meta($audio_id, '_zaas_cover_url', esc_url_raw(wp_unslash($_POST['cover_url'] ?? '')));
        delete_post_meta($audio_id, '_zaas_upload_total_bytes');
        delete_post_meta($audio_id, '_zaas_upload_received_bytes');
        wp_update_post(['ID' => $audio_id, 'post_status' => 'publish']);

        $this->p()->log('audio_uploaded', 'audio', $audio_id, get_the_title($audio_id));
        wp_send_json_success(['audio_id' => $audio_id]);
    }

    public function ajax_zaas_chunk_upload_cancel() {
        $this->require_upload_cap();
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $audio_id = absint($_POST['audio_id'] ?? 0);
        if ($audio_id && get_post_type($audio_id) === self::CPT && get_post_status($audio_id) === 'draft') {
            $filename = get_post_meta($audio_id, '_zaas_file', true);
            if ($filename) {
                $path = trailingslashit($this->protected_dir()) . basename($filename);
                if (file_exists($path)) { unlink($path); }
            }
            wp_delete_post($audio_id, true);
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------ *
     *  Signed streaming URL + shortcode
     * ------------------------------------------------------------------ */

    private function sign($audio_id, $email, $expires) {
        return hash_hmac('sha256', $audio_id . '|' . strtolower($email) . '|' . $expires, wp_salt('secure_auth'));
    }

    public function stream_url($audio_id, $email, $ttl_seconds = 21600) {
        $expires = time() + max(60, $ttl_seconds);
        $token = $this->sign($audio_id, $email, $expires);
        return add_query_arg([
            'zaas_stream_token'   => $token,
            'zaas_stream_expires' => $expires,
        ], home_url('/zaas-stream/' . absint($audio_id) . '/'));
    }

    public function audio_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $audio_id = absint($atts['id']);
        if (!$audio_id || get_post_type($audio_id) !== self::CPT) { return ''; }

        $product_id = (int) get_post_meta($audio_id, '_zaas_product_id', true);
        $email = ZAAS_Access::instance()->resolve_customer_email();
        if (!$email || ($product_id && !ZAAS_Access::instance()->has_active_access($product_id, $email))) {
            return ZAAS_Access::instance()->buy_button_shortcode(['product_id' => $product_id]);
        }

        $url = $this->stream_url($audio_id, $email);
        $title = get_the_title($audio_id);
        return '<div class="zaas-interface zaas-player"><div class="zaas-player-title">' . esc_html($title) . '</div>' .
            '<audio class="zaas-audio-el" controls controlsList="nodownload" preload="none" src="' . esc_url($url) . '"></audio></div>';
    }

    /* ------------------------------------------------------------------ *
     *  Granular metadata shortcodes — one per book-display field, parity
     *  with zau-audiobook-suite's zau_audio_cover/title/author/part/badge
     *  set, for pages that build their own custom book layout instead of
     *  using the all-in-one [zaas_audio] player.
     * ------------------------------------------------------------------ */

    private function valid_audio_id($atts) {
        $atts = shortcode_atts(['id' => 0], (array) $atts);
        $audio_id = absint($atts['id']);
        return ($audio_id && get_post_type($audio_id) === self::CPT) ? $audio_id : 0;
    }

    public function cover_shortcode($atts) {
        $audio_id = $this->valid_audio_id($atts);
        if (!$audio_id) { return ''; }
        $cover = get_post_meta($audio_id, '_zaas_cover_url', true);
        if (!$cover) { return ''; }
        return '<img class="zaas-audio-cover" src="' . esc_url($cover) . '" alt="' . esc_attr(get_the_title($audio_id)) . '">';
    }

    public function title_shortcode($atts) {
        $audio_id = $this->valid_audio_id($atts);
        return $audio_id ? esc_html(get_the_title($audio_id)) : '';
    }

    public function author_shortcode($atts) {
        $audio_id = $this->valid_audio_id($atts);
        return $audio_id ? esc_html(get_post_meta($audio_id, '_zaas_author', true)) : '';
    }

    public function part_shortcode($atts) {
        $audio_id = $this->valid_audio_id($atts);
        return $audio_id ? esc_html(get_post_meta($audio_id, '_zaas_part_title', true)) : '';
    }

    public function badge_shortcode($atts) {
        $audio_id = $this->valid_audio_id($atts);
        if (!$audio_id) { return ''; }
        $product_id = (int) get_post_meta($audio_id, '_zaas_product_id', true);
        $has_access = $product_id ? ZAAS_Access::instance()->has_active_access($product_id) : false;
        return $has_access
            ? '<span class="zaas-badge zaas-badge-active">Доступно</span>'
            : '<span class="zaas-badge">Нет доступа</span>';
    }

    /**
     * Lists every audio part belonging to one product — cover, title,
     * author, part label, badge, and (if the visitor has access) the
     * player itself. Equivalent to zau_audio_bundle/zau_audio_book.
     */
    public function bundle_shortcode($atts) {
        $atts = shortcode_atts(['product_id' => 0], $atts);
        $product_id = absint($atts['product_id']);
        if (!$product_id) { return ''; }

        $parts = get_posts([
            'post_type' => self::CPT, 'numberposts' => -1, 'post_status' => 'publish',
            'meta_key' => '_zaas_product_id', 'meta_value' => $product_id,
            'orderby' => 'menu_order title', 'order' => 'ASC',
        ]);
        if (!$parts) { return ''; }

        $email = ZAAS_Access::instance()->resolve_customer_email();
        $has_access = ZAAS_Access::instance()->has_active_access($product_id, $email);

        ob_start();
        echo '<div class="zaas-interface zaas-bundle">';
        foreach ($parts as $part) {
            echo '<div class="zaas-bundle-item">';
            echo $this->cover_shortcode(['id' => $part->ID]);
            echo '<div class="zaas-bundle-item-body">';
            echo '<div class="zaas-bundle-item-title">' . esc_html(get_the_title($part->ID)) . '</div>';
            $author = get_post_meta($part->ID, '_zaas_author', true);
            if ($author) { echo '<div class="zaas-muted">' . esc_html($author) . '</div>'; }
            $part_title = get_post_meta($part->ID, '_zaas_part_title', true);
            if ($part_title) { echo '<div class="zaas-muted">' . esc_html($part_title) . '</div>'; }
            echo $this->badge_shortcode(['id' => $part->ID]);
            if ($has_access) { echo $this->audio_shortcode(['id' => $part->ID]); }
            echo '</div></div>';
        }
        echo '</div>';
        if (!$has_access) { echo ZAAS_Access::instance()->buy_button_shortcode(['product_id' => $product_id]); }
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ *
     *  Range streaming
     * ------------------------------------------------------------------ */

    public function maybe_stream() {
        $audio_id = absint(get_query_var('zaas_stream_id'));
        if (!$audio_id) { return; }

        $token = sanitize_text_field(wp_unslash($_GET['zaas_stream_token'] ?? ''));
        $expires = absint($_GET['zaas_stream_expires'] ?? 0);
        if (!$token || !$expires || $expires < time()) {
            status_header(403); exit('Ссылка недействительна или истекла.');
        }

        $email = ZAAS_Access::instance()->resolve_customer_email();
        if (!$email || !hash_equals($this->sign($audio_id, $email, $expires), $token)) {
            status_header(403); exit('Доступ запрещён.');
        }

        $product_id = (int) get_post_meta($audio_id, '_zaas_product_id', true);
        if ($product_id && !ZAAS_Access::instance()->has_active_access($product_id, $email)) {
            status_header(403); exit('Доступ к этой книге не активен.');
        }

        if (!$this->claim_stream_slot($email, $audio_id)) {
            status_header(429); exit('Одновременно воспроизводится максимум разрешённое число потоков. Остановите плеер на другом устройстве.');
        }

        $filename = get_post_meta($audio_id, '_zaas_file', true);
        $path = $filename ? trailingslashit($this->protected_dir()) . basename($filename) : '';
        if (!$filename || !file_exists($path)) { status_header(404); exit('Файл не найден.'); }

        $this->stream_file($path, $audio_id, (string) get_post_meta($audio_id, '_zaas_mime', true));
        exit;
    }

    private function claim_stream_slot($email, $audio_id) {
        global $wpdb;
        $settings = $this->p()->settings();
        $max_streams = max(1, (int) $settings['simultaneous_streams']);
        $device_id = substr(hash('sha256', $email . '|' . ($_COOKIE[ZAAS_Access::DEVICE_COOKIE] ?? '')), 0, 32);
        $audio_key = (string) $audio_id;

        $wpdb->query("DELETE FROM {$this->p()->stream_locks_table} WHERE updated_at < (UTC_TIMESTAMP() - INTERVAL 30 SECOND)");

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->p()->stream_locks_table} (customer_email, device_id, audio_key, updated_at) VALUES (%s,%s,%s, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()",
            $email, $device_id, $audio_key
        ));

        $active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT device_id) FROM {$this->p()->stream_locks_table} WHERE customer_email=%s", $email
        ));
        return $active <= $max_streams;
    }

    private function stream_file($path, $audio_id, $mime) {
        $size = filesize($path);
        $key = $this->encryption_key($audio_id);

        $start = 0;
        $end = $size - 1;
        $is_range = false;

        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $is_range = true;
            if ($m[1] !== '') { $start = (int) $m[1]; }
            if ($m[2] !== '') { $end = (int) $m[2]; }
            if ($end >= $size) { $end = $size - 1; }
            if ($start > $end) { $start = 0; $end = $size - 1; $is_range = false; }
        }

        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex');
        header('Content-Disposition: inline');

        $length = $end - $start + 1;
        if ($is_range) {
            status_header(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            status_header(200);
        }
        header('Content-Length: ' . $length);

        $fh = fopen($path, 'rb');
        if (!$fh) { status_header(500); exit; }
        fseek($fh, $start);

        $chunk_size = 8192;
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $read = min($chunk_size, $remaining);
            $offset = $start + ($length - $remaining);
            $data = fread($fh, $read);
            if ($data === false || $data === '') { break; }
            echo $this->crypt_bytes($data, $key, $offset);
            $remaining -= strlen($data);
            flush();
        }
        fclose($fh);
    }
}
