/**
 * Front-end behaviour for the auth panel ([zaas_auth] / [zaas_library]
 * when logged out): tab switching, email-link recovery (request+verify
 * OTP), PIN login/set/forgot-reset, passkey enroll/login, and device
 * logout. All requests go through admin-ajax.php using ZAASData (see
 * ZAAS_Access::public_assets()).
 */
(function () {
    'use strict';
    if (typeof window.ZAASData === 'undefined') { return; }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', window.ZAASData.nonce);
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
        return fetch(window.ZAASData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    function showMessage(form, text, isError) {
        var el = form.querySelector('[data-zaas-message]');
        if (!el) { return; }
        el.textContent = text || '';
        el.classList.toggle('is-error', !!isError);
        el.classList.toggle('is-success', !isError && !!text);
    }

    function bindTabs(root) {
        var tabs = root.querySelectorAll('[data-zaas-tab]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                root.querySelectorAll('[data-zaas-panel]').forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.getAttribute('data-zaas-panel') === tab.getAttribute('data-zaas-tab'));
                });
            });
        });
    }

    function bindRecoveryForm(form) {
        var otpStep = form.querySelector('[data-zaas-otp-step]');
        var codeRequested = false;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var email = form.email.value.trim();
            if (!email) { return; }

            if (!codeRequested) {
                showMessage(form, 'Отправляем код…', false);
                post('zaas_request_recovery', { email: email }).then(function (res) {
                    var body = res.data || {};
                    showMessage(form, body.message || '', !res.success);
                    if (res.success) { codeRequested = true; if (otpStep) { otpStep.hidden = false; } }
                });
                return;
            }

            var code = form.code ? form.code.value.trim() : '';
            showMessage(form, 'Проверяем код…', false);
            post('zaas_verify_recovery', { email: email, code: code }).then(function (res) {
                var body = res.data || {};
                showMessage(form, body.message || '', !res.success);
                if (res.success && body.redirect) { window.location.href = body.redirect; }
            });
        });
    }

    function bindPasswordLoginForm(form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showMessage(form, 'Входим…', false);
            post('zaas_password_login', { login: form.login.value.trim(), password: form.password.value }).then(function (res) {
                var body = res.data || {};
                showMessage(form, body.message || '', !res.success);
                if (res.success && body.redirect) { window.location.href = body.redirect; }
            });
        });
    }

    function bindPinLoginForm(form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var identity = form.identity ? form.identity.value.trim() : '';
            var pin = form.pin.value.trim();
            showMessage(form, 'Входим…', false);
            post('zaas_pin_login', { identity: identity, pin: pin }).then(function (res) {
                var body = res.data || {};
                showMessage(form, body.message || '', !res.success);
                if (res.success && body.redirect) { window.location.href = body.redirect; }
            });
        });

        var forgotBtn = form.querySelector('[data-zaas-pin-forgot]');
        if (forgotBtn) {
            forgotBtn.addEventListener('click', function () {
                form.hidden = true;
                var resetForm = form.parentElement.querySelector('[data-zaas-pin-reset-form]');
                if (resetForm) { resetForm.hidden = false; }
            });
        }
    }

    function bindPinResetForm(form) {
        var sendBtn = form.querySelector('[data-zaas-pin-send-code]');
        var otpStep = form.querySelector('[data-zaas-otp-step]');

        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                var email = form.email.value.trim();
                if (!email) { return; }
                showMessage(form, 'Отправляем код…', false);
                post('zaas_pin_request_reset', { email: email }).then(function (res) {
                    var body = res.data || {};
                    showMessage(form, body.message || '', !res.success);
                    if (res.success && otpStep) { otpStep.hidden = false; }
                });
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var email = form.email.value.trim();
            var code = form.code ? form.code.value.trim() : '';
            var newPin = form.new_pin ? form.new_pin.value.trim() : '';
            showMessage(form, 'Сохраняем новый PIN…', false);
            post('zaas_pin_reset', { email: email, code: code, new_pin: newPin }).then(function (res) {
                var body = res.data || {};
                showMessage(form, body.message || '', !res.success);
                if (res.success && body.redirect) { window.location.href = body.redirect; }
            });
        });
    }

    function bindLogout(root) {
        var btn = root.querySelector('[data-zaas-logout]');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            post('zaas_logout', {}).then(function (res) {
                var body = res.data || {};
                window.location.href = body.redirect || '/';
            });
        });
    }

    function base64urlToBuffer(value) {
        var padded = value.replace(/-/g, '+').replace(/_/g, '/');
        while (padded.length % 4) { padded += '='; }
        var raw = window.atob(padded);
        var buffer = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { buffer[i] = raw.charCodeAt(i); }
        return buffer.buffer;
    }

    function bufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var str = '';
        for (var i = 0; i < bytes.byteLength; i++) { str += String.fromCharCode(bytes[i]); }
        return window.btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function bindPasskeyLogin(root) {
        var btn = root.querySelector('[data-zaas-passkey-login]');
        if (!btn || !window.PublicKeyCredential) { return; }
        btn.addEventListener('click', function () {
            post('zaas_passkey_login_options', {}).then(function (res) {
                if (!res.success) { return; }
                var options = res.data.publicKey;
                var loginToken = res.data.loginToken;
                options.challenge = base64urlToBuffer(options.challenge);
                (options.allowCredentials || []).forEach(function (c) { c.id = base64urlToBuffer(c.id); });
                navigator.credentials.get({ publicKey: options }).then(function (assertion) {
                    var response = {
                        rawId: bufferToBase64url(assertion.rawId),
                        clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
                        authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                        signature: bufferToBase64url(assertion.response.signature),
                    };
                    return post('zaas_passkey_login_finish', { login_token: loginToken, response: JSON.stringify(response) });
                }).then(function (finishRes) {
                    if (finishRes.success && finishRes.data.redirect) { window.location.href = finishRes.data.redirect; }
                });
            });
        });
    }

    function bindPasskeyEnroll(root) {
        var btn = root.querySelector('[data-zaas-passkey-enroll]');
        if (!btn || !window.PublicKeyCredential) { return; }
        btn.addEventListener('click', function () {
            post('zaas_passkey_register_options', {}).then(function (res) {
                if (!res.success) { window.alert(res.data && res.data.message ? res.data.message : 'Ошибка'); return; }
                var options = res.data.publicKey;
                options.challenge = base64urlToBuffer(options.challenge);
                options.user.id = base64urlToBuffer(options.user.id);
                (options.excludeCredentials || []).forEach(function (c) { c.id = base64urlToBuffer(c.id); });
                navigator.credentials.create({ publicKey: options }).then(function (credential) {
                    var response = {
                        rawId: bufferToBase64url(credential.rawId),
                        clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64url(credential.response.attestationObject),
                    };
                    return post('zaas_passkey_register_finish', { response: JSON.stringify(response), label: 'Passkey' });
                }).then(function (finishRes) {
                    window.alert(finishRes.success ? 'Passkey добавлен.' : ((finishRes.data && finishRes.data.message) || 'Не удалось добавить Passkey.'));
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-zaas-auth]').forEach(bindTabs);
        document.querySelectorAll('[data-zaas-recovery-form]').forEach(bindRecoveryForm);
        document.querySelectorAll('[data-zaas-password-form]').forEach(bindPasswordLoginForm);
        document.querySelectorAll('[data-zaas-pin-login-form]').forEach(bindPinLoginForm);
        document.querySelectorAll('[data-zaas-pin-reset-form]').forEach(bindPinResetForm);
        document.querySelectorAll('.zaas-library, .zaas-interface').forEach(bindLogout);
        document.querySelectorAll('.zaas-interface').forEach(bindPasskeyLogin);
        document.querySelectorAll('.zaas-interface').forEach(bindPasskeyEnroll);
    });
})();
