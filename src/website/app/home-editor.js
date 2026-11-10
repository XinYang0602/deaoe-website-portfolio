(()=>{
const forms=[...document.querySelectorAll('.home-image-form')];
const isDirty=()=>forms.some(f=>f.dataset.dirty==='1');
forms.forEach(form=>{
 const list=form.querySelector('.home-editor-list'),input=form.querySelector('input[type=file]');let pending=[],urls=[],drag=null;
 const mark=()=>{form.dataset.dirty='1';form.querySelector('.home-dirty').textContent='有未保存修改';};
 const sync=()=>{const dt=new DataTransfer();pending.forEach(f=>dt.items.add(f));input.files=dt.files;};
 const install=card=>{
  const handle=card.querySelector('.home-drag');
  handle.onpointerdown=e=>{if(e.button!==0)return;drag=card;handle.setPointerCapture(e.pointerId);card.classList.add('is-dragging');e.preventDefault();};
  handle.onpointermove=e=>{if(drag!==card)return;const target=document.elementFromPoint(e.clientX,e.clientY)?.closest('.home-editor-card');if(target&&target!==card&&target.parentElement===list){const all=[...list.children];if(all.indexOf(card)<all.indexOf(target))target.after(card);else target.before(card);mark();}if(e.clientY<90)window.scrollBy(0,-18);if(e.clientY>innerHeight-90)window.scrollBy(0,18);};
  const stop=()=>{card.classList.remove('is-dragging');drag=null;};handle.onpointerup=stop;handle.onpointercancel=stop;handle.onlostpointercapture=stop;
 };
 [...list.children].forEach(install);
 list.onclick=e=>{const b=e.target.closest('button');if(!b)return;const card=b.closest('.home-editor-card');if(b.hasAttribute('data-remove')){if(confirm('确定移除这张图片？保存本组草稿后生效。')){card.remove();const dt=new DataTransfer();[...list.children].filter(c=>c.dataset.token.startsWith('new_')).forEach(c=>{const old=Number(c.dataset.token.slice(4));const next=dt.files.length;dt.items.add(pending[old]);c.dataset.token='new_'+next;});pending=[...dt.files];sync();mark();}}else if(b.dataset.move){if(b.dataset.move==='-1'&&card.previousElementSibling)card.previousElementSibling.before(card);if(b.dataset.move==='1'&&card.nextElementSibling)card.nextElementSibling.after(card);mark();b.focus();}};
 input.onchange=()=>{
  const added=[...input.files];
  if(added.some(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>5*1024*1024)||pending.length+added.length>12||[...pending,...added].reduce((n,f)=>n+f.size,0)>30*1024*1024){alert('每批最多12张、总量30 MB，单张5 MB，仅支持JPG/PNG/WebP。');sync();return;}
  added.forEach(file=>{const index=pending.length;pending.push(file);const card=document.createElement('div');card.className='home-editor-card';card.dataset.token='new_'+index;card.innerHTML='<button type="button" class="home-drag">⠿ 拖动排序</button><img draggable="false"><div><button type="button" data-move="-1">前移</button><button type="button" data-move="1">后移</button><button type="button" data-remove>删除</button></div>';
   const url=URL.createObjectURL(file);urls.push(url);card.querySelector('img').src=url;card.querySelector('img').alt=file.name;list.append(card);install(card);
  });sync();mark();
 };
 form.onsubmit=e=>{
  if(forms.some(f=>f!==form&&f.dataset.dirty==='1')){alert('请每次编辑一组图片并保存。其他组有未保存修改；继续会丢失。');if(!confirm('只保存当前组，放弃其他组未保存修改？')){e.preventDefault();return;}}
  const order=[...list.children].map(c=>c.dataset.token);if(!order.length){alert('请至少保留一张图片。');e.preventDefault();return;}
  // Upload only surviving new files and rewrite their indices in the submitted order.
  const dt=new DataTransfer();const rewritten=order.map(token=>{if(!token.startsWith('new_'))return token;const n=dt.files.length;dt.items.add(pending[Number(token.slice(4))]);return 'new_'+n;});input.files=dt.files;form.querySelector('[name=order]').value=JSON.stringify(rewritten);forms.forEach(f=>f.dataset.dirty='0');
 };
 window.addEventListener('pagehide',()=>urls.forEach(URL.revokeObjectURL));
});
document.querySelector('.home-publish').onsubmit=e=>{if(isDirty()){alert('请先保存图片修改，再发布或恢复。');e.preventDefault();return;}if(!confirm(e.submitter.value==='restore'?'恢复上一版轮播并立即更新官网？':'发布全部已保存的轮播草稿？'))e.preventDefault();};
window.addEventListener('beforeunload',e=>{if(isDirty()){e.preventDefault();e.returnValue='';}});
})();
