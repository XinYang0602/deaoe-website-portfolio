const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('src/service/app.js','utf8').split('// Mobile staff workspace.')[1].split('// Keep mobile document')[0];
const nodes = new Map();
function element(){return {hidden:false,innerHTML:'',classList:{toggle(){}},setAttribute(){},after(){},addEventListener(type,fn){this[type]=fn},querySelectorAll(){return []}}}
for(const id of ['#main','.service-mobile-menu nav','#logout-form']) nodes.set(id,element());
nodes.get('.service-mobile-menu nav').innerHTML='<section>销售单</section>';
let loggedOut=false;
nodes.get('#logout-form').requestSubmit=()=>loggedOut=true;
const viewport={matches:true,addEventListener(type,fn){this.change=fn}};
const created=[];
const context={matchMedia:()=>viewport,document:{createElement(){const e=element();created.push(e);return e},body:{append(){},classList:{toggle(){}}}},$:s=>nodes.get(s),location:{hash:'#dashboard'},db:{settings:{name:'测试总部'},orders:[{id:'TEST-1',customer:'测试客户',status:'待派工',type:'安装',product:'油烟机',date:'2026-11-08'},{id:'TEST-2',status:'已关闭'}]},esc:s=>String(s??'').replaceAll('<','&lt;'),tag:s=>s,render(){nodes.get('#main').hidden=false},MutationObserver:class{observe(){}},ready:true,loadData:async()=>{},toast(){}};
vm.createContext(context);
vm.runInContext('// Mobile staff workspace.'+source,context);
const panel=created[0];
assert.match(panel.innerHTML,/销售开单/);
assert.match(panel.innerHTML,/未完成工单<strong>1/);
for(const [hash,text] of [['#applications','销售单'],['#tasks','TEST-1'],['#account','退出登录']]){context.location.hash=hash;context.render();assert.match(panel.innerHTML,new RegExp(text));assert.equal(panel.hidden,false)}
context.location.hash='#tasks';context.render();assert.ok(!panel.innerHTML.includes('TEST-2'));
context.location.hash='#inventory/sale';context.render();assert.equal(panel.hidden,true);
context.location.hash='#dashboard';viewport.matches=false;viewport.change();assert.equal(panel.hidden,true);assert.equal(nodes.get('#main').hidden,false);
viewport.matches=true;viewport.change();assert.equal(panel.hidden,false);
panel.click({target:{closest:s=>s==='[data-staff-logout]'?{}:null}});assert.equal(loggedOut,true);
console.log('PASS: mobile routes, real pending counts, desktop resize, logout delegation');
