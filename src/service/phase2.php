<?php
declare(strict_types=1);
// Invoked by inventory.php; every mutation runs under its BEGIN IMMEDIATE transaction.
function p2Schema(PDO $d):void{
 $d->exec("CREATE TABLE IF NOT EXISTS inv_ops(id TEXT PRIMARY KEY,kind TEXT NOT NULL,state TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,body TEXT NOT NULL,snapshot TEXT NOT NULL DEFAULT '{}',request_key TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_lots(id TEXT PRIMARY KEY,product_id TEXT NOT NULL,warehouse_id TEXT NOT NULL,zone TEXT NOT NULL,qty INTEGER NOT NULL CHECK(qty>=0),origin TEXT NOT NULL,price_cents INTEGER);
 CREATE TABLE IF NOT EXISTS inv_lot_moves(id INTEGER PRIMARY KEY,doc_id TEXT NOT NULL,lot_id TEXT NOT NULL,delta INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_allocations(line_id TEXT PRIMARY KEY,body TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_holds(product_id TEXT NOT NULL,warehouse_id TEXT NOT NULL,zone TEXT NOT NULL,qty INTEGER NOT NULL CHECK(qty>=0),PRIMARY KEY(product_id,warehouse_id,zone));
 CREATE TABLE IF NOT EXISTS inv_return_lines(op_id TEXT PRIMARY KEY,source_line TEXT NOT NULL,qty INTEGER NOT NULL,serials TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_exchange_links(op_id TEXT PRIMARY KEY,return_id TEXT NOT NULL,old_sn TEXT NOT NULL,new_sn TEXT NOT NULL,qty INTEGER NOT NULL,warranty TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_freezes(warehouse_id TEXT PRIMARY KEY,op_id TEXT NOT NULL UNIQUE);
 CREATE TABLE IF NOT EXISTS inv_label_profiles(product_id TEXT PRIMARY KEY,version INTEGER NOT NULL,body TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_print_jobs(id TEXT PRIMARY KEY,body TEXT NOT NULL,created_at TEXT NOT NULL);");
}
function p2Migrate(PDO $d):array{
 p2Schema($d);$d->exec('BEGIN IMMEDIATE');try{
 if(iq($d,"SELECT v FROM inv_meta WHERE k='phase2'")->fetchColumn()){$d->exec('COMMIT');return ['already'=>true];}
 // Historical non-SN residual cannot be assigned to a specific batch without evidence.
 foreach($d->query('SELECT b.* FROM inv_balances b JOIN inv_products p ON p.id=b.product_id WHERE p.sn_enabled=0 AND b.qty>0')->fetchAll(PDO::FETCH_ASSOC) as $b)p2LotAdd($d,$b['product_id'],$b['warehouse_id'],'available',(int)$b['qty'],'一期结余待核实价格',null,'P2-MIGRATION');
 iq($d,"INSERT INTO inv_meta VALUES('phase2','1')");ilog($d,'migration','phase2');$d->exec('COMMIT');return ['migrated'=>true];
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function p2Ready(PDO $d):bool{return (bool)$d->query("SELECT 1 FROM sqlite_master WHERE name='inv_ops'")->fetchColumn();}
function p2Unlocked(PDO $d,string $wh,?string $own=null):void{if(!p2Ready($d))return;$f=iq($d,'SELECT op_id FROM inv_freezes WHERE warehouse_id=?',[$wh])->fetchColumn();if($f&&$f!==$own)iv('该仓库正在盘点，库存已冻结：'.$f);}
function p2Hold(PDO $d,string $p,string $w):int{return (int)iq($d,'SELECT COALESCE(SUM(qty),0) FROM inv_holds WHERE product_id=? AND warehouse_id=?',[$p,$w])->fetchColumn();}
function p2LotAdd(PDO $d,string $p,string $w,string $zone,int $qty,string $origin,?int $price,string $doc):string{
 $id=iid('LOT');iq($d,'INSERT INTO inv_lots VALUES(?,?,?,?,?,?,?)',[$id,$p,$w,$zone,$qty,$origin,$price]);iq($d,'INSERT INTO inv_lot_moves(doc_id,lot_id,delta) VALUES(?,?,?)',[$doc,$id,$qty]);return $id;
}
function p2LotsTake(PDO $d,string $p,string $w,string $zone,int $qty,array $alloc,string $doc):array{
 if(!$alloc){$lots=iq($d,'SELECT * FROM inv_lots WHERE product_id=? AND warehouse_id=? AND zone=? AND qty>0',[$p,$w,$zone])->fetchAll(PDO::FETCH_ASSOC);if(count($lots)!==1)iv('请选择来源批次及数量；不能自动推定 FIFO 或平均价');$alloc=[['lot_id'=>$lots[0]['id'],'qty'=>$qty]];}
 $sum=0;$taken=[];foreach($alloc as $a){$n=ii($a['qty']??null);$l=iq($d,'SELECT * FROM inv_lots WHERE id=? AND product_id=? AND warehouse_id=? AND zone=?',[$a['lot_id']??'',$p,$w,$zone])->fetch(PDO::FETCH_ASSOC);if(!$l||(int)$l['qty']<$n)iv('批次数量不足或不属于本仓分区');iq($d,'UPDATE inv_lots SET qty=qty-? WHERE id=?',[$n,$l['id']]);iq($d,'INSERT INTO inv_lot_moves(doc_id,lot_id,delta) VALUES(?,?,?)',[$doc,$l['id'],-$n]);$taken[]=['origin'=>$l['origin'],'price_cents'=>$l['price_cents'],'qty'=>$n,'lot_id'=>$l['id']];$sum+=$n;}if($sum!==$qty)iv('批次分配合计须等于业务数量');return $taken;
}
function p2Balance(PDO $d,string $p,string $w,string $zone,int $delta,string $doc,?int $price=null):void{
 iq($d,'INSERT OR IGNORE INTO inv_balances VALUES(?,?,0,0)',[$p,$w]);$b=iq($d,'SELECT * FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$p,$w])->fetch(PDO::FETCH_ASSOC);
 $available=(int)$b['qty']-(int)$b['unidentified']-p2Hold($d,$p,$w);
 if($delta<0&&$zone==='available'&&$available<-$delta)iv('可售库存不足');
 if($zone!=='available'){iq($d,'INSERT OR IGNORE INTO inv_holds VALUES(?,?,?,0)',[$p,$w,$zone]);$q=iq($d,'UPDATE inv_holds SET qty=qty+? WHERE product_id=? AND warehouse_id=? AND zone=? AND qty+?>=0',[$delta,$p,$w,$zone,$delta]);if(!$q->rowCount())iv('分区库存不足');}
 iq($d,'UPDATE inv_balances SET qty=qty+? WHERE product_id=? AND warehouse_id=?',[$delta,$p,$w]);iq($d,'INSERT INTO inv_ledger(doc_id,line_id,product_id,warehouse_id,delta,price_cents,created_at) VALUES(?,?,?,?,?,?,?)',[$doc,iid('FLOW'),$p,$w,$delta,$price,gmdate('c')]);
}
function p2SNMove(PDO $d,array $sns,string $p,string $from,string $old,string $to,string $new,string $doc):array{
 $out=[];foreach($sns as $sn){$sn=invSN($sn);$s=iq($d,'SELECT * FROM inv_sn WHERE sn=? AND product_id=? AND warehouse_id=? AND status=?',[$sn,$p,$from,$old])->fetch(PDO::FETCH_ASSOC);if(!$s)iv('SN 状态/商品/仓库不符或重复：'.$sn);iq($d,'UPDATE inv_sn SET warehouse_id=?,status=? WHERE sn=?',[$to,$new,$sn]);iq($d,'INSERT INTO inv_sn_events(sn,doc_id,status,warehouse_id,created_at) VALUES(?,?,?,?,?)',[$sn,$doc,$new,$to,gmdate('c')]);$out[]=$s;}return $out;
}
function p2V1Post(PDO $d,array $doc,array $line,array $serials):void{
 if(!p2Ready($d))return;p2Unlocked($d,$doc['warehouse_id']);$p=$line['product_id'];$w=$doc['warehouse_id'];$n=(int)$line['qty'];
 if($doc['kind']==='issue'){$b=iq($d,'SELECT * FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$p,$w])->fetch(PDO::FETCH_ASSOC);if(!$b||(int)$b['qty']-(int)$b['unidentified']-p2Hold($d,$p,$w)<$n)iv('可售库存不足，待检/不良库存不可出库');}
 if(!$line['sn_enabled']){if($doc['kind']==='receipt')p2LotAdd($d,$p,$w,'available',$n,$line['id'],$line['price_cents'],$doc['id']);else{$a=json_decode(iq($d,'SELECT body FROM inv_allocations WHERE line_id=?',[$line['id']])->fetchColumn()?:'[]',true);$taken=p2LotsTake($d,$p,$w,'available',$n,$a,$doc['id']);iq($d,'INSERT OR REPLACE INTO inv_allocations VALUES(?,?)',[$line['id'],json_encode($taken)]);}}
}
function p2Create(PDO $d,array $a):array{
 $kind=it($a['kind']??'');if(!in_array($kind,['purchase_return','sales_return','transfer','inspection','count','purchase_exchange','sales_exchange']))iv('业务类型无效');
 $key=it($a['request_key']??'',80);if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))iv('请求编号缺失');$old=iq($d,'SELECT id FROM inv_ops WHERE request_key=?',[$key])->fetchColumn();if($old)return ['id'=>$old,'repeated'=>true];
 $b=$a['body']??null;if(!is_array($b))iv('业务内容错误');$w=it($b['warehouse_id']??'');p2Warehouse($d,$w);p2Unlocked($d,$w);$note=it($b['note']??'',1000);if(!$note)iv('请填写业务原因/依据');$b['note']=$note;
 $id=invReservedDocument($d,$a,['purchase_return'=>'CGTH','sales_return'=>'XSTH','transfer'=>'DB','inspection'=>'ZJ','count'=>'PD','purchase_exchange'=>'CGHH','sales_exchange'=>'XSHH'][$kind]);$snap=[];if($kind==='count'){
 if(iq($d,"SELECT 1 FROM inv_ops WHERE state='transit' AND (json_extract(body,'$.warehouse_id')=? OR json_extract(body,'$.to_warehouse')=?)",[$w,$w])->fetchColumn())iv('该仓库有在途调拨，请先收货再盘点');
 $snap['balances']=iq($d,'SELECT b.*,p.sn_enabled FROM inv_balances b JOIN inv_products p ON p.id=b.product_id WHERE warehouse_id=?',[$w])->fetchAll(PDO::FETCH_ASSOC);$snap['sn']=iq($d,"SELECT sn,product_id FROM inv_sn WHERE warehouse_id=? AND status='available'",[$w])->fetchAll(PDO::FETCH_ASSOC);foreach($snap['balances'] as &$x)$x['available']=(int)$x['qty']-(int)$x['unidentified']-p2Hold($d,$x['product_id'],$w);unset($x);iq($d,'INSERT INTO inv_freezes VALUES(?,?)',[$w,$id]);
 }else{$p=it($b['product_id']??'');if(!iq($d,'SELECT 1 FROM inv_products WHERE id=? AND active=1',[$p])->fetchColumn())iv('商品无效');ii($b['qty']??null,2000);}
 iq($d,"INSERT INTO inv_ops(id,kind,state,body,snapshot,request_key,created_at) VALUES(?,?,'draft',?,?,?,?)",[$id,$kind,json_encode($b,JSON_UNESCAPED_UNICODE),json_encode($snap),$key,gmdate('c')]);ilog($d,'phase2.create',$id,['kind'=>$kind]);return ['id'=>$id];
}
function p2Warehouse(PDO $d,string $w):void{if($w==='LEGACY'||!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$w])->fetchColumn())iv('请选择有效实际仓库');}
function p2Step(PDO $d,array $a):array{
 $id=it($a['id']??'');$op=iq($d,'SELECT * FROM inv_ops WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$op)iv('业务不存在');$act=it($a['transition']??'');$state=$op['state'];if(($act==='approve'&&in_array($state,['approved','transit']))||($act==='receive'&&$state==='approved'))return ['id'=>$id,'repeated'=>true];if(($a['version']??null)!==(int)$op['version'])iv('业务已变化，请刷新');$b=json_decode($op['body'],true);$snap=json_decode($op['snapshot'],true);$kind=$op['kind'];$w=$b['warehouse_id'];
 if($act==='edit'){
 if(!in_array($state,['draft','rejected']))iv('仅草稿/驳回可编辑');$new=$a['body']??null;if(!is_array($new)||($new['warehouse_id']??'')!==$w)iv('不能更换仓库');if($kind==='count'){$b['counts']=$new['counts']??[];$b['note']=it($new['note']??'',1000);}else{$b=$new;ii($b['qty']??null,2000);if(!it($b['note']??'',1000))iv('原因不能为空');} $next='draft';
 }elseif($act==='submit'&&in_array($state,['draft','rejected']))$next='submitted';
 elseif($act==='reject'&&$state==='submitted'){$next='rejected';$b['review_reason']=it($a['reason']??'',1000);if(!$b['review_reason'])iv('请填写驳回原因');}
 elseif($act==='void'&&in_array($state,['draft','rejected'])){$next='void';$b['review_reason']=it($a['reason']??'',1000);if(!$b['review_reason'])iv('请填写作废原因');iq($d,'DELETE FROM inv_freezes WHERE op_id=?',[$id]);}
 elseif(($act==='approve'&&$state==='submitted')||($act==='receive'&&$state==='transit')){
 p2Warehouse($d,$w);p2Unlocked($d,$w,$id);$next='approved';
 if($kind==='count')p2CountPost($d,$id,$b,$snap);
 else{
 $p=it($b['product_id']??'');$product=iq($d,'SELECT * FROM inv_products WHERE id=? AND active=1',[$p])->fetch(PDO::FETCH_ASSOC);if(!$product)iv('商品已停用');$n=ii($b['qty']??null,2000);$sns=$b['serials']??[];if(!is_array($sns)||!array_is_list($sns))iv('SN 列表无效');$sns=array_map(fn($s)=>invSN($s),$sns);if($kind==='purchase_exchange'&&$product['sn_enabled']&&!$sns)$sns=array_map(fn()=>iid('DA'),range(1,$n));if($product['sn_enabled']&&!$sns&&in_array($kind,['sales_return','purchase_return','sales_exchange'])){
 $b['quantity_only']=true;
 $status=$kind==='sales_return'?'sold':($kind==='purchase_return'?it($b['zone']??'available'):'available');
 $sql='SELECT s.sn FROM inv_sn s WHERE s.product_id=? AND s.warehouse_id=? AND s.status=?';$args=[$p,$w,$status];
 if($kind!=='sales_exchange'){$col=$kind==='sales_return'?'issue_line':'receipt_line';$sql.=' AND s.'.$col.'=? AND NOT EXISTS(SELECT 1 FROM inv_return_lines r,json_each(r.serials) j WHERE r.source_line=? AND j.value=s.sn)';$args[]=$b['source_line']??'';$args[]=$b['source_line']??'';}
 $sns=iq($d,$sql.' ORDER BY s.sn LIMIT '.(int)$n,$args)->fetchAll(PDO::FETCH_COLUMN);
 if(count($sns)!==$n)iv('原单或仓库剩余可处理数量不足');
 }
 if(count(array_unique($sns))!==count($sns))iv('SN 重复');$b['serials']=$sns;if($product['sn_enabled']&&count($sns)!==$n)iv('SN 数量须等于业务数量');if(!$product['sn_enabled']&&$sns)iv('数量商品不填写 SN');$alloc=$b['allocations']??[];if(!is_array($alloc))iv('批次分配格式错误');
 if($kind==='transfer'){
 $to=it($b['to_warehouse']??'');p2Warehouse($d,$to);p2Unlocked($d,$to);if($to===$w)iv('调出和调入仓库不能相同');
 if($act==='approve'){$next='transit';if($product['sn_enabled'])$snap['sn']=p2SNMove($d,$sns,$p,$w,'available',$w,'transit',$id);else $snap['lots']=p2LotsTake($d,$p,$w,'available',$n,$alloc,$id);p2Balance($d,$p,$w,'available',-$n,$id);}
 else{if($product['sn_enabled'])p2SNMove($d,$sns,$p,$w,'transit',$to,'available',$id);else foreach($snap['lots'] as $l)p2LotAdd($d,$p,$to,'available',$l['qty'],$l['origin'],$l['price_cents'],$id);p2Balance($d,$p,$to,'available',$n,$id);}
 }elseif(in_array($kind,['purchase_return','sales_return'])){
 $source=it($b['source_line']??'');$line=iq($d,"SELECT l.*,x.kind,x.status,x.warehouse_id,x.party_id FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.id=?",[$source])->fetch(PDO::FETCH_ASSOC);if(!$line||$line['status']!=='approved'||$line['kind']!==($kind==='purchase_return'?'receipt':'issue')||$line['product_id']!==$p)iv('请关联原入库/出库明细');if($line['warehouse_id']!==$w)iv('退货请先选原单仓库');
 $used=(int)iq($d,'SELECT COALESCE(SUM(qty),0) FROM inv_return_lines WHERE source_line=?',[$source])->fetchColumn();if($used+$n>(int)$line['qty'])iv('累计退货超过原单数量');$price=money($b['refund_price']??'');if($price===null)iv('请填写本次退货单价');$b['refund_cents']=$price*$n;$b['party_id']=$line['party_id'];$b['settlement']=$b['settlement']??'pending';if(!in_array($b['settlement'],['pending','refunded','credited']))iv('结算状态无效');
 if($product['sn_enabled'])foreach($sns as $sn){$s=iq($d,'SELECT * FROM inv_sn WHERE sn=?',[$sn])->fetch(PDO::FETCH_ASSOC);if(!$s||$s[$kind==='purchase_return'?'receipt_line':'issue_line']!==$source)iv('SN 不属于原单');if(iq($d,"SELECT 1 FROM inv_return_lines WHERE source_line=? AND EXISTS(SELECT 1 FROM json_each(serials) WHERE value=?)",[$source,$sn])->fetchColumn())iv('该原单 SN 已退货');}
 if($kind==='purchase_return'){$zone=it($b['zone']??'available');if(!in_array($zone,['available','quarantine','defective']))iv('退货分区无效');if($product['sn_enabled'])p2SNMove($d,$sns,$p,$w,$zone,$w,'returned_supplier',$id);else{$taken=p2LotsTake($d,$p,$w,$zone,$n,$alloc?:p2AutoLots($d,$p,$w,$zone,$n,$source),$id);foreach($taken as $l)if($l['origin']!==$source)iv('批次并非该原入库单；一期结余需先核实批次');$snap['lots']=$taken;}p2Balance($d,$p,$w,$zone,-$n,$id,$price);}
 else{if($product['sn_enabled'])p2SNMove($d,$sns,$p,$w,'sold',$w,'quarantine',$id);else{ $original=json_decode(iq($d,'SELECT body FROM inv_allocations WHERE line_id=?',[$source])->fetchColumn()?:'[]',true);if(!$original)p2LotAdd($d,$p,$w,'quarantine',$n,'一期销售退货原批次未记录',null,$id);else{ // Explicit original lot allocation, limited across prior returns.
 $requested=$alloc;if(!$requested){$left=$n;foreach($original as $lot){$prev=0;foreach(iq($d,'SELECT o.snapshot FROM inv_ops o JOIN inv_return_lines r ON r.op_id=o.id WHERE r.source_line=?',[$source])->fetchAll(PDO::FETCH_COLUMN) as $js)foreach(json_decode($js,true)['lots']??[] as $l)if($l['lot_id']===$lot['lot_id'])$prev+=$l['qty'];$take=min($left,max(0,$lot['qty']-$prev));if($take)$requested[]=['lot_id'=>$lot['lot_id'],'qty'=>$take];$left-=$take;if(!$left)break;}if($left)iv('原单剩余可退批次数量不足');}$sum=0;$seenLots=[];foreach($requested as $r){if(isset($seenLots[$r['lot_id']??'']))iv('退货批次不能重复');$seenLots[$r['lot_id']??'']=true;$match=null;foreach($original as $l)if($l['lot_id']===$r['lot_id'])$match=$l;if(!$match)iv('请选择原销售实际出库批次');$q=ii($r['qty']??null);$prev=0;foreach(iq($d,"SELECT o.snapshot FROM inv_ops o JOIN inv_return_lines r ON r.op_id=o.id WHERE r.source_line=?",[$source])->fetchAll(PDO::FETCH_COLUMN) as $js)foreach(json_decode($js,true)['lots']??[] as $l)if($l['lot_id']===$r['lot_id'])$prev+=$l['qty'];if($prev+$q>$match['qty'])iv('超过原销售批次剩余可退数量');p2LotAdd($d,$p,$w,'quarantine',$q,$match['origin'],$match['price_cents'],$id);$snap['lots'][]=[...$match,'qty'=>$q];$sum+=$q;}if($sum!==$n)iv('退货批次合计不符');}}
 p2Balance($d,$p,$w,'quarantine',$n,$id,$price);}
 iq($d,'INSERT INTO inv_return_lines VALUES(?,?,?,?)',[$id,$source,$n,json_encode($sns)]);
 }elseif($kind==='inspection'){
 $zone=it($b['result_zone']??'');if(!in_array($zone,['available','defective']))iv('验收结果只能为可售或不良');if($product['sn_enabled'])p2SNMove($d,$sns,$p,$w,'quarantine',$w,$zone,$id);else foreach(p2LotsTake($d,$p,$w,'quarantine',$n,$alloc,$id) as $l)p2LotAdd($d,$p,$w,$zone,$l['qty'],$l['origin'],$l['price_cents'],$id);p2Balance($d,$p,$w,'quarantine',-$n,$id);p2Balance($d,$p,$w,$zone,$n,$id);
 }elseif(in_array($kind,['purchase_exchange','sales_exchange']))p2Exchange($d,$id,$kind,$b,$snap,$product);
 }
 }elseif($act==='settlement'&&$state==='approved'&&in_array($kind,['purchase_return','sales_return','purchase_exchange','sales_exchange'])){iv('实际收付款请在账款管理登记，不能只修改结算标记');}
 else iv('当前状态不支持此操作');
 if($act==='approve'&&$next==='approved')finOperation($d,$id,$kind,$b);
 iq($d,'UPDATE inv_ops SET state=?,version=version+1,body=?,snapshot=? WHERE id=?',[$next,json_encode($b,JSON_UNESCAPED_UNICODE),json_encode($snap,JSON_UNESCAPED_UNICODE),$id]);ilog($d,'phase2.'.$act,$id,['kind'=>$kind]);return ['id'=>$id];
}
function p2Exchange(PDO $d,string $id,string $kind,array &$b,array &$snap,array $product):void{
 $return=iq($d,"SELECT * FROM inv_ops WHERE id=? AND state='approved'",[$b['return_id']??''])->fetch(PDO::FETCH_ASSOC);if(!$return||$return['kind']!==($kind==='purchase_exchange'?'purchase_return':'sales_return'))iv('请选择已审核的对应退货单');$rb=json_decode($return['body'],true);$n=(int)$b['qty'];$used=(int)iq($d,'SELECT COALESCE(SUM(qty),0) FROM inv_exchange_links WHERE return_id=?',[$return['id']])->fetchColumn();if($used+$n>(int)$rb['qty'])iv('超过退货单尚未换货数量');$w=$b['warehouse_id'];$p=$b['product_id'];$price=money($b['exchange_price']??'');if($price===null)iv('请填写换入/换出单价');$old=$b['old_serials']??[];if(!is_array($old))iv('原 SN 格式错误');$old=array_map(fn($s)=>invSN($s),$old);$oldProduct=iq($d,'SELECT * FROM inv_products WHERE id=?',[$rb['product_id']])->fetch(PDO::FETCH_ASSOC);if((bool)$oldProduct['sn_enabled']!==(bool)$product['sn_enabled'])iv('换货新旧商品需采用相同 SN 模式');if($product['sn_enabled']){
 if(!$old){$b['quantity_only']=true;$usedSN=[];foreach(iq($d,'SELECT old_sn FROM inv_exchange_links WHERE return_id=?',[$return['id']])->fetchAll(PDO::FETCH_COLUMN) as $js)$usedSN=array_merge($usedSN,json_decode($js,true));$old=array_slice(array_values(array_diff($rb['serials'],$usedSN)),0,$n);$b['old_serials']=$old;}
 if(count($old)!==$n||count(array_unique($old))!==$n)iv('请逐台选择退货单中的原 SN');foreach($old as $sn){if(!in_array($sn,$rb['serials'],true))iv('原 SN 不属于退货单');foreach(iq($d,'SELECT old_sn FROM inv_exchange_links WHERE return_id=?',[$return['id']])->fetchAll(PDO::FETCH_COLUMN) as $js)if(in_array($sn,json_decode($js,true),true))iv('原 SN 已换货');}}
 $line=iid('L');$docKind=$kind==='purchase_exchange'?'receipt':'issue';iq($d,"INSERT INTO inv_docs(id,kind,status,warehouse_id,party_id,date,note,created_at,approved_at) VALUES(?,?,'approved',?,?,?,?,?,?)",[$id,$docKind,$w,$rb['party_id'],gmdate('Y-m-d'),'换货关联 '.$return['id'],gmdate('c'),gmdate('c')]);$serials=$b['serials'];iq($d,'INSERT INTO inv_lines VALUES(?,?,?,?,?,NULL,?)',[$line,$id,$p,$n,$price,json_encode(array_map(fn($s)=>['sn'=>$s,'manufacturer_sn'=>''],$serials))]);
 if($kind==='purchase_exchange'){
 if($product['sn_enabled']){foreach($serials as $sn)invNewSN($d,$sn,'',$p,$w,$line,$id);}else p2LotAdd($d,$p,$w,'available',$n,$line,$price,$id);p2Balance($d,$p,$w,'available',$n,$id,$price);
 }else{
 if($product['sn_enabled']){p2SNMove($d,$serials,$p,$w,'available',$w,'sold',$id);foreach($serials as $sn)iq($d,'UPDATE inv_sn SET issue_line=? WHERE sn=?',[$line,$sn]);}else{$taken=p2LotsTake($d,$p,$w,'available',$n,($b['allocations']??[])?:p2AutoLots($d,$p,$w,'available',$n),$id);iq($d,'INSERT INTO inv_allocations VALUES(?,?)',[$line,json_encode($taken)]);}p2Balance($d,$p,$w,'available',-$n,$id,$price);
 }
 $b['difference_cents']=$price*$n-(int)round($rb['refund_cents']/$rb['qty'])*$n;$b['settlement']='pending';$warranty=[];
 if($kind==='sales_exchange'&&$product['sn_enabled']&&!empty($b['quantity_only']))$b['warranty_pending']=true;
 if($kind==='sales_exchange'&&$product['sn_enabled']&&empty($b['quantity_only'])){
 $row=$d->query('SELECT * FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);$state=json_decode($row['body'],true);$start=it($b['exchange_date']??gmdate('Y-m-d'));$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$start);if(!$dt||$dt->format('Y-m-d')!==$start)iv('换机日期无效');$end=it($b['warranty_end']??'');if(!$end){$day=(int)$dt->format('d');$base=$dt->modify('first day of this month')->modify('+'.(int)$state['settings']['months'].' months');$end=$base->setDate((int)$base->format('Y'),(int)$base->format('m'),min($day,(int)$base->format('t')))->format('Y-m-d');}$edt=DateTimeImmutable::createFromFormat('!Y-m-d',$end);if(!$edt||$edt->format('Y-m-d')!==$end||$end<$start)iv('保修截止日期无效');$b['exchange_date']=$start;$b['warranty_end']=$end;$changed=false;
 foreach($old as $i=>$sn){if(iq($d,'SELECT 1 FROM inv_card_links WHERE sn=?',[$serials[$i]])->fetchColumn())iv('换出新 SN 已绑定保修卡');$cid=iq($d,'SELECT card_id FROM inv_card_links WHERE sn=?',[$sn])->fetchColumn();if(!$cid)continue;foreach($state['cards'] as &$c)if($c['id']===$cid){$warranty[]=['card_id'=>$cid,'old_sn'=>$sn,'new_sn'=>$serials[$i],'old_start'=>$c['start'],'old_end'=>$c['end'],'start'=>$start,'end'=>$end];$c['equipmentHistory'][]=['sn'=>$c['sn'],'start'=>$c['start'],'end'=>$c['end'],'exchange'=>$id];$c['sn']=$serials[$i];$c['inventorySN']=$serials[$i];$c['product']=$product['name'].' '.$product['model'];$c['start']=$start;$c['end']=$end;$changed=true;}unset($c);iq($d,'UPDATE inv_card_links SET sn=? WHERE card_id=?',[$serials[$i],$cid]);}
 if($changed){iq($d,'INSERT INTO audit VALUES(?,?,?,?)',[$row['revision'],$_SESSION['user']??'admin',gmdate('c'),$row['body']]);iq($d,'UPDATE state SET revision=revision+1,body=?,updated_at=? WHERE id=1',[json_encode($state,JSON_UNESCAPED_UNICODE),gmdate('c')]);}
 }
 iq($d,'INSERT INTO inv_exchange_links VALUES(?,?,?,?,?,?)',[$id,$return['id'],json_encode($old),json_encode($serials),$n,json_encode($warranty)]);
}
function p2CountPost(PDO $d,string $id,array $b,array $snap):void{
 $w=$b['warehouse_id'];$counts=$b['counts']??null;if(!is_array($counts)||!array_is_list($counts))iv('请录入盘点明细');$map=[];foreach($counts as $c){$p=it($c['product_id']??'');if(isset($map[$p]))iv('盘点商品重复');$map[$p]=$c;}
 if(count($map)!==count($snap['balances']))iv('请完成全部盘点行');foreach($snap['balances'] as $line){$p=$line['product_id'];$c=$map[$p]??null;if(!$c)iv('盘点缺少商品');$actual=$c['qty']??null;if(!is_int($actual)||$actual<0||$actual>1000000)iv('实盘数量须为非负整数');$delta=$actual-$line['available'];if($delta&&!it($c['reason']??'',500))iv('差异必须填写原因');
 $current=iq($d,'SELECT qty FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$p,$w])->fetchColumn();if((int)$current!==(int)$line['qty'])iv('盘点快照已变化，需作废重盘');
 if($line['sn_enabled']){$list=$c['serials']??[];if(!is_array($list))iv('实盘 SN 格式错误');$list=array_map(fn($s)=>invSN($s),$list);if(count($list)!==$actual||count(array_unique($list))!==count($list))iv('实盘 SN 数量不符或重复');$was=array_column(array_filter($snap['sn'],fn($s)=>$s['product_id']===$p),'sn');if((array_diff($was,$list)||array_diff($list,$was))&&!it($c['reason']??'',500))iv('SN 盘盈盘亏必须填写差异原因，即使总数量相同');foreach(array_diff($was,$list) as $sn)p2SNMove($d,[$sn],$p,$w,'available',$w,'lost',$id);foreach(array_diff($list,$was) as $sn){$existing=iq($d,'SELECT * FROM inv_sn WHERE sn=?',[$sn])->fetch(PDO::FETCH_ASSOC);if($existing){if($existing['status']!=='lost'||$existing['product_id']!==$p||$existing['warehouse_id']!==$w)iv('盘盈 SN 属于其他库存/已售，不可认领');p2SNMove($d,[$sn],$p,$w,'lost',$w,'available',$id);}else invNewSN($d,$sn,'',$p,$w,null,$id);}}
 else{if($delta<0)p2LotsTake($d,$p,$w,'available',-$delta,$c['allocations']??[],$id);elseif($delta>0)p2LotAdd($d,$p,$w,'available',$delta,'盘盈 '.$id,null,$id);}
 if($delta)p2Balance($d,$p,$w,'available',$delta,$id);
 }iq($d,'DELETE FROM inv_freezes WHERE op_id=?',[$id]);
}
function p2SaveLabel(PDO $d,array $a):array{
 $p=it($a['product_id']??'');if(!iq($d,'SELECT 1 FROM inv_products WHERE id=?',[$p])->fetchColumn())iv('商品不存在');$old=iq($d,'SELECT * FROM inv_label_profiles WHERE product_id=?',[$p])->fetch(PDO::FETCH_ASSOC);if(($a['version']??0)!==(int)($old['version']??0))iv('打印设置已更新，请刷新');$b=$a['body']??null;if(!is_array($b))iv('打印设置格式错误');
 $out=['barcode_source'=>($b['barcode_source']??'sn')==='manufacturer'?'manufacturer':'sn'];foreach(['title','box_title','company','hotline','inspector','standard','voltage','light_power','power','size','surround_size','production_date','configuration','panel','package_size','weight','qr_text','certificate_text'] as $f)$out[$f]=it($b[$f]??'',($f==='qr_text'?300:180));
 foreach(['certificate_width','certificate_height','box_width','box_height'] as $f){$v=$b[$f]??null;if(!is_int($v)||$v<30||$v>300)iv('纸张尺寸范围为 30–300 毫米整数');$out[$f]=$v;}
 $font=$b['font_size']??10;if(!is_int($font)||$font<6||$font>20)iv('字号范围 6–20 pt');$out['font_size']=$font;
 foreach(['show_barcode','show_qr','show_certificate'] as $f)$out[$f]=($b[$f]??false)===true;
 $rows=$b['custom_fields']??[];if(!is_array($rows)||!array_is_list($rows)||count($rows)>8)iv('自定义字段最多 8 行');$out['custom_fields']=[];foreach($rows as $r)$out['custom_fields'][]=['label'=>it($r['label']??'',30),'value'=>it($r['value']??'',180)];
 // Certification marks are supplied as user-owned product images, never inferred from sample art.
 foreach(['ccc_image','environment_image','qr_image'] as $f){$im=it($b[$f]??'');if($im&&!iq($d,'SELECT 1 FROM inv_images WHERE id=? AND product_id=?',[$im,$p])->fetchColumn())iv('标识图片须为本商品上传的图片');$out[$f]=$im;}
 iq($d,'INSERT INTO inv_label_profiles VALUES(?,?,?) ON CONFLICT(product_id) DO UPDATE SET version=excluded.version,body=excluded.body',[$p,(int)($old['version']??0)+1,json_encode($out,JSON_UNESCAPED_UNICODE)]);ilog($d,'label.profile',$p);return ['product_id'=>$p];
}
function p2Print(PDO $d,array $a):array{
 $serials=$a['serials']??[];if(!is_array($serials)||!count($serials)||count($serials)>200)iv('每次打印 1–200 张');$kind=$a['template']??'';if(!in_array($kind,['certificate','box']))iv('模板类型错误');$rows=[];foreach($serials as $sn){$s=iq($d,'SELECT * FROM inv_sn WHERE sn=?',[invSN($sn)])->fetch(PDO::FETCH_ASSOC);if(!$s)iv('SN 不存在');$p=iq($d,'SELECT * FROM inv_products WHERE id=?',[$s['product_id']])->fetch(PDO::FETCH_ASSOC);$profile=iq($d,'SELECT body FROM inv_label_profiles WHERE product_id=?',[$p['id']])->fetchColumn();if(!$profile)iv('请先设置该商品的打印资料');$rows[]=['sn'=>$s['sn'],'manufacturer_sn'=>$s['manufacturer_sn'],'product'=>$p,'profile'=>json_decode($profile,true)];}
 $id=iid('PRINT');$payload=['template'=>$kind,'rows'=>$rows];iq($d,'INSERT INTO inv_print_jobs VALUES(?,?,?)',[$id,json_encode($payload,JSON_UNESCAPED_UNICODE),gmdate('c')]);ilog($d,'labels.print_requested',$id,['template'=>$kind,'quantity'=>count($rows)]);return ['id'=>$id,...$payload];
}
function p2ReconcileLot(PDO $d,array $a):array{
 $key=it($a['request_key']??'',80);if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))iv('缺少请求编号');$prev=iq($d,"SELECT target FROM inv_events WHERE action='lot.reconcile' AND json_extract(detail,'$.request_key')=?",[$key])->fetchColumn();if($prev)return ['id'=>$prev,'repeated'=>true];
 $lot=iq($d,'SELECT * FROM inv_lots WHERE id=?',[$a['lot_id']??''])->fetch(PDO::FETCH_ASSOC);if(!$lot||$lot['origin']!=='一期结余待核实价格'||$lot['zone']!=='available')iv('只能核对一期结余未知批次');p2Unlocked($d,$lot['warehouse_id']);$n=ii($a['qty']??null);$note=it($a['note']??'',1000);if(!$note)iv('请填写批次核对依据');$source=iq($d,"SELECT l.* FROM inv_lines l JOIN inv_docs d ON d.id=l.doc_id WHERE l.id=? AND d.kind='receipt' AND d.status='approved' AND l.product_id=?",[$a['source_line']??'',$lot['product_id']])->fetch(PDO::FETCH_ASSOC);if(!$source)iv('请选择同商品的原入库明细');
 if(iq($d,'SELECT 1 FROM inv_lot_moves m JOIN inv_lots l ON l.id=m.lot_id WHERE l.origin=? AND m.doc_id=? AND m.delta>0',[$source['id'],$source['doc_id']])->fetchColumn())iv('该入库批次已由系统记录，不应重复归属');
 $used=(int)iq($d,"SELECT COALESCE(SUM(json_extract(detail,'$.qty')),0) FROM inv_events WHERE action='lot.reconcile' AND json_extract(detail,'$.source_line')=?",[$source['id']])->fetchColumn();if($used+$n>(int)$source['qty'])iv('核对数量超过原入库数量');$id=invDocumentNumber($d,'PCHK');p2LotsTake($d,$lot['product_id'],$lot['warehouse_id'],'available',$n,[['lot_id'=>$lot['id'],'qty'=>$n]],$id);$new=p2LotAdd($d,$lot['product_id'],$lot['warehouse_id'],'available',$n,$source['id'],$source['price_cents'],$id);ilog($d,'lot.reconcile',$id,['source_line'=>$source['id'],'new_lot'=>$new,'qty'=>$n,'note'=>$note,'request_key'=>$key]);return ['id'=>$new];
}

// Allocate quantity-only movements deterministically, retaining original lot lineage.
function p2AutoLots(PDO $d,string $p,string $w,string $zone,int $qty,?string $origin=null):array {
 $sql='SELECT * FROM inv_lots WHERE product_id=? AND warehouse_id=? AND zone=? AND qty>0';
 $args=[$p,$w,$zone];if($origin!==null){$sql.=' AND origin=?';$args[]=$origin;}
 $out=[];foreach(iq($d,$sql.' ORDER BY id',$args)->fetchAll(PDO::FETCH_ASSOC) as $lot){
  $take=min($qty,(int)$lot['qty']);$out[]=['lot_id'=>$lot['id'],'qty'=>$take];$qty-=$take;if(!$qty)break;
 }
 if($qty)iv('可用批次数量不足，请先核对库存');return $out;
}

// API owns the write transaction; permission and optimistic quantity checks also apply here.
function invAdjustStock(PDO $d,array $a):array {
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可以直接调整库存');
 $key=it($a['request_key']??'',80);if(strlen($key)<16)iv('调整请求标识无效');
 $prior=iq($d,'SELECT id,kind FROM inv_ops WHERE request_key=?',[$key])->fetch(PDO::FETCH_ASSOC);
 if($prior){if($prior['kind']!=='stock_adjustment')iv('请求标识已用于其他操作');return ['id'=>$prior['id'],'repeated'=>true];}
 $p=it($a['product_id']??'');$w=it($a['warehouse_id']??'');p2Warehouse($d,$w);p2Unlocked($d,$w);
 $product=iq($d,'SELECT * FROM inv_products WHERE id=? AND active=1',[$p])->fetch(PDO::FETCH_ASSOC);if(!$product)iv('请选择启用的商品');
 $qty=$a['qty']??null;$expected=$a['expected_qty']??null;
 if(!is_int($qty)||$qty<0||$qty>1000000||!is_int($expected)||$expected<0)iv('库存数量必须为非负整数');
 $note=it($a['note']??'',1000);if(!$note)iv('请填写调整原因');
 $balance=iq($d,'SELECT * FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$p,$w])->fetch(PDO::FETCH_ASSOC);
 $before=(int)($balance['qty']??0);if($expected!==$before)iv('库存已变化，请刷新后重新调整');
 $locked=(int)($balance['unidentified']??0)+p2Hold($d,$p,$w);if($qty<$locked)iv('调整后总数不能低于待检、不良及待补录数量');
 $delta=$qty-$before;if(!$delta)iv('数量未变化');$id=invDocumentNumber($d,'KCTZ');
 if($product['sn_enabled']){
  if($delta>2000||$delta< -2000)iv('整机单次最多调整 2000 台');
  if($delta>0)for($i=0;$i<$delta;$i++)invNewSN($d,iid('DA'),'',$p,$w,null,$id);
  else{
   $sns=iq($d,"SELECT sn FROM inv_sn WHERE product_id=? AND warehouse_id=? AND status='available' AND NOT EXISTS(SELECT 1 FROM inv_card_links c WHERE c.sn=inv_sn.sn) ORDER BY sn LIMIT ".(-$delta),[$p,$w])->fetchAll(PDO::FETCH_COLUMN);
   if(count($sns)!==-$delta)iv('可调整库存不足，已绑定保修卡的设备不能直接扣减');
   p2SNMove($d,$sns,$p,$w,'available',$w,'lost',$id);
  }
 }elseif($delta>0)p2LotAdd($d,$p,$w,'available',$delta,$id,null,$id);
 else p2LotsTake($d,$p,$w,'available',-$delta,p2AutoLots($d,$p,$w,'available',-$delta),$id);
 p2Balance($d,$p,$w,'available',$delta,$id);
 $body=['product_id'=>$p,'warehouse_id'=>$w,'qty'=>$qty,'before_qty'=>$before,'delta'=>$delta,'note'=>$note,'actor'=>$_SESSION['user']];
 iq($d,"INSERT INTO inv_ops(id,kind,state,body,request_key,created_at) VALUES(?,'stock_adjustment','approved',?,?,?)",[$id,json_encode($body,JSON_UNESCAPED_UNICODE),$key,gmdate('c')]);
 ilog($d,'stock.adjust',$id,$body);return ['id'=>$id];
}
