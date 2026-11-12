'use strict';
Object.assign(names,{operations:'退换货与调拨',counting:'库存盘点',lots:'批次与分区',templates:'打印模板'});
const operationNames={purchase_return:'采购退货',sales_return:'销售退货',purchase_exchange:'采购换入',sales_exchange:'销售换出',transfer:'仓库调拨',inspection:'退货验收',count:'库存盘点',stock_adjustment:'管理员库存调整'};
Object.assign(states,{transit:'在途 / 待收货'});
const zones={available:'可售',quarantine:'待检',defective:'不良',sold:'已售',transit:'在途',returned_supplier:'已退供应商',lost:'盘亏'};
const oldRender=render;
render=function(){const p=location.hash.slice(1);if(!['operations','counting','lots','templates'].includes(p)){oldRender();return;}page=p;$('#nav').innerHTML=Object.entries(names).map(([id,n])=>`<a class="${page===id?'active':''}" href="#${id}">${n}</a>`).join('');let h='';if(p==='operations'||p==='counting'){h=head(p==='counting'?'创建盘点后冻结该仓库；核对可售区，待检/不良区不计入本次实盘。':'退货须关联原单；换货关联已审核退货。调拨分发货与收货两步。',p==='counting'?button('开始盘点','p2-new','count',true):'')+(p==='operations'?`<div class="bar">${Object.entries(operationNames).filter(([k])=>!['count','stock_adjustment'].includes(k)).map(([k,n])=>button(n,'p2-new',k)).join('')}</div>`:'')+table(['业务编号','类型','商品 / 仓库','状态','数量','操作'],filtered(data.ops).filter(o=>p==='counting'?o.kind==='count':o.kind!=='count').map(o=>{const b=JSON.parse(o.body);return [E(o.id),operationNames[o.kind],`${E(b.product_id?product(b.product_id).name:'全部可售商品')} / ${E(warehouse(b.warehouse_id))}`,states[o.state],b.qty||'—',button('查看','p2-detail',o.id)];}));}
if(p==='lots')h=head('批次原始单价保留；一期非 SN 结余无法推定批次，标为未记录。')+table(['批次编号','商品','仓库','分区','数量','原始入库单价','来源','操作'],filtered(data.lots.filter(l=>l.qty>0).map(l=>({...l,name:product(l.product_id).name,warehouse:warehouse(l.warehouse_id)}))).map(l=>[E(l.id),E(l.name),E(l.warehouse),zones[l.zone],l.qty,money(l.price_cents),E(l.origin),l.origin==='一期结余待核实价格'?button('核对来源批次','p2-lot',l.id):'—']))+`<h2 style="margin-top:25px">待检与不良总量（含 SN 商品）</h2>`+table(['商品','仓库','分区','数量'],data.holds.filter(h=>h.qty>0).map(h=>[E(product(h.product_id).name),E(warehouse(h.warehouse_id)),zones[h.zone],h.qty]));
if(p==='templates')h=head('参考合格证 / 外箱标签布局；每个商品独立保存参数、尺寸、可选字段与标识。')+table(['商品','型号','模板资料','操作'],filtered(data.products).map(p=>[E(p.name),E(p.model),data.label_profiles.some(l=>l.product_id===p.id)?'已设置':'待设置',button('设置 / 预览','label-editor',p.id)]));
$('#main').innerHTML=`<div class="bar"><input id="search" type="search" placeholder="搜索当前列表" value="${E(search)}">${button('刷新','refresh')}${button('导出 Excel','export')}${button('打印列表','print-list')}</div><section class="panel">${h}</section>`;};
function parseAlloc(text){return text.split(/\r?\n/).map(x=>x.trim()).filter(Boolean).map(l=>{const v=l.split(/[,，:：]/);if(v.length!==2||!/^\d+$/.test(v[1].trim()))throw Error('批次每行填写：批次编号,数量');return {lot_id:v[0].trim(),qty:Number(v[1])};});}
const snsText=text=>text.split(/\r?\n/).map(x=>x.trim().toUpperCase()).filter(Boolean);
function p2Form(kind,id=''){
 const op=data.ops.find(o=>o.id===id),b=op?JSON.parse(op.body):{};if(kind==='count'&&op)return countForm(op);
 const p=product(b.product_id),sources=data.lines.filter(l=>{const doc=data.docs.find(d=>d.id===l.doc_id);return doc?.status==='approved'&&doc.kind===(kind==='purchase_return'?'receipt':'issue');}).map(l=>({id:l.id,name:`${data.docs.find(d=>d.id===l.doc_id)?.source_id||l.doc_id} · ${product(l.product_id).name} / ${product(l.product_id).model||''} · ${l.qty} ${product(l.product_id).unit}`}));
 let fields=select('业务仓库','warehouse_id',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),b.warehouse_id);
 if(kind!=='count')fields+=select('商品','product_id',data.products.filter(p=>p.active),b.product_id)+input('数量','qty',b.qty||1,'number','required min="1" max="2000"');
 if(kind==='transfer')fields+=select('调入仓库','to_warehouse',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),b.to_warehouse);
 if(['purchase_return','sales_return'].includes(kind))fields+=select('原销售 / 入库单商品','source_line',sources,b.source_line)+input('退货单价（元）','refund_price',b.refund_price??'','number','required min="0" step="0.01"');
 if(kind==='sales_return')fields+=select('退货处理方式','return_mode',[{id:'replacement',name:'退货待补发（不变动账款）'},{id:'refund',name:'退货退款（冲减应收）'}],b.return_mode||'replacement');
 if(kind==='purchase_return')fields+=`<label>退货分区<select name="zone">${Object.entries(zones).filter(([k])=>['available','quarantine','defective'].includes(k)).map(([k,n])=>`<option value="${k}" ${b.zone===k?'selected':''}>${n}</option>`).join('')}</select></label>`;
 if(kind==='inspection')fields+=`<label>验收结果<select name="result_zone"><option value="available">合格，转可售</option><option value="defective" ${b.result_zone==='defective'?'selected':''}>不良</option></select></label>`;
 if(kind.endsWith('exchange')){const returns=data.ops.filter(o=>o.state==='approved'&&o.kind===(kind==='purchase_exchange'?'purchase_return':'sales_return')).map(o=>({id:o.id,name:o.id+' · '+product(JSON.parse(o.body).product_id).name}));fields+=select('关联已审核退货','return_id',returns,b.return_id)+input('换入/换出单价（元）','exchange_price',b.exchange_price??'','number','required min="0" step="0.01"');if(kind==='sales_exchange')fields+=input('换机日期','exchange_date',b.exchange_date||today(),'date','required')+input('保修截止（留空按默认月数重算）','warranty_end',b.warranty_end||'','date');}
 if(!['count','sales_return','purchase_return','sales_exchange','purchase_exchange'].includes(kind))fields+=`<label class="wide">${kind==='purchase_exchange'?'换入系统 SN（留空自动生成）':'系统 SN（逐行，可扫码/粘贴；非 SN 配件留空）'}<textarea name="serials">${E((b.serials||[]).join('\n'))}</textarea></label><label class="wide">非 SN 商品批次分配（每行：批次编号,数量；唯一批次可留空）<textarea name="allocations">${E((b.allocations||[]).map(l=>l.lot_id+','+l.qty).join('\n'))}</textarea><small class="wide">可在“批次与分区”查询编号。销售退货请选择原出库批次；历史未记录的批次保留为空价。</small></label>`;
 fields+=`<label class="wide">业务原因 / 依据<textarea name="note" required>${E(b.note||'')}</textarea></label>`;
 show(`<h2>${operationNames[kind]}</h2><form id="p2-form" data-kind="${kind}" data-id="${E(id)}" data-version="${op?.version||0}" data-key="${key()}"><div class="grid">${fields}</div>${foot(kind==='count'?'开始盘点并冻结仓库':'保存草稿')}</form>`);if(id)$('#p2-form [name=warehouse_id]').disabled=true;
 const source=$('#p2-form [name=source_line]');if(source)source.addEventListener('change',()=>{const l=data.lines.find(x=>x.id===source.value);if(!l)return;const d=data.docs.find(x=>x.id===l.doc_id),f=$('#p2-form');f.elements.product_id.value=l.product_id;f.elements.warehouse_id.value=d.warehouse_id;f.elements.refund_price.value=money(l.price_cents);});

}
function p2Detail(id){const o=data.ops.find(x=>x.id===id),b=JSON.parse(o.body),snap=JSON.parse(o.snapshot);let actions='';if(['draft','rejected'].includes(o.state))actions=button('编辑','p2-edit',id)+button('提交审核','p2-submit',id,true)+button('作废','p2-void',id);if(o.state==='submitted')actions=button('审核生效','p2-approve',id,true)+button('驳回','p2-reject',id);if(o.state==='transit')actions=button('确认全部收货','p2-receive',id,true);if(o.state==='approved'&&['purchase_return','purchase_exchange'].includes(o.kind))actions+=button('前往账款管理','fin-open-payables',id);
 show(`<div id="doc-print"><h2>${operationNames[o.kind]} · ${states[o.state]}</h2><p class="mono">${E(id)}</p><p>仓库：${E(warehouse(b.warehouse_id))}${b.to_warehouse?' → '+E(warehouse(b.to_warehouse)):''}</p><p>商品：${E(b.product_id?product(b.product_id).name:'全部可售商品')}　数量：${b.qty??'—'}</p><p>原单明细：${E(b.source_line||b.return_id||'—')}</p><p>原因：${E(b.note)}</p>${b.review_reason?`<p class="warning">${E(b.review_reason)}</p>`:''}${b.refund_cents!==undefined?`<p>退货金额：¥${money(b.refund_cents)}</p>`:''}${b.difference_cents!==undefined?`<p>换货差额：¥${money(b.difference_cents)}（新货金额－退货金额）</p>`:''}${b.settlement?`<p>结算：${({pending:'待处理',refunded:'已退款',credited:'已抵扣',paid:'已补款'})[b.settlement]||E(b.settlement)} ${E(b.settlement_note||'')}</p>`:''}${b.warranty_end?`<p>新保修期：${E(b.exchange_date)} 至 ${E(b.warranty_end)}</p>`:''}${["sales_return","purchase_return","sales_exchange","purchase_exchange"].includes(o.kind)?'':`<p style="overflow-wrap:anywhere">SN：${E((b.serials||[]).join(' / ')||'—')}</p>`}${o.kind==='stock_adjustment'?`<p>调整前：${b.before_qty} → 调整后：${b.qty}；操作人：${E(b.actor)}</p>`:''}${b.warranty_pending?'<p>按数量换货已完成；具体设备保修绑定需在安装登记时核实。</p>':''}${o.kind==='count'?table(['商品','账面可售','实盘','差异原因'],(snap.balances||[]).map(l=>{const c=(b.counts||[]).find(c=>c.product_id===l.product_id);return [E(product(l.product_id).name),l.available,c?.qty??'未录入',E(c?.reason||'—')];})):''}</div><div class="foot">${button('打印单据','print-doc')}${button('关闭','close')}${actions}</div>`);}
function countForm(o){const b=JSON.parse(o.body),snap=JSON.parse(o.snapshot);show(`<h2>盘点实录 · ${E(warehouse(b.warehouse_id))}</h2><p class="warning">该仓库已冻结。仅填写可售区实物数量 / SN，待检、不良及未归属物料不计入。确认差异后提交审核；作废则解冻。</p><form id="count-form" data-id="${o.id}" data-version="${o.version}"><input type="hidden" name="warehouse_id" value="${E(b.warehouse_id)}"><label>盘点依据<textarea name="note" required>${E(b.note)}</textarea></label>${snap.balances.map(l=>{const c=(b.counts||[]).find(c=>c.product_id===l.product_id)||{};return `<section class="panel count-line" data-product="${E(l.product_id)}"><b>${E(product(l.product_id).name)}</b> · 账面可售 ${l.available}<div class="grid">${input('实盘数量','qty',c.qty??l.available,'number','required min="0"')}${input('差异原因','reason',c.reason||'')}${l.sn_enabled?`<label class="wide">实际 SN 每行一个<textarea name="serials">${E((c.serials||snap.sn.filter(s=>s.product_id===l.product_id).map(s=>s.sn)).join('\n'))}</textarea></label>`:`<label class="wide">盘亏来源批次（批次编号,数量；唯一批次可留空）<textarea name="allocations">${E((c.allocations||[]).map(l=>l.lot_id+','+l.qty).join('\n'))}</textarea></label>`}</div></section>`;}).join('')}${foot('保存盘点实录')}</form>`);}
// Capture phase2 forms before the legacy inventory submit handler.
document.addEventListener('submit',async e=>{const f=e.target;if(!['p2-form','count-form','label-form'].includes(f.id))return;e.preventDefault();e.stopImmediatePropagation();if(busy)return;busy=true;const err=f.querySelector('.form-error'),submit=f.querySelector('[type=submit]');err.hidden=true;submit.disabled=true;try{let result;const v=Object.fromEntries(new FormData(f));if(f.id==='p2-form'){const b={...v,warehouse_id:f.elements.warehouse_id.value,qty:Number(v.qty),serials:snsText(v.serials||''),old_serials:snsText(v.old_serials||''),allocations:parseAlloc(v.allocations||'')};result=await call(f.dataset.id?{action:'p2_step',transition:'edit',id:f.dataset.id,version:Number(f.dataset.version),body:b}:{action:'p2_create',kind:f.dataset.kind,body:b,request_key:f.dataset.key});}
if(f.id==='count-form'){const counts=[...f.querySelectorAll('.count-line')].map(el=>({product_id:el.dataset.product,qty:Number(el.querySelector('[name=qty]').value),reason:el.querySelector('[name=reason]').value,serials:snsText(el.querySelector('[name=serials]')?.value||''),allocations:parseAlloc(el.querySelector('[name=allocations]')?.value||'')}));result=await call({action:'p2_step',transition:'edit',id:f.dataset.id,version:Number(f.dataset.version),body:{warehouse_id:v.warehouse_id,note:v.note,counts}});}
if(f.id==='label-form'){const body=labelFormValues(f);await call({action:'label_save',product_id:f.dataset.id,version:Number(f.dataset.version),body});await load();labelEditor(f.dataset.id);toast('模板资料已保存');return;}
await load();p2Detail(result.id);toast('已保存到服务器');}catch(ex){err.textContent=ex.message;err.hidden=false;}finally{busy=false;submit.disabled=false;}},true);
document.addEventListener('click',async e=>{const el=e.target.closest('[data-action]');if(!el||busy)return;const a=el.dataset.action,id=el.dataset.id;if(!a.startsWith('p2-')&&!a.startsWith('label-'))return;e.stopImmediatePropagation();try{
 if(a==='p2-lot'){const lot=data.lots.find(l=>l.id===id);const qty=Number(prompt('本次核对数量（不改变库存总量）',String(lot.qty)));if(!qty)return;const source_line=prompt('请输入原入库明细编号（入库单详情中可查看）');if(!source_line)return;const note=prompt('请填写实物与单据核对依据');if(!note)return;busy=true;await call({action:'lot_reconcile',request_key:key(),lot_id:id,qty,source_line,note});await load();toast('批次已核对，价格沿用原入库单');return;}if(a==='p2-new')return p2Form(id);if(a==='p2-detail')return p2Detail(id);if(a==='p2-edit')return p2Form(data.ops.find(o=>o.id===id).kind,id);if(a==='label-editor')return labelEditor(id);if(a==='label-preview')return labelPreview();if(a==='label-print')return await printCustom();
 const op=data.ops.find(o=>o.id===id);let reason='';const transition=a.slice(3);if(['reject','void'].includes(transition)){reason=prompt('请输入原因');if(!reason)return;}if(['approve','receive'].includes(transition)&&!confirm('确认生效？将改变库存并保留历史记录。'))return;
 if(transition==='settlement'){show(`<h2>结算状态</h2><div class="bar">${['pending','refunded','credited','paid'].map(s=>`<button data-action="p2-set-${s}" data-id="${id}">${({pending:'待处理',refunded:'已退款',credited:'已抵扣',paid:'已补款'})[s]}</button>`).join('')}</div>${button('关闭','close')}`);return;}
 busy=true;await call({action:'p2_step',id,version:op.version,transition:transition.startsWith('set-')?'settlement':transition,settlement:transition.slice(4),reason});await load();p2Detail(id);toast('操作已保存');
 }catch(ex){toast(ex.message);}finally{busy=false;}},true);
// Extend original lines with explicit non-SN lot allocations for outbound stock.
const oldLineHTML=lineHTML;lineHTML=function(l={},kind='purchase',source=false){let html=oldLineHTML(l,kind,source);if(kind==='issue'){const a=data.allocations?.find(a=>a.line_id===l.id);const text=a?JSON.parse(a.body).map(x=>x.lot_id+','+x.qty).join('\n'):'';html=html.replace('<button type="button" data-action="remove-line">',`<label>非 SN 批次（编号,数量）<textarea name="allocations">${E(text)}</textarea></label><button type="button" data-action="remove-line">`);}return html;};
const oldCall=call;call=async function(a){if(a.action==='save_doc'&&a.kind==='issue'){const els=[...document.querySelectorAll('#lines .line')];a.lines.forEach((l,i)=>l.allocations=parseAlloc(els[i]?.querySelector('[name=allocations]')?.value||''));}return oldCall(a);};
// Native XLSX export with inline strings (no executable spreadsheet formulas), no CDN.
function xlsxBytes(rows){const enc=new TextEncoder(),xml=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c])).replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g,'');const files={'[Content_Types].xml':'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>','_rels/.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="业务报表" sheetId="1" r:id="rId1"/></sheets></workbook>','xl/_rels/workbook.xml.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>','xl/worksheets/sheet1.xml':'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'+rows.map((r,i)=>`<row r="${i+1}">${r.map(c=>`<c t="inlineStr"><is><t xml:space="preserve">${xml(c)}</t></is></c>`).join('')}</row>`).join('')+'</sheetData></worksheet>'};
const concat=parts=>{const out=new Uint8Array(parts.reduce((n,x)=>n+x.length,0));let i=0;for(const x of parts){out.set(x,i);i+=x.length;}return out;},header=(size,items)=>{const a=new Uint8Array(size),v=new DataView(a.buffer);for(const [off,val,len] of items)len===2?v.setUint16(off,val,true):v.setUint32(off,val,true);return a;};const locals=[],central=[];let offset=0;for(const [path,str] of Object.entries(files)){const n=enc.encode(path),b=enc.encode('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'+str);let crc=0xffffffff;for(const c of b){crc^=c;for(let i=0;i<8;i++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}crc=(crc^0xffffffff)>>>0;const l=header(30,[[0,0x04034b50,4],[4,20,2],[14,crc,4],[18,b.length,4],[22,b.length,4],[26,n.length,2]]),c=header(46,[[0,0x02014b50,4],[4,20,2],[6,20,2],[16,crc,4],[20,b.length,4],[24,b.length,4],[28,n.length,2],[42,offset,4]]);locals.push(l,n,b);central.push(c,n);offset+=l.length+n.length+b.length;}const cd=concat(central),end=header(22,[[0,0x06054b50,4],[8,Object.keys(files).length,2],[10,Object.keys(files).length,2],[12,cd.length,4],[16,offset,4]]);return concat([...locals,cd,end]);}
exportTable=function(el){if(!el){toast('当前页面无可导出的表格');return;}const rows=[...el.querySelectorAll('tr')].map(r=>[...r.children].map(c=>c.innerText));const url=URL.createObjectURL(new Blob([xlsxBytes(rows)],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));const a=document.createElement('a');a.href=url;a.download=(names[page]||'业务报表')+'-'+today()+'.xlsx';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};

// Compact inventory navigation shared by all inventory routes.
(()=>{
 const sections=[['账款管理','应收、应付、预收与预付',['finance']],['销售管理','客户拿货、销售与出库',['sale']],['采购管理','采购订单与到货入库',['purchase','receipt','parties']],['库存管理','库存、商品、仓库与打印',['overview','balances','products','warehouses','lots','operations','counting','serials','templates','ledger','events']]];
 const fly=document.createElement('section');fly.className='stock-subnav';fly.id='stock-subnav';fly.hidden=true;fly.setAttribute('aria-label','进销存二级功能');document.body.append(fly);
 let anchor=null,timer;
 function hide(){clearTimeout(timer);fly.hidden=true;if(anchor)anchor.setAttribute('aria-expanded','false');anchor=null;}
 function place(){if(!anchor||fly.hidden)return;const r=anchor.getBoundingClientRect(),side=document.querySelector('aside').getBoundingClientRect();fly.style.width=Math.min(360,innerWidth-24)+'px';fly.style.left=Math.max(12,Math.min(side.right-1,innerWidth-fly.offsetWidth-12))+'px';fly.style.top=Math.max(12,Math.min(r.top,innerHeight-fly.offsetHeight-12))+'px';}
 function showGroup(button){clearTimeout(timer);if(anchor&&anchor!==button)anchor.setAttribute('aria-expanded','false');anchor=button;const g=sections[Number(button.dataset.stockGroup)];fly.innerHTML=`<h2>${g[0]}</h2><p>${g[1]}</p><div>${g[2].map(id=>`<a href="#${id}" ${page===id?'aria-current="page" class="current"':''}>${names[id]}<span>${page===id?'当前':'›'}</span></a>`).join('')}</div>`;fly.setAttribute('aria-label',g[0]+'二级功能');fly.hidden=false;button.setAttribute('aria-expanded','true');place();}
 const group=el=>el?.closest?.('[data-stock-group]');
 function delayed(){clearTimeout(timer);timer=setTimeout(()=>{if(!fly.matches(':hover')&&!anchor?.matches(':hover')&&!fly.contains(document.activeElement)&&document.activeElement!==anchor)hide();},200);}
 document.addEventListener('pointerover',e=>{const b=group(e.target);if(b)showGroup(b);else if(fly.contains(e.target))clearTimeout(timer);});
 document.addEventListener('pointerout',e=>{if(group(e.target)||fly.contains(e.target))delayed();});
 document.addEventListener('click',e=>{const b=group(e.target);if(b)showGroup(b);else if(fly.contains(e.target)&&e.target.closest('a'))hide();else if(!fly.contains(e.target))hide();});
 document.addEventListener('focusin',e=>{const b=group(e.target);if(b)showGroup(b);else if(!fly.contains(e.target))hide();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!fly.hidden){e.preventDefault();anchor?.focus();hide();}else if(e.key==='ArrowRight'&&group(e.target)){e.preventDefault();showGroup(group(e.target));fly.querySelector('a').focus();}});
 document.querySelector('aside').addEventListener('scroll',place);window.addEventListener('resize',place);window.addEventListener('hashchange',hide);
 const renderPage=render;
 render=function(){hide();renderPage();$('#nav').innerHTML='<a href="/service/#dashboard">工作台首页</a>'+sections.map(([title,desc,ids],i)=>`<button type="button" class="stock-group ${ids.includes(page)?'active':''}" data-stock-group="${i}" aria-controls="stock-subnav" aria-expanded="false"><span><b>${title}</b><small>${ids.includes(page)?names[page]:desc}</small></span><i aria-hidden="true">›</i></button>`).join('');const crumb=$('header > span');if(crumb)crumb.textContent='德澳总部 / '+(sections.find(g=>g[2].includes(page))?.[0]||'进销存')+' / '+names[page];};
 if(data)render();
})();

// Phone navigation shares the existing desktop links and never consumes a side column.
(()=>{
 const mobile = document.createElement('details');
 mobile.className = 'inventory-mobile-nav no-print';
 mobile.innerHTML = '<summary>☰ 功能菜单</summary><div class="mobile-nav-links"></div>';
 document.querySelector('.shell').prepend(mobile);
 const refresh = () => {
  const target = mobile.querySelector('.mobile-nav-links');
  target.innerHTML = '<a href="/service/#dashboard">工作台首页</a>' +
   [['销售管理',['sale']],['采购管理',['purchase','receipt','parties']],
    ['库存管理',['overview','balances','products','warehouses','lots','operations','counting','serials','templates','ledger','events']]]
    .map(([title,ids])=>`<details ${ids.includes(page)?'open':''}><summary>${title}</summary><div>${ids.map(id=>`<a href="#${id}" ${page===id?'aria-current="page"':''}>${names[id]}</a>`).join('')}</div></details>`).join('');
  document.querySelectorAll('#main table').forEach(table=>{
   table.classList.add('mobile-records');
   const headings=[...table.querySelectorAll('thead th')].map(th=>th.textContent);
   table.querySelectorAll('tbody tr').forEach(row=>[...row.children].forEach((cell,i)=>{
    if(!cell.hasAttribute('colspan'))cell.dataset.label=headings[i]||'';
   }));
  });
 };
 new MutationObserver(refresh).observe(document.querySelector('#main'),{childList:true});
 mobile.addEventListener('click',e=>{if(e.target.closest('a'))mobile.open=false;});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&mobile.open){mobile.open=false;mobile.querySelector('summary').focus();}});
 refresh();
})();

// Embedded mode removes the second navigation shell, retaining inventory business handlers.
if(new URLSearchParams(location.search).get('embedded')==='1'&&window.parent!==window){
 document.body.classList.add('embedded-inventory');
 const announce=()=>parent.postMessage({type:'deaoe-inventory-current',route:location.hash.slice(1)||'overview'},location.origin);
 window.addEventListener('hashchange',announce);
 let pendingCreate=null,lastCreate=null;
 const openPending=()=>{
  if(!pendingCreate||!data||busy||location.hash.slice(1)!==pendingCreate.route)return;
  const request=pendingCreate;pendingCreate=null;
  if(request.requestId===lastCreate)return;
  lastCreate=request.requestId;
  docForm(request.route);
 };
 const renderBeforeCreate=render;
 render=function(){renderBeforeCreate();openPending();};
 window.addEventListener('hashchange',openPending);
 window.addEventListener('message',e=>{
  if(e.origin===location.origin&&e.source===parent&&e.data?.type==='deaoe-inventory-dismiss'){
   pendingCreate=null;
   window.dispatchEvent(new Event('inventory-navigation'));
   if($('#modal').open)$('#modal').close();
   return;
  }
  if(e.origin!==location.origin||e.source!==parent||e.data?.type!=='deaoe-inventory-route'||!names[e.data.route])return;
  if(e.data.create&&e.data.requestId===lastCreate)return;
  window.dispatchEvent(new Event('inventory-navigation'));
  if($('#modal').open)$('#modal').close();
  pendingCreate=e.data.create&&['sale','purchase','issue','receipt'].includes(e.data.route)?e.data:null;
  refreshSnapshot();
  if(location.hash.slice(1)===e.data.route)openPending();
  else location.replace('#'+e.data.route);
 });
 document.addEventListener('click',e=>{
  const a=e.target.closest('a');if(!a)return;const url=new URL(a.href,location.href);
  if(url.origin===location.origin&&url.pathname==='/service/'){
   e.preventDefault();parent.postMessage({type:'deaoe-service-home'},location.origin);
  }
 },true);
 announce();
}

// Mobile sales: separate creation and record browsing; reuse server mutations.
(() => {
  const mobile = window.matchMedia('(max-width: 700px)');
  let status = '', store = '', term = '';
  const beforeRender = render;
  const salesRows = () => data.docs.filter(doc => {
    if (doc.kind !== 'sale' || !saleDateMatches(doc) || (status && doc.status !== status) || (store && doc.warehouse_id !== store && !data.lines.some(l=>l.doc_id===doc.id&&l.warehouse_id===store))) return false;
    const lines = data.lines.filter(line => line.doc_id === doc.id);
    const text = [doc.id, party(doc.party_id), doc.note, ...lines.map(line => {
      const item = product(line.product_id);
      return [item.name, item.model, item.code].join(' ');
    })].join(' ').toLowerCase();
    return text.includes(term.toLowerCase().trim());
  });
  function paintSales() {
    if (!mobile.matches || page !== 'sale' || !data) return;
    let panel = document.querySelector('#mobile-sales');
    if (!panel) {
      panel = document.createElement('section');
      panel.id = 'mobile-sales';
      document.querySelector('#main').append(panel);
    }
    panel.innerHTML = `<div class="sales-title"><a href="/service/#applications" aria-label="返回工作台">‹</a><h2>销售单</h2><button data-action="new-doc" data-id="sale" aria-label="新增销售单">新增</button></div>
      <input id="sales-query" type="search" placeholder="搜索单号、客户、商品、备注" value="${E(term)}">
      <div class="sales-filters"><label>状态<select id="sales-status"><option value="">全部状态</option>${Object.entries(states).map(([value,label]) => `<option value="${value}" ${status===value?'selected':''}>${label}</option>`).join('')}</select></label>
      <label>仓库<select id="sales-store"><option value="">全部仓库</option>${opts(data.warehouses,'id','name',store)}</select></label></div>${saleDateControls()}<div id="sales-cards"></div>`;
    paintCards();
  }
  function paintCards() {
    const rows = salesRows().sort((a,b)=>String(b.created_at||b.date||'').localeCompare(String(a.created_at||a.date||''))||b.id.localeCompare(a.id,undefined,{numeric:true}));
    document.querySelector('#sales-cards').innerHTML = `<p class="muted">共 ${rows.length} 张销售单</p>` + (rows.map(doc => {
      const lines = data.lines.filter(line => line.doc_id === doc.id);
      let actions = button('查看','doc',doc.id);
      if (['draft','rejected'].includes(doc.status)) actions += button('修改','edit-doc',doc.id) + button('提交审核','submit',doc.id,true);
      if (doc.status === 'submitted') actions += button('审核','approve',doc.id,true) + button('驳回','reject',doc.id);
      if (doc.status === 'approved' && saleRemaining(doc.id)) actions += button('历史销售补发','from-source',doc.id,true);
      return `<article class="sales-card"><div class="sales-card-title"><strong>${E(party(doc.party_id)||'未选择客户')} ${E(doc.delivery_phone||'')}</strong><span class="badge">${E(doc.status==='approved'&&!saleRemaining(doc.id)?'已审核已出库':states[doc.status])}</span></div><div class="sales-card-meta"><span class="mono">${E(doc.id)}</span><span>${E([...new Set(lines.map(l=>warehouse(l.warehouse_id||doc.warehouse_id)))].join('、'))}</span></div><div class="sales-card-meta"><span>${E(doc.date)}</span><span>数量：${lines.reduce((n,l)=>n+l.qty,0)}　合计：¥${money(lines.reduce((n,l)=>n+l.qty*l.price_cents,0))}</span></div><div class="sales-actions">${actions}</div></article>`;
    }).join('') || '<p class="empty">没有符合条件的销售单</p>');
  }
  render = function() {
    beforeRender();
    document.body.classList.toggle('mobile-sales-list', mobile.matches && page === 'sale');
    paintSales();
  };
  document.addEventListener('input', event => {
    if (event.target.id === 'sales-query') { term = event.target.value; paintCards(); }
    updateTotal();
  });
  document.addEventListener('change', event => {
    if (event.target.id === 'sales-status') { status = event.target.value; paintCards(); }
    if (event.target.id === 'sales-store') { store = event.target.value; paintCards(); }
    updateContact();
    updateTotal();
  });
  function updateContact() {
    const target = document.querySelector("#sales-contact");
    if (!target) return;
    const id = document.querySelector("#doc-form [name=party_id]").value;
    const customer = data.parties.find(item => item.id === id);
    target.textContent = customer ? `联系人：${customer.contact || "未填写"}　电话：${customer.phone || "未填写"}　地址：${customer.address || "未填写"}` : "选择客户后显示联系资料";
  }
  function updateTotal() {
    const total = document.querySelector('#sales-total');
    if (!total) return;
    let qty = 0, cents = 0;
    document.querySelectorAll('#doc-form .line').forEach(line => {
      if (!line.querySelector('[name=product]').value) return;
      const count = Number(line.querySelector('[name=qty]').value) || 0;
      const price = Number(line.querySelector('[name=price]').value) || 0;
      qty += count; cents += count * Math.round(price * 100);
    });
    total.textContent = `数量：${qty}　合计：¥${money(cents)}`;
  }
  const beforeShow = show;
  show = function(html) {
    beforeShow(html);
    const form = document.querySelector('#doc-form[data-kind="sale"]');
    const active = mobile.matches && !!form;
    document.querySelector('#modal').classList.toggle('mobile-sale-editor', active);
    if (active) {
      const heading = document.querySelector('#body h2');
      heading.textContent = form.dataset.id ? '修改销售单' : '销售开单';
      heading.insertAdjacentHTML('beforebegin','<button type="button" class="sales-back" data-action="close" aria-label="返回">‹ 返回</button>');
      form.querySelector('.foot').insertAdjacentHTML('afterbegin','<span id="sales-total"></span>');
      form.querySelector('[name=party_id]').required = true;
      form.querySelector('[name=warehouse_id]').required = true;
      form.querySelector('[name=party_id]').closest('label').insertAdjacentHTML('afterend','<p id="sales-contact" class="muted"></p>');
      updateContact();
      updateTotal();
    }
    if (parent !== window) parent.postMessage({type:'deaoe-mobile-document',open:active},location.origin);
  };
  document.querySelector('#modal').addEventListener('close', () => {
    if (parent !== window) parent.postMessage({type:'deaoe-mobile-document',open:false},location.origin);
  });
  // Row insertion/removal runs in the existing click handler first.
  document.addEventListener('click', () => updateTotal());
  mobile.addEventListener('change', () => { if(data) render(); });
  if (data) render();
})();

// One searchable document register, shared by desktop and mobile.
names.documents = '单据中心';
(() => {
  let mode = '', kind = '', keyword = '';
  const previous = render;
  function draw() {
    const rows = data.docs.filter(doc => (!mode || doc.status === mode) && (!kind || doc.kind === kind) &&
      [doc.id, party(doc.party_id), doc.note, ...data.lines.filter(line => line.doc_id === doc.id).map(line => product(line.product_id).name)].join(' ').toLowerCase().includes(keyword.trim().toLowerCase()));
    $('#document-results').innerHTML = table(['单号 / 日期','类型 / 往来单位','状态','数量 / 金额','操作'],rows.map(doc => {
      const lines = data.lines.filter(line => line.doc_id === doc.id);
      return [E(doc.id)+'<br>'+E(doc.date),E(kinds[doc.kind])+' / '+E(party(doc.party_id)),E(states[doc.status]),lines.reduce((n,l)=>n+l.qty,0)+' / ¥'+money(lines.reduce((n,l)=>n+l.qty*l.price_cents,0)),button('查看 / 处理','doc',doc.id)];
    }));
    $('#document-count').textContent = `共 ${rows.length} 张单据`;
    $('#document-results table').classList.add('mobile-records');
    $('#document-results').querySelectorAll('tbody tr').forEach(row => [...row.children].forEach((cell,index) => cell.dataset.label = ['单号 / 日期','类型 / 往来单位','状态','数量 / 金额','操作'][index]));
  }
  render = function() {
    previous();
    if (location.hash !== '#documents' || !data) return;
    page = 'documents';
    $('#main').innerHTML = `<div class="bar"><input id="document-keyword" type="search" placeholder="单号、客户、商品或备注" value="${E(keyword)}"><label>状态<select id="document-mode"><option value="">全部单据</option>${Object.entries(states).map(([value,label])=>`<option value="${value}" ${mode===value?'selected':''}>${label}</option>`).join('')}</select></label><label>类型<select id="document-kind"><option value="">全部类型</option>${Object.entries(kinds).map(([value,label])=>`<option value="${value}" ${kind===value?'selected':''}>${label}</option>`).join('')}</select></label></div><section class="panel"><div class="head"><div><h1>单据中心</h1><div class="muted">集中查询销售、采购和收发货单据，查看草稿及审核状态。</div></div></div><p id="document-count" class="muted"></p><div id="document-results"></div></section>`;
    draw();
  };
  document.addEventListener('input', event => {if(event.target.id==='document-keyword'){keyword=event.target.value;draw();}});
  document.addEventListener('change', event => {
    if(event.target.id==='document-mode'){mode=event.target.value;draw();}
    if(event.target.id==='document-kind'){kind=event.target.value;draw();}
  });
  if(data) render();
})();

// Sales product picker: compact lines and per-product configuration.
(() => {
  let panel=null, sheet=null, owner=null;
  const modal=document.querySelector('#modal');
  const closeSheet=()=>{if(sheet){sheet.close();sheet.remove();sheet=null;}};
  function closePicker(){closeSheet();if(panel){panel.close();panel.remove();panel=null;}owner=null;}
  function lineSummary(line){
    const id=line.querySelector('[name=product]').value,p=product(id),qty=Number(line.querySelector('[name=qty]').value)||0,price=Number(line.querySelector('[name=price]').value)||0;
    const trigger=line.querySelector('.product-choose');
    if(!trigger)return;
    const html=id?`<strong>${E(p.name)}</strong><small><span>编号：${E(p.code)}</span><span>型号：${E(p.model||'—')}</span></small><small><span>¥${price.toFixed(2)} × ${qty} ${E(p.unit)}　金额：¥${(Math.round(price*100)*qty/100).toFixed(2)}</span><span>${E(warehouse(line.dataset.warehouse)||'选择仓库')} ›</span></small>`:'<span class="product-empty-label">＋ 选择产品</span><span class="product-empty-arrow" aria-hidden="true">›</span>';
    trigger.setAttribute('aria-label',id?'修改商品 '+p.name:'选择产品');
    if(trigger.innerHTML!==html)trigger.innerHTML=html;
  }
  function enhance(){
    if(owner&&!owner.isConnected)closePicker();
    const form=document.querySelector('#doc-form[data-kind="sale"]');if(!form)return;
    if(!form.querySelector('.sales-delivery')){
      const doc=data.docs.find(d=>d.id===form.dataset.id)||{},customer=data.parties.find(p=>p.id===form.elements.party_id.value)||{};
      const box=document.createElement('div');box.className='sales-delivery';
      box.innerHTML=['phone','contact','address'].map((f,i)=>input(['联系电话 *','联系人','送货地址 *'][i],'delivery_'+f,doc['delivery_'+f]??customer[f]??'',f==='phone'?'tel':'text',`${f==='contact'?'':'required'} maxlength="${f==='address'?500:200}"`)).join('');
      form.querySelector('.grid').append(box);
      const hint=form.querySelector(':scope > p.muted');if(hint)hint.textContent='保存草稿不扣库存，审核后按各商品仓库出库。';
      form.elements.warehouse_id.closest('label').hidden=true;
      const note=form.elements.note.closest('label');note.classList.add('sales-last-note');form.querySelector('.foot').before(note);
      form.elements.note.rows=1;form.elements.note.placeholder='备注（选填）';
      form.elements.party_id.addEventListener('change',()=>{const c=data.parties.find(p=>p.id===form.elements.party_id.value)||{};for(const f of ['phone','contact','address'])form.elements['delivery_'+f].value=c[f]||'';});
    }
    form.querySelectorAll('.line').forEach(line=>{
      const select=line.querySelector('[name=product]');
      if(!line.querySelector('.product-choose')){
        line.classList.add('compact-sale-line');
        const trigger=document.createElement('button');trigger.type='button';trigger.className='product-choose';select.before(trigger);select.classList.add('product-native');
        trigger.onclick=()=>select.value?editItem(form,{id:select.value,warehouse:line.dataset.warehouse||form.elements.warehouse_id.value,qty:Number(line.querySelector('[name=qty]').value)||1,price:line.querySelector('[name=price]').value},item=>applyItems(form,line,[item])):openPicker(form,line);
        select.addEventListener('change',()=>lineSummary(line));
        select.addEventListener('invalid',e=>{e.preventDefault();if(!panel)openPicker(form,line);});
        line.querySelector('[name=qty]').addEventListener('input',()=>lineSummary(line));line.querySelector('[name=price]').addEventListener('input',()=>lineSummary(line));
      }
      if(!line.dataset.warehouse)line.dataset.warehouse=form.elements.warehouse_id.value;
      lineSummary(line);
    });
  }
  function applyItems(form,target,items){
    let slot=target;
    for(const item of items){
      const duplicate=[...form.querySelectorAll('.line')].find(l=>l!==target&&l.querySelector('[name=product]').value===item.id&&l.dataset.warehouse===item.warehouse);
      if(duplicate){alert('该商品在此仓库已有一行，请点原商品修改数量。');return false;}
    }
    for(const item of items){
      if(!slot){form.querySelector('#lines').insertAdjacentHTML('beforeend',lineHTML({},'sale'));slot=form.querySelector('#lines').lastElementChild;}
      slot.dataset.warehouse=item.warehouse;const select=slot.querySelector('[name=product]');select.value=item.id;select.dispatchEvent(new Event('change',{bubbles:true}));
      slot.querySelector('[name=qty]').value=item.qty;slot.querySelector('[name=price]').value=item.price;slot=null;
    }
    form.elements.warehouse_id.value=form.querySelector('.line').dataset.warehouse;
    form.elements.warehouse_id.dispatchEvent(new Event('change',{bubbles:true}));enhance();return true;
  }
  function editItem(form,item,save){
    closeSheet();owner=form;
    const p=product(item.id);sheet=document.createElement('dialog');sheet.className='sales-item-sheet';
    sheet.innerHTML=`<header><h3>编辑商品</h3><button type="button" class="item-cancel">关闭</button></header><form><strong>${E(p.name)}</strong><small>编号：${E(p.code)}　型号：${E(p.model||'—')}</small>${select('出货仓库 *','store',data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),item.warehouse)}<p class="item-stock muted"></p><div class="item-fields">${input('数量 *','count',item.qty,'number','required min="1" max="1000000" step="1"')}${input('单价（元）','unit',item.price,'number','required min="0" max="99999999.99" step="0.01"')}${input('折扣（1 为原价，0.9 为九折）','discount','1','number','required min="0" max="1" step="0.01"')}${input('金额（元）','amount','','number','required min="0" step="0.01"')}</div><p class="item-round muted">金额按成交单价保留两位小数后计算。</p><p class="item-error" role="alert"></p><footer><button type="button" class="item-cancel">取消</button><button type="submit" class="primary">确认商品</button></footer></form>`;
    document.body.append(sheet);sheet.showModal();const f=sheet.querySelector('form');f.elements.store.required=true;
    let net=Number(item.price)||0;
    function totals(changed){
      const q=Number(f.elements.count.value)||0,u=Number(f.elements.unit.value)||0,d=Number(f.elements.discount.value);
      if(changed==='amount'&&q>0){net=Math.round(Number(f.elements.amount.value)*100/q)/100;f.elements.unit.value=net.toFixed(2);f.elements.discount.value='1';}
      else net=Math.round(u*d*100)/100;
      if(changed!=='amount')f.elements.amount.value=(Math.round(net*100)*q/100).toFixed(2);
      sheet.querySelector('.item-round').textContent=`成交单价 ¥${net.toFixed(2)}，实际金额 ¥${(Math.round(net*100)*q/100).toFixed(2)}（单价保留两位小数）`;
    }
    function stock(){const w=f.elements.store.value;const n=data.balances.filter(b=>b.product_id===p.id&&b.warehouse_id===w).reduce((n,b)=>n+Number(b.qty)-Number(b.unidentified),0)-(data.holds||[]).filter(h=>h.product_id===p.id&&h.warehouse_id===w).reduce((n,h)=>n+Number(h.qty),0);sheet.querySelector('.item-stock').textContent=w?`本仓可售：${Math.max(0,n)} ${p.unit}`:'请选择出货仓库';}
    f.addEventListener('input',e=>{if(['count','unit','discount','amount'].includes(e.target.name))totals(e.target.name);});f.elements.store.onchange=stock;
    sheet.querySelectorAll('.item-cancel').forEach(b=>b.onclick=closeSheet);sheet.addEventListener('cancel',e=>{e.preventDefault();closeSheet();});
    f.onsubmit=e=>{e.preventDefault();if(!f.reportValidity())return;const next={id:item.id,warehouse:f.elements.store.value,qty:Number(f.elements.count.value),price:net.toFixed(2)};if(save(next)!==false)closeSheet();};totals();stock();
  }
  function openPicker(form,target){
    closePicker();owner=form;const cart=new Map();let term='',category='',store='',stockOnly=false;
    panel=document.createElement('dialog');panel.className='sales-product-picker';
    panel.innerHTML=`<header><button type="button" class="picker-back">‹ 返回</button><h2>选择商品</h2></header><div class="picker-controls"><input type="search" aria-label="搜索商品" placeholder="搜索名称、型号、编号"><div class="picker-filters"><label>分类<select data-filter="category"><option value="">全部分类</option>${[...new Set(data.products.filter(p=>p.active).map(p=>p.category))].map(c=>`<option>${E(c)}</option>`).join('')}</select></label><label>库存范围<select data-filter="warehouse"><option value="">全部仓库</option>${opts(data.warehouses.filter(w=>w.active&&w.id!=='LEGACY'),'id','name','')}</select></label></div><label class="check"><input type="checkbox" data-filter="stock">只看有库存</label></div><div class="picker-results"></div><footer><span class="picker-total"></span><button type="button" class="primary picker-done">选好了</button></footer>`;
    document.body.append(panel);panel.showModal();
    function draw(){
      panel.querySelector('.picker-results').innerHTML=data.products.filter(p=>p.active&&(!category||p.category===category)&&[p.name,p.model,p.code,p.brand].join(' ').toLowerCase().includes(term.toLowerCase().trim())).filter(p=>!stockOnly||data.balances.some(b=>b.product_id===p.id&&b.warehouse_id!=='LEGACY'&&(!store||b.warehouse_id===store)&&Number(b.qty)-Number(b.unidentified)-(data.holds||[]).filter(h=>h.product_id===p.id&&h.warehouse_id===b.warehouse_id).reduce((n,h)=>n+Number(h.qty),0)>0)).map(p=>`<button type="button" class="picker-product-row" data-product-id="${E(p.id)}"><strong>${E(p.name)}</strong><small><span>编号：${E(p.code)}</span><span>型号：${E(p.model||'—')}</span></small><small><span>参考价 ¥${money(p.sale_cents)}</span><span>选择仓库 / 数量 ›</span></small></button>`).join('')||'<p class="empty">没有符合条件的商品</p>';
      const items=[...cart.values()];
      if(items.length)panel.querySelector('.picker-results').insertAdjacentHTML('afterbegin',`<section class="picker-chosen"><strong>已选商品</strong>${items.map(i=>`<div><button type="button" data-edit-choice="${E(i.id+'|'+i.warehouse)}">${E(product(i.id).name)} · ${E(warehouse(i.warehouse))} · ${i.qty} 件 · ¥${i.price} ›</button><button type="button" data-remove-choice="${E(i.id+'|'+i.warehouse)}">移除</button></div>`).join('')}</section>`);
      panel.querySelector('.picker-total').textContent=`已选 ${items.reduce((n,i)=>n+i.qty,0)} 件 · ¥${money(items.reduce((n,i)=>n+Math.round(Number(i.price)*100)*i.qty,0))}`;
    }
    panel.querySelector('.picker-back').onclick=closePicker;panel.addEventListener('cancel',e=>{e.preventDefault();closePicker();});
    panel.addEventListener('input',e=>{if(e.target.type==='search'){term=e.target.value;draw();}});
    panel.addEventListener('change',e=>{const k=e.target.dataset.filter;if(!k)return;if(k==='category')category=e.target.value;if(k==='warehouse')store=e.target.value;if(k==='stock')stockOnly=e.target.checked;draw();});
    panel.addEventListener('click',e=>{const remove=e.target.closest('[data-remove-choice]');if(remove){cart.delete(remove.dataset.removeChoice);draw();return;}
      const edit=e.target.closest('[data-edit-choice]');if(edit){const oldKey=edit.dataset.editChoice;editItem(form,{...cart.get(oldKey)},item=>{const newKey=item.id+'|'+item.warehouse;if(newKey!==oldKey&&cart.has(newKey)){alert('该商品在此仓库已选，请先修改或移除原选择。');return false;}cart.delete(oldKey);cart.set(newKey,item);draw();});return;}
      const b=e.target.closest('[data-product-id]');if(!b)return;const p=product(b.dataset.productId);editItem(form,{id:p.id,warehouse:store||form.elements.warehouse_id.value,qty:1,price:p.sale_cents==null?'':money(p.sale_cents)},item=>{const key=item.id+'|'+item.warehouse;const existing=cart.get(key);if(existing)item.qty+=existing.qty;cart.set(key,item);draw();});});
    panel.querySelector('.picker-done').onclick=()=>{if(!cart.size){closePicker();return;}if(applyItems(form,target,[...cart.values()]))closePicker();};draw();
  }
  new MutationObserver(enhance).observe(document.querySelector('#body'),{childList:true,subtree:true});modal.addEventListener('close',closePicker);window.addEventListener('hashchange',closePicker);window.addEventListener('inventory-navigation',closePicker);enhance();
})();

// Shared dense registers retain the existing forms, actions and export tables.
(() => {
  const supported=['purchase','receipt','issue','operations','counting','balances','lots','ledger','documents','parties','products'];
  const saved={};
  const before=render;
  render=function(){
    before();
    const main=document.querySelector('#main');
    const active=supported.includes(page);
    main.classList.toggle('compact-register',active);main.dataset.register=active?page:'';
    if(!active||!data)return;
    const table=main.querySelector('table');if(!table)return;
    if(['parties','products','documents'].includes(page))return; // This register already owns its type/status/search controls.
    const state=saved[page]||(saved[page]={status:'',warehouse:''});
    const withStatus=['purchase','receipt','issue','operations','counting'].includes(page);
    const metadata=row=>{
      const action=row.querySelector('[data-id]');
      const doc=action&&data.docs.find(d=>d.id===action.dataset.id);
      if(doc)return {status:doc.status,warehouses:[...new Set(data.lines.filter(l=>l.doc_id===doc.id).map(l=>l.warehouse_id||doc.warehouse_id))],sort:doc.created_at||doc.date,id:doc.id};
      const op=action&&(data.ops||[]).find(o=>o.id===action.dataset.id);
      if(op){const b=JSON.parse(op.body);return {status:op.state,warehouses:[b.warehouse_id,b.to_warehouse].filter(Boolean),sort:op.created_at,id:op.id};}
      const index=page==='balances'?1:page==='lots'?2:3;
      const name=row.children[index]?.textContent.trim();
      return {status:'',warehouses:data.warehouses.filter(w=>w.name===name).map(w=>w.id)};
    };
    const body=table.querySelector('tbody');
    const rows=body?[...body.querySelectorAll('tr')]:[];
    if(withStatus)rows.sort((a,b)=>{const x=metadata(a),y=metadata(b);return String(y.sort||'').localeCompare(String(x.sort||''))||String(y.id||'').localeCompare(String(x.id||''),undefined,{numeric:true});}).forEach(r=>body.append(r));
    const filters=document.createElement('div');filters.className='register-filters no-print';
    filters.innerHTML=`${withStatus?`<label>状态<select data-register-filter="status"><option value="">全部状态</option>${Object.entries(states).map(([v,n])=>`<option value="${E(v)}" ${state.status===v?'selected':''}>${E(n)}</option>`).join('')}</select></label>`:''}<label>仓库<select data-register-filter="warehouse"><option value="">全部仓库</option>${opts(data.warehouses,'id','name',state.warehouse)}</select></label>`;
    table.before(filters);
    let count=0;
    rows.forEach(row=>{
      if(row.querySelector('[colspan]')){row.remove();return;}
      const m=metadata(row);
      if((state.status&&withStatus&&m.status!==state.status)||(state.warehouse&&!m.warehouses.includes(state.warehouse)))row.remove();else count++;
    });
    const total=document.createElement('p');total.className='register-count';total.textContent=`共 ${count} 条记录`;table.before(total);
    if(!count){const empty=document.createElement('p');empty.className='register-empty';empty.textContent='没有符合条件的记录';table.after(empty);}
    // The secondary holds table in lots must follow the same warehouse filter.
    if(page==='lots'&&state.warehouse)main.querySelectorAll('table:not(:first-of-type) tbody tr').forEach(row=>{if(row.children[1]?.textContent.trim()!==warehouse(state.warehouse))row.remove();});
  };
  document.addEventListener('change',event=>{
    const field=event.target.dataset.registerFilter;
    if(!field||!supported.includes(page))return;
    (saved[page]||(saved[page]={status:'',warehouse:''}))[field]=event.target.value;render();
    document.querySelector(`[data-register-filter="${field}"]`)?.focus();
  });
})();

// Reset only on desktop route changes, not re-renders, edits or filtering.
window.addEventListener('hashchange',()=>{
 if(window.matchMedia('(min-width:701px)').matches)window.scrollTo({top:0,left:0,behavior:'instant'});
});
