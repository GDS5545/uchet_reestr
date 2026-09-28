(function () {
  'use strict';
  var cfg = window.ZAUExactPublic || {};

  function post(action, data) {
    var body = new FormData();
    body.set('action', action);
    body.set('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'Не удалось выполнить действие.'); }
        return json.data;
      });
  }

  function message(root, text, isError) {
    var box = root && root.querySelector('[data-zau-exact-message]');
    if (!box) { window.alert(text); return; }
    box.className = 'zau-legacy-message ' + (isError ? 'zau-form-error' : 'zau-form-success');
    box.textContent = text;
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-zau-exact-action]');
    if (button) {
      var root = button.closest('[data-zau-legacy-archive]');
      var holder = button.closest('[data-submission-id]');
      var action = button.dataset.zauExactAction;
      var comment = '';
      if (action === 'dispute') {
        comment = window.prompt('Почему это заявление не ваше? (например: «подавал(а) за коллегу Иванову А.»)', '');
        if (comment === null) { return; }
      }
      button.disabled = true;
      post('zau_exact_submission_action', { submission_id: holder.dataset.submissionId, do: action, comment: comment })
        .then(function (d) { message(root, d.message, false); setTimeout(function () { window.location.reload(); }, 1200); })
        .catch(function (err) { button.disabled = false; message(root, err.message, true); });
      return;
    }
    var profile = event.target.closest('[data-zau-exact-profile-dispute]');
    if (profile) {
      var box = profile.closest('.zau-legacy-archive');
      var text = window.prompt('Какие данные в профиле не ваши? (ФИО, ИИН, телефон, организация…)', '');
      if (text === null) { return; }
      profile.disabled = true;
      post('zau_exact_profile_dispute', { comment: text })
        .then(function (d) { message(box, d.message, false); setTimeout(function () { window.location.reload(); }, 1500); })
        .catch(function (err) { profile.disabled = false; message(box, err.message, true); });
    }
  });
})();
