<?php
/**
 * Серверная отрисовка документа по шаблону (GD + TrueType) — то же, что делает браузер
 * в renderDocument(): подложка, текстовые поля с переносом строк, подписи/печати, QR.
 * Нужна для фонового пересоздания PDF без открытой вкладки.
 */
if (!defined('ABSPATH')) { exit; }

final class ZAU_Server_Render {
    /** Поля-картинки, как в браузере (imageKeys + zau_cert_field_types). */
    private static function image_keys() {
        $types = (array)apply_filters('zau_cert_field_types', ['signature_url'=>'image', 'signature2_url'=>'image', 'stamp_url'=>'image']);
        $keys = ['signature_url', 'signature2_url', 'stamp_url'];
        foreach ($types as $k => $t) { if ($t === 'image') { $keys[] = (string)$k; } }
        return array_values(array_unique($keys));
    }

    public static function fonts_dir() {
        return dirname(__DIR__) . '/assets/fonts/';
    }

    /** Можно ли рисовать на этом сервере: GD с FreeType, JPEG и шрифты в плагине. */
    public static function available(&$reason = '') {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) { $reason = 'На сервере нет расширения PHP GD.'; return false; }
        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) { $reason = 'В PHP GD нет поддержки шрифтов FreeType.'; return false; }
        if (!is_file(self::fonts_dir() . 'LiberationSans-Regular.ttf')) { $reason = 'В папке плагина нет шрифтов assets/fonts.'; return false; }
        return true;
    }

    private static function font_file($family, $bold, $italic) {
        $serif = (bool)preg_match('/times|georgia|serif|garamond|cambria|roman/i', (string)$family) && !preg_match('/sans/i', (string)$family);
        $base = $serif ? 'LiberationSerif' : 'LiberationSans';
        $style = $bold && $italic ? 'BoldItalic' : ($bold ? 'Bold' : ($italic ? 'Italic' : 'Regular'));
        $file = self::fonts_dir() . $base . '-' . $style . '.ttf';
        if (!is_file($file)) { $file = self::fonts_dir() . $base . '-' . ($bold ? 'Bold' : 'Regular') . '.ttf'; }
        if (!is_file($file)) { $file = self::fonts_dir() . 'LiberationSans-Regular.ttf'; }
        return $file;
    }

    /** Локальный путь к файлу по URL этого сайта (подложки и подписи лежат у нас). */
    private static function local_path($url) {
        $url = (string)$url;
        if ($url === '') { return ''; }
        $path = rawurldecode((string)wp_parse_url($url, PHP_URL_PATH));
        $maps = [];
        $up = wp_upload_dir();
        if (empty($up['error'])) { $maps[(string)wp_parse_url($up['baseurl'], PHP_URL_PATH)] = $up['basedir']; }
        $maps[(string)wp_parse_url(plugins_url(), PHP_URL_PATH)] = WP_PLUGIN_DIR;
        $maps[(string)wp_parse_url(content_url(), PHP_URL_PATH)] = WP_CONTENT_DIR;
        $maps[rtrim((string)wp_parse_url(site_url('/'), PHP_URL_PATH), '/')] = rtrim(ABSPATH, '/');
        foreach ($maps as $prefix => $dir) {
            $prefix = rtrim(rawurldecode($prefix), '/');
            if ($prefix !== '' && strpos($path, $prefix . '/') === 0) {
                $rel = substr($path, strlen($prefix));
                if (strpos($rel, '..') !== false) { continue; }
                $file = rtrim($dir, '/') . $rel;
                if (is_file($file)) { return $file; }
            }
        }
        return '';
    }

    /** Файл со старого сайта, уже скопированный точным переносом в uploads/zau-legacy-files (поиск по имени файла). */
    private static function legacy_copy($url) {
        static $map = null;
        $name = rawurldecode(basename((string)wp_parse_url((string)$url, PHP_URL_PATH)));
        if ($name === '' || strpos($name, '.') === false) { return ''; }
        $up = wp_upload_dir();
        if (!empty($up['error'])) { return ''; }
        $root = trailingslashit($up['basedir']) . 'zau-legacy-files';
        if (!is_dir($root)) { return ''; }
        if ($map === null) {
            $map = get_transient('zau_legacy_files_index');
            if (!is_array($map)) {
                $map = [];
                foreach ((array)@scandir($root) as $dir) {
                    if ($dir === '.' || $dir === '..' || !is_dir($root . '/' . $dir)) { continue; }
                    foreach ((array)@scandir($root . '/' . $dir) as $f) { if ($f !== '.' && $f !== '..') { $map[$f] = $dir; } }
                }
                set_transient('zau_legacy_files_index', $map, HOUR_IN_SECONDS);
            }
        }
        return isset($map[$name]) && is_file($root . '/' . $map[$name] . '/' . $name) ? $root . '/' . $map[$name] . '/' . $name : '';
    }

    private static function load_image($url) {
        static $cache = [];
        $url = (string)$url;
        if ($url === '') { return null; }
        if (array_key_exists($url, $cache)) { return $cache[$url]; }
        $file = self::local_path($url);
        if ($file === '') { $file = self::legacy_copy($url); }
        $bytes = $file !== '' ? @file_get_contents($file) : '';
        if (($bytes === '' || $bytes === false) && preg_match('#^https?://#i', $url)) {
            // Внешний файл (например, подпись на старом сайте): ждём недолго, чтобы хостинг не оборвал обработку.
            $r = wp_remote_get($url, ['timeout'=>8, 'redirection'=>2]);
            $bytes = is_wp_error($r) ? '' : (string)wp_remote_retrieve_body($r);
        }
        $img = $bytes ? @imagecreatefromstring($bytes) : false;
        if (count($cache) > 8) { $cache = []; }
        return $cache[$url] = ($img ?: null);
    }

    private static function color($im, $hex) {
        $hex = ltrim((string)$hex, '#');
        if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) { $hex = '111111'; }
        return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private static function text_width($pt, $font, $text) {
        if ($text === '') { return 0.0; }
        $b = imagettfbbox($pt, 0, $font, $text);
        return (float)max($b[2], $b[4]) - (float)min($b[0], $b[6]);
    }

    /** Перенос по словам — повторяет splitText() из браузера. */
    private static function split_text($pt, $font, $text, $maxWidth) {
        $lines = [];
        $paragraphs = preg_split('/\r?\n/', (string)$text);
        foreach ($paragraphs as $pi => $paragraph) {
            $words = preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) { $lines[] = ''; continue; }
            $line = '';
            foreach ($words as $word) {
                if (self::text_width($pt, $font, $word) > $maxWidth) {
                    if ($line !== '') { $lines[] = $line; $line = ''; }
                    $chunk = '';
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                        $test = $chunk . $ch;
                        if (self::text_width($pt, $font, $test) > $maxWidth && $chunk !== '') { $lines[] = $chunk; $chunk = $ch; } else { $chunk = $test; }
                    }
                    $line = $chunk;
                    continue;
                }
                $test = $line !== '' ? $line . ' ' . $word : $word;
                if (self::text_width($pt, $font, $test) > $maxWidth && $line !== '') { $lines[] = $line; $line = $word; } else { $line = $test; }
            }
            if ($line !== '') { $lines[] = $line; }
            if ($pi < count($paragraphs) - 1) { $lines[] = ''; }
        }
        return $lines;
    }

    private static function draw_text($im, array $cfg, $value, $w, $h) {
        $value = is_scalar($value) ? (string)$value : '';
        if (empty($cfg['enabled']) || trim($value) === '') { return; }
        $x = (float)($cfg['x'] ?? 0) / 100 * $w;
        $y = (float)($cfg['y'] ?? 0) / 100 * $h;
        $maxWidth = (float)($cfg['width'] ?? 50) / 100 * $w;
        $px = (float)($cfg['fontSize'] ?? 36);
        if ($px <= 0) { return; }
        $pt = $px * 0.75; // GD считает размер в пунктах при 96 dpi
        $font = self::font_file($cfg['fontFamily'] ?? 'Arial', !empty($cfg['bold']), !empty($cfg['italic']));
        $align = in_array($cfg['align'] ?? 'center', ['left', 'right', 'center'], true) ? $cfg['align'] : 'center';
        $lines = array_slice(self::split_text($pt, $font, $value, $maxWidth), 0, max(1, (int)($cfg['maxLines'] ?? 2)));
        $lh = $px * (float)($cfg['lineHeight'] ?? 1.2);
        $tx = $align === 'left' ? $x : ($align === 'right' ? $x + $maxWidth : $x + $maxWidth / 2);
        $startY = self::text_start_y($cfg, $y, $h, count($lines) * $lh);
        $color = self::color($im, $cfg['color'] ?? '#111111');
        // textBaseline 'top' в браузере — верх em-квадрата: базовая линия ниже на ascent/(ascent+descent) кегля
        // (Arial/Liberation Sans: 0.905/1.117 ≈ 0.81).
        $ascent = $px * 0.81;
        foreach ($lines as $i => $line) {
            if ($line === '') { continue; }
            $lw = self::text_width($pt, $font, $line);
            $lx = $align === 'left' ? $tx : ($align === 'right' ? $tx - $lw : $tx - $lw / 2);
            imagettftext($im, $pt, 0, (int)round($lx), (int)round($startY + $i * $lh + $ascent), $color, $font, $line);
        }
    }

    /** Верх первой строки: прижатие к верху / низу / центру рамки (как в браузере). */
    public static function text_start_y(array $cfg, $y, $h, $blockH) {
        $valign = (string)($cfg['valign'] ?? '');
        if ($valign === 'bottom' || $valign === 'middle') {
            $bottom = $y + (float)($cfg['height'] ?? 0) / 100 * $h;
            return $valign === 'bottom' ? $bottom - $blockH : $y + (($bottom - $y) - $blockH) / 2;
        }
        return ($cfg['growDirection'] ?? '') === 'up' ? $y - $blockH : $y;
    }

    private static function draw_image($im, array $cfg, $url, $w, $h) {
        if (empty($cfg['enabled']) || trim((string)$url) === '') { return; }
        $src = self::load_image($url);
        if (!$src) { throw new RuntimeException('Не удалось загрузить изображение: ' . basename((string)wp_parse_url((string)$url, PHP_URL_PATH))); }
        $sw = imagesx($src); $sh = imagesy($src);
        $x = (float)($cfg['x'] ?? 0) / 100 * $w; $y = (float)($cfg['y'] ?? 0) / 100 * $h;
        $bw = (float)($cfg['width'] ?? 20) / 100 * $w; $bh = (float)($cfg['height'] ?? 10) / 100 * $h;
        $mode = $cfg['fit'] ?? 'contain';
        $dx = $x; $dy = $y; $dw = $bw; $dh = $bh;
        if ($mode !== 'stretch') {
            $scale = $mode === 'cover' ? max($bw / $sw, $bh / $sh) : min($bw / $sw, $bh / $sh);
            $dw = $sw * $scale; $dh = $sh * $scale; $dx = $x + ($bw - $dw) / 2; $dy = $y + ($bh - $dh) / 2;
        }
        $opacity = max(0.1, min(1.0, (float)($cfg['opacity'] ?? 1)));
        $layer = imagecreatetruecolor(max(1, (int)round($dw)), max(1, (int)round($dh)));
        imagealphablending($layer, false); imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagecopyresampled($layer, $src, 0, 0, 0, 0, imagesx($layer), imagesy($layer), $sw, $sh);
        if ($opacity < 1) {
            for ($py = 0, $lh = imagesy($layer); $py < $lh; $py++) {
                for ($px = 0, $lw = imagesx($layer); $px < $lw; $px++) {
                    $c = imagecolorat($layer, $px, $py);
                    $a = ($c >> 24) & 0x7F;
                    $na = 127 - (int)round((127 - $a) * $opacity);
                    imagesetpixel($layer, $px, $py, ($c & 0xFFFFFF) | ($na << 24));
                }
            }
        }
        imagealphablending($im, true);
        if ($mode === 'cover') {
            // Обрезка по рамке поля.
            $cx = (int)max(0, round($x - $dx)); $cy = (int)max(0, round($y - $dy));
            imagecopy($im, $layer, (int)round(max($dx, $x)), (int)round(max($dy, $y)), $cx, $cy, (int)round(min($dw, $bw)), (int)round(min($dh, $bh)));
        } else {
            imagecopy($im, $layer, (int)round($dx), (int)round($dy), 0, 0, imagesx($layer), imagesy($layer));
        }
        imagedestroy($layer);
    }

    private static function draw_background($im, $img, $w, $h, $mode) {
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 255, 255, 255));
        if (!$img) { return; }
        $sw = imagesx($img); $sh = imagesy($img);
        if ($mode === 'stretch') { imagecopyresampled($im, $img, 0, 0, 0, 0, $w, $h, $sw, $sh); return; }
        $scale = $mode === 'cover' ? max($w / $sw, $h / $sh) : min($w / $sw, $h / $sh);
        $dw = $sw * $scale; $dh = $sh * $scale;
        imagecopyresampled($im, $img, (int)round(($w - $dw) / 2), (int)round(($h - $dh) / 2), 0, 0, (int)round($dw), (int)round($dh), $sw, $sh);
    }

    /** Пошаговая проверка отрисовки одного документа: что и сколько времени занимает. Ничего не сохраняет. */
    public static function probe(array $tpl, array $values) {
        $steps = [];
        $step = function ($name, callable $fn) use (&$steps) {
            $t = microtime(true);
            try { $info = $fn(); $ok = true; } catch (Throwable $e) { $info = $e->getMessage(); $ok = false; }
            $steps[] = ['step'=>$name, 'ok'=>$ok, 'ms'=>(int)round((microtime(true) - $t) * 1000), 'info'=>is_string($info) ? $info : ''];
            return $ok;
        };
        $reason = '';
        if (!$step('GD и шрифты', function () use (&$reason) { if (!self::available($reason)) { throw new RuntimeException($reason); } return 'есть: ' . (defined('GD_VERSION') ? 'GD ' . GD_VERSION : 'GD') . ', шрифты ' . self::fonts_dir(); })) { return $steps; }
        $bgUrl = (string)($tpl['background_url'] ?? '');
        $step('Подложка', function () use ($bgUrl) {
            $file = self::local_path($bgUrl);
            $img = self::load_image($bgUrl);
            if (!$img) { throw new RuntimeException('не загрузилась: ' . $bgUrl . ($file === '' ? ' (локальный файл не найден, пробовали скачать по ссылке)' : '')); }
            return imagesx($img) . '×' . imagesy($img) . ' px, ' . ($file !== '' ? 'локальный файл' : 'скачана по ссылке');
        });
        foreach (self::image_keys() as $k) {
            $cfg = (array)($tpl['fields'][$k] ?? []);
            if (empty($cfg['enabled']) || empty($values[$k])) { continue; }
            $url = (string)$values[$k];
            $step('Картинка «' . $k . '»', function () use ($url) {
                $file = self::local_path($url) ?: self::legacy_copy($url);
                $img = self::load_image($url);
                if (!$img) { throw new RuntimeException('не загрузилась: ' . $url); }
                return imagesx($img) . '×' . imagesy($img) . ' px, ' . ($file !== '' ? 'локальный файл' : 'скачана по ссылке') . ' — ' . $url;
            });
        }
        $step('QR-код', function () use ($values) { $m = ZAU_QR::matrix((string)($values['verify_url'] ?? 'test')); return count($m) . '×' . count($m) . ' модулей'; });
        $step('Полная отрисовка JPEG', function () use ($tpl, $values) {
            $j = self::render($tpl, $values);
            if (is_wp_error($j)) { throw new RuntimeException($j->get_error_message()); }
            return round(strlen($j) / 1024) . ' КБ, пик памяти ' . round(memory_get_peak_usage(true) / 1048576) . ' МБ';
        });
        return $steps;
    }

    /** Рисует документ и возвращает JPEG (как canvas.toDataURL('image/jpeg', 0.94)). */
    public static function render(array $tpl, array $values) {
        $reason = '';
        if (!self::available($reason)) { return new WP_Error('render_unavailable', $reason); }
        $w = (int)($tpl['page_width'] ?? 1754) ?: 1754;
        $h = (int)($tpl['page_height'] ?? 2480) ?: 2480;
        try {
            $im = imagecreatetruecolor($w, $h);
            imagealphablending($im, true);
            $bgUrl = (string)($tpl['background_url'] ?? '');
            $bg = self::load_image($bgUrl);
            if ($bgUrl !== '' && !$bg) { throw new RuntimeException('Не удалось загрузить подложку шаблона: ' . $bgUrl); }
            self::draw_background($im, $bg, $w, $h, $tpl['background_mode'] ?? 'stretch');
            $fields = (array)($tpl['fields'] ?? []);
            $imageKeys = self::image_keys();
            $keys = array_values(array_filter(array_keys($fields), function ($k) { return $k !== 'qr'; }));
            foreach ($keys as $k) {
                $cfg = (array)$fields[$k];
                if (in_array($k, $imageKeys, true) || ($cfg['type'] ?? '') === 'image') { continue; }
                self::draw_text($im, $cfg, $values[$k] ?? '', $w, $h);
            }
            foreach ($keys as $k) {
                $cfg = (array)$fields[$k];
                if (!in_array($k, $imageKeys, true) && ($cfg['type'] ?? '') !== 'image') { continue; }
                self::draw_image($im, $cfg, $values[$k] ?? '', $w, $h);
            }
            $q = (array)($fields['qr'] ?? []);
            if (!empty($q['enabled']) && !empty($values['verify_url'])) {
                $size = max(90, (int)round((float)($q['width'] ?? 12) / 100 * $w));
                self::draw_qr($im, (string)$values['verify_url'], (int)round((float)($q['x'] ?? 0) / 100 * $w), (int)round((float)($q['y'] ?? 0) / 100 * $h), $size);
            }
            ob_start();
            imagejpeg($im, null, 94);
            $jpeg = (string)ob_get_clean();
            imagedestroy($im);
        } catch (Throwable $e) {
            if (ob_get_level() > 0 && isset($jpeg) === false) { @ob_end_clean(); }
            return new WP_Error('render_failed', $e->getMessage());
        }
        return strlen($jpeg) > 1000 ? $jpeg : new WP_Error('render_failed', 'Не удалось сформировать изображение документа.');
    }

    /** QR как в браузере (qrcode.js): квадрат size×size, по краям пустая рамка в 4 модуля. */
    private static function draw_qr($im, $text, $x, $y, $size) {
        $m = ZAU_QR::matrix($text);
        $n = count($m);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, $x, $y, $x + $size - 1, $y + $size - 1, $white);
        $cell = $size / ($n + 8);
        $ox = $x + 4 * $cell; $oy = $y + 4 * $cell;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (!$m[$r][$c]) { continue; }
                imagefilledrectangle($im, (int)floor($ox + $c * $cell), (int)floor($oy + $r * $cell), (int)ceil($ox + ($c + 1) * $cell) - 1, (int)ceil($oy + ($r + 1) * $cell) - 1, $black);
            }
        }
    }
}

/**
 * Минимальный кодировщик QR (байтовый режим, уровень коррекции M, версии 1–40).
 * Алгоритм по ISO/IEC 18004 (схема как в библиотеке Nayuki «QR Code generator»).
 */
final class ZAU_QR {
    // [ECC-кодовых слов на блок, число блоков] для уровня M, версии 1..40.
    private const ECC_M = [0,
        [10,1],[16,1],[26,1],[18,2],[24,2],[16,4],[18,4],[22,4],[22,5],[26,5],
        [30,5],[22,8],[22,9],[24,9],[24,10],[28,10],[28,11],[26,13],[26,14],[26,16],
        [26,17],[28,17],[28,18],[28,20],[28,21],[28,23],[28,25],[28,26],[28,28],[28,29],
        [28,31],[28,33],[28,35],[28,37],[28,38],[28,40],[28,43],[28,45],[28,47],[28,49]];

    private static function raw_modules($ver) {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) { $na = intdiv($ver, 7) + 2; $r -= (25 * $na - 10) * $na - 55; if ($ver >= 7) { $r -= 36; } }
        return $r;
    }

    private static function data_codewords($ver) {
        return intdiv(self::raw_modules($ver), 8) - self::ECC_M[$ver][0] * self::ECC_M[$ver][1];
    }

    private static function gf_mul($x, $y) {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) { $z = ($z << 1) ^ (($z >> 7) * 0x11D); $z ^= (($y >> $i) & 1) * $x; }
        return $z & 0xFF;
    }

    private static function rs_divisor($degree) {
        $result = array_fill(0, $degree, 0); $result[$degree - 1] = 1; $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) { $result[$j] = self::gf_mul($result[$j], $root); if ($j + 1 < $degree) { $result[$j] ^= $result[$j + 1]; } }
            $root = self::gf_mul($root, 0x02);
        }
        return $result;
    }

    private static function rs_remainder(array $data, array $div) {
        $result = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result); $result[] = 0;
            foreach ($div as $i => $coef) { $result[$i] ^= self::gf_mul($coef, $factor); }
        }
        return $result;
    }

    private static function alignment_positions($ver) {
        if ($ver === 1) { return []; }
        $num = intdiv($ver, 7) + 2;
        $step = ($ver === 32) ? 26 : intdiv($ver * 4 + $num * 2 + 1, $num * 2 - 2) * 2;
        $size = $ver * 4 + 17;
        $res = [6];
        for ($pos = $size - 7, $i = 0; $i < $num - 1; $i++, $pos -= $step) { array_splice($res, 1, 0, [$pos]); }
        return $res;
    }

    public static function matrix($text) {
        $bytes = array_values(unpack('C*', (string)$text) ?: []);
        $len = count($bytes);
        $ver = 0;
        for ($v = 1; $v <= 40; $v++) {
            $ccBits = $v <= 9 ? 8 : 16;
            if (4 + $ccBits + $len * 8 <= self::data_codewords($v) * 8) { $ver = $v; break; }
        }
        if (!$ver) { throw new RuntimeException('Слишком длинная ссылка для QR-кода.'); }
        // Битовый поток: режим 0100, длина, данные, терминатор, выравнивание, заполнители.
        $bits = [];
        $push = function ($val, $n) use (&$bits) { for ($i = $n - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; } };
        $push(4, 4); $push($len, $ver <= 9 ? 8 : 16);
        foreach ($bytes as $b) { $push($b, 8); }
        $cap = self::data_codewords($ver) * 8;
        $push(0, min(4, $cap - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) { $push($pad, 8); }
        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) { $b = 0; for ($j = 0; $j < 8; $j++) { $b = ($b << 1) | $bits[$i + $j]; } $data[] = $b; }
        // Блоки, коррекция ошибок, чередование.
        [$eccLen, $numBlocks] = self::ECC_M[$ver];
        $raw = intdiv(self::raw_modules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $div = self::rs_divisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $dlen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $dlen); $k += $dlen;
            $ecc = self::rs_remainder($dat, $div);
            if ($i < $numShort) { $dat[] = -1; }
            $blocks[] = array_merge($dat, $ecc);
        }
        $final = [];
        for ($i = 0, $n = count($blocks[0]); $i < $n; $i++) {
            foreach ($blocks as $j => $blk) { if ($i !== $shortLen - $eccLen || $j >= $numShort) { $final[] = $blk[$i]; } }
        }
        // Матрица.
        $size = $ver * 4 + 17;
        $mod = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = function ($x, $y, $dark) use (&$mod, &$fn) { $mod[$y][$x] = $dark; $fn[$y][$x] = true; };
        for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) { for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy)); $xx = $cx + $dx; $yy = $cy + $dy;
                if ($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size) { $set($xx, $yy, $d !== 2 && $d !== 4); }
            } }
        }
        $al = self::alignment_positions($ver); $na = count($al);
        for ($i = 0; $i < $na; $i++) { for ($j = 0; $j < $na; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) { continue; }
            for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { $set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1); } }
        } }
        $drawFormat = function ($mask) use (&$set, $size) {
            $data = (0 << 3) | $mask; // уровень M = 00
            $rem = $data; for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
            $b = (($data << 10) | $rem) ^ 0x5412;
            $bit = function ($i) use ($b) { return (($b >> $i) & 1) === 1; };
            for ($i = 0; $i <= 5; $i++) { $set(8, $i, $bit($i)); }
            $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
            for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $bit($i)); }
            for ($i = 0; $i < 8; $i++) { $set($size - 1 - $i, 8, $bit($i)); }
            for ($i = 8; $i < 15; $i++) { $set(8, $size - 15 + $i, $bit($i)); }
            $set(8, $size - 8, true);
        };
        $drawFormat(0);
        if ($ver >= 7) {
            $rem = $ver; for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); }
            $b = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) { $bit = (($b >> $i) & 1) === 1; $a = $size - 11 + $i % 3; $c = intdiv($i, 3); $set($a, $c, $bit); $set($c, $a, $bit); }
        }
        // Данные зигзагом.
        $i = 0; $total = count($final) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) { $right = 5; }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j; $upward = (($right + 1) & 2) === 0; $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) { $mod[$y][$x] = (($final[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
                }
            }
        }
        // Маска с наименьшим штрафом.
        $best = null; $bestPenalty = PHP_INT_MAX; $base = $mod;
        for ($mask = 0; $mask < 8; $mask++) {
            $mod = $base;
            self::apply_mask($mod, $fn, $mask, $size);
            $drawFormat($mask);
            $p = self::penalty($mod, $size);
            if ($p < $bestPenalty) { $bestPenalty = $p; $best = $mod; }
        }
        return $best;
    }

    private static function apply_mask(array &$mod, array $fn, $mask, $size) {
        for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) {
            if ($fn[$y][$x]) { continue; }
            switch ($mask) {
                case 0: $inv = ($x + $y) % 2 === 0; break;
                case 1: $inv = $y % 2 === 0; break;
                case 2: $inv = $x % 3 === 0; break;
                case 3: $inv = ($x + $y) % 3 === 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
                case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
            }
            if ($inv) { $mod[$y][$x] = !$mod[$y][$x]; }
        } }
    }

    private static function penalty(array $m, $size) {
        $p = 0; $dark = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1; $line = '';
                for ($b = 0; $b < $size; $b++) {
                    $v = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $line .= $v ? '1' : '0';
                    if ($b > 0) {
                        $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                        if ($v === $prev) { $run++; if ($run === 5) { $p += 3; } elseif ($run > 5) { $p++; } } else { $run = 1; }
                    }
                }
                $p += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        for ($y = 0; $y < $size - 1; $y++) { for ($x = 0; $x < $size - 1; $x++) {
            $c = $m[$y][$x];
            if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) { $p += 3; }
        } }
        foreach ($m as $row) { foreach ($row as $v) { if ($v) { $dark++; } } }
        $total = $size * $size;
        $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $p + max(0, $k) * 10;
    }
}
