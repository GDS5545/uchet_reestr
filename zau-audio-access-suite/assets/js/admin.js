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

    /**
     * Resumable chunked upload for the "Файлы" screen: slices the file in
     * the browser (File.slice), uploads 5MB pieces sequentially, and lets
     * the server encrypt+append each piece as it arrives (see
     * ZAAS_Stream::ajax_zaas_chunk_upload_append) instead of one big
     * multipart POST — avoids PHP upload_max_filesize/memory limits on
     * large audiobook files.
     */
    function bindChunkedUpload() {
        var form = document.getElementById('zaas-upload-form');
        if (!form || typeof File === 'undefined' || !File.prototype.slice) { return; }
        var fileInput = form.querySelector('input[type="file"]');
        var progressWrap = document.getElementById('zaas-upload-progress');
        var progressBar = progressWrap ? progressWrap.querySelector('progress') : null;
        var progressLabel = progressWrap ? progressWrap.querySelector('span') : null;
        var submitBtn = form.querySelector('button[type="submit"]');

        function setProgress(receivedBytes, totalBytes) {
            if (!progressWrap) { return; }
            progressWrap.hidden = false;
            var pct = totalBytes ? Math.round((receivedBytes / totalBytes) * 100) : 0;
            if (progressBar) { progressBar.value = pct; }
            if (progressLabel) { progressLabel.textContent = pct + '%'; }
        }

        form.addEventListener('submit', function (e) {
            var file = fileInput && fileInput.files ? fileInput.files[0] : null;
            if (!file) { return; } // let the normal form submit run (browser will show "required")
            e.preventDefault();
            submitBtn.disabled = true;

            post('zaas_chunk_upload_start', { filename: file.name, total_size: file.size, title: form.title.value }).then(function (res) {
                if (!res.success) { window.alert((res.data && res.data.message) || 'Не удалось начать загрузку.'); submitBtn.disabled = false; return; }
                var audioId = res.data.audio_id;
                var chunkSize = res.data.chunk_size || (5 * 1024 * 1024);
                var offset = 0;

                function uploadNextChunk() {
                    if (offset >= file.size) { return finish(); }
                    var slice = file.slice(offset, offset + chunkSize);
                    var body = new FormData();
                    body.append('action', 'zaas_chunk_upload_append');
                    body.append('nonce', window.ZAASAdmin.nonce);
                    body.append('audio_id', audioId);
                    body.append('offset', offset);
                    body.append('chunk', slice, file.name);
                    fetch(window.ZAASAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (chunkRes) {
                            if (!chunkRes.success) {
                                window.alert((chunkRes.data && chunkRes.data.message) || 'Ошибка загрузки части файла.');
                                post('zaas_chunk_upload_cancel', { audio_id: audioId });
                                submitBtn.disabled = false;
                                return;
                            }
                            offset = chunkRes.data.received;
                            setProgress(offset, file.size);
                            uploadNextChunk();
                        });
                }

                function finish() {
                    post('zaas_chunk_upload_finish', {
                        audio_id: audioId, product_id: form.product_id.value, part_title: form.part_title.value,
                        author: form.author.value, cover_url: form.cover_url.value,
                    }).then(function (finishRes) {
                        submitBtn.disabled = false;
                        if (!finishRes.success) { window.alert((finishRes.data && finishRes.data.message) || 'Не удалось завершить загрузку.'); return; }
                        window.location.reload();
                    });
                }

                uploadNextChunk();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindChunkedUpload();

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
