(function ($) {
    'use strict';

    const App = window.ZAUCertData || {};
    const defs = App.fieldDefinitions || {};
    const fieldTypes = App.fieldTypes || {};
    const samples = {
        full_name: 'Иванов Иван Иванович',
        document_title: 'Сертификат участника / название мероприятия',
        organization: 'Название организации',
        issue_date: '24.07.2026',
        document_no: 'ZAU-2026-000001',
        member_status: 'Состоит в профсоюзе',
        extra1: 'Дополнительная информация 1',
        extra2: 'Дополнительная информация 2',
        extra3: 'Дополнительная информация 3',
        extra4: 'Дополнительная информация 4',
        signature_url: 'Подпись участника',
        signature2_url: 'Вторая подпись',
        stamp_url: 'Печать / штамп',
        verify_url: 'https://site.kz/proverka-dokumenta/?zau_verify=...',
        union_requisites: 'ОО «Название организации»\nРНН – ...\nИИК – ...\nБИК – ...\nБИН – ...\nБанк ...\nАдрес: ...\ne-mail: ...',
        qr: 'QR'
    };

    const clamp = (value, min, max) => Math.max(min, Math.min(max, Number(value)));
    const parseDecimal = (value, fallback = 0) => { const n = parseFloat(String(value ?? '').trim().replace(',', '.')); return Number.isFinite(n) ? n : fallback; };
    const formatDecimal = value => { const n = parseDecimal(value, 0); return String(Math.round(n * 100) / 100); };
    const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const getTemplate = id => (App.templates || []).find(t => Number(t.id) === Number(id));
    const imageKeys = ['signature_url', 'signature2_url', 'stamp_url'];
    const isImageKey = key => imageKeys.includes(key) || fieldTypes[key] === 'image';

    function initTemplateEditor() {
        const root = document.getElementById('zau-template-editor');
        if (!root) return;

        const form = document.getElementById('zau-template-form');
        const stage = document.getElementById('zau-stage');
        const stageFields = document.getElementById('zau-stage-fields');
        const bg = document.getElementById('zau-stage-bg');
        const fieldList = document.getElementById('zau-field-list');
        const fieldSearch = document.getElementById('zau-field-search');
        const bulkBar = document.getElementById('zau-field-bulk-bar');
        const bulkCount = document.getElementById('zau-field-bulk-count');
        const bulkAddBtn = document.getElementById('zau-field-bulk-add');
        const bulkClearBtn = document.getElementById('zau-field-bulk-clear');
        const bulkSelected = new Set();
        const fieldPanel = document.getElementById('zau-field-panel');
        const hidden = document.getElementById('zau-fields-json');
        const orientation = document.getElementById('zau-orientation');
        const bgMode = document.getElementById('zau-background-mode');
        const bgUrl = document.getElementById('zau-background-url');
        const bgId = document.getElementById('zau-background-id');
        const stageScroll = document.getElementById('zau-stage-scroll');
        const stageStatus = document.getElementById('zau-stage-size-status');
        const stageWarning = document.getElementById('zau-stage-warning');
        const stageFit = document.getElementById('zau-stage-fit');
        const stageZoomOut = document.getElementById('zau-stage-zoom-out');
        const stageZoomIn = document.getElementById('zau-stage-zoom-in');
        const stageGrid = document.getElementById('zau-stage-grid');
        const saveStatus = document.getElementById('zau-template-save-status');
        let fields = {};
        let selectedKey = 'full_name';
        let stageZoom = 1;
        let dirty = false;
        let saving = false;

        try { fields = JSON.parse(hidden.value || '{}'); } catch (e) { fields = {}; }

        function dimensions() {
            return orientation.value === 'portrait'
                ? {width: 1754, height: 2480}
                : {width: 2480, height: 1754};
        }

        function ensureField(key) {
            if (!fields[key]) {
                const imageField = isImageKey(key);
                fields[key] = {
                    enabled: key === 'qr' ? 1 : 0, x: 10, y: 10, width: key === 'qr' ? 12 : (imageField ? 20 : 60),
                    height: imageField ? 10 : 10, fit: 'contain', opacity: 1,
                    fontSize: (key === 'qr' || imageField) ? 8 : 42, fontFamily: 'Arial', color: '#111111', align: 'center',
                    bold: 0, italic: 0, lineHeight: 1.2, maxLines: 2
                };
            }
            return fields[key];
        }

        function sync() { hidden.value = JSON.stringify(fields); }

        function setSaveStatus(message, type = '') {
            if (!saveStatus) return;
            saveStatus.textContent = message || '';
            saveStatus.className = 'zau-template-save-status' + (type ? ' is-' + type : '');
        }

        function markDirty() {
            dirty = true;
            setSaveStatus('Есть несохранённые изменения', 'dirty');
        }

        function fittedStageWidth() {
            const d = dimensions();
            const ratio = d.width / d.height;
            const availableWidth = Math.max(260, (stageScroll?.clientWidth || stage.parentElement?.clientWidth || 700) - 26);
            const availableHeight = Math.max(420, Math.floor(window.innerHeight * 0.76));
            const orientationLimit = orientation.value === 'portrait' ? 720 : 1120;
            return Math.max(240, Math.min(availableWidth, availableHeight * ratio, orientationLimit));
        }

        function updateStageStatus(displayWidth, displayHeight) {
            if (!stageStatus) return;
            const d = dimensions();
            const percent = Math.round((displayWidth / d.width) * 100);
            stageStatus.textContent = `Страница ${d.width}×${d.height} px · редактор ${Math.round(displayWidth)}×${Math.round(displayHeight)} px · ${percent}%`;
        }

        function validateBackgroundRatio() {
            if (!stageWarning) return;
            if (!bg.src || !bg.naturalWidth || !bg.naturalHeight) {
                stageWarning.hidden = false;
                stageWarning.className = 'zau-stage-warning';
                stageWarning.textContent = 'Подложка ещё не загружена. Для точного совпадения используйте PNG/JPG 1754×2480 px для книжной страницы или 2480×1754 px для альбомной.';
                return;
            }
            const d = dimensions();
            const sourceRatio = bg.naturalWidth / bg.naturalHeight;
            const targetRatio = d.width / d.height;
            const diff = Math.abs(sourceRatio - targetRatio) / targetRatio;
            stageWarning.hidden = false;
            if (diff <= 0.005) {
                stageWarning.className = 'zau-stage-warning is-ok';
                stageWarning.textContent = `Пропорции подложки совпадают: ${bg.naturalWidth}×${bg.naturalHeight} px. Поля в редакторе и в PDF будут находиться в одинаковых местах.`;
            } else if (bgMode.value === 'stretch') {
                stageWarning.className = 'zau-stage-warning is-error';
                stageWarning.textContent = `Подложка ${bg.naturalWidth}×${bg.naturalHeight} px имеет другие пропорции. Режим «Растянуть» искажает документ. Выберите «Вместить целиком» или подготовьте изображение ${d.width}×${d.height} px.`;
            } else if (bgMode.value === 'cover') {
                stageWarning.className = 'zau-stage-warning';
                stageWarning.textContent = `Подложка ${bg.naturalWidth}×${bg.naturalHeight} px отличается по пропорциям. В режиме «Заполнить с обрезкой» часть краёв будет обрезана.`;
            } else {
                stageWarning.className = 'zau-stage-warning';
                stageWarning.textContent = `Подложка ${bg.naturalWidth}×${bg.naturalHeight} px отличается по пропорциям. В режиме «Вместить целиком» появятся свободные поля, но изображение не исказится.`;
            }
        }

        function applyStageGeometry() {
            const d = dimensions();
            const baseWidth = fittedStageWidth();
            const displayWidth = Math.max(220, baseWidth * stageZoom);
            const displayHeight = displayWidth * d.height / d.width;
            stage.style.aspectRatio = d.width + ' / ' + d.height;
            stage.style.width = displayWidth + 'px';
            stage.style.height = displayHeight + 'px';
            stage.dataset.orientation = orientation.value;
            bg.style.objectFit = bgMode.value === 'stretch' ? 'fill' : bgMode.value;
            updateStageStatus(displayWidth, displayHeight);
            validateBackgroundRatio();
            requestAnimationFrame(renderOverlays);
        }

        function updateBulkBar() {
            if (!bulkBar) return;
            const count = bulkSelected.size;
            bulkBar.hidden = count === 0;
            if (bulkCount) bulkCount.textContent = count === 1 ? 'Выбрано полей: 1' : 'Выбрано полей: ' + count;
        }

        function renderFieldList() {
            fieldList.innerHTML = '';
            const query = (fieldSearch?.value || '').trim().toLowerCase();
            const keys = Object.keys(defs).filter(key => !query || key.toLowerCase().includes(query) || String(defs[key] || '').toLowerCase().includes(query));
            if (!keys.length) { fieldList.innerHTML = '<p class="zau-field-list-empty">Ничего не найдено.</p>'; updateBulkBar(); return; }
            keys.forEach(key => {
                const f = ensureField(key);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'zau-field-list-item' + (selectedKey === key ? ' is-active' : '') + (f.enabled ? '' : ' is-disabled');
                button.dataset.key = key;
                button.innerHTML = '<label class="zau-field-check" title="Отметить для добавления списком"><input type="checkbox"' + (bulkSelected.has(key) ? ' checked' : '') + '></label><span class="zau-field-list-item-text"><span>' + esc(defs[key]) + '</span><small>' + (f.enabled ? 'включено' : 'выключено') + '</small></span>';
                const checkbox = button.querySelector('input[type="checkbox"]');
                checkbox.addEventListener('click', ev => {
                    ev.stopPropagation();
                    if (checkbox.checked) bulkSelected.add(key); else bulkSelected.delete(key);
                    updateBulkBar();
                });
                button.addEventListener('click', () => { selectedKey = key; renderFieldList(); renderPanel(); renderOverlays(); });
                fieldList.appendChild(button);
            });
            updateBulkBar();
        }
        fieldSearch?.addEventListener('input', renderFieldList);

        function bulkAddSelected() {
            if (!bulkSelected.size) return;
            let y = 10;
            Object.keys(defs).forEach(k => { const f = fields[k]; if (f && Number(f.enabled)) y = Math.max(y, Number(f.y) + 7); });
            let lastKey = selectedKey;
            bulkSelected.forEach(key => {
                const f = ensureField(key);
                if (!Number(f.enabled)) {
                    f.enabled = 1;
                    f.y = Math.min(94, y);
                    y += 7;
                }
                lastKey = key;
            });
            selectedKey = lastKey;
            bulkSelected.clear();
            sync(); markDirty(); renderFieldList(); renderPanel(); renderOverlays();
        }
        bulkAddBtn?.addEventListener('click', bulkAddSelected);
        bulkClearBtn?.addEventListener('click', () => { bulkSelected.clear(); renderFieldList(); });

        function overlayFontSize(f) {
            const d = dimensions();
            const scale = stage.clientWidth / d.width;
            return Math.max(8, Number(f.fontSize || 24) * scale);
        }

        function renderOverlays() {
            stageFields.innerHTML = '';
            Object.keys(defs).forEach(key => {
                const f = ensureField(key);
                if (!Number(f.enabled)) return;
                const el = document.createElement('div');
                el.className = 'zau-stage-field' + (selectedKey === key ? ' is-selected' : '') + (key === 'qr' ? ' is-qr' : '') + (isImageKey(key) ? ' is-image' : '');
                el.dataset.key = key;
                el.style.left = Number(f.x) + '%';
                el.style.top = Number(f.y) + '%';
                el.style.width = Number(f.width) + '%';
                if (key === 'qr') {
                    el.style.aspectRatio = '1 / 1';
                    el.innerHTML = '<span>QR</span>';
                } else if (isImageKey(key)) {
                    el.style.height = Number(f.height || 10) + '%';
                    el.innerHTML = '<span>' + esc(samples[key] || defs[key]) + '</span>';
                } else {
                    el.textContent = samples[key] || defs[key];
                    el.style.fontFamily = f.fontFamily || 'Arial';
                    el.style.fontSize = overlayFontSize(f) + 'px';
                    el.style.color = f.color || '#111111';
                    el.style.textAlign = f.align || 'center';
                    el.style.fontWeight = Number(f.bold) ? '700' : '400';
                    el.style.fontStyle = Number(f.italic) ? 'italic' : 'normal';
                    el.style.lineHeight = String(f.lineHeight || 1.2);
                }
                el.addEventListener('click', ev => { ev.stopPropagation(); selectedKey = key; renderFieldList(); renderPanel(); renderOverlays(); });
                makeDraggable(el, key);
                stageFields.appendChild(el);
            });
        }

        function makeDraggable(el, key) {
            el.addEventListener('pointerdown', ev => {
                if (ev.button !== 0) return;
                ev.preventDefault();
                selectedKey = key;
                el.setPointerCapture(ev.pointerId);
                const rect = stage.getBoundingClientRect();
                const f = ensureField(key);
                const startX = ev.clientX;
                const startY = ev.clientY;
                const oldX = Number(f.x);
                const oldY = Number(f.y);
                const move = e => {
                    const dx = (e.clientX - startX) / rect.width * 100;
                    const dy = (e.clientY - startY) / rect.height * 100;
                    f.x = Math.round(clamp(oldX + dx, 0, 100 - Number(f.width)) * 1000) / 1000;
                    const maxY = key === 'qr' ? 100 - (Number(f.width) * rect.width / rect.height) : (isImageKey(key) ? 100 - Number(f.height || 10) : 97);
                    f.y = Math.round(clamp(oldY + dy, 0, maxY) * 1000) / 1000;
                    el.style.left = f.x + '%'; el.style.top = f.y + '%'; sync(); markDirty(); updatePanelNumbers();
                };
                const up = e => {
                    el.releasePointerCapture(e.pointerId);
                    el.removeEventListener('pointermove', move);
                    el.removeEventListener('pointerup', up);
                    renderFieldList(); renderPanel();
                };
                el.addEventListener('pointermove', move);
                el.addEventListener('pointerup', up);
            });
        }

        function updatePanelNumbers() {
            const f = ensureField(selectedKey);
            ['x','y','width','height'].forEach(name => {
                const input = fieldPanel.querySelector('[name="field_' + name + '"]');
                if (input) input.value = formatDecimal(f[name]);
            });
        }

        function renderPanel() {
            const key = selectedKey;
            const f = ensureField(key);
            const isQr = key === 'qr';
            const isImage = isImageKey(key);
            const sizeControls = isImage
                ? `<label>Ширина, %<input type="text" inputmode="decimal" data-decimal data-min="2" data-max="100" name="field_width" value="${esc(formatDecimal(f.width))}"></label><label>Высота, %<input type="text" inputmode="decimal" data-decimal data-min="2" data-max="100" name="field_height" value="${esc(formatDecimal(f.height || 10))}"></label>`
                : `<label>${isQr ? 'Размер' : 'Ширина'}, %<input type="text" inputmode="decimal" data-decimal data-min="2" data-max="100" name="field_width" value="${esc(formatDecimal(f.width))}"></label>${isQr ? '' : `<label>Размер шрифта, px<input type="number" min="8" max="240" name="field_fontSize" value="${esc(f.fontSize)}"></label>`}`;
            const detailControls = isImage
                ? `<div class="zau-mini-grid"><label>Вписывание<select name="field_fit"><option value="contain">Вместить целиком</option><option value="cover">Заполнить с обрезкой</option><option value="stretch">Растянуть</option></select></label><label>Прозрачность<input type="number" min="0.1" max="1" step="0.05" name="field_opacity" value="${esc(f.opacity ?? 1)}"></label></div><p class="description">Лучше использовать PNG с прозрачным фоном. Файл подписи выбирается при создании документа или передаётся из CSV/XLSX.</p>`
                : (isQr ? '' : `
                <label>Шрифт<select name="field_fontFamily"><option>Arial</option><option>Georgia</option><option>Times New Roman</option><option>Verdana</option><option>Tahoma</option></select></label>
                <div class="zau-mini-grid">
                    <label>Цвет<input type="color" name="field_color" value="${esc(f.color || '#111111')}"></label>
                    <label>Выравнивание<select name="field_align"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></label>
                    <label>Интервал строк<input type="number" min="0.8" max="2.5" step="0.05" name="field_lineHeight" value="${esc(f.lineHeight)}"></label>
                    <label>Макс. строк<input type="number" min="1" max="10" name="field_maxLines" value="${esc(f.maxLines)}"></label>
                </div>
                <div class="zau-check-row"><label><input type="checkbox" name="field_bold" ${Number(f.bold) ? 'checked' : ''}> Жирный</label><label><input type="checkbox" name="field_italic" ${Number(f.italic) ? 'checked' : ''}> Курсив</label></div>`);

            fieldPanel.innerHTML = `
                <h3>${esc(defs[key] || key)}</h3>
                <label class="zau-switch"><input type="checkbox" name="field_enabled" ${Number(f.enabled) ? 'checked' : ''}><span>Показывать это поле</span></label>
                <div class="zau-mini-grid">
                    <label>X, %<input type="text" inputmode="decimal" data-decimal data-min="0" data-max="100" name="field_x" value="${esc(formatDecimal(f.x))}"></label>
                    <label>Y, %<input type="text" inputmode="decimal" data-decimal data-min="0" data-max="100" name="field_y" value="${esc(formatDecimal(f.y))}"></label>
                    ${sizeControls}
                </div>
                <div class="zau-nudge-box"><span>Точное перемещение</span><div class="zau-nudge-controls"><button type="button" data-nudge-x="-1" aria-label="Влево">←</button><button type="button" data-nudge-y="-1" aria-label="Вверх">↑</button><button type="button" data-nudge-y="1" aria-label="Вниз">↓</button><button type="button" data-nudge-x="1" aria-label="Вправо">→</button><select data-nudge-step aria-label="Шаг перемещения"><option value="0.01">0,01%</option><option value="0.05">0,05%</option><option value="0.1" selected>0,1%</option><option value="0.5">0,5%</option><option value="1">1%</option></select></div></div>
                ${detailControls}
                <p class="description">X и Y — координаты левого верхнего угла поля. Можно вводить точку или запятую. Положение после перетаскивания сохраняется с точностью до 0,001%.</p>`;

            const font = fieldPanel.querySelector('[name="field_fontFamily"]'); if (font) font.value = f.fontFamily || 'Arial';
            const align = fieldPanel.querySelector('[name="field_align"]'); if (align) align.value = f.align || 'center';
            const fit = fieldPanel.querySelector('[name="field_fit"]'); if (fit) fit.value = f.fit || 'contain';

            fieldPanel.querySelectorAll('input,select').forEach(input => input.addEventListener('input', () => {
                const name = input.name.replace('field_', '');
                if (input.type === 'checkbox') f[name] = input.checked ? 1 : 0;
                else if (['x','y','width','height','fontSize','lineHeight','maxLines','opacity'].includes(name)) {
                    let value = parseDecimal(input.value, f[name]);
                    const min = input.dataset.min !== undefined ? parseDecimal(input.dataset.min, -Infinity) : (input.min !== '' ? parseDecimal(input.min, -Infinity) : -Infinity);
                    const max = input.dataset.max !== undefined ? parseDecimal(input.dataset.max, Infinity) : (input.max !== '' ? parseDecimal(input.max, Infinity) : Infinity);
                    value = clamp(value, min, max);
                    f[name] = value;
                } else f[name] = input.value;
                sync(); markDirty(); renderFieldList(); renderOverlays();
            }));

            fieldPanel.querySelectorAll('[data-nudge-x],[data-nudge-y]').forEach(button => button.addEventListener('click', () => {
                const step = parseDecimal(fieldPanel.querySelector('[data-nudge-step]')?.value, 0.1);
                const dx = parseDecimal(button.dataset.nudgeX, 0) * step;
                const dy = parseDecimal(button.dataset.nudgeY, 0) * step;
                f.x = Math.round(clamp(parseDecimal(f.x) + dx, 0, 100 - parseDecimal(f.width, 0)) * 1000) / 1000;
                const maxY = key === 'qr' ? 100 - parseDecimal(f.width, 0) : (isImage ? 100 - parseDecimal(f.height, 10) : 97);
                f.y = Math.round(clamp(parseDecimal(f.y) + dy, 0, maxY) * 1000) / 1000;
                sync(); markDirty(); updatePanelNumbers(); renderOverlays();
            }));
        }

        stage.addEventListener('click', () => { renderOverlays(); });
        orientation.addEventListener('change', () => { stageZoom = 1; markDirty(); applyStageGeometry(); });
        bgMode.addEventListener('change', () => { markDirty(); applyStageGeometry(); });
        bgUrl.addEventListener('input', () => { markDirty(); bg.src = bgUrl.value; });
        bg.addEventListener('load', () => { validateBackgroundRatio(); applyStageGeometry(); });
        bg.addEventListener('error', () => {
            if (!stageWarning) return;
            stageWarning.hidden = false;
            stageWarning.className = 'zau-stage-warning is-error';
            stageWarning.textContent = 'Не удалось загрузить подложку. Выберите изображение из медиабиблиотеки этого сайта.';
        });
        stageFit?.addEventListener('click', () => { stageZoom = 1; applyStageGeometry(); stageScroll?.scrollTo({top:0,left:0,behavior:'smooth'}); });
        stageZoomOut?.addEventListener('click', () => { stageZoom = Math.max(0.5, Math.round((stageZoom - 0.1) * 10) / 10); applyStageGeometry(); });
        stageZoomIn?.addEventListener('click', () => { stageZoom = Math.min(2, Math.round((stageZoom + 0.1) * 10) / 10); applyStageGeometry(); });
        stageGrid?.addEventListener('change', () => stage.classList.toggle('has-grid', stageGrid.checked));
        let resizeTimer = 0;
        window.addEventListener('resize', () => {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(applyStageGeometry, 80);
        });
        // Не наблюдаем сам прокручиваемый контейнер через ResizeObserver: изменение полосы
        // прокрутки может создавать цикл перерасчётов и мешать нажатию «Сохранить».

        const prefixInput = root.querySelector('[name="document_prefix"]');
        const patternInput = root.querySelector('[name="number_pattern"]');
        const digitsInput = root.querySelector('[name="number_digits"]');
        const numberExample = root.querySelector('[data-zau-number-example]');
        function updateNumberExample() {
            if (!numberExample) return;
            const prefix = (prefixInput?.value || App.defaultPrefix || 'ZAU').trim();
            const pattern = (patternInput?.value || '{prefix}-{year}-{number}').trim();
            const digits = Math.max(1, Math.min(12, parseInt(digitsInput?.value || '6', 10) || 6));
            const now = new Date();
            const vars = {
                '{prefix}': prefix,
                '{year}': String(now.getFullYear()),
                '{month}': String(now.getMonth() + 1).padStart(2, '0'),
                '{day}': String(now.getDate()).padStart(2, '0'),
                '{number}': String(1).padStart(digits, '0'),
                '{id}': '1'
            };
            let example = pattern;
            Object.entries(vars).forEach(([key, value]) => { example = example.split(key).join(value); });
            numberExample.textContent = example || `${prefix}-${now.getFullYear()}-${String(1).padStart(digits, '0')}`;
        }
        [prefixInput, patternInput, digitsInput].filter(Boolean).forEach(input => input.addEventListener('input', updateNumberExample));
        updateNumberExample();

        form?.addEventListener('input', event => { if (event.isTrusted) markDirty(); });
        form?.addEventListener('change', event => { if (event.isTrusted) markDirty(); });

        form?.addEventListener('submit', async event => {
            if (saving) { event.preventDefault(); return; }
            event.preventDefault();
            sync();
            const nameInput = form.querySelector('[name="name"]');
            if (!String(nameInput?.value || '').trim()) {
                setSaveStatus('Укажите название шаблона.', 'error');
                nameInput?.focus();
                return;
            }
            const payload = new FormData(form);
            payload.set('action', 'zau_cert_save_template_ajax');
            payload.set('fields_json', JSON.stringify(fields));
            const buttons = Array.from(form.querySelectorAll('button[type="submit"],input[type="submit"]'));
            saving = true;
            buttons.forEach(button => button.disabled = true);
            setSaveStatus('Сохраняем шаблон…', 'saving');
            try {
                const response = await fetch(App.ajaxUrl, {method:'POST', body:payload, credentials:'same-origin'});
                let result;
                try { result = await response.json(); }
                catch (parseError) { throw new Error('Сервер вернул некорректный ответ. Проверьте журнал ошибок PHP.'); }
                if (!response.ok || !result?.success) {
                    throw new Error(result?.data?.message || 'Не удалось сохранить шаблон.');
                }
                const id = Number(result.data.template_id || 0);
                if (id) {
                    const idInput = form.querySelector('[name="template_id"]');
                    if (idInput) idInput.value = String(id);
                    root.dataset.templateId = String(id);
                    if (result.data.edit_url) window.history.replaceState({}, '', result.data.edit_url);
                }
                dirty = false;
                setSaveStatus('Сохранено: ' + (result.data.updated_at || 'только что'), 'success');
            } catch (error) {
                setSaveStatus(error.message || 'Ошибка сохранения.', 'error');
            } finally {
                saving = false;
                buttons.forEach(button => button.disabled = false);
            }
        });

        window.addEventListener('beforeunload', event => {
            if (!dirty || saving) return;
            event.preventDefault();
            event.returnValue = '';
        });

        document.getElementById('zau-select-background').addEventListener('click', () => {
            const frame = wp.media({title: App.strings?.chooseImage || 'Выберите подложку', button: {text: App.strings?.useImage || 'Использовать'}, multiple: false, library: {type: 'image'}});
            frame.on('select', () => {
                const item = frame.state().get('selection').first().toJSON();
                bgUrl.value = item.url; bgId.value = item.id; bg.src = item.url; stageZoom = 1; markDirty();
            });
            frame.open();
        });

        renderFieldList(); renderPanel(); applyStageGeometry(); sync();
    }

    function initCreatePage() {
        const form = document.getElementById('zau-create-document');
        if (!form) return;
        const select = document.getElementById('zau-create-template');
        const summary = document.getElementById('zau-template-summary');
        const progress = document.getElementById('zau-create-progress');
        const result = document.getElementById('zau-create-result');
        const holder = document.getElementById('zau-render-holder');

        form.querySelectorAll('.zau-select-document-image').forEach(button => button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input[type="url"]');
            const frame = wp.media({title: 'Выберите изображение подписи или печати', button: {text: 'Использовать изображение'}, multiple: false, library: {type: 'image'}});
            frame.on('select', () => { const item = frame.state().get('selection').first().toJSON(); input.value = item.url || ''; });
            frame.open();
        }));

        function showSummary() {
            const tpl = getTemplate(select.value);
            if (!tpl) { summary.innerHTML = ''; return; }
            summary.innerHTML = `<strong>${esc(tpl.name)}</strong><span>${tpl.orientation === 'portrait' ? 'Книжная ориентация' : 'Альбомная ориентация'} · ${Number(tpl.page_width)}×${Number(tpl.page_height)} px</span>`;
        }
        select.addEventListener('change', showSummary);

        form.addEventListener('submit', async ev => {
            ev.preventDefault(); result.innerHTML = ''; progress.textContent = App.strings?.generating || 'Формируется документ…';
            const button = form.querySelector('button[type="submit"]'); button.disabled = true;
            try {
                const values = Object.fromEntries(new FormData(form).entries());
                const prepared = await ajax('zau_cert_prepare_document', values);
                values.document_no = prepared.document_no;
                values.verify_url = prepared.verify_url;
                const imageData = await renderDocument(prepared.template, values, holder);
                const finalized = await ajax('zau_cert_finalize_document', {document_id: prepared.id, image_data: imageData});
                progress.textContent = '';
                result.innerHTML = `<div class="notice notice-success inline zau-result"><p><strong>Документ создан.</strong></p><p><a class="button button-primary" target="_blank" href="${esc(finalized.pdf_url)}">Открыть PDF</a> <a class="button" target="_blank" href="${esc(finalized.verify_url)}">Открыть проверку QR</a> <a class="button" href="${esc(finalized.pdf_url)}" download>Скачать PDF</a></p></div>`;
                result.scrollIntoView({behavior: 'smooth', block: 'center'});
            } catch (err) {
                progress.textContent = '';
                result.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || App.strings?.error || 'Ошибка')}</p></div>`;
            } finally { button.disabled = false; }
        });
    }


    function initImportPage() {
        const form = document.getElementById('zau-import-form');
        if (!form) return;
        const progress = document.getElementById('zau-import-progress');
        const result = document.getElementById('zau-import-result');
        const holder = document.getElementById('zau-import-render-holder');
        const button = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', async ev => {
            ev.preventDefault();
            result.innerHTML = '';
            button.disabled = true;
            let created = 0;
            const failures = [];
            try {
                progress.textContent = 'Чтение файла…';
                const parsed = await ajaxFile('zau_cert_parse_import', new FormData(form));
                const templateId = form.querySelector('[name="template_id"]').value;
                const template = getTemplate(templateId);
                if (!template) throw new Error('Шаблон не найден.');
                const userId = form.querySelector('[name="user_id"]').value || '0';
                for (let i = 0; i < parsed.rows.length; i++) {
                    const row = Object.assign({}, parsed.rows[i], {template_id: templateId, user_id: userId});
                    progress.textContent = `Создание ${i + 1} из ${parsed.rows.length}: ${row.full_name}`;
                    try {
                        const prepared = await ajax('zau_cert_prepare_document', row);
                        row.document_no = prepared.document_no;
                        row.verify_url = prepared.verify_url;
                        const imageData = await renderDocument(prepared.template, row, holder);
                        await ajax('zau_cert_finalize_document', {document_id: prepared.id, image_data: imageData});
                        created++;
                    } catch (err) {
                        failures.push(`${row.full_name}: ${err.message || 'ошибка'}`);
                    }
                    await new Promise(resolve => setTimeout(resolve, 20));
                }
                progress.textContent = '';
                const failedHtml = failures.length
                    ? `<details><summary>Ошибки: ${failures.length}</summary><ol>${failures.map(v => `<li>${esc(v)}</li>`).join('')}</ol></details>`
                    : '';
                result.innerHTML = `<div class="notice ${failures.length ? 'notice-warning' : 'notice-success'} inline zau-result"><p><strong>Импорт завершён.</strong> Создано: ${created}. Не создано: ${failures.length}.</p><p><a class="button button-primary" href="${esc(location.pathname + '?page=zau-cert-registry')}">Открыть реестр</a></p>${failedHtml}</div>`;
            } catch (err) {
                progress.textContent = '';
                result.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || 'Ошибка импорта.')}</p></div>`;
            } finally {
                button.disabled = false;
            }
        });
    }

    function initRegeneratePage() {
        const root = document.getElementById('zau-regenerate-document');
        if (!root) return;
        const button = root.querySelector('[data-zau-start-regenerate]');
        const progress = document.getElementById('zau-regenerate-progress');
        const result = document.getElementById('zau-regenerate-result');
        const holder = document.getElementById('zau-regenerate-render-holder');
        const documentId = Number(root.dataset.documentId || App.regenerateJob?.documentId || 0);
        const updateNumber = root.querySelector('[data-zau-regenerate-number]');
        if (!button || !documentId) return;
        button.addEventListener('click', async () => {
            button.disabled = true; result.innerHTML = ''; progress.textContent = 'Подготавливаем данные и шаблон…';
            try {
                const prepared = await ajax('zau_cert_prepare_regeneration', {document_id: documentId, update_number: updateNumber?.checked ? 1 : 0});
                progress.textContent = 'Формируем новое изображение документа…';
                const imageData = await renderDocument(prepared.template, prepared.values || {}, holder);
                progress.textContent = 'Сохраняем PDF…';
                const finalized = await ajax('zau_cert_finalize_document', {document_id: documentId, document_no: prepared.document_no, image_data: imageData});
                progress.textContent = '';
                const numberText = prepared.number_changed ? ` Номер обновлён: <code>${esc(prepared.old_document_no)}</code> → <code>${esc(prepared.document_no)}</code>.` : ` Номер: <code>${esc(prepared.document_no)}</code>.`;
                result.innerHTML = `<div class="notice notice-success inline zau-result"><p><strong>PDF успешно пересоздан.</strong>${numberText} QR сохранён.</p><p><a class="button button-primary" target="_blank" rel="noopener" href="${esc(finalized.pdf_url)}">Открыть новый PDF</a> <a class="button" target="_blank" rel="noopener" href="${esc(finalized.verify_url)}">Проверить QR</a></p></div>`;
            } catch (err) {
                progress.textContent = '';
                result.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || 'Не удалось пересоздать PDF.')}</p></div>`;
            } finally { button.disabled = false; }
        });
    }


    function initBulkRegeneratePage() {
        const root = document.getElementById('zau-bulk-regenerate-page');
        if (!root) return;
        const form = document.getElementById('zau-bulk-regenerate-form');
        const runner = root.querySelector('[data-zau-bulk-runner]');
        const previewButton = root.querySelector('[data-zau-bulk-preview]');
        const createButton = root.querySelector('[data-zau-bulk-create]');
        const previewResult = root.querySelector('[data-zau-bulk-preview-result]');
        const startButton = root.querySelector('[data-zau-bulk-start]');
        const pauseButton = root.querySelector('[data-zau-bulk-pause]');
        const retryButton = root.querySelector('[data-zau-bulk-retry]');
        const cancelButton = root.querySelector('[data-zau-bulk-cancel]');
        const progressBar = root.querySelector('[data-zau-bulk-progress-bar]');
        const statusText = root.querySelector('[data-zau-bulk-status-text]');
        const currentText = root.querySelector('[data-zau-bulk-current]');
        const result = root.querySelector('[data-zau-bulk-result]');
        const errorsList = root.querySelector('[data-zau-bulk-errors]');
        const holder = root.querySelector('[data-zau-bulk-render-holder]');
        const reportLink = root.querySelector('[data-zau-bulk-report]');
        const jobLabel = root.querySelector('[data-zau-bulk-job-label]');
        const stats = {
            total: root.querySelector('[data-zau-stat-total]'),
            processed: root.querySelector('[data-zau-stat-processed]'),
            success: root.querySelector('[data-zau-stat-success]'),
            failed: root.querySelector('[data-zau-stat-failed]'),
            skipped: root.querySelector('[data-zau-stat-skipped]')
        };
        let jobId = Number(root.dataset.activeJobId || 0);
        let running = false;
        let delayMs = 150;

        const ajaxTimeout = async (action, payload, timeoutMs) => {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            try {
                const body = new URLSearchParams(); body.set('action', action); body.set('nonce', App.nonce || '');
                Object.entries(payload || {}).forEach(([k, v]) => body.set(k, v == null ? '' : String(v)));
                const response = await fetch(App.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), credentials:'same-origin', signal: controller.signal});
                const json = await response.json().catch(() => null);
                if (!response.ok || !json || !json.success) throw new Error(json?.data?.message || 'Ошибка сервера: ' + response.status);
                return json.data;
            } catch (err) {
                if (err.name === 'AbortError') throw new Error('Сервер не ответил за ' + Math.round(timeoutMs / 1000) + ' секунд — запрос завис.');
                throw err;
            } finally {
                clearTimeout(timer);
            }
        };

        const backfillButton = root.querySelector('[data-zau-bulk-backfill-run]');
        const backfillStatus = root.querySelector('[data-zau-bulk-backfill-status]');
        backfillButton?.addEventListener('click', async () => {
            backfillButton.disabled = true;
            backfillStatus.textContent = 'Ищем формы с привязанными шаблонами…';
            try {
                const formsData = await ajaxTimeout('zau_cert_bulk_backfill_forms', {}, 20000);
                const forms = (formsData.forms || []).filter(f => Number(f.submission_count || 0) > 0);
                const formsSummary = (formsData.forms || []).map(f => `«${f.name}»${f.active ? '' : ' (неактивна)'}: ${f.submission_count} заявок`).join('; ');
                if (!(formsData.forms || []).length) {
                    backfillStatus.textContent = 'Нет ни одной формы с привязанными шаблонами документов.';
                    return;
                }
                if (!forms.length) {
                    backfillStatus.textContent = `Формы с шаблонами есть, но заявок в них 0. ${formsSummary}`;
                    return;
                }
                let totalChecked = 0, totalCreated = 0;
                for (const f of forms) {
                    let afterId = 0, hasMore = true;
                    while (hasMore) {
                        backfillStatus.textContent = `Форма «${f.name}»: проверено ${totalChecked}, создано документов ${totalCreated}…`;
                        let res, attempt = 0;
                        while (true) {
                            try { res = await ajaxTimeout('zau_cert_bulk_backfill_docs', {form_id: f.id, after_id: afterId}, 25000); break; }
                            catch (err) {
                                attempt++;
                                if (attempt >= 3) throw err;
                                backfillStatus.textContent = `Форма «${f.name}»: сервер не ответил вовремя, повторяем попытку ${attempt + 1}/3…`;
                                await sleep(2000 * attempt);
                            }
                        }
                        totalChecked += Number(res.checked || 0);
                        totalCreated += Number(res.created || 0);
                        afterId = Number(res.next_after_id || afterId);
                        hasMore = !!res.has_more;
                    }
                }
                backfillStatus.textContent = `Готово. Проверено заявок: ${totalChecked}. Создано новых документов (черновиков без PDF): ${totalCreated}. Формы: ${formsSummary}. Теперь их можно выбрать в шаге 1 фильтром «Состояние файла → Только без PDF».`;
            } catch (err) {
                backfillStatus.textContent = 'Ошибка: ' + (err.message || 'не удалось выполнить проверку.') + ' Нажмите кнопку ещё раз — уже созданные документы не задублируются.';
            } finally {
                backfillButton.disabled = false;
            }
        });

        const sleep = ms => new Promise(resolve => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
        const formPayload = () => {
            const fd = new FormData(form); const payload = {};
            for (const [key, value] of fd.entries()) {
                if (key.endsWith('[]')) {
                    const base = key.slice(0, -2);
                    payload[base] = payload[base] ? payload[base] + ',' + value : String(value);
                } else {
                    payload[key] = value;
                }
            }
            form.querySelectorAll('input[type="checkbox"]').forEach(input => { payload[input.name] = input.checked ? 1 : 0; });
            return payload;
        };
        const setBusy = busy => {
            if (previewButton) previewButton.disabled = busy;
            if (createButton) createButton.disabled = busy;
        };
        const updateUi = data => {
            if (!data) return;
            if (jobLabel) jobLabel.textContent = data.job_id ? `#${data.job_id}` : '';
            Object.keys(stats).forEach(key => { if (stats[key] && data[key] != null) stats[key].textContent = String(data[key]); });
            const total = Number(data.total || 0), processed = Number(data.processed || 0);
            if (progressBar) progressBar.style.width = (total ? Math.min(100, processed / total * 100) : 0) + '%';
            if (statusText) statusText.textContent = `${data.status_label || data.status || 'Ожидание'} · ${processed} из ${total}`;
            if (data.options && data.options.delay_ms != null) delayMs = Number(data.options.delay_ms) || 0;
            if (errorsList && Array.isArray(data.errors)) {
                errorsList.innerHTML = data.errors.length ? data.errors.map(item => `<li><strong>#${esc(item.document_id)} ${esc(item.full_name || '')}</strong> ${esc(item.document_no || '')}: ${esc(item.error || '')} <small>Попыток: ${esc(item.attempts)}</small></li>`).join('') : '<li>Ошибок пока нет.</li>';
            }
            if (reportLink && data.report_url) reportLink.href = data.report_url;
            if (data.status === 'completed') {
                running = false;
                if (result) result.innerHTML = `<div class="notice notice-success inline"><p><strong>Массовое пересоздание завершено.</strong> Успешно: ${esc(data.success)}. Ошибок: ${esc(data.failed)}. Пропущено: ${esc(data.skipped)}.</p></div>`;
            } else if (data.status === 'paused' || data.status === 'canceled') {
                running = false;
            }
        };
        const refreshStatus = async () => {
            if (!jobId) return null;
            const data = await ajax('zau_cert_bulk_job_status', {job_id: jobId});
            updateUi(data); return data;
        };

        async function runQueue() {
            if (!jobId || running) return;
            running = true;
            if (result) result.innerHTML = '';
            try { await ajax('zau_cert_bulk_control', {job_id: jobId, command: 'resume'}); } catch (_) {}
            while (running) {
                let item;
                try {
                    item = await ajax('zau_cert_bulk_next_item', {job_id: jobId});
                } catch (err) {
                    running = false;
                    if (result) result.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || 'Не удалось получить следующий документ.')}</p><p>Очередь сохранена. Нажмите «Продолжить» после восстановления связи.</p></div>`;
                    break;
                }
                if (item.done || item.status === 'completed') { running = false; await refreshStatus(); break; }
                if (item.status === 'paused' || item.status === 'canceled') { running = false; await refreshStatus(); break; }
                if (item.skip) { if (currentText) currentText.textContent = item.message || 'Запись пропущена.'; await refreshStatus(); await sleep(delayMs); continue; }
                if (!item.item_id || !item.document_id) { await sleep(300); continue; }
                if (currentText) currentText.innerHTML = `<strong>Документ #${esc(item.document_id)}</strong> · ${esc(item.full_name || '')} · ${esc(item.document_no || '')}<br><small>${esc(item.organization || '')} · попытка ${esc(item.attempt || 1)}</small>`;
                try {
                    const prepared = await ajax('zau_cert_prepare_regeneration', {
                        document_id: item.document_id,
                        target_template_id: item.target_template_id || 0,
                        update_number: item.update_number ? 1 : 0
                    });
                    if (currentText) currentText.innerHTML += '<br>Формируем изображение…';
                    const imageData = await renderDocument(prepared.template, prepared.values || {}, holder);
                    if (currentText) currentText.innerHTML += ' Сохраняем PDF…';
                    const finalized = await ajax('zau_cert_finalize_document', {
                        document_id: item.document_id,
                        document_no: prepared.document_no,
                        target_template_id: item.target_template_id || 0,
                        keep_old_files: item.keep_old_files ? 1 : 0,
                        image_data: imageData
                    });
                    await ajax('zau_cert_bulk_mark_item', {job_id: jobId, item_id: item.item_id, success: 1, document_no: finalized.document_no || prepared.document_no});
                    if (currentText) currentText.innerHTML = `<strong>Готово:</strong> #${esc(item.document_id)} · ${esc(finalized.document_no || prepared.document_no)} · ревизия ${esc(finalized.revision || '')}`;
                } catch (err) {
                    try { await ajax('zau_cert_bulk_mark_item', {job_id: jobId, item_id: item.item_id, success: 0, error: err.message || 'Ошибка пересоздания'}); } catch (_) {}
                    if (currentText) currentText.innerHTML = `<strong>Ошибка:</strong> #${esc(item.document_id)} · ${esc(err.message || 'Не удалось пересоздать PDF.')}`;
                }
                await refreshStatus();
                await sleep(item.delay_ms != null ? item.delay_ms : delayMs);
            }
        }

        previewButton?.addEventListener('click', async () => {
            setBusy(true); previewResult.innerHTML = '<p>Проверяем выборку…</p>';
            try {
                const data = await ajax('zau_cert_bulk_preview', formPayload());
                const sample = (data.sample || []).map(row => `<tr><td>#${esc(row.id)}</td><td>${esc(row.full_name)}</td><td>${esc(row.document_no)}</td><td>${esc(row.template)}</td><td>${esc(row.organization || '')}</td></tr>`).join('');
                previewResult.innerHTML = `<div class="notice ${data.count ? 'notice-info' : 'notice-warning'} inline"><p><strong>Найдено документов: ${esc(data.count)}</strong></p>${sample ? `<table class="widefat striped"><thead><tr><th>ID</th><th>ФИО</th><th>Номер</th><th>Шаблон</th><th>Организация</th></tr></thead><tbody>${sample}</tbody></table>` : '<p>Уточните фильтры или выберите целевой шаблон.</p>'}</div>`;
            } catch (err) { previewResult.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || 'Ошибка проверки.')}</p></div>`; }
            finally { setBusy(false); }
        });
        createButton?.addEventListener('click', async () => {
            if (!confirm('Создать очередь массового пересоздания? Сначала рекомендуется проверить несколько документов вручную.')) return;
            setBusy(true); previewResult.innerHTML = '<p>Создаём очередь…</p>';
            try {
                const data = await ajax('zau_cert_bulk_create_job', formPayload());
                jobId = Number(data.job_id || 0); root.dataset.activeJobId = String(jobId);
                if (runner) runner.hidden = false;
                history.replaceState({}, '', location.pathname + '?page=zau-cert-bulk-regenerate&job=' + jobId);
                previewResult.innerHTML = `<div class="notice notice-success inline"><p>Очередь #${esc(jobId)} создана. Документов: ${esc(data.total)}.</p></div>`;
                await refreshStatus(); await runQueue();
            } catch (err) { previewResult.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message || 'Не удалось создать очередь.')}</p></div>`; }
            finally { setBusy(false); }
        });
        startButton?.addEventListener('click', runQueue);
        pauseButton?.addEventListener('click', async () => { if (!jobId) return; running = false; await ajax('zau_cert_bulk_control', {job_id: jobId, command: 'pause'}); await refreshStatus(); });
        retryButton?.addEventListener('click', async () => { if (!jobId) return; await ajax('zau_cert_bulk_control', {job_id: jobId, command: 'retry_failed'}); await refreshStatus(); await runQueue(); });
        cancelButton?.addEventListener('click', async () => { if (!jobId || !confirm('Отменить очередь? Уже успешно пересозданные документы останутся.')) return; running = false; await ajax('zau_cert_bulk_control', {job_id: jobId, command: 'cancel'}); await refreshStatus(); });
        if (jobId) refreshStatus().catch(err => { if (result) result.innerHTML = `<div class="notice notice-error inline"><p>${esc(err.message)}</p></div>`; });
    }

    async function ajaxFile(action, formData) {
        formData.set('action', action);
        formData.set('nonce', App.nonce || '');
        const response = await fetch(App.ajaxUrl, {method:'POST', body:formData, credentials:'same-origin'});
        const json = await response.json().catch(() => null);
        if (!response.ok || !json || !json.success) throw new Error(json?.data?.message || 'Ошибка сервера: ' + response.status);
        return json.data;
    }

    async function ajax(action, payload) {
        const body = new URLSearchParams(); body.set('action', action); body.set('nonce', App.nonce || '');
        Object.entries(payload || {}).forEach(([k,v]) => body.set(k, v == null ? '' : String(v)));
        const response = await fetch(App.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString(), credentials:'same-origin'});
        const json = await response.json().catch(() => null);
        if (!response.ok || !json || !json.success) throw new Error(json?.data?.message || 'Ошибка сервера: ' + response.status);
        return json.data;
    }

    function loadImage(url) {
        return new Promise((resolve, reject) => {
            if (!url) { resolve(null); return; }
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img); img.onerror = () => reject(new Error('Не удалось загрузить изображение. Используйте файл из медиабиблиотеки этого сайта или URL с разрешённым CORS.'));
            img.src = url;
        });
    }

    function drawBackground(ctx, img, w, h, mode) {
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0,0,w,h); if (!img) return;
        if (mode === 'stretch') { ctx.drawImage(img,0,0,w,h); return; }
        const scale = mode === 'cover' ? Math.max(w/img.width, h/img.height) : Math.min(w/img.width, h/img.height);
        const dw = img.width*scale, dh = img.height*scale;
        ctx.drawImage(img,(w-dw)/2,(h-dh)/2,dw,dh);
    }

    function splitText(ctx, text, maxWidth) {
        const paragraphs = String(text || '').split(/\r?\n/); const lines=[];
        paragraphs.forEach((paragraph, pIndex) => {
            const words = paragraph.trim().split(/\s+/).filter(Boolean);
            if (!words.length) { lines.push(''); return; }
            let line='';
            words.forEach(word => {
                if (ctx.measureText(word).width > maxWidth) {
                    if (line) { lines.push(line); line=''; }
                    let chunk='';
                    Array.from(word).forEach(ch => { const test=chunk+ch; if(ctx.measureText(test).width>maxWidth && chunk){lines.push(chunk); chunk=ch;} else chunk=test; });
                    line=chunk; return;
                }
                const test=line ? line+' '+word : word;
                if(ctx.measureText(test).width>maxWidth && line){ lines.push(line); line=word; } else line=test;
            });
            if(line)lines.push(line); if(pIndex<paragraphs.length-1)lines.push('');
        });
        return lines;
    }

    function drawTextField(ctx, cfg, value, w, h) {
        if (!Number(cfg.enabled) || !String(value ?? '').trim()) return;
        const x = Number(cfg.x)/100*w, y = Number(cfg.y)/100*h, maxWidth = Number(cfg.width)/100*w;
        const size=Number(cfg.fontSize||36), style=(Number(cfg.italic)?'italic ':'')+(Number(cfg.bold)?'700 ':'400 ');
        ctx.font=style+size+'px "'+(cfg.fontFamily||'Arial')+'"'; ctx.fillStyle=cfg.color||'#111'; ctx.textBaseline='top'; ctx.textAlign=cfg.align||'center';
        const lines=splitText(ctx,value,maxWidth).slice(0,Number(cfg.maxLines||2)); const lh=size*Number(cfg.lineHeight||1.2);
        const tx=cfg.align==='left'?x:(cfg.align==='right'?x+maxWidth:x+maxWidth/2);
        lines.forEach((line,i)=>ctx.fillText(line,tx,y+i*lh,maxWidth));
    }

    async function drawPlacedImage(ctx, cfg, url, w, h) {
        if (!Number(cfg.enabled) || !String(url || '').trim()) return;
        const img = await loadImage(url);
        if (!img) return;
        const x = Number(cfg.x)/100*w, y = Number(cfg.y)/100*h;
        const boxW = Number(cfg.width || 20)/100*w, boxH = Number(cfg.height || 10)/100*h;
        const mode = cfg.fit || 'contain';
        let dx=x, dy=y, dw=boxW, dh=boxH;
        if (mode !== 'stretch') {
            const scale = mode === 'cover' ? Math.max(boxW/img.width, boxH/img.height) : Math.min(boxW/img.width, boxH/img.height);
            dw = img.width*scale; dh = img.height*scale; dx = x+(boxW-dw)/2; dy = y+(boxH-dh)/2;
        }
        ctx.save(); ctx.globalAlpha = Math.max(0.1, Math.min(1, Number(cfg.opacity ?? 1)));
        if (mode === 'cover') { ctx.beginPath(); ctx.rect(x,y,boxW,boxH); ctx.clip(); }
        ctx.drawImage(img,dx,dy,dw,dh); ctx.restore();
    }

    async function createQrCanvas(text, size, holder) {
        const div=document.createElement('div'); div.className='zau-offscreen-qr'; holder.appendChild(div);
        const qr=new QRCode(div,{text:String(text),width:size,height:size,colorDark:'#000000',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
        await new Promise(resolve=>setTimeout(resolve,0));
        const canvas=div.querySelector('canvas') || qr._canvas; if(!canvas)throw new Error('Не удалось создать QR-код.');
        return {canvas, cleanup:()=>div.remove()};
    }

    async function renderDocument(tpl, values, holder) {
        const w=Number(tpl.page_width), h=Number(tpl.page_height); const canvas=document.createElement('canvas'); canvas.width=w; canvas.height=h;
        const ctx=canvas.getContext('2d',{alpha:false}); const img=await loadImage(tpl.background_url); drawBackground(ctx,img,w,h,tpl.background_mode||'stretch');
        const fields=tpl.fields||{};
        const keys = Object.keys(fields).filter(key => key !== 'qr');
        keys.filter(key => !isImageKey(key)).forEach(key => drawTextField(ctx, fields[key]||{}, values[key]||'', w, h));
        for (const key of keys.filter(isImageKey)) { await drawPlacedImage(ctx, fields[key]||{}, values[key]||'', w, h); }
        const qrCfg=fields.qr||{};
        if(Number(qrCfg.enabled)){
            const size=Math.max(90,Math.round(Number(qrCfg.width)/100*w)); const qr=await createQrCanvas(values.verify_url,size,holder);
            const x=Number(qrCfg.x)/100*w, y=Number(qrCfg.y)/100*h; ctx.drawImage(qr.canvas,x,y,size,size); qr.cleanup();
        }
        return canvas.toDataURL('image/jpeg',0.94);
    }

    document.addEventListener('DOMContentLoaded', () => { initTemplateEditor(); initCreatePage(); initImportPage(); initRegeneratePage(); initBulkRegeneratePage(); });
})(jQuery);
