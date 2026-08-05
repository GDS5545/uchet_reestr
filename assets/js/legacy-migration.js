(function($){
    'use strict';
    var running=false;
    var $wrap,$bar,$text,$log;

    function init(){
        $wrap=$('[data-zau-legacy-progress-wrap]');
        $bar=$('[data-zau-legacy-progress-bar]');
        $text=$('[data-zau-legacy-progress-text]');
        $log=$('[data-zau-legacy-log]');
        $(document).on('click','[data-zau-legacy-start]',start);
        $(document).on('click','[data-zau-legacy-reset]',reset);
    }

    function post(action,data){
        data=data||{};
        data.action=action;
        data.nonce=ZAULegacyMigration.nonce;
        return $.ajax({url:ZAULegacyMigration.ajaxUrl,method:'POST',data:data,dataType:'json',timeout:180000});
    }

    function formDataObject(){
        var data={};
        $('#zau-legacy-form').serializeArray().forEach(function(item){
            if(item.name==='form_ids[]'){
                data.form_ids=data.form_ids||[];
                data.form_ids.push(item.value);
            }else{
                data[item.name]=item.value;
            }
        });
        ['import_entries','import_pdfs','create_missing_users','update_user_profiles'].forEach(function(name){
            if(!Object.prototype.hasOwnProperty.call(data,name)){data[name]='';}
        });
        return data;
    }

    function start(e){
        e.preventDefault();
        if(running){return;}
        var mode=$(this).data('zau-legacy-start');
        var message=mode==='import'?'Начать перенос? Старые файлы не удаляются, но новые строки будут добавлены в реестр.':'Запустить безопасный анализ без записи данных?';
        if(!window.confirm(message)){return;}
        running=true;
        toggleButtons(true);
        $wrap.prop('hidden',false);
        $text.text('Подготовка списка данных…');
        $log.text('');
        var data=formDataObject();
        data.mode=mode;
        post('zau_legacy_start',data).done(function(response){
            if(!response.success){fail(response.data&&response.data.message?response.data.message:'Не удалось начать.');return;}
            render(response.data.state);
            stepEntries();
        }).fail(xhrFail);
    }

    function stepEntries(){
        post('zau_legacy_step_entries',{}).done(function(response){
            if(!response.success){fail(response.data&&response.data.message?response.data.message:'Ошибка обработки WPForms.');return;}
            render(response.data.state);
            if(response.data.phase_done){stepDocuments();}
            else{window.setTimeout(stepEntries,120);}
        }).fail(xhrFail);
    }

    function stepDocuments(){
        post('zau_legacy_step_documents',{}).done(function(response){
            if(!response.success){fail(response.data&&response.data.message?response.data.message:'Ошибка обработки PDF.');return;}
            render(response.data.state);
            if(response.data.phase_done){
                running=false;
                toggleButtons(false);
                $text.append(' Готово.');
            }else{window.setTimeout(stepDocuments,120);}
        }).fail(xhrFail);
    }

    function render(state){
        state=state||{};
        var entryTotal=parseInt(state.entry_total||0,10);
        var docTotal=parseInt(state.document_total||0,10);
        var all=Math.max(1,entryTotal+docTotal);
        var done=Math.min(entryTotal,parseInt(state.entry_processed||0,10))+Math.min(docTotal,parseInt(state.document_processed||0,10));
        var percent=Math.max(0,Math.min(100,Math.round(done/all*100)));
        if(state.phase==='done'){percent=100;}
        $bar.css('width',percent+'%');
        $text.text((state.message||'Обработка')+' · '+percent+'% · заявок: '+(state.imported_entries||0)+' · PDF: '+(state.imported_documents||0)+' · пропущено: '+(state.skipped||0)+' · ошибок: '+(state.errors||0));
        $log.text((state.log||[]).join('\n'));
        if($log.length){$log.scrollTop($log[0].scrollHeight);}
    }

    function reset(e){
        e.preventDefault();
        if(running||!window.confirm('Сбросить только текущий прогресс? Уже перенесённые записи останутся.')){return;}
        post('zau_legacy_reset_state',{}).done(function(response){
            if(response.success){$wrap.prop('hidden',true);$bar.css('width','0');$text.text('');$log.text('');}
            else{window.alert(response.data&&response.data.message?response.data.message:'Не удалось сбросить прогресс.');}
        }).fail(xhrFail);
    }

    function toggleButtons(disabled){
        $('#zau-legacy-form button').prop('disabled',disabled);
    }

    function xhrFail(xhr){
        var message='Сервер не завершил запрос.';
        if(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message){message=xhr.responseJSON.data.message;}
        fail(message);
    }

    function fail(message){
        running=false;
        toggleButtons(false);
        $wrap.prop('hidden',false);
        $text.text(message);
        $log.append(($log.text()?'\n':'')+'ОШИБКА: '+message);
    }

    $(init);
})(jQuery);
