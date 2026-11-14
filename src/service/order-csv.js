'use strict';
// CSV text only: preserve order IDs and phone numbers; never evaluate spreadsheet cells.
const orderCSV={
 fields:[['order_id','平台订单号',true],['date','订单日期',true],['sku','产品编号',true],['qty','数量',true],['price','优惠后单价',true],['line_amount','商品实付金额',false],['customer','结算客户全名',false],['contact','收货人',false],['phone','收货电话',true],['address','收货地址',true]],
 aliases:{order_id:['平台订单号','订单编号','订单号','主订单编号','order_id'],date:['订单日期','date'],sku:['产品编号','商家编码','sku'],qty:['数量','购买数量','商品数量','qty'],price:['优惠后单价','单价','price'],line_amount:['商品实付金额','line_amount'],customer:['结算客户全名','customer'],contact:['收货人','收货人姓名','contact'],phone:['收货电话','联系手机','收货人手机号','phone'],address:['收货地址','address']},
 parse(text){
  if(text.length>1500000)throw Error('文件过大，请拆分为每批最多500行');
  text=text.replace(/^\uFEFF/,'');let rows=[],row=[],value='',quoted=false,closed=false;
  const cell=()=>{row.push(value);value='';closed=false;};const line=()=>{cell();if(row.some(v=>v.trim()!==''))rows.push(row);row=[];if(rows.length>501)throw Error('每批最多500行商品明细');};
  for(let i=0;i<text.length;i++){const c=text[i];
   if(quoted){if(c==='"'){if(text[i+1]==='"'){value+='"';i++;}else{quoted=false;closed=true;}}else value+=c;continue;}
   if(c===','){cell();continue;}if(c==='\n'||c==='\r'){if(c==='\r'&&text[i+1]==='\n')i++;line();continue;}
   if(closed)throw Error('CSV引号后存在额外内容');
   if(c==='"'){if(value!=='')throw Error('CSV引号格式错误');quoted=true;}else value+=c;
  }
  if(quoted)throw Error('CSV存在未闭合引号');if(value!==''||row.length||closed)line();
  if(rows.length<2)throw Error('CSV需要表头和至少一行商品');const headers=rows.shift().map(h=>h.trim());
  if(headers.some(h=>!h)||new Set(headers).size!==headers.length)throw Error('表头不能重复或为空');
  rows.forEach((r,i)=>{if(r.length!==headers.length)throw Error(`第${i+2}行列数与表头不一致`);});return {headers,rows};
 },
 map(file,mapping){return file.rows.map(row=>Object.fromEntries(this.fields.map(([key])=>[key,mapping[key]===''?'':String(row[Number(mapping[key])]??'').trim()])));}
};
if(typeof module!=='undefined')module.exports=orderCSV;
