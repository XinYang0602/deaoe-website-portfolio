const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('src/service/phase2.js','utf8').split('// Embedded mode')[1].split('// Mobile sales:')[0];
const listeners={},opened=[],parent={postMessage(){}},location={search:'?embedded=1',origin:'https://test.invalid',hash:'#sale',replace(hash){this.hash=hash;}};
let closed=0,navigation=0;const modal={open:true,close(){closed++;this.open=false;}};
const context={Event,$:()=>modal,URLSearchParams,URL,location,parent,names:{sale:1,purchase:1,issue:1,receipt:1,products:1},data:null,busy:false,render(){},docForm:kind=>opened.push(kind),document:{body:{classList:{add(){}}},addEventListener(){}},window:{parent,dispatchEvent(){navigation++;},addEventListener(type,fn){(listeners[type]??=[]).push(fn)}}};
vm.createContext(context);vm.runInContext('// Embedded mode'+source,context);
const message=(route,id,create=true,origin=location.origin)=>listeners.message[0]({origin,source:parent,data:{type:'deaoe-inventory-route',route,requestId:id,create}});
message('sale',1);assert.equal(opened.length,0);context.data={};context.render();assert.deepEqual(opened,['sale']);
const before=closed;message('sale',1);assert.equal(closed,before);assert.equal(opened.length,1);message('sale',2);assert.equal(opened.length,2);
message('purchase',3);assert.equal(opened.length,2);location.hash='#purchase';listeners.hashchange.forEach(fn=>fn());assert.equal(opened.at(-1),'purchase');
message('products',4);context.render();assert.equal(opened.length,3);
message('sale',5,true,'https://untrusted.invalid');assert.equal(opened.length,3);
console.log('PASS: deferred data, deduplication, repeated opening, route change, allowed actions and origin');

location.hash='#sale';modal.open=true;message('sale',6,false);assert.equal(modal.open,false);assert.ok(navigation>0);assert.equal(opened.length,3);
