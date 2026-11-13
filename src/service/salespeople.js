'use strict';
Object.assign(names,{salespeople:'业务员管理'});
function salespersonName(id){const s=(data.salespeople||[]).find(s=>s.id===id);return s?s.name+(s.active?'':'（已停用）'):'未分配';}
function assignSalespeople(ids){
 const customers=ids.map(id=>data.parties.find(p=>p.id===id)).filter(p=>p?.kind==='customer');
 if(!customers.length){toast('请先选择客户');return;}
 const current=customers.every(p=>p.salesperson_id===customers[0].salesperson_id)?customers[0].salesperson_id:'';
 show(`<h2>分配业务员</h2><form id="salespeople-assignment"><p>已选择 ${customers.length} 位客户。选择业务员后保存，可改派或取消分配。</p><div class="panel">${table(['客户','当前业务员'],customers.map(p=>[E(p.name),E(salespersonName(p.salesperson_id))]))}</div><label>分配给<select name="salesperson_id"><option value="__choose" disabled selected>请选择业务员或取消分配</option><option value="">取消分配（无业务员）</option>${(data.salespeople||[]).filter(s=>s.active).map(s=>`<option value="${E(s.id)}" ${s.id===current?'selected':''}>${E(s.name)}${s.phone?' · '+E(s.phone):''}</option>`).join('')}</select></label><p class="muted">仅显示启用的业务员。业务员资料可在“业务员管理”中创建。</p>${foot('保存分配')}</form>`);
 const f=$('#salespeople-assignment');
 f.onsubmit=async e=>{e.preventDefault();if(busy)return;if(f.elements.salesperson_id?.value==='__choose'){toast('请选择业务员，或明确选择取消分配');return;}busy=true;const submit=f.querySelector('[type=submit]');submit.disabled=true;
  try{await call({action:'assign_salespeople',salesperson_id:f.elements.salesperson_id.value,customers:customers.map(p=>({id:p.id,version:Number(p.version)}))});$('#modal').close();await load();toast(`已更新 ${customers.length} 位客户的业务员分配`);}
  catch(error){f.querySelector('.form-error').hidden=false;f.querySelector('.form-error').textContent=error.message;}
  finally{busy=false;submit.disabled=false;}
 };
}
(()=>{
 let showInactive=false;
 const previous=render;
 render=function(){if(location.hash.slice(1)!=='salespeople'){previous();return;}page='salespeople';$('#main').className='salespeople-workspace';paint();};
 function paint(){
  const rows=filtered(data.salespeople||[]).filter(s=>showInactive||s.active);
  $('#main').innerHTML=`<div class="head"><div><h1>业务员管理</h1><p class="muted">管理负责客户维系的业务员，客户归属在客户档案中分配。</p></div><button type="button" class="primary" data-salesperson-new>新建业务员</button></div><div class="bar"><input id="search" type="search" aria-label="搜索业务员" placeholder="搜索姓名、电话、备注" value="${E(search)}"><label class="check"><input type="checkbox" data-salesperson-inactive ${showInactive?'checked':''}>显示停用</label><a class="button" href="#parties">客户分配</a></div><div class="panel">${table(['姓名','联系电话','负责客户数','状态','备注','操作'],rows.map(s=>[E(s.name),E(s.phone||'—'),data.parties.filter(p=>p.kind==='customer'&&p.salesperson_id===s.id).length,s.active?'启用':'停用',E(s.note||'—'),`<button type="button" data-salesperson-edit="${E(s.id)}">编辑</button>`]))}</div>`;
  $('#main [data-salesperson-new]').onclick=()=>edit('');$('#main [data-salesperson-inactive]').onchange=e=>{showInactive=e.target.checked;paint();};
  $('#main').querySelectorAll('[data-salesperson-edit]').forEach(b=>b.onclick=()=>edit(b.dataset.salespersonEdit));
 }
 function edit(id){const s=(data.salespeople||[]).find(s=>s.id===id)||{};
  show(`<h2>${id?'编辑':'新建'}业务员</h2><form id="salespeople-editor"><div class="grid">${input('姓名 *','name',s.name||'','text','required maxlength="80"')}${input('联系电话','phone',s.phone||'','tel','maxlength="80"')}<label class="wide">备注<textarea name="note" maxlength="1000">${E(s.note||'')}</textarea></label><label class="check"><input type="checkbox" name="active" ${s.active===0?'':'checked'}>启用</label></div><p class="muted">停用后不可新分配客户，已有客户归属会保留，可到客户档案改派。</p>${foot('保存业务员')}</form>`);
  const f=$('#salespeople-editor');f.onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const submit=f.querySelector('[type=submit]');submit.disabled=true;
   try{await call({action:'save_salesperson',id,version:Number(s.version||0),name:f.elements.name.value,phone:f.elements.phone.value,note:f.elements.note.value,active:f.elements.active.checked});$('#modal').close();await load();toast('业务员已保存');}
   catch(error){f.querySelector('.form-error').hidden=false;f.querySelector('.form-error').textContent=error.message;}
   finally{busy=false;submit.disabled=false;}
  };
 }
})();
