(()=>{
const editor=document.getElementById('product-editor');
const dirty=()=>{if(editor)editor.dispatchEvent(new Event('input',{bubbles:true}));};
document.querySelectorAll('.sortable-images').forEach(list=>{
 list.addEventListener('click',e=>{const b=e.target.closest('[data-move]');if(!b)return;const item=b.closest('.sortable-image');if(b.dataset.move==='-1'&&item.previousElementSibling)list.insertBefore(item,item.previousElementSibling);else if(b.dataset.move==='1'&&item.nextElementSibling)list.insertBefore(item.nextElementSibling,item);dirty();b.focus();});
 list.querySelectorAll('.image-drag').forEach(handle=>{
 let item=null;
 handle.addEventListener('pointerdown',e=>{if(e.button!==0)return;item=handle.closest('.sortable-image');handle.setPointerCapture(e.pointerId);item.classList.add('sorting');e.preventDefault();});
 handle.addEventListener('pointermove',e=>{if(!item)return;const target=document.elementFromPoint(e.clientX,e.clientY)?.closest('.sortable-image');if(target&&target!==item&&target.parentElement===list){const children=[...list.children];if(children.indexOf(item)<children.indexOf(target))target.after(item);else target.before(item);dirty();}if(e.clientY<90)window.scrollBy(0,-18);else if(e.clientY>innerHeight-90)window.scrollBy(0,18);});
 const end=()=>{if(item)item.classList.remove('sorting');item=null;};handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);handle.addEventListener('lostpointercapture',end);
 });
});
const section=document.querySelector('.detail .description');
if(section&&section.querySelector(':scope > img')){
 const bar=document.createElement('div');bar.className='detail-zoom';bar.innerHTML='<span>详情图大小</span><button type="button" data-zoom="-1" aria-label="缩小详情图">− 缩小</button><output aria-live="polite">100%</output><button type="button" data-zoom="1" aria-label="放大详情图">＋ 放大</button><button type="button" data-zoom="0">还原</button>';
 section.insertBefore(bar,section.querySelector(':scope > img'));let scale=100;
 bar.addEventListener('click',e=>{const b=e.target.closest('[data-zoom]');if(!b)return;scale=b.dataset.zoom==='0'?100:Math.min(160,Math.max(50,scale+Number(b.dataset.zoom)*10));section.style.setProperty('--detail-image-width',`${750*scale/100}px`);bar.querySelector('output').value=scale+'%';bar.querySelector('[data-zoom="-1"]').disabled=scale===50;bar.querySelector('[data-zoom="1"]').disabled=scale===160;});
}
})();

/* immediate-image-preview-v1 */
(()=>{
const form=document.getElementById('product-editor');if(!form)return;
const changed=()=>form.dispatchEvent(new Event('input',{bubbles:true}));
form.querySelectorAll('input[type=checkbox][name^="remove_"]').forEach(box=>{
 const label=box.closest('label');if(!label)return;
 const item=box.closest('.sortable-image');
 const photo=item?.querySelector('img')||label.previousElementSibling;
 box.hidden=true;label.hidden=true;
 const button=document.createElement('button');button.type='button';button.className='image-delete';button.textContent='删除图片';label.after(button);
 const undo=document.createElement('button');undo.type='button';undo.className='image-undo';undo.textContent='撤销删除';undo.hidden=true;
 const note=document.createElement('p');note.className='image-deleted-note';note.hidden=true;note.textContent='图片已移除，保存草稿后生效。';note.append(' ',undo);
 if(item)item.after(note);else button.after(note);
 button.onclick=()=>{if(!confirm('确定删除这张图片？保存草稿后生效，发布后更新官网。'))return;box.checked=true;if(item)item.hidden=true;else{if(photo?.tagName==='IMG')photo.hidden=true;button.hidden=true;}note.hidden=false;undo.hidden=false;changed();};
 undo.onclick=()=>{box.checked=false;if(item)item.hidden=false;else{if(photo?.tagName==='IMG')photo.hidden=false;button.hidden=false;}note.hidden=true;changed();};
});
form.querySelectorAll('input[type=file]').forEach(input=>{
 const panel=document.createElement('div');panel.className='pending-images';input.after(panel);let urls=[];
 const render=()=>{
 urls.forEach(URL.revokeObjectURL);urls=[];panel.replaceChildren();
 if(!input.files.length)return;
 const note=document.createElement('p');note.className='pending-note';note.textContent='已选图片预览 · 尚未保存';panel.append(note);
 const grid=document.createElement('div');grid.className='pending-grid';panel.append(grid);
 [...input.files].forEach((file,index)=>{
 const card=document.createElement('div');card.className='pending-card';
 const img=document.createElement('img');img.alt=file.name;const url=URL.createObjectURL(file);urls.push(url);img.src=url;
 const name=document.createElement('p');name.textContent=file.name;
 const remove=document.createElement('button');remove.type='button';remove.className='image-delete';remove.textContent='删除';
 remove.onclick=()=>{if(!confirm('确定移除这张待上传图片？'))return;const dt=new DataTransfer();[...input.files].forEach((f,i)=>{if(i!==index)dt.items.add(f);});input.files=dt.files;render();changed();};
 card.append(img,name,remove);grid.append(card);
 });
 };
 input.addEventListener('change',()=>{
 const files=[...input.files];
 if(files.some(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>5*1024*1024)){
 alert('请选择 JPG、PNG 或 WebP 图片，每张不超过5 MB。');input.value='';render();return;
 }
 render();changed();
 });
 window.addEventListener('pagehide',()=>urls.forEach(URL.revokeObjectURL));
});
})();
