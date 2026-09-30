(function () {
  'use strict';
  var cfg = window.ZAUExactMigration || {};
  var running = false;
  var retries = 0;

  function $(sel) { return document.querySelector(sel); }
  function $all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }

  function post(action, data) {
    var body = new FormData();
    body.set('action', action);
    body.set('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { success: false, data: { message: 'Сервер вернул не JSON (HTTP ' + r.status + ').' } }; });
    }).then(function (json) {
      if (!json || !json.success) {
        var err = new Error((json && json.data && json.data.message) || 'Ошибка запроса.');
        err.data = (json && json.data) || {};
        throw err;
      }
      return json.data;
    });
  }

  var labels = {
    created: 'Создано аккаунтов', linked_existing: 'Связано по email', updated: 'Обновлено', unchanged: 'Без изменений',
    would_create: 'Будет создано аккаунтов', would_link_existing: 'Будет связано по email', would_link_prior_import: 'Будет связано с аккаунтом прежнего переноса', linked_prior_import: 'Связано с аккаунтом прежнего переноса', would_update: 'Будет обновлено',
    conflict: 'Конфликтов email', skipped_admin: 'Администраторов пропущено', skipped_no_create: 'Не создано (выключено)',
    own: 'Заявлений владельцев', other_person: 'Поданы за другого', unassigned: 'Без владельца',
    profile_filled: 'Профилей дополнено', transfer_error: 'Ошибок передачи', error: 'Ошибок',
    users_ok: 'Аккаунтов совпало', users_missing: 'Аккаунтов не хватает', users_changed_on_old_site: 'Аккаунтов изменено на старом',
    users_stored_mismatch: 'Копий аккаунтов не совпало', users_skipped_admin: 'Администраторов (не переносятся)',
    entries_ok: 'Заявлений совпало', entries_ok_unassigned: 'Совпало, без владельца', entries_ok_hidden: 'Совпало (спам/корзина)',
    entries_missing: 'Заявлений не хватает', entries_changed_on_old_site: 'Заявлений изменено на старом',
    entries_stored_mismatch: 'Копий заявлений не совпало', users_deleted_on_old_site: 'Аккаунтов удалено на старом',
    entrys_deleted_on_old_site: 'Заявлений удалено на старом',
    prior_submissions: 'Заявок прежних переносов', would_supersede: 'Будет скрыто', superseded: 'Скрыто',
    prior_wrong_owner: 'Были у чужого аккаунта', plan_supersede: 'Скрыть прежнюю копию', plan_reassign_exact: 'Отдать точную копию заявителю', plan_review: 'На ручную проверку (без изменений)', possible_duplicate: 'Возможных дублей', prior_account_mismatch: 'Ошибочных аккаунтов прежнего переноса', no_exact_copy: 'Без точной копии', restored: 'Возвращено'
  };
  var phases = { forms: 'формы', users: 'аккаунты', entries: 'заявления', profiles: 'профили', v_users: 'сверка аккаунтов',
    v_entries: 'сверка заявлений', v_orphans: 'поиск удалённых', dupcheck: 'проверка дублей', cleanup_index: 'индекс прежних заявок', cleanup: 'прежние переносы', rollback: 'откат', finished: 'завершено' };

  function render(job) {
    if (!job || !document.querySelector('[data-zau-exact-progress]')) { return; }
    var total = (Number(job.totals && job.totals.users) || 0) + (Number(job.totals && job.totals.entries) || 0);
    var done = (Number(job.processed && job.processed.users) || 0) + (Number(job.processed && job.processed.entries) || 0);
    var pct = job.status === 'finished' ? 100 : (total ? Math.min(99, Math.round(done * 100 / total)) : 0);
    var bar = $('[data-zau-exact-progress]');
    bar.hidden = false;
    bar.querySelector('span').style.width = pct + '%';
    $('[data-zau-exact-progress-text]').textContent = (job.mode_label || '') + ' · ' + (phases[job.phase] || job.phase || '') + (total ? ' · ' + done + ' из ' + total : '');
    var html = '';
    Object.keys(job.stats || {}).forEach(function (k) { html += '<div><strong>' + esc(job.stats[k]) + '</strong><span>' + esc(labels[k] || k) + '</span></div>'; });
    $('[data-zau-exact-stats]').innerHTML = html;
    $('[data-zau-exact-log]').textContent = (job.log || []).join('\n');
    var summary = $('[data-zau-exact-summary]');
    if (job.summary) {
      var s = job.summary;
      summary.innerHTML = '<div class="zau-exact-summary ' + (s.complete ? 'is-ok' : 'is-bad') + '"><strong>' +
        (s.complete ? '100% — все данные старого сайта есть на новом и совпадают побайтно.' : (s.not_imported ? 'Перенос ещё не выполнен: на новом сайте нет ни одной перенесённой записи. Нажмите «Перенести», дождитесь окончания и повторите сверку.' : 'Есть расхождения: ' + esc(s.problems) + '. Скачайте отчёт и запустите перенос ещё раз.')) +
        '</strong><span>Аккаунты: ' + esc(s.users_ok) + ' из ' + esc(s.users_total) + ' · Заявления: ' + esc(s.entries_ok) + ' из ' + esc(s.entries_total) +
        (s.unassigned ? ' · без владельца: ' + esc(s.unassigned) + ' (см. очередь)' : '') + '</span></div>';
    } else { summary.innerHTML = ''; }
    $all('[data-zau-exact-start]').forEach(function (b) { b.disabled = job.status === 'running'; });
    $('[data-zau-exact-resume]').hidden = !(job.status === 'running' && !running);
  }

  function loop() {
    if (!running) { return; }
    post('zau_exact_process').then(function (job) {
      retries = 0;
      render(job);
      if (job.status === 'running') { setTimeout(loop, 150); } else { running = false; render(job); }
    }).catch(function (err) {
      if (err.data && err.data.job) { render(err.data.job); }
      // «Предыдущая партия ещё обрабатывается» — просто ждём снятия блокировки, это не ошибка.
      var busy = /ещё обрабатывается/.test(err.message || '');
      retries += busy ? 0.1 : 1;
      if (busy) { $('[data-zau-exact-progress-text]').textContent = 'Ждём завершения предыдущей партии… (до 3 минут)'; }
      if (retries > 8) {
        running = false;
        $('[data-zau-exact-progress-text]').textContent = 'Остановлено: ' + err.message + ' Нажмите «Продолжить».';
        $('[data-zau-exact-resume]').hidden = false;
        return;
      }
      setTimeout(loop, 1000 * Math.min(15, (err.data && err.data.retry_after) || retries * 2));
    });
  }

  $all('[data-zau-exact-start]').forEach(function (button) {
    button.addEventListener('click', function (e) {
      e.preventDefault();
      if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { return; }
      button.disabled = true;
      post('zau_exact_start', { mode: button.dataset.zauExactStart }).then(function (job) {
        render(job);
        running = true; retries = 0; loop();
      }).catch(function (err) { button.disabled = false; window.alert(err.message); });
    });
  });

  var resume = $('[data-zau-exact-resume]');
  if (resume) { resume.addEventListener('click', function () { running = true; retries = 0; resume.hidden = true; loop(); }); }

  var reset = $('[data-zau-exact-reset]');
  if (reset) {
    reset.addEventListener('click', function () {
      if (!window.confirm('Сбросить текущее задание? Перенесённые данные не удаляются.')) { return; }
      running = false;
      post('zau_exact_reset').then(function (d) { window.alert(d.message); window.location.reload(); }).catch(function (err) { window.alert(err.message); });
    });
  }

  var test = $('[data-zau-exact-test]');
  if (test) {
    test.addEventListener('click', function () {
      var box = $('[data-zau-exact-message]');
      box.className = 'zau-rb-message';
      box.textContent = 'Проверяем…';
      post('zau_exact_test').then(function (d) { box.classList.add('ok'); box.textContent = d.message; })
        .catch(function (err) { box.classList.add('error'); box.textContent = err.message; });
    });
  }

  // Показать состояние последнего задания после перезагрузки страницы.
  if (cfg.job && cfg.job.token && document.querySelector('[data-zau-exact-progress]')) { render(cfg.job); }
})();
/* Проверка ФИО и подписей: пакетный запуск */
(function () {
  'use strict';
  var cfg = window.ZAUExactMigration || {};
  var btn = document.querySelector('[data-zau-audit-run]');
  if (!btn) { return; }
  var out = document.querySelector('[data-zau-audit-progress]');
  function step(cursor, tries) {
    var body = new FormData();
    body.set('action', 'zau_exact_audit_run'); body.set('nonce', cfg.nonce); body.set('cursor', cursor);
    fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { throw new Error('Сервер ответил не JSON (HTTP ' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)); }
      });
    }).then(function (j) {
      if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Ошибка'); }
      var d = j.data;
      out.textContent = 'Проверено ' + d.done + ' из ' + d.total + '…';
      if (d.finished) { out.textContent = 'Готово: проверено ' + d.total + '. Обновляем страницу…'; setTimeout(function () { window.location.reload(); }, 800); }
      else { step(d.cursor, 0); }
    }).catch(function (e) {
      if (tries < 5) { out.textContent = 'Повтор… ' + e.message; setTimeout(function () { step(cursor, tries + 1); }, 2000 * (tries + 1)); }
      else { out.textContent = 'Остановлено: ' + e.message + '. Нажмите кнопку ещё раз — проверка начнётся сначала.'; btn.disabled = false; }
    });
  }
  btn.addEventListener('click', function () { btn.disabled = true; out.textContent = 'Запуск…'; step(0, 0); });
})();
/* Массовое исправление ФИО: просмотр / исправление / отмена партиями */
(function () {
  'use strict';
  var cfg = window.ZAUExactMigration || {};
  var buttons = document.querySelectorAll('[data-zau-namefix]');
  if (!buttons.length) { return; }
  var out = document.querySelector('[data-zau-namefix-progress]');
  var names = { preview: 'Просмотр', apply: 'Исправление', rollback: 'Отмена' };
  var statNames = { strong: 'доказано', plain: 'без признаков', review: 'вручную', fixed: 'исправлено', skipped: 'пропущено', restored: 'возвращено', changed: 'не тронуто', none: 'нет данных' };
  function statsText(stats) {
    return Object.keys(stats || {}).map(function (k) { return (statNames[k] || k) + ': ' + stats[k]; }).join(' · ');
  }
  function setBusy(on) { Array.prototype.forEach.call(buttons, function (b) { b.disabled = on; }); }
  function step(mode, cats, cursor, processed, stats, tries) {
    var body = new FormData();
    body.set('action', 'zau_exact_namefix_run'); body.set('nonce', cfg.nonce); body.set('mode', mode);
    body.set('cursor', cursor); body.set('processed', processed);
    cats.forEach(function (c) { body.append('cats[]', c); });
    Object.keys(stats).forEach(function (k) { body.set('stats[' + k + ']', stats[k]); });
    fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { throw new Error('Сервер ответил не JSON (HTTP ' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)); }
      });
    }).then(function (j) {
      if (!j || !j.success) { var err = new Error((j && j.data && j.data.message) || 'Ошибка'); err.fatal = true; throw err; }
      var d = j.data;
      out.textContent = names[mode] + ': обработано ' + d.done + (d.total ? ' из ' + d.total : '') + '… ' + statsText(d.stats);
      if (d.finished) {
        out.textContent = names[mode] + ' завершён(а). ' + statsText(d.stats) + '. Обновляем страницу…';
        setTimeout(function () { window.location.reload(); }, 1200);
      } else { step(mode, cats, d.cursor, d.processed, d.stats || {}, 0); }
    }).catch(function (e) {
      if (!e.fatal && tries < 5) { out.textContent = 'Повтор… ' + e.message; setTimeout(function () { step(mode, cats, cursor, processed, stats, tries + 1); }, 2000 * (tries + 1)); }
      else { out.textContent = 'Остановлено: ' + e.message; setBusy(false); }
    });
  }
  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var mode = btn.getAttribute('data-zau-namefix');
      var cats = Array.prototype.filter.call(document.querySelectorAll('[data-zau-namefix-cat]'), function (c) { return c.checked; }).map(function (c) { return c.value; });
      if (mode === 'apply' && !cats.length) { window.alert('Отметьте хотя бы одну группу.'); return; }
      if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) { return; }
      setBusy(true); out.textContent = 'Запуск…';
      step(mode, cats, 0, 0, {}, 0);
    });
  });
})();
/* Пересоздание перенесённых заявлений: подготовка документов / удаление */
(function () {
  'use strict';
  var cfg = window.ZAUExactMigration || {};
  var buttons = document.querySelectorAll('[data-zau-regen]');
  if (!buttons.length) { return; }
  var out = document.querySelector('[data-zau-regen-progress]');
  var box = document.querySelector('[data-zau-regen-result]');
  var usersInput = document.querySelector('[data-zau-regen-users]');
  var names = { submissions: 'заявлений', created: 'создано документов', updated: 'обновлено', ready: 'уже были готовы', no_signature: 'пропущено без подписи', no_user: 'нет владельца', error: 'ошибок', deleted: 'удалено' };
  function statsText(s) { return Object.keys(s || {}).map(function (k) { return (names[k] || k) + ': ' + s[k]; }).join(' · '); }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
  function setBusy(on) { Array.prototype.forEach.call(buttons, function (b) { b.disabled = on; }); }
  var errors = [];
  function step(mode, cursor, processed, stats, tries) {
    var body = new FormData();
    body.set('action', 'zau_exact_regen_run'); body.set('nonce', cfg.nonce); body.set('mode', mode);
    body.set('cursor', cursor); body.set('processed', processed);
    body.set('users', usersInput ? usersInput.value : '');
    Object.keys(stats).forEach(function (k) { body.set('stats[' + k + ']', stats[k]); });
    fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { throw new Error('Сервер ответил не JSON (HTTP ' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)); }
      });
    }).then(function (j) {
      if (!j || !j.success) { var err = new Error((j && j.data && j.data.message) || 'Ошибка'); err.fatal = true; throw err; }
      var d = j.data;
      errors = errors.concat(d.errors || []).slice(-30);
      out.textContent = (mode === 'delete' ? 'Удаление: ' : 'Подготовка: ') + d.done + (d.total ? ' из ' + d.total : '') + '… ' + statsText(d.stats);
      if (!d.finished) { step(mode, d.cursor, d.processed, d.stats || {}, 0); return; }
      setBusy(false);
      if (mode === 'delete') { out.textContent = 'Готово. ' + statsText(d.stats) + '. Обновляем страницу…'; setTimeout(function () { window.location.reload(); }, 1000); return; }
      out.textContent = 'Подготовка завершена. ' + statsText(d.stats);
      var html = errors.length ? '<p><strong>Ошибки:</strong><br>' + errors.map(esc).join('<br>') + '</p>' : '';
      html += d.job_url ? '<p><a class="button button-primary" href="' + esc(d.job_url) + '">Открыть очередь и сформировать PDF</a> — на открывшейся странице нажмите «Продолжить».</p>' : '<p>Новых документов для формирования нет.</p>';
      box.innerHTML = html;
    }).catch(function (e) {
      if (!e.fatal && tries < 5) { out.textContent = 'Повтор… ' + e.message; setTimeout(function () { step(mode, cursor, processed, stats, tries + 1); }, 2000 * (tries + 1)); }
      else { out.textContent = 'Остановлено: ' + e.message; setBusy(false); }
    });
  }
  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) { return; }
      setBusy(true); errors = []; out.textContent = 'Запуск…';
      step(btn.getAttribute('data-zau-regen'), 0, 0, {}, 0);
    });
  });
})();
