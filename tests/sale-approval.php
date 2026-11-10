<?php
require (getenv('DEAOE_TEST_INVENTORY') ?: __DIR__.'/../src/service/inventory.php');
function check($ok){if(!$ok)throw new RuntimeException('assertion failed');}
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);p2Schema($d);
$d->exec("INSERT INTO inv_warehouses VALUES('W','仓库',1,1); INSERT INTO inv_parties VALUES('C','customer','测试','','','',1,1);
INSERT INTO inv_products VALUES('P','P','测试','整机','德澳','','台',1,NULL,NULL,1,'',1);
INSERT INTO inv_balances VALUES('P','W',3,0);
INSERT INTO inv_sn VALUES('SN1',NULL,'P','W','available',NULL,NULL,'now'),('SN2',NULL,'P','W','available',NULL,NULL,'now'),('SN3',NULL,'P','W','available',NULL,NULL,'now');");
function sale($sns,$qty){global $d;$d->beginTransaction();$r=invSaveDoc($d,['kind'=>'sale','warehouse_id'=>'W','party_id'=>'C','date'=>'2026-11-08','request_key'=>bin2hex(random_bytes(12)),'lines'=>[['product_id'=>'P','qty'=>$qty,'price'=>'12.50','serials'=>array_map(fn($s)=>['sn'=>$s],$sns)]]]);invTransition($d,['id'=>$r['id'],'transition'=>'submit','version'=>1]);$d->commit();return $r['id'];}
function approve($id){global $d;$d->beginTransaction();try{$r=invTransition($d,['id'=>$id,'transition'=>'approve','version'=>2]);$d->commit();return $r;}catch(Throwable $e){$d->rollBack();throw $e;}}
$id=sale([],1);approve($id);check($d->query("SELECT qty FROM inv_balances")->fetchColumn()==2);check(approve($id)['repeated']);check($d->query("SELECT COUNT(*) FROM inv_ledger")->fetchColumn()==1);check($d->query("SELECT issue_line FROM inv_sn WHERE sn='SN1'")->fetchColumn());
// Stale manually entered SN must not block approval of an existing submitted sale.
$stale=sale(['MISSING'],1);
iq($d,'UPDATE inv_lines SET serials=? WHERE doc_id=?',[json_encode([['sn'=>'12313']]),$stale]);
approve($stale);
check(iq($d,"SELECT status FROM inv_sn WHERE sn='SN2'")->fetchColumn()==='sold');
$bad=sale([],2);
try{approve($bad);throw new RuntimeException('unexpected success');}catch(InvalidArgumentException $e){}
check(iq($d,'SELECT status FROM inv_docs WHERE id=?',[$bad])->fetchColumn()==='submitted');
check($d->query("SELECT qty FROM inv_balances")->fetchColumn()==1);
check($d->query("SELECT COUNT(*) FROM inv_docs WHERE kind='issue'")->fetchColumn()==2);
echo "PASS: quantity-only approval, stale SN ignored, idempotency, insufficient stock rollback\n";
// Non-serial parts deduct their available lot, and legacy approved sales do not repost.
$d->exec("INSERT INTO inv_products VALUES('PART','PART','配件','配件','德澳','','件',0,NULL,NULL,1,'',1);INSERT INTO inv_balances VALUES('PART','W',5,0)");
p2LotAdd($d,'PART','W','available',5,'TEST',100,'TEST');
$d->beginTransaction();$part=invSaveDoc($d,['kind'=>'sale','warehouse_id'=>'W','party_id'=>'C','date'=>'2026-11-08','request_key'=>bin2hex(random_bytes(12)),'lines'=>[['product_id'=>'PART','qty'=>2,'price'=>'3.00','serials'=>[]]]]);invTransition($d,['id'=>$part['id'],'transition'=>'submit','version'=>1]);$d->commit();approve($part['id']);check(iq($d,"SELECT qty FROM inv_balances WHERE product_id='PART'")->fetchColumn()==3);check(iq($d,"SELECT SUM(qty) FROM inv_lots WHERE product_id='PART'")->fetchColumn()==3);
$legacy=sale(['SN3'],1);iq($d,"UPDATE inv_docs SET status='approved' WHERE id=?",[$legacy]);check(approve($legacy)['repeated']);check(iq($d,"SELECT status FROM inv_sn WHERE sn='SN3'")->fetchColumn()==='available');echo "PASS: non-SN lot deduction and legacy approval preservation\n";
