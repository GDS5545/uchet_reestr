(function(){
'use strict';
const App=window.ZAUUnionData||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const ORG_DERIVED_KEYS=['organization_bin','organization','organization_name_ru','organization_name_kz','organization_director','organization_address','organization_address_ru','organization_address_kz','organization_status','organization_status_code','organization_type','organization_type_code','organization_registration_date','organization_oked','organization_oked_name','organization_kato'];
let nonceRefreshPromise=null;
async function refreshNonce(){
 if(nonceRefreshPromise)return nonceRefreshPromise;
 nonceRefreshPromise=(async()=>{
  const body=new URLSearchParams();body.set('action','zau_union_refresh_nonce');body.set('_',String(Date.now()));
  const r=await fetch(App.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','Cache-Control':'no-cache'},body:body.toString(),credentials:'same-origin',cache:'no-store'});
  const j=await r.json().catch(()=>null);
  if(!r.ok||!j||!j.success||!j.data?.nonce)throw new Error(j?.data?.message||'Не удалось обновить защитный код формы. Обновите страницу.');
  App.nonce=String(j.data.nonce);App.isLoggedIn=!!j.data.logged_in;return App.nonce;
 })().finally(()=>{nonceRefreshPromise=null;});
 return nonceRefreshPromise;
}
function responseError(response,json,fallback){const data=(json&&typeof json.data==='object'&&json.data)?json.data:{};const err=new Error(data.message||fallback);err.data=data;err.status=response.status;err.code=data.code||'';err.field=data.field||'';return err;}
async function ajax(action,payload,retry=true){
 const body=new URLSearchParams();body.set('action',action);body.set('nonce',App.nonce||'');Object.entries(payload||{}).forEach(([k,v])=>body.set(k,v==null?'':String(v)));
 const r=await fetch(App.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','Cache-Control':'no-cache'},body:body.toString(),credentials:'same-origin',cache:'no-store'});
 const text=await r.text();let j=null;try{j=JSON.parse(text);}catch(e){}
 if((r.status===403||text.trim()==='-1')&&retry){await refreshNonce();return ajax(action,payload,false);}
 if(!r.ok||!j||!j.success)throw responseError(r,j,(r.status===403)?'Защитный код формы устарел. Обновите страницу и повторите отправку.':'Ошибка сервера: '+r.status);return j.data;
}
async function ajaxForm(action,formData,retry=true){
 formData.set('action',action);formData.set('nonce',App.nonce||'');
 const r=await fetch(App.ajaxUrl,{method:'POST',body:formData,credentials:'same-origin',cache:'no-store'});
 const text=await r.text();let j=null;try{j=JSON.parse(text);}catch(e){}
 if((r.status===403||text.trim()==='-1')&&retry){await refreshNonce();return ajaxForm(action,formData,false);}
 if(!r.ok||!j||!j.success)throw responseError(r,j,(r.status===403)?'Защитный код формы устарел. Обновите страницу и повторите отправку.':'Ошибка сервера: '+r.status);return j.data;
}
function initAuth(scope=document){
 const roots=[];
 if(scope&&scope.matches&&scope.matches('[data-zau-auth]'))roots.push(scope);
 if(scope&&scope.querySelectorAll)scope.querySelectorAll('[data-zau-auth]').forEach(root=>roots.push(root));
 roots.forEach(root=>{
  if(root.dataset.zauAuthReady==='1')return;root.dataset.zauAuthReady='1';
  const msg=root.querySelector('[data-zau-auth-message]');
  const set=(text,error=false)=>{if(!msg)return;msg.textContent=text||'';msg.classList.toggle('is-error',error);msg.classList.toggle('is-success',!!text&&!error);};
  const go=data=>{set(data.message||'Готово.');location.href=data.redirect||root.dataset.returnUrl||App.cabinetUrl||App.formUrl||location.href;};
  const allowedChannels=(root.dataset.otpChannels||'email,phone').split(',').filter(Boolean);
  const validDestination=value=>{const v=String(value||'').trim(),isEmail=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),digits=v.replace(/\D/g,''),isPhone=digits.length===10||digits.length===11;return (isEmail&&allowedChannels.includes('email'))||(isPhone&&allowedChannels.includes('phone'));};

  const flowPanels=[...root.querySelectorAll('[data-zau-auth-flow-panel]')],flowTabs=[...root.querySelectorAll('[data-zau-auth-flow-tab]')];
  const activateFlow=flow=>{const choosing=flow==='choice';root.classList.toggle('is-choosing',choosing);root.classList.toggle('is-flow-selected',!choosing);flowPanels.forEach(panel=>{const active=!choosing&&panel.dataset.zauAuthFlowPanel===flow;panel.hidden=!active;panel.classList.toggle('is-active',active);});flowTabs.forEach(tab=>{const active=!choosing&&tab.dataset.zauAuthFlowTab===flow;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',active?'true':'false');});set('');if(!choosing)setTimeout(()=>{if(flow==='register')root.querySelector('[data-zau-union-form] input:not([type="hidden"]),[data-zau-register-destination]')?.focus();else root.querySelector('[data-zau-auth-panel]:not([hidden]) input')?.focus();},0);};
  flowTabs.forEach(tab=>tab.addEventListener('click',()=>activateFlow(tab.dataset.zauAuthFlowTab)));
  root.querySelectorAll('[data-zau-back-to-auth-choice]').forEach(button=>button.addEventListener('click',()=>activateFlow('choice')));
  root.querySelectorAll('[data-zau-open-auth-flow]').forEach(button=>button.addEventListener('click',()=>activateFlow(button.dataset.zauOpenAuthFlow)));
  const configuredFlow=root.dataset.authFlow||'combined';
  activateFlow(configuredFlow==='combined'?(root.dataset.defaultFlow||'choice'):configuredFlow);

  const panels=[...root.querySelectorAll('[data-zau-auth-panel]')],tabs=[...root.querySelectorAll('[data-zau-auth-tab]')];
  const activate=method=>{panels.forEach(panel=>{const active=panel.dataset.zauAuthPanel===method;panel.hidden=!active;panel.classList.toggle('is-active',active);});tabs.forEach(tab=>{const active=tab.dataset.zauAuthTab===method;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',active?'true':'false');});set('');};
  tabs.forEach(tab=>tab.addEventListener('click',()=>activate(tab.dataset.zauAuthTab)));
  root.querySelectorAll('[data-zau-switch-method]').forEach(button=>button.addEventListener('click',()=>activate(button.dataset.zauSwitchMethod)));
  const available=(root.dataset.methods||'').split(',').filter(Boolean);if(panels.length)activate(root.dataset.defaultMethod||available[0]||'otp');

  function bindOtp({destination,code,send,verify,change,first,second,intent,label,purpose}){
   if(send&&destination){send.addEventListener('click',async()=>{if(!validDestination(destination.value)){set('Введите корректный email или номер телефона.',true);destination.focus();return;}send.disabled=true;set('Отправляем код…');try{const d=await ajax('zau_union_send_otp',{destination:destination.value,purpose:purpose||intent});set(d.message);if(first)first.hidden=true;if(second)second.hidden=false;if(label)label.textContent=destination.value.trim();code?.focus();}catch(e){set(e.message,true);}finally{send.disabled=false;}});destination.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();send.click();}});}
   if(verify&&code){verify.addEventListener('click',async()=>{const clean=code.value.replace(/\D/g,'');if(clean.length!==6){set('Введите шестизначный код.',true);code.focus();return;}verify.disabled=true;set(intent==='register'?'Подтверждаем регистрацию…':'Проверяем код…');try{const target=intent==='register'?(root.dataset.registerReturnUrl||root.dataset.returnUrl||''):(root.dataset.loginReturnUrl||root.dataset.returnUrl||'');go(await ajax('zau_union_verify_otp',{destination:destination?.value||'',code:clean,intent,return_url:target}));}catch(e){set(e.message,true);}finally{verify.disabled=false;}});code.addEventListener('input',()=>{code.value=code.value.replace(/\D/g,'').slice(0,6);});code.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();verify.click();}});}
   change?.addEventListener('click',()=>{if(second)second.hidden=true;if(first)first.hidden=false;if(code)code.value='';set('');destination?.focus();});
  }

  bindOtp({
   destination:root.querySelector('[data-zau-register-destination]'),code:root.querySelector('[data-zau-register-code]'),send:root.querySelector('[data-zau-register-send-code]'),verify:root.querySelector('[data-zau-register-verify-code]'),change:root.querySelector('[data-zau-register-change-destination]'),first:root.querySelector('[data-register-step="destination"]'),second:root.querySelector('[data-register-step="code"]'),label:root.querySelector('[data-zau-register-destination-label]'),intent:'register',purpose:'register'
  });
  bindOtp({
   destination:root.querySelector('[data-zau-destination]'),code:root.querySelector('[data-zau-code]'),send:root.querySelector('[data-zau-send-code]'),verify:root.querySelector('[data-zau-verify-code]'),change:root.querySelector('[data-zau-change-destination]'),first:root.querySelector('[data-step="destination"]'),second:root.querySelector('[data-step="code"]'),intent:'login',purpose:'login'
  });

  const passwordId=root.querySelector('[data-zau-password-identifier]'),password=root.querySelector('[data-zau-password]'),passwordButton=root.querySelector('[data-zau-password-login]'),remember=root.querySelector('[data-zau-password-remember]');
  if(passwordButton){const login=async()=>{if(!passwordId?.value.trim()||!password?.value){set('Введите логин/email и пароль.',true);return;}passwordButton.disabled=true;set('Проверяем логин и пароль…');try{go(await ajax('zau_union_password_login',{identifier:passwordId.value,password:password.value,remember:remember?.checked?'1':'',return_url:root.dataset.loginReturnUrl||root.dataset.returnUrl||''}));}catch(e){set(e.message,true);}finally{passwordButton.disabled=false;}};passwordButton.addEventListener('click',login);[passwordId,password].forEach(el=>el?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();login();}}));}

  const pinId=root.querySelector('[data-zau-pin-identifier]'),pin=root.querySelector('[data-zau-pin]'),pinButton=root.querySelector('[data-zau-pin-login-button]'),pinDeviceOnly=root.dataset.pinDeviceOnly==='1';
  if(pin)pin.addEventListener('input',()=>{pin.value=pin.value.replace(/\D/g,'').slice(0,12);});
  if(pinButton){const login=async()=>{if(!pinDeviceOnly&&!pinId?.value.trim()){set('Введите email, телефон или логин.',true);pinId?.focus();return;}if(!pin?.value){set('Введите постоянный PIN.',true);pin?.focus();return;}pinButton.disabled=true;set('Проверяем PIN…');try{go(await ajax('zau_union_pin_login',{identifier:pinId?.value||'',pin:pin.value,return_url:root.dataset.loginReturnUrl||root.dataset.returnUrl||''}));}catch(e){set(e.message,true);}finally{pinButton.disabled=false;}};pinButton.addEventListener('click',login);[pinId,pin].forEach(el=>el?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();login();}}));}

  const pinLogin=root.querySelector('[data-zau-pin-login]'),resetBox=root.querySelector('[data-zau-pin-reset]'),openReset=root.querySelector('[data-zau-open-pin-reset]'),closeReset=root.querySelector('[data-zau-close-pin-reset]'),resetEmail=root.querySelector('[data-zau-pin-reset-email]'),requestReset=root.querySelector('[data-zau-request-pin-reset]'),requestStep=root.querySelector('[data-zau-pin-reset-request]'),confirmStep=root.querySelector('[data-zau-pin-reset-confirm]'),resetCode=root.querySelector('[data-zau-pin-reset-code]'),newPin=root.querySelector('[data-zau-new-pin]'),newPinConfirm=root.querySelector('[data-zau-new-pin-confirm]'),resetButton=root.querySelector('[data-zau-reset-pin]');
  openReset?.addEventListener('click',()=>{if(pinLogin)pinLogin.hidden=true;if(resetBox)resetBox.hidden=false;if(resetEmail&&pinId?.value.includes('@'))resetEmail.value=pinId.value;set('');});
  closeReset?.addEventListener('click',()=>{if(resetBox)resetBox.hidden=true;if(pinLogin)pinLogin.hidden=false;set('');});
  [resetCode,newPin,newPinConfirm].forEach(el=>el?.addEventListener('input',()=>{el.value=el.value.replace(/\D/g,'').slice(0,12);}));
  requestReset?.addEventListener('click',async()=>{if(!resetEmail?.value.trim()){set('Введите email аккаунта.',true);return;}requestReset.disabled=true;set('Отправляем код восстановления…');try{const d=await ajax('zau_union_request_pin_reset',{email:resetEmail.value});set(d.message);if(requestStep)requestStep.hidden=true;if(confirmStep)confirmStep.hidden=false;resetCode?.focus();}catch(e){set(e.message,true);}finally{requestReset.disabled=false;}});
  resetButton?.addEventListener('click',async()=>{resetButton.disabled=true;set('Сохраняем новый PIN…');try{go(await ajax('zau_union_reset_pin',{email:resetEmail?.value||'',code:resetCode?.value||'',pin:newPin?.value||'',confirm:newPinConfirm?.value||'',return_url:root.dataset.loginReturnUrl||root.dataset.returnUrl||''}));}catch(e){set(e.message,true);}finally{resetButton.disabled=false;}});
 });
}
function initSignatures(root){root.querySelectorAll('.zau-signature-wrap').forEach(wrap=>{const canvas=wrap.querySelector('canvas');const hidden=wrap.querySelector('[data-zau-signature-value]');const clear=wrap.querySelector('[data-zau-clear-signature]');const ctx=canvas.getContext('2d');ctx.lineWidth=4;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#111';let drawing=false,dirty=false,last=null;function point(e){const rect=canvas.getBoundingClientRect();return{x:(e.clientX-rect.left)*canvas.width/rect.width,y:(e.clientY-rect.top)*canvas.height/rect.height};}canvas.addEventListener('pointerdown',e=>{e.preventDefault();drawing=true;dirty=true;last=point(e);canvas.setPointerCapture(e.pointerId);});canvas.addEventListener('pointermove',e=>{if(!drawing)return;e.preventDefault();const p=point(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;});function finish(e){if(!drawing)return;drawing=false;try{canvas.releasePointerCapture(e.pointerId);}catch(_e){}hidden.value=dirty?canvas.toDataURL('image/png'):'';}canvas.addEventListener('pointerup',finish);canvas.addEventListener('pointercancel',finish);clear.addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false;hidden.value='';});});}
function setOrganizationFields(root,org){const map={organization_bin:'bin',organization:'name',organization_name_ru:'name_ru',organization_name_kz:'name_kz',organization_director:'director',organization_address:'address',organization_address_ru:'address_ru',organization_address_kz:'address_kz',organization_status:'status',organization_status_code:'status_code',organization_type:'type',organization_type_code:'type_code',organization_registration_date:'registration_date',organization_oked:'oked',organization_oked_name:'oked_name',organization_kato:'kato'};Object.entries(map).forEach(([field,key])=>{const el=root.querySelector(`[name="${field}"]`);if(el&&org[key]!=null&&String(org[key])!==''){el.value=org[key];el.dispatchEvent(new Event('change',{bubbles:true}));}});if(org.region){const region=root.querySelector('[name="region"]');if(region&&!region.value)region.value=org.region;}}
function initBin(root){
 root.querySelectorAll('[data-zau-bin-input]').forEach(input=>{
  if(input.dataset.zauBinReady==='1')return;input.dataset.zauBinReady='1';
  const field=input.closest('.zau-form-field')||root,button=field.querySelector('[data-zau-bin-lookup]'),status=field.querySelector('[data-zau-bin-status]'),suggestions=field.querySelector('[data-zau-bin-suggestions]');
  let timer=null,requestNo=0;
  const clean=()=>{input.value=input.value.replace(/\D/g,'').slice(0,12);return input.value;};
  const setStatus=(message,error=false,success=false)=>{if(!status)return;status.textContent=message||'';status.classList.toggle('is-error',error);status.classList.toggle('is-success',success);};
  const closeSuggestions=()=>{if(suggestions){suggestions.hidden=true;suggestions.innerHTML='';}};
  const choose=org=>{setOrganizationFields(root,org||{});if(org?.bin)input.value=String(org.bin).replace(/\D/g,'').slice(0,12);closeSuggestions();setStatus('Данные найдены и вставлены в форму.',false,true);root.querySelector('[name="organization"]')?.focus();};
  const renderSuggestions=items=>{if(!suggestions)return;const list=Array.isArray(items)?items:[];if(!list.length){closeSuggestions();return;}suggestions.innerHTML=list.map((org,index)=>`<button type="button" class="zau-bin-suggestion" data-zau-bin-choice="${index}"><strong>${esc(org.name||org.value||'Организация')}</strong><span>${esc(org.bin||'')}${org.address?' · '+esc(org.address):''}</span></button>`).join('');suggestions.hidden=false;suggestions.querySelectorAll('[data-zau-bin-choice]').forEach(choice=>choice.addEventListener('click',()=>choose(list[Number(choice.dataset.zauBinChoice)]||{})));};
  const exact=async automatic=>{const value=clean();closeSuggestions();if(value.length!==12){setStatus('Введите ровно 12 цифр БИН или ИИН.',true);if(!automatic)input.focus();return;}const current=++requestNo;if(button){button.disabled=true;button.textContent='Ищем…';}setStatus('Ищем организацию или ИП…');try{const org=await ajax('zau_union_lookup_bin',{bin:value});if(current!==requestNo)return;choose(org);}catch(e){if(current!==requestNo)return;setStatus(e.message||'Данные не найдены. Заполните название организации вручную.',true);}finally{if(button){button.disabled=false;button.textContent='Найти';}}};
  const suggest=async()=>{const query=clean(),min=Math.max(3,Number(App.dadataMinChars||3));if(!App.dadataAutocomplete||query.length<min||query.length>=12){closeSuggestions();return;}const current=++requestNo;try{const data=await ajax('zau_union_suggest_party_kz',{query});if(current!==requestNo)return;renderSuggestions(data.suggestions||[]);}catch(_e){closeSuggestions();}};
  input.addEventListener('input',()=>{const value=clean();setStatus(value.length===12?'Номер введён. Идёт поиск…':'Введите 12 цифр БИН или ИИН.');clearTimeout(timer);if(value.length===12)timer=setTimeout(()=>exact(true),300);else if(App.dadataAutocomplete)timer=setTimeout(suggest,450);else closeSuggestions();});
  input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();exact(false);}});
  input.addEventListener('blur',()=>setTimeout(closeSuggestions,180));
  button?.addEventListener('click',()=>exact(false));
 });
}
function initSelfEmployed(root){
 const toggle=root.querySelector('[name="self_employed"]');
 if(!toggle||toggle.dataset.zauSelfEmployedReady==='1')return;toggle.dataset.zauSelfEmployedReady='1';
 const targets=ORG_DERIVED_KEYS.map(key=>root.querySelector(`[name="${key}"]`)).filter(Boolean);
 const apply=()=>{
  const on=toggle.checked;
  targets.forEach(el=>{
   const field=el.closest('.zau-form-field');
   el.disabled=on;
   if(field)field.classList.toggle('is-disabled',on);
   if(on){
    el.value='';el.dispatchEvent(new Event('change',{bubbles:true}));clearFieldError(field);
    const lookupButton=field?.querySelector('[data-zau-bin-lookup]');if(lookupButton)lookupButton.disabled=true;
    const suggestions=field?.querySelector('[data-zau-bin-suggestions]');if(suggestions){suggestions.hidden=true;suggestions.innerHTML='';}
   } else {
    const lookupButton=field?.querySelector('[data-zau-bin-lookup]');if(lookupButton)lookupButton.disabled=false;
   }
  });
 };
 toggle.addEventListener('change',apply);
 apply();
}
function initBranches(root){root.querySelectorAll('[data-zau-branch-select]').forEach(select=>{const field=select.closest('.zau-form-field');const summary=field?.querySelector('[data-zau-branch-summary]');const setField=(name,value)=>{const el=root.querySelector(`[name="${name}"]`);if(el&&value!=null){el.value=String(value);el.dispatchEvent(new Event('change',{bubbles:true}));}};const multiline=value=>esc(value).replace(/\r?\n/g,'<br>');const render=branch=>{if(!summary)return;const full=String(branch.branch_full_details||'').trim();if(full){summary.innerHTML=`<div class="zau-branch-full-details"><span>Данные выбранного филиала</span><strong>${multiline(full)}</strong></div>`;summary.hidden=false;return;}const rows=[['Филиал',branch.branch_name],['Регион',branch.branch_region],['БИН',branch.branch_union_bin],['ИИК',branch.branch_iban],['БИК',branch.branch_bik],['Банк',branch.branch_bank_name],['Адрес',branch.branch_legal_address],['Председатель',[branch.branch_chairman,branch.branch_chairman_phone].filter(Boolean).join(' · ')],['Главный бухгалтер',[branch.branch_accountant,branch.branch_accountant_phone].filter(Boolean).join(' · ')]].filter(row=>row[1]);summary.innerHTML=rows.map(row=>`<div><span>${esc(row[0])}</span><strong>${esc(row[1])}</strong></div>`).join('');summary.hidden=!rows.length;};const load=async()=>{const id=select.value;if(!id){if(summary){summary.hidden=true;summary.innerHTML='';}return;}select.disabled=true;if(summary){summary.hidden=false;summary.innerHTML='<div class="zau-branch-loading">Загружаем данные филиала…</div>';}try{const data=await ajax('zau_union_get_branch',{branch_id:id});const branch=data.branch||{};Object.entries(branch).forEach(([name,value])=>{if(name==='branch'||name==='branch_id')return;setField(name,value);});render(branch);}catch(e){if(summary){summary.hidden=false;summary.innerHTML=`<div class="zau-form-error">${esc(e.message)}</div>`;}}finally{select.disabled=false;}};select.addEventListener('change',load);if(select.value)setTimeout(load,50);});}

function dataUrlToBlob(dataUrl){const parts=String(dataUrl||'').split(',');if(parts.length<2)throw new Error('Изображение не распознано.');const mime=(parts[0].match(/data:([^;]+)/)||[])[1]||'application/octet-stream';const binary=atob(parts.slice(1).join(','));const bytes=new Uint8Array(binary.length);for(let i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);return new Blob([bytes],{type:mime});}
function registrationFormData(form){const trap=form.querySelector('[name="zau_company_website"]');if(trap)trap.value='';const human=form.querySelector('[data-zau-human-form]');if(human)human.value='1';const fd=new FormData(form);fd.set('zau_company_website','');fd.set('zau_human_form','1');form.querySelectorAll('[data-zau-signature-value]').forEach(input=>{const value=String(input.value||'');if(!input.name||!value.startsWith('data:image/png;base64,'))return;fd.delete(input.name);fd.append('zau_signature_file__'+input.name,dataUrlToBlob(value),'signature-'+input.name+'.png');});return fd;}
function showFormToast(message,type='error'){let toast=document.querySelector('[data-zau-form-toast]');if(!toast){toast=document.createElement('div');toast.className='zau-form-toast';toast.dataset.zauFormToast='1';toast.setAttribute('role','alert');toast.setAttribute('aria-live','assertive');document.body.appendChild(toast);}toast.classList.toggle('is-success',type==='success');toast.classList.toggle('is-error',type!=='success');toast.innerHTML=`<strong>${type==='success'?'Готово':'Проверьте форму'}</strong><span>${esc(message||'')}</span>`;toast.classList.add('is-visible');clearTimeout(toast._zauTimer);toast._zauTimer=setTimeout(()=>toast.classList.remove('is-visible'),6500);}
function fieldContainer(form,key){if(!key||key==='_form')return form;const safe=(window.CSS&&CSS.escape)?CSS.escape(key):String(key).replace(/"/g,'\\"');return form.querySelector(`.zau-form-field[data-field-key="${safe}"]`)||form.querySelector(`[name="${safe}"]`)?.closest('.zau-form-field')||(String(key).startsWith('zau_login_pin')?form.querySelector('[data-zau-registration-security]'):form);}
function clearFieldError(container){if(!container)return;container.classList.remove('is-invalid');container.querySelectorAll('.zau-field-error').forEach(node=>node.remove());container.querySelectorAll('[aria-invalid="true"]').forEach(el=>el.removeAttribute('aria-invalid'));}
function clearFormErrors(form){form.querySelectorAll('.is-invalid').forEach(clearFieldError);form.classList.remove('has-form-error');}
function markFieldError(form,key,message){const container=fieldContainer(form,key);if(!container)return;container.classList.add('is-invalid');if(container===form)form.classList.add('has-form-error');let note=container.querySelector(':scope > .zau-field-error');if(!note){note=document.createElement('div');note.className='zau-field-error';note.setAttribute('role','alert');container.appendChild(note);}note.textContent=message;const control=(key&&key!=='_form')?form.querySelector(`[name="${window.CSS?.escape?CSS.escape(key):key}"]`):null;control?.setAttribute('aria-invalid','true');}
function focusFormError(form,key){const container=fieldContainer(form,key);if(!container)return;container.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(()=>{const target=(key&&key!=='_form')?form.querySelector(`[name="${window.CSS?.escape?CSS.escape(key):key}"]`):container.querySelector('input,select,textarea,button');if(target&&target.type!=='hidden')target.focus({preventScroll:true});},380);}
function validateRegistrationForm(form){clearFormErrors(form);const errors=[];const add=(key,message)=>{if(!errors.some(item=>item.key===key))errors.push({key,message});};form.querySelectorAll('.zau-form-field').forEach(field=>{const key=field.dataset.fieldKey||'',control=field.querySelector('input:not([type="hidden"]),select,textarea'),signature=field.querySelector('[data-zau-signature-value]');if(signature?.required&&!String(signature.value||'').trim()){add(key,'Нарисуйте обязательную подпись.');return;}if(!control||control.disabled)return;const value=control.type==='checkbox'?(control.checked?'1':''):String(control.value||'').trim();if(control.required&&!value){add(key,control.tagName==='SELECT'?'Выберите значение из списка.':'Заполните обязательное поле.');return;}if(value&&control.type==='email'&&!control.validity.valid)add(key,'Введите корректный email, например name@example.kz.');if(value&&control.type==='tel'){const digits=value.replace(/\D/g,'');if(digits.length<10||digits.length>11)add(key,'Введите корректный номер телефона.');}if(value&&control.matches('[data-zau-bin-input]')&&value.replace(/\D/g,'').length!==12)add(key,'БИН или ИИН должен содержать ровно 12 цифр.');});const pin=form.querySelector('[name="zau_login_pin"]'),confirmPin=form.querySelector('[name="zau_login_pin_confirm"]');if(pin||confirmPin){const a=String(pin?.value||'').replace(/\D/g,''),b=String(confirmPin?.value||'').replace(/\D/g,''),min=Number(App.pinMinLength||4),max=Number(App.pinMaxLength||12);if(pin?.required&&!a)add('zau_login_pin','Создайте постоянный PIN.');else if(a&&(a.length<min||a.length>max))add('zau_login_pin',`PIN должен содержать от ${min} до ${max} цифр.`);if(a!==b)add('zau_login_pin_confirm','PIN и подтверждение не совпадают.');}errors.forEach(item=>markFieldError(form,item.key,item.message));return errors;}
function initForms(){document.querySelectorAll('[data-zau-union-form]').forEach(form=>{if(form.dataset.zauFormReady==='1')return;form.dataset.zauFormReady='1';initSignatures(form);initBin(form);initBranches(form);initSelfEmployed(form);const progress=form.querySelector('[data-zau-form-progress]');const result=form.querySelector('[data-zau-form-result]');const holder=form.querySelector('[data-zau-render-holder]');const human=form.querySelector('[data-zau-human-form]');const markHuman=()=>{if(human)human.value='1';};['pointerdown','keydown','input','change'].forEach(type=>form.addEventListener(type,markHuman,{passive:true}));form.addEventListener('input',event=>clearFieldError(event.target.closest('.zau-form-field')||event.target.closest('[data-zau-registration-security]')));form.addEventListener('change',event=>clearFieldError(event.target.closest('.zau-form-field')||event.target.closest('[data-zau-registration-security]')));form.addEventListener('submit',async e=>{e.preventDefault();result.innerHTML='';markHuman();const submit=form.querySelector('button[type="submit"]');const errors=validateRegistrationForm(form);if(errors.length){const first=errors[0];result.innerHTML=`<div class="zau-form-error zau-form-error-summary"><strong>Не удалось отправить форму.</strong><span>Исправьте отмеченные поля: ${errors.map(item=>esc(item.message)).join(' ')}</span></div>`;showFormToast(first.message);focusFormError(form,first.key);return;}submit.disabled=true;progress.textContent='Сохраняем заявку…';try{const prepared=await ajaxForm('zau_union_submit_form',registrationFormData(form));if(prepared.registered_directly){progress.textContent='Открываем защищённую сессию…';await refreshNonce();}const links=[];for(let i=0;i<(prepared.documents||[]).length;i++){const job=prepared.documents[i];progress.textContent=`Формируем документ ${i+1} из ${prepared.documents.length}…`;const image=await renderDocument(job.template,job.values||prepared.data||{},holder);const fd=new FormData();fd.set('document_id',job.id);fd.append('image_file',dataUrlToBlob(image),'document-'+job.id+'.jpg');const final=await ajaxForm('zau_union_finalize_document',fd);links.push(`<a target="_blank" rel="noopener" href="${esc(final.pdf_url)}">${esc(job.template.name)} — PDF</a>`);}progress.textContent='';const redirectUrl=prepared.redirect||App.cabinetUrl||'';const autoRedirect=!!prepared.registered_directly&&!!prepared.auto_redirect&&!!redirectUrl;result.innerHTML=`<div class="zau-form-success"><h3>Заявка принята</h3><p>${esc(prepared.message)}</p>${links.length?'<div class="zau-result-links">'+links.join('')+'</div>':''}<p>${autoRedirect?'Открываем личный кабинет…':`<a href="${esc(redirectUrl||'#')}">Перейти в личный кабинет</a>`}</p></div>`;showFormToast(autoRedirect?'Регистрация завершена. Открываем личный кабинет…':'Заявка успешно принята.','success');if(autoRedirect){setTimeout(()=>window.location.replace(redirectUrl),650);}else{result.scrollIntoView({behavior:'smooth',block:'center'});}}catch(err){progress.textContent='';const field=err.field||err.data?.field||'_form',message=err.message||'Ошибка отправки формы.';markFieldError(form,field,message);result.innerHTML=`<div class="zau-form-error zau-form-error-summary"><strong>Регистрация не завершена.</strong><span>${esc(message)}</span></div>`;showFormToast(message);focusFormError(form,field);}finally{submit.disabled=false;}});});}
function initCabinetRecovery(){document.querySelectorAll('[data-zau-cabinet]').forEach(root=>{const panel=root.querySelector('[data-zau-cabinet-generation]');const progress=root.querySelector('[data-zau-cabinet-progress]');const result=root.querySelector('[data-zau-cabinet-result]');const holder=root.querySelector('[data-zau-cabinet-render-holder]');let running=false;async function run(button){if(running)return;running=true;panel.hidden=false;result.innerHTML='';root.querySelectorAll('[data-zau-recover-submission]').forEach(b=>b.disabled=true);progress.textContent='Подготавливаем документы из сохранённой заявки…';try{const prepared=await ajax('zau_union_prepare_submission_documents',{submission_id:button.dataset.zauRecoverSubmission});const links=[];(prepared.ready||[]).forEach(doc=>links.push(`<a target="_blank" rel="noopener" href="${esc(doc.pdf_url)}">${esc(doc.name||doc.document_no)} — PDF</a>`));for(let i=0;i<(prepared.documents||[]).length;i++){const job=prepared.documents[i];progress.textContent=`Формируем документ ${i+1} из ${prepared.documents.length}…`;const image=await renderDocument(job.template,job.values||{},holder);const fd=new FormData();fd.set('document_id',job.id);fd.set('image_data',image);const final=await ajaxForm('zau_union_finalize_document',fd);links.push(`<a target="_blank" rel="noopener" href="${esc(final.pdf_url)}">${esc(job.template.name)} — PDF</a>`);}progress.textContent='';result.innerHTML=`<div class="zau-form-success"><strong>PDF сформирован.</strong>${links.length?'<div class="zau-result-links">'+links.join('')+'</div>':''}</div>`;button.remove();setTimeout(()=>location.reload(),900);}catch(err){progress.textContent='';result.innerHTML=`<div class="zau-form-error">${esc(err.message||'Не удалось сформировать PDF.')}</div>`;}finally{running=false;root.querySelectorAll('[data-zau-recover-submission]').forEach(b=>b.disabled=false);}}root.querySelectorAll('[data-zau-recover-submission]').forEach(button=>button.addEventListener('click',()=>run(button)));const automatic=root.querySelector('[data-zau-recover-submission][data-auto="1"]');if(automatic)setTimeout(()=>run(automatic),300);});}
function cabinetDocumentCard(doc,mode){const active=doc.record_status==='active',stateClass=active?'is-active':(doc.record_status==='revoked'?'is-revoked':'is-draft');let actions='';if(!doc.pdf_ready){actions='<span class="zau-muted">PDF ещё не сформирован</span>';}else{if((doc.image_url||doc.pdf_url)&&['popup','both'].includes(mode))actions+=`<button type="button" class="zau-union-button zau-secondary-button" data-zau-document-preview data-preview-url="${esc(doc.image_url||'')}" data-preview-pdf-url="${esc(doc.pdf_url||'')}" data-preview-title="${esc(doc.title)}">Предпросмотр</button>`;if(doc.pdf_url&&['tab','both'].includes(mode))actions+=`<a class="zau-union-button" target="_blank" rel="noopener" href="${esc(doc.pdf_url)}">Открыть PDF</a>`;}const revision=Number(doc.revision||0)>0?`<small class="zau-doc-revision">Версия ${Number(doc.revision)} · обновлено ${esc(doc.updated_at||'')}</small>`:'';return `<article class="zau-doc-card" data-zau-document-card="${Number(doc.id||0)}" data-document-search="${esc((doc.title||'')+' '+(doc.document_no||''))}" data-document-state="${doc.pdf_ready?(doc.record_status==='active'?'active':'revoked'):'pending'}"><div class="zau-doc-card-top"><span class="zau-doc-icon">▤</span><span class="zau-doc-state ${stateClass}">${esc(doc.status_label||'Документ')}</span></div><strong>${esc(doc.title||'Документ')}</strong><dl><div><dt>Номер</dt><dd>${esc(doc.document_no||'')}</dd></div><div><dt>Дата</dt><dd>${esc(doc.issue_date||'')}</dd></div></dl>${revision}<div class="zau-doc-actions">${actions}</div></article>`;}
function initCabinetDocumentsRefresh(){document.querySelectorAll('[data-zau-doc-grid]').forEach(grid=>{if(grid.dataset.zauRefreshReady==='1')return;grid.dataset.zauRefreshReady='1';const root=grid.closest('[data-zau-cabinet]')||document;const buttons=[...root.querySelectorAll('[data-zau-refresh-documents]')];let busy=false;async function refresh(manual=false){if(busy)return;busy=true;buttons.forEach(b=>{b.disabled=true;if(manual)b.textContent='Обновляем…';});try{const data=await ajax('zau_union_cabinet_documents',{cache_bust:Date.now()});const docs=data.documents||[];grid.innerHTML=docs.length?docs.map(doc=>cabinetDocumentCard(doc,data.mode||'both')).join(''):'<div class="zau-empty-state"><strong>Документов пока нет</strong><span>После формирования они появятся в этом разделе.</span></div>';grid.dispatchEvent(new CustomEvent('zau:documents-refreshed',{bubbles:true}));const stat=root.querySelector('.zau-cabinet-stats>div:first-child strong');if(stat)stat.textContent=String(docs.length);}catch(e){if(manual){const note=document.createElement('div');note.className='zau-form-error';note.textContent=e.message||'Не удалось обновить список документов.';grid.prepend(note);}}finally{busy=false;buttons.forEach(b=>{b.disabled=false;b.textContent='Обновить';});}}buttons.forEach(button=>button.addEventListener('click',()=>refresh(true)));setTimeout(()=>refresh(false),120);});}
function initOrganizationMembers(){document.querySelectorAll('[data-zau-org-members]').forEach(root=>{
 if(root.dataset.zauMembersReady==='1')return;root.dataset.zauMembersReady='1';
 const search=root.querySelector('[data-zau-member-search]'),message=root.querySelector('[data-zau-manager-message]');
 const setMessage=(text,error=false)=>{if(!message)return;message.textContent=text||'';message.classList.toggle('is-error',error);};
 if(search)search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();root.querySelectorAll('.zau-org-member-card').forEach(card=>{card.hidden=!!(q&&!String(card.dataset.search||'').includes(q));});});
 root.querySelectorAll('[data-zau-save-member]').forEach(button=>button.addEventListener('click',async()=>{const memberId=button.dataset.zauSaveMember,select=root.querySelector(`[data-zau-member-status="${memberId}"]`);button.disabled=true;setMessage('Сохраняем статус…');try{const data=await ajax('zau_union_manager_update_member',{member_id:memberId,status:select?.value||''});setMessage(data.message||'Статус обновлён.');}catch(e){setMessage(e.message,true);}finally{button.disabled=false;}}));
 root.querySelectorAll('[data-zau-quick-approve]').forEach(button=>button.addEventListener('click',async()=>{const memberId=button.dataset.zauQuickApprove;if(!confirm('Одобрить вступление участника в профсоюз?'))return;button.disabled=true;setMessage('Сохраняем решение…');try{const data=await ajax('zau_union_membership_decision',{member_id:memberId,decision:'approve',note:''});setMessage(data.message||'Вступление одобрено.');const select=root.querySelector(`[data-zau-member-status="${memberId}"]`);if(select)select.value=data.status||'Состоит в профсоюзе';}catch(e){setMessage(e.message,true);}finally{button.disabled=false;}}));
 const selectAll=root.querySelector('[data-zau-select-all-members]');if(selectAll)selectAll.addEventListener('change',()=>root.querySelectorAll('.zau-org-member-card:not([hidden]) [data-zau-member-select]').forEach(box=>box.checked=selectAll.checked));
 const bulkButton=root.querySelector('[data-zau-apply-member-bulk]');if(bulkButton)bulkButton.addEventListener('click',async()=>{const ids=[...root.querySelectorAll('[data-zau-member-select]:checked')].map(box=>box.value);if(!ids.length){setMessage('Выберите участников.',true);return;}const action=root.querySelector('[data-zau-bulk-member-action]')?.value||'set_status',status=root.querySelector('[data-zau-bulk-member-status]')?.value||'',note=root.querySelector('[data-zau-bulk-member-note]')?.value||'';const fd=new FormData();ids.forEach(id=>fd.append('member_ids[]',id));fd.set('bulk_action',action);fd.set('status',status);fd.set('note',note);bulkButton.disabled=true;setMessage('Обрабатываем выбранных участников…');try{const data=await ajaxForm('zau_union_manager_bulk_update_members',fd);setMessage(data.message||'Готово.');setTimeout(()=>location.reload(),700);}catch(e){setMessage(e.message,true);}finally{bulkButton.disabled=false;}});
 root.querySelectorAll('[data-zau-toggle-member-docs]').forEach(button=>button.addEventListener('click',()=>{const panel=button.closest('.zau-org-member-card')?.querySelector('[data-zau-member-docs]');if(!panel)return;panel.hidden=!panel.hidden;}));
});}

function initMemberCards(){document.querySelectorAll('[data-zau-member-card]').forEach(root=>{
 if(root.dataset.zauCardReady==='1')return;root.dataset.zauCardReady='1';
 const memberId=root.dataset.memberId||'',form=root.querySelector('[data-zau-member-card-form]'),message=root.querySelector('[data-zau-member-card-message]');
 const setMessage=(text,error=false)=>{if(!message)return;message.innerHTML=`<div class="${error?'zau-form-error':'zau-form-success'}">${esc(text||'')}</div>`;};
 if(form)form.addEventListener('submit',async event=>{event.preventDefault();const submit=form.querySelector('button[type="submit"]'),fd=new FormData(form);if(submit)submit.disabled=true;setMessage('Сохраняем карточку…');try{const data=await ajaxForm('zau_union_save_member_card',fd);setMessage(data.message||'Карточка сохранена.');}catch(e){setMessage(e.message,true);}finally{if(submit)submit.disabled=false;}});
 root.querySelectorAll('[data-zau-review-card]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{const data=await ajax('zau_union_review_member_card',{member_id:memberId,decision:button.dataset.zauReviewCard});setMessage(data.message||'Решение сохранено.');setTimeout(()=>location.reload(),600);}catch(e){setMessage(e.message,true);}finally{button.disabled=false;}}));
 root.querySelectorAll('[data-zau-sensitive-field]').forEach(field=>{
  const key=field.dataset.zauSensitiveField,display=field.querySelector('[data-zau-sensitive-display]'),show=field.querySelector('[data-zau-sensitive-show]'),copy=field.querySelector('[data-zau-sensitive-copy]');let timer=null,visible=false;
  const mask=()=>{if(timer)clearTimeout(timer);timer=null;visible=false;if(display)display.textContent=display.dataset.masked||'••••';if(show)show.textContent='Показать';};
  const fetchValue=async mode=>ajax('zau_union_reveal_sensitive',{member_id:memberId,field:key,mode});
  show?.addEventListener('click',async()=>{if(visible){mask();return;}show.disabled=true;try{const data=await fetchValue('show');if(display)display.textContent=data.value;visible=true;show.textContent='Скрыть';timer=setTimeout(mask,(Number(data.expires_in||root.dataset.sensitiveTimeout||30))*1000);}catch(e){setMessage(e.message,true);}finally{show.disabled=false;}});
  copy?.addEventListener('click',async()=>{copy.disabled=true;try{const data=await fetchValue('copy');if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(data.value);else{const area=document.createElement('textarea');area.value=data.value;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();}setMessage('Значение скопировано.');}catch(e){setMessage(e.message,true);}finally{copy.disabled=false;}});
 });
 root.querySelectorAll('[data-zau-membership-decision]').forEach(button=>button.addEventListener('click',async()=>{const decision=button.dataset.zauMembershipDecision,note=root.querySelector('[data-zau-membership-note]')?.value||'';if(decision==='approve'&&!confirm('После одобрения статус станет «Состоит в профсоюзе». Продолжить?'))return;button.disabled=true;try{const data=await ajax('zau_union_membership_decision',{member_id:memberId,decision,note});setMessage(data.message||'Решение сохранено.');setTimeout(()=>location.reload(),650);}catch(e){setMessage(e.message,true);}finally{button.disabled=false;}}));
 });}

function initDocumentModals(){document.querySelectorAll('[data-zau-cabinet]').forEach(root=>{const modal=root.querySelector('[data-zau-document-modal]');if(!modal||root.dataset.zauModalReady==='1')return;root.dataset.zauModalReady='1';const image=modal.querySelector('[data-zau-modal-image]');const frame=modal.querySelector('[data-zau-modal-pdf]');const title=modal.querySelector('[data-zau-modal-title]');const loading=modal.querySelector('[data-zau-preview-loading]');let lastFocus=null;const clearMedia=()=>{image?.removeAttribute('src');if(frame){frame.removeAttribute('src');frame.hidden=true;}if(image)image.hidden=true;};const close=()=>{modal.hidden=true;document.documentElement.classList.remove('zau-modal-open');clearMedia();if(lastFocus)lastFocus.focus();};const showPdf=(pdfUrl)=>{if(!frame||!pdfUrl){loading.textContent='Не удалось загрузить предпросмотр.';loading.hidden=false;return;}loading.textContent='Открываем PDF…';frame.onload=()=>{loading.hidden=true;frame.hidden=false;};frame.src=pdfUrl+(pdfUrl.includes('?')?'&':'?')+'_zau_refresh='+Date.now();};root.addEventListener('click',event=>{const button=event.target.closest('[data-zau-document-preview]');if(!button||!root.contains(button))return;lastFocus=button;title.textContent=button.dataset.previewTitle||'Предпросмотр документа';loading.textContent='Загружаем документ…';loading.hidden=false;clearMedia();modal.hidden=false;document.documentElement.classList.add('zau-modal-open');const imageUrl=button.dataset.previewUrl||'';const pdfUrl=button.dataset.previewPdfUrl||'';if(!imageUrl){showPdf(pdfUrl);return;}image.onload=()=>{loading.hidden=true;image.hidden=false;};image.onerror=()=>{image.hidden=true;showPdf(pdfUrl);};image.src=imageUrl+(imageUrl.includes('?')?'&':'?')+'_zau_refresh='+Date.now();});modal.querySelectorAll('[data-zau-modal-close]').forEach(el=>el.addEventListener('click',close));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});});}
function initOrganizationRegistry(){
  document.querySelectorAll('[data-zau-org-registry]').forEach(root=>{
    if(root.dataset.zauRegistryReady==='1')return;
    root.dataset.zauRegistryReady='1';
    const tbody=root.querySelector('[data-zau-registry-tbody]');
    const filters=[...root.querySelectorAll('[data-filter]')];
    const selectAll=root.querySelector('[data-zau-registry-select-all]');
    const count=root.querySelector('[data-zau-registry-count]');
    const message=root.querySelector('[data-zau-registry-message]');
    const serverForm=root.querySelector('[data-zau-registry-server-form]');
    const pagination=root.querySelector('[data-zau-registry-pagination]');
    const pageLabel=root.querySelector('[data-zau-registry-page-label]');
    const prevBtn=root.querySelector('[data-zau-registry-prev]');
    const nextBtn=root.querySelector('[data-zau-registry-next]');
    if(!tbody)return;
    const selected=new Set();
    let currentPage=1,totalPages=parseInt(root.dataset.totalPages,10)||1,currentTotal=parseInt(root.dataset.total,10)||0,loading=false;

    function filterValues(){const v={};filters.forEach(f=>v[f.dataset.filter]=f.value);return v;}
    function filterPayload(){const v=filterValues();return{f_name:v.name||'',f_iin:v.iin||'',f_date_from:v.date_from||'',f_date_to:v.date_to||'',f_organization:v.organization||'',f_phone:v.phone||'',f_email:v.email||'',f_document:v.document||''};}
    function rows(){return[...tbody.querySelectorAll('[data-zau-registry-row]')];}

    function updateSelectAllState(){
      if(!selectAll)return;
      const checks=rows().map(r=>r.querySelector('[data-zau-registry-check]')).filter(Boolean);
      selectAll.checked=checks.length>0&&checks.every(c=>c.checked);
      selectAll.indeterminate=checks.some(c=>c.checked)&&!selectAll.checked;
    }
    function updateCount(){
      if(count)count.textContent=`Показано: ${rows().length} из ${currentTotal}`+(selected.size?` · Выбрано: ${selected.size}`:'');
    }
    function wireRows(){
      rows().forEach(row=>{
        const id=String(row.dataset.userId);
        const cb=row.querySelector('[data-zau-registry-check]');
        if(!cb)return;
        cb.checked=selected.has(id);
        cb.addEventListener('change',()=>{
          if(cb.checked)selected.add(id);else selected.delete(id);
          updateSelectAllState();updateCount();
        });
      });
      updateSelectAllState();updateCount();
      root._zauApplyRegistryStyles?.();
    }

    async function loadPage(page){
      if(loading)return;
      loading=true;
      if(message)message.textContent='Загрузка…';
      try{
        const res=await ajax('zau_union_registry_page',Object.assign({page},filterPayload()));
        tbody.innerHTML=res.html||'<tr><td colspan="7">Участники не найдены.</td></tr>';
        currentPage=res.page||1;totalPages=res.total_pages||1;currentTotal=res.total||0;
        if(pageLabel)pageLabel.textContent=`Страница ${currentPage} из ${totalPages}`;
        if(pagination)pagination.hidden=totalPages<2;
        if(prevBtn)prevBtn.disabled=currentPage<=1;
        if(nextBtn)nextBtn.disabled=currentPage>=totalPages;
        wireRows();
        if(message)message.textContent='';
      }catch(e){
        if(message)message.textContent=e.message||'Не удалось загрузить список.';
      }finally{loading=false;}
    }

    let debounceTimer=null;
    function scheduleReload(){clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>loadPage(1),350);}
    filters.forEach(f=>f.addEventListener('input',scheduleReload));
    root.querySelector('[data-zau-registry-reset]')?.addEventListener('click',()=>{filters.forEach(f=>f.value='');loadPage(1);});
    prevBtn?.addEventListener('click',()=>{if(currentPage>1)loadPage(currentPage-1);});
    nextBtn?.addEventListener('click',()=>{if(currentPage<totalPages)loadPage(currentPage+1);});
    selectAll?.addEventListener('change',()=>{
      rows().forEach(row=>{
        const cb=row.querySelector('[data-zau-registry-check]');
        if(!cb)return;
        cb.checked=selectAll.checked;
        const id=String(row.dataset.userId);
        if(selectAll.checked)selected.add(id);else selected.delete(id);
      });
      updateCount();
    });

    function fillFilterFields(target){
      const payload=filterPayload();
      Object.keys(payload).forEach(key=>{const field=target.querySelector(`[name="${key}"]`);if(field)field.value=payload[key];});
    }
    function submitServer(action){
      fillFilterFields(serverForm);
      serverForm.querySelector('[name="action"]').value=action;
      serverForm.querySelector('[name="member_ids"]').value=[...selected].join(',');
      serverForm.submit();
    }
    root.querySelector('[data-zau-registry-excel]')?.addEventListener('click',()=>submitServer('zau_union_export_org_registry_excel'));
    root.querySelector('[data-zau-registry-zip]')?.addEventListener('click',()=>submitServer('zau_union_download_org_documents_zip'));
    root.querySelector('[data-zau-registry-pdf]')?.addEventListener('click',async button=>{
      const el=button.currentTarget;
      el.disabled=true;
      if(message)message.textContent='Загружаем строки для PDF…';
      try{
        const payload=filterPayload();
        if(selected.size)payload.ids=[...selected].join(',');else payload.all=1;
        const res=await ajax('zau_union_registry_page',payload);
        const holder=document.createElement('tbody');holder.innerHTML=res.html||'';
        const pageRows=[...holder.querySelectorAll('[data-zau-registry-row]')];
        if(!pageRows.length){if(message)message.textContent='Нет строк для выгрузки.';el.disabled=false;return;}
        if(message)message.textContent='Формируем страницы PDF…';
        const pages=makeRegistryPdfPages(pageRows);
        const fd=new FormData();fd.set('action',root.dataset.pdfAction||'zau_union_export_org_registry_pdf');fd.set('nonce',App.nonce||'');
        pages.forEach(page=>fd.append('pages[]',page));
        const response=await fetch(App.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'});
        if(!response.ok){throw new Error((await response.text())||'Ошибка создания PDF.');}
        const blob=await response.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='reestr-organizacii.pdf';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1500);
        if(message)message.textContent=`PDF сформирован. Записей: ${pageRows.length}.`;
      }catch(e){if(message)message.textContent=e.message||'Не удалось сформировать PDF.';}
      finally{el.disabled=false;}
    });

    initRegistryFormatting(root,message);
    wireRows();
  });
}
function initRegistryFormatting(root,message){
  let style={columns:{},rows:{},cells:{}};
  try{const parsed=JSON.parse(root.dataset.zauRegistryStyle||'{}');style=Object.assign(style,{columns:parsed.columns||{},rows:parsed.rows||{},cells:parsed.cells||{}});}catch(_e){}
  const table=root.querySelector('.zau-org-registry-table');
  const formatBtn=root.querySelector('[data-zau-registry-format]');
  const clearBtn=root.querySelector('[data-zau-registry-format-clear]');
  const hint=root.querySelector('[data-zau-registry-format-hint]');
  if(!table||!formatBtn)return;
  let popover=null,saveTimer=null;
  function ruleCss(rule){const css={};if(!rule)return css;if(rule.bg)css.backgroundColor=rule.bg;if(rule.color)css.color=rule.color;if(rule.bold)css.fontWeight='800';return css;}
  function applyStyles(){
    table.querySelectorAll('th[data-col]').forEach(th=>{const css=ruleCss(style.columns[th.dataset.col]);th.style.backgroundColor=css.backgroundColor||'';th.style.color=css.color||'';th.style.fontWeight=css.fontWeight||'';});
    table.querySelectorAll('tbody tr[data-user-id]').forEach(tr=>{
      const userId=tr.dataset.userId,rowRule=style.rows[userId];
      tr.querySelectorAll('td[data-col]').forEach(td=>{
        const col=td.dataset.col,merged=Object.assign({},style.columns[col],rowRule,style.cells[userId+':'+col]),css=ruleCss(merged);
        td.style.backgroundColor=css.backgroundColor||'';td.style.color=css.color||'';td.style.fontWeight=css.fontWeight||'';
      });
    });
  }
  function scheduleSave(){
    clearTimeout(saveTimer);
    saveTimer=setTimeout(()=>{
      const fd=new FormData();fd.set('action','zau_union_save_registry_style');fd.set('nonce',App.nonce||'');fd.set('style',JSON.stringify(style));
      fetch(App.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{if(message&&res&&res.success===false)message.textContent=res.data?.message||'Не удалось сохранить оформление.';}).catch(()=>{if(message)message.textContent='Не удалось сохранить оформление — проверьте связь.';});
    },400);
  }
  function updateClearVisibility(){const has=Object.keys(style.columns).length||Object.keys(style.rows).length||Object.keys(style.cells).length;if(clearBtn)clearBtn.hidden=!has;}
  function closePopover(){if(popover){popover.remove();popover=null;}}
  function openPopover(anchor,scopeOptions,getRule,setRule){
    closePopover();
    const current=getRule()||{};
    popover=document.createElement('div');
    popover.className='zau-registry-format-popover';
    const scopeHtml=scopeOptions.length>1?('<div class="zau-registry-format-scope">'+scopeOptions.map((o,i)=>`<label><input type="radio" name="zau-format-scope" value="${o.value}" ${i===0?'checked':''}> ${o.label}</label>`).join('')+'</div>'):'';
    popover.innerHTML=`${scopeHtml}
      <label class="zau-registry-format-row"><span>Фон</span><input type="color" data-f-bg value="${current.bg||'#ffffff'}"><button type="button" data-f-bg-clear class="zau-link-button">убрать</button></label>
      <label class="zau-registry-format-row"><span>Текст</span><input type="color" data-f-color value="${current.color||'#111111'}"><button type="button" data-f-color-clear class="zau-link-button">убрать</button></label>
      <label class="zau-registry-format-row"><input type="checkbox" data-f-bold ${current.bold?'checked':''}> Жирный текст</label>
      <div class="zau-registry-format-actions">
        <button type="button" class="zau-union-button" data-f-apply>Применить</button>
        <button type="button" class="zau-link-button" data-f-reset>Сбросить</button>
        <button type="button" class="zau-link-button" data-f-close>Закрыть</button>
      </div>`;
    document.body.appendChild(popover);
    const rect=anchor.getBoundingClientRect();
    let left=window.scrollX+rect.left;
    const maxLeft=window.scrollX+document.documentElement.clientWidth-popover.offsetWidth-10;
    if(left>maxLeft)left=Math.max(10,maxLeft);
    popover.style.top=(window.scrollY+rect.bottom+6)+'px';
    popover.style.left=left+'px';
    const bgInput=popover.querySelector('[data-f-bg]'),colorInput=popover.querySelector('[data-f-color]'),boldInput=popover.querySelector('[data-f-bold]');
    let bgSet=!!current.bg,colorSet=!!current.color;
    popover.querySelector('[data-f-bg-clear]')?.addEventListener('click',()=>{bgSet=false;});
    popover.querySelector('[data-f-color-clear]')?.addEventListener('click',()=>{colorSet=false;});
    bgInput?.addEventListener('input',()=>{bgSet=true;});
    colorInput?.addEventListener('input',()=>{colorSet=true;});
    popover.querySelector('[data-f-close]')?.addEventListener('click',closePopover);
    function scopeValue(){const checked=popover.querySelector('input[name="zau-format-scope"]:checked');return checked?checked.value:(scopeOptions[0]?scopeOptions[0].value:'cell');}
    popover.querySelector('[data-f-reset]')?.addEventListener('click',()=>{setRule(scopeValue(),null);applyStyles();scheduleSave();updateClearVisibility();closePopover();});
    popover.querySelector('[data-f-apply]')?.addEventListener('click',()=>{
      const rule={};if(bgSet&&bgInput.value)rule.bg=bgInput.value;if(colorSet&&colorInput.value)rule.color=colorInput.value;if(boldInput.checked)rule.bold=1;
      setRule(scopeValue(),Object.keys(rule).length?rule:null);applyStyles();scheduleSave();updateClearVisibility();closePopover();
    });
  }
  formatBtn.addEventListener('click',()=>{
    root.classList.toggle('is-format-mode');
    const active=root.classList.contains('is-format-mode');
    formatBtn.classList.toggle('is-active',active);
    if(hint)hint.hidden=!active;
    if(!active)closePopover();
  });
  clearBtn?.addEventListener('click',()=>{
    if(!window.confirm('Сбросить всё оформление таблицы?'))return;
    style={columns:{},rows:{},cells:{}};applyStyles();scheduleSave();updateClearVisibility();closePopover();
  });
  table.addEventListener('click',e=>{
    if(!root.classList.contains('is-format-mode'))return;
    const th=e.target.closest('th[data-col]'),td=e.target.closest('td[data-col]');
    if(th){
      const col=th.dataset.col;
      openPopover(th,[{value:'column',label:'Столбец'}],()=>style.columns[col],(_scope,rule)=>{if(rule)style.columns[col]=rule;else delete style.columns[col];});
      e.preventDefault();return;
    }
    if(td){
      const tr=td.closest('tr[data-user-id]'),col=td.dataset.col,userId=tr?tr.dataset.userId:'';
      if(!userId)return;
      const cellKey=userId+':'+col;
      openPopover(td,[{value:'cell',label:'Ячейка'},{value:'row',label:'Строка'},{value:'column',label:'Столбец'}],()=>style.cells[cellKey],(scope,rule)=>{
        if(scope==='row'){if(rule)style.rows[userId]=rule;else delete style.rows[userId];}
        else if(scope==='column'){if(rule)style.columns[col]=rule;else delete style.columns[col];}
        else{if(rule)style.cells[cellKey]=rule;else delete style.cells[cellKey];}
      });
      e.preventDefault();
    }
  });
  document.addEventListener('click',e=>{
    if(popover&&!popover.contains(e.target)&&!e.target.closest('th[data-col]')&&!e.target.closest('td[data-col]'))closePopover();
  });
  applyStyles();
  updateClearVisibility();
  root._zauApplyRegistryStyles=applyStyles;
}
function registryCell(row,label){const cell=row.querySelector(`[data-label="${label}"]`);return cell?cell.innerText.replace(/\s+/g,' ').trim():'';}
function wrapCanvasText(ctx,text,maxWidth,maxLines=3){const words=String(text||'').split(/\s+/).filter(Boolean),lines=[];let line='';for(const word of words){const test=line?line+' '+word:word;if(ctx.measureText(test).width>maxWidth&&line){lines.push(line);line=word;if(lines.length>=maxLines-1)break;}else line=test;}if(line&&lines.length<maxLines)lines.push(line);return lines;}
function makeRegistryPdfPages(rows){const width=1684,height=1190,margin=48,headerH=138,rowH=88,footerH=42,perPage=Math.max(1,Math.floor((height-headerH-footerH-margin)/rowH)),total=Math.ceil(rows.length/perPage),pages=[];for(let pageIndex=0;pageIndex<total;pageIndex++){const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;const ctx=canvas.getContext('2d',{alpha:false});ctx.fillStyle='#fff';ctx.fillRect(0,0,width,height);ctx.fillStyle='#101828';ctx.font='700 34px Arial';ctx.fillText('Реестр участников организации',margin,50);ctx.font='20px Arial';ctx.fillStyle='#667085';ctx.fillText(`Сформировано: ${new Date().toLocaleString('ru-RU')} · Записей: ${rows.length}`,margin,84);const cols=[{label:'',w:42},{label:'ФИО',w:230},{label:'Дата регистрации',w:150},{label:'Организация',w:390},{label:'Телефон',w:160},{label:'Email',w:250},{label:'Документы',w:350}],tableW=cols.reduce((a,c)=>a+c.w,0),scale=Math.min(1,(width-margin*2)/tableW);let x=margin,y=112;ctx.fillStyle='#EAF2FD';ctx.fillRect(margin,y,tableW*scale,42);ctx.strokeStyle='#B8D2F3';ctx.lineWidth=1;ctx.font='700 17px Arial';ctx.fillStyle='#101828';for(const col of cols){const w=col.w*scale;ctx.strokeRect(x,y,w,42);if(col.label)ctx.fillText(col.label,x+7,y+26);x+=w;}y+=42;const chunk=rows.slice(pageIndex*perPage,(pageIndex+1)*perPage);chunk.forEach((row,index)=>{x=margin;ctx.fillStyle=index%2?'#f8fbf9':'#fff';ctx.fillRect(margin,y,tableW*scale,rowH);const values=['',registryCell(row,'ФИО'),registryCell(row,'Дата регистрации'),registryCell(row,'Организация'),registryCell(row,'Телефон'),registryCell(row,'Email'),registryCell(row,'Документы')];ctx.font='16px Arial';ctx.fillStyle='#344054';cols.forEach((col,colIndex)=>{const w=col.w*scale;ctx.strokeStyle='#D9E2EF';ctx.strokeRect(x,y,w,rowH);if(colIndex){const maxLines=colIndex===6?4:3,lines=wrapCanvasText(ctx,values[colIndex],w-14,maxLines);lines.forEach((line,lineIndex)=>ctx.fillText(line,x+7,y+23+lineIndex*19));}x+=w;});y+=rowH;});ctx.font='16px Arial';ctx.fillStyle='#667085';ctx.fillText(`Страница ${pageIndex+1} из ${total}`,width-margin-150,height-28);pages.push(canvas.toDataURL('image/jpeg',.9));}return pages;}
function loadImage(url){return new Promise((resolve,reject)=>{if(!url){resolve(null);return;}const img=new Image();img.crossOrigin='anonymous';img.onload=()=>resolve(img);img.onerror=()=>reject(new Error('Не удалось загрузить изображение подложки или подписи. Используйте файлы медиабиблиотеки этого сайта.'));img.src=url;});}
function drawBackground(ctx,img,w,h,mode){ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h);if(!img)return;if(mode==='stretch'){ctx.drawImage(img,0,0,w,h);return;}const scale=mode==='cover'?Math.max(w/img.width,h/img.height):Math.min(w/img.width,h/img.height);const dw=img.width*scale,dh=img.height*scale;ctx.drawImage(img,(w-dw)/2,(h-dh)/2,dw,dh);}
function splitText(ctx,text,maxWidth){const lines=[];String(text??'').split(/\r?\n/).forEach((para,pi)=>{const words=para.trim().split(/\s+/).filter(Boolean);if(!words.length){lines.push('');return;}let line='';words.forEach(word=>{const test=line?line+' '+word:word;if(ctx.measureText(test).width>maxWidth&&line){lines.push(line);line=word;}else line=test;});if(line)lines.push(line);if(pi<String(text??'').split(/\r?\n/).length-1)lines.push('');});return lines;}
function drawText(ctx,cfg,value,w,h){if(!Number(cfg.enabled)||!String(value??'').trim())return;const x=Number(cfg.x||0)/100*w,y=Number(cfg.y||0)/100*h,maxWidth=Number(cfg.width||50)/100*w,size=Number(cfg.fontSize||36);ctx.font=(Number(cfg.italic)?'italic ':'')+(Number(cfg.bold)?'700 ':'400 ')+size+'px "'+(cfg.fontFamily||'Arial')+'"';ctx.fillStyle=cfg.color||'#111';ctx.textBaseline='top';ctx.textAlign=cfg.align||'center';const lines=splitText(ctx,value,maxWidth).slice(0,Number(cfg.maxLines||2)),lh=size*Number(cfg.lineHeight||1.2),tx=cfg.align==='left'?x:(cfg.align==='right'?x+maxWidth:x+maxWidth/2);const top=cfg.vAnchor==='bottom'?y-lines.length*lh:y;lines.forEach((line,i)=>ctx.fillText(line,tx,top+i*lh,maxWidth));}
async function drawImage(ctx,cfg,url,w,h){if(!Number(cfg.enabled)||!String(url||'').trim())return;const img=await loadImage(url);if(!img)return;const x=Number(cfg.x||0)/100*w,y=Number(cfg.y||0)/100*h,bw=Number(cfg.width||20)/100*w,bh=Number(cfg.height||10)/100*h,mode=cfg.fit||'contain';let dx=x,dy=y,dw=bw,dh=bh;if(mode!=='stretch'){const scale=mode==='cover'?Math.max(bw/img.width,bh/img.height):Math.min(bw/img.width,bh/img.height);dw=img.width*scale;dh=img.height*scale;dx=x+(bw-dw)/2;dy=y+(bh-dh)/2;}ctx.save();ctx.globalAlpha=Math.max(.1,Math.min(1,Number(cfg.opacity??1)));if(mode==='cover'){ctx.beginPath();ctx.rect(x,y,bw,bh);ctx.clip();}ctx.drawImage(img,dx,dy,dw,dh);ctx.restore();}
async function qrCanvas(text,size,holder){const div=document.createElement('div');div.className='zau-offscreen-qr';holder.appendChild(div);const qr=new QRCode(div,{text:String(text),width:size,height:size,colorDark:'#000',colorLight:'#fff',correctLevel:QRCode.CorrectLevel.M});await new Promise(r=>setTimeout(r,0));const c=div.querySelector('canvas')||qr._canvas;if(!c)throw new Error('Не удалось создать QR-код.');return{canvas:c,cleanup:()=>div.remove()};}
async function renderDocument(tpl,values,holder){const w=Number(tpl.page_width||1754),h=Number(tpl.page_height||2480),canvas=document.createElement('canvas');canvas.width=w;canvas.height=h;const ctx=canvas.getContext('2d',{alpha:false});drawBackground(ctx,await loadImage(tpl.background_url),w,h,tpl.background_mode||'stretch');const fields=tpl.fields||{},types=App.fieldTypes||{};for(const key of Object.keys(fields)){if(key==='qr')continue;const cfg=fields[key]||{},value=values[key]??'';const isImage=types[key]==='image'||cfg.type==='image'||['signature_url','signature2_url','stamp_url','signature'].includes(key);if(isImage)await drawImage(ctx,cfg,value,w,h);else drawText(ctx,cfg,value,w,h);}const q=fields.qr||{};if(Number(q.enabled)){const size=Math.max(90,Math.round(Number(q.width||12)/100*w)),qr=await qrCanvas(values.verify_url,size,holder);ctx.drawImage(qr.canvas,Number(q.x||0)/100*w,Number(q.y||0)/100*h,size,size);qr.cleanup();}return canvas.toDataURL('image/jpeg',.94);}
function initCabinetTabs(scope=document){
 const roots=[];
 if(scope?.matches?.('[data-zau-cabinet-tabs]'))roots.push(scope);
 scope?.querySelectorAll?.('[data-zau-cabinet-tabs]').forEach(root=>roots.push(root));
 roots.forEach(root=>{
  if(root.dataset.zauTabsReady==='1')return;
  root.dataset.zauTabsReady='1';
  const tabs=[...root.querySelectorAll('[data-zau-tab]')];
  const panels=[...root.querySelectorAll('[data-zau-tab-panel]')];
  const wrapper=root.closest('.zau-elementor-interface');
  const hiddenClass={home:'zau-e-hide-home',documents:'zau-e-hide-documents',submissions:'zau-e-hide-submissions',card:'zau-e-hide-card',benefits:'zau-e-hide-benefits',members:'zau-e-hide-members',registry:'zau-e-hide-registry',info:'zau-e-hide-info',logins:'zau-e-hide-logins'};
  const available=()=>tabs.filter(tab=>{
   const key=tab.dataset.zauTab;
   const panel=panels.find(item=>item.dataset.zauTabPanel===key);
   const blocked=wrapper&&hiddenClass[key]&&wrapper.classList.contains(hiddenClass[key]);
   tab.hidden=!panel||blocked;
   return panel&&!blocked;
  });
  const activate=(key,updateUrl=false,focus=false)=>{
   const usable=available();
   if(!usable.length)return;
   let active=usable.find(tab=>tab.dataset.zauTab===key)||usable[0];
   key=active.dataset.zauTab;
   tabs.forEach(tab=>{
    const selected=tab===active;
    tab.classList.toggle('is-active',selected);
    tab.setAttribute('aria-selected',selected?'true':'false');
    tab.tabIndex=selected?0:-1;
   });
   panels.forEach(panel=>{panel.hidden=panel.dataset.zauTabPanel!==key;});
   root.dataset.zauActiveTab=key;
   if(focus)active.focus();
   if(updateUrl&&window.history?.pushState){
    const url=new URL(window.location.href);
    url.searchParams.set('zau_tab',key);
    url.hash='';
    window.history.pushState({zauTab:key},'',url);
   }
   root.dispatchEvent(new CustomEvent('zau:tabchange',{detail:{tab:key}}));
  };
  tabs.forEach(tab=>{
   tab.addEventListener('click',event=>{event.preventDefault();activate(tab.dataset.zauTab,true,false);});
   tab.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
    const usable=available(); if(!usable.length)return;
    event.preventDefault();
    let index=usable.indexOf(tab);
    if(event.key==='Home')index=0;else if(event.key==='End')index=usable.length-1;else index=(index+(event.key==='ArrowRight'?1:-1)+usable.length)%usable.length;
    activate(usable[index].dataset.zauTab,true,true);
   });
  });
  const urlTab=new URL(window.location.href).searchParams.get('zau_tab');
  activate(urlTab||root.dataset.zauActiveTab||'home',false,false);
  window.addEventListener('popstate',()=>activate(new URL(window.location.href).searchParams.get('zau_tab')||root.dataset.zauActiveTab||'home',false,false));
 });
}
function initBenefitModals(){document.querySelectorAll('[data-zau-benefit-modal]').forEach(modal=>{if(modal.dataset.zauBenefitReady==='1')return;modal.dataset.zauBenefitReady='1';const section=modal.closest('.zau-benefits');if(!section)return;const body=modal.querySelector('[data-zau-benefit-modal-body]');const titleEl=modal.querySelector('[data-zau-benefit-modal-title]');let lastFocus=null;const close=()=>{modal.hidden=true;document.documentElement.classList.remove('zau-modal-open');body.innerHTML='';if(lastFocus)lastFocus.focus();};const open=card=>{const tpl=card.querySelector('template.zau-benefit-detail-template');if(!tpl)return;lastFocus=card;titleEl.textContent=card.querySelector('h4')?.textContent||'';body.innerHTML='';body.appendChild(tpl.content.cloneNode(true));modal.hidden=false;document.documentElement.classList.add('zau-modal-open');modal.querySelector('.zau-document-modal-head button')?.focus();};section.addEventListener('click',event=>{if(event.target.closest('a'))return;const card=event.target.closest('[data-zau-benefit-open]');if(!card||!section.contains(card))return;open(card);});section.addEventListener('keydown',event=>{if(event.key!=='Enter'&&event.key!==' ')return;const card=event.target.closest('[data-zau-benefit-open]');if(!card)return;event.preventDefault();open(card);});modal.querySelectorAll('[data-zau-modal-close]').forEach(el=>el.addEventListener('click',close));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});});}
function initPinPrompt(){const modal=document.querySelector('[data-zau-pin-prompt-modal]');if(!modal||modal.dataset.zauPinPromptReady==='1')return;modal.dataset.zauPinPromptReady='1';document.documentElement.classList.add('zau-modal-open');const valueInput=modal.querySelector('[data-zau-pin-prompt-value]');const confirmInput=modal.querySelector('[data-zau-pin-prompt-confirm]');const errorEl=modal.querySelector('[data-zau-pin-prompt-error]');const saveButton=modal.querySelector('[data-zau-pin-prompt-save]');const min=parseInt(modal.dataset.pinMin,10)||4;const max=parseInt(modal.dataset.pinMax,10)||8;const showError=msg=>{if(!errorEl)return;errorEl.textContent=msg||'';errorEl.hidden=!msg;};const close=()=>{modal.hidden=true;document.documentElement.classList.remove('zau-modal-open');};const dismiss=async()=>{close();try{await ajax('zau_union_dismiss_pin_prompt',{});}catch(e){}};modal.querySelectorAll('[data-zau-pin-prompt-dismiss]').forEach(el=>el.addEventListener('click',dismiss));saveButton?.addEventListener('click',async()=>{const pin=(valueInput?.value||'').trim();const confirmVal=(confirmInput?.value||'').trim();if(!/^\d+$/.test(pin)||pin.length<min||pin.length>max){showError(`PIN должен содержать от ${min} до ${max} цифр.`);return;}if(pin!==confirmVal){showError('PIN и подтверждение не совпадают.');return;}showError('');saveButton.disabled=true;try{await ajax('zau_union_set_login_pin',{pin,pin_confirm:confirmVal});close();}catch(e){showError(e.message||'Не удалось сохранить PIN.');}finally{saveButton.disabled=false;}});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)dismiss();});}
function initSecurityModal(){
  const openButton=document.querySelector('[data-zau-open-security]');
  const modal=document.querySelector('[data-zau-security-modal]');
  if(!openButton||!modal||modal.dataset.zauSecurityReady==='1')return;
  modal.dataset.zauSecurityReady='1';
  const close=()=>{modal.hidden=true;document.documentElement.classList.remove('zau-modal-open');};
  const open=()=>{modal.hidden=false;document.documentElement.classList.add('zau-modal-open');};
  openButton.addEventListener('click',open);
  modal.querySelectorAll('[data-zau-security-dismiss]').forEach(el=>el.addEventListener('click',close));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});
  const pinMin=parseInt(modal.dataset.pinMin,10)||4,pinMax=parseInt(modal.dataset.pinMax,10)||8;
  const pinValue=modal.querySelector('[data-zau-security-pin-value]'),pinConfirm=modal.querySelector('[data-zau-security-pin-confirm]'),pinError=modal.querySelector('[data-zau-security-pin-error]'),pinSave=modal.querySelector('[data-zau-security-pin-save]');
  const showPinError=msg=>{if(!pinError)return;pinError.textContent=msg||'';pinError.hidden=!msg;};
  pinSave?.addEventListener('click',async()=>{
    const pin=(pinValue?.value||'').trim(),confirmVal=(pinConfirm?.value||'').trim();
    if(!/^\d+$/.test(pin)||pin.length<pinMin||pin.length>pinMax){showPinError(`PIN должен содержать от ${pinMin} до ${pinMax} цифр.`);return;}
    if(pin!==confirmVal){showPinError('PIN и подтверждение не совпадают.');return;}
    showPinError('');pinSave.disabled=true;
    try{await ajax('zau_union_set_login_pin',{pin,pin_confirm:confirmVal});showPinError('PIN сохранён.');if(pinValue)pinValue.value='';if(pinConfirm)pinConfirm.value='';}
    catch(e){showPinError(e.message||'Не удалось сохранить PIN.');}
    finally{pinSave.disabled=false;}
  });
  const pwCurrent=modal.querySelector('[data-zau-security-password-current]'),pwNew=modal.querySelector('[data-zau-security-password-new]'),pwConfirm=modal.querySelector('[data-zau-security-password-confirm]'),pwError=modal.querySelector('[data-zau-security-password-error]'),pwSave=modal.querySelector('[data-zau-security-password-save]');
  const showPwError=msg=>{if(!pwError)return;pwError.textContent=msg||'';pwError.hidden=!msg;};
  pwSave?.addEventListener('click',async()=>{
    const current=pwCurrent?.value||'',next=pwNew?.value||'',confirmVal=pwConfirm?.value||'';
    if(!current){showPwError('Введите текущий пароль.');return;}
    if(next.length<8){showPwError('Новый пароль должен быть не короче 8 символов.');return;}
    if(next!==confirmVal){showPwError('Новый пароль и подтверждение не совпадают.');return;}
    showPwError('');pwSave.disabled=true;
    try{await ajax('zau_union_change_password',{current_password:current,new_password:next,new_password_confirm:confirmVal});showPwError('Пароль изменён.');if(pwCurrent)pwCurrent.value='';if(pwNew)pwNew.value='';if(pwConfirm)pwConfirm.value='';}
    catch(e){showPwError(e.message||'Не удалось изменить пароль.');}
    finally{pwSave.disabled=false;}
  });
}
function initAll(scope=document){
 initAuth(scope);
 initCabinetTabs(scope);
 initForms();
 initCabinetRecovery();
 initCabinetDocumentsRefresh();
 initOrganizationMembers();
 initMemberCards();
 initDocumentModals();
 initOrganizationRegistry();
 initBenefitModals();
 initPinPrompt();
 initSecurityModal();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>initAll(document));else initAll(document);
window.addEventListener('elementor/frontend/init',()=>{
 if(window.elementorFrontend?.hooks){
  window.elementorFrontend.hooks.addAction('frontend/element_ready/global',scope=>{const node=scope?.[0]||scope||document;initAuth(node);initCabinetTabs(node);initOrganizationMembers();initMemberCards();});
 }
});
const authObserver=new MutationObserver(mutations=>{
 mutations.forEach(mutation=>mutation.addedNodes.forEach(node=>{
  if(node&&node.nodeType===1)initAuth(node);
 }));
});
if(document.documentElement)authObserver.observe(document.documentElement,{childList:true,subtree:true});
})();
