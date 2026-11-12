const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('src/service/phase2.js','utf8').split('// Mobile sales:')[1].split('// One searchable')[0];
const events={},nodes={};
function node(){return {innerHTML:'',classList:{toggle(){}},append(el){nodes['#'+el.id]=el},addEventListener(){}}}
for(const id of ['#main','#modal','#sales-cards'])nodes[id]=node();
const viewport={matches:true,addEventListener(){}};
const ctx={documentSortControls:()=>'',compareDocuments:(a,b)=>String(b.date).localeCompare(String(a.date)),saleDateMatches:()=>true,saleDateControls:()=>'',window:{matchMedia:()=>viewport},parent:{postMessage(){}},location:{origin:'https://test.invalid'},page:'sale',data:null,render(){},show(){},document:{body:node(),createElement:node,querySelector:s=>nodes[s]||null,addEventListener:(e,f)=>events[e]=f},E:s=>String(s??'').replaceAll('<','&lt;'),opts:()=>'',states:{draft:'草稿',approved:'已审核'},party:id=>id,product:id=>({name:id,model:'',code:''}),warehouse:id=>id,money:n=>(n/100).toFixed(2),button:(label,action)=>`${label}:${action}`};
ctx.saleRemaining=()=>true;vm.createContext(ctx);vm.runInContext('// Mobile sales:'+source,ctx);
ctx.data={warehouses:[],docs:[{id:'S1',kind:'sale',status:'draft',party_id:'客户甲',warehouse_id:'A',date:'2026-11-08'},{id:'S2',kind:'sale',status:'approved',party_id:'客户乙',warehouse_id:'B'},{id:'P1',kind:'purchase'}],lines:[{doc_id:'S1',product_id:'油烟机',qty:2,price_cents:12345}]};
ctx.render();assert.match(nodes['#sales-cards'].innerHTML,/246.90/);assert.match(nodes['#sales-cards'].innerHTML,/提交审核:submit/);assert.match(nodes['#sales-cards'].innerHTML,/历史销售补发:from-source/);assert.doesNotMatch(nodes['#sales-cards'].innerHTML,/P1/);
events.input({target:{id:'sales-query',value:'油烟机'}});assert.match(nodes['#sales-cards'].innerHTML,/S1/);assert.doesNotMatch(nodes['#sales-cards'].innerHTML,/S2/);
events.change({target:{id:'sales-status',value:'approved'}});assert.match(nodes['#sales-cards'].innerHTML,/没有符合条件/);
events.input({target:{id:'sales-query',value:''}});assert.match(nodes['#sales-cards'].innerHTML,/S2/);
events.change({target:{id:'sales-store',value:'A'}});assert.match(nodes['#sales-cards'].innerHTML,/没有符合条件/);
console.log('PASS mobile sales: totals, actions, product search, status and warehouse filters');
