'use strict';
const deviceUsersUrl = '/service/api/device-users';
let directoryRequest = 0;

function deviceUserPage() {
 const request = ++directoryRequest;
 queueMicrotask(async () => {
  const panel = document.querySelector('[data-user-directory]');
  if (!panel) return;
  try {
   const response = await fetch(deviceUsersUrl, {cache:'no-store'});
   if (!response.ok) throw Error('读取用户管理失败，请刷新');
   const {users} = await response.json();
   if (!panel.isConnected || request !== directoryRequest) return;
   function draw() {
    const term = panel.querySelector('input').value.trim().toLowerCase();
    const rows = users.filter(u => [u.name,u.phone,...u.machines.flatMap(m=>[m.sn,m.product,m.address])].some(v=>String(v).toLowerCase().includes(term)));
    const list = panel.querySelector('[data-profile-list]');
    list.innerHTML = table(['使用人','联系电话','机器数','操作'],rows.map(u=>
     `<tr><td>${esc(u.name)}</td><td>${esc(u.phone)}</td><td>${u.machines.length}</td><td><button type="button" data-profile-index="${users.indexOf(u)}">查看档案</button></td></tr>`));
   }
   panel.querySelector('input').oninput = draw;
   panel.onclick = e => {
    const button=e.target.closest('[data-profile-index]');
    if(button) showDeviceUser(users[Number(button.dataset.profileIndex)]);
   };
   draw();
  } catch (error) { if (panel.isConnected) panel.querySelector('[data-profile-list]').textContent=error.message; }
 });
 return title('用户管理','实际使用人的机器、机身二维码和服务记录。','<button class="primary" type="button" data-register-device>登记机器</button>')+
  '<section class="panel" data-user-directory><div class="toolbar"><input type="search" placeholder="搜索姓名、电话、产品、机身编号或地址" aria-label="搜索使用人或机器"></div><div data-profile-list aria-live="polite">正在读取档案…</div></section>';
}

function showDeviceUser(user) {
 const machines=user.machines.map(m=>{
  const photos=m.photos.map(id=>{
   const url=deviceUsersUrl+'?image='+encodeURIComponent(id);
   return `<a href="${url}" target="_blank" rel="noopener"><img width="88" height="88" style="object-fit:contain" src="${url}" alt="机身二维码"></a> <a href="${url}&amp;download=1" download>下载 WebP</a>`;
  }).join(' ');
  return `<section class="panel" style="padding:12px"><h3>${esc(m.product)}</h3><p>机身编号　${esc(m.sn||'仅二维码')}<br>地址　${esc(m.address||'未填写')}</p><div>${photos}</div>
   <button type="button" data-add-machine-photo="${esc(m.key)}">补传机身二维码</button>
   ${m.orders.length?`<p>关联工单　${m.orders.map(id=>`<button type="button" data-detail="${esc(id)}">${esc(id)}</button>`).join(' ')}</p>`:''}
   ${m.cards.length?`<p>电子保修卡　${m.cards.map(id=>`<button type="button" data-card="${esc(id)}">${esc(id)}</button>`).join(' ')}</p>`:''}</section>`;
 }).join('');
 openModal('用户管理',`<p><strong>${esc(user.name)}</strong>　${esc(user.phone)}</p>${machines}`);
}

function machineRegistration(machineKey='') {
 const photoOnly=Boolean(machineKey);
 openModal(photoOnly?'补传机身二维码':'登记机器',`<form id="device-registration"><div class="form-grid">
 ${photoOnly?'':field('实际使用人','customer','text','','maxlength="80"')+field('联系电话','phone','tel','','maxlength="40"')+
  '<label class="wide">使用地址<input name="address" maxlength="500"></label>'+machineModelField(activeStock().filter(p=>p.kind==='整机').map(p=>p.name+' '+p.model))+
  '<label>机身编号<input name="sn" maxlength="200" placeholder="也可仅上传二维码"></label>'}
 <label class="wide">机身二维码照片<input type="file" name="image" accept="image/png,image/jpeg,image/webp" ${photoOnly?'required':''}></label></div>
 <div class="modal-foot"><button class="primary" type="submit">保存</button></div></form>`);
 const form=document.querySelector('#device-registration'),requestId=crypto.randomUUID();
 form.onsubmit=async event=>{
  event.preventDefault();event.stopPropagation();
  const button=form.querySelector('button[type=submit]');
  if(button.disabled)return;
  button.disabled=true;
  try{
   const body=new FormData(form);
   body.set('operation',photoOnly?'photo':'register');
   body.set('request_id',requestId);
   if(photoOnly)body.set('machine_key',machineKey);
   const response=await fetch(deviceUsersUrl,{method:'POST',headers:{'X-CSRF-Token':csrf},body});
   const result=await response.json();
   if(!response.ok)throw Error(result.error||'保存失败');
   document.querySelector('#modal').close();render();toast('机器档案已保存');
  }catch(error){toast(error.message);}finally{button.disabled=false;}
 };
}

document.addEventListener('click',event=>{
 if(event.target.closest('[data-register-device]'))machineRegistration();
 const button=event.target.closest('[data-add-machine-photo]');
 if(button)machineRegistration(button.dataset.addMachinePhoto);
});

document.addEventListener('change', event=>{
 if(event.target.matches('[data-machine-photo]')){
  const serial=event.target.form?.elements.sn;
  if(serial)serial.required=!event.target.files.length;
 }
});
