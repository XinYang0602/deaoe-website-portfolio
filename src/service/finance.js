/* Four independent balances; all amounts remain integer cents on the server. */
names.finance = '账款管理';
(() => {
  const labels = {sale:'销售应收', history:'历史应收核对', receipt:'客户付款', refund:'退款给客户', return:'销售退货冲减',purchase:'采购入库应付',purchase_history:'历史应付核对',purchase_return:'采购退货冲减',payment:'支付供应商',supplier_refund:'收到供应商退款',advance_receipt:'登记预收款',advance_payment:'登记预付款',return_advance_receipt:'退还客户预收款',return_advance_payment:'收回供应商预付款',offset_receipt:'预收抵应收',offset_payment:'预付抵应付'};
  const buckets = {ar:'应收款',advance_receipt:'预收款',ap:'应付款',advance_payment:'预付款'};
  Object.entries(buckets).forEach(([key,label])=>names['finance_'+key]=label);
  const accountTypes={wechat:'微信',alipay:'支付宝',bank:'银行账户',cash:'现金',other:'其他'};
  const accountLabel=a=>a.name+(a.identifier?' · '+a.identifier:'')+(a.holder?' · '+a.holder:'');
  function accountsPanel(){
    show(`<h2>收付款账户设置</h2><p class="muted">公司共用，保存后其他管理端刷新即可选择。修改或停用不会改写历史流水。</p>${button('新增账户','fin-account-new','',true)}${(data.finance.accounts||[]).map(a=>`<article class="finance-card"><h3>${E(a.name)} · ${E(accountTypes[a.type])}</h3><p>${E(a.identifier||'现金')} ${E(a.holder)}</p><p>${a.active?'启用中':'已停用'}</p>${button('编辑','fin-account-edit',a.id)}</article>`).join('')||'<p>尚未设置账户，请先新增。</p>'}${button('关闭','close')}`);
  }
  function accountForm(id){
    const a=data.finance.accounts.find(a=>a.id===id)||{name:'',type:'wechat',identifier:'',holder:'',active:1};
    show(`<h2>${id?'编辑':'新增'}收付款账户</h2><form id="finance-account-form"><div class="form-grid">${input('名称（如微信·张会计、公司基本户）','name',a.name,'text','required maxlength="30"')}${select('类型','type',Object.entries(accountTypes).map(([id,name])=>({id,name})),a.type)}${input('账号／微信号（现金可留空）','identifier',a.identifier,'text','maxlength="40"')}${input('户名／保管人','holder',a.holder,'text','maxlength="20"')}${select('状态','active',[{id:1,name:'启用'},{id:0,name:'停用'}],a.active)}</div>${foot('保存账户')}</form>`);
    const form=$('#finance-account-form'),request=key();
    form.elements.type.required=true;form.elements.active.required=true;
    form.addEventListener('submit',async event=>{
      event.preventDefault();event.stopImmediatePropagation();const submit=form.querySelector('[type="submit"]');if(submit.disabled)return;submit.disabled=true;
      try{const values=Object.fromEntries(new FormData(form));await call({action:'finance',operation:'save_account',...values,active:Number(values.active),id:id||'',version:a.version,request_key:request});await load();accountsPanel();}
      catch(error){const el=form.querySelector('.form-error');el.hidden=false;el.textContent=error.message;}
      finally{submit.disabled=false;}
    });
  }
  const previous = render;
  let tab='ar',lastFinanceRoute='';
  const amount = (id,bucket) => Number((bucket==='ar' ? data.finance.balances.find(b=>b.party_id===id) : data.finance.aux_balances.find(b=>b.party_id===id&&b.bucket===bucket))?.cents || 0);
  const pending = (id,customer) => (customer?data.finance.sales:data.finance.purchases).filter(f=>f.state==='pending'&&(f.party_id||data.docs.find(d=>d.id===f.sale_id)?.party_id)===id);
  const matches = text => !search || String(text).toLowerCase().includes(search.toLowerCase());
  const section = (title,rows) => `<h2>${title}</h2><div class="finance-grid">${rows.filter(Boolean).join('')||'<p class="muted">暂无记录</p>'}</div>`;
  render = function () {
    previous();
    const isFinance=page==='finance'||Object.keys(buckets).some(k=>page==='finance_'+k);
    document.querySelector('#main section.panel')?.classList.toggle('finance-page',isFinance);
    if (!isFinance || !data?.finance) return;
    if(page!==lastFinanceRoute){tab=buckets[page.slice(8)]?page.slice(8):'ar';lastFinanceRoute=page;}
    const f=data.finance;
    if (!f.aux_balances) return;
    const exportButton=document.querySelector('[data-action="export"]'); if(exportButton) exportButton.remove();
    const customer=tab==='ar'||tab==='advance_receipt', advance=tab.startsWith('advance_');
    const totals=Object.entries(buckets).map(([k,label])=>{
      const rows=k==='ar'?f.balances:f.aux_balances.filter(b=>b.bucket===k);
      const total=rows.reduce((n,b)=>n+Math.max(0,Number(b.cents)),0);
      const refunds=rows.reduce((n,b)=>n+Math.max(0,-Number(b.cents)),0);
      return `<button type="button" class="finance-summary ${tab===k?'active':''}" data-action="fin-tab" data-id="${k}" aria-pressed="${tab===k}"><span>${label}</span><strong>¥${money(total)}</strong>${refunds?`<small>${k==='ar'?'另需退客户':'另待供应商退款'} ¥${money(refunds)}</small>`:''}</button>`;
    }).join('');
    const parties=data.parties.filter(p=>p.kind===(customer?'customer':'supplier')&&(p.active||amount(p.id,tab)||pending(p.id,customer).length));
    const cards=parties.filter(p=>matches(p.name+p.phone)).map(p=>{
      const n=amount(p.id,tab),count=pending(p.id,customer).length;
      let actions='';
      if(advance){
        if(p.active)actions+=button(customer?'登记预收款':'登记预付款','fin-'+tab,p.id,true);
        if(n>0){
          actions+=button(customer?'退还预收款':'收回预付款','fin-return_'+tab,p.id);
          if(!count&&amount(p.id,customer?'ar':'ap')>0)actions+=button(customer?'抵扣应收':'抵扣应付','fin-'+(customer?'offset_receipt':'offset_payment'),p.id);
        }
      }else if(!count){
        if(n>0)actions+=button(customer?'登记收款':'登记付款','fin-'+(customer?'receipt':'payment'),p.id,true);
        if(n<0)actions+=button(customer?'退给客户':'收到供应商退款','fin-'+(customer?'refund':'supplier_refund'),p.id,true);
      }
      return `<article class="finance-card"><h3>${E(p.name)}</h3><strong>${n<0?(customer?'应退客户':'待供应商退款'):buckets[tab]} ¥${money(Math.abs(n))}</strong>${count?`<p class="muted">${count} 张历史单待核对</p>`:''}<div class="finance-actions">${actions}</div></article>`;
    });
    const history=(customer?f.sales:f.purchases).filter(r=>r.state==='pending').map(r=>{
      const d=data.docs.find(d=>d.id===(r.sale_id||r.doc_id));if(!d||!matches(d.id+party(d.party_id)))return '';
      return `<article class="finance-card"><h3>${E(party(d.party_id))}</h3><p>${E(d.id)} · ${E(d.date)}</p><p>缺价旧单请凭原始票据核实。核对退货、已收付款后，仅录入仍${customer?'未收':'未付'}金额，已结清填 0。</p>${button('核对历史'+(customer?'应收':'应付'),customer?'fin-history':'fin-purchase-history',d.id)}</article>`;
    });
    const replacements=customer&&!advance?(data.ops||[]).filter(o=>o.kind==='sales_return'&&o.state==='approved').map(o=>{
      const b=JSON.parse(o.body);if(b.finance_mode!=='replacement')return '';
      const sent=(data.ops||[]).filter(x=>x.kind==='sales_exchange'&&x.state==='approved').reduce((n,x)=>{const v=JSON.parse(x.body);return n+(v.return_id===o.id?Number(v.qty):0);},0);
      const left=Number(b.qty)-sent;if(left<=0||!matches(party(b.party_id)+o.id+product(b.product_id).name))return '';
      return `<article class="finance-card"><h3>${E(party(b.party_id))}</h3><p>${E(product(b.product_id).name)} · 待补发 ${left} ${E(product(b.product_id).unit)}</p><p class="muted">${E(o.id)} · 不变动账款</p>${button('办理补发','fin-replace',o.id)}</article>`;
    }):[];
    const entries=(tab==='ar'?f.entries:f.aux_entries.filter(e=>e.bucket===tab)).filter(e=>matches(party(e.party_id)+e.source+e.note+e.reference)).map(e=>{
      let ref=e.reference||f.links.find(l=>l.source===e.source)?.doc_id||'';
      if(!ref&&['sale','history'].includes(e.kind))ref=e.source.slice(e.source.indexOf(':')+1);
      if(!ref&&e.kind==='return'){
        const op=data.ops.find(o=>e.source==='return:'+o.id), body=op?JSON.parse(op.body):{};
        const line=data.lines.find(l=>l.id===body.source_line);
        ref=data.docs.find(d=>d.id===line?.doc_id)?.source_id||'';
      }
      return `<article class="finance-card"><h3>${E(party(e.party_id))} · ${E(labels[e.kind]||e.kind)}</h3><strong>${Number(e.cents)>=0?'+':'−'}¥${money(Math.abs(Number(e.cents)))}</strong><p>${E(e.created_at)} · ${E(e.account||'非现金记账')}</p><p>${E(e.note)}</p><small>${ref?'关联单据：'+E(ref):'往来汇总记录，未指定单据'}<br>${E(e.source)}</small></article>`;
    });
    $('#main section.panel').innerHTML=head('往来余额与收付款',button('收付款账户设置','fin-accounts'))+`<details class="finance-help"><summary>记账说明</summary><p>销售审核记应收，采购入库审核记应付。预收、预付单独管理，抵扣时选择单据；历史付款不会自动分摊到单据。</p></details>`+`<div class="finance-summary-grid">${totals}</div>`+section(buckets[tab]+' · '+(customer?'客户':'供应商'),cards)+(history.some(Boolean)?section('历史'+(customer?'销售应收':'采购应付')+'待核对',history):'')+(replacements.length?section('退货待补发',replacements):'')+`<details class="finance-details"><summary>${buckets[tab]}流水 · ${entries.length} 条</summary>${section('＋增加余额，−减少余额',entries)}</details>`;
  };
  document.addEventListener('click',e=>{
    const b=e.target.closest('[data-action^="fin-"]');if(!b)return;
    e.preventDefault();e.stopImmediatePropagation();
    const action=b.dataset.action,id=b.dataset.id;
    if(action==='fin-accounts'){accountsPanel();return;}
    if(action==='fin-account-new'){accountForm('');return;}
    if(action==='fin-account-edit'){accountForm(id);return;}
    if(action==='fin-open-payables'){$('#modal').close();location.hash='finance_ap';return;}
    if(action==='fin-tab'){if(location.hash==='#finance_'+id)render();else location.hash='finance_'+id;return;}
    if(action==='fin-replace'){p2Form('sales_exchange');const field=$('#body [name="return_id"]');if(field)field.value=id;return;}
    const op=action==='fin-history'?'confirm_history':action==='fin-purchase-history'?'confirm_purchase_history':action.slice(4);
    const history=op==='confirm_history'||op==='confirm_purchase_history',offset=op.startsWith('offset_');
    const doc=data.docs.find(d=>d.id===id),partyId=history?doc.party_id:id;
    const customer=data.parties.find(p=>p.id===partyId)?.kind==='customer';
    const advance=['advance_receipt','advance_payment','return_advance_receipt','return_advance_payment'].includes(op);
    const kind=customer?'sale':advance?'purchase':'receipt';
    const docs=data.docs.filter(d=>d.party_id===partyId&&d.kind===kind&&d.status==='approved').map(d=>({id:d.id,name:d.id+' · '+d.date}));
    const title=history?'核对历史'+(customer?'应收':'应付'):labels[op];
    show(`<h2>${E(title)}</h2><p>${E(party(partyId))}</p><form id="finance-form"><div class="form-grid">${input(history?'核实后未收／未付金额（结清填 0）':'金额（元）','amount','','number','required min="'+(history?'0':'0.01')+'" step="0.01"')}${history||offset?'':select('收付款账户','account_id',data.finance.accounts.filter(a=>a.active).map(a=>({id:a.id,name:accountLabel(a)})))}${history?'':select(offset?'抵扣到单据（必选）':advance?'关联订单（可选）':'关联单据（可选，留空按往来汇总结算）','reference',docs)}${input('核对依据／备注','note','','text','required maxlength="1000"')}</div>${offset?'<p class="muted">抵扣只转移账面余额，不代表新增现金收付款。</p>':''}${foot('确认登记')}</form>`);
    const form=$('#finance-form'),request=key();if(offset)form.elements.reference.required=true;
    if(!history&&!offset){form.elements.account_id.required=true;if(!data.finance.accounts.some(a=>a.active))form.insertAdjacentHTML('afterbegin','<p>请先设置一个启用的收付款账户。</p>'+button('去设置账户','fin-accounts'));}
    form.addEventListener('submit',async event=>{
      event.preventDefault();event.stopImmediatePropagation();const submit=form.querySelector('[type="submit"]');if(submit.disabled)return;submit.disabled=true;
      try{const values=Object.fromEntries(new FormData(form));const account=data.finance.accounts.find(a=>a.id===values.account_id);await call({action:'finance',operation:op,sale_id:id,doc_id:id,party_id:partyId,request_key:request,...values,account_version:account?.version});$('#modal').close();await load();}
      catch(error){const el=form.querySelector('.form-error');el.hidden=false;el.textContent=error.message;}
      finally{submit.disabled=false;}
    });
  },true);
})();
