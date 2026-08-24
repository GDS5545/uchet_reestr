<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Single admin area for the whole plugin: grants, devices/passkeys, audio
 * files, Kaspi/amoCRM connection info, PIN & access policy, design
 * (style) tokens, legacy import, and the activity log — one top-level
 * menu with tabs instead of the nine separate settings screens the
 * original plugin set had spread across WooCommerce/Users/its own menus.
 */
final class ZAAS_Admin {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_notices', [$this, 'admin_notices']);

        add_action('admin_post_zaas_save_settings', [$this, 'save_settings']);
        add_action('admin_post_zaas_resend_grant', [$this, 'resend_grant']);
        add_action('admin_post_zaas_revoke_grant', [$this, 'revoke_grant']);
        add_action('admin_post_zaas_revoke_device', [$this, 'revoke_device']);
        add_action('admin_post_zaas_revoke_passkey', [$this, 'revoke_passkey']);
    }

    private function p() { return ZAAS_Plugin::instance(); }
    private function require_cap() { if (!current_user_can(ZAAS_Plugin::CAP_MANAGE)) { wp_die('Недостаточно прав.'); } }

    /* ------------------------------------------------------------------ *
     *  Menu
     * ------------------------------------------------------------------ */

    public function admin_menu() {
        add_menu_page('ZAU Аудиодоступ', 'ZAU Аудиодоступ', ZAAS_Plugin::CAP_MANAGE, 'zaas-grants', [$this, 'page_grants'], 'dashicons-format-audio', 57);
        add_submenu_page('zaas-grants', 'Гранты доступа', 'Гранты', ZAAS_Plugin::CAP_MANAGE, 'zaas-grants', [$this, 'page_grants']);
        add_submenu_page('zaas-grants', 'Устройства и Passkeys', 'Устройства', ZAAS_Plugin::CAP_MANAGE, 'zaas-devices', [$this, 'page_devices']);
        add_submenu_page('zaas-grants', 'Аудиофайлы', 'Файлы', ZAAS_Plugin::CAP_MANAGE, 'zaas-files', [$this, 'page_files']);
        add_submenu_page('zaas-grants', 'Kaspi', 'Kaspi', ZAAS_Plugin::CAP_MANAGE, 'zaas-kaspi', [$this, 'page_kaspi']);
        add_submenu_page('zaas-grants', 'amoCRM', 'amoCRM', ZAAS_Plugin::CAP_MANAGE, 'zaas-amocrm', [$this, 'page_amocrm']);
        add_submenu_page('zaas-grants', 'PIN и вход', 'PIN и вход', ZAAS_Plugin::CAP_MANAGE, 'zaas-access-settings', [$this, 'page_access_settings']);
        add_submenu_page('zaas-grants', 'Стили и виджеты', 'Стили', ZAAS_Plugin::CAP_MANAGE, 'zaas-styles', [$this, 'page_styles']);
        add_submenu_page('zaas-grants', 'Перенос старых данных', 'Импорт', ZAAS_Plugin::CAP_MANAGE, 'zaas-import', [$this, 'page_import']);
        add_submenu_page('zaas-grants', 'Журнал', 'Журнал', ZAAS_Plugin::CAP_MANAGE, 'zaas-log', [$this, 'page_log']);
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, 'zaas-') === false) { return; }
        wp_enqueue_style('zaas-admin', ZAAS_PLUGIN_URL . 'assets/css/admin.css', [], ZAAS_Plugin::VERSION);
        wp_enqueue_script('zaas-admin', ZAAS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], ZAAS_Plugin::VERSION, true);
        wp_localize_script('zaas-admin', 'ZAASAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(ZAAS_Plugin::NONCE),
        ]);
    }

    public function admin_notices() {
        if (!empty($_GET['zaas_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['zaas_error']))) . '</p></div>';
        }
        if (!empty($_GET['zaas_notice'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['zaas_notice']))) . '</p></div>';
        }
    }

    private function redirect_back($page, $notice = '', $error = '') {
        $args = ['page' => $page];
        if ($notice) { $args['zaas_notice'] = rawurlencode($notice); }
        if ($error) { $args['zaas_error'] = rawurlencode($error); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /* ------------------------------------------------------------------ *
     *  Settings save (one handler, section-aware — the form's hidden
     *  "section" field decides which settings keys get merged in, so the
     *  Kaspi tab's plain HTML fields (say) never accidentally clobber the
     *  amoCRM tab's keys when only one form was actually submitted).
     * ------------------------------------------------------------------ */

    public function save_settings() {
        $this->require_cap();
        check_admin_referer(ZAAS_Plugin::NONCE);
        $section = sanitize_key($_POST['section'] ?? '');
        $partial = [];

        switch ($section) {
            case 'access':
                $partial = [
                    'default_access_days'      => max(1, absint($_POST['default_access_days'] ?? 90)),
                    'default_activation_hours' => max(1, absint($_POST['default_activation_hours'] ?? 72)),
                    'max_devices'              => max(1, absint($_POST['max_devices'] ?? 2)),
                    'session_days'             => max(1, absint($_POST['session_days'] ?? 365)),
                    'simultaneous_streams'     => max(1, absint($_POST['simultaneous_streams'] ?? 1)),
                    'otp_minutes'              => max(1, absint($_POST['otp_minutes'] ?? 15)),
                    'otp_max_attempts'         => max(1, absint($_POST['otp_max_attempts'] ?? 5)),
                    'pin_enabled'              => isset($_POST['pin_enabled']) ? 1 : 0,
                    'pin_setup_mode'           => in_array($_POST['pin_setup_mode'] ?? '', ['off', 'optional', 'required'], true) ? $_POST['pin_setup_mode'] : 'optional',
                    'pin_min_length'           => max(4, min(10, absint($_POST['pin_min_length'] ?? 4))),
                    'pin_max_length'           => max(4, min(10, absint($_POST['pin_max_length'] ?? 8))),
                    'pin_max_attempts'         => max(1, absint($_POST['pin_max_attempts'] ?? 5)),
                    'pin_lock_minutes'         => max(1, absint($_POST['pin_lock_minutes'] ?? 15)),
                    'pin_device_only'          => isset($_POST['pin_device_only']) ? 1 : 0,
                    'passkeys_enabled'         => isset($_POST['passkeys_enabled']) ? 1 : 0,
                    'passkey_prompt_enabled'   => isset($_POST['passkey_prompt_enabled']) ? 1 : 0,
                ];
                break;

            case 'amocrm':
                $partial = [
                    'amo_account_domain'     => sanitize_text_field(wp_unslash($_POST['amo_account_domain'] ?? '')),
                    'amo_client_id'          => sanitize_text_field(wp_unslash($_POST['amo_client_id'] ?? '')),
                    'amo_client_secret'      => sanitize_text_field(wp_unslash($_POST['amo_client_secret'] ?? '')),
                    'amo_pipeline_id'        => sanitize_text_field(wp_unslash($_POST['amo_pipeline_id'] ?? '')),
                    'amo_new_status_id'      => sanitize_text_field(wp_unslash($_POST['amo_new_status_id'] ?? '')),
                    'amo_approved_status_id' => sanitize_text_field(wp_unslash($_POST['amo_approved_status_id'] ?? '')),
                    'amo_webhook_secret'     => sanitize_text_field(wp_unslash($_POST['amo_webhook_secret'] ?? '')) ?: wp_generate_password(32, false),
                    'amo_auto_create_lead'   => isset($_POST['amo_auto_create_lead']) ? 1 : 0,
                ];
                break;

            case 'styles':
                $partial = [
                    'design_enabled'     => isset($_POST['design_enabled']) ? 1 : 0,
                    'design_accent'      => sanitize_hex_color($_POST['design_accent'] ?? '') ?: '#1565C0',
                    'design_accent_dark' => sanitize_hex_color($_POST['design_accent_dark'] ?? '') ?: '#0D47A1',
                    'design_surface'     => sanitize_hex_color($_POST['design_surface'] ?? '') ?: '#FFFFFF',
                    'design_text'        => sanitize_hex_color($_POST['design_text'] ?? '') ?: '#152033',
                    'design_radius'      => max(0, min(40, absint($_POST['design_radius'] ?? 14))),
                ];
                break;

            case 'library_page':
                $partial = ['library_page_id' => absint($_POST['library_page_id'] ?? 0)];
                break;
        }

        if ($partial) { $this->p()->update_settings($partial); }
        $this->redirect_back(sanitize_key($_POST['return_page'] ?? 'zaas-grants'), 'Настройки сохранены.');
    }

    /* ------------------------------------------------------------------ *
     *  Grants
     * ------------------------------------------------------------------ */

    public function page_grants() {
        $this->require_cap();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->p()->grants_table} ORDER BY created_at DESC LIMIT 200");
        ?>
        <div class="wrap zaas-wrap">
            <h1>Гранты доступа</h1>
            <table class="widefat striped">
                <thead><tr><th>Email</th><th>Продукт</th><th>Статус</th><th>Активирован</th><th>До</th><th>Источник</th><th></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7">Пока нет ни одного гранта.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row):
                    $product = wc_get_product($row->product_id); ?>
                    <tr>
                        <td><?php echo esc_html($row->customer_email); ?></td>
                        <td><?php echo esc_html($product ? $product->get_name() : '#' . $row->product_id); ?></td>
                        <td><span class="zaas-badge zaas-badge-<?php echo esc_attr($row->status); ?>"><?php echo esc_html($row->status); ?></span></td>
                        <td><?php echo esc_html($row->activated_at ?: '—'); ?></td>
                        <td><?php echo esc_html($row->access_expires_at ?: '—'); ?></td>
                        <td><?php echo esc_html($row->source); ?></td>
                        <td class="zaas-actions">
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_resend_grant&id=' . $row->id), ZAAS_Plugin::NONCE)); ?>">Повторить письмо</a>
                            <a class="button button-link-delete" onclick="return confirm('Отозвать доступ?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_revoke_grant&id=' . $row->id), ZAAS_Plugin::NONCE)); ?>">Отозвать</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function resend_grant() {
        $this->require_cap();
        check_admin_referer(ZAAS_Plugin::NONCE);
        $id = absint($_GET['id'] ?? 0);
        global $wpdb;
        $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p()->grants_table} WHERE id=%d", $id));
        if (!$grant) { $this->redirect_back('zaas-grants', '', 'Грант не найден.'); }

        $token = ZAAS_Access::instance()->regenerate_token($id);
        if (is_wp_error($token)) { $this->redirect_back('zaas-grants', '', $token->get_error_message()); }

        if (class_exists('WooCommerce') && $grant->order_id) {
            ZAAS_WooCommerce::instance()->send_access_email($grant->order_id, [$id => $token]);
        }
        $this->redirect_back('zaas-grants', 'Ссылка пересоздана и отправлена на ' . $grant->customer_email . '.');
    }

    public function revoke_grant() {
        $this->require_cap();
        check_admin_referer(ZAAS_Plugin::NONCE);
        $id = absint($_GET['id'] ?? 0);
        global $wpdb;
        $wpdb->update($this->p()->grants_table, ['status' => 'revoked', 'updated_at' => current_time('mysql', true)], ['id' => $id]);
        $this->p()->log('grant_revoked', 'grant', $id);
        $this->redirect_back('zaas-grants', 'Доступ отозван.');
    }

    /* ------------------------------------------------------------------ *
     *  Devices / Passkeys
     * ------------------------------------------------------------------ */

    public function page_devices() {
        $this->require_cap();
        global $wpdb;
        $devices = $wpdb->get_results("SELECT * FROM {$this->p()->devices_table} WHERE revoked=0 ORDER BY last_seen_at DESC LIMIT 200");
        $passkeys = $wpdb->get_results("SELECT * FROM {$this->p()->passkeys_table} WHERE revoked=0 ORDER BY created_at DESC LIMIT 200");
        ?>
        <div class="wrap zaas-wrap">
            <h1>Устройства</h1>
            <table class="widefat striped">
                <thead><tr><th>Email</th><th>Устройство</th><th>Создано</th><th>Последний вход</th><th></th></tr></thead>
                <tbody>
                <?php if (!$devices): ?><tr><td colspan="5">Нет привязанных устройств.</td></tr><?php endif; ?>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?php echo esc_html($d->customer_email); ?></td>
                        <td><?php echo esc_html($d->device_name); ?></td>
                        <td><?php echo esc_html($d->created_at); ?></td>
                        <td><?php echo esc_html($d->last_seen_at); ?></td>
                        <td><a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_revoke_device&id=' . $d->id), ZAAS_Plugin::NONCE)); ?>">Отозвать</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h1>Passkeys</h1>
            <table class="widefat striped">
                <thead><tr><th>Email</th><th>Метка</th><th>Создан</th><th>Использован</th><th></th></tr></thead>
                <tbody>
                <?php if (!$passkeys): ?><tr><td colspan="5">Нет зарегистрированных passkey.</td></tr><?php endif; ?>
                <?php foreach ($passkeys as $k): ?>
                    <tr>
                        <td><?php echo esc_html($k->customer_email); ?></td>
                        <td><?php echo esc_html($k->label); ?></td>
                        <td><?php echo esc_html($k->created_at); ?></td>
                        <td><?php echo esc_html($k->last_used_at ?: '—'); ?></td>
                        <td><a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_revoke_passkey&id=' . $k->id), ZAAS_Plugin::NONCE)); ?>">Отозвать</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function revoke_device() {
        $this->require_cap();
        check_admin_referer(ZAAS_Plugin::NONCE);
        global $wpdb;
        $wpdb->update($this->p()->devices_table, ['revoked' => 1, 'revoked_at' => current_time('mysql', true), 'revoke_reason' => 'admin'], ['id' => absint($_GET['id'] ?? 0)]);
        $this->redirect_back('zaas-devices', 'Устройство отозвано.');
    }

    public function revoke_passkey() {
        $this->require_cap();
        check_admin_referer(ZAAS_Plugin::NONCE);
        global $wpdb;
        $wpdb->update($this->p()->passkeys_table, ['revoked' => 1], ['id' => absint($_GET['id'] ?? 0)]);
        $this->redirect_back('zaas-devices', 'Passkey отозван.');
    }

    /* ------------------------------------------------------------------ *
     *  Files
     * ------------------------------------------------------------------ */

    public function page_files() {
        $this->require_cap();
        $files = get_posts(['post_type' => ZAAS_Stream::CPT, 'numberposts' => 200, 'post_status' => 'publish']);
        ?>
        <div class="wrap zaas-wrap">
            <h1>Аудиофайлы</h1>
            <form class="zaas-card" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="zaas_upload_audio">
                <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>
                <label>Название<input type="text" name="title" required></label>
                <label>Товар WooCommerce (ID)<input type="number" name="product_id" min="0"></label>
                <label>Часть/глава<input type="text" name="part_title"></label>
                <label>Файл (mp3/m4a/wav/ogg)<input type="file" name="audio_file" accept=".mp3,.m4a,.wav,.ogg" required></label>
                <button type="submit" class="button button-primary">Загрузить и зашифровать</button>
            </form>

            <table class="widefat striped">
                <thead><tr><th>Название</th><th>Товар</th><th>Размер</th><th></th></tr></thead>
                <tbody>
                <?php if (!$files): ?><tr><td colspan="4">Файлов пока нет.</td></tr><?php endif; ?>
                <?php foreach ($files as $file):
                    $product_id = (int) get_post_meta($file->ID, '_zaas_product_id', true);
                    $size = (int) get_post_meta($file->ID, '_zaas_size', true); ?>
                    <tr>
                        <td><?php echo esc_html($file->post_title); ?> <code>[zaas_audio id="<?php echo (int) $file->ID; ?>"]</code></td>
                        <td><?php echo $product_id ? esc_html($product_id) : '—'; ?></td>
                        <td><?php echo esc_html(size_format($size)); ?></td>
                        <td><a class="button button-link-delete" onclick="return confirm('Удалить файл?');" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zaas_delete_audio&id=' . $file->ID), ZAAS_Plugin::NONCE)); ?>">Удалить</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Kaspi
     * ------------------------------------------------------------------ */

    public function page_kaspi() {
        $this->require_cap();
        $gateway_settings = get_option('woocommerce_zaas_kaspi_settings', []);
        $secret = $gateway_settings['secret_key'] ?? '';
        ?>
        <div class="wrap zaas-wrap">
            <h1>Kaspi</h1>
            <p>Основные настройки шлюза (название, QR, шаблон ссылки, секретный ключ) находятся в
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=zaas_kaspi')); ?>">WooCommerce → Настройки → Способы оплаты → Kaspi QR</a>.</p>
            <?php if ($secret): ?>
                <h2>Вебхук подтверждения</h2>
                <p>Для внешней автоматизации (amoCRM-сценарий, Make, Albato) используйте подписанные ссылки:</p>
                <p><code><?php echo esc_html(rest_url('zaas/v1/kaspi/approve') . '?order_id=123&signature=' . hash_hmac('sha256', 'approve|123', $secret)); ?></code></p>
                <p class="zaas-muted">Подпись = hash_hmac('sha256', "approve|{order_id}" или "reject|{order_id}", секретный ключ).</p>
            <?php else: ?>
                <p class="zaas-muted">Задайте «Секретный ключ вебхука» в настройках шлюза, чтобы получить ссылки подтверждения.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  amoCRM
     * ------------------------------------------------------------------ */

    public function page_amocrm() {
        $this->require_cap();
        $s = $this->p()->settings();
        $authorize_url = ZAAS_AmoCRM::instance()->authorize_url();
        ?>
        <div class="wrap zaas-wrap">
            <h1>amoCRM</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zaas-card">
                <input type="hidden" name="action" value="zaas_save_settings">
                <input type="hidden" name="section" value="amocrm">
                <input type="hidden" name="return_page" value="zaas-amocrm">
                <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>
                <label>Домен аккаунта amoCRM<input type="text" name="amo_account_domain" value="<?php echo esc_attr($s['amo_account_domain']); ?>" placeholder="example.amocrm.ru"></label>
                <label>Client ID<input type="text" name="amo_client_id" value="<?php echo esc_attr($s['amo_client_id']); ?>"></label>
                <label>Client Secret<input type="password" name="amo_client_secret" value="<?php echo esc_attr($s['amo_client_secret']); ?>"></label>
                <label>ID воронки<input type="text" name="amo_pipeline_id" value="<?php echo esc_attr($s['amo_pipeline_id']); ?>"></label>
                <label>ID статуса «Новый заказ»<input type="text" name="amo_new_status_id" value="<?php echo esc_attr($s['amo_new_status_id']); ?>"></label>
                <label>ID статуса «Подтверждено»<input type="text" name="amo_approved_status_id" value="<?php echo esc_attr($s['amo_approved_status_id']); ?>"></label>
                <label>Секрет вебхука<input type="text" name="amo_webhook_secret" value="<?php echo esc_attr($s['amo_webhook_secret']); ?>" placeholder="Оставьте пустым — сгенерируется автоматически"></label>
                <label><input type="checkbox" name="amo_auto_create_lead" <?php checked($s['amo_auto_create_lead']); ?>> Автоматически создавать сделку при оформлении заказа</label>
                <p class="description">Redirect URI: <code><?php echo esc_html($s['amo_redirect_uri']); ?></code></p>
                <p class="description">URL вебхука для настройки в amoCRM (Настройки → Интеграции → Webhooks): <code><?php echo esc_html(add_query_arg('secret', $s['amo_webhook_secret'] ?: '{secret}', rest_url('zaas/v1/amocrm-webhook'))); ?></code></p>
                <button type="submit" class="button button-primary">Сохранить</button>
            </form>

            <?php if ($authorize_url): ?>
                <h2>Авторизация</h2>
                <p>Статус: <?php echo !empty($s['amo_access_token']) ? '<span class="zaas-badge zaas-badge-active">подключено</span>' : '<span class="zaas-badge">не подключено</span>'; ?></p>
                <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url($authorize_url); ?>">Авторизовать amoCRM</a>
                <p class="description">После авторизации amoCRM вызовет ваш redirect URI с параметром <code>code</code> — обработайте его формой ниже, если постмессадж-виджет недоступен.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=zaas_amo_exchange_code')); ?>" class="zaas-card">
                    <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>
                    <label>Код авторизации<input type="text" name="code"></label>
                    <button type="submit" class="button">Обменять код на токен</button>
                </form>
            <?php else: ?>
                <p class="zaas-muted">Укажите домен и Client ID, чтобы получить ссылку авторизации.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Access / PIN settings
     * ------------------------------------------------------------------ */

    public function page_access_settings() {
        $this->require_cap();
        $s = $this->p()->settings();
        ?>
        <div class="wrap zaas-wrap">
            <h1>PIN и вход</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zaas-card">
                <input type="hidden" name="action" value="zaas_save_settings">
                <input type="hidden" name="section" value="access">
                <input type="hidden" name="return_page" value="zaas-access-settings">
                <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>

                <h2>Доступ и устройства</h2>
                <label>Срок доступа после активации, дней<input type="number" name="default_access_days" min="1" value="<?php echo (int) $s['default_access_days']; ?>"></label>
                <label>Срок действия ссылки-активации, часов<input type="number" name="default_activation_hours" min="1" value="<?php echo (int) $s['default_activation_hours']; ?>"></label>
                <label>Максимум устройств на клиента<input type="number" name="max_devices" min="1" value="<?php echo (int) $s['max_devices']; ?>"></label>
                <label>Срок жизни устройства, дней<input type="number" name="session_days" min="1" value="<?php echo (int) $s['session_days']; ?>"></label>
                <label>Одновременных потоков воспроизведения<input type="number" name="simultaneous_streams" min="1" value="<?php echo (int) $s['simultaneous_streams']; ?>"></label>

                <h2>Восстановление по email (OTP)</h2>
                <label>Срок действия кода, минут<input type="number" name="otp_minutes" min="1" value="<?php echo (int) $s['otp_minutes']; ?>"></label>
                <label>Максимум попыток<input type="number" name="otp_max_attempts" min="1" value="<?php echo (int) $s['otp_max_attempts']; ?>"></label>

                <h2>Постоянный PIN</h2>
                <label><input type="checkbox" name="pin_enabled" <?php checked($s['pin_enabled']); ?>> Разрешить вход по PIN</label>
                <label>Режим установки<select name="pin_setup_mode">
                    <option value="off" <?php selected($s['pin_setup_mode'], 'off'); ?>>Выключено</option>
                    <option value="optional" <?php selected($s['pin_setup_mode'], 'optional'); ?>>По желанию</option>
                    <option value="required" <?php selected($s['pin_setup_mode'], 'required'); ?>>Обязательно</option>
                </select></label>
                <label>Мин. длина PIN<input type="number" name="pin_min_length" min="4" max="10" value="<?php echo (int) $s['pin_min_length']; ?>"></label>
                <label>Макс. длина PIN<input type="number" name="pin_max_length" min="4" max="10" value="<?php echo (int) $s['pin_max_length']; ?>"></label>
                <label>Попыток до блокировки<input type="number" name="pin_max_attempts" min="1" value="<?php echo (int) $s['pin_max_attempts']; ?>"></label>
                <label>Блокировка, минут<input type="number" name="pin_lock_minutes" min="1" value="<?php echo (int) $s['pin_lock_minutes']; ?>"></label>
                <label><input type="checkbox" name="pin_device_only" <?php checked($s['pin_device_only']); ?>> Вход по PIN только на уже привязанном устройстве (без email)</label>

                <h2>Passkeys</h2>
                <label><input type="checkbox" name="passkeys_enabled" <?php checked($s['passkeys_enabled']); ?>> Разрешить вход по Passkey (требуется HTTPS)</label>
                <label><input type="checkbox" name="passkey_prompt_enabled" <?php checked($s['passkey_prompt_enabled']); ?>> Предлагать создать Passkey в кабинете</label>

                <button type="submit" class="button button-primary">Сохранить</button>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Styles (design tokens applied as CSS custom properties, same
     *  runtime-apply pattern as the reference plugin's aqniet-blue.js)
     * ------------------------------------------------------------------ */

    public function page_styles() {
        $this->require_cap();
        $s = $this->p()->settings();
        $pages = get_pages();
        ?>
        <div class="wrap zaas-wrap">
            <h1>Стили и виджеты</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zaas-card">
                <input type="hidden" name="action" value="zaas_save_settings">
                <input type="hidden" name="section" value="styles">
                <input type="hidden" name="return_page" value="zaas-styles">
                <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>
                <label><input type="checkbox" name="design_enabled" <?php checked($s['design_enabled']); ?>> Применять фирменные цвета на фронтенде</label>
                <label>Основной цвет<input type="color" name="design_accent" value="<?php echo esc_attr($s['design_accent']); ?>"></label>
                <label>Тёмный оттенок (hover/акценты)<input type="color" name="design_accent_dark" value="<?php echo esc_attr($s['design_accent_dark']); ?>"></label>
                <label>Фон карточек<input type="color" name="design_surface" value="<?php echo esc_attr($s['design_surface']); ?>"></label>
                <label>Цвет текста<input type="color" name="design_text" value="<?php echo esc_attr($s['design_text']); ?>"></label>
                <label>Радиус скругления, px<input type="number" name="design_radius" min="0" max="40" value="<?php echo (int) $s['design_radius']; ?>"></label>
                <button type="submit" class="button button-primary">Сохранить стили</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zaas-card">
                <input type="hidden" name="action" value="zaas_save_settings">
                <input type="hidden" name="section" value="library_page">
                <input type="hidden" name="return_page" value="zaas-styles">
                <?php wp_nonce_field(ZAAS_Plugin::NONCE); ?>
                <label>Страница «Мои аудиокниги»<select name="library_page_id">
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo (int) $page->ID; ?>" <?php selected((int) $s['library_page_id'], $page->ID); ?>><?php echo esc_html($page->post_title); ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button type="submit" class="button">Сохранить</button>
            </form>

            <h2>Elementor</h2>
            <p>В редакторе Elementor виджеты доступны в категории «ZAU Аудиодоступ»: Библиотека, Защищённый блок, Аудиоплеер, Кнопка покупки, Оплата Kaspi, Вход/PIN. На вкладке «Стиль» каждого виджета можно переопределить цвета, отступы, радиус и типографику только для этого блока — глобальные значения выше остаются основой (см. assets/css/theme.css).</p>
            <h2>Шорткоды (если Elementor не используется)</h2>
            <ul class="zaas-shortcode-list">
                <li><code>[zaas_library]</code> — личный кабинет / список книг</li>
                <li><code>[zaas_auth]</code> — форма входа (ссылка на email + PIN)</li>
                <li><code>[zaas_audio id="123"]</code> — плеер для конкретного файла</li>
                <li><code>[zaas_protected product_id="45"]...[/zaas_protected]</code> — блок, видимый только владельцам продукта</li>
                <li><code>[zaas_buy_button product_id="45"]</code> — кнопка покупки</li>
            </ul>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Import
     * ------------------------------------------------------------------ */

    public function page_import() {
        $this->require_cap();
        ?>
        <div class="wrap zaas-wrap" id="zaas-import-app">
            <h1>Перенос старых данных</h1>
            <p>Найдёт данные старых плагинов (WC Secure Audio Access, ZAU temp-access, Woo Kaspi QR amoCRM Access) и скопирует их в единую таблицу грантов этого плагина. Уже перенесённые записи не дублируются при повторном запуске.</p>
            <button type="button" class="button button-primary" id="zaas-import-scan">Проверить источники</button>
            <div id="zaas-import-sources"></div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Log
     * ------------------------------------------------------------------ */

    public function page_log() {
        $this->require_cap();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->p()->log_table} ORDER BY id DESC LIMIT 200");
        ?>
        <div class="wrap zaas-wrap">
            <h1>Журнал</h1>
            <table class="widefat striped">
                <thead><tr><th>Время</th><th>Действие</th><th>Объект</th><th>Детали</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html($row->action); ?></td>
                        <td><?php echo esc_html($row->object_type . ($row->object_id ? ' #' . $row->object_id : '')); ?></td>
                        <td><?php echo esc_html($row->details); ?></td>
                        <td><?php echo esc_html($row->ip); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
