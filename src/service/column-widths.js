/* Shared desktop table sizing. Preferences are scoped by authenticated server identity. */
(()=>{
 const mq=matchMedia('(min-width:701px)');let prefs={},csrf='',loaded=false,scheduled=false;
 let saveQueue=Promise.resolve();
 const endpoint='/service/api/column-widths';
 const style=document.createElement('style');style.textContent=`@media(min-width:701px){table.column-sized{table-layout:fixed!important;max-width:none!important}table.column-sized th{position:relative;overflow:visible}table.column-sized td{overflow:hidden;text-overflow:ellipsis} .column-grip{position:absolute!important;right:-4px;top:0;width:9px!important;height:100%;padding:0!important;margin:0!important;cursor:col-resize;border:0!important;background:transparent!important;touch-action:none;z-index:3}.column-grip:hover,.column-grip:focus{background:#c8403966!important}.column-grip:focus-visible{outline:2px solid #c84039}}@media(min-width:701px){.column-hidden{display:none!important}.column-controls{position:sticky;z-index:10;width:max-content;background:#fff;display:flex;align-items:center;gap:12px;padding:6px 0;min-height:36px;left:0}.column-menu{position:relative}.column-menu summary{cursor:pointer;list-style:none;border:1px solid #d6dee8;border-radius:4px;background:#fff;padding:6px 12px;color:#334155;font-size:13px}.column-options{position:fixed;z-index:30;min-width:190px;max-height:320px;overflow:auto;padding:10px;background:#fff;border:1px solid #d6dee8;box-shadow:0 6px 18px #0002}.column-options label{display:flex!important;align-items:center;gap:8px;padding:5px 2px;white-space:nowrap;font-size:13px}.column-options input{width:16px!important;height:16px!important;margin:0!important}.column-options button{margin:6px 4px 0 0;font-size:12px}.column-save-status{font-size:12px;color:#64748b}}@media print{.column-grip,.column-controls{display:none!important}}`;document.head.append(style);
 function persist(key,values){
  prefs[key]=values.slice();
  const request=saveQueue.catch(()=>{}).then(async()=>{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({key,widths:values})});
   if(!r.ok)throw Error('保存失败');
  });saveQueue=request;return request;
 }
 async function save(key,widths,grip){try{await persist(key,widths);grip.title='列宽已保存；双击恢复默认';}catch{grip.title='保存失败，请重新拖动重试';alert('列宽未保存，请检查连接后重新拖动。');}}
 function enhance(){scheduled=false;if(!loaded||!mq.matches)return;
 const counts={};document.querySelectorAll('table').forEach(t=>{
 const heads=[...(t.tHead?.rows[0]?.cells||t.querySelector('tr')?.cells||[])];if(!heads.length||!heads.every(h=>h.tagName==='TH'&&h.colSpan===1))return;
 const signature=JSON.stringify([location.pathname,location.hash.split('?')[0],heads.map(h=>h.textContent.trim())]);const n=counts[signature]||0;counts[signature]=n+1;const key=signature+':'+n;if(key.length>1989)return;if(t.dataset.columnReady){t._columnApply?.();return;}t.dataset.columnReady='1';
 const initial=heads.map(h=>Math.max(40,Math.min(1600,Math.round(h.getBoundingClientRect().width))));if(!t.getBoundingClientRect().width){delete t.dataset.columnReady;return;}
 let widths=prefs[key]?.length===heads.length?prefs[key].slice():initial.slice();
 const visibilityKey='visibility:'+key;
 let hidden=Array.isArray(prefs[visibilityKey])&&prefs[visibilityKey].length===heads.length&&prefs[visibilityKey].every(v=>typeof v==='boolean')?prefs[visibilityKey].slice():heads.map(()=>false);
 if(hidden.every(Boolean))hidden.fill(false);
 const cols=document.createElement('colgroup');heads.forEach(()=>cols.append(document.createElement('col')));t.prepend(cols);t.classList.add('column-sized');
 function apply(){
 t.style.width=widths.reduce((sum,w,i)=>sum+(hidden[i]?0:w),0)+'px';
 [...cols.children].forEach((c,i)=>{c.style.width=widths[i]+'px';c.style.display=hidden[i]?'none':'';});
 heads.forEach((h,i)=>{h.style.width=widths[i]+'px';h.style.minWidth='0';});
 for(const row of t.rows){let index=0;for(const cell of row.cells){
  const span=Number(cell.dataset.columnSpan||cell.colSpan);cell.dataset.columnSpan=span;
  const visible=hidden.slice(index,index+span).filter(v=>!v).length;
  cell.classList.toggle('column-hidden',visible===0);if(span>1)cell.colSpan=Math.max(1,visible);index+=span;
 }}
 }
 const toolbar=document.createElement('div');toolbar.className='column-controls';
 const menu=document.createElement('details');menu.className='column-menu';
 const summary=document.createElement('summary');summary.textContent='显示列 ▾';menu.append(summary);
 const panel=document.createElement('div');panel.className='column-options';menu.append(panel);
 const notice=document.createElement('span');notice.className='column-save-status';notice.setAttribute('role','status');
 const checks=[];
 const storeVisibility=async()=>{notice.textContent='保存中…';try{await persist(visibilityKey,hidden.slice());notice.textContent='已保存';}catch{notice.textContent='未保存，请重试';}};
 heads.forEach((h,i)=>{const label=document.createElement('label'),check=document.createElement('input');
 check.type='checkbox';check.checked=!hidden[i];checks.push(check);
 label.append(check,document.createTextNode(h.textContent.trim()||'选择'));panel.append(label);
 check.addEventListener('change',()=>{
  if(!check.checked&&hidden.filter(v=>!v).length===1){check.checked=true;notice.textContent='至少保留一列';return;}
  hidden[i]=!check.checked;apply();storeVisibility();
 });});
 const reset=document.createElement('button');reset.type='button';reset.textContent='恢复默认';panel.append(reset);
 reset.addEventListener('click',()=>{hidden.fill(false);checks.forEach(c=>c.checked=true);apply();storeVisibility();});
 const retry=document.createElement('button');retry.type='button';retry.textContent='重新保存';panel.append(retry);retry.addEventListener('click',storeVisibility);
 menu.addEventListener('toggle',()=>{if(menu.open){const r=summary.getBoundingClientRect();panel.style.left=Math.max(8,Math.min(r.left,innerWidth-240))+'px';panel.style.top=r.bottom+'px';panel.style.maxHeight=Math.max(100,innerHeight-r.bottom-16)+'px';}});
 menu.addEventListener('keydown',e=>{if(e.key==='Escape'){menu.open=false;summary.focus();}});
 toolbar.append(menu,notice);t.before(toolbar);t._columnControls=toolbar;t._columnApply=apply;

 apply();heads.forEach((h,i)=>{const g=document.createElement('span');g.className='column-grip';g.tabIndex=0;g.setAttribute('role','separator');g.setAttribute('aria-orientation','vertical');g.setAttribute('aria-label','调整'+(h.textContent.trim()||'选择')+'列宽');g.title='拖动调整，方向键微调；双击恢复默认';h.append(g);
 const change=w=>{widths[i]=Math.max(40,Math.min(1600,Math.round(w)));apply();g.setAttribute('aria-valuenow',widths[i]);};
 g.addEventListener('pointerdown',e=>{e.preventDefault();e.stopPropagation();const x=e.clientX,w=widths[i];g.setPointerCapture(e.pointerId);const move=e=>change(w+e.clientX-x);const end=()=>{g.removeEventListener('pointermove',move);g.removeEventListener('pointerup',end);g.removeEventListener('pointercancel',end);save(key,widths.slice(),g);};g.addEventListener('pointermove',move);g.addEventListener('pointerup',end);g.addEventListener('pointercancel',end);});
 g.addEventListener('click',e=>e.stopPropagation());g.addEventListener('dblclick',e=>{e.stopPropagation();widths=initial.slice();apply();save(key,widths.slice(),g);});g.addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();change(widths[i]+(e.key==='ArrowLeft'?-10:10));save(key,widths.slice(),g);}});
 });
 });}
 document.addEventListener('click',e=>{document.querySelectorAll('.column-menu[open]').forEach(m=>{if(!m.contains(e.target))m.open=false;});});
 addEventListener('resize',()=>document.querySelectorAll('.column-menu[open]').forEach(m=>m.open=false));
 const scan=()=>{if(!scheduled){scheduled=true;requestAnimationFrame(enhance);}};
 new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});addEventListener('hashchange',scan);mq.addEventListener('change',()=>{if(!mq.matches){document.querySelectorAll('table.column-sized').forEach(t=>{t.classList.remove('column-sized');t._columnControls?.remove();t.querySelectorAll('[data-column-span]').forEach(c=>{c.colSpan=Number(c.dataset.columnSpan);delete c.dataset.columnSpan;c.classList.remove('column-hidden');});t.style.width='';t.querySelector('colgroup')?.remove();t.querySelectorAll('.column-grip').forEach(g=>g.remove());t.querySelectorAll('th').forEach(h=>{h.style.width='';h.style.minWidth='';});delete t.dataset.columnReady;});}scan();});
 fetch(endpoint,{cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(v=>{prefs=v.views;csrf=v.csrf;loaded=true;scan();}).catch(()=>console.warn('Column preferences unavailable'));
})();
