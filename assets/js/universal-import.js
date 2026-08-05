(function($){
    'use strict';
    const cfg = window.ZAUUniversalImport || {};
    let currentHeaders = [];

    function message(text, type){
        const cls = type === 'error' ? 'notice-error' : 'notice-success';
        $('.zau-universal-wrap > .zau-ui-message').remove();
        $('<div class="notice '+cls+' inline zau-ui-message"><p></p></div>').find('p').text(text).end().insertAfter('.zau-universal-wrap h1');
    }
    function ajax(data, files){
        if(files){
            data.append('nonce', cfg.nonce);
            return $.ajax({url:cfg.ajaxUrl,method:'POST',data:data,processData:false,contentType:false,dataType:'json'});
        }
        data.nonce = cfg.nonce;
        return $.post(cfg.ajaxUrl, data, null, 'json');
    }
    function esc(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
    function targetSelect(index, selected){
        let html = '<select class="zau-map-target" data-index="'+index+'">';
        Object.keys(cfg.targetFields || {}).forEach(function(key){
            html += '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(cfg.targetFields[key])+'</option>';
        });
        return html + '</select>';
    }
    function renderMapping(resp){
        currentHeaders = resp.headers || [];
        const preview = resp.preview || [];
        const auto = resp.auto_mapping || {};
        const $body = $('[data-zau-mapping-table] tbody').empty();
        currentHeaders.forEach(function(header, i){
            const samples = preview.map(r => (r[i] || '')).filter(Boolean).slice(0,2).join(' · ');
            $body.append('<tr><td><strong>'+esc(header || ('Колонка '+(i+1)))+'</strong></td><td class="zau-example">'+esc(samples)+'</td><td>'+targetSelect(i, auto[String(i)] || '')+'</td></tr>');
        });
        $('[data-zau-file-summary]').text(resp.file_name+' · строк: '+resp.total+' · колонок: '+currentHeaders.length);
        $('[data-zau-mapping]').prop('hidden', false)[0].scrollIntoView({behavior:'smooth',block:'start'});
    }
    function mapping(){
        const map = {};
        $('.zau-map-target').each(function(){ map[$(this).data('index')] = $(this).val(); });
        return map;
    }
    function options(){
        return {
            mode:$('input[name="mode"]:checked').val() || 'both',
            create_users:$('input[name="create_users"]').is(':checked') ? 1 : 0,
            update_existing:$('input[name="update_existing"]').is(':checked') ? 1 : 0,
            overwrite:$('input[name="overwrite"]').is(':checked') ? 1 : 0,
            default_status:$('[name="default_status"]').val(),
            batch_size:$('[name="batch_size"]').val()
        };
    }
    function statLabel(key){
        const labels={created_users:'Создано аккаунтов',updated_users:'Обновлено аккаунтов',existing_users:'Найдено аккаунтов',submissions:'Добавлено заявлений',duplicates:'Повторы',skipped:'Пропущено',errors:'Ошибки',would_create:'Будет создано',would_update:'Будет обновлено',would_submit:'Будет заявлений'};
        return labels[key] || key;
    }
    function renderProgress(job){
        const total = Number(job.total || 0), done = Number(job.processed || 0), pct = total ? Math.min(100, Math.round(done/total*100)) : 0;
        $('[data-zau-progress]').prop('hidden', false);
        $('[data-zau-progress-bar]').css('width', pct+'%');
        $('[data-zau-progress-text]').text(done+' из '+total+' · '+pct+'%'+(job.dry_run?' · режим проверки':''));
        const stats = job.stats || {};
        let html=''; Object.keys(stats).forEach(k => { if(Number(stats[k])) html += '<span><strong>'+stats[k]+'</strong>'+esc(statLabel(k))+'</span>'; });
        $('[data-zau-stats]').html(html || '<span>Пока нет обработанных строк</span>');
        $('[data-zau-log]').text((job.log || []).join('\n'));
        if(job.status === 'finished'){
            message(job.dry_run ? 'Проверка завершена. Данные не записывались.' : 'Перенос завершён.', 'success');
            return;
        }
        setTimeout(processBatch, 250);
    }
    function processBatch(){
        ajax({action:'zau_universal_process'}).done(function(resp){
            if(!resp || !resp.success){ message(resp && resp.data && resp.data.message ? resp.data.message : 'Ошибка обработки партии.', 'error'); return; }
            renderProgress(resp.data);
        }).fail(function(xhr){ message('Сервер остановил импорт: HTTP '+xhr.status+'. Нажмите «Начать перенос» повторно — уже обработанные строки не задублируются.', 'error'); });
    }

    $('#zau-universal-upload-form').on('submit', function(e){
        e.preventDefault();
        const fd = new FormData(this); fd.append('action','zau_universal_upload');
        const $btn=$(this).find('button').prop('disabled',true).text('Читаем файл…');
        ajax(fd,true).done(function(resp){
            if(!resp || !resp.success){ message(resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось прочитать файл.', 'error'); return; }
            message(resp.data.message, 'success'); renderMapping(resp.data);
        }).fail(function(xhr){ message('Ошибка загрузки: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Загрузить и прочитать CSV'); });
    });

    $(document).on('click','[data-zau-start]',function(){
        const dry = $(this).data('zau-start') === 'dry';
        const opts = options();
        const data = Object.assign({action:'zau_universal_start',mapping:JSON.stringify(mapping()),dry_run:dry?1:0},opts);
        $('[data-zau-start]').prop('disabled',true);
        ajax(data).done(function(resp){
            $('[data-zau-start]').prop('disabled',false);
            if(!resp || !resp.success){ message(resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось запустить импорт.', 'error'); return; }
            $('[data-zau-progress]')[0].scrollIntoView({behavior:'smooth',block:'start'});
            renderProgress(resp.data);
        }).fail(function(xhr){ $('[data-zau-start]').prop('disabled',false); message('Ошибка запуска: HTTP '+xhr.status, 'error'); });
    });

    $(document).on('click','[data-zau-reset]',function(){
        if(!window.confirm('Сбросить загруженный файл и текущий прогресс? Уже импортированные данные останутся.')) return;
        ajax({action:'zau_universal_reset'}).done(function(resp){
            if(resp && resp.success){ window.location.reload(); }
            else message(resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось сбросить.', 'error');
        });
    });
})(jQuery);
