/**
 * Drives the "Перенос старых данных" (legacy import) screen: scans which
 * legacy sources are present, then runs each in paged AJAX batches so a
 * large site doesn't time out a single request.
 */
(function () {
    'use strict';
    if (typeof window.ZAASAdmin === 'undefined') { return; }

    var SOURCE_LABELS = { wcsaa: 'WC Secure Audio Access', zau_temp: 'ZAU temp-access (ядро/мост/ZSPA)', wkqaa: 'Woo Kaspi QR amoCRM Access' };

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', window.ZAASAdmin.nonce);
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
        return fetch(window.ZAASAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
    }

    function runImport(source, container, countEl) {
        var offset = 0;
        var totalCreated = 0;

        function step() {
            post('zaas_legacy_import_batch', { source: source, offset: offset }).then(function (res) {
                if (!res.success) { countEl.textContent = 'Ошибка: ' + (res.data && res.data.message ? res.data.message : 'неизвестная'); return; }
                var body = res.data;
                totalCreated += body.created;
                offset += body.processed;
                countEl.textContent = 'Перенесено: ' + totalCreated + (body.processed ? ' (обработано ' + offset + ')' : '');
                if (!body.done && body.processed > 0) { step(); } else { countEl.textContent += ' — готово.'; }
            });
        }
        step();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var scanBtn = document.getElementById('zaas-import-scan');
        var container = document.getElementById('zaas-import-sources');
        if (!scanBtn || !container) { return; }

        scanBtn.addEventListener('click', function () {
            container.innerHTML = 'Проверяем…';
            post('zaas_legacy_scan', {}).then(function (res) {
                if (!res.success) { container.textContent = 'Ошибка проверки.'; return; }
                container.innerHTML = '';
                var found = false;
                Object.keys(res.data.available).forEach(function (source) {
                    if (!res.data.available[source]) { return; }
                    found = true;
                    var row = document.createElement('div');
                    row.className = 'zaas-import-row';
                    var label = document.createElement('strong');
                    label.textContent = SOURCE_LABELS[source] + ' (' + (res.data.counts[source] || 0) + ')';
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'button button-primary'; btn.textContent = 'Перенести';
                    var status = document.createElement('span');
                    row.appendChild(label); row.appendChild(btn); row.appendChild(status);
                    container.appendChild(row);
                    btn.addEventListener('click', function () { btn.disabled = true; runImport(source, container, status); });
                });
                if (!found) { container.textContent = 'Данные старых плагинов не найдены — переносить нечего.'; }
            });
        });
    });
})();
