(function($){
  'use strict';
  var cfg = window.ZAURemoteBridge || {};
  var running = false, pdfRunning = false, busyRetries = 0;

  function request(action, data){
    data = data || {};
    data.action = action;
    data.nonce = cfg.nonce;
    return $.ajax({url: cfg.ajaxUrl, method:'POST', data:data, dataType:'json'}).then(function(resp){
      if (!resp || !resp.success) {
        var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Неизвестная ошибка.';
        return $.Deferred().reject({message:msg, response:resp}).promise();
      }
      return resp.data;
    }, function(xhr){
      var msg = 'Ошибка соединения с сервером.';
      if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
      return $.Deferred().reject({message:msg, xhr:xhr, data:(xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : {}}).promise();
    });
  }

  function esc(v){ return $('<div>').text(v == null ? '' : String(v)).html(); }
  function statsHtml(stats){
    var labels = {
      staged_users:'Считано аккаунтов', staged_applications:'Считано заявлений', unique_people:'Предполагаемых людей',
      created:'Создано аккаунтов', updated:'Обновлено аккаунтов', existing:'Найдено существующих', submissions:'Добавлено/сохранено заявлений', updated_submissions:'Обновлено заявлений без дубля',
      current:'Актуальных заявлений', archived:'Старых в архиве', exact_duplicates:'Точных повторов', already_imported:'Уже переносились',
      review_required:'Нужна ручная проверка', identity_conflicts:'Конфликтов личности', duplicates:'Повторов', skipped:'Пропущено', errors:'Ошибок',
      would_create:'Будет создано аккаунтов', would_update:'Будет найдено/обновлено', would_submit:'Будет новых заявлений', would_update_submission:'Будет обновлено заявлений без дубля',
      registered:'PDF привязано', unmatched:'PDF без владельца', ambiguous:'Неоднозначных PDF'
    };
    var html='';
    $.each(stats || {}, function(k,v){ html += '<div><strong>'+esc(v)+'</strong><span>'+esc(labels[k] || k)+'</span></div>'; });
    return html;
  }

  function renderJob(job){
    var total = 0, done = 0;
    $.each(job.totals || {}, function(k,v){ total += Number(v)||0; });
    $.each(job.processed || {}, function(k,v){ done += Number(v)||0; });
    var pct = total ? Math.min(100, Math.round(done*100/total)) : (job.status==='finished'?100:0);
    $('[data-zau-rb-progress]').removeAttr('hidden').find('span').css('width',pct+'%');
    var phases = {
      scan_users:'считывание аккаунтов', scan_applications:'считывание заявлений', people:'объединение людей и дублей',
      imports:'перенос актуальных заявлений', finished:'завершено'
    };
    var phase = phases[job.phase] || job.phase || 'ожидание';
    $('[data-zau-rb-progress-text]').text(done+' из '+total+' · '+phase+(job.dry_run?' · пробный анализ':''));
    $('[data-zau-rb-stats]').html(statsHtml(job.stats));
    $('[data-zau-rb-log]').text((job.log||[]).join('\n'));
  }

  function setRunningUi(isRunning){
    $('[data-zau-rb-start]').prop('disabled', !!isRunning);
    $('[data-zau-rb-resume]').prop('hidden', !!isRunning);
  }

  function processNext(){
    if (!running) return;
    request('zau_remote_bridge_process').done(function(job){
      busyRetries = 0;
      renderJob(job);
      if (job.status === 'finished') {
        running=false; setRunningUi(false); $('[data-zau-rb-resume]').prop('hidden',true); return;
      }
      setTimeout(processNext, 350);
    }).fail(function(err){
      var status = err.xhr && err.xhr.status ? Number(err.xhr.status) : 0;
      if (status === 409 && err.data && err.data.job && err.data.job.status === 'running') {
        renderJob(err.data.job);
        busyRetries++;
        var wait = Math.max(1200, Math.min(5000, Number(err.data.retry_after || 2) * 1000));
        $('[data-zau-rb-message]').removeClass('error').addClass('ok').text('Текущая партия ещё завершается. Повтор через '+Math.round(wait/1000)+' сек.');
        setTimeout(processNext, wait);
        return;
      }
      running=false; setRunningUi(false);
      $('[data-zau-rb-resume]').prop('hidden',false);
      $('[data-zau-rb-message]').removeClass('ok').addClass('error').text(err.message || 'Ошибка переноса. Нажмите «Продолжить текущий процесс».');
    });
  }

  $('[data-zau-rb-test]').on('click', function(){
    var $m=$('[data-zau-rb-message]').removeClass('ok error').text('Проверяем…');
    request('zau_remote_bridge_test').done(function(data){
      var text='Подключение работает. Мост: <strong>'+esc(data.bridge_version || 'неизвестно')+'</strong>. Пользователей: <strong>'+esc(data.user_count)+'</strong>, заявлений WPForms: <strong>'+esc(data.application_count)+'</strong>.';
      if (data.warning) text += '<br><strong>'+esc(data.warning)+'</strong>';
      $m.addClass(data.warning?'error':'ok').html(text);
    }).fail(function(err){ $m.addClass('error').text(err.message || 'Подключение не установлено.'); });
  });

  $('[data-zau-rb-start]').on('click', function(){
    if (running) return;
    var dry = $(this).data('zau-rb-start') === 'dry' ? 1 : 0;
    if (!dry && !confirm('Начать настоящий перенос аккаунтов и заявлений? Перед этим должна быть резервная копия.')) return;
    running=true; busyRetries=0; setRunningUi(true);
    request('zau_remote_bridge_start',{dry_run:dry}).done(function(job){ renderJob(job); $('[data-zau-rb-message]').removeClass('error ok').text(''); processNext(); }).fail(function(err){ running=false; setRunningUi(false); $('[data-zau-rb-message]').removeClass('ok').addClass('error').text(err.message || 'Не удалось начать перенос.'); });
  });

  $('[data-zau-rb-reset]').on('click', function(){
    if (!confirm('Сбросить только прогресс? Уже перенесённые данные останутся.')) return;
    running=false; setRunningUi(false);
    request('zau_remote_bridge_reset').done(function(data){ location.reload(); }).fail(function(err){ alert(err.message); });
  });

  $('[data-zau-rb-resume]').on('click', function(){
    if (running) return;
    running=true; busyRetries=0; setRunningUi(true);
    $('[data-zau-rb-message]').removeClass('error ok').text('Продолжаем с сохранённого места…');
    processNext();
  });

  function loadCurrentStatus(){
    request('zau_remote_bridge_status').done(function(data){
      if (data && data.job) {
        renderJob(data.job);
        if (data.job.status === 'running') {
          $('[data-zau-rb-resume]').prop('hidden',false);
          $('[data-zau-rb-start]').prop('disabled',true);
          var msg = data.stale_cleared ? 'Зависшая блокировка снята. Нажмите «Продолжить текущий процесс».' : 'Найден незавершённый процесс. Можно продолжить с сохранённого места.';
          $('[data-zau-rb-message]').removeClass('error').addClass('ok').text(msg);
        } else {
          $('[data-zau-rb-resume]').prop('hidden',true);
        }
      }
    });
  }
  loadCurrentStatus();

  function renderPdf(job){
    var pct = job.total ? Math.min(100,Math.round(job.processed*100/job.total)) : (job.status==='finished'?100:0);
    $('[data-zau-rb-pdf-progress]').removeAttr('hidden').find('span').css('width',pct+'%');
    $('[data-zau-rb-pdf-progress-text]').text(job.processed+' из '+job.total);
    $('[data-zau-rb-pdf-stats]').html(statsHtml(job.stats));
    $('[data-zau-rb-pdf-log]').text((job.log||[]).join('\n'));
  }
  function pdfNext(){
    if (!pdfRunning) return;
    request('zau_remote_bridge_pdf_process').done(function(job){
      renderPdf(job);
      if (job.status==='finished'){ pdfRunning=false; $('[data-zau-rb-pdf-start]').prop('disabled',false); return; }
      setTimeout(pdfNext,250);
    }).fail(function(err){ pdfRunning=false; $('[data-zau-rb-pdf-start]').prop('disabled',false); alert(err.message); });
  }
  $('[data-zau-rb-pdf-start]').on('click',function(){
    if (pdfRunning) return;
    pdfRunning=true; $(this).prop('disabled',true);
    request('zau_remote_bridge_pdf_start').done(function(job){ renderPdf(job); pdfNext(); }).fail(function(err){ pdfRunning=false; $('[data-zau-rb-pdf-start]').prop('disabled',false); alert(err.message); });
  });
  $('[data-zau-rb-pdf-reset]').on('click',function(){
    if (!confirm('Сбросить проверку PDF? Уже привязанные документы останутся.')) return;
    pdfRunning=false;
    request('zau_remote_bridge_pdf_reset').done(function(){ location.reload(); }).fail(function(err){ alert(err.message); });
  });
})(jQuery);
