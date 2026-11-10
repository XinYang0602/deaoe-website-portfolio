<?php
require __DIR__.'/sale-approval.php';
$d->exec("INSERT INTO inv_warehouses VALUES('W2','第二仓',1,1);INSERT INTO inv_balances VALUES('P','W2',1,0);INSERT INTO inv_sn VALUES('W2-SN',NULL,'P','W2','available',NULL,NULL,'now');");
function multi($qty){global $d;$d->beginTransaction();try{$r=invSaveDoc($d,['kind'=>'sale','warehouse_id'=>'W','party_id'=>'C','date'=>'2026-11-09','request_key'=>bin2hex(random_bytes(12)),'delivery_phone'=>'000123','delivery_contact'=>'测试收货人','delivery_address'=>'测试送货地址','lines'=>[['product_id'=>'P','warehouse_id'=>'W','qty'=>1,'price'=>'9.50'],['product_id'=>'P','warehouse_id'=>'W2','qty'=>$qty,'price'=>'8.00']]]);invTransition($d,['id'=>$r['id'],'transition'=>'submit','version'=>1]);$d->commit();return $r['id'];}catch(Throwable $e){$d->rollBack();throw $e;}}
$fail=multi(2);try{approve($fail);throw new RuntimeException('unexpected success');}catch(InvalidArgumentException $e){}
check(iq($d,"SELECT status FROM inv_sn WHERE sn='SN3'")->fetchColumn()==='available');check(iq($d,'SELECT COUNT(*) FROM inv_docs WHERE source_id=?',[$fail])->fetchColumn()==0);
$ok=multi(1);approve($ok);check(iq($d,'SELECT COUNT(*) FROM inv_docs WHERE source_id=?',[$ok])->fetchColumn()==2);
check(iq($d,"SELECT status FROM inv_sn WHERE sn='W2-SN'")->fetchColumn()==='sold');
$d->exec("UPDATE inv_parties SET address='已改客户地址' WHERE id='C'");
check(iq($d,'SELECT address FROM inv_delivery WHERE doc_id=?',[$ok])->fetchColumn()==='测试送货地址');
check(iq($d,'SELECT COUNT(*) FROM inv_delivery x JOIN inv_docs d ON d.id=x.doc_id WHERE d.source_id=? AND x.address=?',[$ok,'测试送货地址'])->fetchColumn()==2);
check(approve($ok)['repeated']);
$issue=iq($d,"SELECT id FROM inv_docs WHERE source_id=? AND warehouse_id='W2'",[$ok])->fetchColumn();
$line=iq($d,'SELECT id FROM inv_lines WHERE doc_id=?',[$issue])->fetchColumn();
$d->beginTransaction();$ret=p2Create($d,['kind'=>'sales_return','request_key'=>bin2hex(random_bytes(12)),'body'=>['warehouse_id'=>'W2','product_id'=>'P','qty'=>1,'note'=>'测试','source_line'=>$line,'serials'=>['W2-SN'],'refund_price'=>'8.00']]);p2Step($d,['id'=>$ret['id'],'transition'=>'submit','version'=>1]);p2Step($d,['id'=>$ret['id'],'transition'=>'approve','version'=>2]);$d->commit();
check(iq($d,"SELECT status FROM inv_sn WHERE sn='W2-SN'")->fetchColumn()==='quarantine');
echo "PASS multiwarehouse: same SKU across warehouses, atomic stock failure, separate issues, address snapshots, idempotency, warehouse-specific return\n";
$d->exec("INSERT INTO inv_balances VALUES('PART','W2',1,0)");p2LotAdd($d,'PART','W2','available',1,'TEST',100,'TEST');
$d->beginTransaction();$parts=invSaveDoc($d,['kind'=>'sale','warehouse_id'=>'W','party_id'=>'C','date'=>'2026-11-09','request_key'=>bin2hex(random_bytes(12)),'lines'=>[['product_id'=>'PART','warehouse_id'=>'W','qty'=>1,'price'=>'3.00'],['product_id'=>'PART','warehouse_id'=>'W2','qty'=>1,'price'=>'4.00']]]);invTransition($d,['id'=>$parts['id'],'transition'=>'submit','version'=>1]);$d->commit();approve($parts['id']);
check(iq($d,"SELECT SUM(qty) FROM inv_lots WHERE product_id='PART' AND warehouse_id='W2'")->fetchColumn()==0);
check(iq($d,'SELECT SUM(qty*price_cents) FROM inv_lines WHERE doc_id=?',[$parts['id']])->fetchColumn()==700);
echo "PASS multiwarehouse non-SN lots and sales amount\n";
