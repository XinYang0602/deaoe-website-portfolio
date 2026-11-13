<?php
declare(strict_types=1);

// Additive schema; all writes below run inside the inventory API transaction.
function productionSchema(PDO $d):void {
 $d->exec("CREATE TABLE IF NOT EXISTS inv_locations(product_id TEXT NOT NULL REFERENCES inv_products(id),warehouse_id TEXT NOT NULL REFERENCES inv_warehouses(id),shelf TEXT NOT NULL DEFAULT '',level TEXT NOT NULL DEFAULT '',version INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(product_id,warehouse_id));
 CREATE TABLE IF NOT EXISTS inv_boms(id TEXT PRIMARY KEY,name TEXT NOT NULL,product_id TEXT NOT NULL REFERENCES inv_products(id),version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_bom_parts(bom_id TEXT NOT NULL REFERENCES inv_boms(id),product_id TEXT NOT NULL REFERENCES inv_products(id),qty INTEGER NOT NULL CHECK(qty>0),PRIMARY KEY(bom_id,product_id));
 CREATE TABLE IF NOT EXISTS inv_production(id TEXT PRIMARY KEY,kind TEXT NOT NULL,date TEXT NOT NULL,status TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,warehouse_id TEXT NOT NULL REFERENCES inv_warehouses(id),product_id TEXT NOT NULL REFERENCES inv_products(id),qty INTEGER NOT NULL,body TEXT NOT NULL,result TEXT NOT NULL DEFAULT '{}',request_key TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_production_parts(doc_id TEXT NOT NULL REFERENCES inv_production(id),product_id TEXT NOT NULL REFERENCES inv_products(id),qty INTEGER NOT NULL CHECK(qty>0),PRIMARY KEY(doc_id,product_id));");
}
function productionSnapshot(PDO $d):array {
 productionSchema($d);$out=[];
 foreach(['locations','boms','bom_parts','production','production_parts'] as $t)$out[$t]=iq($d,'SELECT * FROM inv_'.$t)->fetchAll(PDO::FETCH_ASSOC);
 return $out;
}
function productionProduct(PDO $d,string $id):array {
 $p=iq($d,'SELECT * FROM inv_products WHERE id=? AND active=1',[$id])->fetch(PDO::FETCH_ASSOC);
 if(!$p)iv('请选择启用的产品');return $p;
}
function productionLocations(PDO $d,string $id,array $rows):void {
 if(count($rows)>200)iv('仓位记录过多');$seen=[];
 foreach($rows as $r){$w=it($r['warehouse_id']??'');if(isset($seen[$w]))iv('仓库重复');$seen[$w]=true;p2Warehouse($d,$w);
 $old=iq($d,'SELECT * FROM inv_locations WHERE product_id=? AND warehouse_id=?',[$id,$w])->fetch(PDO::FETCH_ASSOC);
 if((int)($old['version']??0)!==($r['version']??0))iv('仓位已更新，请重新打开产品');
 $s=it($r['shelf']??'',161);$l=it($r['level']??'',80);
 if(!$old&&$s===''&&$l==='')continue;
 iq($d,'INSERT INTO inv_locations(product_id,warehouse_id,shelf,level) VALUES(?,?,?,?) ON CONFLICT(product_id,warehouse_id) DO UPDATE SET shelf=excluded.shelf,level=excluded.level,version=version+1',[$id,$w,$s,$l]);
 }
}
function productionAction(PDO $d,array $a):array {
 if(($_SESSION['user']??'')!=='admin')iv('没有生产管理权限');
 $op=$a['operation']??'';
 if($op==='template'){
  $id=it($a['id']??'');$name=it($a['name']??'',100);if(!$name)iv('请填写模板名称');$p=productionProduct($d,it($a['product_id']??''));
  $parts=$a['parts']??[];if(!is_array($parts)||!array_is_list($parts)||!count($parts)||count($parts)>50)iv('模板需要1–50种零部件');$clean=[];
  foreach($parts as $r){$part=productionProduct($d,it($r['product_id']??''));if($part['id']===$p['id']||isset($clean[$part['id']]))iv('零部件不能重复或与成品相同');$clean[$part['id']]=ii($r['qty']??null,2000);}
  if($id){$v=iq($d,'SELECT version FROM inv_boms WHERE id=?',[$id])->fetchColumn();if($v===false||(int)$v!==($a['version']??null))iv('模板已变化，请刷新');iq($d,'UPDATE inv_boms SET name=?,product_id=?,version=version+1 WHERE id=?',[$name,$p['id'],$id]);iq($d,'DELETE FROM inv_bom_parts WHERE bom_id=?',[$id]);}
  else{$id=iid('BOM');iq($d,'INSERT INTO inv_boms(id,name,product_id) VALUES(?,?,?)',[$id,$name,$p['id']]);}
  foreach($clean as $pid=>$qty)iq($d,'INSERT INTO inv_bom_parts VALUES(?,?,?)',[$id,$pid,$qty]);ilog($d,'production.template',$id);return ['id'=>$id];
 }
 if($op==='create'){
  $key=it($a['request_key']??'',80);if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))iv('请求标识无效');
  $prior=iq($d,'SELECT id FROM inv_production WHERE request_key=?',[$key])->fetchColumn();if($prior)return ['id'=>$prior,'repeated'=>true];
  $kind=$a['kind']??'';if(!in_array($kind,['assembly','disassembly'],true))iv('生产类型无效');
  $bom=iq($d,'SELECT * FROM inv_boms WHERE id=?',[$a['bom_id']??''])->fetch(PDO::FETCH_ASSOC);if(!$bom||(int)$bom['version']!==($a['bom_version']??null))iv('模板已变化，请重新选择');productionProduct($d,$bom['product_id']);
  $parts=iq($d,'SELECT * FROM inv_bom_parts WHERE bom_id=?',[$bom['id']])->fetchAll(PDO::FETCH_ASSOC);$qty=ii($a['qty']??null,2000);
  foreach($parts as &$r){productionProduct($d,$r['product_id']);$r['qty']*=$qty;if($r['qty']>2000)iv('每种产品单次最多2000件');}unset($r);
  $w=it($a['warehouse_id']??'');p2Warehouse($d,$w);$date=it($a['date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||date('Y-m-d',strtotime($date))!==$date)iv('日期无效');
  $id=invReservedDocument($d,$a,$kind==='assembly'?'ZZ':'CZ',$date);
  $body=['template'=>$bom,'note'=>it($a['note']??'',1000)];
  iq($d,"INSERT INTO inv_production(id,kind,date,status,warehouse_id,product_id,qty,body,request_key,created_at) VALUES(?,?,?,'draft',?,?,?,?,?,?)",[$id,$kind,$date,$w,$bom['product_id'],$qty,json_encode($body,JSON_UNESCAPED_UNICODE),$key,gmdate('c')]);
  foreach($parts as $r)iq($d,'INSERT INTO inv_production_parts VALUES(?,?,?)',[$id,$r['product_id'],$r['qty']]);ilog($d,'production.create',$id);return ['id'=>$id];
 }
 if($op!=='transition')iv('生产操作无效');
 $id=it($a['id']??'');$doc=iq($d,'SELECT * FROM inv_production WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$doc)iv('生产单不存在');$act=$a['transition']??'';
 if($act==='approve'&&$doc['status']==='approved')return ['id'=>$id,'repeated'=>true];
 if((int)$doc['version']!==($a['version']??null))iv('单据已更新，请刷新');
 $next=match($act){'submit'=>in_array($doc['status'],['draft','rejected'])?'submitted':null,'approve'=>$doc['status']==='submitted'?'approved':null,'reject'=>$doc['status']==='submitted'?'rejected':null,'void'=>in_array($doc['status'],['draft','rejected'])?'void':null,default=>null};if(!$next)iv('当前状态不支持此操作');
 $result=[];
 if($act==='approve'){
  $w=$doc['warehouse_id'];p2Warehouse($d,$w);p2Unlocked($d,$w);
  $parts=iq($d,'SELECT product_id,qty FROM inv_production_parts WHERE doc_id=?',[$id])->fetchAll(PDO::FETCH_ASSOC);$finished=[['product_id'=>$doc['product_id'],'qty'=>(int)$doc['qty']]];
  $inputs=$doc['kind']==='assembly'?$parts:$finished;$outputs=$doc['kind']==='assembly'?$finished:$parts;
  // Input and output lineage is retained. No unverified finished-goods cost is invented.
  foreach($inputs as $r){$p=productionProduct($d,$r['product_id']);$n=(int)$r['qty'];$trace=['product_id'=>$p['id'],'qty'=>$n];
   if($p['sn_enabled']){
    $sns=$a['serials'][$p['id']]??[];if(!is_array($sns)||!array_is_list($sns)||count($sns)!==$n)iv('请为每件投入的追踪产品选择SN');
    foreach($sns as $sn)if(iq($d,'SELECT 1 FROM inv_card_links WHERE sn=?',[invSN($sn)])->fetchColumn())iv('已绑定保修卡的产品不能投入组装或拆装');
    $trace['serials']=p2SNMove($d,$sns,$p['id'],$w,'available',$w,'production_consumed',$id);
   }else $trace['lots']=p2LotsTake($d,$p['id'],$w,'available',$n,p2AutoLots($d,$p['id'],$w,'available',$n),$id);
   p2Balance($d,$p['id'],$w,'available',-$n,$id);$result['inputs'][]=$trace;
  }
  foreach($outputs as $r){$p=productionProduct($d,$r['product_id']);$n=(int)$r['qty'];$trace=['product_id'=>$p['id'],'qty'=>$n];
   if($p['sn_enabled'])for($i=0;$i<$n;$i++)$trace['serials'][]=invNewSN($d,iid('DA'),'',$p['id'],$w,null,$id);
   else $trace['lot_id']=p2LotAdd($d,$p['id'],$w,'available',$n,$id,null,$id);
   p2Balance($d,$p['id'],$w,'available',$n,$id);$result['outputs'][]=$trace;
  }
 }
 iq($d,'UPDATE inv_production SET status=?,version=version+1,result=? WHERE id=?',[$next,json_encode($result,JSON_UNESCAPED_UNICODE),$id]);ilog($d,'production.'.$act,$id);return ['id'=>$id];
}
