<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional, default-off PWA layer (parity with zau-audiobook-suite's
 * isolated Suite PWA module — also default-off there). Serves a virtual
 * manifest.json and service worker through rewrite/query-var routes, so
 * nothing needs to be written to the site's web root. Deliberately does
 * NOT cache anything under /zaas-stream/, /wp-admin/, /wp-json/ or
 * admin-ajax.php — those must always hit the network so access checks,
 * signed stream URLs, and nonces stay live.
 */
final class ZAAS_PWA {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_rewrites']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve'], -999);
        add_action('wp_head', [$this, 'print_head_tags']);
        add_action('wp_footer', [$this, 'print_install_prompt_script']);
    }

    private function p() { return ZAAS_Plugin::instance(); }
    private function enabled() { return !empty($this->p()->settings()['pwa_enabled']); }

    public function register_rewrites() {
        add_rewrite_rule('^zaas-manifest\.json$', 'index.php?zaas_pwa=manifest', 'top');
        add_rewrite_rule('^zaas-sw\.js$', 'index.php?zaas_pwa=sw', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'zaas_pwa';
        return $vars;
    }

    public function maybe_serve() {
        $what = get_query_var('zaas_pwa');
        if (!$what || !$this->enabled()) { return; }

        if ($what === 'manifest') {
            $this->serve_manifest();
        } elseif ($what === 'sw') {
            $this->serve_service_worker();
        }
        exit;
    }

    private function library_url() {
        $library = absint($this->p()->settings()['library_page_id']);
        return $library ? get_permalink($library) : home_url('/');
    }

    private function serve_manifest() {
        $s = $this->p()->settings();
        $manifest = [
            'name' => get_bloginfo('name') . ' — Аудиокниги',
            'short_name' => get_bloginfo('name'),
            'start_url' => $this->library_url(),
            'display' => 'standalone',
            'background_color' => sanitize_hex_color($s['design_surface']) ?: '#FFFFFF',
            'theme_color' => sanitize_hex_color($s['design_accent']) ?: '#1565C0',
            'icons' => [
                ['src' => ZAAS_PLUGIN_URL . 'assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => ZAAS_PLUGIN_URL . 'assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ];
        header('Content-Type: application/manifest+json');
        header('Cache-Control: public, max-age=3600');
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function serve_service_worker() {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        ?>
const ZAAS_CACHE = 'zaas-shell-v1';
const NEVER_CACHE = ['/zaas-stream/', '/wp-admin/', '/wp-json/', '/admin-ajax.php', '/audio-dostup/', '/moya-kniga/'];

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    var url = event.request.url;
    if (event.request.method !== 'GET') { return; }
    for (var i = 0; i < NEVER_CACHE.length; i++) {
        if (url.indexOf(NEVER_CACHE[i]) !== -1) { return; } // let the browser hit the network directly
    }
    event.respondWith(
        fetch(event.request).then(function (response) {
            if (response && response.ok && (event.request.destination === 'style' || event.request.destination === 'script' || event.request.destination === 'image')) {
                var copy = response.clone();
                caches.open(ZAAS_CACHE).then(function (cache) { cache.put(event.request, copy); });
            }
            return response;
        }).catch(function () {
            return caches.match(event.request);
        })
    );
});
        <?php
    }

    public function print_head_tags() {
        if (!$this->enabled()) { return; }
        $library = absint($this->p()->settings()['library_page_id']);
        if (!is_page($library)) { return; }
        echo '<link rel="manifest" href="' . esc_url(home_url('/zaas-manifest.json')) . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url(ZAAS_PLUGIN_URL . 'assets/pwa/icon-192.png') . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr(sanitize_hex_color($this->p()->settings()['design_accent']) ?: '#1565C0') . '">' . "\n";
    }

    public function print_install_prompt_script() {
        if (!$this->enabled()) { return; }
        $library = absint($this->p()->settings()['library_page_id']);
        if (!is_page($library)) { return; }
        ?>
        <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?php echo esc_url(home_url('/zaas-sw.js')); ?>').catch(function () {});
        }
        </script>
        <?php
    }
}
