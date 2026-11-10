<?php
require (getenv('DEAOE_TEST_INVENTORY') ?: __DIR__.'/../src/service/inventory.php');
set_error_handler(function($level,$message){throw new RuntimeException($message);});
function expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function tx(callable $fn){global $d;$d->beginTransaction();try{$r=$fn();$d->commit();return $r;}catch(Throwable $e){$d->rollBack();throw $e;}}
function denied(callable $fn){try{tx($fn);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected rejection');}
function value($sql,$args=[]){global $d;return iq($d,$sql,$args)->fetchColumn();}
function doc($kind,$qty,$source=null,$sn=[],$product='P'){
 global $d;
 return tx(function()use($d,$kind,$qty,$source,$sn,$product){
 $line=['product_id'=>$product,'qty'=>$qty,'price'=>'20.00','serials'=>array_map(fn($s)=>['sn'=>$s],$sn)];
 if($source)$line['source_line']=value('SELECT id FROM inv_lines WHERE doc_id=?',[$source]);
 $r=invSaveDoc($d,['kind'=>$kind,'warehouse_id'=>'W','party_id'=>$kind==='sale'?'C':'S','source_id'=>$source,'date'=>'2026-11-08','request_key'=>bin2hex(random_bytes(12)),'lines'=>[$line]]);
 invTransition($d,['id'=>$r['id'],'transition'=>'submit','version'=>1]);return $r['id'];});
}
function approve($id){global $d;return tx(fn()=>invTransition($d,['id'=>$id,'transition'=>'approve','version'=>2]));}
function op($kind,$body){global $d;return tx(function()use($d,$kind,$body){$r=p2Create($d,['kind'=>$kind,'request_key'=>bin2hex(random_bytes(12)),'body'=>array_merge(['warehouse_id'=>'W','product_id'=>'P','qty'=>1,'note'=>'隔离回归测试'],$body)]);p2Step($d,['id'=>$r['id'],'transition'=>'submit','version'=>1]);return $r['id'];});}
function post($id){global $d;return tx(fn()=>p2Step($d,['id'=>$id,'transition'=>'approve','version'=>2]));}
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
invSchema($d);p2Schema($d);
$d->exec("CREATE TABLE state(id INTEGER PRIMARY KEY,revision INTEGER,body TEXT,updated_at TEXT);CREATE TABLE audit(revision INTEGER,user TEXT,created_at TEXT,body TEXT);
INSERT INTO inv_warehouses VALUES('W','测试仓',1,1);
INSERT INTO inv_parties VALUES('S','supplier','测试供应商','','','',1,1),('C','customer','测试客户','','','',1,1);
INSERT INTO inv_products VALUES('P','P','油烟机','整机','德澳','','台',1,NULL,NULL,1,'',1),('PART','PART','配件','配件','德澳','','件',0,NULL,NULL,1,'',1);");
iq($d,'INSERT INTO state VALUES(1,1,?,?)',[json_encode(['cards'=>[],'settings'=>['months'=>12]]),'now']);
$purchase=doc('purchase',3);approve($purchase);expect(value('SELECT COUNT(*) FROM inv_ledger')==0,'采购订单不得增加库存');
$receipt=doc('receipt',2,$purchase,['A1','A2']);approve($receipt);expect(approve($receipt)['repeated'],'入库幂等');
$over=doc('receipt',2,$purchase,['A3','A4']);denied(fn()=>invTransition($d,['id'=>$over,'transition'=>'approve','version'=>2]));
expect(value('SELECT qty FROM inv_balances')==2,'超收回滚');expect(value('SELECT COUNT(*) FROM inv_sn')==2,'超收 SN 回滚');
$last=doc('receipt',1,$purchase);approve($last);expect(value('SELECT COUNT(*) FROM inv_sn')==3,'自动生成 SN');
echo "PASS purchase: approval, partial receipts, over-receipt rollback, generated SN, repeated approval\n";
$source=value('SELECT id FROM inv_lines WHERE doc_id=?',[$receipt]);
$ret=op('purchase_return',['source_line'=>$source,'serials'=>['A1'],'refund_price'=>'20']);post($ret);expect(post($ret)['repeated'],'退货幂等');
expect(value("SELECT status FROM inv_sn WHERE sn='A1'")==='returned_supplier','采购退货状态');
$duplicate=op('purchase_return',['source_line'=>$source,'serials'=>['A1'],'refund_price'=>'20']);denied(fn()=>p2Step($d,['id'=>$duplicate,'transition'=>'approve','version'=>2]));
$exchange=op('purchase_exchange',['return_id'=>$ret,'old_serials'=>['A1'],'serials'=>['NEW1'],'exchange_price'=>'25']);post($exchange);
expect(value('SELECT qty FROM inv_balances')==3,'采购换货恢复库存');
$again=op('purchase_exchange',['return_id'=>$ret,'old_serials'=>['A1'],'serials'=>['NEW2'],'exchange_price'=>'25']);denied(fn()=>p2Step($d,['id'=>$again,'transition'=>'approve','version'=>2]));
$sale=doc('sale',1,null,['A2']);approve($sale);$issue=value("SELECT id FROM inv_docs WHERE source_id=? AND kind='issue'",[$sale]);
$salesReturn=op('sales_return',['source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$issue]),'serials'=>['A2'],'refund_price'=>'20']);post($salesReturn);
expect(value("SELECT status FROM inv_sn WHERE sn='A2'")==='quarantine','销售退货待检');expect(p2Hold($d,'P','W')===1,'待检占用');
$blocked=doc('sale',3,null,[]);denied(fn()=>invTransition($d,['id'=>$blocked,'transition'=>'approve','version'=>2]));
$state=['cards'=>[['id'=>'CARD','sn'=>'A2','start'=>'2025-01-01','end'=>'2026-01-01']],'settings'=>['months'=>12]];
iq($d,'UPDATE state SET body=? WHERE id=1',[json_encode($state)]);iq($d,'INSERT INTO inv_card_links VALUES(?,?)',['CARD','A2']);
$sx=op('sales_exchange',['return_id'=>$salesReturn,'old_serials'=>['A2'],'serials'=>['NEW1'],'exchange_price'=>'25','exchange_date'=>'2026-11-08']);post($sx);
$card=json_decode(value('SELECT body FROM state'),true)['cards'][0];expect($card['sn']==='NEW1'&&$card['start']==='2026-11-08'&&$card['end']==='2027-11-08','换机保修重算');
$inspect=op('inspection',['serials'=>['A2'],'result_zone'=>'available']);post($inspect);expect(p2Hold($d,'P','W')===0,'验收释放待检');
echo "PASS returns/exchanges: source SN, duplicate protection, quarantine, inspection, replacement warranty\n";
$pp=doc('purchase',5,null,[],'PART');approve($pp);$pr=doc('receipt',5,$pp,[],'PART');approve($pr);
$pret=op('purchase_return',['product_id'=>'PART','qty'=>2,'source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$pr]),'refund_price'=>'20']);post($pret);
expect(value("SELECT SUM(qty) FROM inv_lots WHERE product_id='PART'")==3,'配件退货批次');
$count=op('count',[]);expect(value('SELECT COUNT(*) FROM inv_freezes')==1,'盘点冻结');
$freezePurchase=doc('purchase',1);approve($freezePurchase);$pending=doc('receipt',1,$freezePurchase,['BLOCK']);denied(fn()=>invTransition($d,['id'=>$pending,'transition'=>'approve','version'=>2]));
$sns=iq($d,"SELECT sn FROM inv_sn WHERE status='available'",[])->fetchAll(PDO::FETCH_COLUMN);
$counts=[['product_id'=>'P','qty'=>count($sns),'serials'=>$sns],['product_id'=>'PART','qty'=>2,'reason'=>'测试盘亏']];
// Submitted counts must be rejected before editing.
tx(fn()=>p2Step($d,['id'=>$count,'transition'=>'reject','version'=>2,'reason'=>'录入实盘']));
tx(fn()=>p2Step($d,['id'=>$count,'transition'=>'edit','version'=>3,'body'=>['warehouse_id'=>'W','note'=>'盘点','counts'=>$counts]]));
tx(fn()=>p2Step($d,['id'=>$count,'transition'=>'submit','version'=>4]));
tx(fn()=>p2Step($d,['id'=>$count,'transition'=>'approve','version'=>5]));
expect(value('SELECT COUNT(*) FROM inv_freezes')==0,'盘点解冻');expect(value("SELECT qty FROM inv_balances WHERE product_id='PART'")==2,'盘亏库存');expect(value("SELECT SUM(qty) FROM inv_lots WHERE product_id='PART'")==2,'盘亏批次');
echo "PASS count: freeze, reject/edit/resubmit, loss adjustment and lot consistency\n";

// Equal total quantity still requires a reason if the actual machines differ.
$swapCounts=[['product_id'=>'P','qty'=>count($sns),'serials'=>array_merge(array_slice($sns,1),['FOUND1'])],['product_id'=>'PART','qty'=>2]];
$swap=op('count',['counts'=>$swapCounts]);
denied(fn()=>p2Step($d,['id'=>$swap,'transition'=>'approve','version'=>2]));
expect(value("SELECT COUNT(*) FROM inv_sn WHERE sn='FOUND1'")==0,'无原因 SN 替换须回滚');
echo "PASS count: equal-quantity SN replacement requires an explanation\n";

$swapCounts[0]['reason']='实物序列号核实后更正';
tx(fn()=>p2Step($d,['id'=>$swap,'transition'=>'reject','version'=>2,'reason'=>'补充原因']));
tx(fn()=>p2Step($d,['id'=>$swap,'transition'=>'edit','version'=>3,'body'=>['warehouse_id'=>'W','note'=>'核实差异','counts'=>$swapCounts]]));
tx(fn()=>p2Step($d,['id'=>$swap,'transition'=>'submit','version'=>4]));
tx(fn()=>p2Step($d,['id'=>$swap,'transition'=>'approve','version'=>5]));
expect(value('SELECT status FROM inv_sn WHERE sn=?',[$sns[0]])==='lost','旧 SN 盘亏');
expect(value("SELECT status FROM inv_sn WHERE sn='FOUND1'")==='available','新 SN 盘盈');
expect(value("SELECT qty FROM inv_balances WHERE product_id='P'")==count($sns),'替换不改变总量');
expect(value('SELECT COUNT(*) FROM inv_freezes')==0,'补原因后解除冻结');
echo "PASS count: explained SN replacement and unfreeze\n";
