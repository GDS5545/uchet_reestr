(function($){
    'use strict';
    const cfg = window.ZAULegacyImport || {};
    const state = {}; // scope -> { headers: [] }

    function message($section, text, type){
        const cls = type === 'error' ? 'notice-error' : 'notice-success';
        $section.find('> .zau-ui-message').remove();
        $('<div class="notice '+cls+' inline zau-ui-message"><p></p></div>').find('p').text(text).end().prependTo($section);
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
    function fieldsFor(scope){ return scope === 'accounts' ? (cfg.accountFields || {}) : (cfg.applicationFields || {}); }
    function targetSelect(scope, index, selected){
        const fields = fieldsFor(scope);
        let html = '<select class="zau-map-target" data-index="'+index+'">';
        Object.keys(fields).forEach(function(key){
            html += '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(fields[key])+'</option>';
        });
        return html + '</select>';
    }
    function renderMapping(scope, $section, resp){
        state[scope] = state[scope] || {};
        state[scope].headers = resp.headers || [];
        const preview = resp.preview || [];
        const auto = resp.auto_mapping || {};
        const $body = $section.find('[data-zau-mapping-table] tbody').empty();
        state[scope].headers.forEach(function(header, i){
            const samples = preview.map(r => (r[i] || '')).filter(Boolean).slice(0,2).join(' · ');
            $body.append('<tr><td><strong>'+esc(header || ('Колонка '+(i+1)))+'</strong></td><td class="zau-example">'+esc(samples)+'</td><td>'+targetSelect(scope, i, auto[String(i)] || '')+'</td></tr>');
        });
        $section.find('[data-zau-file-summary]').text(resp.file_name+' · строк: '+resp.total+' · колонок: '+state[scope].headers.length);
        const $mapping = $section.find('[data-zau-mapping]').prop('hidden', false);
        $mapping[0].scrollIntoView({behavior:'smooth',block:'start'});
    }
    function mapping($section){
        const map = {};
        $section.find('.zau-map-target').each(function(){ map[$(this).data('index')] = $(this).val(); });
        return map;
    }
    function statLabel(key){
        const labels={created:'Создано',updated:'Обновлено',existing:'Уже существовало',linked:'Привязано',skipped:'Пропущено',errors:'Ошибки',would_create:'Будет создано',would_update:'Будет обновлено'};
        return labels[key] || key;
    }
    function renderProgress(scope, $section, job){
        const total = Number(job.total || 0), done = Number(job.processed || 0), pct = total ? Math.min(100, Math.round(done/total*100)) : 0;
        $section.find('[data-zau-progress]').prop('hidden', false);
        $section.find('[data-zau-progress-bar]').css('width', pct+'%');
        $section.find('[data-zau-progress-text]').text(done+' из '+total+' · '+pct+'%'+(job.dry_run?' · режим проверки':''));
        const stats = job.stats || {};
        let html=''; Object.keys(stats).forEach(k => { if(Number(stats[k])) html += '<span><strong>'+stats[k]+'</strong>'+esc(statLabel(k))+'</span>'; });
        $section.find('[data-zau-stats]').html(html || '<span>Пока нет обработанных строк</span>');
        $section.find('[data-zau-log]').text((job.log || []).join('\n'));
        $section.find('[data-zau-report]').attr('href', cfg.reportUrl+'&scope='+scope);
        if(job.status === 'finished'){
            message($section, job.dry_run ? 'Проверка завершена. Данные не записывались.' : 'Перенос завершён.', 'success');
            return;
        }
        setTimeout(function(){ processBatch(scope, $section); }, 250);
    }
    function processBatch(scope, $section){
        ajax({action:'zau_legacy_process', scope:scope}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Ошибка обработки партии.', 'error'); return; }
            renderProgress(scope, $section, resp.data);
        }).fail(function(xhr){ message($section, 'Сервер остановил перенос: HTTP '+xhr.status+'. Нажмите кнопку переноса повторно — уже обработанные строки не задублируются.', 'error'); });
    }

    $('.zau-legacy-upload-form').on('submit', function(e){
        e.preventDefault();
        const scope = $(this).data('scope');
        const $section = $(this).closest('[data-zau-scope]');
        const fd = new FormData(this); fd.append('action','zau_legacy_upload'); fd.append('scope', scope);
        const $btn=$(this).find('button').prop('disabled',true).text('Читаем файл…');
        ajax(fd,true).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось прочитать файл.', 'error'); return; }
            message($section, resp.data.message, 'success'); renderMapping(scope, $section, resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка загрузки: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Загрузить CSV'); });
    });

    $(document).on('click','[data-zau-start]',function(){
        const $section = $(this).closest('[data-zau-scope]');
        const scope = $section.data('zau-scope');
        const dry = $(this).data('zau-start') === 'dry';
        if(scope === 'applications'){
            const formId = $section.find('[data-zau-form-id]').val();
            if(!formId){ message($section, 'Сначала выберите форму, к которой привязать заявления.', 'error'); return; }
        }
        const data = {
            action:'zau_legacy_start',
            scope:scope,
            mapping:JSON.stringify(mapping($section)),
            dry_run:dry?1:0,
            overwrite:$section.find('[name="overwrite"]').is(':checked') ? 1 : 0,
            default_status:$section.find('[name="default_status"]').val(),
            batch_size:$section.find('[name="batch_size"]').val(),
            form_id: scope === 'applications' ? $section.find('[data-zau-form-id]').val() : ''
        };
        $section.find('[data-zau-start]').prop('disabled',true);
        ajax(data).done(function(resp){
            $section.find('[data-zau-start]').prop('disabled',false);
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось запустить перенос.', 'error'); return; }
            $section.find('[data-zau-progress]')[0].scrollIntoView({behavior:'smooth',block:'start'});
            renderProgress(scope, $section, resp.data);
        }).fail(function(xhr){ $section.find('[data-zau-start]').prop('disabled',false); message($section, 'Ошибка запуска: HTTP '+xhr.status, 'error'); });
    });

    $(document).on('click','[data-zau-reset]',function(){
        const $section = $(this).closest('[data-zau-scope]');
        const scope = $section.data('zau-scope');
        if(!window.confirm('Сбросить загруженный файл и текущий прогресс? Уже перенесённые данные останутся.')) return;
        ajax({action:'zau_legacy_reset', scope:scope}).done(function(resp){
            if(resp && resp.success){ window.location.reload(); }
            else message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось сбросить.', 'error');
        });
    });

    /* ---- PDF привязка по уникальному ID ---- */
    function pdfParams($section){
        return {
            folder: $section.find('[data-zau-pdf-folder]').val(),
            card_pattern: $section.find('[data-zau-card-pattern]').val(),
            card_regex: $section.find('[data-zau-card-regex]').val(),
            card_template_id: $section.find('[data-zau-card-template]').val(),
            application_pattern: $section.find('[data-zau-application-pattern]').val(),
            application_regex: $section.find('[data-zau-application-regex]').val(),
            application_template_id: $section.find('[data-zau-application-template]').val()
        };
    }
    function roleLabel(role){ return role === 'card' ? 'личная карточка' : (role === 'application' ? 'заявление' : '—'); }
    function renderPdfScan($section, resp){
        const rows = resp.rows || [];
        let html = '<p>Найдено файлов: '+resp.total+' · личных карточек: '+resp.counts.card+' · заявлений: '+resp.counts.application
            +' · не подошло ни под одно правило: '+resp.counts.unmatched+' · не найдена цель для привязки: '+resp.counts.unresolved+'</p>';
        html += '<div class="zau-ui-table-wrap"><table class="widefat striped"><thead><tr><th>Файл</th><th>Тип</th><th>ID</th><th>Найдена цель</th></tr></thead><tbody>';
        rows.forEach(function(row){
            const cls = !row.role ? 'zau-pdf-row-unmatched' : (!row.resolved ? 'zau-pdf-row-unresolved' : '');
            html += '<tr class="'+cls+'"><td>'+esc(row.file)+'</td><td>'+esc(roleLabel(row.role))+'</td><td>'+esc(row.legacy_id)+'</td><td>'+(row.resolved?'да':'нет')+'</td></tr>';
        });
        html += '</tbody></table></div>';
        if(rows.length < resp.total){ html += '<p class="description">Показаны первые '+rows.length+' файлов из '+resp.total+'.</p>'; }
        $section.find('[data-zau-pdf-result]').html(html);
        $section.find('[data-zau-pdf-attach]').prop('hidden', resp.total === 0);
    }
    $(document).on('click','[data-zau-pdf-scan]',function(){
        const $section = $(this).closest('[data-zau-pdf]');
        const $btn = $(this).prop('disabled',true).text('Сканируем…');
        ajax(Object.assign({action:'zau_legacy_scan_pdfs'}, pdfParams($section))).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось просканировать папку.', 'error'); return; }
            renderPdfScan($section, resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка сканирования: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Просканировать папку'); });
    });
    function attachPdfBatch($section){
        ajax({action:'zau_legacy_attach_pdfs'}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Ошибка привязки PDF.', 'error'); return; }
            const d = resp.data;
            message($section, 'Привязано: '+d.attached+' · не найдена цель: '+d.unresolved+' · не подошло правило: '+d.unmatched+' · ошибки: '+d.errors+(d.remaining?(' · осталось файлов: '+d.remaining):''), d.errors ? 'error' : 'success');
            if(d.log && d.log.length){ $section.find('[data-zau-pdf-result]').prepend('<pre>'+esc(d.log.join('\n'))+'</pre>'); }
            if(d.remaining > 0){ setTimeout(function(){ attachPdfBatch($section); }, 300); }
            else { $section.find('[data-zau-pdf-scan]').trigger('click'); }
        }).fail(function(xhr){ message($section, 'Сервер остановил привязку: HTTP '+xhr.status+'. Нажмите «Привязать найденные PDF» повторно — уже обработанные файлы не задублируются.', 'error'); });
    }
    $(document).on('click','[data-zau-pdf-attach]',function(){
        const $section = $(this).closest('[data-zau-pdf]');
        $(this).prop('disabled',true);
        attachPdfBatch($section);
        setTimeout(function(){ $section.find('[data-zau-pdf-attach]').prop('disabled',false); }, 500);
    });
})(jQuery);
