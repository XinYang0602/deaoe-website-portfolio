(() => {
 const current=document.querySelector('meta[name="service-release"]')?.content;
 if(!current)return;
 let checking=false,shown='',banner;
 async function check(){
  if(document.hidden||checking)return;
  checking=true;
  try{
   const r=await fetch('/service/api/release',{cache:'no-store',signal:AbortSignal.timeout(15000)});
   if(!r.ok||!r.headers.get('content-type')?.includes('application/json'))return;
   const {version,summary}=await r.json();
   if(!version||version===current){banner?.remove();banner=null;shown='';return;}
   if(version===shown)return;
   shown=version;banner?.remove();banner=document.createElement('div');banner.id='release-notice';banner.setAttribute('role','status');
   banner.style.cssText='position:fixed;inset:auto 12px 12px auto;max-width:min(36em,calc(100vw - 24px));padding:12px 16px;background:#fff;color:#223044;border:1px solid #c3ccd6;border-radius:6px;box-shadow:0 3px 16px #18283830;z-index:99999';
   const message=document.createElement('p');message.textContent='有新版本，请在保存当前工作后刷新网页。'+(summary?' '+summary:'');banner.append(message);
   for(const [label,action] of [['刷新网页',()=>location.reload()],['稍后',()=>banner.remove()]]){
    const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=action;banner.append(b);
   }
   document.body.append(banner);
  }catch{/* An offline tab will try again when it becomes visible or on the next tick. */}
  finally{checking=false;}
 }
 setInterval(check,60000);document.addEventListener('visibilitychange',check);check();
})();
