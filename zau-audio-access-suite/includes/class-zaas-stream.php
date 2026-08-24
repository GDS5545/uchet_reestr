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

        add_action('admin_post_zaas_upload_audio', [$this, 'handle_upload']);
        add_action('admin_post_zaas_delete_audio', [$this, 'handle_delete']);
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
