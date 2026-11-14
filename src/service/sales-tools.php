<?php
declare(strict_types=1);
// Normalized order ingestion is shared by file import and future authenticated adapters.
// No remote polling, credentials or arbitrary formula evaluation is enabled here.
function salesToolsSchema(PDO $d):void {
 $d->exec("CREATE TABLE IF NOT EXISTS inv_platform_orders(platform TEXT NOT NULL,shop TEXT NOT NULL,external_id TEXT NOT NULL,doc_id TEXT NOT NULL UNIQUE REFERENCES inv_docs(id),fingerprint TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(platform,shop,external_id));
 CREATE TABLE IF NOT EXISTS inv_commission_templates(id TEXT PRIMARY KEY,name TEXT NOT NULL UNIQUE,body TEXT NOT NULL,active INTEGER NOT NULL,version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_commission_sales(doc_id TEXT PRIMARY KEY REFERENCES inv_docs(id),salesperson_id TEXT NOT NULL,salesperson_name TEXT NOT NULL,template_name TEXT NOT NULL,body TEXT NOT NULL,verified_cents INTEGER,verified_return_cents INTEGER,verified_return_qty INTEGER,note TEXT NOT NULL DEFAULT '',version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_salesperson_commission(salesperson_id TEXT PRIMARY KEY REFERENCES inv_salespeople(id),template_id TEXT NOT NULL REFERENCES inv_commission_templates(id));");
}
function salesToolsSnapshot(PDO $d):array {
 return ['details'=>commissionDetails($d),'templates'=>iq($d,'SELECT * FROM inv_commission_templates ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC),
 'assignments'=>iq($d,'SELECT * FROM inv_salesperson_commission')->fetchAll(PDO::FETCH_ASSOC),
 'imports'=>iq($d,'SELECT p.*,x.status FROM inv_platform_orders p JOIN inv_docs x ON x.id=p.doc_id ORDER BY p.created_at DESC,p.doc_id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC)];
}
function salesToolsAction(PDO $d,array $a):array {
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可以导入订单或管理提成模板');
 return match($a['op']??''){'preview'=>platformPreview($d,$a),'import'=>platformImport($d,$a),'template'=>commissionSave($d,$a),'assign'=>commissionAssign($d,$a),'trial'=>commissionTrial($a),'verify'=>commissionVerify($d,$a),'details'=>['rows'=>commissionDetails($d,it($a['before']??'',100))],default=>throw new InvalidArgumentException('未知销售工具操作')};
}
function platformPlan(PDO $d,array $a):array {
 $platform=it($a['platform']??'',20);if(!in_array($platform,['taobao','jd','douyin'],true))iv('请选择淘宝、京东或抖音');
 $shop=it($a['shop']??'',80);if($shop==='')iv('请填写店铺名称，同一店铺请保持名称一致');
 $wh=it($a['warehouse_id']??'');if($wh==='LEGACY'||!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$wh])->fetchColumn())iv('请选择启用的实际出货仓库');
 $default=it($a['party_id']??'');if($default&&!iq($d,"SELECT 1 FROM inv_parties WHERE id=? AND kind='customer' AND active=1",[$default])->fetchColumn())iv('请选择有效的默认结算客户');
 $rows=$a['rows']??null;if(!is_array($rows)||!array_is_list($rows)||!count($rows)||count($rows)>500)iv('每批需要1至500行商品明细');
 $orders=[];$errors=[];
 foreach($rows as $n=>$r){
  try{
   if(!is_array($r))iv('行格式错误');
   $ext=it($r['order_id']??'',100);if($ext===''||preg_match('/[\x00-\x1f\x7f]/',$ext)||preg_match('/^[0-9.]+[Ee][+-]?\d+$/D',$ext))iv('订单号须完整文本，不能是科学计数法');
   $date=it($r['date']??'',10);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)iv('订单日期须为YYYY-MM-DD');
   $customer=it($r['customer']??'',200);$party=$default;
   if($customer!=='')$party=iq($d,"SELECT id FROM inv_parties WHERE kind='customer' AND active=1 AND name=?",[$customer])->fetchColumn()?:'';
   if(!$party)iv('结算客户未匹配，请选择默认客户或填写已有客户全名');
   $sku=it($r['sku']??'',200);$p=iq($d,'SELECT id,name,sn_enabled,version FROM inv_products WHERE code=? AND active=1',[$sku])->fetch(PDO::FETCH_ASSOC);if(!$p)iv('商品编码未匹配：'.$sku.'，请填写产品档案编号');
   $rawqty=it($r['qty']??'',10);if(!preg_match('/^[1-9]\d{0,5}$/D',$rawqty))iv('数量须为1至999999的整数');$qty=(int)$rawqty;if($p['sn_enabled']&&$qty>2000)iv('SN商品每单每行最多2000台');
   $price=money($r['price']??'');if($price===null)iv('请填写优惠后的商品单价');$amount=$qty*$price;
   $lineAmount=money($r['line_amount']??'');if($lineAmount!==null&&$lineAmount!==$amount)iv('商品实付金额与数量×单价不一致，请核对优惠分摊；本期不合并运费');
   $delivery=[];foreach(['contact'=>200,'phone'=>200,'address'=>500] as $f=>$max)$delivery[$f]=it($r[$f]??'',$max);
   if(!$delivery['phone']||!$delivery['address'])iv('请填写收货电话和地址');
   $meta=['date'=>$date,'party_id'=>$party,'delivery'=>$delivery];
   if(!isset($orders[$ext]))$orders[$ext]=['order_id'=>$ext,...$meta,'lines'=>[],'amount_cents'=>0];
   $o=&$orders[$ext];foreach($meta as $k=>$v)if($o[$k]!==$v)iv('同一订单的日期、客户或收货信息不一致');
   $pid=$p['id'];if(isset($o['lines'][$pid])&&$o['lines'][$pid]['price_cents']!==$price)iv('同一订单同一商品存在不同单价，请先合并并核对优惠');
   if(!isset($o['lines'][$pid]))$o['lines'][$pid]=['product_id'=>$pid,'code'=>$sku,'name'=>$p['name'],'product_version'=>(int)$p['version'],'qty'=>0,'price_cents'=>$price];
   $o['lines'][$pid]['qty']+=$qty;if($o['lines'][$pid]['qty']>($p['sn_enabled']?2000:999999))iv('合并商品数量过大，请分单');
   if(count($o['lines'])>200)iv('每单最多200个不同产品');
   $o['amount_cents']+=$amount;if($o['amount_cents']>9000000000000)iv('订单金额过大');unset($o);
  }catch(InvalidArgumentException $e){$errors[]=['row'=>$n+2,'message'=>$e->getMessage()];unset($o);}
 }
 if(count($orders)>100)iv('每批最多100个订单，请分批导入');
 foreach($orders as &$o){
  ksort($o['lines']);$o['lines']=array_values($o['lines']);
  // Product versions affect preview freshness, but not duplicate identity of source content.
  $identity=$o;foreach($identity['lines'] as &$line)unset($line['product_version'],$line['name']);unset($line);
  $o['fingerprint']=hash('sha256',json_encode([$platform,$shop,$wh,$identity],JSON_UNESCAPED_UNICODE));
  $existing=iq($d,'SELECT doc_id,fingerprint FROM inv_platform_orders WHERE platform=? AND shop=? AND external_id=?',[$platform,$shop,$o['order_id']])->fetch(PDO::FETCH_ASSOC);
  $o['doc_id']=$existing['doc_id']??'';$o['result']=$existing?($existing['fingerprint']===$o['fingerprint']?'duplicate':'conflict'):'new';
  if($o['result']==='conflict')$errors[]=['row'=>0,'message'=>'订单 '.$o['order_id'].' 已导入但本次内容不同；请在原销售单中核对，不会重复开单'];
 }
 unset($o);ksort($orders);
 return ['platform'=>$platform,'shop'=>$shop,'warehouse_id'=>$wh,'orders'=>array_values($orders),'errors'=>$errors];
}
function platformPreview(PDO $d,array $a):array {
 $plan=platformPlan($d,$a);$plan['preview_hash']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE));return $plan;
}
function platformImport(PDO $d,array $a):array {
 $plan=platformPreview($d,$a);if($plan['errors'])iv('存在未解决的导入错误，请修正后重新预览');
 if(!is_string($a['preview_hash']??null)||!hash_equals($plan['preview_hash'],$a['preview_hash']))iv('订单或资料已变化，请重新预览后导入');
 $created=[];$skipped=[];
 foreach($plan['orders'] as $o){
  if($o['result']==='duplicate'){$skipped[]=$o['doc_id'];continue;}
  $key='PLATFORM_'.hash('sha256',json_encode([$plan['platform'],$plan['shop'],$o['order_id']]));
  $r=invSaveDoc($d,['kind'=>'sale','warehouse_id'=>$plan['warehouse_id'],'party_id'=>$o['party_id'],'date'=>$o['date'],'request_key'=>$key,
   'note'=>'平台订单：'.$plan['platform'].' / '.$plan['shop'].' / '.$o['order_id'],
   'delivery_phone'=>$o['delivery']['phone'],'delivery_contact'=>$o['delivery']['contact'],'delivery_address'=>$o['delivery']['address'],
   'lines'=>array_map(fn($l)=>['product_id'=>$l['product_id'],'qty'=>$l['qty'],'price'=>number_format($l['price_cents']/100,2,'.','')],$o['lines'])]);
  if(!empty($r['repeated']))iv('订单编号已关联其它记录，请刷新核对');
  iq($d,'INSERT INTO inv_platform_orders VALUES(?,?,?,?,?,?)',[$plan['platform'],$plan['shop'],$o['order_id'],$r['id'],$o['fingerprint'],gmdate('c')]);$created[]=$r['id'];
 }
 ilog($d,'platform.import',$plan['platform'],['created'=>$created,'skipped'=>$skipped]);return ['created'=>$created,'skipped'=>$skipped];
}
// Structured rules mirror fixed + percentage + quantity calculations without eval().
function commissionRule(array $a):array {
 $base=$a['base']??'';if(!in_array($base,['sales','receipts','gross_profit'],true))iv('请选择销售额、实收额或毛利');
 $rate=money($a['rate']??'');if($rate===null||$rate>10000)iv('提成比例须在0至100之间，最多两位小数');
 $fixed=money($a['fixed']??'');$unit=money($a['per_unit']??'');if($fixed===null||$unit===null)iv('固定金额和每件金额不能为空，可填0');
 $returns=$a['returns']??'';if(!in_array($returns,['deduct','keep'],true))iv('请选择退货处理方式');
 if(!$rate&&!$fixed&&!$unit)iv('比例、每单或每件提成至少填写一项');
 return ['base'=>$base,'rate_bp'=>$rate,'fixed_cents'=>$fixed,'per_unit_cents'=>$unit,'returns'=>$returns];
}
function commissionSave(PDO $d,array $a):array {
 $id=it($a['id']??'');$old=$id?iq($d,'SELECT * FROM inv_commission_templates WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;
 if($id&&(!$old||(int)$old['version']!==($a['version']??null)))iv('模板已变化，请刷新');
 $name=it($a['name']??'',80);if(!$name)iv('请填写模板名称');$rule=commissionRule($a);$active=$a['active']??true;if(!is_bool($active))iv('启用状态无效');$id=$id?:iid('CT');
 if($old)iq($d,'UPDATE inv_commission_templates SET name=?,body=?,active=?,version=version+1 WHERE id=?',[$name,json_encode($rule),(int)$active,$id]);
 else iq($d,'INSERT INTO inv_commission_templates(id,name,body,active) VALUES(?,?,?,?)',[$id,$name,json_encode($rule),(int)$active]);
 ilog($d,'commission.template',$id,['name'=>$name]);return ['id'=>$id];
}
function commissionAssign(PDO $d,array $a):array {
 $id=it($a['salesperson_id']??'');$sp=iq($d,'SELECT * FROM inv_salespeople WHERE id=? AND active=1',[$id])->fetch(PDO::FETCH_ASSOC);
 if(!$sp||(int)$sp['version']!==($a['version']??null))iv('业务员已变化或已停用，请刷新');
 $template=it($a['template_id']??'');if($template&&!iq($d,'SELECT 1 FROM inv_commission_templates WHERE id=? AND active=1',[$template])->fetchColumn())iv('请选择启用的提成模板');
 if($template)iq($d,'INSERT INTO inv_salesperson_commission VALUES(?,?) ON CONFLICT(salesperson_id) DO UPDATE SET template_id=excluded.template_id',[$id,$template]);
 else iq($d,'DELETE FROM inv_salesperson_commission WHERE salesperson_id=?',[$id]);
 iq($d,'UPDATE inv_salespeople SET version=version+1 WHERE id=?',[$id]);ilog($d,'commission.assign',$id,['template_id'=>$template]);return ['id'=>$id];
}
function commissionTrial(array $a):array {
 $r=commissionRule($a);$base=money($a['amount']??'');$returned=money($a['returned_amount']??'');
 if($base===null||$returned===null||$returned>$base)iv('试算基数须非负，退货对应基数不能超过原基数');
 $qty=$a['qty']??null;$back=$a['returned_qty']??null;
 if(!is_int($qty)||$qty<1||$qty>10000||!is_int($back)||$back<0||$back>$qty)iv('数量须为整数，退货数量不能超过销售数量（最多10000）');
 return commissionBreakdown($base,$qty,$returned,$back,$r);
}
// Capture the customer owner and the active rule at approval, inside the sale transaction.
// Later transfers or template changes must not rewrite historical commissions.
function commissionCapture(PDO $d,string $sale):void {
 $row=iq($d,"SELECT s.id salesperson_id,s.name salesperson_name,t.name template_name,t.body FROM inv_docs x JOIN inv_customer_salespeople o ON o.party_id=x.party_id JOIN inv_salespeople s ON s.id=o.salesperson_id AND s.active=1 JOIN inv_salesperson_commission a ON a.salesperson_id=s.id JOIN inv_commission_templates t ON t.id=a.template_id AND t.active=1 WHERE x.id=? AND x.kind='sale'",[$sale])->fetch(PDO::FETCH_ASSOC);
 if(!$row)return;
 iq($d,'INSERT INTO inv_commission_sales(doc_id,salesperson_id,salesperson_name,template_name,body) VALUES(?,?,?,?,?)',[$sale,$row['salesperson_id'],$row['salesperson_name'],$row['template_name'],$row['body']]);
}
function commissionBreakdown(int $base,int $qty,int $returned,int $back,array $r):array {
 $deduct=$r['returns']==='deduct';$effective=max(0,$base-($deduct?$returned:0));$units=max(0,$qty-($deduct?$back:0));$fixed=$deduct&&$back>=$qty?0:$r['fixed_cents'];
 // Avoid base*rate overflow on large sales; round half up only once.
 $percent=intdiv($effective,10000)*$r['rate_bp']+intdiv(($effective%10000)*$r['rate_bp']+5000,10000);
 if($units>0&&$r['per_unit_cents']>intdiv(PHP_INT_MAX-$fixed-$percent,$units))iv('提成金额超出范围');
 return ['cents'=>$percent+$fixed+$units*$r['per_unit_cents'],'effective_cents'=>$effective,'effective_qty'=>$units,'fixed_cents'=>$fixed];
}
function commissionDetails(PDO $d,string $before=''):array {
 $cursor=$before?(int)iq($d,'SELECT rowid FROM inv_commission_sales WHERE doc_id=?',[$before])->fetchColumn():PHP_INT_MAX;
 $rows=iq($d,"WITH recent AS (SELECT rowid seq,* FROM inv_commission_sales WHERE rowid<? ORDER BY rowid DESC LIMIT 100),
 totals AS (SELECT l.doc_id,SUM(l.qty*l.price_cents) amount,SUM(l.qty) qty FROM recent c JOIN inv_lines l ON l.doc_id=c.doc_id GROUP BY l.doc_id),
 refunds AS (SELECT x.source_id,SUM(json_extract(o.body,'$.refund_cents')) refund,SUM(b.qty) back FROM recent c JOIN inv_docs x ON x.source_id=c.doc_id AND x.kind='issue' JOIN inv_lines l ON l.doc_id=x.id JOIN inv_return_lines b ON b.source_line=l.id JOIN inv_ops o ON o.id=b.op_id AND o.kind='sales_return' AND o.state='approved' GROUP BY x.source_id)
 SELECT c.*,x.date,t.amount,t.qty,COALESCE(f.refund,0) refund,COALESCE(f.back,0) back FROM recent c JOIN inv_docs x ON x.id=c.doc_id JOIN totals t ON t.doc_id=c.doc_id LEFT JOIN refunds f ON f.source_id=c.doc_id ORDER BY c.seq DESC",[$cursor])->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as &$row){$r=json_decode($row['body'],true,32,JSON_THROW_ON_ERROR);$amount=$row['amount'];$qty=$row['qty'];$refund=$row['refund'];$back=$row['back'];
  $row['qty']=(int)$qty;$row['returned_qty']=(int)$back;$row['sales_cents']=(int)$amount;$row['return_cents']=(int)$refund;
  $base=$r['base']==='sales'?(int)$amount:$row['verified_cents'];$returned=$r['base']==='sales'?(int)$refund:$row['verified_return_cents'];
  // A newly approved return invalidates any manually verified receipt/profit basis.
  $stale=$r['base']!=='sales'&&$row['verified_return_qty']!==(int)$back;
  $row['commission_cents']=$base===null||$stale?null:commissionBreakdown((int)$base,(int)$qty,(int)$returned,(int)$back,$r)['cents'];
 }unset($row);return $rows;
}
function commissionVerify(PDO $d,array $a):array {
 $id=it($a['doc_id']??'');$row=iq($d,'SELECT * FROM inv_commission_sales WHERE doc_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
 if(!$row||(int)$row['version']!==($a['version']??null)||json_decode($row['body'],true)['base']==='sales')iv('明细已变化或无需核实，请刷新');
 $base=money($a['amount']??'');$ret=money($a['returned_amount']??'');$note=it($a['note']??'',300);if($base===null||$ret===null||$ret>$base||$note==='')iv('请填写核实依据和有效金额，退货基数不可大于原基数');
 $back=(int)iq($d,"SELECT COALESCE(SUM(b.qty),0) FROM inv_docs x JOIN inv_lines l ON l.doc_id=x.id JOIN inv_return_lines b ON b.source_line=l.id JOIN inv_ops o ON o.id=b.op_id WHERE x.source_id=? AND x.kind='issue' AND o.kind='sales_return' AND o.state='approved'",[$id])->fetchColumn();
 if($back!==($a['returned_qty']??null))iv('原单退货已变化，请刷新后重新核实');
 iq($d,'UPDATE inv_commission_sales SET verified_cents=?,verified_return_cents=?,verified_return_qty=?,note=?,version=version+1 WHERE doc_id=?',[$base,$ret,$back,$note,$id]);ilog($d,'commission.verify',$id,['base_cents'=>$base,'returned_cents'=>$ret,'note'=>$note]);return ['id'=>$id];
}
