<?php
require __DIR__.'/purchase-operations.php';
$cashAccount=tx(fn()=>finAction($d,['operation'=>'save_account','name'=>'测试现金','type'=>'cash','identifier'=>'','holder'=>'','active'=>1,'request_key'=>bin2hex(random_bytes(16))]))['id'];
function pay4($op,$party,$amount,$extra=[]){global $d,$cashAccount;return tx(fn()=>finAction($d,array_merge(['account_id'=>$cashAccount,'account_version'=>1,'operation'=>$op,'party_id'=>$party,'amount'=>$amount,'account'=>'测试银行','note'=>'四类账款隔离测试','request_key'=>bin2hex(random_bytes(16))],$extra)));}
$ap=finAuxBalance($d,'S','ap');expect($ap===12500,'采购入库、退货与换货形成正确应付');
$stable=finSnapshot($d);finSchema($d);expect(finAuxBalance($d,'S','ap')===$ap,'重复初始化不重复入账');
// Prepayments are independent of trade debt, and can exist before an order.
pay4('advance_payment','S','30');expect(finAuxBalance($d,'S','advance_payment')===3000,'供应商预付独立');expect(finAuxBalance($d,'S','ap')===$ap,'预付不自动减应付');
$offset=['operation'=>'offset_payment','party_id'=>'S','amount'=>'10','reference'=>$receipt,'note'=>'抵采购入库','request_key'=>bin2hex(random_bytes(16))];
tx(fn()=>finAction($d,$offset));expect(finAuxBalance($d,'S','ap')===$ap-1000,'预付抵应付');expect(finAuxBalance($d,'S','advance_payment')===2000,'预付减少');
expect(tx(fn()=>finAction($d,$offset))['repeated'],'抵扣重试幂等');denied(fn()=>finAction($d,array_replace($offset,['amount'=>'11'])));
$before=finSnapshot($d);denied(fn()=>finAction($d,array_replace($offset,['amount'=>'21','request_key'=>bin2hex(random_bytes(16))])));expect(finSnapshot($d)===$before,'超额抵扣完整回滚');
pay4('return_advance_payment','S','20');expect(finAuxBalance($d,'S','advance_payment')===0,'收回预付款');
try{pay4('advance_payment','C','1');throw new RuntimeException('Expected wrong party rejection');}catch(InvalidArgumentException $e){}
// Customer pre-receipts and offsets leave the supplier accounts intact.
pay4('advance_receipt','C','30');$ar=finBalance($d,'C');
pay4('offset_receipt','C','10',['reference'=>$sale]);expect(finBalance($d,'C')===$ar-1000,'预收抵销售应收');expect(finAuxBalance($d,'C','advance_receipt')===2000,'预收余额');
pay4('return_advance_receipt','C','20');expect(finAuxBalance($d,'C','advance_receipt')===0,'客户预收退回');
expect(finAuxBalance($d,'S','ap')===$ap-1000,'客户操作不串供应商账');
// Selected documents must belong to the same counterparty and have capacity.
iq($d,"INSERT INTO inv_parties VALUES('S2','supplier','另一供应商','','','',1,1)");
pay4('advance_payment','S2','20');
$cross=['operation'=>'offset_payment','party_id'=>'S2','amount'=>'1','reference'=>$receipt,'note'=>'跨供应商','request_key'=>bin2hex(random_bytes(16))];denied(fn()=>finAction($d,$cross));
$docBalance=finDocumentBalance($d,'S',$receipt,'supplier');
pay4('payment','S',number_format($docBalance/100,2,'.',''),['reference'=>$receipt]);
try{pay4('payment','S','0.01',['reference'=>$receipt]);throw new RuntimeException('Expected doc limit');}catch(InvalidArgumentException $e){}
// Fully paid receipt followed by a return produces a supplier refund receivable.
$pp4=doc('purchase',1,null,[],'PART');approve($pp4);$pr4=doc('receipt',1,$pp4,[],'PART');approve($pr4);
$all=finAuxBalance($d,'S','ap');pay4('payment','S',number_format(($all-2000)/100,2,'.',''));pay4('payment','S','20',['reference'=>$pr4]);expect(finAuxBalance($d,'S','ap')===0,'供应商结清');
$ret4=op('purchase_return',['product_id'=>'PART','source_line'=>value('SELECT id FROM inv_lines WHERE doc_id=?',[$pr4]),'refund_price'=>'20']);post($ret4);expect(finAuxBalance($d,'S','ap')===-2000,'已付退货形成待收供应商退款');
pay4('supplier_refund','S','20',['reference'=>$pr4]);expect(finAuxBalance($d,'S','ap')===0,'实际收到供应商退款');
// Missing new receipt prices cannot post stock without its payable.
$p5=doc('purchase',1,null,[],'PART');approve($p5);$r5=doc('receipt',1,$p5,[],'PART');iq($d,'UPDATE inv_lines SET price_cents=NULL WHERE doc_id=?',[$r5]);
$stock=value("SELECT qty FROM inv_balances WHERE product_id='PART' AND warehouse_id='W'");denied(fn()=>invTransition($d,['id'=>$r5,'transition'=>'approve','version'=>2]));expect(value("SELECT qty FROM inv_balances WHERE product_id='PART' AND warehouse_id='W'")===$stock,'缺单价审核库存回滚');
// Emulate upgrade of existing receipts: no invented historical liability.
$d->exec("DELETE FROM inv_meta WHERE k='finance_four_v1';DROP TABLE inv_fin_aux;DROP TABLE inv_fin_purchases;DROP TABLE inv_fin_requests;DROP TABLE inv_fin_links");finSchema($d);
expect(finAuxBalance($d,'S','ap')===0,'历史采购不自动造欠款');expect(finPending($d,'S','supplier'),'历史采购进入核对');
$hist=['operation'=>'confirm_purchase_history','doc_id'=>$receipt,'amount'=>'5','note'=>'凭票据确认剩余五元'];tx(fn()=>finAction($d,$hist));expect(finAuxBalance($d,'S','ap')===500,'只入账核实的未付金额');expect(tx(fn()=>finAction($d,$hist))['repeated'],'核对幂等');
try{pay4('payment','S','1');throw new RuntimeException('Expected pending history block');}catch(InvalidArgumentException $e){}
foreach(iq($d,"SELECT doc_id FROM inv_fin_purchases WHERE state='pending'")->fetchAll(PDO::FETCH_COLUMN) as $id)tx(fn()=>finAction($d,['operation'=>'confirm_purchase_history','doc_id'=>$id,'amount'=>'0','note'=>'已核实结清']));
pay4('payment','S','5');expect(finAuxBalance($d,'S','ap')===0,'历史核对后付款');
echo "PASS four balances: procurement accrual/returns/exchange, prepayments/pre-receipts/refunds/offsets, reference/party isolation, idempotency, atomic rollback, historical migration\n";
