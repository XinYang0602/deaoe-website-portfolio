'use strict';
// Shared inventory data and modal helpers; no separate legacy page.
Object.assign(names,{production:'组装与拆装',boms:'生产模板',locations:'仓位记录'});
function locationFields(id){
 return `<details class="product-locations"><summary>所在仓库 · 仓位</summary><div class="location-scroll"><table><thead><tr><th>仓库名称</th><th>货位</th></tr></thead><tbody>${data.warehouses.filter(w=>w.active&&w.id!=='LEGACY').map(w=>{const r=(data.locations||[]).find(r=>r.product_id===id&&r.warehouse_id===w.id)||{};return `<tr data-location="${E(w.id)}" data-version="${r.version||0}"><td>${E(w.name)}</td><td><input aria-label="${E(w.name)}货位" data-shelf maxlength="161" value="${E([r.shelf,r.level].filter(Boolean).join(' · '))}" placeholder="例如 A架2层"></td></tr>`;}).join('')}</tbody></table></div></details>`;
}
function locationValues(f){return [...f.querySelectorAll('[data-location]')].map(r=>({warehouse_id:r.dataset.location,version:Number(r.dataset.version),shelf:r.querySelector('[data-shelf]').value,level:''}));}
(() => {
 const previous=render;
 render=function(){const route=location.hash.slice(1);if(!['production','boms','locations'].includes(route)){previous();return;}page=route;$('#main').className='production-workspace';paint();};
 const label=id=>stockProductLabel(product(id));
 const prodSelect=(name,value='')=>`<select name="${name}" required><option value="">选择产品</option>${opts(data.products.filter(p=>p.active),'id',stockProductLabel,value)}</select>`;
 const act=(text,action,id='')=>`<button type="button" data-production="${action}" data-id="${E(id)}">${text}</button>`;
 function paint(){
  let rows,heads,controls='';
  if(page==='boms'){
   controls=act('新增模板','template');heads=['模板名称','成品','零部件种类','操作'];rows=(data.boms||[]).map(b=>[E(b.name),E(label(b.product_id)),data.bom_parts.filter(p=>p.bom_id===b.id).length,act('修改','template',b.id)]);
  }else if(page==='locations'){
   heads=['产品编号','产品名称 / 型号','仓库','货架','层数','操作'];rows=(data.locations||[]).filter(r=>r.shelf||r.level).map(r=>[E(product(r.product_id).code),E(product(r.product_id).name+' / '+product(r.product_id).model),E(warehouse(r.warehouse_id)),E(r.shelf||'—'),E(r.level||'—'),button('修改','edit-product',r.product_id)]);
  }else{
   controls=act('新增组装单','assembly')+act('新增拆装单','disassembly');heads=['单号 / 日期','类型','成品','仓库','数量','状态','操作'];rows=[...(data.production||[])].sort((a,b)=>b.created_at.localeCompare(a.created_at)).map(d=>[`${E(d.id)}<small>${E(d.date)}</small>`,d.kind==='assembly'?'组装':'拆装',E(label(d.product_id)),E(warehouse(d.warehouse_id)),d.qty,states[d.status],act('查看','view',d.id)]);
  }
  $('#main').innerHTML=`<section class="panel"><div class="head"><h1>${names[page]}</h1><div>${controls}${button('刷新','refresh')}</div></div><nav class="production-tabs"><a href="#production">组装 / 拆装单</a><a href="#boms">生产模板</a><a href="#locations">仓位记录</a></nav><div class="production-table-scroll">${table(heads,rows)}</div></section>`;
 }
 function partRow(p={}){return `<tr><td>${prodSelect('part',p.product_id)}</td><td><input name="usage" aria-label="每台用量" type="number" min="1" max="2000" required value="${p.qty||1}"></td><td>${act('移除','remove')}</td></tr>`;}
 function template(id){const b=data.boms.find(b=>b.id===id)||{};show(`<h2>${id?'修改':'新增'}生产模板</h2><form id="production-template"><div class="grid">${input('模板名称','name',b.name||'','text','required maxlength="100"')}<label>成品${prodSelect('product_id',b.product_id)}</label></div><table><thead><tr><th>零部件</th><th>每台成品用量</th><th></th></tr></thead><tbody>${(id?data.bom_parts.filter(p=>p.bom_id===id):[{}]).map(partRow).join('')}</tbody></table>${act('＋ 添加零部件','add-part')}${foot('保存模板')}</form>`);
  const f=$('#production-template');f.onsubmit=e=>save(e,f,{operation:'template',id,version:b.version||0,name:f.elements.name.value,product_id:f.elements.product_id.value,parts:[...f.querySelectorAll('tbody tr')].map(r=>({product_id:r.querySelector('[name=part]').value,qty:Number(r.querySelector('[name=usage]').value)}))});
 }
 async function create(kind){
  show(`<h2>新增${kind==='assembly'?'组装':'拆装'}单</h2><form id="production-create"><div class="grid">${input('单号','number','','text','readonly')}${input('业务日期','date',today(),'date','required')}${select('生产模板','bom_id',data.boms)}${select('仓库','warehouse_id',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'))}${input('成品数量','qty',1,'number','required min="1" max="2000"')}${input('备注','note','','text','maxlength="1000"')}</div><div data-preview></div>${foot('保存草稿')}</form>`);
  const f=$('#production-create'),requestKey=key();let reservation='',reservedDate='',generation=0;
  async function reserve(){const g=++generation;reservation='';f.querySelector('[type=submit]').disabled=true;try{const d=f.elements.date.value;const r=await call({action:'reserve_number',type:kind==='assembly'?'ZZ':'CZ',request_key:requestKey,date:d});if(g!==generation||!f.isConnected)return;reservation=r.number;reservedDate=d;f.elements.number.value=reservation;f.querySelector('[type=submit]').disabled=false;}catch(e){toast(e.message);}}
  const preview=()=>{const bom=data.boms.find(b=>b.id===f.elements.bom_id.value),n=Number(f.elements.qty.value);f.querySelector('[data-preview]').innerHTML=bom?`<p>${kind==='assembly'?'扣减零部件 → 增加成品':'扣减成品 → 增加零部件'}：${E(label(bom.product_id))} × ${n}</p>`+table(['零部件','合计用量'],data.bom_parts.filter(r=>r.bom_id===bom.id).map(r=>[E(label(r.product_id)),r.qty*n])):'<p class="muted">请选择模板查看投入与产出。</p>';};
  f.elements.date.onchange=reserve;f.elements.bom_id.onchange=preview;f.elements.qty.oninput=preview;preview();
  f.onsubmit=e=>{const b=data.boms.find(b=>b.id===f.elements.bom_id.value);if(!b||!reservation){e.preventDefault();toast('请选择模板并等待单号加载');return;}save(e,f,{operation:'create',kind,bom_id:b.id,bom_version:b.version,warehouse_id:f.elements.warehouse_id.value,qty:Number(f.elements.qty.value),date:f.elements.date.value,note:f.elements.note.value,request_key:requestKey,reserved_number:reservation,reserved_date:reservedDate});};await reserve();
 }
 function view(id){const d=data.production.find(d=>d.id===id),b=JSON.parse(d.body),parts=data.production_parts.filter(r=>r.doc_id===id),inputs=d.kind==='assembly'?parts:[{product_id:d.product_id,qty:d.qty}],result=JSON.parse(d.result);
  const serials=d.status==='submitted'?inputs.filter(r=>product(r.product_id).sn_enabled).map(r=>`<fieldset><legend>${E(label(r.product_id))} · 选择 ${r.qty} 个投入 SN</legend><div class="production-serials">${data.sn.filter(s=>s.product_id===r.product_id&&s.warehouse_id===d.warehouse_id&&s.status==='available'&&!data.card_links.some(c=>c.sn===s.sn)).map(s=>`<label><input type="checkbox" data-input-product="${E(r.product_id)}" value="${E(s.sn)}">${E(s.sn)}</label>`).join('')||'没有可用 SN'}</div></fieldset>`).join(''):'';
  let actions='';if(['draft','rejected'].includes(d.status))actions=act('提交审核','submit',id)+act('作废','void',id);if(d.status==='submitted')actions=act('审核入账','approve',id)+act('驳回','reject',id);
  show(`<h2>${d.kind==='assembly'?'组装':'拆装'}单 · ${E(d.id)}</h2><p>${E(d.date)} · ${E(warehouse(d.warehouse_id))} · ${states[d.status]}</p><p>成品：${E(label(d.product_id))} × ${d.qty}　模板：${E(b.template.name)}</p>${table(['零部件','数量'],parts.map(r=>[E(label(r.product_id)),r.qty]))}<p>${E(b.note)}</p><p class="muted">审核后整单更新库存；投入批次与 SN 留存追溯，产出成本暂记为未记录。</p>${serials}${result.outputs?`<h3>产出记录</h3>${table(['产品','数量','SN / 批次'],result.outputs.map(r=>[E(label(r.product_id)),r.qty,E((r.serials||[r.lot_id]).join('、'))]))}`:''}<div class="foot">${actions}${button('关闭','close')}</div>`);
 }
 async function save(e,f,payload){e.preventDefault();e.stopPropagation();const b=f.querySelector('[type=submit]');if(b.disabled)return;b.disabled=true;try{const r=await call({action:'production',...payload});await load();$('#modal').close();if(payload.operation==='create')view(r.id);}catch(e){const err=f.querySelector('.form-error');err.textContent=e.message;err.hidden=false;}finally{b.disabled=false;}}
 document.addEventListener('click',async e=>{const b=e.target.closest('[data-production]');if(!b)return;const a=b.dataset.production,id=b.dataset.id;
  if(a==='remove'){b.closest('tr').remove();return;}if(a==='add-part'){$('#production-template tbody').insertAdjacentHTML('beforeend',partRow());return;}
  if(a==='template'){template(id);return;}if(['assembly','disassembly'].includes(a)){await create(a);return;}if(a==='view'){view(id);return;}
  if(!confirm(a==='approve'?'确认审核？将扣减投入库存并增加产出库存。':'确认此操作？'))return;
  b.disabled=true;try{const d=data.production.find(d=>d.id===id),serials={};document.querySelectorAll('[data-input-product]:checked').forEach(el=>(serials[el.dataset.inputProduct]??=[]).push(el.value));await call({action:'production',operation:'transition',id,version:d.version,transition:a,serials});await load();view(id);}catch(e){toast(e.message);}finally{b.disabled=false;}
 });
})();

function pricePresetFields(id){const prices=data.price_presets?.[id]||[];return `<details class="product-locations"><summary>预设售价</summary><div class="grid">${Array.from({length:10},(_,i)=>`<label>预设售价${i+1}<input data-price-preset type="number" min="0" max="99999999.99" step="0.01" value="${prices[i]==null?'':money(prices[i])}" aria-label="预设售价${i+1}"></label>`).join('')}</div></details>`;}
function setupSalePricing(f,owner,p,recalculate){
 const controls=document.createElement('div');controls.innerHTML='<button type="button" data-pick-price>选择预设售价</button> <label class="check"><input type="checkbox" data-track-price>同一客户价格跟踪</label><small data-price-source></small>';f.querySelector('.item-fields').after(controls);
 const apply=(cents,note)=>{f.elements.unit.value=cents==null?'':money(cents);f.elements.discount.value='1';recalculate();controls.querySelector('[data-price-source]').textContent=note;};
 controls.querySelector('[data-track-price]').onchange=e=>{if(!e.target.checked)return;const customer=owner.elements.party_id.value;const docs=data.docs.filter(d=>d.kind==='sale'&&d.status==='approved'&&d.party_id===customer).sort((a,b)=>b.date.localeCompare(a.date)||b.id.localeCompare(a.id));let line,doc;for(const d of docs){line=data.lines.filter(l=>l.doc_id===d.id&&l.product_id===p.id).at(-1);if(line){doc=d;break;}}apply(line?line.price_cents:p.sale_cents,line?'历史成交价 · '+doc.id:'无历史成交价，使用参考销售价');};
 controls.querySelector('[data-pick-price]').onclick=()=>{const dialog=document.createElement('dialog');dialog.setAttribute('aria-label','选择单价');const prices=[...(data.price_presets?.[p.id]||Array(10).fill(null)).map((v,i)=>['预设售价'+(i+1),v]),['参考销售价',p.sale_cents]];dialog.innerHTML='<h3>请选择单价</h3>'+prices.map(([label,v],i)=>`<p><button type="button" data-price-index="${i}" ${v==null?'disabled':''}>${label}　${v==null?'未设置':money(v)}</button></p>`).join('')+'<button type="button" data-close>关闭</button>';document.body.append(dialog);dialog.showModal();const close=()=>{dialog.close();dialog.remove();};dialog.oncancel=e=>{e.preventDefault();close();};dialog.onclick=e=>{const b=e.target.closest('[data-price-index]');if(b){const [label,v]=prices[Number(b.dataset.priceIndex)];apply(v,label);close();}else if(e.target.closest('[data-close]'))close();};};
}
