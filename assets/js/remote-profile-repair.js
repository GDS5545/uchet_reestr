(function($){
  'use strict';
  const cfg=window.ZAURemoteProfileRepair||{};
  let busy=false;
  const $progress=$('[data-zau-rpr-progress]');
  const $bar=$progress.find('span');
  const $text=$('[data-zau-rpr-progress-text]');
  const $stats=$('[data-zau-rpr-stats]');
  const $log=$('[data-zau-rpr-log]');
  function request(action,data){return $.ajax({url:cfg.ajaxUrl,method:'POST',data:Object.assign({action:action,nonce:cfg.nonce},data||{})});}
  function esc(v){return $('<div>').text(v==null?'':String(v)).html();}
  function render(job){
    job=job||{};const total=parseInt(job.total||0,10),done=parseInt(job.processed||0,10);const pct=total?Math.min(100,Math.round(done*100/total)):0;
    if(job.token){$progress.prop('hidden',false);} $bar.css('width',pct+'%');
    $text.text(done+' из '+total+' · '+(job.status==='finished'?'завершено':(job.dry_run?'пробный анализ':'исправление')));
    const s=job.stats||{};
    $stats.html([
      ['Обработано',done],['Исправлено',s.updated||0],['Без изменений',s.unchanged||0],['Нужна проверка',s.partial||0],['Ошибок',s.error||0]
    ].map(x=>'<div><strong>'+esc(x[1])+'</strong><span>'+esc(x[0])+'</span></div>').join(''));
    $log.text((job.log||[]).join('\n'));
  }
  function loop(){
    if(!busy)return;
    request('zau_remote_profile_repair_process').done(function(r){
      if(!r.success){busy=false;alert((r.data&&r.data.message)||'Ошибка обработки.');return;}
      render(r.data);if(r.data.status==='finished'){busy=false;return;} setTimeout(loop,150);
    }).fail(function(xhr){
      const d=xhr.responseJSON&&xhr.responseJSON.data;
      if(xhr.status===409 && d&&d.retry){setTimeout(loop,1200);return;}
      busy=false;alert((d&&d.message)||'Ошибка сервера при восстановлении профилей.');
    });
  }
  $(document).on('click','[data-zau-rpr-start]',function(){
    if(busy)return;const dry=$(this).data('zau-rpr-start')==='dry';
    if(!dry&&!window.confirm('Начать исправление уже перенесённых профилей? Новые аккаунты создаваться не будут.'))return;
    busy=true;request('zau_remote_profile_repair_start',{dry_run:dry?1:0}).done(function(r){if(!r.success){busy=false;alert(r.data&&r.data.message||'Не удалось начать.');return;}render(r.data);loop();}).fail(function(){busy=false;alert('Не удалось начать процесс.');});
  });
  $(document).on('click','[data-zau-rpr-reset]',function(){
    if(busy&&!window.confirm('Процесс выполняется. Сбросить только прогресс?'))return;
    busy=false;request('zau_remote_profile_repair_reset').done(function(r){location.reload();});
  });
  request('zau_remote_profile_repair_status').done(function(r){if(r.success&&r.data&&r.data.token)render(r.data);});
})(jQuery);
