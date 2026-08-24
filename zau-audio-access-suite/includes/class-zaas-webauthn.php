<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Standalone WebAuthn (passkey) crypto helper — ES256/P-256 registration
 * and assertion verification, including CBOR/COSE decoding and COSE->PEM
 * conversion. No WordPress DB access here on purpose: this class is pure
 * cryptography so it can be reasoned about (and audited) in isolation from
 * the storage/glue code in ZAAS_WebAuthn below.
 */
class ZAAS_WebAuthn_Crypto {
    public static function rp_id() {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return strtolower((string) $host);
    }

    public static function origin() {
        $parts  = wp_parse_url(home_url('/'));
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : (is_ssl() ? 'https' : 'http');
        $host   = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port   = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        return $scheme . '://' . $host . $port;
    }

    public static function random_challenge() {
        try {
            return self::base64url_encode(random_bytes(32));
        } catch (Exception $e) {
            return '';
        }
    }

    public static function user_handle($email) {
        return self::base64url_encode(hash('sha256', strtolower(trim($email)), true));
    }

    public static function registration_options($email, $display_name, $exclude_ids = []) {
        $challenge = self::random_challenge();
        if (!$challenge) { return new WP_Error('challenge', 'Не удалось создать криптографический запрос.'); }

        $exclude = [];
        foreach ($exclude_ids as $credential_id) {
            $exclude[] = ['type' => 'public-key', 'id' => (string) $credential_id];
        }

        return [
            'challenge' => $challenge,
            'publicKey' => [
                'challenge' => $challenge,
                'rp'        => ['name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'id' => self::rp_id()],
                'user'      => ['id' => self::user_handle($email), 'name' => strtolower($email), 'displayName' => $display_name ?: strtolower($email)],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
                'timeout'   => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
                'excludeCredentials' => $exclude,
            ],
        ];
    }

    public static function login_options($credential_ids) {
        $challenge = self::random_challenge();
        if (!$challenge) { return new WP_Error('challenge', 'Не удалось создать криптографический запрос.'); }

        $allow = [];
        foreach ($credential_ids as $credential_id) {
            $allow[] = ['type' => 'public-key', 'id' => (string) $credential_id];
        }

        return [
            'challenge' => $challenge,
            'publicKey' => [
                'challenge' => $challenge, 'rpId' => self::rp_id(), 'allowCredentials' => $allow,
                'userVerification' => 'required', 'timeout' => 60000,
            ],
        ];
    }

    public static function verify_registration($response, $expected_challenge) {
        $client_data_json = self::base64url_decode($response['clientDataJSON'] ?? '');
        $attestation       = self::base64url_decode($response['attestationObject'] ?? '');
        $raw_id            = isset($response['rawId']) ? sanitize_text_field($response['rawId']) : '';

        if (false === $client_data_json || false === $attestation || !$raw_id) {
            return new WP_Error('invalid_response', 'Некорректный ответ ключа доступа.');
        }

        $client = json_decode($client_data_json, true);
        $check  = self::verify_client_data($client, 'webauthn.create', $expected_challenge);
        if (is_wp_error($check)) { return $check; }

        $offset = 0;
        $decoded = self::cbor_decode_item($attestation, $offset);
        if (!is_array($decoded) || empty($decoded['authData'])) {
            return new WP_Error('attestation', 'Не удалось прочитать данные регистрации passkey.');
        }

        $auth = self::parse_authenticator_data($decoded['authData'], true);
        if (is_wp_error($auth)) { return $auth; }

        if (!hash_equals(self::base64url_encode($auth['credential_id']), $raw_id)) {
            return new WP_Error('credential_mismatch', 'Идентификатор passkey не совпал.');
        }

        $pem = self::cose_ec2_to_pem($auth['credential_public_key']);
        if (is_wp_error($pem)) { return $pem; }

        return ['credential_id' => $raw_id, 'public_key' => $pem, 'sign_count' => $auth['sign_count']];
    }

    public static function verify_assertion($response, $expected_challenge, $public_key, $stored_sign_count = 0) {
        $client_data_json = self::base64url_decode($response['clientDataJSON'] ?? '');
        $auth_data        = self::base64url_decode($response['authenticatorData'] ?? '');
        $signature        = self::base64url_decode($response['signature'] ?? '');

        if (false === $client_data_json || false === $auth_data || false === $signature) {
            return new WP_Error('invalid_response', 'Некорректный ответ passkey.');
        }

        $client = json_decode($client_data_json, true);
        $check  = self::verify_client_data($client, 'webauthn.get', $expected_challenge);
        if (is_wp_error($check)) { return $check; }

        $auth = self::parse_authenticator_data($auth_data, false);
        if (is_wp_error($auth)) { return $auth; }

        if (!function_exists('openssl_verify')) { return new WP_Error('openssl', 'На сервере отсутствует расширение PHP OpenSSL.'); }
        $signed_data = $auth_data . hash('sha256', $client_data_json, true);
        $verified = openssl_verify($signed_data, $signature, $public_key, OPENSSL_ALGO_SHA256);
        if (1 !== $verified) { return new WP_Error('signature', 'Криптографическая подпись passkey не прошла проверку.'); }

        $new_count = (int) $auth['sign_count'];
        $old_count = (int) $stored_sign_count;
        if ($old_count > 0 && $new_count > 0 && $new_count <= $old_count) {
            return new WP_Error('counter', 'Счётчик passkey не увеличился. Возможна копия ключа.');
        }
        return ['sign_count' => $new_count];
    }

    private static function verify_client_data($client, $expected_type, $expected_challenge) {
        if (!is_array($client)) { return new WP_Error('client_data', 'Не удалось прочитать clientDataJSON.'); }
        if (empty($client['type']) || !hash_equals($expected_type, (string) $client['type'])) {
            return new WP_Error('client_type', 'Неверный тип WebAuthn-запроса.');
        }
        if (empty($client['challenge']) || !hash_equals((string) $expected_challenge, (string) $client['challenge'])) {
            return new WP_Error('challenge', 'Срок запроса истёк или challenge не совпал.');
        }
        if (empty($client['origin']) || !hash_equals(self::origin(), strtolower(rtrim((string) $client['origin'], '/')))) {
            return new WP_Error('origin', 'WebAuthn origin не совпал с адресом сайта.');
        }
        if (!empty($client['crossOrigin'])) { return new WP_Error('cross_origin', 'Кросс-доменная авторизация запрещена.'); }
        return true;
    }

    private static function parse_authenticator_data($auth_data, $require_attested) {
        if (strlen($auth_data) < 37) { return new WP_Error('auth_data', 'Authenticator data слишком короткие.'); }

        $rp_hash    = substr($auth_data, 0, 32);
        $flags      = ord($auth_data[32]);
        $count_data = unpack('Ncount', substr($auth_data, 33, 4));
        $sign_count = isset($count_data['count']) ? (int) $count_data['count'] : 0;

        if (!hash_equals(hash('sha256', self::rp_id(), true), $rp_hash)) {
            return new WP_Error('rp_id', 'RP ID passkey не совпал с доменом.');
        }
        if (0 === ($flags & 0x01)) { return new WP_Error('user_presence', 'Не подтверждено присутствие пользователя.'); }
        if (0 === ($flags & 0x04)) { return new WP_Error('user_verification', 'Не выполнена проверка пользователя через PIN, биометрию или системную защиту.'); }

        $result = ['flags' => $flags, 'sign_count' => $sign_count];

        if ($require_attested) {
            if (0 === ($flags & 0x40)) { return new WP_Error('attested_data', 'В ответе нет данных нового credential.'); }
            if (strlen($auth_data) < 55) { return new WP_Error('attested_data', 'Данные credential повреждены.'); }

            $offset = 37 + 16;
            $length_data = unpack('nlength', substr($auth_data, $offset, 2));
            $credential_length = isset($length_data['length']) ? (int) $length_data['length'] : 0;
            $offset += 2;
            if ($credential_length < 1 || strlen($auth_data) < $offset + $credential_length) {
                return new WP_Error('credential_length', 'Неверная длина credential ID.');
            }

            $credential_id = substr($auth_data, $offset, $credential_length);
            $offset += $credential_length;
            $cbor_offset = $offset;
            $public_key = self::cbor_decode_item($auth_data, $cbor_offset);
            if (!is_array($public_key)) { return new WP_Error('public_key', 'Не удалось прочитать публичный ключ passkey.'); }

            $result['credential_id'] = $credential_id;
            $result['credential_public_key'] = $public_key;
        }

        return $result;
    }

    private static function cose_ec2_to_pem($cose) {
        $kty = isset($cose[1]) ? (int) $cose[1] : 0;
        $alg = isset($cose[3]) ? (int) $cose[3] : 0;
        $crv = isset($cose[-1]) ? (int) $cose[-1] : 0;
        $x   = $cose[-2] ?? '';
        $y   = $cose[-3] ?? '';

        if (2 !== $kty || -7 !== $alg || 1 !== $crv || 32 !== strlen($x) || 32 !== strlen($y)) {
            return new WP_Error('unsupported_key', 'Поддерживаются passkey с алгоритмом ES256 (P-256).');
        }

        $ec_public_key_oid = hex2bin('06072A8648CE3D0201');
        $prime256v1_oid    = hex2bin('06082A8648CE3D030107');
        $algorithm  = self::der_sequence($ec_public_key_oid . $prime256v1_oid);
        $point      = "\x04" . $x . $y;
        $bit_string = "\x03" . self::der_length(strlen($point) + 1) . "\x00" . $point;
        $der        = self::der_sequence($algorithm . $bit_string);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der_sequence($content) { return "\x30" . self::der_length(strlen($content)) . $content; }

    private static function der_length($length) {
        if ($length < 128) { return chr($length); }
        $temp = '';
        while ($length > 0) { $temp = chr($length & 0xff) . $temp; $length >>= 8; }
        return chr(0x80 | strlen($temp)) . $temp;
    }

    private static function cbor_decode_item($data, &$offset) {
        $length = strlen($data);
        if ($offset >= $length) { return null; }

        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info  = $initial & 0x1f;
        $value = self::cbor_read_length($data, $offset, $info);
        if (is_wp_error($value)) { return null; }

        switch ($major) {
            case 0: return $value;
            case 1: return -1 - $value;
            case 2:
                if ($offset + $value > $length) { return null; }
                $bytes = substr($data, $offset, $value); $offset += $value; return $bytes;
            case 3:
                if ($offset + $value > $length) { return null; }
                $text = substr($data, $offset, $value); $offset += $value; return $text;
            case 4:
                $array = [];
                for ($i = 0; $i < $value; $i++) { $array[] = self::cbor_decode_item($data, $offset); }
                return $array;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::cbor_decode_item($data, $offset);
                    $map[$key] = self::cbor_decode_item($data, $offset);
                }
                return $map;
            case 6: return self::cbor_decode_item($data, $offset);
            case 7:
                if (20 === $info) { return false; }
                if (21 === $info) { return true; }
                if (22 === $info || 23 === $info) { return null; }
                return $value;
        }
        return null;
    }

    private static function cbor_read_length($data, &$offset, $info) {
        $length = strlen($data);
        if ($info < 24) { return $info; }
        if (24 === $info) {
            if ($offset + 1 > $length) { return new WP_Error('cbor'); }
            return ord($data[$offset++]);
        }
        if (25 === $info) {
            if ($offset + 2 > $length) { return new WP_Error('cbor'); }
            $out = unpack('nvalue', substr($data, $offset, 2)); $offset += 2; return (int) $out['value'];
        }
        if (26 === $info) {
            if ($offset + 4 > $length) { return new WP_Error('cbor'); }
            $out = unpack('Nvalue', substr($data, $offset, 4)); $offset += 4; return (int) $out['value'];
        }
        if (27 === $info) {
            if ($offset + 8 > $length) { return new WP_Error('cbor'); }
            $parts = unpack('Nhigh/Nlow', substr($data, $offset, 8)); $offset += 8;
            return (int) ($parts['high'] * 4294967296 + $parts['low']);
        }
        return new WP_Error('cbor_indefinite');
    }

    public static function base64url_encode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

    public static function base64url_decode($data) {
        if (!is_string($data) || '' === $data) { return false; }
        $remainder = strlen($data) % 4;
        if ($remainder) { $data .= str_repeat('=', 4 - $remainder); }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

/**
 * Storage + AJAX glue on top of ZAAS_WebAuthn_Crypto: registers/verifies
 * passkeys against the canonical customer email identity and, on
 * successful login, binds the device exactly like PIN/OTP login do.
 */
final class ZAAS_WebAuthn {

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        foreach (['zaas_passkey_register_options', 'zaas_passkey_register_finish', 'zaas_passkey_login_options', 'zaas_passkey_login_finish'] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    private function p() { return ZAAS_Plugin::instance(); }
    private function table() { return $this->p()->passkeys_table; }

    public function is_available() {
        $settings = $this->p()->settings();
        return !empty($settings['passkeys_enabled']) && is_ssl() && function_exists('openssl_verify');
    }

    public function credential_ids_for_email($email) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT credential_id FROM {$this->table()} WHERE customer_email=%s AND revoked=0", sanitize_email($email)
        ));
    }

    public function ajax_zaas_passkey_register_options() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        if (!$this->is_available()) { wp_send_json_error(['message' => 'Passkey недоступны на этом сайте (нужен HTTPS).'], 400); }
        $email = ZAAS_Access::instance()->resolve_customer_email();
        if (!$email) { wp_send_json_error(['message' => 'Сначала выполните вход.'], 401); }

        $options = ZAAS_WebAuthn_Crypto::registration_options($email, $email, $this->credential_ids_for_email($email));
        if (is_wp_error($options)) { wp_send_json_error(['message' => $options->get_error_message()], 500); }

        set_transient('zaas_passkey_reg_' . md5($email), $options['challenge'], 5 * MINUTE_IN_SECONDS);
        wp_send_json_success($options);
    }

    public function ajax_zaas_passkey_register_finish() {
        check_ajax_referer(ZAAS_Plugin::NONCE, 'nonce');
        $email = ZAAS_Access::instance()->resolve_customer_email();
        if (!$email) { wp_send_json_error(['message' => 'Сначала выполните вход.'], 401); }

        $challenge = get_transient('zaas_passkey_reg_' . md5($email));
        if (!$challenge) { wp_send_json_error(['message' => 'Время запроса истекло, начните заново.'], 400); }

        $response = json_decode(wp_unslash($_POST['response'] ?? '{}'), true);
        $result = ZAAS_WebAuthn_Crypto::verify_registration((array) $response, $challenge);
        delete_transient('zaas_passkey_reg_' . md5($email));
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }

        global $wpdb;
        $wpdb->insert($this->table(), [
            'customer_email'  => $email,
            'credential_hash' => hash('sha256', $result['credential_id']),
            'credential_id'   => $result['credential_id'],
            'public_key'      => $result['public_key'],
            'sign_count'      => (int) $result['sign_count'],
            'label'           => sanitize_text_field($_POST['label'] ?? 'Passkey'),
            'created_at'      => current_time('mysql', true),
        ]);
        $this->p()->log('passkey_registered', 'passkey', 0, $email);
        wp_send_json_success(['message' => 'Passkey добавлен.']);
    }

    public function ajax_zaas_passkey_login_options() {
        if (!$this->is_available()) { wp_send_json_error(['message' => 'Passkey недоступны.'], 400); }
        $options = ZAAS_WebAuthn_Crypto::login_options([]);
        if (is_wp_error($options)) { wp_send_json_error(['message' => $options->get_error_message()], 500); }
        $token = wp_generate_password(20, false);
        set_transient('zaas_passkey_login_tok_' . $token, $options['challenge'], 5 * MINUTE_IN_SECONDS);
        $options['loginToken'] = $token;
        wp_send_json_success($options);
    }

    public function ajax_zaas_passkey_login_finish() {
        $token = sanitize_text_field($_POST['login_token'] ?? '');
        $challenge = $token ? get_transient('zaas_passkey_login_tok_' . $token) : false;
        if (!$challenge) { wp_send_json_error(['message' => 'Время запроса истекло, начните заново.'], 400); }

        $response = json_decode(wp_unslash($_POST['response'] ?? '{}'), true);
        $raw_id = isset($response['rawId']) ? sanitize_text_field($response['rawId']) : '';
        if (!$raw_id) { wp_send_json_error(['message' => 'Некорректный ответ passkey.'], 400); }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE credential_hash=%s AND revoked=0", hash('sha256', $raw_id)
        ));
        if (!$row) { wp_send_json_error(['message' => 'Passkey не найден.'], 404); }

        $result = ZAAS_WebAuthn_Crypto::verify_assertion((array) $response, $challenge, $row->public_key, (int) $row->sign_count);
        delete_transient('zaas_passkey_login_tok_' . $token);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }

        $wpdb->update($this->table(), ['sign_count' => (int) $result['sign_count'], 'last_used_at' => current_time('mysql', true)], ['id' => $row->id]);

        ZAAS_Access::instance()->bind_device($row->customer_email);
        $this->p()->log('passkey_login', 'passkey', $row->id, $row->customer_email);

        $settings = $this->p()->settings();
        $library = absint($settings['library_page_id']);
        wp_send_json_success(['message' => 'Вход выполнен.', 'redirect' => $library ? get_permalink($library) : home_url('/')]);
    }
}
