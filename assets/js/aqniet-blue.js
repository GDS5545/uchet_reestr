(function(){
'use strict';
const App=window.ZAUUnionData||{};
const ready=(fn)=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();

function applyBrand(){
 const color=/^#[0-9a-f]{6}$/i.test(App.designPrimary||'')?App.designPrimary:'#1565C0';
 document.documentElement.style.setProperty('--zau-brand',color);
}

function applyAuthButtonDesign(){
 const root=document.documentElement;
 const bg=/^#[0-9a-f]{6}$/i.test(App.designAuthButtonBg||'')?App.designAuthButtonBg:'#1565C0';
 const text=/^#[0-9a-f]{6}$/i.test(App.designAuthButtonText||'')?App.designAuthButtonText:'#ffffff';
 const radius=parseInt(App.designAuthButtonRadius,10);
 const fontSize=parseInt(App.designAuthButtonFontSize,10);
 const weight=['400','600','700','800'].includes(String(App.designAuthButtonFontWeight))?App.designAuthButtonFontWeight:'700';
 root.style.setProperty('--zau-auth-btn-bg',bg);
 root.style.setProperty('--zau-auth-btn-text',text);
 root.style.setProperty('--zau-auth-btn-radius',(Number.isFinite(radius)?radius:9)+'px');
 root.style.setProperty('--zau-auth-btn-font-size',(Number.isFinite(fontSize)?fontSize:15)+'px');
 root.style.setProperty('--zau-auth-btn-font-weight',weight);
 const closePos=['right','center','left'].includes(App.designModalClosePosition)?App.designModalClosePosition:'right';
 root.setAttribute('data-zau-modal-close',closePos);
}

function normalize(s){return String(s||'').toLocaleLowerCase('ru-RU').trim();}

function initQuickLinks(scope=document){
 const roots=[];
 if(scope.matches?.('[data-zau-cabinet-tabs]'))roots.push(scope);
 scope.querySelectorAll?.('[data-zau-cabinet-tabs]').forEach(r=>roots.push(r));
 roots.forEach(root=>{
  root.querySelectorAll('[data-zau-open-tab]').forEach(link=>{
   if(link.dataset.zauQuickReady==='1')return;
   link.dataset.zauQuickReady='1';
   link.addEventListener('click',e=>{
    const key=link.dataset.zauOpenTab;
    const tab=root.querySelector('[data-zau-tab="'+CSS.escape(key)+'"]');
    if(!tab)return;
    e.preventDefault();tab.click();
    root.scrollIntoView({behavior:'smooth',block:'start'});
   });
  });
 });
}

function initDocumentSearch(scope=document){
 const toolbars=[];
 if(scope.matches?.('[data-zau-document-toolbar]'))toolbars.push(scope);
 scope.querySelectorAll?.('[data-zau-document-toolbar]').forEach(t=>toolbars.push(t));
 toolbars.forEach(toolbar=>{
  if(toolbar.dataset.zauDocToolbarReady==='1')return;
  toolbar.dataset.zauDocToolbarReady='1';
  const section=toolbar.closest('[data-zau-tab-panel="documents"]')||toolbar.parentElement;
  const search=toolbar.querySelector('[data-zau-document-search]');
  const filter=toolbar.querySelector('[data-zau-document-filter]');
  const count=toolbar.querySelector('[data-zau-document-count]');
  const update=()=>{
   const q=normalize(search?.value),state=filter?.value||'';
   const cards=[...section.querySelectorAll('[data-zau-document-card]')];
   let shown=0;
   cards.forEach(card=>{
    const okText=!q||normalize(card.dataset.documentSearch||card.textContent).includes(q);
    const okState=!state||card.dataset.documentState===state;
    card.hidden=!(okText&&okState);if(!card.hidden)shown++;
   });
   if(count)count.textContent='Показано: '+shown;
  };
  search?.addEventListener('input',update);filter?.addEventListener('change',update);section.addEventListener('zau:documents-refreshed',update);update();
 });
}

const stepDefinitions=[
 {key:'contact',label:'Контакты',title:'Личные и контактные данные',match:(key,node)=>/^(first_name|last_name|middle_name|full_name|member_name_header|iin|birth_date|phone|email|region|address)$/.test(key)},
 {key:'organization',label:'Организация',title:'Организация по БИН',match:(key,node)=>key.startsWith('organization')||node.classList.contains('zau-field-bin_lookup')},
 {key:'branch',label:'Филиал',title:'Филиал и реквизиты',match:(key,node)=>key==='branch'||key.startsWith('branch_')||node.classList.contains('zau-field-branch_select')},
 {key:'details',label:'Данные',title:'Данные заявления',match:()=>false},
 {key:'signature',label:'Подпись',title:'Согласия и подпись',match:(key,node)=>key.includes('signature')||node.classList.contains('zau-field-signature')||node.classList.contains('zau-field-checkbox')},
 {key:'pin',label:'PIN',title:'PIN для входа',match:(key,node)=>node.matches('[data-zau-registration-security]')}
];
function nodeStep(node){
 if(node.matches('[data-zau-registration-security]'))return 'pin';
 const key=node.dataset.fieldKey||'';
 for(const def of stepDefinitions){if(def.key!=='details'&&def.match(key,node))return def.key;}
 return 'details';
}
function validatePanel(panel){
 const fields=[...panel.querySelectorAll('input,select,textarea')].filter(el=>!el.disabled&&el.type!=='hidden');
 for(const field of fields){if(!field.checkValidity()){field.reportValidity();field.focus({preventScroll:true});field.scrollIntoView({behavior:'smooth',block:'center'});return false;}}
 return true;
}
function initStepForm(form){
 if(form.dataset.zauAqnietSteps!=='1'||form.dataset.zauStepReady==='1')return;
 form.dataset.zauStepReady='1';
 const actions=form.querySelector(':scope > .zau-form-actions');
 if(!actions)return;
 const direct=[...form.children];
 const movable=direct.filter(node=>node.matches?.('.zau-form-field,.zau-form-heading,[data-zau-registration-security]'));
 if(movable.length<3)return;
 const buckets=new Map(stepDefinitions.map(d=>[d.key,[]]));
 let lastKey='details';
 movable.forEach(node=>{
  let key=node.matches('.zau-form-heading')?lastKey:nodeStep(node);
  if(node.matches('.zau-form-heading')){
   const t=normalize(node.textContent);
   if(t.includes('организац'))key='organization';else if(t.includes('филиал')||t.includes('реквизит'))key='branch';else if(t.includes('подпис')||t.includes('соглас'))key='signature';else key='details';
  }else lastKey=key;
  buckets.get(key).push(node);
 });
 const activeDefs=stepDefinitions.filter(d=>(buckets.get(d.key)||[]).length);
 if(activeDefs.length<2)return;
 const stepper=document.createElement('div');stepper.className='zau-aqniet-stepper';
 const track=document.createElement('div');track.className='zau-aqniet-stepper-track';track.setAttribute('role','tablist');
 const panels=[];
 activeDefs.forEach((def,index)=>{
  const button=document.createElement('button');button.type='button';button.className='zau-aqniet-stepper-item';button.dataset.stepIndex=String(index);button.setAttribute('role','tab');
  button.innerHTML='<span class="zau-aqniet-stepper-number">'+(index+1)+'</span><span class="zau-aqniet-stepper-label">'+def.label+'</span>';
  track.appendChild(button);
  const panel=document.createElement('section');panel.className='zau-aqniet-step-panel';panel.dataset.stepIndex=String(index);panel.setAttribute('role','tabpanel');
  panel.innerHTML='<div class="zau-aqniet-step-title"><span>'+(index+1)+'</span><h3>'+def.title+'</h3></div>';
  buckets.get(def.key).forEach(node=>panel.appendChild(node));
  const nav=document.createElement('div');nav.className='zau-aqniet-step-nav';
  if(index>0){const prev=document.createElement('button');prev.type='button';prev.className='zau-union-button zau-aqniet-prev';prev.textContent='Назад';prev.addEventListener('click',()=>activate(index-1));nav.appendChild(prev);}else nav.appendChild(document.createElement('span'));
  if(index<activeDefs.length-1){const next=document.createElement('button');next.type='button';next.className='zau-union-button zau-aqniet-next';next.textContent='Далее';next.addEventListener('click',()=>{if(validatePanel(panel))activate(index+1);});nav.appendChild(next);}
  panel.appendChild(nav);actions.before(panel);panels.push(panel);
 });
 stepper.appendChild(track);form.querySelector('.zau-union-form-head')?.after(stepper);
 const buttons=[...track.children];let current=0;
 function activate(index){
  index=Math.max(0,Math.min(activeDefs.length-1,index));current=index;
  panels.forEach((p,i)=>p.hidden=i!==index);
  buttons.forEach((b,i)=>{b.classList.toggle('is-active',i===index);b.classList.toggle('is-complete',i<index);b.setAttribute('aria-selected',i===index?'true':'false');b.tabIndex=i===index?0:-1;});
  form.classList.toggle('is-last-step',index===activeDefs.length-1);
  stepper.scrollIntoView({behavior:'smooth',block:'start'});
 }
 buttons.forEach((b,i)=>b.addEventListener('click',()=>{if(i<=current||buttons[i].classList.contains('is-complete'))activate(i);}));
 form.classList.add('is-step-ready');activate(0);
 form.addEventListener('submit',e=>{if(!form.classList.contains('is-last-step')){e.preventDefault();if(validatePanel(panels[current]))activate(current+1);}},true);
}
function initStepForms(scope=document){
 if(scope.matches?.('[data-zau-aqniet-steps="1"]'))initStepForm(scope);
 scope.querySelectorAll?.('[data-zau-aqniet-steps="1"]').forEach(initStepForm);
}

function init(scope=document){applyBrand();applyAuthButtonDesign();initQuickLinks(scope);initDocumentSearch(scope);initStepForms(scope);}
ready(()=>init(document));
window.addEventListener('elementor/frontend/init',()=>{
 if(window.elementorFrontend?.hooks)window.elementorFrontend.hooks.addAction('frontend/element_ready/global',scope=>init(scope?.[0]||scope||document));
});
const observer=new MutationObserver(list=>list.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType===1)init(n);}))); 
ready(()=>observer.observe(document.documentElement,{childList:true,subtree:true}));
})();
