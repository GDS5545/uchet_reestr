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
            form_id: scope === 'applications' ? $section.find('[data-zau-form-id]').val() : '',
            template_id: scope === 'applications' ? $section.find('[data-zau-csv-template]').val() : ''
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

    /* ---- Восстановление полей уже загруженных заявок ---- */
    function remapTargetSelect(rawKey){
        let html = '<select class="zau-map-target" data-remap-key="'+esc(rawKey)+'"><option value="">— не сопоставлять —</option>';
        const fields = cfg.applicationFields || {};
        Object.keys(fields).forEach(function(key){
            if(!key || key === 'legacy_entry_id' || key === 'legacy_user_id') return;
            html += '<option value="'+esc(key)+'">'+esc(fields[key])+'</option>';
        });
        return html + '</select>';
    }
    function renderRemapMapping($section, resp){
        const $body = $section.find('[data-zau-remap-table]').empty();
        const candidates = resp.candidates || {};
        Object.keys(candidates).forEach(function(rawKey){
            const c = candidates[rawKey] || {};
            $body.append('<tr><td><strong>'+esc(rawKey)+'</strong></td><td class="zau-example">'+esc((c.examples||[]).join(' · '))+'</td><td>'+(c.count||0)+'</td><td>'+remapTargetSelect(rawKey)+'</td></tr>');
        });
        $section.find('[data-zau-remap-mapping]').prop('hidden', Object.keys(candidates).length === 0);
        if(!Object.keys(candidates).length){ message($section, 'В этой форме не нашлось полей, требующих сопоставления.', 'success'); }
    }
    function remapMapping($section){
        const map = {};
        $section.find('[data-remap-key]').each(function(){
            const key = $(this).data('remap-key');
            const val = $(this).val();
            if(val) map[key] = val;
        });
        return map;
    }
    function remapStatLabel(key){
        const labels = {submissions_updated:'Заявок обновлено',fields_filled:'Полей заполнено',documents_updated:'Документов обновлено',accounts_updated:'Аккаунтов участников обновлено',contacts_updated:'Email/телефон обновлено',contacts_conflict:'Email/телефон уже занят другим (пропущено)',unchanged:'Без изменений',skipped_suspicious:'Пропущено подозрительных',errors:'Ошибки'};
        return labels[key] || key;
    }
    function renderRemapProgress($section, job){
        const total = Number(job.total||0), done = Number(job.processed||0), pct = total ? Math.min(100, Math.round(done/total*100)) : 0;
        $section.find('[data-zau-remap-progress]').prop('hidden', false);
        $section.find('[data-zau-progress-bar]').css('width', pct+'%');
        $section.find('[data-zau-progress-text]').text(done+' из '+total+' · '+pct+'%'+(job.dry_run?' · режим проверки':''));
        const stats = job.stats || {};
        let html=''; Object.keys(stats).forEach(function(k){ if(Number(stats[k])) html += '<span><strong>'+stats[k]+'</strong>'+esc(remapStatLabel(k))+'</span>'; });
        $section.find('[data-zau-stats]').html(html || '<span>Пока нет изменений</span>');
        $section.find('[data-zau-log]').text((job.log||[]).join('\n'));
        if(job.status === 'finished'){
            message($section, job.dry_run ? 'Проверка завершена. Данные не записывались.' : 'Восстановление завершено.', 'success');
            const ids = job.changed_document_ids || [];
            const $hint = $section.find('[data-zau-remap-recreate-hint]');
            if(!job.dry_run && ids.length){
                const urlIds = ids.slice(0, 300);
                const idList = ids.join(', ');
                const url = cfg.bulkRegenerateUrl + '&document_ids=' + encodeURIComponent(urlIds.join(','));
                const truncNote = ids.length > urlIds.length ? ' (в ссылку подставлены первые '+urlIds.length+' — остальные скопируйте из списка ниже вручную в поле «ID документов»)' : '';
                $hint.html('<div class="notice notice-warning inline"><p>У '+ids.length+' документ(ов) изменились данные (ФИО/организация) — PDF-файл ещё содержит старые данные. '
                    +'<a class="button button-primary" href="'+esc(url)+'" target="_blank">Открыть «Массовое пересоздание» с этим списком</a>'+truncNote+'</p>'
                    +'<p class="description">ID документов: '+esc(idList)+'</p></div>').prop('hidden', false);
            } else {
                $hint.empty().prop('hidden', true);
            }
            return;
        }
        setTimeout(function(){ remapProcessBatch($section); }, 250);
    }
    function remapProcessBatch($section){
        ajax({action:'zau_legacy_remap_process'}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Ошибка обработки партии.', 'error'); return; }
            renderRemapProgress($section, resp.data);
        }).fail(function(xhr){ message($section, 'Сервер остановил обработку: HTTP '+xhr.status+'. Нажмите «Исправить данные» повторно — уже обработанные заявки не задублируются.', 'error'); });
    }
    $(document).on('click','[data-zau-reset-opcache]',function(){
        const $section = $(this).closest('[data-zau-remap]');
        const $btn = $(this).prop('disabled',true).text('Сбрасываем…');
        ajax({action:'zau_legacy_reset_opcache'}).done(function(resp){
            message($section, resp && resp.data && resp.data.message ? resp.data.message : (resp && resp.success ? 'Готово.' : 'Не удалось сбросить кэш.'), resp && resp.success ? 'success' : 'error');
        }).fail(function(xhr){ message($section, 'Ошибка: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Сбросить кэш PHP на сервере'); });
    });
    $(document).on('click','[data-zau-remap-scan]',function(){
        const $section = $(this).closest('[data-zau-remap]');
        const formId = $section.find('[data-zau-remap-form]').val();
        if(!formId){ message($section, 'Выберите форму для проверки.', 'error'); return; }
        const $btn = $(this).prop('disabled',true).text('Проверяем…');
        ajax({action:'zau_legacy_remap_scan', form_id:formId}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось проверить форму.', 'error'); return; }
            message($section, resp.data.message, 'success');
            renderRemapMapping($section, resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка проверки: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Проверить форму'); });
    });
    $(document).on('click','[data-zau-remap-start]',function(){
        const $section = $(this).closest('[data-zau-remap]');
        const dry = $(this).data('zau-remap-start') === 'dry';
        const map = remapMapping($section);
        if(!Object.keys(map).length){ message($section, 'Сопоставьте хотя бы одно поле в таблице выше.', 'error'); return; }
        const data = {
            action:'zau_legacy_remap_start',
            mapping: JSON.stringify(map),
            dry_run: dry?1:0,
            overwrite: $section.find('[data-zau-remap-overwrite]').is(':checked') ? 1 : 0,
            sync_documents: $section.find('[data-zau-remap-sync]').is(':checked') ? 1 : 0,
            sync_contacts: $section.find('[data-zau-remap-sync-contacts]').is(':checked') ? 1 : 0,
            exclude_values: $section.find('[data-zau-remap-exclude]').val() || '',
        };
        $section.find('[data-zau-remap-start]').prop('disabled',true);
        ajax(data).done(function(resp){
            $section.find('[data-zau-remap-start]').prop('disabled',false);
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось запустить.', 'error'); return; }
            $section.find('[data-zau-remap-progress]')[0].scrollIntoView({behavior:'smooth',block:'start'});
            renderRemapProgress($section, resp.data);
        }).fail(function(xhr){ $section.find('[data-zau-remap-start]').prop('disabled',false); message($section, 'Ошибка запуска: HTTP '+xhr.status, 'error'); });
    });

    /* ---- Дубликаты аккаунтов (по ИИН и email) ---- */
    function reasonsLabel(reasons){
        const labels = { 'ИИН':'ИИН', 'email':'email' };
        return (reasons || []).map(r => labels[r] || r).join(' + ') || 'совпадение';
    }
    function renderDuplicateGroups($section, groups){
        let html = '';
        groups.forEach(function(group, gi){
            html += '<div class="zau-dup-group" data-zau-dup-group="'+gi+'">';
            html += '<h4>Совпадение по: '+esc(reasonsLabel(group.reasons))+'</h4>';
            html += '<div class="zau-ui-table-wrap"><table class="widefat striped"><thead><tr>'
                +'<th>Оставить (главный)</th><th>Объединить (скрыть)</th><th>ID</th><th>Имя</th><th>Email</th>'
                +'<th>Телефон</th><th>ИИН</th><th>Регистрация</th><th>Заявок</th><th>Документов</th><th>Скрыт сейчас</th>'
                +'</tr></thead><tbody>';
            (group.users || []).forEach(function(u, ui){
                html += '<tr>';
                html += '<td><input type="radio" class="zau-dup-keep" name="zau-dup-keep-'+gi+'" value="'+esc(u.id)+'"'+(ui===0?' checked':'')+'></td>';
                html += '<td><input type="checkbox" class="zau-dup-merge" value="'+esc(u.id)+'"'+(ui!==0?' checked':'')+(ui===0?' disabled':'')+'></td>';
                html += '<td>#'+esc(u.id)+'</td>';
                html += '<td>'+esc(u.display_name)+'</td>';
                html += '<td>'+esc(u.email)+'</td>';
                html += '<td>'+esc(u.phone)+'</td>';
                html += '<td>'+esc(u.iin_masked)+'</td>';
                html += '<td>'+esc(u.registered)+'</td>';
                html += '<td>'+esc(u.submissions)+'</td>';
                html += '<td>'+esc(u.documents)+'</td>';
                html += '<td>'+(u.hidden ? 'да' : 'нет')+'</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="zau-ui-actions"><button type="button" class="button button-primary" data-zau-dup-merge-btn>Объединить выбранные аккаунты в главный</button></div>';
            html += '<p data-zau-dup-group-message></p>';
            html += '</div>';
        });
        $section.find('[data-zau-dup-groups]').html(html || '<p>Дубликаты по ИИН/email не найдены.</p>');
    }
    $(document).on('change','.zau-dup-keep',function(){
        const $group = $(this).closest('[data-zau-dup-group]');
        const keepId = $(this).val();
        $group.find('.zau-dup-merge').each(function(){
            const isKeep = String($(this).val()) === String(keepId);
            $(this).prop('disabled', isKeep);
            if(isKeep){ $(this).prop('checked', false); }
        });
    });
    $(document).on('click','[data-zau-dup-scan]',function(){
        const $section = $(this).closest('[data-zau-duplicates]');
        const $btn = $(this).prop('disabled',true).text('Ищем…');
        ajax({action:'zau_legacy_duplicates_scan'}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось найти дубликаты.', 'error'); return; }
            const groups = resp.data.groups || [];
            $section.find('[data-zau-dup-message]').text(groups.length ? ('Найдено групп дубликатов: '+groups.length) : 'Дубликаты по ИИН/email не найдены — всё чисто.');
            renderDuplicateGroups($section, groups);
        }).fail(function(xhr){ message($section, 'Ошибка поиска: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Найти дубликаты'); });
    });
    $(document).on('click','[data-zau-dup-merge-btn]',function(){
        const $group = $(this).closest('[data-zau-dup-group]');
        const $section = $(this).closest('[data-zau-duplicates]');
        const keepId = $group.find('.zau-dup-keep:checked').val();
        const mergeIds = [];
        $group.find('.zau-dup-merge:checked').each(function(){ mergeIds.push($(this).val()); });
        const $msg = $group.find('[data-zau-dup-group-message]');
        if(!keepId){ $msg.css('color','#b32d2e').text('Выберите главный аккаунт.'); return; }
        if(!mergeIds.length){ $msg.css('color','#b32d2e').text('Отметьте хотя бы один дублирующийся аккаунт.'); return; }
        if(!window.confirm('Перенести все заявки и документы на аккаунт #'+keepId+' и скрыть остальные ('+mergeIds.length+') из реестра и списков? Данные не удаляются, но скрытые аккаунты перестанут находиться в поиске.')) return;
        const $btn = $(this).prop('disabled',true).text('Объединяем…');
        ajax({action:'zau_legacy_duplicates_merge', keep_id:keepId, merge_ids:mergeIds}).done(function(resp){
            if(!resp || !resp.success){ $msg.css('color','#b32d2e').text(resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось объединить.'); $btn.prop('disabled',false).text('Объединить выбранные аккаунты в главный'); return; }
            $msg.css('color','#1a7f37').text(resp.data.message);
            $group.find('table').css('opacity','0.5');
            $btn.text('Готово');
        }).fail(function(xhr){ $msg.css('color','#b32d2e').text('Ошибка: HTTP '+xhr.status); $btn.prop('disabled',false).text('Объединить выбранные аккаунты в главный'); });
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
    /* ---- Подключение к старому сайту по API ---- */
    let bridgeForms = [];

    $(document).on('click','[data-zau-bridge-save-settings]',function(){
        const $section = $(this).closest('[data-zau-bridge]');
        const url = $section.find('[data-zau-bridge-url]').val();
        const secret = $section.find('[data-zau-bridge-secret]').val();
        if(!url || !secret){ message($section, 'Укажите адрес старого сайта и секретный ключ.', 'error'); return; }
        const $btn = $(this).prop('disabled',true).text('Сохраняем…');
        ajax({action:'zau_legacy_bridge_save_settings', url:url, secret:secret}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось сохранить.', 'error'); return; }
            message($section, resp.data.message, 'success');
        }).fail(function(xhr){ message($section, 'Ошибка сохранения: HTTP '+xhr.status+(xhr.responseText ? (' · '+String(xhr.responseText).slice(0,200)) : ''), 'error'); }).always(function(){ $btn.prop('disabled',false).text('Сохранить подключение'); });
    });

    $(document).on('click','[data-zau-bridge-test]',function(){
        const $section = $(this).closest('[data-zau-bridge]');
        const $btn = $(this).prop('disabled',true).text('Проверяем…');
        ajax({action:'zau_legacy_bridge_test'}).done(function(resp){
            if(!resp || !resp.success){ $section.find('[data-zau-bridge-test-result]').text((resp && resp.data && resp.data.message) || 'Не удалось подключиться.'); return; }
            const d = resp.data;
            $section.find('[data-zau-bridge-test-result]').text('Подключено: '+d.site+' · WordPress '+d.wp_version+' · Ultimate Member: '+(d.um_active?'да':'нет')+' · WPForms: '+(d.wpforms_active?'да':'нет')+' · пользователей: '+d.user_count+' · форм: '+d.forms_count);
        }).fail(function(xhr){ $section.find('[data-zau-bridge-test-result]').text('Ошибка проверки: HTTP '+xhr.status+(xhr.responseText ? (' · '+String(xhr.responseText).slice(0,200)) : '')); }).always(function(){ $btn.prop('disabled',false).text('Проверить подключение'); });
    });

    function fieldMapSelect(index, selected){
        let html = '<select class="zau-map-target" data-index="'+esc(index)+'">';
        Object.keys(cfg.applicationFields || {}).forEach(function(key){
            html += '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(cfg.applicationFields[key])+'</option>';
        });
        return html + '</select>';
    }
    function renderBridgeForms($section, resp){
        bridgeForms = resp.forms || [];
        let html = '';
        bridgeForms.forEach(function(form, formIndex){
            html += '<div class="zau-ui-card" data-zau-bridge-form-card data-form-id="'+esc(form.id)+'" data-form-index="'+formIndex+'">';
            html += '<h4>'+esc(form.title)+' · записей: '+form.entry_count+'</h4>';
            html += '<div class="zau-ui-grid">';
            html += '<label>Форма нового кабинета<select data-zau-bridge-target-form><option value="">— выберите —</option>';
            (resp.new_forms || []).forEach(function(f){ html += '<option value="'+f.id+'"'+(Number(form.target_form_id)===Number(f.id)?' selected':'')+'>'+esc(f.name)+'</option>'; });
            html += '</select></label>';
            html += '<label>PDF-шаблон<select data-zau-bridge-target-template><option value="0">— выберите —</option>';
            (resp.templates || []).forEach(function(t){ html += '<option value="'+t.id+'"'+(Number(form.template_id)===Number(t.id)?' selected':'')+'>'+esc(t.name)+'</option>'; });
            html += '</select></label></div>';
            html += '<div class="zau-ui-table-wrap"><table class="widefat striped"><thead><tr><th>Поле старой формы</th><th>Тип</th><th>Поле нового кабинета</th></tr></thead><tbody>';
            (form.fields || []).forEach(function(field){
                html += '<tr><td>'+esc(field.label)+'</td><td>'+esc(field.type)+'</td><td>'+fieldMapSelect(field.label, (form.field_map || {})[field.label] || '')+'</td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="zau-ui-actions">';
            html += '<button type="button" class="button button-primary" data-zau-bridge-save-form>Сохранить соответствие</button>';
            html += '<button type="button" class="button button-secondary" data-zau-bridge-start-application data-mode="dry">Только проверить</button>';
            html += '<button type="button" class="button button-primary" data-zau-bridge-start-application data-mode="import">Перенести заявления по этой форме</button>';
            html += '</div></div>';
        });
        $section.find('[data-zau-bridge-forms]').html(html || '<p>Старый сайт не вернул ни одной формы.</p>');
    }

    $(document).on('click','[data-zau-bridge-load-forms]',function(){
        const $section = $(this).closest('[data-zau-bridge]');
        const $btn = $(this).prop('disabled',true).text('Загружаем…');
        ajax({action:'zau_legacy_bridge_list_forms'}).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось загрузить список форм.', 'error'); return; }
            renderBridgeForms($section, resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка загрузки списка форм: HTTP '+xhr.status, 'error'); }).always(function(){ $btn.prop('disabled',false).text('Загрузить список форм со старого сайта'); });
    });

    $(document).on('click','[data-zau-bridge-save-form]',function(){
        const $card = $(this).closest('[data-zau-bridge-form-card]');
        const $section = $(this).closest('[data-zau-bridge]');
        const fieldMap = {};
        $card.find('.zau-map-target').each(function(){ const v = $(this).val(); if(v){ fieldMap[$(this).data('index')] = v; } });
        ajax({
            action:'zau_legacy_bridge_save_form_map',
            form_id:$card.data('form-id'),
            target_form_id:$card.find('[data-zau-bridge-target-form]').val(),
            template_id:$card.find('[data-zau-bridge-target-template]').val(),
            field_map:JSON.stringify(fieldMap)
        }).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось сохранить соответствие.', 'error'); return; }
            message($section, resp.data.message, 'success');
        }).fail(function(xhr){ message($section, 'Ошибка сохранения соответствия: HTTP '+xhr.status, 'error'); });
    });

    function bridgeStatLabel(key){ return statLabel(key); }
    function renderBridgeProgress(scope, $section, formId, job){
        const $panel = $section.find('[data-zau-bridge-progress="'+scope+'"]');
        const total = Number(job.total || 0), done = Number(job.processed || 0), pct = total ? Math.min(100, Math.round(done/total*100)) : 0;
        $panel.prop('hidden', false);
        $panel.find('[data-zau-progress-bar]').css('width', pct+'%');
        $panel.find('[data-zau-progress-text]').text(done+' из '+total+' · '+pct+'%'+(job.dry_run?' · режим проверки':''));
        const stats = job.stats || {};
        let html=''; Object.keys(stats).forEach(k => { if(Number(stats[k])) html += '<span><strong>'+stats[k]+'</strong>'+esc(bridgeStatLabel(k))+'</span>'; });
        $panel.find('[data-zau-stats]').html(html || '<span>Пока нет обработанных записей</span>');
        $panel.find('[data-zau-log]').text((job.log || []).join('\n'));
        if(job.status === 'finished'){
            message($section, job.dry_run ? 'Проверка по API завершена. Данные не записывались.' : 'Перенос по API завершён.', 'success');
            return;
        }
        setTimeout(function(){ bridgeProcessBatch(scope, $section, formId); }, 300);
    }
    function bridgeProcessBatch(scope, $section, formId){
        const data = {action:'zau_legacy_bridge_process', scope:scope};
        if(scope === 'applications'){ data.form_id = formId; }
        ajax(data).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Ошибка обработки партии по API.', 'error'); return; }
            renderBridgeProgress(scope, $section, formId, resp.data);
        }).fail(function(xhr){ message($section, 'Сервер остановил перенос по API: HTTP '+xhr.status+'. Нажмите кнопку переноса повторно — уже обработанные записи не задублируются.', 'error'); });
    }

    $(document).on('click','[data-zau-bridge-start]',function(){
        const $section = $(this).closest('[data-zau-bridge]');
        const [scope, mode] = String($(this).data('zau-bridge-start')).split(':');
        const dry = mode === 'dry';
        const data = {action:'zau_legacy_bridge_start', scope:scope, dry_run:dry?1:0, overwrite:$section.find('[data-zau-bridge-overwrite]').is(':checked')?1:0, default_status:'Состоит в профсоюзе'};
        ajax(data).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось запустить перенос по API.', 'error'); return; }
            $section.find('[data-zau-bridge-progress="'+scope+'"]')[0].scrollIntoView({behavior:'smooth',block:'start'});
            renderBridgeProgress(scope, $section, '', resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка запуска: HTTP '+xhr.status, 'error'); });
    });

    $(document).on('click','[data-zau-bridge-start-application]',function(){
        const $card = $(this).closest('[data-zau-bridge-form-card]');
        const $section = $(this).closest('[data-zau-bridge]');
        const formId = $card.data('form-id');
        const dry = $(this).data('mode') === 'dry';
        const targetFormId = $card.find('[data-zau-bridge-target-form]').val();
        const templateId = $card.find('[data-zau-bridge-target-template]').val();
        if(!targetFormId || !templateId){ message($section, 'Сначала сохраните соответствие: выберите форму нового кабинета и PDF-шаблон.', 'error'); return; }
        const data = {action:'zau_legacy_bridge_start', scope:'applications', form_id:formId, dry_run:dry?1:0};
        ajax(data).done(function(resp){
            if(!resp || !resp.success){ message($section, resp && resp.data && resp.data.message ? resp.data.message : 'Не удалось запустить перенос заявлений по API.', 'error'); return; }
            $section.find('[data-zau-bridge-progress="applications"]')[0].scrollIntoView({behavior:'smooth',block:'start'});
            renderBridgeProgress('applications', $section, formId, resp.data);
        }).fail(function(xhr){ message($section, 'Ошибка запуска: HTTP '+xhr.status, 'error'); });
    });
})(jQuery);
