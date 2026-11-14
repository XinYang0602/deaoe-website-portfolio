const assert=require('node:assert/strict'),csv=require('../src/service/order-csv.js');
const p=csv.parse('\uFEFFid,number,note\r\n00123456789012345678,000001,"包含,逗号\n和""引号"""\r\n');assert.deepEqual(p.rows,[['00123456789012345678','000001','包含,逗号\n和"引号"']]);
for(const bad of ['a,a\n1,2','a,b\n1','a\n"unclosed','a\n"ok"oops','a\n'+'x\n'.repeat(501)])assert.throws(()=>csv.parse(bad));console.log('PASS CSV quoted text, identifiers, malformed files and limits');
