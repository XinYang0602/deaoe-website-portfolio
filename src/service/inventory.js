'use strict';
const $=s=>document.querySelector(s), E=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const names={overview:'进销存总览',products:'产品档案',warehouses:'仓库管理',parties:'供应商与客户',purchase:'采购订单',receipt:'采购入库',sale:'销售单',issue:'销售出库',balances:'库存与期初归属',serials:'SN 与标签',ledger:'库存流水',events:'操作日志'};
const states={draft:'草稿',submitted:'待审核',approved:'已审核',rejected:'已驳回',void:'已作废'},kinds={purchase:'采购订单',receipt:'入库单',sale:'销售单',issue:'出库单'};
let data=null,page='overview',search='',busy=false;
const key=()=>crypto.randomUUID(), money=n=>n===null||n===undefined?'未记录':(n/100).toFixed(2), today=()=>new Date().toLocaleDateString('sv-SE');
const product=id=>data.products.find(p=>p.id===id)||{name:id,unit:''}, warehouse=id=>data.warehouses.find(p=>p.id===id)?.name||id, party=id=>data.parties.find(p=>p.id===id)?.name||id;
const stockProductLabel=p=>`${p.code||p.id} · ${p.name} / ${p.model||'未填型号'}`;
const button=(text,action,id='',primary=false)=>`<button type="button" class="${primary?'primary':''}" data-action="${action}" data-id="${E(id)}">${text}</button>`;
const table=(heads,rows)=>`<table><thead><tr>${heads.map(x=>`<th>${x}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(c=>`<td>${c}</td>`).join('')}</tr>`).join(''):`<tr><td class="empty" colspan="${heads.length}">暂无记录</td></tr>`}</tbody></table>`;
const opts=(rows,value,label,selected)=>rows.map(x=>`<option value="${E(x[value])}" ${String(x[value])===String(selected)?'selected':''}>${E(typeof label==='function'?label(x):x[label])}</option>`).join('');
const input=(title,name,value='',type='text',extra='')=>`<label>${title}<input name="${name}" type="${type}" value="${E(value)}" ${extra}></label>`;
function select(title,name,rows,value='',label='name'){return `<label>${title}<select name="${name}"><option value="">请选择</option>${opts(rows,'id',label,value)}</select></label>`;}
function filtered(rows){const q=search.toLowerCase();return rows.filter(x=>JSON.stringify(x).toLowerCase().includes(q));}
function toast(s){$('#notice').textContent=s;$('#notice').style.display='block';setTimeout(()=>$('#notice').style.display='none',4000);}
async function call(a){snapshotEpoch++;snapshotAt=0;try{const r=await fetch('/service/api/inventory',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},body:JSON.stringify(a)});let b;try{b=await r.json();}catch{throw Error('会话可能过期，请返回后台重新登录');}if(!r.ok)throw Error(b.error||'保存失败');return b;}finally{snapshotEpoch++;snapshotAt=0;}}
// Memory-only snapshot. Explicit refresh and post-write reloads always bypass the TTL.
let snapshotAt=0,snapshotEpoch=0,snapshotFlight=null;
async function load({maxAge=0,quiet=false}={}){
 if(maxAge&&data&&Date.now()-snapshotAt<maxAge)return;
 if(snapshotFlight)return snapshotFlight;
 snapshotFlight=(async()=>{
  try{
   let epoch,next;
   do{
    epoch=snapshotEpoch;
    const r=await fetch('/service/api/inventory',{cache:'no-store'});
    if(!r.ok)throw Error(r.status===401?'登录已过期，请返回后台登录':'数据更新失败，请点击刷新重试');
    next=await r.json();
   }while(epoch!==snapshotEpoch);
   data=next;snapshotAt=Date.now();
   // Background updates must not replace an open editor or picker.
   if(!quiet||!$('#modal').open)render();
  }finally{snapshotFlight=null;}
 })();
 return snapshotFlight;
}
function refreshSnapshot(){
 if(!data)return; // The initial load is already running.
 load({maxAge:15000,quiet:true}).catch(e=>toast(e.message+'（当前显示上次数据）'));
}
window.addEventListener('focus',refreshSnapshot);
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refreshSnapshot();});

// Shared business-date range for desktop and mobile sales lists (inclusive).
const documentSort={sale:'desc',purchase:'desc'};
function compareDocuments(a,b,kind){const order=String(a.date||'').localeCompare(String(b.date||''))||String(a.id).localeCompare(String(b.id),undefined,{numeric:true});return documentSort[kind]==='asc'?order:-order;}
function documentSortControls(kind){return `<div class="sale-date-filters no-print" role="group" aria-label="日期排序"><button type="button" data-document-sort="desc" data-sort-kind="${kind}" aria-pressed="${documentSort[kind]==='desc'}" class="${documentSort[kind]==='desc'?'primary':''}">日期从新到旧</button><button type="button" data-document-sort="asc" data-sort-kind="${kind}" aria-pressed="${documentSort[kind]==='asc'}" class="${documentSort[kind]==='asc'?'primary':''}">日期从旧到新</button></div>`;}
document.addEventListener('click',event=>{const button=event.target.closest('[data-document-sort]');if(!button)return;const kind=button.dataset.sortKind,order=button.dataset.documentSort;if(!['sale','purchase'].includes(kind)||!['asc','desc'].includes(order))return;documentSort[kind]=order;render();});
const saleDates={from:'',to:''};
function saleDateMatches(doc){return (!saleDates.from||doc.date>=saleDates.from)&&(!saleDates.to||doc.date<=saleDates.to);}
function saleDateControls(){return `<div class="sale-date-filters no-print"><label>开始日期<input type="date" data-sale-date="from" value="${E(saleDates.from)}" max="${E(saleDates.to)}"></label><label>结束日期<input type="date" data-sale-date="to" value="${E(saleDates.to)}" min="${E(saleDates.from)}"></label><button type="button" data-sale-date-clear>清除日期</button></div>`;}
document.addEventListener('change',event=>{
 const field=event.target.dataset.saleDate;if(!['from','to'].includes(field))return;
 const value=event.target.value,next={...saleDates,[field]:value};
 if(!event.target.validity.valid||(next.from&&next.to&&next.from>next.to)){
  event.target.value=saleDates[field];toast('开始日期不能晚于结束日期');return;
 }
 saleDates[field]=value;render();
 [...document.querySelectorAll(`[data-sale-date="${field}"]`)].find(input=>input.getClientRects().length)?.focus();
});
document.addEventListener('click',event=>{if(event.target.closest('[data-sale-date-clear]')){saleDates.from='';saleDates.to='';render();}});
function show(html){$('#body').innerHTML=html;if(!$('#modal').open)$('#modal').showModal();}
function foot(label='保存草稿'){return `<div class="foot"><button type="button" data-action="close">取消</button><button type="submit" class="primary">${label}</button></div><p class="form-error error" hidden></p>`;}
function head(desc,actions=''){return `<div class="head"><div><h1>${names[page]}</h1><div class="muted">${desc}</div></div><div>${actions}</div></div>`;}

// 商品筛选：同组多选取并集，不同组取交集；分类以商品保存的资料为准。
let productFilters = {kind: [], family: [], name: []};
let productPage = 1, productPageSize = 20;
// Explicit directory paths do not change the existing product-name defaults.
function categoryParts(p){return String(p.category_path||p.category||'未分类').split(/\/|>+|›/).map(x=>x.trim()).filter(Boolean);}
function categoryPath(p){return categoryParts(p).join(' / ');}
function categoryEditor(p){
 const inherited=!p.id&&page==='products'?document.querySelector('[data-catalog-category][aria-current="true"]')?.dataset.catalogCategory:'';
 const path=p.category_path||inherited||'';
 return `<div class="wide category-editor"><div>产品分类</div><input type="hidden" name="category_path" value="${E(path)}"><div class="category-levels">${categoryLevelFields(path)}</div><div class="category-actions"><button type="button" data-category-add>＋ 添加下一级</button><button type="button" data-category-reset>沿用名称分类</button></div><output class="category-path-preview">${E(path?path.split(' / ').join(' › '):'沿用名称分类')}</output><small>逐级选择分类，或选择“新增分类”填写名称；保存产品后生效。</small></div>`;
}
function categoryLevelFields(path){
 const parts=path?path.split(' / '):[];
 return [...parts,''].slice(0,8).map((value,i)=>{
  const siblings=[...new Set(data.products.map(categoryParts).filter(p=>parts.slice(0,i).every((x,j)=>p[j]===x)).map(p=>p[i]).filter(Boolean))];
  if(value&&!siblings.includes(value))siblings.push(value);
  return `<div class="category-level"><label>第 ${i+1} 级<select data-category-level="${i}"><option value="">请选择分类</option>${siblings.map(n=>`<option value="${E(n)}" ${n===value?'selected':''}>${E(n)}</option>`).join('')}<option value="__new__">＋ 新增分类…</option></select></label><button type="button" data-category-remove="${i}" aria-label="移除第 ${i+1} 级及下级" ${value?'':'hidden'}>移除</button><div class="category-new" hidden><input aria-label="新分类名称" maxlength="60" placeholder="填写本级分类名称"><button type="button" data-category-confirm="${i}">添加</button><button type="button" data-category-cancel>取消新增</button></div></div>`;
 }).join('');
}
function setCategoryEditor(editor,parts){
 const path=parts.join(' / ');editor.querySelector('[name=category_path]').value=path;
 editor.querySelector('.category-levels').innerHTML=categoryLevelFields(path);
 editor.querySelector('.category-path-preview').textContent=parts.join(' › ')||'沿用名称分类';
 editor.querySelector('[data-category-add]').disabled=parts.length>=8;
}
function categoryDraft(editor){return editor.querySelector('[name=category_path]').value.split(' / ').filter(Boolean);}
document.addEventListener('change',e=>{
 if(!e.target.matches('[data-category-level]'))return;
 const editor=e.target.closest('.category-editor'),i=Number(e.target.dataset.categoryLevel);
 if(e.target.value==='__new__'){const box=e.target.closest('.category-level').querySelector('.category-new');box.hidden=false;box.querySelector('input').focus();return;}
 setCategoryEditor(editor,[...categoryDraft(editor).slice(0,i),...(e.target.value?[e.target.value]:[])]);
});
document.addEventListener('click',e=>{
 const b=e.target.closest('button'),editor=b?.closest('.category-editor');if(!editor)return;
 const parts=categoryDraft(editor);
 if(b.hasAttribute('data-category-cancel'))setCategoryEditor(editor,parts);
 if(b.hasAttribute('data-category-reset'))setCategoryEditor(editor,[]);
 if(b.hasAttribute('data-category-remove'))setCategoryEditor(editor,parts.slice(0,Number(b.dataset.categoryRemove)));
 if(b.hasAttribute('data-category-add')){const select=editor.querySelector('.category-level:last-child select');if(parts.length<8){select.value='__new__';select.dispatchEvent(new Event('change',{bubbles:true}));}}
 if(b.hasAttribute('data-category-confirm')){
  const input=b.previousElementSibling,name=input.value.trim();
  input.setCustomValidity(!name?'请输入分类名称':/[\/›>]/.test(name)?'分类名称不能包含路径分隔符':'');
  if(!input.reportValidity())return;
  setCategoryEditor(editor,[...parts.slice(0,Number(b.dataset.categoryConfirm)),name]);
 }
});
document.addEventListener('input',e=>{if(e.target.matches('.category-new input'))e.target.setCustomValidity('');});
document.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.matches('.category-new input')){e.preventDefault();e.target.nextElementSibling.click();}});
function productFacets(p) {
 const parts = String(p.category || '').split('/').map(s => s.trim());
 const kind = parts[parts.length - 1] || '未分类';
 const families = parts.length > 1 ? parts.slice(0, -1).join('/').split('、').filter(Boolean)
  : (['油烟机','燃气灶','热水器'].includes(p.name) ? [p.name] : ['未分类']);
 return {kind: [kind], family: families, name: [p.name || '未命名']};
}
function matchingProducts(rows, filters) {
 return rows.filter(p => {
  const facets = productFacets(p);
  return Object.keys(filters).every(k => !filters[k].length || filters[k].some(v => facets[k].includes(v)));
 });
}
function productSlice(rows, pageNumber, size) {
 const pages = Math.max(1, Math.ceil(rows.length / size));
 const current = Math.min(pages, Math.max(1, pageNumber));
 return {rows: rows.slice((current - 1) * size, current * size), current, pages, total: rows.length};
}
function currentProductSlice() {
 const result = productSlice(matchingProducts(filtered(data.products), productFilters), productPage, productPageSize);
 productPage = result.current;
 return result;
}
function productFilterBar() {
 const chosen = Object.values(productFilters).flat();
 return `<div class="product-filter-bar no-print">${button('分类筛选' + (chosen.length ? '（'+chosen.length+'）' : ''),'product-filter')} ${button('重置筛选','reset-product-filter')}<span class="muted">${chosen.length ? E(chosen.join(' · ')) : '全部分类'} · 批量操作仅针对本页勾选商品</span></div>`;
}
function productPager() {
 const r = currentProductSlice();
 return `<div class="product-pager no-print"><span>共 ${r.total} 件 · 第 ${r.current} / ${r.pages} 页</span><label>每页 <select id="product-page-size">${[20,50,100].map(n=>`<option value="${n}" ${n===productPageSize?'selected':''}>${n}</option>`).join('')}</select> 件</label><button data-action="product-prev" ${r.current===1?'disabled':''}>上一页</button><button data-action="product-next" ${r.current===r.pages?'disabled':''}>下一页</button></div>`;
}
function showProductFilters() {
 const groups = [['kind','商品类型'],['family','所属产品'],['name','商品名称']];
 show(`<h2>分类筛选</h2><p class="muted">可多选；每组不勾选表示全部。确认后更新列表，可同时使用关键词搜索。</p><div id="product-filter-options">${groups.map(([key,title])=>{
  const values = [...new Set([...data.products.flatMap(p=>productFacets(p)[key]), ...productFilters[key], ...(key==='kind'?['整机','配件','元器件','器材']:[])])].sort((a,b)=>a.localeCompare(b,'zh-CN'));
  return `<fieldset><legend>${title}</legend><div class="product-filter-options">${values.map(v=>`<label><input type="checkbox" data-filter="${key}" value="${E(v)}" ${productFilters[key].includes(v)?'checked':''}>${E(v)}</label>`).join('')}</div></fieldset>`;
 }).join('')}</div><div class="foot">${button('清空勾选','clear-product-filter')}${button('取消','close')}${button('确认筛选','apply-product-filter','',true)}</div>`);
}

function render(){page=names[location.hash.slice(1)]?location.hash.slice(1):'overview';$('#nav').innerHTML=Object.entries(names).map(([id,n])=>`<a class="${page===id?'active':''}" href="#${id}">${n}</a>`).join('');let html='';
if(page==='overview'){const available=data.balances.reduce((n,b)=>n+Number(b.qty)-Number(b.unidentified),0),waiting=data.balances.reduce((n,b)=>n+Number(b.unidentified),0);html=head('销售、采购、库存独立办理，按业务直接进入。')+`<section class="panel"><h2>常用业务</h2><div class="bar">${button('新增销售单','new-doc','sale',true)}<a class="button" href="#documents">单据中心</a>${button('新增采购订单','new-doc','purchase')}<a class="button" href="#receipt">采购入库</a><a class="button" href="#balances">库存查询</a></div></section>`+`<div class="stats">${[['启用商品',data.products.filter(p=>p.active).length],['库存总数',data.balances.reduce((n,b)=>n+Number(b.qty),0)],['待补录 SN',waiting],['待审核单据',data.docs.filter(d=>d.status==='submitted').length]].map(([t,n])=>`<div class="stat">${t}<b>${n}</b></div>`).join('')}</div><section class="panel"><h2>采购到销售</h2><p>采购订单 → 提交审核 → 关联入库单 → 审核入库 / 生成 SN → 打印标签 → 销售单（填写商品数量）→ 审核通过自动扣库存</p><p class="muted">单据审核后生效；参考价只用于带出，本次价格不会改写历史单据。金额按人民币两位小数记录，不进行成本与毛利核算。</p><div class="bar">${button('新增采购订单','new-doc','purchase',true)}${button('新增销售单','new-doc','sale')}<a class="button" href="#balances">核对期初库存</a></div></section><div class="warning">旧库存保留在“期初待归属仓库”。请依据实物登记实际仓库与 SN。已审核单据不可删除；退换货、仓间调拨和盘点请进入对应第二期页面。</div>`;}
else if(page==='products'){html=head('统一管理整机与配件，历史业务保留商品编号。',button('新增商品','master','products',true)+button('名称种类管理','product-names')+button('批量停用','archive')+button('批量删除','delete-products')+button('测试数据清理','test-cleanup'))+productFilterBar()+table(['选择','图片','编码 / 名称','分类 / 品牌','型号','单位','SN','参考采购价 / 销售价','状态','操作'],currentProductSlice().rows.map(p=>{const im=data.images.find(i=>i.product_id===p.id);return [`<input type="checkbox" data-product="${E(p.id)}">`,im?`<img class="photo" tabindex="0" alt="${E(p.name)}，悬停查看大图" src="/service/api/images?id=${E(im.id)}&thumb=1">`:'—',`${E(p.code)}<br><b>${E(p.name)}</b>`,`${E(p.category)} / ${E(p.brand)}`,E(p.model),E(p.unit),p.sn_enabled?'逐台':'数量',`${money(p.purchase_cents)} / ${money(p.sale_cents)}`,p.active?'启用':'停用',button('编辑','edit-product',p.id)+button('图片','images',p.id)+button('删除','delete-product',p.id)];}));html+=productPager();}
else if(page==='warehouses')html=head('仓库可自行增加；有库存或未完成单据不能停用。',button('新增仓库','master','warehouses',true))+table(['仓库名称','库存数量','状态','操作'],filtered(data.warehouses).map(w=>[E(w.name),data.balances.filter(b=>b.warehouse_id===w.id).reduce((n,b)=>n+Number(b.qty),0),w.active?'启用':'停用',w.id==='LEGACY'?'历史专用':button('编辑','edit-warehouse',w.id)+(w.id===data.sale_default_warehouse?' <strong>销售默认</strong>':w.active?button('设为销售默认','default-warehouse',w.id):'')]));
else if(page==='parties')html=head('供应商与销售客户独立建档，可后续扩展更多字段。',button('新增往来单位','master','parties',true))+table(['类型','名称','联系人','电话','地址','状态','操作'],filtered(data.parties).map(p=>[p.kind==='supplier'?'供应商':'客户',E(p.name),E(p.contact),E(p.phone),E(p.address),p.active?'启用':'停用',button('编辑','edit-party',p.id)]));
else if(kinds[page]){const rows=filtered(data.docs.filter(d=>d.kind===page&&(page!=='sale'||saleDateMatches(d))));if(['sale','purchase'].includes(page))rows.sort((a,b)=>compareDocuments(a,b,page));html=head('保存为草稿，提交后审核；审核时校验订单剩余数量和可售库存。',button('新建'+kinds[page],'new-doc',page,true))+(page==='sale'?saleDateControls():'')+(['sale','purchase'].includes(page)?documentSortControls(page):'')+table(['编号 / 日期','仓库','往来单位','状态','数量 / 金额','来源单','操作'],rows.map(d=>{const ls=data.lines.filter(l=>l.doc_id===d.id);return [`<span class="mono">${E(d.id)}</span><br>${E(d.date)}`,E([...new Set(data.lines.filter(l=>l.doc_id===d.id).map(l=>warehouse(l.warehouse_id||d.warehouse_id)))].join('、')||warehouse(d.warehouse_id)),E(party(d.party_id)),`<span class="badge">${states[d.status]}</span>`,`${ls.reduce((n,l)=>n+Number(l.qty),0)} / ¥${money(ls.reduce((n,l)=>n+l.qty*l.price_cents,0))}`,E(d.source_id||'—'),button('查看','doc',d.id)];}));}
else if(page==='balances')html=head('库存总数包含待检、不良和待补录数量。',data.can_adjust_stock?button('直接调整库存','adjust-stock','',true):'')+table(['商品','仓库','账面数量','待补录 SN','可用数量','单位','操作'],filtered(data.balances.map(b=>({...b,name:stockProductLabel(product(b.product_id)),warehouse:warehouse(b.warehouse_id)}))).map(b=>[E(b.name),E(b.warehouse),b.qty,b.unidentified,b.warehouse_id==='LEGACY'?'待核对':b.qty-b.unidentified-(data.holds||[]).filter(h=>h.product_id===b.product_id&&h.warehouse_id===b.warehouse_id).reduce((n,h)=>n+h.qty,0),E(product(b.product_id).unit),b.warehouse_id==='LEGACY'&&b.qty?button('核对并归属','opening',b.product_id):data.can_adjust_stock&&product(b.product_id).active&&data.warehouses.some(w=>w.id===b.warehouse_id&&w.active&&w.id!=='LEGACY')?`<button type="button" data-action="adjust-stock" data-id="${E(b.product_id)}" data-warehouse="${E(b.warehouse_id)}">调整库存</button>`:'—']));
else if(page==='serials')html=head('搜索系统 SN / 厂家编号，查看流转历史；补打标签不会生成新 SN。',button('打印简易 SN 标签','labels')+button('打印合格证 / 外箱标签','label-print','',true))+`<div class="bar"><label>标签尺寸<select id="label-size"><option>50×30</option><option>40×30</option><option>60×40</option></select></label><label>编码方式<select id="label-format"><option value="qr">二维码</option><option value="code128">Code 128</option><option value="text">纯文本</option></select></label></div>`+table(['选择','系统 SN','厂家 SN','商品 / 型号','仓库','状态','操作'],filtered(data.sn.map(s=>({...s,name:product(s.product_id).name,model:product(s.product_id).model}))).map(s=>[`<input type="checkbox" data-sn="${E(s.sn)}">`,`<span class="mono">${E(s.sn)}</span>`,E(s.manufacturer_sn||'—'),`${E(s.name)} / ${E(s.model)}`,E(warehouse(s.warehouse_id)),({available:'在库',sold:'已售',transit:'在途',quarantine:'待检',defective:'不良',lost:'盘亏',returned_supplier:'已退供应商'})[s.status]||s.status,button('追踪','sn',s.sn)]));
else if(page==='ledger')html=head('期初结存及每次已审核出入库，按原始价格保留。')+table(['时间','单据','商品','仓库','数量变化','单价','金额'],filtered(data.ledger.map(l=>({...l,name:product(l.product_id).name,warehouse:warehouse(l.warehouse_id)}))).reverse().map(l=>[E(l.created_at),E(l.doc_id),E(l.name),E(l.warehouse),l.delta>0?'+'+l.delta:l.delta,money(l.price_cents),l.price_cents===null?'未记录':money(Math.abs(l.delta)*l.price_cents)]));
else if(page==='events')html=head('最近 300 次操作；打印仅记录发起请求，不代表打印机已出纸。')+table(['时间','操作人','操作','对象','内容'],filtered(data.events).map(e=>[E(e.created_at),E(e.actor),E(e.action),E(e.target),`<span style="white-space:normal;max-width:500px;display:block">${E(e.detail)}</span>`]));
$('#main').innerHTML=page==='overview'?html:`${html.startsWith('<div class="head"')?'':''}<div class="bar no-print"><input id="search" type="search" placeholder="搜索当前列表" value="${E(search)}">${button('刷新','refresh')}${button(page==='products'?'导出本页 Excel':'导出 Excel','export')}${button(page==='products'?'打印本页':'打印列表','print-list')}</div><section class="panel">${html}</section>`;
}
function productNameRow(entry={}){
 if(typeof entry!=='object'||entry===null)entry={};
 const choice=(name,values,value)=>`<select name="${name}">${values.map(v=>`<option value="${E(v)}" ${v===value?'selected':''}>${E(v||'请选择')}</option>`).join('')}</select>`;
 return `<tr><td class="name-row-number"></td><td><input name="product_name" maxlength="200" placeholder="例如：燃气灶点火针" value="${E(entry.name||'')}"></td><td>${choice('name_kind',['','整机','配件','元器件','器材'],entry.kind||'')}</td><td><div class="name-families">${['油烟机','燃气灶','热水器'].map(f=>`<label><input type="checkbox" name="name_family" value="${f}" ${(entry.families||[]).includes(f)?'checked':''}>${f}</label>`).join('')}</div></td><td>${choice('name_unit',['台','个','件','套','米'],entry.unit||'个')}</td><td><label class="name-sn"><input type="checkbox" name="name_sn" ${entry.sn_enabled?'checked':''}>追踪</label></td><td><button type="button" data-action="remove-name-row" aria-label="移除此名称行">移除</button></td></tr>`;
}
function applyProductNameDefaults(form){
 const entry=data.product_names.entries.find(e=>e.name===form.elements.name.value);
 if(!entry||!entry.kind||!entry.families.length){form.elements.category.value='待配置';form.dataset.applyNameDefaults='true';toast('请先在名称种类管理中完善该名称配置');return;}
 form.elements.category.value=entry.families.join('、')+' / '+entry.kind;
 form.elements.unit.value=entry.unit;form.elements.sn_enabled.checked=entry.sn_enabled;
 form.dataset.applyNameDefaults='true';
}
function numberNameRows(){
 document.querySelectorAll('#product-name-rows tr').forEach((row,i)=>{
  row.querySelector('.name-row-number').textContent=i+1;
  row.querySelector('input').setAttribute('aria-label',`第 ${i+1} 行商品名称`);
 });
}
function productNameSelect(current){
 const names=data.product_names.names;
 const values=current&&!names.includes(current)?[current,...names]:names;
 return `<label>名称 *<select name="name" required><option value="">请选择名称</option>${values.map(n=>`<option value="${E(n)}" ${n===current?'selected':''}>${E(n)}${!names.includes(n)?'（原名称）':''}</option>`).join('')}</select></label>`;
}
function master(type,id=''){const collection=data[type],p=collection.find(x=>x.id===id)||{};let fields=input('名称 *','name',p.name||'','text','required maxlength="200"');if(type==='products')fields=input('产品编号（留空自动生成）','code',p.code||'','text','maxlength="200" placeholder="保存时自动生成"')+productNameSelect(p.name||'')+input('分类（自动带出）','category',p.category||'请选择名称','text','readonly')+categoryEditor(p)+input('品牌','brand',p.brand||'德澳')+input('型号','model',p.model||'')+`<label>计量单位<select name="unit">${[...new Set(['台','个','件','套','米',p.unit||'台'])].map(u=>`<option ${u===(p.unit||'台')?'selected':''}>${E(u)}</option>`).join('')}</select></label>`+input('参考采购价（元）','purchase_price',p.purchase_cents==null?'':money(p.purchase_cents),'number','step="0.01" min="0"')+input('参考销售价（元）','sale_price',p.sale_cents==null?'':money(p.sale_cents),'number','step="0.01" min="0"')+input('适用型号（逗号分隔）','models',p.models||'')+`<label class="check"><input name="sn_enabled" type="checkbox" ${p.sn_enabled===0?'':'checked'}>逐台 SN 追踪（整机默认开启）</label>`;
if(type==='parties')fields=`<label>类型<select name="kind"><option value="supplier" ${p.kind==='supplier'?'selected':''}>供应商</option><option value="customer" ${p.kind==='customer'?'selected':''}>客户</option></select></label>`+fields+input('联系人','contact',p.contact||'')+input('电话','phone',p.phone||'')+input('地址','address',p.address||'');
show(`<h2>${id?'编辑':'新增'}${type==='products'?'商品':type==='warehouses'?'仓库':'往来单位'}</h2><form id="master-form" data-type="${type}" data-id="${E(id)}" data-version="${p.version||0}"><div class="grid">${fields}<label class="check"><input type="checkbox" name="active" ${p.active===0?'':'checked'} ${type==='products'&&!id?'disabled':''}>启用${type==='products'&&!id?'（新产品默认启用）':''}</label></div>${type==='products'?'<p class="muted">选择名称会带出分类、单位和 SN 默认值；已有库存历史时不可更改单位或 SN 模式。</p><button type="button" data-action="apply-name-defaults">重新带出名称默认设置</button>':''}${type==='products'?productImageEditor(id):''}${foot('保存档案')}</form>`);}
function serialParse(text){return text.split(/\r?\n/).map(s=>s.trim()).filter(Boolean).map(s=>{let v=s.split(/[,，\t]/).map(x=>x.trim());if(v.length>2)throw Error('每行只能填写系统 SN、厂家 SN 两列');return {sn:v[0],manufacturer_sn:v[1]||''};});}
function lineHTML(l={},kind='purchase',source=false){const p=product(l.product_id),serials=typeof l.serials==='string'?JSON.parse(l.serials):l.serials||[];return `<div class="line" data-source="${E(l.source_line||'')}" data-warehouse="${E(l.warehouse_id||'')}"><label>商品<select name="product" ${source?'disabled':''} required><option value="">请选择商品</option>${opts(data.products.filter(p=>p.active||p.id===l.product_id),'id',p=>p.code+' · '+p.name+' / '+p.model,l.product_id)}</select></label><label>数量<input name="qty" type="number" min="1" max="1000000" step="1" required value="${l.qty||1}"></label><label>本单单价（元）<input name="price" type="number" min="0" step="0.01" required value="${l.price_cents==null?'':money(l.price_cents)}"></label>${kind==='sale'?'':`<label>${['sale','issue'].includes(kind)?'销售 SN：每行一个（数量须一致）':kind==='receipt'?'系统 SN,厂家 SN：留空自动生成':'SN 在收发货时填写'}<textarea name="serials" ${kind==='purchase'?'disabled':''} placeholder="${['sale','issue'].includes(kind)?'选择、扫码或粘贴本仓系统 SN；非 SN 商品留空':'每行：系统SN,厂家SN；仅厂家号填 ,厂家SN'}">${E(serials.map(s=>s.sn+(s.manufacturer_sn?','+s.manufacturer_sn:'')).join('\n'))}</textarea>${['receipt','issue','sale'].includes(kind)?'<input class="sn-file" type="file" accept=".txt,.csv" aria-label="导入 SN 文本">':''}</label>`}<button type="button" data-action="remove-line">移除</button></div>`;}
function docForm(kind,id='',sourceId=''){const d=data.docs.find(d=>d.id===id)||{},source=data.docs.find(d=>d.id===(sourceId||d.source_id));if(['receipt','issue'].includes(kind)&&!source){const parents=data.docs.filter(d=>d.kind===(kind==='receipt'?'purchase':'sale')&&d.status==='approved');show(`<h2>选择已审核的${kind==='receipt'?'采购订单':'销售单'}</h2><p class="muted">支持分批收货 / 出库，系统审核时校验累计数量。</p>${table(['编号','往来','仓库','操作'],parents.map(p=>[E(p.id),E(party(p.party_id)),E(warehouse(p.warehouse_id)),button('关联创建','from-source',p.id)]))}<div class="foot">${button('关闭','close')}</div>`);return;}
let lines=id?data.lines.filter(l=>l.doc_id===id):source?data.lines.filter(l=>l.doc_id===source.id).map(l=>({...l,source_line:l.id,qty:l.qty-data.lines.filter(x=>x.source_line===l.id&&data.docs.find(d=>d.id===x.doc_id)?.status==='approved').reduce((n,x)=>n+x.qty,0),serials:[]})).filter(l=>l.qty>0):[{}];
if(!lines.length){toast('该订单已全部收发完成');return;}
show(`<h2>${id?'编辑':'新建'}${kinds[kind]}</h2><form id="doc-form" data-kind="${kind}" data-id="${E(id)}" data-source="${E(source?.id||'')}" data-version="${d.version||0}" data-key="${key()}"><div class="grid">${input('业务日期','date',d.date||today(),'date','required')}${select('仓库','warehouse_id',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),d.warehouse_id||source?.warehouse_id||(kind==='sale'?data.sale_default_warehouse:''))}${select(kind==='purchase'||kind==='receipt'?'供应商':'客户','party_id',data.parties.filter(p=>p.active&&p.kind===(['purchase','receipt'].includes(kind)?'supplier':'customer')),d.party_id||source?.party_id)}<label class="wide">备注<textarea name="note" maxlength="2000">${E(d.note||'')}</textarea></label></div>${source?`<p>来源：${E(source.id)}</p>`:''}<div id="lines">${lines.map(l=>lineHTML(l,kind,!!source)).join('')}</div>${!source?button('添加商品行','add-line'):''}<p class="muted">价格保留两位小数。入库空 SN 将在审核时生成；销售无需录入 SN，审核按数量扣减可售库存。保存草稿不改变库存。</p>${foot()}</form>`);if(source){$('#doc-form [name=warehouse_id]').disabled=true;$('#doc-form [name=party_id]').disabled=true;}}
function detail(id){const d=data.docs.find(x=>x.id===id);if(!d)return;const ls=data.lines.filter(l=>l.doc_id===id);let actions='';if(['draft','rejected'].includes(d.status))actions=button('编辑','edit-doc',id)+button('提交审核','submit',id,true)+button('作废','void',id);if(d.status==='submitted')actions=button('审核通过并生效','approve',id,true)+button('驳回','reject',id);if(d.status==='approved'&&d.kind==='purchase')actions=button('关联入库','from-source',id,true);if(d.status==='approved'&&d.kind==='sale'&&saleRemaining(id))actions=button('历史销售补发','from-source',id,true);if(d.status==='approved'&&d.kind==='receipt')actions+=button('打印本单 SN 标签','doc-labels',id);show(`<div id="doc-print"><h2>${kinds[d.kind]} · ${states[d.status]}</h2><p class="mono">${E(id)} · ${E(d.date)}</p><p>仓库：${E([...new Set(data.lines.filter(l=>l.doc_id===d.id).map(l=>warehouse(l.warehouse_id||d.warehouse_id)))].join('、')||warehouse(d.warehouse_id))}　往来：${E(party(d.party_id))}</p>${['sale','issue'].includes(d.kind)?`<div class="delivery-print"><p>电话：${E(d.delivery_phone||'未记录')}　联系人：${E(d.delivery_contact||'未记录')}</p><p><strong>送货地址：${E(d.delivery_address||'未记录')}</strong></p></div>`:''}<p>来源：${E(d.source_id||'—')}　备注：${E(d.note||'—')}</p>${d.reason?`<p class="warning">${E(d.reason)}</p>`:''}${table(['商品 / 型号','数量','单价（元）','金额（元）',...(d.kind==='sale'?[]:['SN / 厂家 SN'])],ls.map(l=>[`${E(product(l.product_id).name)} / ${E(product(l.product_id).model)}<br>仓库：${E(warehouse(l.warehouse_id||d.warehouse_id))}<br><small class="mono">${E(l.id)}</small>`,l.qty+' '+E(product(l.product_id).unit),money(l.price_cents),money(l.qty*l.price_cents),...(d.kind==='sale'?[]:[`<div style="white-space:normal;max-width:380px">${JSON.parse(l.serials).map(s=>E(s.sn)+(s.manufacturer_sn?' / '+E(s.manufacturer_sn):'')).join('<br>')||'—'}</div>`])]))}<p>金额合计：¥ ${money(ls.reduce((n,l)=>n+l.qty*l.price_cents,0))}</p></div><div class="foot">${button('导出单据 Excel','export-doc')}${button('打印单据','print-doc')}${button('关闭','close')}${actions}</div>`);}
function opening(id){const p=product(id),b=data.balances.find(b=>b.product_id===id&&b.warehouse_id==='LEGACY');show(`<h2>期初实物核对 · ${E(p.name)}</h2><p class="warning">待归属 ${b.qty} ${E(p.unit)}。此操作将核对数量归属实际仓库，不增加总库存。历史价格仍为“未记录”。</p><form id="opening-form" data-id="${E(id)}" data-key="${key()}"><div class="grid">${select('实际仓库','warehouse_id',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'))}${input('本次核实数量','qty',1,'number',`min="1" max="${Math.min(b.qty,2000)}" required`)}<label class="wide">核对依据<textarea name="note" required placeholder="实物清点日期、责任人、来源凭据"></textarea></label>${p.sn_enabled?'<label class="wide">逐台实物 SN（每行 系统SN,厂家SN；不得随意编造）<textarea name="serials" required></textarea></label>':''}</div>${foot('确认归属并记录')}</form>`);}
function productImageEditor(id){
 return `<section class="wide product-image-editor" data-image-product="${E(id)}"><h3>产品图片</h3><div class="product-edit-gallery">${productImageGallery(id)}</div>${id?'<label>选择图片<input data-product-image-file type="file" accept="image/jpeg,image/png,image/webp"></label><button type="button" data-upload-product-image>上传图片</button><small>JPG / PNG / WebP，单张不超过 8MB；第一张为主图。图片单独保存，不影响尚未保存的产品资料。</small>':'<small>请先保存产品档案，再在修改窗口添加图片。</small>'}<p data-image-status role="status"></p></section>`;
}
function productImageGallery(id){return data.images.filter(i=>i.product_id===id).map(i=>`<a href="/service/api/images?id=${E(i.id)}" target="_blank" rel="noopener"><img src="/service/api/images?id=${E(i.id)}&thumb=1" alt="${E(i.name)}"></a>`).join('')||'<span class="muted">暂无图片</span>';}
document.addEventListener('click',async event=>{
 const trigger=event.target.closest('[data-upload-product-image]');if(!trigger)return;
 const section=trigger.closest('[data-image-product]'),field=section.querySelector('[data-product-image-file]'),status=section.querySelector('[data-image-status]'),file=field.files[0];
 if(!file){status.textContent='请先选择图片';field.focus();return;}
 if(file.size>8*1024*1024){status.textContent='图片超过 8MB，请选择较小的图片';return;}
 trigger.disabled=true;field.disabled=true;status.textContent='正在上传…';
 try{
  const body=new FormData();body.append('product_id',section.dataset.imageProduct);body.append('image',file);
  const response=await fetch('/service/api/images',{method:'POST',headers:{'X-CSRF-Token':data.csrf},body});
  let result;try{result=await response.json();}catch{throw Error('上传未完成，请检查文件大小或重新登录');}
  if(!response.ok)throw Error(result.error||'上传失败');
  field.value='';status.textContent='图片已上传，正在更新预览…';
  await load();
  if(section.isConnected){section.querySelector('.product-edit-gallery').innerHTML=productImageGallery(section.dataset.imageProduct);status.textContent='图片已保存';}
 }catch(error){status.textContent=error.message;}
 finally{trigger.disabled=false;field.disabled=false;}
});
function images(id){const p=product(id);show(`<h2>${E(p.name)} · 商品图片</h2><p class="muted">第一张为主图，其余为附图。JPG / PNG / WebP，单张不超过 8MB、1600 万像素；自动压缩，保留透明背景。</p><div class="gallery">${data.images.filter(i=>i.product_id===id).map(i=>`<a href="/service/api/images?id=${E(i.id)}" target="_blank"><img src="/service/api/images?id=${E(i.id)}" alt="${E(i.name)}"></a>`).join('')}</div><form id="image-form" data-id="${E(id)}"><p><input name="image" type="file" accept="image/jpeg,image/png,image/webp" required></p>${foot('上传图片')}</form>`);}
function exportTable(el){const rows=[...el.querySelectorAll('tr')].map(r=>[...r.children].map(c=>{let v=c.innerText;if(/^[=+\-@\t\r]/.test(v))v="'"+v;return '"'+v.replace(/"/g,'""')+'"';}).join(','));const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\ufeff'+rows.join('\r\n')],{type:'text/csv;charset=utf-8'}));a.download=names[page]+'-'+today()+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);}
async function labels(serials,size='50×30',format='qr'){if(!serials.length)throw Error('请先选择 SN');if(serials.length>500)throw Error('每次最多 500 张标签');if(format==='code128'&&serials.some(s=>s.length>28))throw Error('长 SN 请使用二维码标签，避免条码过密');const win=window.open('','_blank');if(!win)throw Error('请允许此页面弹出打印窗口');try{await call({action:'print',serials,template:size+'/'+format});const [w,h]=size.split('×').map(Number);win.document.write(labelDocument(serials.map(sn=>({...data.sn.find(s=>s.sn===sn),product:product(data.sn.find(s=>s.sn===sn).product_id)})),w,h,format));win.document.close();}catch(e){win.close();throw e;}}
document.addEventListener('click',async e=>{const b=e.target.closest('[data-action]');if(!b||busy)return;const a=b.dataset.action,id=b.dataset.id;try{
if(a==='product-filter'){showProductFilters();return;}
if(a==='clear-product-filter'){document.querySelectorAll('[data-filter]').forEach(el=>el.checked=false);return;}
if(a==='apply-product-filter'){
 for(const key of Object.keys(productFilters)) productFilters[key]=[...document.querySelectorAll(`[data-filter="${key}"]:checked`)].map(el=>el.value);
 productPage=1;$('#modal').close();render();return;
}
if(a==='reset-product-filter'){productFilters={kind:[],family:[],name:[]};productPage=1;render();return;}
if(a==='product-prev'||a==='product-next'){productPage+=a==='product-next'?1:-1;render();return;}
if(a==='apply-name-defaults'){applyProductNameDefaults($('#master-form'));return;}
if(a==='product-names'){
 show(`<h2>名称种类管理</h2><p class="muted">配置名称对应的类型、所属产品、单位和 SN。配件、元器件和器材可勾选多个所属产品；调整配置不会自动改写已有商品。</p><form id="product-names-form" data-version="${E(data.product_names.version)}"><div class="name-sheet"><table><thead><tr><th scope="col">序号</th><th scope="col">商品名称</th><th scope="col">商品类型</th><th scope="col">所属产品</th><th scope="col">默认单位</th><th scope="col">默认 SN</th><th scope="col">操作</th></tr></thead><tbody id="product-name-rows">${data.product_names.entries.map(productNameRow).join('')}</tbody></table></div><button type="button" class="name-add-row" data-action="add-name-row">新增一行</button><p class="muted">空白行不会保存，最多 200 个名称。</p>${foot('保存名称种类')}</form>`);numberNameRows();return;
}
if(a==='add-name-row'){
 const rows=$('#product-name-rows');if(rows.children.length>=200)throw Error('最多添加 200 行');
 rows.insertAdjacentHTML('beforeend',productNameRow());numberNameRows();rows.lastElementChild.querySelector('input').focus();return;
}
if(a==='remove-name-row'){
 const row=b.closest('tr'),next=row.nextElementSibling||row.previousElementSibling;
 row.remove();numberNameRows();if(next)next.querySelector('input').focus();else $('[data-action="add-name-row"]').focus();return;
}
if(a==='test-cleanup'){
 const ids=[...document.querySelectorAll('[data-product]:checked')].map(x=>x.dataset.product);
 if(!ids.length)throw Error('请先勾选需要删除的测试商品');
 busy=true;const preview=await call({action:'test_cleanup_preview',ids});
 const labels={inv_fin_sales:'销售记账',inv_fin_entries:'应收及收退款流水',inv_fin_purchases:'采购记账',inv_fin_aux:'应付/预收预付及抵扣流水',inv_fin_links:'收付款单据关联',inv_fin_requests:'收付款请求',inv_fin_payment_accounts:'收付款账号快照',inv_docs:'采购/销售/出入库单',inv_lines:'单据明细',inv_sn:'SN',inv_balances:'库存余额',inv_ops:'退换货/调拨/盘点业务','state.cards':'电子保修卡','state.orders':'售后工单','state.stock':'旧库存','state.moves':'旧流水'};
 const impact=preview.summary.products.map(p=>`${p.delete?'删除商品':'保留档案、清空关联库存业务'}：${p.code} ${p.name}`).join('\n');
 const counts=Object.entries(preview.summary.counts).map(([k,n])=>`${labels[k]||k}：${n}`).join('\n');
 const confirmation=prompt('仅用于测试！混合单据整单删除，关联会继续扩展到后续业务。\n'+impact+'\n\n'+counts+'\n\n系统将先备份。客户账号、仓库、往来单位和审计日志保留。\n请输入“删除测试数据”确认：');
 if(confirmation!=='删除测试数据')return;
 const result=await call({action:'test_cleanup_execute',ids,fingerprint:preview.fingerprint,confirmation});
 await load();alert('测试数据已清理。数据库备份：\n'+result.backup);return;
}
if(a==='delete-product'||a==='delete-products'){const ids=a==='delete-product'?[id]:[...document.querySelectorAll('[data-product]:checked')].map(x=>x.dataset.product);if(!ids.length)throw Error('请先勾选商品');if(!confirm('确认删除 '+ids.length+' 个商品？删除后不可恢复；有关联库存、单据或 SN 的商品不能删除。'))return;busy=true;await call({action:'delete_products',ids});await load();toast('商品已删除');return;}if(a==='archive'){const ids=[...document.querySelectorAll('[data-product]:checked')].map(x=>x.dataset.product);if(!ids.length)throw Error('请先勾选商品');if(!confirm('停用所选零库存商品？历史保留，可在编辑中重新启用。'))return;busy=true;await call({action:'archive',ids});await load();toast('已停用，历史保留');return;}if(a==='default-warehouse'){await call({action:'set_default_warehouse',warehouse_id:id});await load();toast('已设置销售默认仓库');return;}if(a==='close')return $('#modal').close();if(a==='refresh')return await load();if(a==='master')return master(id);if(a==='edit-product')return master('products',id);if(a==='edit-warehouse')return master('warehouses',id);if(a==='edit-party')return master('parties',id);if(a==='images')return images(id);if(a==='new-doc')return docForm(id);if(a==='doc')return detail(id);if(a==='edit-doc')return docForm(data.docs.find(d=>d.id===id).kind,id);if(a==='from-source')return docForm(data.docs.find(d=>d.id===id).kind==='purchase'?'receipt':'issue','',id);if(a==='opening')return opening(id);if(a==='add-line'){const f=$('#doc-form');$('#lines').insertAdjacentHTML('beforeend',lineHTML({},f.dataset.kind));return;}if(a==='remove-line'){b.closest('.line').remove();return;}if(a==='export-doc')return exportTable($('#doc-print table'));if(a==='export')return exportTable($('#main table'));if(a==='print-list')return window.print();if(a==='print-doc'){const w=window.open('','_blank');if(!w)throw Error('请允许弹出窗口');w.document.write('<!doctype html><meta charset="utf-8"><title>单据打印</title><style>body{font:14px sans-serif;padding:30px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:10px}</style>'+$('#doc-print').innerHTML);w.document.close();w.print();return;}
if(a==='labels')return await labels([...document.querySelectorAll('[data-sn]:checked')].map(x=>x.dataset.sn),$('#label-size').value,$('#label-format').value);if(a==='doc-labels')return await labels(data.sn.filter(s=>data.lines.some(l=>l.doc_id===id&&l.id===s.receipt_line)).map(s=>s.sn));
if(a==='sn'){const s=data.sn.find(s=>s.sn===id);show(`<h2>SN 流转历史</h2><p class="mono">${E(id)}</p><p>${E(product(s.product_id).name)} / ${E(s.manufacturer_sn||'无厂家编号')}</p>${table(['时间','单据','仓库','状态'],data.sn_events.filter(x=>x.sn===id).map(x=>[E(x.created_at),E(x.doc_id),E(warehouse(x.warehouse_id)),x.status==='sold'?'已售':'在库']))}<div class="foot">${button('关闭','close')}</div>`);return;}
if(['submit','approve','reject','void'].includes(a)){const d=data.docs.find(d=>d.id===id);let reason='';if(['reject','void'].includes(a)){reason=prompt('请填写'+(a==='reject'?'驳回':'作废')+'原因');if(!reason)return;}if(a==='approve'&&!confirm(d.kind==='sale'?'确认审核销售单并直接扣库存？系统将校验本仓可售库存，已生效单据不可直接删除。':'确认审核生效？收发货审核会改变库存，已生效单据不可直接删除。'))return;busy=true;await call({action:'transition',id,version:d.version,transition:a,reason});await load();detail(id);toast('操作已保存');}
}catch(e){toast(e.message);}finally{busy=false;}});
document.addEventListener('submit',async e=>{const f=e.target;if(!f.id.endsWith('-form')||['code-rule-form','move-category-form'].includes(f.id))return;e.preventDefault();if(busy)return;
if(f.id==='master-form'&&f.dataset.type==='products'){
 const pending=f.querySelector('.category-new:not([hidden])');
 if(pending){const err=f.querySelector('.form-error');err.textContent='分类尚未添加，请先点击“添加”，或“取消新增”后再保存。';err.hidden=false;pending.querySelector('input').focus();return;}
}
busy=true;const submit=f.querySelector('[type=submit]');submit.disabled=true;const err=f.querySelector('.form-error');err.hidden=true;const values=Object.fromEntries(new FormData(f));try{let result;
if(f.id==='product-names-form')result=await call({action:'save_product_names',version:f.dataset.version,entries:[...f.querySelectorAll('#product-name-rows tr')].filter(row=>row.querySelector('[name=product_name]').value.trim()).map(row=>({name:row.querySelector('[name=product_name]').value.trim(),kind:row.querySelector('[name=name_kind]').value,families:[...row.querySelectorAll('[name=name_family]:checked')].map(el=>el.value),unit:row.querySelector('[name=name_unit]').value,sn_enabled:row.querySelector('[name=name_sn]').checked}))});
if(f.id==='master-form'){result=await call({...values,action:'master',name_config_version:data.product_names.version,apply_name_defaults:f.dataset.applyNameDefaults==='true',type:f.dataset.type,id:f.dataset.id,version:Number(f.dataset.version),active:!!f.elements.active.checked,sn_enabled:!!f.elements.sn_enabled?.checked});}
if(f.id==='doc-form'){const ls=[...f.querySelectorAll('.line')].map(el=>({product_id:el.querySelector('[name=product]').value,warehouse_id:el.dataset.warehouse||f.elements.warehouse_id.value,qty:Number(el.querySelector('[name=qty]').value),price:el.querySelector('[name=price]').value,source_line:el.dataset.source,serials:serialParse(el.querySelector('[name=serials]')?.value||'')}));result=await call({action:'save_doc',...values,warehouse_id:f.elements.warehouse_id.value,party_id:f.elements.party_id.value,id:f.dataset.id,kind:f.dataset.kind,source_id:f.dataset.source,version:Number(f.dataset.version),request_key:f.dataset.key,lines:ls});}
if(f.id==='opening-form')result=await call({...values,action:'opening',product_id:f.dataset.id,qty:Number(values.qty),serials:serialParse(values.serials||''),request_key:f.dataset.key});
if(f.id==='image-form'){const body=new FormData(f);body.append('product_id',f.dataset.id);const r=await fetch('/service/api/images',{method:'POST',headers:{'X-CSRF-Token':data.csrf},body});let b;try{b=await r.json();}catch{throw Error('上传未完成，可能超过服务器上传限制');}if(!r.ok)throw Error(b.error);}
await load();if(f.id==='master-form'&&f.dataset.type==='products'&&!f.dataset.id)document.dispatchEvent(new CustomEvent('catalog-product-created',{detail:{id:result.id}}));if(f.id==='image-form')images(f.dataset.id);else if(f.id==='doc-form')detail(result.id);else $('#modal').close();toast('已保存到服务器');
}catch(e){err.textContent=e.message;err.hidden=false;}finally{busy=false;submit.disabled=false;}});
document.addEventListener('change',async e=>{
if(e.target.matches('#master-form[data-type="products"] [name=name]'))applyProductNameDefaults(e.target.form);
if(e.target.id==='product-page-size'){productPageSize=Number(e.target.value);productPage=1;render();return;}
if(e.target.matches('[name=name_kind]')){const row=e.target.closest('tr');row.querySelector('[name=name_unit]').value=e.target.value==='整机'?'台':'个';row.querySelector('[name=name_sn]').checked=e.target.value==='整机';}
if(e.target.matches('.line [name=product]')){const p=product(e.target.value),f=$('#doc-form'),v=['purchase','receipt'].includes(f.dataset.kind)?p.purchase_cents:p.sale_cents;e.target.closest('.line').querySelector('[name=price]').value=v==null?'':money(v);}if(e.target.matches('.sn-file')){try{const file=e.target.files[0];if(!file)return;if(file.size>200000)throw Error('SN 文本不能超过 200KB');const text=await file.text();serialParse(text);e.target.closest('.line').querySelector('[name=serials]').value=text;toast('已导入，保存和审核时将验证 SN');}catch(err){toast(err.message);}}});
document.addEventListener('input',e=>{if(e.target.id==='search'){search=e.target.value;productPage=1;const pos=e.target.selectionStart;render();$('#search').focus();$('#search').setSelectionRange(pos,pos);}});
window.addEventListener('hashchange',()=>{$('#modal').close();search='';if(data){render();refreshSnapshot();}});window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});load().catch(e=>{$('#main').innerHTML=`<div class="error">${E(e.message)}</div><p><a href="/service/">返回后台</a></p>`;});

// Product thumbnail preview lives outside the table's scrolling container.
(() => {
  const preview = document.createElement('div');
  preview.hidden = true;
  preview.setAttribute('aria-hidden', 'true');
  preview.style.cssText = 'position:fixed;z-index:2000;pointer-events:none;padding:12px;background:#fff;border:1px solid #dce2e9;border-radius:12px;box-shadow:0 12px 36px #26313e30;box-sizing:border-box';
  const image = document.createElement('img');
  image.alt = '';
  image.style.cssText = 'display:block;width:100%;height:100%;object-fit:contain';
  preview.append(image);
  document.body.append(preview);
  let active = null;
  function hide() {
    active = null;
    preview.hidden = true;
    image.removeAttribute('src');
  }
  function show(target) {
    if (!(target instanceof Element) || !target.matches('img.photo')) return;
    if (active === target) return;
    active = target;
    const rect = target.getBoundingClientRect();
    const width = Math.min(380, window.innerWidth - 24);
    const height = Math.min(340, window.innerHeight - 24);
    let left = rect.right + 14;
    if (left + width > window.innerWidth - 12) left = rect.left - width - 14;
    left = Math.max(12, Math.min(left, window.innerWidth - width - 12));
    const top = Math.max(12, Math.min(rect.top, window.innerHeight - height - 12));
    Object.assign(preview.style, { width: width + 'px', height: height + 'px', left: left + 'px', top: top + 'px' });
    const url = new URL(target.src, location.href);
    url.searchParams.delete('thumb');
    image.src = url.href;
    preview.hidden = false;
  }
  image.addEventListener('error', hide);
  document.addEventListener('mouseover', event => show(event.target));
  document.addEventListener('mouseout', event => { if (event.target === active) hide(); });
  document.addEventListener('focusin', event => show(event.target));
  document.addEventListener('focusout', event => { if (event.target === active) hide(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
  document.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  window.addEventListener('hashchange', hide);
  new MutationObserver(() => { if (active && !active.isConnected) hide(); })
    .observe(document.body, { childList: true, subtree: true });
})();

// Legacy approved sales retain a contextual completion action; never deduct them on migration.
function saleRemaining(id){return data.lines.filter(l=>l.doc_id===id).some(l=>Number(l.qty)>data.lines.filter(c=>c.source_line===l.id&&data.docs.some(d=>d.id===c.doc_id&&d.kind==='issue'&&d.status==='approved')).reduce((n,c)=>n+Number(c.qty),0));}

// Customer selection keeps the live sales form (including unsaved lines) intact.
(function customerFirstSales() {
  let activePicker = null;
  function dismissPicker() {
    if (!activePicker) return;
    const panel = activePicker;
    activePicker = null;
    panel.close();
    panel.remove();
  }
  document.querySelector('#modal').addEventListener('close', dismissPicker);
  window.addEventListener('hashchange', dismissPicker);
  window.addEventListener('inventory-navigation', dismissPicker);
  const originalShow = show;
  show = function(html) {
    dismissPicker();
    originalShow(html);
    const form = document.querySelector('#doc-form[data-kind="sale"]');
    if (!form) return;
    const customer = form.elements.party_id;
    customer.required = true;
    form.elements.warehouse_id.required = true;
    const label = customer.closest('label');
    label.classList.add('sales-customer-field');
    label.parentElement.prepend(label);
    const choose = document.createElement('button');
    choose.type = 'button';
    choose.className = 'sales-customer-trigger';
    choose.textContent = customer.value ? party(customer.value) + ' ›' : '选择客户 / 搜索 / 新建 ›';
    customer.hidden = true;
    label.append(choose);
    choose.onclick = () => openCustomerPicker(form, choose);
    customer.addEventListener('invalid', event => { event.preventDefault(); alert('请选择客户'); openCustomerPicker(form, choose); });
  };

  function openCustomerPicker(sales, trigger) {
    dismissPicker();
    const panel = document.createElement('dialog');
    activePicker = panel;
    const desktop = matchMedia('(min-width:701px)').matches;
    let selectedCustomer = sales.elements.party_id.value;
    panel.className = 'sales-customer-picker' + (desktop ? ' desktop-selector customer-desktop' : '');
    panel.setAttribute('aria-label','选择客户');
    panel.innerHTML = `<header><h2>选择客户</h2><button type="button" class="picker-back">返回销售单</button></header>
      <input class="customer-search" placeholder="搜索客户名称、联系人、电话" aria-label="搜索客户">
      <button type="button" class="customer-new">新增客户</button><div class="customer-results"></div>
      <form class="customer-create" hidden><h3>新建客户</h3>
      ${input('联系电话 *','phone','','tel','required maxlength="200"')}
      ${input('联系人','contact','','text','maxlength="200"')}
      ${input('地址','address','','text','maxlength="500"')}
      ${input('客户名称 *','name','','text','required maxlength="200"')}
      <p class="customer-error" role="alert"></p><button type="submit">保存并选择</button>
      <button type="button" class="customer-cancel">取消新建</button></form>`;
    if (desktop) {
      const search=panel.querySelector('.customer-search'), add=panel.querySelector('.customer-new'), results=panel.querySelector('.customer-results');
      const toolbar=document.createElement('div');toolbar.className='selector-toolbar';search.before(toolbar);toolbar.append(search,add);
      const body=document.createElement('div');body.className='customer-desktop-body';results.before(body);body.innerHTML='<div class="selector-sidebar"><strong>客户管理</strong><span>全部启用客户</span></div>';body.append(results);
      panel.insertAdjacentHTML('beforeend','<footer><span class="customer-count"></span><button type="button" class="customer-confirm primary">确定选择</button></footer>');
      panel.querySelector('.customer-confirm').onclick=()=>{if(selectedCustomer)choose(selectedCustomer);};
      panel.querySelector('.picker-back').textContent='关闭';
    }
    document.body.append(panel);
    const close = () => panel.close();
    panel.addEventListener('close', () => {
      const wasActive = activePicker === panel;
      if (wasActive) activePicker = null;
      panel.remove();
      if (wasActive && trigger.isConnected && document.querySelector('#modal').open) trigger.focus();
    });
    panel.querySelector('.picker-back').onclick = close;
    function choose(id) {
      if (activePicker !== panel || !sales.isConnected) return;
      const select = sales.elements.party_id;
      select.innerHTML = '<option value="">请选择</option>' + opts(data.parties.filter(p => p.active && p.kind === 'customer'), 'id', 'name', id);
      select.value = id;
      trigger.textContent = party(id) + ' ›';
      const error=sales.querySelector('.form-error');if(error?.textContent==='请选择客户'){error.textContent='';error.hidden=true;}
      select.dispatchEvent(new Event('change', { bubbles: true }));
      close();
    }
    function paint() {
      const term = panel.querySelector('.customer-search').value.trim().toLowerCase();
      const customers = data.parties.filter(p => p.active && p.kind === 'customer' && [p.name,p.contact,p.phone,p.address].join(' ').toLowerCase().includes(term));
      const list = panel.querySelector('.customer-results');
      if(desktop){
        list.innerHTML='<table class="selector-table"><thead><tr><th>联系电话</th><th>客户名称</th><th>联系人</th><th>地址</th></tr></thead><tbody>'+customers.map(p=>`<tr data-customer="${E(p.id)}" tabindex="0" aria-selected="${p.id===selectedCustomer}"><td>${E(p.phone||'—')}</td><td>${E(p.name)}</td><td>${E(p.contact||'—')}</td><td>${E(p.address||'—')}</td></tr>`).join('')+'</tbody></table>'+(customers.length?'':'<p class="empty">暂无匹配客户，可新建客户。</p>');
        if(!customers.some(p=>p.id===selectedCustomer))selectedCustomer='';
        panel.querySelector('.customer-confirm').disabled=!selectedCustomer;
        panel.querySelector('.customer-count').textContent=`共 ${customers.length} 位客户 · 选择后确认，或双击客户`;
        list.querySelectorAll('[data-customer]').forEach(row=>{row.onclick=()=>{selectedCustomer=row.dataset.customer;list.querySelectorAll('[data-customer]').forEach(r=>r.setAttribute('aria-selected',r===row));panel.querySelector('.customer-confirm').disabled=false;};row.ondblclick=()=>choose(row.dataset.customer);row.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();choose(row.dataset.customer);}else if(e.key===' '){e.preventDefault();row.click();}};});
      }else{
        list.innerHTML = customers.map(p => `<button type="button" class="customer-result" data-customer="${E(p.id)}"><strong>${E(p.phone||'未填电话')}　${E(p.name)}</strong><small>联系人：${E(p.contact||p.name)}</small><small>地址：${E(p.address||'未填写')}</small></button>`).join('') || '<p>暂无匹配客户，可新建客户。</p>';
        list.querySelectorAll('[data-customer]').forEach(b => b.onclick = () => choose(b.dataset.customer));
      }
    }
    const create = panel.querySelector('.customer-create');
    panel.querySelector('.customer-search').oninput = paint;
    panel.querySelector('.customer-new').onclick = () => { create.hidden = false; create.elements.phone.focus(); };
    panel.querySelector('.customer-cancel').onclick = () => { create.hidden = true; };
    create.onsubmit = async event => {
      event.preventDefault();
      const save = create.querySelector('[type=submit]');
      if (save.disabled) return;
      save.disabled = true;
      const values = Object.fromEntries(new FormData(create));
      try {
        const result = await call({action:'master',type:'parties',kind:'customer',active:true,...values});
        data.parties.push({id:result.id,kind:'customer',active:1,version:1,...values});
        choose(result.id);
      } catch(error) {
        panel.querySelector('.customer-error').textContent = error.message;
      } finally { save.disabled = false; }
    };
    paint();
    panel.showModal();
    panel.querySelector('.customer-search').focus();
  }
})();

// Administrator quantity correction; the server rechecks role and current quantity.
document.addEventListener('click', e => {
 const target=e.target.closest('[data-action="adjust-stock"]');if(!target)return;
 e.stopImmediatePropagation();if(!data.can_adjust_stock)return;
 show(`<h2>直接调整库存</h2><p>仅调整所选商品（编码、型号）在所选仓库的数量，不影响其他商品；保留库存流水。</p><form id="adjust-stock-form" data-key="${key()}"><div class="grid">${select('具体商品（编码 · 名称 / 型号）','product_id',data.products.filter(p=>p.active),target.dataset.id||'',stockProductLabel)}${select('仓库','warehouse_id',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),target.dataset.warehouse||'')}<p id="adjust-current"></p>${input('该商品调整后数量','qty','','number','required min="0" max="1000000"')}<label>调整原因<textarea name="note" required></textarea></label></div>${foot('确认调整')}</form>`);
 const f=document.getElementById('adjust-stock-form');
 const update=()=>{const b=data.balances.find(b=>b.product_id===f.elements.product_id.value&&b.warehouse_id===f.elements.warehouse_id.value);f.dataset.expected=String(b?.qty||0);document.getElementById('adjust-current').textContent='当前账面数量：'+f.dataset.expected;f.elements.qty.value=f.dataset.expected;};
 f.elements.product_id.onchange=update;f.elements.warehouse_id.onchange=update;update();
},true);
document.addEventListener('submit',async e=>{
 const f=e.target;if(f.id!=='adjust-stock-form')return;e.preventDefault();e.stopImmediatePropagation();if(busy)return;busy=true;
 try{await call({action:'adjust_stock',request_key:f.dataset.key,product_id:f.elements.product_id.value,warehouse_id:f.elements.warehouse_id.value,expected_qty:Number(f.dataset.expected),qty:Number(f.elements.qty.value),note:f.elements.note.value});document.querySelector('dialog').close();await load();toast('库存已调整，流水已保存');}
 catch(error){const err=f.querySelector('.form-error');err.hidden=false;err.textContent=error.message;}finally{busy=false;}
},true);

// Prioritize an actionable customer message before native validation of other fields.
document.addEventListener('click',event=>{
 const submit=event.target.closest('#doc-form[data-kind="sale"] button[type="submit"]');
 if(!submit)return;
 const form=submit.closest('form');if(form.elements.party_id.value)return;
 event.preventDefault();event.stopImmediatePropagation();
 const error=form.querySelector('.form-error');if(error){error.hidden=false;error.textContent='请选择客户';}
 alert('请选择客户');form.querySelector('.sales-customer-trigger').focus();
},true);

document.addEventListener('click',e=>{
 if(e.target.closest('[data-code-rule]')){const r=data.product_code_rule;show(`<h2>产品编号规则</h2><form id="code-rule-form" data-version="${r.version}"><div class="grid">${input('前缀（0–3位字母）','prefix',r.prefix)}${input('流水位数','digits',r.digits,'number','min="1" max="7" required')}${input('下一个流水号','next',r.next,'number','min="1" required')}</div><p>默认 P00001；总长度小于8位，不使用日期。保存时跳过已有编号，不修改历史产品编号。</p>${foot('保存规则')}</form>`);}
 if(e.target.closest('[data-move-category]')){const from=document.querySelector('[data-catalog-category][aria-current="true"]')?.dataset.catalogCategory;if(!from){toast('请先在左侧选择要移动的分类');return;}const paths=new Set();data.products.forEach(p=>categoryParts(p).forEach((_,i)=>paths.add(categoryParts(p).slice(0,i+1).join(' / '))));show(`<h2>移动分类</h2><form id="move-category-form" data-from="${E(from)}"><p>当前目录：${E(from)}。下级分类和产品一起移动；移入同名目录时合并。</p><label>目标上级目录<select name="parent"><option value="">根目录</option>${[...paths].filter(p=>p!==from&&!p.startsWith(from+' / ')).map(p=>`<option value="${E(p)}">${E(p.replaceAll(' / ',' › '))}</option>`).join('')}</select></label>${foot('移动分类')}</form>`);}
});
document.addEventListener('submit',async e=>{const f=e.target;if(!['code-rule-form','move-category-form'].includes(f.id))return;e.preventDefault();const b=f.querySelector('[type=submit]');b.disabled=true;try{await call(f.id==='code-rule-form'?{action:'product_code_rule',version:Number(f.dataset.version),prefix:f.elements.prefix.value,digits:Number(f.elements.digits.value),next:Number(f.elements.next.value)}:{action:'move_category',from:f.dataset.from,parent:f.elements.parent.value,fingerprint:data.category_fingerprint});$('#modal').close();await load();document.querySelector('[data-catalog-reset]')?.click();toast('已保存');}catch(err){toast(err.message);}finally{b.disabled=false;}});
