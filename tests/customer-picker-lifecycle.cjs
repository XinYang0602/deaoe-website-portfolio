const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = 'src/service/';
let source = fs.readFileSync(path + 'inventory.js', 'utf8').split('(function customerFirstSales() {')[1];
source = '(function customerFirstSales() {' + source;
source = source.replace('  const originalShow = show;', '  globalThis.setPickerForTest = panel => { activePicker = panel; };\n  const originalShow = show;');
const listeners = {};
const context = {
  show() {},
  document: {addEventListener() {}, querySelector(selector) {return selector === '#modal' ? {addEventListener(type, fn) {listeners.close = fn;}} : null;}},
  window: {addEventListener(type, fn) {listeners[type] = fn;}}
};
vm.createContext(context);
vm.runInContext(source, context);
for (const action of [() => listeners.close(), () => listeners.hashchange(), () => listeners['inventory-navigation'](), () => context.show('new form')]) {
  let closed = 0, removed = 0;
  context.setPickerForTest({close() {closed++;}, remove() {removed++;}});
  action();
  assert.equal(closed, 1);
  assert.equal(removed, 1);
  listeners['inventory-navigation']();
  assert.equal(removed, 1);
}
const css = fs.readFileSync(path + 'inventory.css', 'utf8');
assert.ok(!css.includes('body.embedded-inventory header,'));
assert.ok(css.includes('body.embedded-inventory .shell > header,'));
console.log('PASS picker cleanup: parent close, route change, same-route navigation, form replacement; scoped header');
