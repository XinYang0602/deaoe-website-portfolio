'use strict';
(()=>{
 const choices=[[100,'标准（100%）'],[110,'稍大（110%）'],[125,'大（125%）'],[150,'特大（150%）']];
 let scale=100,csrf='',ready=false,saving=false,loadError=false;
 const style=document.createElement('style');
 style.textContent=':root{--ui-font-scale:1;font-size:calc(16px * var(--ui-font-scale))}.font-preferences-controls [hidden]{display:none!important}.font-preferences-controls{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.font-preferences-controls label{min-width:180px}.font-preferences-sample{line-height:1.7;overflow-wrap:anywhere}.font-preferences-status{min-height:1.5em}@media print{:root{--ui-font-scale:1!important}}';
 document.head.append(style);
 // Convert explicit pixel typography once, preserving the existing hierarchy and relative units.
 function typography(declaration){
  for(const property of ['font-size','line-height','--list-text']){
   const value=declaration.getPropertyValue(property).trim();
   if(/^\d+(?:\.\d+)?px$/.test(value)&&parseFloat(value)>0)declaration.setProperty(property,`calc(${value} * var(--ui-font-scale))`,declaration.getPropertyPriority(property));
  }
 }
 function rules(list){for(const rule of list){if(rule.media?.mediaText.includes('print'))continue;if(rule.style)typography(rule.style);if(rule.cssRules)rules(rule.cssRules);}}
 function sheets(){for(const sheet of document.styleSheets){try{rules(sheet.cssRules);}catch{/* Ignore external stylesheets; this app's styles are same-origin. */}}}
 function inline(root){if(root.nodeType!==1)return;if(root.hasAttribute('style'))typography(root.style);root.querySelectorAll('[style]').forEach(el=>typography(el.style));}
 function panel(){return `<section class="panel font-preferences-panel"><div class="panel-head"><h2>界面字号</h2></div><form id="font-preferences-form" class="settings"><p>调整整个后台的文字大小，仅对当前登录账号生效。</p><div class="font-preferences-controls"><label>字号大小<select name="font_scale" ${ready?'':'disabled'}>${choices.map(([v,label])=>`<option value="${v}" ${v===scale?'selected':''}>${label}</option>`).join('')}</select></label><button type="submit" class="primary" ${ready?'':'disabled'}>保存字号</button><button type="button" data-font-retry ${ready?'hidden':''}>重新读取</button></div><p class="font-preferences-sample">预览：客户档案 · 商品名称 · 销售金额 ¥1,250.00</p><p class="font-preferences-status" role="status">${ready?'已读取当前账号的字号设置':loadError?'字号读取失败，请点击重新读取':'正在读取字号设置…'}</p></form></section>`;}
 window.fontPreferencesPanel=panel;
 function syncPanel(){const f=document.querySelector('#font-preferences-form');if(!f)return;f.elements.font_scale.disabled=!ready||saving;f.querySelector('[type=submit]').disabled=!ready||saving;f.querySelector('[data-font-retry]').hidden=ready;if(!f.dataset.loaded&&ready){f.elements.font_scale.value=scale;f.dataset.loaded='true';f.querySelector('[role=status]').textContent='已读取当前账号的字号设置';}}
 function apply(value,broadcast=true){
  if(!choices.some(([v])=>v===value))return;scale=value;
  document.documentElement.style.setProperty('--ui-font-scale',String(scale/100));
  if(broadcast)document.querySelectorAll('iframe').forEach(frame=>frame.contentWindow?.postMessage({type:'deaoe-font-scale',value:scale},location.origin));
 }
 window.addEventListener('message',event=>{
  if(event.origin!==location.origin)return;
  if(event.source===window.parent&&window.parent!==window&&event.data?.type==='deaoe-font-scale')apply(event.data.value,false);
  if(ready&&event.data?.type==='deaoe-font-ready'&&[...document.querySelectorAll('iframe')].some(f=>f.contentWindow===event.source))event.source.postMessage({type:'deaoe-font-scale',value:scale},location.origin);
 });
 async function read(){
  loadError=false;
  try{const response=await fetch('/service/api/ui-preferences',{cache:'no-store'});if(!response.ok)throw Error();const result=await response.json();csrf=result.csrf;ready=true;apply(result.font_scale);syncPanel();if(window.parent!==window)window.parent.postMessage({type:'deaoe-font-ready'},location.origin);}
  catch{loadError=true;const status=document.querySelector('#font-preferences-form [role=status]');if(status)status.textContent='字号读取失败，请点击重新读取';}
 }
 document.addEventListener('submit',async event=>{
  const f=event.target;if(f.id!=='font-preferences-form')return;event.preventDefault();event.stopImmediatePropagation();if(!ready||saving)return;
  saving=true;syncPanel();const status=f.querySelector('[role=status]');status.textContent='正在保存…';
  try{const response=await fetch('/service/api/ui-preferences',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({font_scale:Number(f.elements.font_scale.value)})});if(!response.ok)throw Error();const result=await response.json();apply(result.font_scale);status.textContent='字号已保存，下次登录自动恢复';}
  catch{status.textContent='保存失败，当前字号未改变，请重试';}
  finally{saving=false;syncPanel();}
 },true);
 document.addEventListener('click',e=>{if(e.target.closest('[data-font-retry]'))read();});
 new MutationObserver(records=>{
  let rescan=false;
  for(const record of records){if(record.type==='attributes'){inline(record.target);continue;}for(const node of record.addedNodes){if(node.nodeType===1){inline(node);if(node.matches('style,link[rel=stylesheet]')||node.querySelector('style,link[rel=stylesheet]'))rescan=true;}else if(record.target.nodeName==='STYLE')rescan=true;}}
  if(rescan)sheets();syncPanel();
 }).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['style']});
 document.addEventListener('load',event=>{if(event.target.matches?.('link[rel=stylesheet]'))sheets();},true);
 sheets();inline(document.documentElement);read();
 if(location.hash==='#settings'&&document.querySelector('#settings-form')&&typeof render==='function')render();
})();
