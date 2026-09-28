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
    would_create: 'Будет создано аккаунтов', would_link_existing: 'Будет связано по email', would_update: 'Будет обновлено',
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
    prior_wrong_owner: 'Были у чужого аккаунта', possible_duplicate: 'Возможных дублей', no_exact_copy: 'Без точной копии', restored: 'Возвращено'
  };
  var phases = { forms: 'формы', users: 'аккаунты', entries: 'заявления', profiles: 'профили', v_users: 'сверка аккаунтов',
    v_entries: 'сверка заявлений', v_orphans: 'поиск удалённых', dupcheck: 'проверка дублей', cleanup: 'прежние переносы', rollback: 'откат', finished: 'завершено' };

  function render(job) {
    if (!job) { return; }
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
      retries++;
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
  if (cfg.job && cfg.job.token) { render(cfg.job); }
})();
