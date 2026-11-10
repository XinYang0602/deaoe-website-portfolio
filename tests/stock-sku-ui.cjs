const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const base = 'src/service/';
const html = fs.readFileSync(base + 'inventory.html', 'utf8');
const early = html.match(/<script>(.*?)<\/script>/s)[1];
for (const embedded of [false, true]) {
  const classes = [];
  const window = {}; window.parent = {};
  vm.runInNewContext(early, {URLSearchParams, location: {search: embedded ? '?embedded=1' : ''}, window, document: {documentElement: {classList: {add: c => classes.push(c)}}}});
  assert.deepEqual(classes, embedded ? ['inventory-frame'] : []);
}
assert.ok(html.indexOf('html.inventory-frame body>aside') < html.indexOf('<body'));
const source = fs.readFileSync(base + 'inventory.js', 'utf8');
const label = vm.runInNewContext(source.match(/const stockProductLabel=(.*);/)[1]);
assert.notEqual(label({id:'a',code:'A',name:'油烟机',model:'803'}), label({id:'b',code:'B',name:'油烟机',model:'806'}));
const listeners = {};
const form = {dataset:{}, elements:{product_id:{value:'b'},warehouse_id:{value:'w'},qty:{}}};
const current = {};
const selections = [];
vm.runInNewContext(source.slice(source.indexOf('// Administrator quantity correction')).split('// Prioritize an actionable customer')[0], {
 document:{addEventListener:(n,fn)=>listeners[n]=fn,getElementById:id=>id==='adjust-stock-form'?form:current},
 data:{can_adjust_stock:true,products:[{id:'b',active:true}],warehouses:[{id:'w',active:true}],balances:[{product_id:'a',warehouse_id:'w',qty:9},{product_id:'b',warehouse_id:'w',qty:3}]},
 show(){},key:()=> 'key',select:(title,name,rows,value)=>{selections.push([name,value]);return '';},input:()=>'',foot:()=>'',stockProductLabel:label
});
listeners.click({target:{closest:()=>({dataset:{id:'b',warehouse:'w'}})},stopImmediatePropagation(){}});
assert.deepEqual(selections,[['product_id','b'],['warehouse_id','w']]);
assert.equal(form.elements.qty.value,'3');
assert.equal(form.dataset.expected,'3');
console.log('PASS early iframe mode, distinct SKU labels, row product/warehouse preselection and isolated quantity');
