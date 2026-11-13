/* Shared desktop table sizing. Preferences are scoped by authenticated server identity. */
(()=>{
 const mq=matchMedia('(min-width:701px)');let prefs={},csrf='',loaded=false,scheduled=false;
 const endpoint='/service/api/column-widths';
 const style=document.createElement('style');style.textContent=`@media(min-width:701px){table.column-sized{table-layout:fixed!important;max-width:none!important}table.column-sized th{position:relative;overflow:visible}table.column-sized td{overflow:hidden;text-overflow:ellipsis} .column-grip{position:absolute!important;right:-4px;top:0;width:9px!important;height:100%;padding:0!important;margin:0!important;cursor:col-resize;border:0!important;background:transparent!important;touch-action:none;z-index:3}.column-grip:hover,.column-grip:focus{background:#c8403966!important}.column-grip:focus-visible{outline:2px solid #c84039}}@media print{.column-grip{display:none!important}}`;document.head.append(style);
 async function save(key,widths,grip){prefs[key]=widths;try{const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({key,widths})});if(!r.ok)throw Error();grip.title='列宽已保存；双击恢复默认';}catch{grip.title='保存失败，请重新拖动重试';alert('列宽未保存，请检查连接后重新拖动。');}}
 function enhance(){scheduled=false;if(!loaded||!mq.matches)return;
 const counts={};document.querySelectorAll('table').forEach(t=>{
 const heads=[...(t.tHead?.rows[0]?.cells||t.querySelector('tr')?.cells||[])];if(!heads.length||!heads.every(h=>h.tagName==='TH'&&h.colSpan===1))return;
 const signature=JSON.stringify([location.pathname,location.hash.split('?')[0],heads.map(h=>h.textContent.trim())]);const n=counts[signature]||0;counts[signature]=n+1;const key=signature+':'+n;if(key.length>2000||t.dataset.columnReady)return;t.dataset.columnReady='1';
 const initial=heads.map(h=>Math.max(40,Math.min(1600,Math.round(h.getBoundingClientRect().width))));if(!t.getBoundingClientRect().width){delete t.dataset.columnReady;return;}
 let widths=prefs[key]?.length===heads.length?prefs[key].slice():initial.slice();
 const cols=document.createElement('colgroup');heads.forEach(()=>cols.append(document.createElement('col')));t.prepend(cols);t.classList.add('column-sized');
 function apply(){t.style.width=widths.reduce((a,b)=>a+b,0)+'px';[...cols.children].forEach((c,i)=>c.style.width=widths[i]+'px');heads.forEach((h,i)=>{h.style.width=widths[i]+'px';h.style.minWidth='0';});}
 apply();heads.forEach((h,i)=>{const g=document.createElement('span');g.className='column-grip';g.tabIndex=0;g.setAttribute('role','separator');g.setAttribute('aria-orientation','vertical');g.setAttribute('aria-label','调整'+(h.textContent.trim()||'选择')+'列宽');g.title='拖动调整，方向键微调；双击恢复默认';h.append(g);
 const change=w=>{widths[i]=Math.max(40,Math.min(1600,Math.round(w)));apply();g.setAttribute('aria-valuenow',widths[i]);};
 g.addEventListener('pointerdown',e=>{e.preventDefault();e.stopPropagation();const x=e.clientX,w=widths[i];g.setPointerCapture(e.pointerId);const move=e=>change(w+e.clientX-x);const end=()=>{g.removeEventListener('pointermove',move);g.removeEventListener('pointerup',end);g.removeEventListener('pointercancel',end);save(key,widths.slice(),g);};g.addEventListener('pointermove',move);g.addEventListener('pointerup',end);g.addEventListener('pointercancel',end);});
 g.addEventListener('click',e=>e.stopPropagation());g.addEventListener('dblclick',e=>{e.stopPropagation();widths=initial.slice();apply();save(key,widths.slice(),g);});g.addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();change(widths[i]+(e.key==='ArrowLeft'?-10:10));save(key,widths.slice(),g);}});
 });
 });}
 const scan=()=>{if(!scheduled){scheduled=true;requestAnimationFrame(enhance);}};
 new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});addEventListener('hashchange',scan);mq.addEventListener('change',()=>{if(!mq.matches){document.querySelectorAll('table.column-sized').forEach(t=>{t.classList.remove('column-sized');t.style.width='';t.querySelector('colgroup')?.remove();t.querySelectorAll('.column-grip').forEach(g=>g.remove());t.querySelectorAll('th').forEach(h=>{h.style.width='';h.style.minWidth='';});delete t.dataset.columnReady;});}scan();});
 fetch(endpoint,{cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(v=>{prefs=v.views;csrf=v.csrf;loaded=true;scan();}).catch(()=>console.warn('Column preferences unavailable'));
})();
