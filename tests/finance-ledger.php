<?php
require __DIR__.'/purchase-operations.php';
$cashAccount=tx(fn()=>finAction($d,['operation'=>'save_account','name'=>'测试现金','type'=>'cash','identifier'=>'','holder'=>'','active'=>1,'request_key'=>bin2hex(random_bytes(16))]))['id'];
expect(finBalance($d,'C')===2000,'销售应收且换货不改变金额');
$pay=['account_id'=>$cashAccount,'account_version'=>1,'operation'=>'receipt','party_id'=>'C','amount'=>'5.00','account'=>'测试现金','note'=>'测试收款','request_key'=>bin2hex(random_bytes(16))];
tx(fn()=>finAction($d,$pay));expect(finBalance($d,'C')===1500,'部分收款');
expect(tx(fn()=>finAction($d,$pay))['repeated'],'重复收款幂等');
denied(fn()=>finAction($d,array_replace($pay,['amount'=>'16','request_key'=>bin2hex(random_bytes(16))])));
// New ordinary part sale and refund, including actual refund payout.
$s2=doc('sale',1,null,[],'PART');approve($s2);expect(finBalance($d,'C')===3500,'新销售自动入账');
$i2=value("SELECT id FROM inv_docs WHERE source_id=? AND kind='issue'",[$s2]);
$r2=op('sales_return',['product_id'=>'PART','source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$i2]),'refund_price'=>'20','return_mode'=>'refund']);
post($r2);expect(finBalance($d,'C')===1500,'退款退货冲减');post($r2);expect(finBalance($d,'C')===1500,'重复退货不重复记账');
$bad=op('sales_exchange',['product_id'=>'PART','return_id'=>$r2,'exchange_price'=>'20','serials'=>[]]);denied(fn()=>p2Step($d,['id'=>$bad,'transition'=>'approve','version'=>2]));
$s3=doc('sale',1,null,[],'PART');approve($s3);
tx(fn()=>finAction($d,array_replace($pay,['amount'=>'35','request_key'=>bin2hex(random_bytes(16))])));expect(finBalance($d,'C')===0,'结清');
$i3=value("SELECT id FROM inv_docs WHERE source_id=? AND kind='issue'",[$s3]);
$r3=op('sales_return',['product_id'=>'PART','source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$i3]),'refund_price'=>'20','return_mode'=>'refund']);post($r3);expect(finBalance($d,'C')===-2000,'已付款退货形成应退');
tx(fn()=>finAction($d,array_replace($pay,['operation'=>'refund','amount'=>'20','request_key'=>bin2hex(random_bytes(16))])));expect(finBalance($d,'C')===0,'实际退款结清');
// Rebuild only finance state to emulate an old database's first upgrade.
$d->exec("DROP TABLE inv_fin_sales;DROP TABLE inv_fin_entries;DELETE FROM inv_meta WHERE k='finance_v1'");finSchema($d);
expect(finBalance($d,'C')===0,'历史销售不自动形成欠款');
expect(value("SELECT COUNT(*) FROM inv_fin_sales WHERE state='pending'")==3,'历史销售待核对');
$history=['operation'=>'confirm_history','sale_id'=>$sale,'amount'=>'7','note'=>'核实旧单剩余7元'];
tx(fn()=>finAction($d,$history));expect(finBalance($d,'C')===700,'只计确认欠款');
tx(fn()=>finAction($d,$history));expect(finBalance($d,'C')===700,'历史确认幂等');
denied(fn()=>finAction($d,array_replace($pay,['amount'=>'1','request_key'=>bin2hex(random_bytes(16))])));
foreach([$s2,$s3] as $sid)tx(fn()=>finAction($d,['operation'=>'confirm_history','sale_id'=>$sid,'amount'=>'0','note'=>'已结清']));
finSchema($d);expect(value("SELECT COUNT(*) FROM inv_fin_sales WHERE state='pending'")==0,'迁移重复运行不覆盖核对');
tx(fn()=>finAction($d,array_replace($pay,['amount'=>'7','request_key'=>bin2hex(random_bytes(16))])));expect(finBalance($d,'C')===0,'历史核对后收款');
echo "PASS finance: migration, history reconciliation, sale accrual, partial receipt, refund, replacement neutrality, idempotency and limits\n";
