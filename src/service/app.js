const $=s=>document.querySelector(s),esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let db={settings:{name:'德澳总部',months:36},orders:[],cards:[],stock:[],dealers:[],moves:[]};let revision=0,csrf='',busy=false,ready=false;let page='dashboard',query='',filter='全部';
let selectedStock=new Set();
function activeStock(){return db.stock.filter(s=>!s.deleted)}
function visibleStock(){return activeStock().filter(match).filter(s=>filter==='全部'||s.kind===filter||(filter==='库存不足'&&s.qty<s.min))}
const pages={dashboard:['工作台','⌂'],orders:['服务管理','▤'],cards:['电子保修卡','▣'],stock:['库存管理','▦'],dealers:['经销商管理','◇'],customers:['客户管理','♧'],settings:['基础设置','⚙']};
async function loadData(renderPage=true){const r=await fetch('/service/api/state',{cache:'no-store'});if(r.status===401){location.replace('/service/');throw new Error('请重新登录')}if(!r.ok)throw new Error('加载失败，请稍后刷新');const result=await r.json();db=result.data;revision=result.revision;csrf=result.csrf;ready=true;if(renderPage)render();}
async function persist(importing=false){busy=true;document.body.classList.add('saving');try{const r=await fetch('/service/api/state',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({revision,data:db,import:importing})});if(r.status===401){location.replace('/service/');throw new Error('登录已过期，请重新登录')}const result=await r.json();if(!r.ok)throw new Error(result.error||'保存失败');db=result.data;revision=result.revision;return true}catch(err){try{await loadData(false)}catch{ready=false}toast(err.message+'（请保留表单内容，确认后重试）');return false}finally{busy=false;document.body.classList.remove('saving')}}
function toast(t){if($('#modal').open){let note=$('#modal .form-message');if(!note){note=document.createElement('p');note.className='notice form-message';note.setAttribute('role','alert');$('#modalBody').append(note)}note.textContent=t}$('#toast').textContent=t;$('#toast').style.opacity=1;setTimeout(()=>$('#toast').style.opacity=0,2800)}
function tag(s){return `<span class="tag ${['待派工','库存不足','已过保'].includes(s)?'red':['已完工','已关闭','保修中','正常','合作中'].includes(s)?'green':s==='处理中'?'gold':s==='待处理'?'':'gray'}">${esc(s)}</span>`}
function go(p,f='全部'){query='';filter=f;if(location.hash==='#'+p){page=p;render()}else{location.hash=p}}
function title(h,p,actions=''){return `<div class="title"><div><h1>${h}</h1><p>${p}</p></div><div class="actions">${actions}</div></div>`}
function btn(t,action,primary=false){return `<button ${primary?'class="primary"':''} data-action="${action}">${t}</button>`}
function table(head,rows){return `<div class="table-wrap"><table><thead><tr>${head.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')||`<tr><td colspan="${head.length}" class="empty">没有找到符合条件的记录</td></tr>`}</tbody></table></div>`}
function tool(options,placeholder){return `<div class="toolbar"><input class="search" id="search" placeholder="${placeholder}" value="${esc(query)}" aria-label="搜索记录"><select id="filter" aria-label="筛选状态">${options.map(s=>`<option ${s===filter?'selected':''}>${s}</option>`).join('')}</select></div>`}
function match(o){return Object.values(o).join(' ').toLowerCase().includes(query.toLowerCase())}
function orderRows(list){return list.map(o=>`<tr><td class="mono">${esc(o.id)}<small>${esc(o.date)}</small></td><td>${tag(o.type)}</td><td>${esc(o.customer)}<small>${esc(o.phone)}</small></td><td>${esc(o.product)}<small>${esc(o.sn)}</small></td><td>${esc(o.channel)}<small>${esc(o.dealer)}</small></td><td>${tag(o.status)}</td><td>${esc(o.staff)}</td><td><button class="link" data-detail="${o.id}">查看详情 →</button></td></tr>`)}
function businessEntries(){
 const flows=[
  ['销售业务',[['inventory/sale/new','销售开单'],['inventory/sale','销售单 / 审核'],['inventory/finance_ar','应收款 / 收款']]],
  ['采购业务',[['inventory/purchase','采购订单'],['inventory/receipt','采购入库'],['inventory/balances','查询库存']]],
  ['服务管理',[['orders/new','服务开单'],['orders','工单跟进'],['cards','电子保修卡']]]
 ];
 return `<section class="business-workflows" aria-label="业务流程导航"><div class="workflow-heading"><h2>业务导航</h2><a href="#inventory/documents">全部单据 →</a></div>${flows.map(([name,steps])=>`<div class="business-workflow"><strong>${name}</strong><div>${steps.map(([route,label],i)=>`${i?'<span class="workflow-arrow" aria-hidden="true">→</span>':''}<a href="#${route}"><span class="workflow-step">${i+1}</span>${label}</a>`).join('')}</div></div>`).join('')}</section>`;
}
function dashboard(){let pending=db.orders.filter(x=>x.status==='待派工').length,active=db.orders.filter(x=>!['已完工','已关闭'].includes(x.status)).length,low=activeStock().filter(x=>x.qty<x.min).length;return title('工作台','每一台设备，每一次服务，都有迹可循。',btn('导出工单','export')+btn('新增工单','new-order',true))+businessEntries()+`<div class="metrics">${[['待处理工单',active,'▤',`其中 ${pending} 单等待派工`],['电子保修卡',db.cards.length,'▣','设备档案与服务记录关联'],['总部库存总量',activeStock().reduce((a,b)=>a+b.qty,0),'▦','整机与配件数量合计'],['合作经销商',db.dealers.length,'◇','直营与经销渠道统一管理']].map(([l,v,i,s])=>`<div class="metric"><span class="metric-label">${l}</span><span class="metric-icon">${i}</span><strong>${v}<span style="font-size:12px;color:#a1a8b0;margin-left:8px">${l==='总部库存总量'?'件':'个'}</span></strong><small>${s}</small></div>`).join('')}</div><div class="grid"><section class="panel"><div class="panel-head"><h2>常用功能</h2><a href="/service/inventory#documents">单据中心 →</a><span style="font-size:11px;color:#9ba3ac">从登记到服务，一站完成</span></div><div class="quick">${[['▤','新增工单','new-order'],['▣','登记保修卡','new-card'],['↓','采购入库','in']].map(([i,t,a])=>`<button data-action="${a}"><b>${i}</b>${t}</button>`).join('')}</div><div class="flow"><b>01 销售登记</b> → <b>02 出库交付</b> → <b>03 安装开卡</b> → <b>04 服务管理</b></div></section><section class="panel"><div class="panel-head"><h2>需要关注</h2><span class="tag red">${pending+low} 项</span></div><div class="todo"><button data-go="orders" data-filter="待派工">等待分配的工单 <span class="count">${pending} →</span></button><button data-go="stock" data-filter="库存不足">低于预警线的物料 <span class="count">${low} →</span></button><button data-go="cards">查看设备保修档案 <span class="count">${db.cards.length} →</span></button></div></section></div><section class="panel"><div class="panel-head"><h2>最近工单 <span style="font-weight:400;color:#a0a7af;margin-left:10px;font-size:11px">跟进每一次服务</span></h2><button class="link" data-go="orders">全部工单 →</button></div>${table(['工单编号 / 日期','类型','客户信息','产品 / 机身编号','销售渠道','状态','服务人员','操作'],orderRows(db.orders.slice(0,5)))}<div class="table-foot"><span>共 ${db.orders.length} 张工单</span></div></section>`}
function cards(){let rows=db.cards.filter(match).filter(c=>filter==='全部'||cardStatus(c)===filter);return title('电子保修卡','一机一卡，关联购买、安装与维修记录。',btn('新增保修卡','new-card',true))+`<section class="panel">${tool(['全部','保修中','已过保'],'搜索客户、机身编号或产品型号')}${table(['保修卡号','客户','产品 / 机身编号','销售归属','保修期限','状态','操作'],rows.map(c=>`<tr><td class="mono">${esc(c.id)}</td><td>${esc(c.customer)}<small>${esc(c.phone)}</small></td><td>${esc(c.product)}<small>${esc(c.sn)}${c.inventorySN?' · 库存 SN：'+esc(c.inventorySN):''}</small></td><td>${esc(c.dealer)}</td><td>${esc(c.start)}<small>至 ${esc(c.end)}</small></td><td>${tag(cardStatus(c))}</td><td><button class="link" data-card="${c.id}">查看保修卡 →</button></td></tr>`))}</section>`}
function cardStatus(c){return c.end<localDate()?'已过保':'保修中'}
function localDate(){let d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`}
function stock(){location.replace('/service/inventory');return ''; }
function legacyStock(){let rows=visibleStock();selectedStock=new Set([...selectedStock].filter(id=>rows.some(s=>s.id===id)));return title('库存管理','管理物料档案，追踪每一次库存变化。',btn('新增物料','new-stock')+btn('入库登记','in')+btn('出库登记','out',true))+`<section class="panel">${tool(['全部','整机','配件','库存不足'],'搜索物料名称或型号')}<div class="toolbar"><span>已选 ${selectedStock.size} 项</span>${btn('批量删除','delete-stock')}<span class="right">可删除零库存物料，历史流水保留</span></div>${table(['<input type="checkbox" id="stock-all" aria-label="全选当前筛选物料" '+(rows.length&&rows.every(s=>selectedStock.has(s.id))?'checked':'')+'>','物料编码','物料名称','规格型号','分类','可用库存','预警值','库存状态','操作'],rows.map(s=>`<tr><td><input type="checkbox" data-stock-select="${s.id}" aria-label="选择${esc(s.name)}" ${selectedStock.has(s.id)?'checked':''}></td><td class="mono">${s.id}</td><td>${esc(s.name)}</td><td>${esc(s.model)}</td><td>${tag(s.kind)}</td><td><b>${s.qty}</b> ${esc(s.unit)}</td><td>${s.min} ${esc(s.unit)}</td><td>${tag(s.qty<s.min?'库存不足':'正常')}</td><td><button class="link" data-edit-stock="${s.id}">编辑</button></td></tr>`))}</section><section class="panel"><div class="panel-head"><h2>出入库流水</h2><span class="mono">${db.moves.length} 条记录</span></div>${table(['时间','物料','业务类型','数量','去向 / 来源','关联工单'],db.moves.map(m=>`<tr><td>${esc(m.date)}</td><td>${esc(m.name)}</td><td>${tag(m.type)}</td><td>${m.delta>0?'+':''}${m.delta}${m.unit?' '+esc(m.unit):''}</td><td>${esc(m.target)}</td><td>${esc(m.order||'—')}</td></tr>`))}</section>`}
function editStock(id){const item=activeStock().find(s=>s.id===id);if(!item)return;const units=['台','个','件'];if(!units.includes(item.unit))units.push(item.unit);form('edit-stock',field('物料名称','name','text',item.name)+field('规格型号','model','text',item.model)+select('分类','kind',['整机','配件'])+select('计量单位','unit',units)+field('预警值','min','number',item.min,'min="0" step="1"')+`<p class="notice wide">当前库存：${item.qty} ${esc(item.unit)}。库存数量通过出入库调整；有库存时不能更改计量单位。</p>`);const f=$('#modal form');f.dataset.id=id;f.elements.kind.value=item.kind;f.elements.unit.value=item.unit;}
function dealers(){return title('经销商管理','连接各地门店，统一管理渠道与服务归属。',btn('新增经销商','new-dealer',true))+`<section class="panel">${tool(['全部','合作中'],'搜索经销商、联系人或地区')}${table(['经销商编号','经销商名称','联系人','联系电话','所在地区','已登记保修卡','状态'],db.dealers.filter(match).map(d=>`<tr><td class="mono">${d.id}</td><td>${esc(d.name)}</td><td>${esc(d.contact)}</td><td>${esc(d.phone)}</td><td>${esc(d.area)}</td><td>${db.cards.filter(c=>c.dealer===d.name).length}</td><td>${tag(d.status)}</td></tr>`))}</section><div class="notice">当前为总部视角的交互原型。经销商独立登录和服务端数据隔离尚未接入。</div>`}
function serviceCustomers(){let map=new Map();[...db.orders,...db.cards].forEach(o=>{if(!map.has(o.phone))map.set(o.phone,o)});return title('客户管理','查看客户、设备保修与服务记录。',btn('电子保修卡','customer-cards')+btn('经销商管理','customer-dealers'))+`<section class="panel">${table(['客户姓名','联系电话','设备数量','服务工单','最近记录地址','操作'],[...map.values()].filter(match).map(o=>`<tr><td>${esc(o.customer)}</td><td>${esc(o.phone)}</td><td>${new Set([...db.orders,...db.cards].filter(c=>c.phone===o.phone).map(c=>c.sn)).size}</td><td>${db.orders.filter(c=>c.phone===o.phone).length}</td><td>${esc(o.address||'待补充')}</td><td><button class="link" data-customer="${esc(o.phone)}">查看档案 →</button></td></tr>`))}</section>`}
function settings(){return title('基础设置','维护工作空间名称与新卡默认保修期限。')+`<section class="panel"><div class="panel-head"><h2>业务配置</h2></div><form id="settings-form" class="settings"><label>工作空间名称<input name="name" required maxlength="40" value="${esc(db.settings.name)}"></label><label>新保修卡默认期限（月）<input name="months" type="number" min="1" max="240" required value="${db.settings.months}"></label><p class="notice">请按实际保修政策设置默认期限。修改后只影响新卡，不改变已有保修卡。</p><button class="primary">保存设置</button></form></section><section class="panel"><div class="panel-head"><h2>资料导出与完整备份</h2></div><div class="settings"><p style="color:#7b8693;font-size:12px">此处仅导出旧业务资料 JSON，不含新版采购、销售、SN 和图片。完整备份请在服务器运行第一期安装包中的 backup_full.sh。</p>${btn('导出工单与保卡资料','backup')}</div></section>`}
function render(){page=pages[location.hash.slice(1)]?location.hash.slice(1):'dashboard';$('#nav').innerHTML='<div class="nav-label">业务工作空间</div>'+[
 ['dashboard','工作台','⌂','#dashboard'],['sales','销售管理','↑','/service/inventory#sale'],
 ['accounts','账款管理','¥','#inventory/finance'],['purchasing','采购管理','↓','/service/inventory#purchase'],['stock','库存管理','▦','/service/inventory#balances'],
 ['customers','客户管理','♧','#customers'],['orders','服务管理','▤','#orders'],['settings','基础设置','⚙','#settings']
].map(([k,n,i,url])=>`<a href="${url}" data-section="${k}" class="${(page===k||(k==='customers'&&['cards','dealers'].includes(page)))?'active':''}"><i>${i}</i><span>${n}</span></a>`).join('');$('#crumb').textContent='工作空间 / '+(['cards','dealers'].includes(page)?'客户管理 / ':'')+pages[page][0];$('.workspace b').textContent=db.settings.name;$('#today').textContent=new Date().toLocaleDateString('zh-CN',{year:'numeric',month:'long',day:'numeric'});$('#main').innerHTML=page.startsWith('workflow-')?window.renderWorkflow(page):page==='dashboard'?dashboard():page==='orders'?title('业务管理','安装、维修与派工，在同一处跟进。',btn('导出工单','export')+btn('新增工单','new-order',true))+`<section class="panel">${tool(['全部','待派工','待处理','处理中','已完工','已关闭'],'搜索工单、客户、电话或机器编号')}${table(['工单编号 / 日期','类型','客户信息','产品 / 机身编号','销售渠道','状态','服务人员','操作'],orderRows(db.orders.filter(match).filter(o=>filter==='全部'||o.status===filter)))}<div class="table-foot"><span>共 ${db.orders.filter(match).filter(o=>filter==='全部'||o.status===filter).length} 条符合条件的记录</span><span>安装与维修统一受理</span></div></section>`:({cards,stock,dealers,customers,settings}[page])();}
function openModal(h,content){$('#modalBody').innerHTML=`<div class="modal-head"><h2>${h}</h2><button type="button" data-action="close" aria-label="关闭">×</button></div>${content}`;if(!$('#modal').open)$('#modal').showModal()}
function field(label,name,type='text',value='',extra=''){return `<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" required ${extra}></label>`}
function select(label,name,items){return `<label>${label}<select name="${name}">${items.map(i=>`<option value="${esc(i)}">${esc(i)}</option>`).join('')}</select></label>`}
function form(type,body,caption='确认保存'){openModal({'new-order':'新建服务工单','new-card':'登记电子保修卡','new-dealer':'新增经销商','new-stock':'新增物料','edit-stock':'编辑物料','bind-card':'绑定微信客户',in:'入库登记',out:'出库登记'}[type],`<form data-form="${type}"><div class="form-grid">${body}</div><div class="modal-foot">${btn('取消','close')}<button class="primary" type="submit">${caption}</button></div></form>`);$('#modal [data-action="close"]')?.setAttribute('type','button');$('#modal .modal-foot [data-action="close"]')?.setAttribute('type','button')}
function newForm(a){const products=activeStock().filter(s=>s.kind==='整机').map(s=>s.name+' '+s.model),dealers=['总部直营',...db.dealers.map(d=>d.name)],phone=field('客户手机','phone','tel','','pattern="[0-9]{11}" maxlength="11"');if(a==='new-stock')form(a,field('物料名称','name')+field('规格型号','model')+select('分类','kind',['整机','配件'])+select('计量单位','unit',['台','个','件'])+field('预警值','min','number',0,'min="0" step="1"'));if(a==='new-order')form(a,select('工单类型','type',['安装','维修'])+select('销售归属','dealer',dealers)+field('客户姓名','customer')+phone+select('产品型号','product',products)+field('机身编号 / 临时设备编号','sn')+field('预约日期','date','date',localDate())+field('安装 / 服务地址','address')+'<label class="wide">需求描述<textarea name="note" rows="3" required></textarea></label>');if(a==='new-card')form(a,field('客户姓名','customer')+phone+select('产品型号','product',products)+field('唯一机身编号','sn')+select('销售归属','dealer',dealers)+field('保修起始日期','start','date',localDate())+`<p class="notice wide">按设置中的 ${db.settings.months} 个月计算到期日。仅登记档案，不重复扣减库存。</p>`);if(a==='new-dealer')form(a,field('经销商名称','name')+field('联系人','contact')+field('联系电话','phone','tel','','pattern="[0-9]{11}" maxlength="11"')+field('所在地区','area'));if(a==='in'||a==='out')form(a,select('物料','item',activeStock().map(s=>s.id+' · '+s.name+' '+s.model))+select('业务类型','type',a==='in'?['采购入库','退料入库']:['销售出库','维修耗用','报废出库'])+field('数量','qty','number',1,'min="1" step="1"')+field(a==='in'?'来源 / 供应商':'去向 / 经销商 / 领用人','target')+`<label class="wide">关联工单（维修耗用必选）<select name="order"><option value="">不关联</option>${db.orders.filter(o=>!['已完工','已关闭'].includes(o.status)).map(o=>`<option value="${o.id}">${o.id} · ${esc(o.customer)}</option>`).join('')}</select></label>`)}
function detail(id){let o=db.orders.find(x=>x.id===id);openModal('工单详情',`<p class="mono">${o.id}　${tag(o.status)}</p><div class="details">${[['客户',o.customer+' · '+o.phone],['产品',o.product],['机身编号',o.sn],['销售归属',o.dealer],['服务地址',o.address],['服务人员',o.staff]].map(([l,v])=>`<div><label>${l}</label>${esc(v)}</div>`).join('')}</div><p>${esc(o.note)}</p><h2>服务记录</h2><div class="history">${o.history.map(h=>`<div>${esc(h)}</div>`).join('')}</div>${!['已完工','已关闭'].includes(o.status)?`<form data-form="update-order" data-id="${id}"><div class="form-grid">${select('服务人员','staff',['王师傅','刘师傅','陈师傅'])}${select('处理状态','status',['待处理','处理中','已完工'])}<label class="wide">处理记录<textarea required name="record" rows="3" placeholder="填写派工说明或维修结果"></textarea></label></div><div class="modal-foot"><button class="primary">更新工单</button></div></form>`:`<div class="modal-foot">${o.status==='已完工'?`<button class="primary" data-close-order="${id}">确认关闭工单</button>`:''}</div>`}`);let sel=$('#modal select[name="staff"]');if(sel&&o.staff!=='未指派')sel.value=o.staff;let st=$('#modal select[name="status"]');if(st&&o.status!=='待派工')st.value=o.status}
function showCard(id){let c=db.cards.find(x=>x.id===id);openModal('电子保修卡',`<div class="card-preview"><svg class="brand-logo card-logo" viewBox="365 1223 7952 908" role="img" aria-label="DEAOE 德澳"><defs><filter id="card-white-logo" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  -0.2126 -0.7152 -0.0722 0 1"/><feComposite in2="SourceAlpha" operator="in"/></filter></defs><image href="assets/deaoe-logo.png" width="8661" height="3465" filter="url(#card-white-logo)"/></svg><h3>产品电子保修卡</h3><p>${esc(c.product)}</p><p>机身编号　${esc(c.sn)}</p><p>有效期限　${c.start} — ${c.end}</p></div><div class="details"><div><label>客户</label>${esc(c.customer)}</div><div><label>保修状态</label>${tag(cardStatus(c))}</div><div><label>卡号</label>${c.id}</div><div><label>销售归属</label>${esc(c.dealer)}</div></div><h2>关联服务记录</h2><div class="history">${db.orders.filter(o=>o.sn===c.sn).map(o=>`<div>${o.date} · ${o.type} · ${o.status}</div>`).join('')||'暂无维修或安装记录'}</div><p style="color:#929aa4;font-size:11px">微信客户：${esc(c.miniCustomerId||'尚未绑定')}</p><div class="modal-foot"><button class="primary" data-bind-card="${c.id}">绑定 / 更换微信客户</button></div>`)}
function download(name,text,type){let url=URL.createObjectURL(new Blob(['\ufeff',text],{type})),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
document.addEventListener('click',async e=>{if(e.target.closest('#logout-form'))return;if(busy||!ready){e.preventDefault();return;}if(e.target.closest('nav a,.brand')){query='';filter='全部'}let b=e.target.closest('button');if(!b)return;if(b.dataset.bindCard){const c=db.cards.find(c=>c.id===b.dataset.bindCard);form('bind-card',field('会员号','miniCustomerId','text',c.miniCustomerId||'')+'<p class="notice wide">请核实客户与设备归属，再填写客户小程序“我的”页的 DE 开头会员号（也支持原 WX 编号）。保存后，该微信账号可以查看这张保修卡并申请服务。</p>');$('#modal form').dataset.id=c.id;return}if(b.dataset.editStock){editStock(b.dataset.editStock);return}if(b.dataset.go)go(b.dataset.go,b.dataset.filter||'全部');if(b.dataset.detail)detail(b.dataset.detail);if(b.dataset.card)showCard(b.dataset.card);if(b.dataset.customer){let phone=b.dataset.customer,items=db.orders.filter(o=>o.phone===phone),cards=db.cards.filter(c=>c.phone===phone);openModal('客户服务档案',`<p>${esc(phone)}</p><h2>关联设备保修卡</h2><div class="history">${cards.map(c=>`<div>${esc(c.product)} · ${esc(c.sn)} · ${cardStatus(c)}</div>`).join('')||'暂无保修卡'}</div><h2 style="margin-top:22px">服务工单</h2><div class="history">${items.map(o=>`<div>${o.date} · ${o.type} · ${o.status}</div>`).join('')||'暂无工单'}</div>`)}if(b.dataset.closeOrder){let o=db.orders.find(o=>o.id===b.dataset.closeOrder);o.status='已关闭';o.history.push('总部确认关闭工单');if(!await persist())return;$('#modal').close();render();toast('工单已关闭')}let a=b.dataset.action;if(!a)return;if(a==='customer-cards'){go('cards');return;}if(a==='customer-dealers'){go('dealers');return;}if(['in','out','new-stock','delete-stock'].includes(a)){location.hash='inventory/'+({in:'receipt',out:'issue','new-stock':'products','delete-stock':'products'}[a]);return;}if(a==='close'){$('#modal').close();return}if(a==='delete-stock'){const items=visibleStock().filter(s=>selectedStock.has(s.id));if(!items.length){toast('请先勾选要删除的物料');return}if(items.some(s=>s.qty!==0)){toast('所选物料中有非零库存，请先处理库存或取消勾选');return}openModal('确认批量删除',`<p>将从物料列表移除以下 ${items.length} 项，历史出入库流水仍保留。</p><div class="history">${items.map(s=>`<div>${esc(s.name)} · ${esc(s.model)}</div>`).join('')}</div><div class="modal-foot">${btn('取消','close')}${btn('确认删除','confirm-delete-stock',true)}</div>`);return}if(a==='confirm-delete-stock'){const items=visibleStock().filter(s=>selectedStock.has(s.id));if(!items.length||items.some(s=>s.qty!==0)){toast('选择已变化，请重新选择');return}items.forEach(s=>{s.deleted=true;s.deletedAt=new Date().toISOString()});if(!await persist())return;selectedStock.clear();$('#modal').close();render();toast('所选物料已删除，历史流水已保留');return}if(a==='export'){const cell=v=>'"'+String(v??'').replace(/^[=+@-]/,"'$&").replaceAll('"','""')+'"';download('德澳工单.csv',[['工单编号','类型','客户','手机','产品','机身编号','渠道','归属','状态','人员','日期'],...db.orders.map(o=>[o.id,o.type,o.customer,o.phone,o.product,o.sn,o.channel,o.dealer,o.status,o.staff,o.date])].map(r=>r.map(cell).join(',')).join('\n'),'text/csv;charset=utf-8');toast('工单已导出');return}if(a==='legacy-preview'){let old;try{old=JSON.parse(localStorage.getItem('deaoe-console-v1'))}catch{}if(!old){toast('此浏览器没有旧版数据');return}openModal('迁移旧浏览器数据',`<p>旧数据可能包含演示记录，请先下载检查。只能导入到尚未保存过数据的服务器。</p><div class="details">工单：${Number(old.orders?.length||0)} 条<br>保修卡：${Number(old.cards?.length||0)} 张<br>物料：${Number(old.stock?.length||0)} 项</div><div class="modal-foot">${btn('下载旧数据检查','legacy-download')}${btn('确认迁移到服务器','legacy-import',true)}</div>`);return}if(a==='legacy-download'){download('旧浏览器数据.json',localStorage.getItem('deaoe-console-v1')||'{}','application/json');return}if(a==='legacy-import'){if(revision!==0){toast('服务器已有保存记录，不能覆盖导入');return}try{db=JSON.parse(localStorage.getItem('deaoe-console-v1'));if(!await persist(true))return;$('#modal').close();render();toast('旧数据已迁移，浏览器副本仍保留')}catch{toast('旧数据格式不正确')}return}if(a==='backup'){try{await loadData()}catch(err){toast(err.message);return}download('德澳业务资料-非完整备份.json',JSON.stringify(db,null,2),'application/json');return}newForm(a)});
document.addEventListener('input',e=>{if(e.target.id==='search'){let pos=e.target.selectionStart;query=e.target.value;render();$('#search').focus();$('#search').setSelectionRange(pos,pos)}});document.addEventListener('change',e=>{if(busy||!ready)return;if(e.target.id==='stock-all'){selectedStock=new Set(e.target.checked?visibleStock().map(s=>s.id):[]);render();return}if(e.target.dataset.stockSelect){if(e.target.checked)selectedStock.add(e.target.dataset.stockSelect);else selectedStock.delete(e.target.dataset.stockSelect);render();return}if(e.target.id==='filter'){filter=e.target.value;render()}});
document.addEventListener('submit',async e=>{if(e.target.id==='logout-form')return;e.preventDefault();if(busy||!ready)return;let f=e.target,d=Object.fromEntries(new FormData(f));if(f.id==='settings-form'){db.settings={name:d.name.trim(),months:Number(d.months)};if(!await persist())return;render();toast('设置已保存');return}let type=f.dataset.form;if(!type)return;for(let k in d)d[k]=d[k].trim();if(Object.entries(d).some(([k,v])=>k!=='order'&&!v)){toast('请完整填写表单');return}const uid=p=>p+crypto.randomUUID().replaceAll('-','');if(type==='new-order'){db.orders.unshift({...d,id:uid('GD'),channel:d.dealer==='总部直营'?'直营':'经销商',status:'待派工',staff:'未指派',history:['工单已登记，等待派工']})}if(type==='new-card'){if(db.cards.some(c=>c.sn.toLowerCase()===d.sn.toLowerCase())){toast('该机身编号已有保修卡，请勿重复登记');return}let start=new Date(d.start+'T00:00:00'),day=start.getDate();start.setDate(1);start.setMonth(start.getMonth()+db.settings.months);let last=new Date(start.getFullYear(),start.getMonth()+1,0).getDate();start.setDate(Math.min(day,last));let end=[start.getFullYear(),String(start.getMonth()+1).padStart(2,'0'),String(start.getDate()).padStart(2,'0')].join('-');db.cards.unshift({...d,id:uid('BK'),end})}if(type==='bind-card'){const c=db.cards.find(c=>c.id===f.dataset.id);if(!c){toast('保修卡不存在');return}c.miniCustomerId=d.miniCustomerId.toUpperCase()}if(type==='edit-stock'){const item=db.stock.find(s=>s.id===f.dataset.id&&!s.deleted);if(!item){toast('该物料已不存在，请刷新');return}if(item.qty!==0&&d.unit!==item.unit){toast('有库存时不能更改计量单位');return}Object.assign(item,{name:d.name,model:d.model,kind:d.kind,unit:d.unit,min:Number(d.min)})}if(type==='new-stock'){db.stock.push({...d,id:uid('P'),qty:0,min:Number(d.min)})}if(type==='new-dealer'){if(db.dealers.some(x=>x.name===d.name)){toast('经销商名称已存在');return}db.dealers.push({...d,id:uid('D'),status:'合作中'})}if(type==='in'||type==='out'){let s=activeStock().find(s=>s.id===d.item.split(' · ')[0]),qty=Number(d.qty);if(!s){toast('物料已删除或不存在，请重新选择');return}if(!Number.isSafeInteger(qty)||qty<1){toast('请输入有效正整数');return}if(type==='out'&&qty>s.qty){toast('可用库存不足，无法出库');return}if(d.type==='维修耗用'&&!d.order){toast('维修耗用必须关联工单');return}let delta=type==='in'?qty:-qty;s.qty+=delta;db.moves.unshift({...d,id:uid('KC'),name:s.name,stockId:s.id,unit:s.unit,date:new Date().toLocaleString('zh-CN'),delta});if(d.order){let o=db.orders.find(o=>o.id===d.order);o.history.push(`${d.type}：${s.name} ${qty}${s.unit}，${d.target}`)}}if(type==='update-order'){let o=db.orders.find(o=>o.id===f.dataset.id);o.staff=d.staff;o.status=d.status;o.history.push(`${d.staff} · ${d.status}：${d.record}`)}if(!await persist())return;$('#modal').close();render();toast('已保存，相关记录已更新')});
window.addEventListener('hashchange',()=>{query='';if(ready)render()});window.addEventListener('pageshow',e=>{if(e.persisted)location.reload()});$('#main').innerHTML='<p class="notice">正在加载…</p>';loadData().catch(e=>{$('#main').textContent=e.message});

// Readable phone navigation and record labels; desktop DOM and actions stay intact.
(()=>{
 const menu=document.createElement('details');
 menu.className='service-mobile-menu';
 menu.innerHTML='<summary><span>☷ 应用</span><span class="mobile-menu-hint">展开功能</span></summary><nav aria-label="业务应用"></nav>';
 document.querySelector('.shell').prepend(menu);
 function refresh(){
  const routes=window.deaoeInventoryRoutes||{};
  const current=location.hash.slice(1)||'dashboard';
  const paths={
   home:'M3 11 12 3l9 8M5 10v11h5v-7h4v7h5V10',
   document:'M6 3h12v18H6zM9 8h6M9 12h6M9 16h4',
   out:'M4 5h11v14H4zM10 12h11m-4-4 4 4-4 4',
   in:'M9 5h11v14H9M3 12h12m-4-4 4 4-4 4',
   box:'m3 7 9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
   user:'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M4 21v-3a8 8 0 0 1 16 0v3',
   card:'M3 5h18v14H3zM3 10h18M7 15h4',
   grid:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'
  };
  const iconFor=href=>href==='#dashboard'?'home':href.includes('issue')?'out':href.includes('receipt')?'in':href.includes('cards')?'card':/customers|dealers|parties/.test(href)?'user':/balances|warehouses|products|lots/.test(href)?'box':href==='#settings'?'grid':'document';
  const link=(href,label)=>`<a href="${esc(href)}" ${current===href.slice(1)?'aria-current="page"':''}><span class="mobile-app-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${paths[iconFor(href)]}"/></svg></span><span>${esc(label)}</span></a>`;
  const group=(id,title,items)=>`<section class="mobile-app-group" data-group="${id}"><h2>${title}</h2><div>${items.map(([href,label])=>link(href,label)).join('')}</div></section>`;
  menu.querySelector('nav').innerHTML=
   group('common','常用功能',[['#dashboard','工作台'],['#workflow-sales','业务导航'],['#inventory/documents','单据中心']])+
   group('sales','销售管理',[['#inventory/sale/new','销售开单'],['#inventory/sale','销售单']])+
   group('accounts','账款管理',[['#inventory/finance_ar','应收款'],['#inventory/finance_advance_receipt','预收款'],['#inventory/finance_ap','应付款'],['#inventory/finance_advance_payment','预付款']])+
   group('stock','仓库管理',[['#inventory/balances','库存查询'],['#inventory/counting','库存盘点'],['#inventory/operations','退换与调拨'],['#inventory/serials','SN 与标签'],['#inventory/lots','批次与分区'],['#inventory/ledger','库存流水']])+
   group('purchasing','采购管理',[['#inventory/purchase/new','采购开单'],['#inventory/receipt/new','采购入库'],['#inventory/purchase','采购记录']])+
   group('service','服务管理',[['#orders/new','服务开单'],['#orders','工单记录'],['#cards','电子保修卡']])+
   group('basic','基础数据',[['#inventory/products','产品档案'],['#inventory/warehouses','仓库设置'],['#inventory/parties','往来单位'],['#settings','基础设置']])+
   group('branches','网点管理',[['#dealers','经销商门店']])+
   group('records','资料管理',[['#customers','客户管理'],['#inventory/templates','打印模板'],['#inventory/events','操作日志']]);
  document.querySelectorAll('#main table').forEach(t=>{
   t.classList.add('service-mobile-records');
   const labels=[...t.querySelectorAll('thead th')].map(th=>th.textContent);
   t.querySelectorAll('tbody tr').forEach(row=>[...row.children].forEach((cell,i)=>{
    if(!cell.hasAttribute('colspan'))cell.dataset.label=labels[i]||'';
   }));
  });
 }
 new MutationObserver(refresh).observe(document.querySelector('#main'),{childList:true});
 menu.addEventListener('click',e=>{if(e.target.closest('a')){query='';filter='全部';menu.open=false;}});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&menu.open){menu.open=false;menu.querySelector('summary').focus();}});
 refresh();
})();

// One persistent workspace: inventory keeps its own scripts and data without reloading the shell.
const inventoryRoutes={finance_ar:['accounts','应收款'],finance_ap:['accounts','应付款'],finance_advance_receipt:['accounts','预收款'],finance_advance_payment:['accounts','预付款'],finance:['accounts','往来账款'],documents:['dashboard','单据中心'],sale:['sales','销售单'],issue:['sales','销售出库'],purchase:['purchasing','采购订单'],receipt:['purchasing','采购入库'],parties:['purchasing','供应商与往来单位'],overview:['stock','进销存总览'],balances:['stock','库存查询'],products:['stock','产品档案'],warehouses:['stock','仓库管理'],operations:['stock','退换货与调拨'],counting:['stock','库存盘点'],lots:['stock','批次与分区'],serials:['stock','SN 与标签'],templates:['stock','打印模板'],ledger:['stock','库存流水'],events:['stock','操作日志']};
window.deaoeInventoryRoutes=inventoryRoutes;
function inventoryRoute(){const h=location.hash.slice(1);const route=h.replace(/\/new$/,'').slice(10);return h.startsWith('inventory/')&&inventoryRoutes[route]?route:null;}
(()=>{
 let frame=null,lastHash='',requestId=0;
 const routeMessage=route=>({type:'deaoe-inventory-route',route,create:location.hash.endsWith('/new'),requestId});
 const previousRender=render;
 render=function(){
  previousRender();
  const route=inventoryRoute();
  const currentHash=location.hash;
  if(currentHash!==lastHash){lastHash=currentHash;requestId++;if(window.matchMedia('(min-width:701px)').matches)window.scrollTo({top:0,left:0,behavior:'instant'});}
  document.body.classList.toggle('inventory-workspace',!!route);
  if(!route){if(frame){frame.hidden=true;frame.contentWindow.postMessage({type:'deaoe-inventory-dismiss'},location.origin);}return;}
  $('#main').hidden=true;
  const [section,label]=inventoryRoutes[route];
  $('#nav a.active')?.classList.remove('active');
  $(`#nav a[data-section="${section}"]`)?.classList.add('active');
  $('#crumb').textContent='工作空间 / '+({accounts:'账款管理',sales:'销售管理',purchasing:'采购管理',stock:'库存管理'}[section])+' / '+label;
  ensureFrame(route);
  frame.hidden=false;
  frame.contentWindow.postMessage(routeMessage(route),location.origin);
 };
 // Warm one hidden workspace after the shell is ready, never create a second frame.
 function ensureFrame(route='balances'){
  if(frame)return;
  frame=document.createElement('iframe');frame.id='inventory-workspace-frame';frame.title='进销存业务';frame.hidden=true;
  frame.addEventListener('load',()=>{
   if(frame.contentWindow.location.pathname!=='/service/inventory'){
    if(inventoryRoute())location.assign('/service/');else{frame.remove();frame=null;}
    return;
   }
   const current=inventoryRoute();if(current)frame.contentWindow.postMessage(routeMessage(current),location.origin);
  });
  frame.src='/service/inventory?embedded=1#'+route;
  $('#main').after(frame);
 }
 let warmScheduled=false;
 const renderBeforeWarm=render;
 render=function(){
  renderBeforeWarm();
  if(ready&&!frame&&!warmScheduled){
   warmScheduled=true;
   const warm=()=>{warmScheduled=false;if(ready&&!frame&&document.visibilityState!=='hidden')ensureFrame();};
   if(window.requestIdleCallback)window.requestIdleCallback(warm,{timeout:1500});else setTimeout(warm,300);
  }
 };
 const wrappedRender=render;
 render=function(){if(!inventoryRoute())$('#main').hidden=false;wrappedRender();};
 document.addEventListener('click',e=>{
  const a=e.target.closest('a');if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||a.target==='_blank')return;
  const url=new URL(a.href,location.href);
  if(url.origin===location.origin&&url.pathname===location.pathname&&url.hash===location.hash&&url.hash.endsWith('/new')&&inventoryRoute()){
   e.preventDefault();requestId++;
   frame?.contentWindow.postMessage(routeMessage(inventoryRoute()),location.origin);return;
  }
  if(url.origin!==location.origin||url.pathname!=='/service/inventory')return;
  const route=url.hash.slice(1)||'overview';if(!inventoryRoutes[route])return;
  e.preventDefault();
  if(a.matches('#nav a[data-section]')){a.focus();a.dispatchEvent(new FocusEvent('focusin',{bubbles:true}));return;}
  location.hash='inventory/'+route;
 },true);
 window.addEventListener('message',e=>{
  if(!frame||e.origin!==location.origin||e.source!==frame.contentWindow)return;
  if(e.data?.type==='deaoe-inventory-current'&&inventoryRoutes[e.data.route]){
   if(inventoryRoute()&&inventoryRoute()!==e.data.route)location.hash='inventory/'+e.data.route;
  }
  if(e.data?.type==='deaoe-service-home')location.hash='dashboard';
 });
 if(ready)render();
})();

// Mobile staff workspace. Business forms and desktop routing remain shared.
(() => {
 const viewport = matchMedia('(max-width:700px)');
 const panel = document.createElement('section');
 panel.id = 'staff-mobile-page';
 panel.hidden = true;
 $('#main').after(panel);
 const tabs = document.createElement('nav');
 tabs.className = 'staff-mobile-tabs';
 tabs.setAttribute('aria-label', '手机工作台导航');
 const items = [['dashboard','首页','⌂'],['applications','应用','▦'],['tasks','待办','▤'],['account','我的','♙']];
 tabs.innerHTML = items.map(([route,label,icon]) => `<a href="#${route}"><span aria-hidden="true">${icon}</span>${label}</a>`).join('');
 document.body.append(tabs);
 const entry = (route, label, note) => `<a class="staff-entry" href="#${route}"><b>${label}</b><small>${note}</small><span aria-hidden="true">›</span></a>`;
 const previous = render;
 function update() {
  const route = location.hash.slice(1) || 'dashboard';
  const special = ['dashboard','applications','tasks','account'].includes(route);
  const mobile = viewport.matches;
  panel.hidden = !(mobile && special);
  document.body.classList.toggle('staff-mobile-workspace', mobile);
  const selected = items.some(([id]) => id === route) ? route : 'applications';
  tabs.querySelectorAll('a').forEach(a => {
   if (a.hash === '#' + selected) a.setAttribute('aria-current','page');
   else a.removeAttribute('aria-current');
  });
  if (!mobile || !special) return;
  $('#main').hidden = true;
  const outstanding = db.orders.filter(o => !['已完工','已关闭'].includes(o.status));
  const waiting = outstanding.filter(o => o.status === '待派工').length;
  if (route === 'applications') {
   panel.innerHTML = '<h1>应用</h1><p class="staff-subtitle">按业务选择功能</p><nav aria-label="全部业务应用">' + ($('.service-mobile-menu nav')?.innerHTML || '') + '</nav>';
  } else if (route === 'dashboard') {
   panel.innerHTML = `<div class="staff-heading"><div><small>${esc(db.settings.name)}</small><h1>工作台</h1></div><button type="button" data-staff-refresh>刷新</button></div><p class="staff-subtitle">销售、采购与库存，随时办理</p><div class="staff-shortcuts">${entry('inventory/sale/new','销售开单','直接新增销售单')}${entry('inventory/sale','销售单','查看销售记录')}${entry('inventory/balances','查库存','仓库数量 · 可用库存')}${entry('inventory/receipt/new','采购入库','选择采购单 · 到货入库')}</div><h2>售后待办</h2><a class="staff-summary" href="#tasks"><span>未完成工单<strong>${outstanding.length}</strong></span><span>等待派工<strong>${waiting}</strong></span><span aria-hidden="true">›</span></a><h2>常用功能</h2><div class="staff-links">${entry('inventory/documents','单据中心','订单查询 · 草稿 · 审核')}${entry('inventory/purchase','采购订单','采购记录与订单管理')}${entry('customers','客户管理','客户与设备保修资料')}${entry('applications','全部应用','商品、盘点、标签与更多功能')}</div>`;
  } else if (route === 'tasks') {
   panel.innerHTML = `<div class="staff-heading"><h1>待办</h1><button type="button" data-staff-refresh>刷新</button></div><p class="staff-subtitle">${outstanding.length} 张未完成售后工单 · 采购销售审核请进入对应单据</p><div class="staff-links">${entry('inventory/sale','销售单审核','进入销售单查看状态')}${entry('inventory/purchase','采购订单审核','进入采购订单查看状态')}</div><h2>售后跟进</h2>` + (outstanding.length ? outstanding.map(o => `<article class="staff-task"><div><b>${esc(o.customer)}</b>${tag(o.status)}</div><p>${esc(o.type)} · ${esc(o.product)}</p><small>${esc(o.id)} · ${esc(o.date)}</small><button type="button" data-detail="${esc(o.id)}">查看并处理 →</button></article>`).join('') : '<div class="staff-empty">暂无未完成售后工单</div>');
  } else {
   panel.innerHTML = `<h1>我的</h1><section class="staff-account"><h2>${esc(db.settings.name)}</h2><p>当前管理账号已登录</p><small>客户会员资料可在客户管理中查看</small></section><div class="staff-links">${entry('settings','基础设置','工作空间与业务配置')}${entry('customers','客户管理','查看客户与电子保修卡')}</div><button class="staff-logout" type="button" data-staff-logout>退出登录</button>`;
  }
 }
 render = function() { previous(); update(); };
 // The existing application registry is populated by a main-content observer.
 new MutationObserver(() => {
  if (viewport.matches && location.hash === '#applications') update();
 }).observe($('.service-mobile-menu nav'), {childList:true});
 viewport.addEventListener('change', () => { if (ready) render(); });
 panel.addEventListener('click', async e => {
  if(e.target.closest('a[href="#orders/new"]')){e.preventDefault();if(ready&&!busy)newForm('new-order');return;}
  if (e.target.closest('[data-staff-logout]')) $('#logout-form')?.requestSubmit();
  const refresh = e.target.closest('[data-staff-refresh]');
  if (refresh) {
   refresh.disabled = true;
   try { await loadData(); } catch (err) { toast(err.message); }
   finally { refresh.disabled = false; }
  }
 });
 if (ready) render();
})();

// Keep mobile document editing inside one full-screen workspace.
window.addEventListener('message', event => {
  const frame = document.querySelector('#inventory-workspace-frame');
  if (event.origin !== location.origin || event.source !== frame?.contentWindow) return;
  if (event.data?.type === 'deaoe-mobile-document') {
    document.body.classList.toggle('mobile-document-open', !!event.data.open);
  }
});
window.addEventListener('hashchange', () => document.body.classList.remove('mobile-document-open'));

// Browser back / iOS edge-back returns mobile staff to the home workspace.
const markStaffHistory = () => history.replaceState({...history.state, deaoeStaffEntry:true}, '', location.href);
markStaffHistory();
window.addEventListener('hashchange', markStaffHistory);
window.addEventListener('popstate', event => {
 if (!event.state?.deaoeStaffEntry || !matchMedia('(max-width:700px)').matches) return;
 history.replaceState({deaoeStaffEntry:true}, '', '#dashboard');
 document.querySelector('#modal')?.close();
 document.body.classList.remove('mobile-document-open');
 query = '';
 if (ready) render();
});


// Customer master data is shared with inventory; service history remains available.
let tradeCustomerData=[],tradeCustomerState='loading',tradeCustomerError='';
function customers(){
 const rows=tradeCustomerData.filter(p=>p.kind==='customer'&&match(p));
 const content=tradeCustomerState==='loading'?'<p class="customer-list-message" role="status">正在加载客户资料…</p>':tradeCustomerState==='error'?`<p class="customer-list-message" role="alert">${esc(tradeCustomerError)}</p>`:
 rows.map(p=>`<article class="trade-customer"><div class="customer-summary"><strong title="${esc((p.phone||'未填电话')+' '+p.name)}">${esc(p.phone||'未填电话')}　${esc(p.name)}</strong><div class="customer-summary-meta">${p.contact?`<span>联系人：${esc(p.contact)}</span>`:''}${!p.active?'<span class="customer-inactive">已停用</span>':''}</div>${p.address?`<small title="${esc(p.address)}">${esc(p.address)}</small>`:''}</div><button type="button" class="customer-view" data-customer-summary="${esc(p.id)}" aria-label="查看 ${esc(p.name)} 客户详情">查看</button></article>`).join('')||'<p class="customer-list-message">暂无匹配客户</p>';
 return `<section class="customer-directory"><div class="customer-directory-head"><h1>客户管理</h1><div class="customer-directory-actions"><a href="#inventory/parties">管理客户</a><button type="button" data-customer-refresh>刷新</button></div></div><div class="customer-directory-search"><input class="search" id="search" aria-label="搜索客户管理" placeholder="搜索姓名、电话或地址" value="${esc(query)}"></div>${tradeCustomerState==='ready'?`<p class="customer-directory-count">共 ${rows.length} 位客户</p>`:''}<div class="trade-customer-grid">${content}</div></section><details class="panel service-customer-history"><summary>售后与保修记录</summary>${serviceCustomers()}</details>`;
}
document.addEventListener('click',event=>{
 const trigger=event.target.closest('[data-customer-summary]');if(!trigger)return;
 const p=tradeCustomerData.find(p=>p.id===trigger.dataset.customerSummary);if(!p)return;
 openModal('客户详情',`<dl class="customer-profile">${[['客户名称',p.name],['联系电话',p.phone],['联系人',p.contact],['地址',p.address],['状态',p.active?'启用':'停用']].map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value||'未填写')}</dd></div>`).join('')}</dl><div class="customer-profile-actions"><a href="#inventory/parties" data-close-customer-profile>管理客户资料</a>${p.phone?`<button type="button" data-customer="${esc(p.phone)}">查看服务记录</button>`:''}</div>`);
});
document.addEventListener('click',event=>{if(event.target.closest('[data-close-customer-profile]'))$('#modal').close();});

(()=>{
 let active=false,sequence=0;
 async function refreshCustomers(){
  const request=++sequence;tradeCustomerState='loading';if(location.hash==='#customers')render();
  try{const response=await fetch('/service/api/inventory',{cache:'no-store'});if(!response.ok)throw Error('客户资料读取失败，请刷新重试');const snapshot=await response.json();if(request!==sequence)return;tradeCustomerData=snapshot.parties||[];tradeCustomerState='ready';}
  catch(error){if(request!==sequence)return;tradeCustomerState='error';tradeCustomerError=error.message;}
  if(location.hash==='#customers')render();
 }
 const previous=render;render=function(){previous();const now=location.hash==='#customers';const entering=now&&!active;active=now;if(entering)refreshCustomers();};
 document.addEventListener('click',e=>{if(e.target.closest('[data-customer-refresh]'))refreshCustomers();});
})();


// Recent workspace routes are navigation shortcuts, not retained form instances.
// Memory-only: no customer names, documents or authentication data are persisted.
(() => {
 const strip=document.createElement('nav');
 strip.className='workspace-route-tabs';
 strip.setAttribute('aria-label','已打开的业务页面');
 document.querySelector('.shell>header').after(strip);
 const routes=['dashboard'];
 function current(){return location.hash.slice(1)||'dashboard';}
 function label(route){
  if(route.startsWith('inventory/')){
   const key=route.slice(10).replace(/\/new$/,'');
   const name=inventoryRoutes[key]?.[1];
   return name ? (route.endsWith('/new')?'新建'+name:name) : null;
  }
  return pages[route]?.[0]||null;
 }
 function paint(){
  const active=current();
  // New-document forms stay under their list tab: a tab never promises to retain a draft.
  const route=active.replace(/\/new$/,'');
  if(label(route)&&!routes.includes(route))routes.push(route);
  if(routes.length>9)routes.splice(1,1);
  strip.innerHTML=routes.map(r=>`<div class="workspace-route-tab ${r===route?'is-current':''}"><a href="#${esc(r)}" ${r===route?'aria-current="page"':''}>${esc(label(r))}</a>${r==='dashboard'?'':`<button type="button" data-close-route="${esc(r)}" aria-label="关闭${esc(label(r))}页面标签">×</button>`}</div>`).join('');
  const selected=strip.querySelector('.is-current');
  if(selected&&(selected.offsetLeft<strip.scrollLeft||selected.offsetLeft+selected.offsetWidth>strip.scrollLeft+strip.clientWidth))strip.scrollLeft=selected.offsetLeft;
 }
 strip.addEventListener('click',event=>{
  const button=event.target.closest('[data-close-route]');if(!button)return;
  const route=button.dataset.closeRoute,index=routes.indexOf(route);
  if(index<1)return;routes.splice(index,1);
  if(current().replace(/\/new$/,'')===route)location.hash=routes[Math.max(0,index-1)];
  else paint();
 });
 window.addEventListener('hashchange',paint);
 const before=render;render=function(){before();paint();};
 paint();
})();

/* Shared business navigation. Only route preferences are stored locally. */
(() => {
 const item=(label,route,icon='document',status='')=>({label,route,icon,status});
 const modules={
  sales:{label:'销售管理',title:'销售导航',icon:'sale',groups:[
   ['销售业务',[item('销售开单','inventory/sale/new'),item('销售单 / 审核','inventory/sale'),item('销售出库','inventory/issue')]],
   ['销售往来',[item('应收款 / 收款','inventory/finance_ar','money'),item('预收款','inventory/finance_advance_receipt','money'),item('退换货与补发','inventory/operations','return')]]],
   lanes:[['销售出货',[item('销售开单','inventory/sale/new'),item('销售单 / 审核','inventory/sale','check'),item('库存查询','inventory/balances','stock')]],['收款与交付',[item('销售出库记录','inventory/issue','stock'),item('应收款 / 收款','inventory/finance_ar','money'),item('电子保修卡','cards','card')]],['退换处理',[item('退换货与补发','inventory/operations','return'),item('库存流水','inventory/ledger','stock'),item('往来账款','inventory/finance','money')]]]},
  purchasing:{label:'采购管理',title:'采购导航',icon:'purchase',groups:[
   ['采购业务',[item('采购开单','inventory/purchase/new'),item('采购订单 / 审核','inventory/purchase'),item('采购入库','inventory/receipt')]],
   ['供应往来',[item('供应商与往来单位','inventory/parties','user'),item('应付款 / 付款','inventory/finance_ap','money'),item('预付款','inventory/finance_advance_payment','money')]]],
   lanes:[['到货入库',[item('采购开单','inventory/purchase/new'),item('采购订单 / 审核','inventory/purchase','check'),item('采购入库','inventory/receipt','stock'),item('库存查询','inventory/balances','stock')]],['供应往来',[item('供应商档案','inventory/parties','user'),item('应付款 / 付款','inventory/finance_ap','money'),item('预付款','inventory/finance_advance_payment','money')]]]},
  stock:{label:'仓库管理',title:'仓库导航',icon:'stock',groups:[
   ['库存业务',[item('库存查询','inventory/balances','stock'),item('采购入库','inventory/receipt'),item('销售出库','inventory/issue'),item('退换货与调拨','inventory/operations','return'),item('库存盘点','inventory/counting','check')]],
   ['库存资料',[item('产品档案','inventory/products','stock'),item('仓库管理','inventory/warehouses','stock'),item('批次与分区','inventory/lots'),item('SN 与标签','inventory/serials'),item('库存流水','inventory/ledger'),item('打印模板','inventory/templates')]]],
   lanes:[['货物流转',[item('采购入库','inventory/receipt','purchase'),item('库存查询','inventory/balances','stock'),item('销售出库','inventory/issue','sale')]],['仓库作业',[item('库存盘点','inventory/counting','check'),item('库存流水','inventory/ledger','stock'),item('退换货与调拨','inventory/operations','return')]],['库存追溯',[item('产品档案','inventory/products','stock'),item('批次与分区','inventory/lots'),item('SN 与标签','inventory/serials'),item('操作日志','inventory/events')]]]},
  accounts:{label:'账款管理',title:'账款导航',icon:'money',groups:[
   ['收款业务',[item('应收款','inventory/finance_ar','money'),item('预收款','inventory/finance_advance_receipt','money')]],
   ['付款业务',[item('应付款','inventory/finance_ap','money'),item('预付款','inventory/finance_advance_payment','money')]],
   ['往来管理',[item('往来余额与流水','inventory/finance','money'),item('往来单位','inventory/parties','user')]]],
   lanes:[['销售收款',[item('销售单','inventory/sale','sale'),item('应收款 / 登记收款','inventory/finance_ar','money'),item('往来余额与流水','inventory/finance','document')]],['采购付款',[item('采购入库','inventory/receipt','purchase'),item('应付款 / 登记付款','inventory/finance_ap','money'),item('往来余额与流水','inventory/finance','document')]],['预收与预付',[item('预收款','inventory/finance_advance_receipt','money'),item('往来账款','inventory/finance','money'),item('预付款','inventory/finance_advance_payment','money')]]]},
  orders:{label:'服务管理',title:'服务导航',icon:'service',groups:[
   ['服务工单',[item('工单受理','orders/new','service'),item('待派工','orders','service','待派工'),item('待处理','orders','service','待处理'),item('处理中','orders','service','处理中'),item('已完工','orders','check','已完工'),item('已关闭','orders','check','已关闭'),item('全部工单','orders','document')]],
   ['客户与设备',[item('客户管理','customers','user'),item('电子保修卡','cards','card'),item('经销商管理','dealers','user')]]],
   lanes:[['工单处理',[item('工单受理','orders/new','service'),item('待派工','orders','service','待派工'),item('待处理','orders','service','待处理'),item('处理中','orders','service','处理中')]],['完工归档',[item('处理中','orders','service','处理中'),item('已完工','orders','check','已完工'),item('已关闭','orders','check','已关闭')]],['服务资料',[item('客户管理','customers','user'),item('电子保修卡','cards','card'),item('全部工单','orders','document')]]]},
  customers:{label:'客户管理',title:'客户导航',icon:'user',groups:[
   ['客户资料',[item('客户管理','customers','user'),item('供应商与往来单位','inventory/parties','user'),item('经销商管理','dealers','user')]],
   ['客户业务',[item('销售开单','inventory/sale/new','sale'),item('电子保修卡','cards','card'),item('全部服务工单','orders','service')]]],
   lanes:[['客户业务',[item('客户管理','customers','user'),item('销售开单','inventory/sale/new','sale'),item('应收款 / 收款','inventory/finance_ar','money')]],['设备服务',[item('电子保修卡','cards','card'),item('工单受理','orders/new','service'),item('全部服务工单','orders','document')]]]},
  settings:{label:'基础数据',title:'基础导航',icon:'settings',groups:[
   ['业务资料',[item('产品档案','inventory/products','stock'),item('仓库管理','inventory/warehouses','stock'),item('往来单位','inventory/parties','user')]],
   ['系统维护',[item('基础设置','settings','settings'),item('打印模板','inventory/templates','document'),item('操作日志','inventory/events','document')]]],
   lanes:[['基础建档',[item('产品档案','inventory/products','stock'),item('仓库管理','inventory/warehouses','stock'),item('往来单位','inventory/parties','user')]],['业务应用',[item('采购订单','inventory/purchase','purchase'),item('销售单','inventory/sale','sale'),item('库存查询','inventory/balances','stock')]],['系统维护',[item('基础设置','settings','settings'),item('打印模板','inventory/templates','document'),item('操作日志','inventory/events','document')]]]}
 };
 const paths={document:'M6 3h12v18H6zM9 8h6M9 12h6M9 16h4',sale:'M4 5h11v14H4zM10 12h11m-4-4 4 4-4 4',purchase:'M9 5h11v14H9M3 12h12m-4-4 4 4-4 4',stock:'m3 7 9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',money:'M3 5h18v14H3zM7 9h1m8 6h1M14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0',user:'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M4 21v-3a8 8 0 0 1 16 0v3',service:'m4 20 7-7M13 3a6 6 0 0 0-2 10 6 6 0 0 0 10-2l-5 2-3-3 2-5z',settings:'M12 3v3m0 12v3M3 12h3m12 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0',card:'M3 5h18v14H3zM3 10h18M7 15h4',check:'M4 12l5 5L20 6',return:'M8 5 3 10l5 5M3 10h12a6 6 0 0 1 0 12'};
 const icon=name=>`<span class="workflow-icon icon-${esc(name)}" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${paths[name]||paths.document}"/></svg></span>`;
 function action(x,cls=''){
  const inner=icon(x.icon)+`<span>${esc(x.label)}</span>`;
  return x.status?`<button type="button" class="${cls}" data-go="orders" data-filter="${esc(x.status)}">${inner}</button>`:`<a class="${cls}" href="#${esc(x.route)}">${inner}</a>`;
 }
 Object.entries(modules).forEach(([key,m])=>{pages['workflow-'+key]=[m.title,m.icon];});
 window.deaoeWorkflowModules=modules;
 window.renderWorkflow=route=>{
  const key=route.replace('workflow-',''),m=modules[key];if(!m)return '';
  const related=m.groups.flatMap(g=>g[1]).filter(x=>!x.status);
  return `<section class="workflow-page" aria-label="${m.title}"><nav class="workflow-switcher" aria-label="业务流程导航">${Object.entries(modules).map(([k,v])=>`<a href="#workflow-${k}" ${key===k?'aria-current="page"':''}>${icon(v.icon)}${v.label}</a>`).join('')}</nav><div class="workflow-canvas"><header class="workflow-canvas-head"><h1>${m.title}</h1><span>点击节点进入业务</span></header><div class="workflow-lanes">${m.lanes.map(([label,nodes])=>`<section class="workflow-lane"><h2>${label}</h2><div class="workflow-path">${nodes.map((n,i)=>(i?'<span class="workflow-arrow" aria-hidden="true">→</span>':'')+action(n,'workflow-node')).join('')}</div></section>`).join('')}</div></div><section class="workflow-related"><h2>相关记录与资料</h2>${related.map(x=>action(x,'workflow-related-link')).join('')}</section></section>`;
 };
 const menu=document.createElement('section');menu.id='workflow-menu';menu.hidden=true;menu.setAttribute('aria-label','业务功能面板');
 const shade=document.createElement('div');shade.id='workflow-shade';shade.hidden=true;shade.setAttribute('aria-hidden','true');document.body.append(shade,menu);
 let trigger=null,active='',timer=0;
 let favorites=new Set(['sales','purchasing','stock','orders']);
 try{const saved=JSON.parse(localStorage.getItem('deaoe-workflow-favorites'));if(Array.isArray(saved))favorites=new Set(saved.filter(k=>Object.hasOwn(modules,k)));}catch{}
 function close(){clearTimeout(timer);menu.hidden=true;shade.hidden=true;trigger?.setAttribute('aria-expanded','false');active='';}
 function position(){if(menu.hidden||!trigger)return;const r=trigger.getBoundingClientRect(),mobile=innerWidth<=700;menu.style.top=(mobile?Math.max(0,r.bottom):document.querySelector('aside').getBoundingClientRect().bottom)+'px';menu.style.left=mobile?'12px':Math.max(12,Math.min(r.left,innerWidth-menu.offsetWidth-12))+'px';menu.style.maxHeight=Math.max(160,innerHeight-parseFloat(menu.style.top)-12)+'px';}
 function moduleTile(key){const m=modules[key];return `<div class="workflow-app">${action(item(m.label,'workflow-'+key,m.icon),'workflow-app-link')}<button type="button" data-workflow-favorite="${key}" aria-label="${favorites.has(key)?'取消收藏':'收藏'}${m.label}" aria-pressed="${favorites.has(key)}">${favorites.has(key)?'★':'☆'}</button></div>`;}
 function paint(){
  menu.classList.toggle('workflow-menu-all',active==='all');
  if(active==='all')menu.innerHTML=`<div class="workflow-menu-heading"><h2>全部应用</h2><button type="button" data-workflow-close aria-label="关闭业务面板">×</button></div><section class="workflow-app-section"><h3>业务管理</h3><div class="workflow-app-grid">${Object.keys(modules).filter(k=>k!=='settings').map(moduleTile).join('')}</div></section><section class="workflow-app-section"><h3>系统维护</h3><div class="workflow-app-grid">${moduleTile('settings')}</div></section><section class="workflow-app-section"><h3>我的常用</h3><div class="workflow-app-grid">${[...favorites].map(k=>action(item(modules[k].label,'workflow-'+k,modules[k].icon),'workflow-app-link')).join('')||'<p class="workflow-empty">点击星标添加常用模块</p>'}</div></section>`;
  else {const m=modules[active];menu.innerHTML=`<div class="workflow-menu-heading"><a href="#workflow-${active}">${icon(m.icon)}<strong>${m.title}</strong></a><button type="button" data-workflow-close aria-label="关闭业务面板">×</button></div><div class="workflow-menu-columns">${m.groups.map(([title,items])=>`<section><h3>${title}</h3>${items.map(x=>action(x,'workflow-menu-link')).join('')}</section>`).join('')}</div>`;}
 }
 function open(a){const key=a.dataset.section;if(key!=='all'&&!modules[key])return;clearTimeout(timer);if(trigger!==a)trigger?.setAttribute('aria-expanded','false');trigger=a;active=key;paint();a.setAttribute('aria-expanded','true');a.setAttribute('aria-controls',menu.id);menu.hidden=false;shade.hidden=false;position();}
 const find=target=>target?.closest?.('#nav [data-section]');
 function refresh(){
  const nav=document.querySelector('#nav');if(!nav)return;
  if(!nav.querySelector('[data-section=all]'))nav.insertAdjacentHTML('afterbegin','<button type="button" class="workflow-all-trigger" data-section="all">▦ <span>全部</span></button>');
  nav.querySelectorAll('[data-section]').forEach(a=>{if(a.dataset.section==='all'||modules[a.dataset.section]){a.setAttribute('aria-controls',menu.id);a.setAttribute('aria-expanded','false');}});
  const key=location.hash.replace('#workflow-','');if(modules[key]){nav.querySelectorAll('.active').forEach(a=>a.classList.remove('active'));nav.querySelector(`[data-section="${key}"]`)?.classList.add('active');}
 }
 const previous=render;render=function(){previous();refresh();};refresh();
 document.addEventListener('pointerover',e=>{const a=find(e.target);if(a&&matchMedia('(min-width:701px)').matches)open(a);else if(menu.contains(e.target))clearTimeout(timer);});
 document.addEventListener('pointerout',e=>{if(find(e.target)||menu.contains(e.target)){clearTimeout(timer);timer=setTimeout(()=>{if(!menu.matches(':hover')&&!trigger?.matches(':hover')&&!menu.contains(document.activeElement))close();},240);}});
 document.addEventListener('click',e=>{
  const a=find(e.target);if(a&&(a.dataset.section==='all'||modules[a.dataset.section])){e.preventDefault();e.stopImmediatePropagation();open(a);return;}
  const star=e.target.closest('[data-workflow-favorite]');if(star){const key=star.dataset.workflowFavorite;favorites.has(key)?favorites.delete(key):favorites.add(key);try{localStorage.setItem('deaoe-workflow-favorites',JSON.stringify([...favorites]));}catch{}paint();position();menu.querySelector(`[data-workflow-favorite="${key}"]`)?.focus();return;}
  if(e.target===shade||e.target.closest('[data-workflow-close]')){close();return;}
  if(menu.contains(e.target)&&e.target.closest('a,button[data-go]'))close();
 },true);
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!menu.hidden){e.preventDefault();const a=trigger;close();a?.focus();}else if(e.key==='ArrowDown'&&find(e.target)){e.preventDefault();open(find(e.target));menu.querySelector('a,button')?.focus();}});
 window.addEventListener('resize',position);window.addEventListener('hashchange',close);
})();

// Compact desktop service entry uses the same record-selection pattern as procurement.
(() => {
 const desktop=()=>matchMedia('(min-width:701px)').matches;
 const priorRender=render,priorNewForm=newForm;
 let pickers=new Set(),serviceSelected='';
 function closePickers(){for(const d of [...pickers])d.close();}
 function serviceList(){const rows=db.orders.filter(match).filter(o=>filter==='全部'||o.status===filter);return `<section class="service-register"><div class="service-register-head"><h1>服务工单</h1><div>${btn('导出工单','export')}${btn('服务开单','new-order',true)}</div></div><div class="service-register-filter">${tool(['全部','待派工','待处理','处理中','已完工','已关闭'],'搜索单号、客户、电话、产品或机身编号')}<span>共 ${rows.length} 张工单</span></div>${table(['工单编号 / 预约日期','服务类型','客户 / 电话','产品 / 机身编号','服务归属','状态','服务人员','操作'],orderRows(rows).map((html,i)=>html.replace('<tr>',`<tr tabindex="0" data-service-row="${esc(rows[i].id)}">`)))}<section class="service-register-summary" aria-label="工单摘要"></section></section>`;}
 render=function(){priorRender();const on=desktop()&&location.hash==='#orders';$('#main').classList.toggle('service-workspace',on);if(on){$('#main').innerHTML=serviceList();const rows=[...document.querySelectorAll('[data-service-row]')];if(!rows.some(r=>r.dataset.serviceRow===serviceSelected))serviceSelected=rows[0]?.dataset.serviceRow||'';rows.forEach(row=>{const choose=()=>{serviceSelected=row.dataset.serviceRow;summary();};row.onclick=e=>{if(!e.target.closest('button,a'))choose();};row.onkeydown=e=>{if((e.key==='Enter'||e.key===' ')&&e.target===row){e.preventDefault();choose();}};});summary();}};
 function summary(){const root=$('.service-register-summary'),o=db.orders.find(x=>x.id===serviceSelected);if(!root)return;document.querySelectorAll('[data-service-row]').forEach(row=>row.setAttribute('aria-selected',String(row.dataset.serviceRow===serviceSelected)));root.innerHTML=o?`<h2>工单摘要 <span>${esc(o.id)}</span></h2><div><p><b>服务地址</b> ${esc(o.address)}</p><p><b>需求描述</b> ${esc(o.note)}</p></div><details><summary>处理记录 · ${(o.history||[]).length} 条</summary>${(o.history||[]).map(h=>`<p>${esc(h)}</p>`).join('')}</details>`:'<p>暂无工单记录</p>';}
 newForm=function(action){if(action!=='new-order'||!desktop())return priorNewForm(action);
  form('new-order',`<div class="service-form-section wide">客户与服务信息</div>${select('服务类型','type',['安装','维修'])}${select('服务归属','dealer',['总部直营',...db.dealers.map(d=>d.name)])}${field('预约日期','date','date',localDate())}<label class="service-input-choice">客户姓名<div><input name="customer" required><button type="button" data-service-pick="customer">选择客户</button></div></label>${field('联系电话','phone','tel','','pattern="[0-9]{11}" maxlength="11"')}<label class="wide">服务地址<input name="address" required></label><div class="service-form-section wide">产品与服务需求</div><label class="service-input-choice wide">产品 / 型号<div><input name="product" required placeholder="选择产品，或填写其他待服务产品"><button type="button" data-service-pick="product">选择产品</button></div></label>${field('机身 / 临时设备编号','sn')}<label class="wide">需求描述<textarea name="note" rows="2" required placeholder="填写安装要求或故障现象"></textarea></label>`,'保存工单');
  $('#modal').classList.add('desktop-service-form');$('#modal h2').textContent='服务开单';
 };
 function picker(kind,form){const d=document.createElement('dialog');d.className='service-record-picker';d.setAttribute('aria-label',kind==='customer'?'选择客户':'选择产品');d.innerHTML=`<header><h2>${kind==='customer'?'选择客户':'选择产品'}</h2><button type="button" data-picker-close>关闭</button></header><div class="service-picker-search"><input type="search" aria-label="搜索记录" placeholder="${kind==='customer'?'搜索姓名、联系人、电话、地址':'搜索编号、名称、型号、品牌'}"></div><div class="service-picker-records"><p role="status">正在加载…</p></div><footer><span>选中后确认，或双击选择</span><button type="button" class="primary" data-picker-confirm disabled>确定选择</button></footer>`;document.body.append(d);pickers.add(d);d.addEventListener('close',()=>{pickers.delete(d);d.remove();});d.querySelector('[data-picker-close]').onclick=()=>d.close();d.showModal();
  let rows=[],chosen=null;const cells=r=>kind==='customer'?[r.phone||'—',r.name,r.contact||'—',r.address||'—']:[r.code,r.name,r.model||'—',r.brand||'—'];
  const commit=()=>{if(!chosen||!form.isConnected||!$('#modal').open)return;const r=chosen;if(kind==='customer'){form.elements.customer.value=r.name;form.elements.phone.value=r.phone||'';form.elements.address.value=r.address||'';}else{form.elements.product.value=[r.name,r.model].filter(Boolean).join(' ');}d.close();};
  function draw(){const term=d.querySelector('input').value.trim().toLowerCase(),list=rows.filter(r=>cells(r).join(' ').toLowerCase().includes(term));if(chosen&&!list.includes(chosen))chosen=null;d.querySelector('[data-picker-confirm]').disabled=!chosen;d.querySelector('.service-picker-records').innerHTML=table(kind==='customer'?['联系电话','客户名称','联系人','地址']:['产品编号','产品名称','规格型号','品牌'],list.map((r,i)=>`<tr tabindex="0" data-choice="${i}" aria-selected="${r===chosen}">${cells(r).map(c=>`<td>${esc(c)}</td>`).join('')}</tr>`));d.querySelectorAll('[data-choice]').forEach(el=>{const choose=()=>{chosen=list[Number(el.dataset.choice)];d.querySelectorAll('[data-choice]').forEach(x=>x.setAttribute('aria-selected',String(x===el)));d.querySelector('[data-picker-confirm]').disabled=false;};el.onclick=choose;el.ondblclick=()=>{choose();commit();};el.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();choose();commit();}else if(e.key===' '){e.preventDefault();choose();}};});}
  d.querySelector('input').oninput=draw;d.querySelector('[data-picker-confirm]').onclick=commit;
  fetch('/service/api/inventory',{cache:'no-store'}).then(async response=>{if(!response.ok)throw Error('读取失败，请关闭窗口后重试');const data=await response.json();if(!d.isConnected)return;rows=kind==='customer'?(data.parties||[]).filter(r=>r.active&&r.kind==='customer'):(data.products||[]).filter(r=>r.active);draw();}).catch(error=>{if(d.isConnected)d.querySelector('.service-picker-records').textContent=error.message;});
 }
 document.addEventListener('click',e=>{const b=e.target.closest('[data-service-pick]');if(b)picker(b.dataset.servicePick,b.closest('form'));});
 $('#modal').addEventListener('close',()=>{closePickers();$('#modal').classList.remove('desktop-service-form');});
 window.addEventListener('hashchange',closePickers);
 matchMedia('(min-width:701px)').addEventListener('change',()=>{closePickers();if(ready)render();});
})();
