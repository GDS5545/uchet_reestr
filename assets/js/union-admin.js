(function(){
'use strict';
const root=document.getElementById('zau-union-form-editor');
if(!root)return;
const holder=document.getElementById('zau-form-fields');
const hidden=document.getElementById('zau-union-fields-json');
const add=document.getElementById('zau-add-form-field');
const types=(window.ZAUUnionAdmin&&ZAUUnionAdmin.fieldTypes)||{};
let fields=[];
try{fields=JSON.parse(hidden.value||'[]');if(!Array.isArray(fields))fields=[];}catch(e){fields=[];}
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function newField(){return {key:'field_'+Math.floor(Math.random()*9000+1000),label:'Новое поле',type:'text',required:0,placeholder:'',options:'',default:''};}
function sync(){hidden.value=JSON.stringify(fields);}
function typeOptions(current){return Object.entries(types).map(([v,l])=>`<option value="${esc(v)}" ${v===current?'selected':''}>${esc(l)}</option>`).join('');}
function render(){holder.innerHTML='';fields.forEach((f,i)=>{
 const row=document.createElement('div');row.className='zau-field-row';row.dataset.index=i;
 row.innerHTML=`<div class="zau-field-row-head"><strong>Поле ${i+1}</strong><div><button type="button" class="button" data-move="up" ${i===0?'disabled':''}>↑</button> <button type="button" class="button" data-move="down" ${i===fields.length-1?'disabled':''}>↓</button> <button type="button" class="button button-link-delete" data-remove>Удалить</button></div></div>
 <div class="zau-field-grid">
 <label>Название<input data-prop="label" value="${esc(f.label)}"></label>
 <label>Ключ для PDF<input data-prop="key" value="${esc(f.key)}" pattern="[A-Za-z0-9_-]+"><small>Латиница, цифры и _</small></label>
 <label>Тип<select data-prop="type">${typeOptions(f.type||'text')}</select></label>
 <label>Подсказка<input data-prop="placeholder" value="${esc(f.placeholder||'')}"></label>
 <label>Значение по умолчанию<input data-prop="default" value="${esc(f.default||'')}"></label>
 <label class="zau-field-required"><input type="checkbox" data-prop="required" ${Number(f.required)?'checked':''}> Обязательное</label>
 <label class="zau-field-options">Варианты списка<textarea data-prop="options" rows="3" placeholder="Один вариант в строке. Можно: value|Название">${esc(f.options||'')}</textarea></label>
 </div>`;
 row.querySelectorAll('[data-prop]').forEach(input=>input.addEventListener('input',()=>{const p=input.dataset.prop;fields[i][p]=input.type==='checkbox'?(input.checked?1:0):input.value;sync();if(p==='type')row.classList.toggle('has-options',input.value==='select');}));
 row.querySelector('[data-remove]').addEventListener('click',()=>{if(confirm('Удалить это поле?')){fields.splice(i,1);sync();render();}});
 row.querySelectorAll('[data-move]').forEach(btn=>btn.addEventListener('click',()=>{const ni=btn.dataset.move==='up'?i-1:i+1;if(ni<0||ni>=fields.length)return;[fields[i],fields[ni]]=[fields[ni],fields[i]];sync();render();}));
 row.classList.toggle('has-options',(f.type||'text')==='select');holder.appendChild(row);
 });sync();}
add.addEventListener('click',()=>{fields.push(newField());render();});
root.querySelector('form').addEventListener('submit',()=>sync());
render();
})();
