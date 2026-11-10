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

$_SESSION['user']='admin';
function adjust($p,$before,$after,$key=null){global $d;return tx(fn()=>invAdjustStock($d,['product_id'=>$p,'warehouse_id'=>'W','expected_qty'=>$before,'qty'=>$after,'note'=>'测试调整','request_key'=>$key??bin2hex(random_bytes(12))]));}
adjust('P',0,4);expect(value("SELECT qty FROM inv_balances WHERE product_id='P'")==4,'增加整机库存');
$sale=doc('sale',2);approve($sale);$issue=value("SELECT id FROM inv_docs WHERE source_id=? AND kind='issue'",[$sale]);
$source=value('SELECT id FROM inv_lines WHERE doc_id=?',[$issue]);
$ret=op('sales_return',['qty'=>2,'source_line'=>$source,'refund_price'=>'20','return_mode'=>'replacement']);post($ret);
expect(p2Hold($d,'P','W')===2,'免 SN 退货待检');
$x=op('sales_exchange',['qty'=>2,'return_id'=>$ret,'exchange_price'=>'20','exchange_date'=>'2026-11-09']);post($x);
expect(value("SELECT qty FROM inv_balances WHERE product_id='P'")==2,'免 SN 换货扣减');
expect(json_decode(value('SELECT body FROM inv_ops WHERE id=?',[$x]),true)['warranty_pending'],'自动匹配不更改顾客保修绑定');
denied(fn()=>invAdjustStock($d,['product_id'=>'P','warehouse_id'=>'W','expected_qty'=>2,'qty'=>0,'note'=>'不可扣待检','request_key'=>bin2hex(random_bytes(12))]));
$k=bin2hex(random_bytes(12));adjust('PART',0,8,$k);expect(adjust('PART',0,8,$k)['repeated'],'重复请求不重复增加');adjust('PART',8,3);
expect(value("SELECT SUM(qty) FROM inv_lots WHERE product_id='PART'")==3,'配件库存与批次一致');
$_SESSION['user']='staff';denied(fn()=>invAdjustStock($d,[]));$_SESSION['user']='admin';
denied(fn()=>invAdjustStock($d,['product_id'=>'PART','warehouse_id'=>'W','expected_qty'=>8,'qty'=>10,'note'=>'过期','request_key'=>bin2hex(random_bytes(12))]));
$ps=doc('sale',2,null,[],'PART');approve($ps);$pi=value("SELECT id FROM inv_docs WHERE source_id=? AND kind='issue'",[$ps]);
$pr=op('sales_return',['product_id'=>'PART','qty'=>2,'source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$pi]),'refund_price'=>'20']);post($pr);
expect(p2Hold($d,'PART','W')===2,'配件按原批次退货');
echo "PASS quantity returns, exchanges, stock adjustment, role, stale quantity, idempotency and held stock protection\n";
