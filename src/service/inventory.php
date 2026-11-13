<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
require_once __DIR__.'/phase2.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/inventory-cleanup.php';
function invSchema(PDO $d):void{
 $d->exec("PRAGMA foreign_keys=ON;
 CREATE TABLE IF NOT EXISTS inv_meta(k TEXT PRIMARY KEY,v TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_products(id TEXT PRIMARY KEY,code TEXT NOT NULL COLLATE NOCASE UNIQUE,name TEXT NOT NULL,category TEXT NOT NULL,brand TEXT NOT NULL,model TEXT NOT NULL,unit TEXT NOT NULL,sn_enabled INTEGER NOT NULL,purchase_cents INTEGER,sale_cents INTEGER,active INTEGER NOT NULL,models TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_warehouses(id TEXT PRIMARY KEY,name TEXT NOT NULL UNIQUE,active INTEGER NOT NULL,version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_parties(id TEXT PRIMARY KEY,kind TEXT NOT NULL,name TEXT NOT NULL,contact TEXT NOT NULL,phone TEXT NOT NULL,address TEXT NOT NULL,active INTEGER NOT NULL,version INTEGER NOT NULL DEFAULT 1,UNIQUE(kind,name));
 CREATE TABLE IF NOT EXISTS inv_docs(id TEXT PRIMARY KEY,kind TEXT NOT NULL,status TEXT NOT NULL,warehouse_id TEXT REFERENCES inv_warehouses(id),party_id TEXT REFERENCES inv_parties(id),source_id TEXT REFERENCES inv_docs(id),date TEXT NOT NULL,note TEXT NOT NULL,reason TEXT NOT NULL DEFAULT '',version INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,approved_at TEXT,request_key TEXT UNIQUE);
 CREATE TABLE IF NOT EXISTS inv_lines(id TEXT PRIMARY KEY,doc_id TEXT NOT NULL REFERENCES inv_docs(id),product_id TEXT NOT NULL REFERENCES inv_products(id),qty INTEGER NOT NULL CHECK(qty>0),price_cents INTEGER,source_line TEXT REFERENCES inv_lines(id),serials TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_balances(product_id TEXT NOT NULL REFERENCES inv_products(id),warehouse_id TEXT NOT NULL REFERENCES inv_warehouses(id),qty INTEGER NOT NULL CHECK(qty>=0),unidentified INTEGER NOT NULL DEFAULT 0 CHECK(unidentified>=0 AND unidentified<=qty),PRIMARY KEY(product_id,warehouse_id));
 CREATE TABLE IF NOT EXISTS inv_sn(sn TEXT PRIMARY KEY COLLATE NOCASE,manufacturer_sn TEXT COLLATE NOCASE UNIQUE,product_id TEXT NOT NULL REFERENCES inv_products(id),warehouse_id TEXT NOT NULL REFERENCES inv_warehouses(id),status TEXT NOT NULL,receipt_line TEXT REFERENCES inv_lines(id),issue_line TEXT REFERENCES inv_lines(id),created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,doc_id TEXT NOT NULL,line_id TEXT,product_id TEXT NOT NULL,warehouse_id TEXT NOT NULL,delta INTEGER NOT NULL,price_cents INTEGER,created_at TEXT NOT NULL,UNIQUE(doc_id,line_id,warehouse_id));
 CREATE TABLE IF NOT EXISTS inv_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor TEXT NOT NULL,action TEXT NOT NULL,target TEXT NOT NULL,detail TEXT NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_sn_events(id INTEGER PRIMARY KEY AUTOINCREMENT,sn TEXT NOT NULL,doc_id TEXT NOT NULL,status TEXT NOT NULL,warehouse_id TEXT NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_images(id TEXT PRIMARY KEY,product_id TEXT NOT NULL REFERENCES inv_products(id),name TEXT NOT NULL,bytes INTEGER NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_card_links(card_id TEXT PRIMARY KEY,sn TEXT NOT NULL COLLATE NOCASE UNIQUE REFERENCES inv_sn(sn));
 CREATE INDEX IF NOT EXISTS inv_sn_stock ON inv_sn(product_id,warehouse_id,status);
 CREATE INDEX IF NOT EXISTS inv_lines_doc ON inv_lines(doc_id);");
 $d->exec("CREATE TABLE IF NOT EXISTS inv_delivery(doc_id TEXT PRIMARY KEY REFERENCES inv_docs(id) ON DELETE CASCADE,phone TEXT NOT NULL,contact TEXT NOT NULL,address TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS inv_sale_line_settings(line_id TEXT PRIMARY KEY REFERENCES inv_lines(id) ON DELETE CASCADE,warehouse_id TEXT NOT NULL REFERENCES inv_warehouses(id));");
 invCategorySchema($d);
 finSchema($d);
}
// Run for existing installations too; the initial inventory migration is not repeated on API reads.
function invCategorySchema(PDO $d):void{
 $d->exec("CREATE TABLE IF NOT EXISTS inv_product_categories(product_id TEXT PRIMARY KEY REFERENCES inv_products(id) ON DELETE CASCADE,path TEXT NOT NULL);");
}
function iq(PDO $d,string $sql,array $a=[]):PDOStatement{$q=$d->prepare($sql);$q->execute($a);return $q;}
function iv(string $message):never{throw new InvalidArgumentException($message);}
function it(mixed $s,int $max=200):string{if(!is_string($s)||mb_strlen($s)>$max)iv('文字字段格式错误或过长');return trim($s);}
function ii(mixed $n,int $max=1000000):int{if(!is_int($n)||$n<1||$n>$max)iv('数量须为 1–'.$max.' 的整数');return $n;}
function money(mixed $s):?int{if($s===null||$s==='')return null;if(!is_string($s)||!preg_match('/^\d{1,8}(\.\d{1,2})?$/D',$s))iv('价格须为非负金额，最多两位小数');$p=explode('.',$s);return (int)$p[0]*100+(int)str_pad($p[1]??'',2,'0');}
// Daily counters are part of the same write transaction as their documents.
function invNumberTypes():array{return ['XS'=>'销售单','CG'=>'采购单','RK'=>'入库单','CK'=>'出库单','DB'=>'调拨单','PD'=>'盘点单','CGTH'=>'采购退货','XSTH'=>'销售退货','ZJ'=>'质检单','CGHH'=>'采购换货','XSHH'=>'销售换货'];}
function invNumberRule(PDO $d,string $type):array{
 if(!isset(invNumberTypes()[$type]))iv('编号类型无效');
 $r=['prefix'=>$type,'date_format'=>'ymd','separator'=>'-','digits'=>3,'start'=>1,'version'=>0];
 if($type!=='XS'){$saved=iq($d,'SELECT v FROM inv_meta WHERE k=?',['number_rule:'.$type])->fetchColumn();if($saved)$r=array_merge($r,json_decode($saved,true));}return $r;
}
function invSaveNumberRule(PDO $d,array $a):array{
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可修改编号规则');
 $type=it($a['type']??'');if($type==='XS')iv('销售单规则保持不变');$old=invNumberRule($d,$type);
 if(($a['version']??null)!==$old['version'])iv('规则已更新，请刷新');
 $r=['prefix'=>strtoupper(it($a['prefix']??'')),'date_format'=>$a['date_format']??'','separator'=>$a['separator']??'','digits'=>$a['digits']??0,'start'=>$a['start']??0,'version'=>$old['version']+1];
 if(!preg_match('/^[A-Z]{1,6}$/D',$r['prefix'])||!in_array($r['date_format'],['ymd','Ymd','ym','none'],true)||!in_array($r['separator'],['-','_',''],true)||!is_int($r['digits'])||$r['digits']<1||$r['digits']>7||!is_int($r['start'])||$r['start']<1||$r['start']>=10**$r['digits'])iv('编号规则无效');
 iq($d,'INSERT INTO inv_meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v',['number_rule:'.$type,json_encode($r)]);ilog($d,'number.rule',$type,$r);return $r;
}
function invReserveNumber(PDO $d,array $a):array{
 $type=it($a['type']??'');if($type!=='product'&&!isset(invNumberTypes()[$type]))iv('编号类型无效');
 $key=it($a['request_key']??'',80);if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))iv('请求编号无效');
 $date=it($a['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'));
 $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$day||$day->format('Y-m-d')!==$date)iv('日期无效');
 if($type==='product')return ['number'=>invNextProductCode($d,true),'date'=>$date];
 $storage='number_reservation:'.hash('sha256',($_SESSION['user']??'').':'.$type.':'.$key.':'.$date);
 $existing=iq($d,'SELECT v FROM inv_meta WHERE k=?',[$storage])->fetchColumn();if($existing)return ['number'=>$existing,'date'=>$date];
 $number=$type==='product'?invNextProductCode($d):invDocumentNumber($d,$type,$date);
 iq($d,'INSERT INTO inv_meta(k,v) VALUES(?,?)',[$storage,$number]);return ['number'=>$number,'date'=>$date];
}
function invReservedDocument(PDO $d,array $a,string $type,?string $date=null):string{
 $date=$date??($a['reserved_date']??null)??(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
 $provided=it($a['reserved_number']??'');if(!$provided)return invDocumentNumber($d,$type,$date); // legacy clients
 $storage='number_reservation:'.hash('sha256',($_SESSION['user']??'').':'.$type.':'.($a['request_key']??'').':'.$date);
 if(iq($d,'SELECT v FROM inv_meta WHERE k=?',[$storage])->fetchColumn()!==$provided)iv('编号与当前日期不匹配，请重新获取编号');return $provided;
}
function invDocumentNumber(PDO $d,string $prefix,?string $date=null):string{
 $date=$date??(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
 $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
 if(!$day||$day->format('Y-m-d')!==$date||!preg_match('/^[A-Z]{2,6}$/D',$prefix))iv('单号日期或类型无效');
 $r=isset(invNumberTypes()[$prefix])?invNumberRule($d,$prefix):['prefix'=>$prefix,'date_format'=>'ymd','separator'=>'-','digits'=>3,'start'=>1];
 $part=$r['date_format']==='none'?'':$day->format($r['date_format']);
 $stem=$r['prefix'].$r['separator'].($part!==''?$part.$r['separator']:'');
 $key='document_sequence:'.$prefix.':'.($r['date_format']==='ymd'?$date:$part).':'.hash('sha256',json_encode([$r['prefix'],$r['separator'],$r['digits']]));
 // Preserve existing counters for every unchanged default rule, including sales.
 if($r['prefix']===$prefix&&$r['date_format']==='ymd'&&$r['separator']==='-'&&$r['digits']===3)$key='document_sequence:'.$prefix.':'.$date;
 $d->exec('SAVEPOINT document_number');
 try{
  iq($d,'INSERT OR IGNORE INTO inv_meta(k,v) VALUES(?,?)',[$key,'0']);
  $n=max($r['start']-1,(int)iq($d,'SELECT v FROM inv_meta WHERE k=?',[$key])->fetchColumn());
  do{$n++;$number=$stem.str_pad((string)$n,$r['digits'],'0',STR_PAD_LEFT);
  }while(iq($d,'SELECT 1 FROM inv_docs WHERE id=?',[$number])->fetchColumn()||(p2Ready($d)&&iq($d,'SELECT 1 FROM inv_ops WHERE id=?',[$number])->fetchColumn())||iq($d,'SELECT 1 FROM inv_meta WHERE k=?',['issued_number:'.$number])->fetchColumn());
  iq($d,'INSERT INTO inv_meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v',[$key,(string)$n]);
  iq($d,'INSERT INTO inv_meta(k,v) VALUES(?,?)',['issued_number:'.$number,$prefix]);
  $d->exec('RELEASE SAVEPOINT document_number');return $number;
 }catch(Throwable $e){$d->exec('ROLLBACK TO SAVEPOINT document_number');$d->exec('RELEASE SAVEPOINT document_number');throw $e;}
}

function iid(string $prefix):string{return $prefix.gmdate('Ymd').strtoupper(bin2hex(random_bytes(6)));}
function ilog(PDO $d,string $action,string $target,array $detail=[]):void{iq($d,'INSERT INTO inv_events(actor,action,target,detail,created_at) VALUES(?,?,?,?,?)',[$_SESSION['user']??'migration',$action,$target,json_encode($detail,JSON_UNESCAPED_UNICODE),gmdate('c')]);}
function invMigrate(PDO $d):array{
 invSchema($d);$d->exec('BEGIN IMMEDIATE');
 try{
 if(iq($d,"SELECT v FROM inv_meta WHERE k='version'")->fetchColumn()){$d->exec('COMMIT');return ['already'=>true];}
 $state=json_decode($d->query('SELECT body FROM state WHERE id=1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);validateData($state);
 iq($d,'INSERT INTO inv_warehouses(id,name,active) VALUES(?,?,1)',['LEGACY','期初待归属仓库']);$total=0;
 foreach($state['stock'] as $s){$tracked=$s['kind']==='整机';iq($d,'INSERT INTO inv_products(id,code,name,category,brand,model,unit,sn_enabled,purchase_cents,sale_cents,active,models) VALUES(?,?,?,?,?,?,?,?,NULL,NULL,?,?)',[$s['id'],$s['id'],$s['name'],$s['kind'],'德澳',$s['model'],$s['unit'],(int)$tracked,empty($s['deleted'])?1:0,'']);iq($d,'INSERT INTO inv_balances VALUES(?,?,?,?)',[$s['id'],'LEGACY',$s['qty'],$tracked?$s['qty']:0]);if($s['qty'])iq($d,'INSERT INTO inv_ledger(doc_id,line_id,product_id,warehouse_id,delta,price_cents,created_at) VALUES(?,?,?,?,?,NULL,?)',['OPENING',$s['id'],$s['id'],'LEGACY',$s['qty'],gmdate('c')]);$total+=$s['qty'];}
 foreach($state['dealers'] as $p)iq($d,'INSERT INTO inv_parties(id,kind,name,contact,phone,address,active) VALUES(?,?,?,?,?,?,1)',['DEALER_'.$p['id'],'customer',$p['name'],$p['contact'],$p['phone'],$p['area']]);
 $after=(int)$d->query('SELECT COALESCE(SUM(qty),0) FROM inv_balances')->fetchColumn();if($total!==$after)throw new RuntimeException('期初数量对账失败');
 iq($d,'INSERT INTO inv_meta VALUES(?,?)',['version','1']);iq($d,'INSERT INTO inv_meta VALUES(?,?)',['opening_total',(string)$total]);ilog($d,'migration','phase1',['quantity'=>$total,'products'=>count($state['stock'])]);$d->exec('COMMIT');return ['quantity'=>$total,'products'=>count($state['stock'])];
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function invDefaultWarehouse(PDO $d):string {
 return (string)(iq($d,"SELECT w.id FROM inv_meta m JOIN inv_warehouses w ON w.id=m.v WHERE m.k='sale_default_warehouse' AND w.active=1 AND w.id!='LEGACY'")->fetchColumn() ?: '');
}
function invSetDefaultWarehouse(PDO $d,array $a):array {
 $id=it($a['warehouse_id']??'');
 if($id==='LEGACY'||!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$id])->fetchColumn())iv('请选择启用的实际仓库');
 iq($d,"INSERT INTO inv_meta(k,v) VALUES('sale_default_warehouse',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",[$id]);
 ilog($d,'warehouse.default',$id);return ['id'=>$id];
}
function invCategoryPath(mixed $value):string{
 $text=it($value,600);if($text==='')return '';
 $parts=array_map('trim',preg_split('~(?:/|>+|›)~u',$text));
 if(count($parts)>8)iv('产品分类最多支持 8 级');
 foreach($parts as $part)if($part===''||mb_strlen($part)>60)iv('每级分类须为 1–60 个字符，不可留空');
 return implode(' / ',$parts);
}
function invCodeRule(PDO $d):array {
 $v=iq($d,"SELECT v FROM inv_meta WHERE k='product_code_rule'")->fetchColumn();
 return $v?json_decode($v,true):['prefix'=>'P','digits'=>5,'next'=>1,'version'=>0];
}
function invSaveCodeRule(PDO $d,array $a):array {
 $old=invCodeRule($d);if(($a['version']??null)!==$old['version'])iv('编号规则已变化，请刷新');
 $prefix=strtoupper(it($a['prefix']??'',3));$digits=(int)($a['digits']??0);$next=(int)($a['next']??0);
 if(!preg_match('/^[A-Z]{0,3}$/',$prefix)||$digits<1||$digits>7||strlen($prefix)+$digits>7||$next<1||$next>=10**$digits)iv('编号总长度须小于8位，流水起点须在位数范围内');
 $v=['prefix'=>$prefix,'digits'=>$digits,'next'=>$next,'version'=>$old['version']+1];
 iq($d,"INSERT INTO inv_meta VALUES('product_code_rule',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",[json_encode($v)]);ilog($d,'product.code_rule','rule',$v);return $v;
}
function invNextProductCode(PDO $d,bool $preview=false):string {
 $r=invCodeRule($d);
 foreach(iq($d,'SELECT code FROM inv_products')->fetchAll(PDO::FETCH_COLUMN) as $existing){if(preg_match('/^'.preg_quote($r['prefix'],'/').'([0-9]{'.$r['digits'].'})$/D',$existing,$m))$r['next']=max($r['next'],(int)$m[1]+1);}
 do {if($r['next']>=10**$r['digits'])iv('产品编号已用尽，请调整编号规则');$code=$r['prefix'].str_pad((string)$r['next']++,$r['digits'],'0',STR_PAD_LEFT);}while(iq($d,'SELECT 1 FROM inv_products WHERE code=?',[$code])->fetchColumn()||iq($d,'SELECT 1 FROM inv_meta WHERE k=?',['issued_product:'.$code])->fetchColumn());
 if($preview)return $code;
 iq($d,'INSERT INTO inv_meta(k,v) VALUES(?,?)',['issued_product:'.$code,'1']);
 $r['version']++;iq($d,"INSERT INTO inv_meta VALUES('product_code_rule',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",[json_encode($r)]);return $code;
}
function invMoveCategory(PDO $d,array $a):array {
 $from=invCategoryPath($a['from']??'');$parent=invCategoryPath($a['parent']??'');
 if(!$from||$parent===$from||str_starts_with($parent,$from.' / '))iv('不能移入自身或下级目录');
 $parts=explode(' / ',$from);$to=invCategoryPath(($parent?$parent.' / ':'').end($parts));
 $rows=$d->query("SELECT p.id,p.version,p.category,COALESCE(c.path,'') AS path FROM inv_products p LEFT JOIN inv_product_categories c ON c.product_id=p.id ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
 if(($a['fingerprint']??'')!==hash('sha256',json_encode($rows)))iv('分类资料已变化，请刷新后重试');
 $count=0;foreach($rows as $r){$path=invCategoryPath($r['path']?:$r['category']);if($path!==$from&&!str_starts_with($path,$from.' / '))continue;$new=invCategoryPath($to.substr($path,strlen($from)));iq($d,'INSERT INTO inv_product_categories VALUES(?,?) ON CONFLICT(product_id) DO UPDATE SET path=excluded.path',[$r['id'],$new]);iq($d,'UPDATE inv_products SET version=version+1 WHERE id=?',[$r['id']]);$count++;}
 if(!$count)iv('目录不存在');ilog($d,'category.move',$from,['to'=>$to,'products'=>$count]);return ['count'=>$count];
}
function invBrands(PDO $d):array{
 $v=iq($d,"SELECT v FROM inv_meta WHERE k='product_brands'")->fetchColumn();
 return $v?json_decode($v,true):['version'=>0,'entries'=>[['name'=>'德澳','english'=>'DEAOE'],['name'=>'狄澳','english'=>'DIAOR']]];
}
function invSaveBrands(PDO $d,array $a):array{
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可以管理品牌');
 $old=invBrands($d);if(($a['version']??null)!==$old['version'])iv('品牌配置已更新，请刷新');
 $rows=$a['entries']??[];if(!is_array($rows)||count($rows)>200)iv('品牌数量无效');$clean=[];$seen=[];
 foreach($rows as $r){$name=it($r['name']??'',60);$en=strtoupper(it($r['english']??'',60));if(!$name&&!$en)continue;if(!$name||!$en)iv('请填写品牌中英文名称');foreach([$name,$en] as $alias){$k=strtoupper($alias);if(isset($seen[$k]))iv('品牌名称重复');$seen[$k]=true;}$clean[]=['name'=>$name,'english'=>$en];}
 if(!$clean)iv('请至少保留一个品牌');$out=['version'=>$old['version']+1,'entries'=>$clean];
 iq($d,"INSERT INTO inv_meta(k,v) VALUES('product_brands',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",[json_encode($out)]);ilog($d,'brands.update','product_brands',$out);return $out;
}
function invMaster(PDO $d,array $a):array{
 $type=$a['type']??'';$tables=['products'=>'inv_products','warehouses'=>'inv_warehouses','parties'=>'inv_parties'];if(!isset($tables[$type]))iv('档案类型错误');$table=$tables[$type];$id=it($a['id']??'');$old=$id?iq($d,"SELECT * FROM $table WHERE id=?",[$id])->fetch(PDO::FETCH_ASSOC):false;
 if($id&&!$old)iv('档案不存在');if($old&&(int)$old['version']!==($a['version']??null))iv('档案已更新，请刷新');$id=$id?:iid('M');$name=it($a['name']??'');if($name==='')iv('名称不能为空');// New products always start enabled; disabling remains an explicit edit/archive action.
 $status=$a['active']??($old?(bool)$old['active']:true);
 if(!in_array($status,[true,false,1,0,'1','0','on','off'],true))iv('启用状态无效，请刷新后重试');
 $active=$type==='products'&&!$old?1:(in_array($status,[true,1,'1','on'],true)?1:0);
 if($type==='products'){
 $categoryPath=array_key_exists('category_path',$a)?invCategoryPath($a['category_path']):null;
 $config=invProductNames($d);
 if(!$old||$name!==$old['name']||($a['apply_name_defaults']??false)===true){
  if(($a['name_config_version']??'')!==$config['version'])iv('名称配置已更新，请刷新后重新选择名称');
  $entry=null;foreach($config['entries'] as $item)if($item['name']===$name)$entry=$item;
  if(!$entry||!$entry['kind']||!$entry['families'])iv('请先在名称种类管理中完善该名称的类型与所属产品');
  $a['category']=implode('、',$entry['families']).' / '.$entry['kind'];$a['unit']??=$entry['unit'];$a['sn_enabled']??=$entry['sn_enabled'];
 }else{$a['category']=$old['category'];}
 $code=it($a['code']??'');if($code===''&&!$old)$code=invNextProductCode($d);if($code==='')iv('商品编码不能为空');$unit=it($a['unit']??'台',20);if($unit==='')iv('单位不能为空');$sn=($a['sn_enabled']??true)===true?1:0;
 if($old&&($unit!==$old['unit']||$sn!==(int)$old['sn_enabled'])&&(iq($d,'SELECT 1 FROM inv_lines WHERE product_id=? LIMIT 1',[$id])->fetchColumn()||iq($d,'SELECT 1 FROM inv_ledger WHERE product_id=? LIMIT 1',[$id])->fetchColumn()))iv('有库存历史的商品不能改变单位或 SN 模式');
 // Normalize known bilingual aliases on new/explicitly changed brand assignments only.
 $brand=it($a['brand']??'德澳');
 if(!$old||$brand!==$old['brand'])foreach(invBrands($d)['entries'] as $entry)if($brand===$entry['name']||strtoupper($brand)===$entry['english']){$a['brand']=$entry['name'];break;}
 if(iq($d,'SELECT 1 FROM inv_products WHERE code=? AND id<>?',[$code,$id])->fetchColumn())iv('产品编号已被使用，请修改编号后再保存');
 $fields=['code'=>$code,'name'=>$name,'category'=>it($a['category']??'整机'),'brand'=>it($a['brand']??'德澳'),'model'=>it($a['model']??''),'unit'=>$unit,'sn_enabled'=>$sn,'purchase_cents'=>money($a['purchase_price']??null),'sale_cents'=>money($a['sale_price']??null),'active'=>$active,'models'=>it($a['models']??'',3000)];
 }elseif($type==='warehouses'){
 if(!$active&&p2Ready($d)){p2Unlocked($d,$id);if(iq($d,"SELECT 1 FROM inv_ops WHERE state IN ('draft','submitted','rejected','transit') AND (json_extract(body,'$.warehouse_id')=? OR json_extract(body,'$.to_warehouse')=?)",[$id,$id])->fetchColumn())iv('仓库有未完成第二期业务');}
 if($id==='LEGACY'&&($name!==$old['name']||!$active))iv('期初待归属仓库为历史专用，请使用期初归属功能');
 if(!$active&&(iq($d,'SELECT 1 FROM inv_balances WHERE warehouse_id=? AND qty>0',[$id])->fetchColumn()||iq($d,"SELECT 1 FROM inv_docs WHERE warehouse_id=? AND status IN ('draft','submitted','rejected')",[$id])->fetchColumn()))iv('仓库有库存或未完成单据，不能停用');$fields=['name'=>$name,'active'=>$active];
 }else{$kind=it($a['kind']??'');if(!in_array($kind,['supplier','customer']))iv('往来类型错误');if($old&&$kind!==$old['kind'])iv('不能改变往来类型');$fields=['kind'=>$kind,'name'=>$name,'contact'=>it($a['contact']??''),'phone'=>it($a['phone']??''),'address'=>it($a['address']??'',500),'active'=>$active];}
 if($type==='products'&&!$active&&p2Ready($d)&&(iq($d,"SELECT 1 FROM inv_ops WHERE state IN ('draft','submitted','rejected','transit') AND json_extract(body,'$.product_id')=?",[$id])->fetchColumn()||iq($d,"SELECT 1 FROM inv_sn WHERE product_id=? AND status='transit'",[$id])->fetchColumn()))iv('商品有未完成第二期业务');
 if($type==='products'&&!$active&&(iq($d,'SELECT 1 FROM inv_balances WHERE product_id=? AND qty>0',[$id])->fetchColumn()||iq($d,"SELECT 1 FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.product_id=? AND x.status IN ('draft','submitted','rejected')",[$id])->fetchColumn()))iv('商品有库存或未完成单据，不能停用');
 if($old){$sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($fields)));iq($d,"UPDATE $table SET $sets,version=version+1 WHERE id=?",[...array_values($fields),$id]);}else{iq($d,"INSERT INTO $table(id,".implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields)+1,'?')).')',[$id,...array_values($fields)]);}
 if($type==='products'&&$categoryPath!==null){iq($d,'INSERT INTO inv_product_categories(product_id,path) VALUES(?,?) ON CONFLICT(product_id) DO UPDATE SET path=excluded.path',[$id,$categoryPath]);$fields['category_path']=$categoryPath;}
 ilog($d,$old?'master.update':'master.create',$id,$fields);return ['id'=>$id];
}
function invSaveDoc(PDO $d,array $a):array{
 $id=it($a['id']??'');$old=$id?iq($d,'SELECT * FROM inv_docs WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;if($id&&!$old)iv('单据不存在');
 if($old&&(!in_array($old['status'],['draft','rejected'])||(int)$old['version']!==($a['version']??null)))iv('仅可编辑当前版本的草稿或驳回单');
 $kind=$a['kind']??'';if(!in_array($kind,['purchase','sale','receipt','issue']))iv('单据类型错误');if($old&&$old['kind']!==$kind)iv('单据类型不可变');
 $key=it($a['request_key']??'',80);if(!$old){if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))iv('缺少幂等请求编号');$existing=iq($d,'SELECT id FROM inv_docs WHERE request_key=?',[$key])->fetchColumn();if($existing)return ['id'=>$existing,'repeated'=>true];}
 $wh=it($a['warehouse_id']??'');if($wh==='LEGACY'||!iq($d,'SELECT id FROM inv_warehouses WHERE id=? AND active=1',[$wh])->fetchColumn())iv('请选择实际启用的仓库');$party=it($a['party_id']??'');$pk=in_array($kind,['purchase','receipt'])?'supplier':'customer';if(!iq($d,'SELECT id FROM inv_parties WHERE id=? AND kind=? AND active=1',[$party,$pk])->fetchColumn())iv('请选择有效的供应商或客户');
 $source=it($a['source_id']??'');$source=$source?:null;if(in_array($kind,['receipt','issue'])){if(!$source)iv('请关联已审核采购订单或销售单');$s=iq($d,'SELECT * FROM inv_docs WHERE id=?',[$source])->fetch(PDO::FETCH_ASSOC);if(!$s||$s['kind']!==($kind==='receipt'?'purchase':'sale')||$s['status']!=='approved'||$s['party_id']!==$party||($kind==='receipt'&&$s['warehouse_id']!==$wh))iv('来源单类型、状态、仓库或往来不一致');}elseif($source)iv('订单不能设置来源单');
 $date=it($a['date']??'');$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)iv('日期不正确');$note=it($a['note']??'',2000);$lines=$a['lines']??null;if(!is_array($lines)||!array_is_list($lines)||!count($lines)||count($lines)>200)iv('单据需要 1–200 条明细');
 $id=$id?:invReservedDocument($d,$a,['sale'=>'XS','purchase'=>'CG','receipt'=>'RK','issue'=>'CK'][$kind],$date);if($old){iq($d,"UPDATE inv_docs SET warehouse_id=?,party_id=?,source_id=?,date=?,note=?,status='draft',reason='',version=version+1 WHERE id=?",[$wh,$party,$source,$date,$note,$id]);if(p2Ready($d))iq($d,'DELETE FROM inv_allocations WHERE line_id IN (SELECT id FROM inv_lines WHERE doc_id=?)',[$id]);iq($d,'DELETE FROM inv_lines WHERE doc_id=?',[$id]);}else iq($d,"INSERT INTO inv_docs(id,kind,status,warehouse_id,party_id,source_id,date,note,created_at,request_key) VALUES(?,?,'draft',?,?,?,?,?,?,?)",[$id,$kind,$wh,$party,$source,$date,$note,gmdate('c'),$key]);
 $total=0;$seen=[];foreach($lines as $l){$pid=it($l['product_id']??'');$lineWh=$kind==='sale'?it($l['warehouse_id']??$wh):$wh;
 if($lineWh==='LEGACY'||!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$lineWh])->fetchColumn())iv('请选择商品实际出货仓库');
 $unique=$pid.'|'.$lineWh;if(isset($seen[$unique]))iv('同一商品同一仓库请合并到一行');$seen[$unique]=true;$p=iq($d,'SELECT * FROM inv_products WHERE id=? AND active=1',[$pid])->fetch(PDO::FETCH_ASSOC);if(!$p)iv('商品不存在或已停用');$qty=ii($l['qty']??null);if($p['sn_enabled']&&$qty>2000)iv('SN 商品每行最多 2000 台，请分单');$price=money($l['price']??null);if($price===null)iv('请填写本单价格（可填 0）');$total+=$price*$qty;if($total>9000000000000)iv('单据金额过大，请分单');$sl=it($l['source_line']??'')?:null;if($source){$parent=iq($d,'SELECT * FROM inv_lines WHERE id=? AND doc_id=? AND product_id=?',[$sl,$source,$pid])->fetch(PDO::FETCH_ASSOC);if(!$parent||$qty>(int)$parent['qty'])iv('来源明细或数量不正确');
 if($kind==='issue'){ $sourceWh=iq($d,'SELECT warehouse_id FROM inv_sale_line_settings WHERE line_id=?',[$sl])->fetchColumn()?:$s['warehouse_id'];if($sourceWh!==$wh)iv('出库仓库与销售明细不一致'); }}elseif($sl)iv('订单不应设置来源行');$serials=$kind==='sale'?[]:($l['serials']??[]);if(!is_array($serials)||!array_is_list($serials)||count($serials)>2000)iv('SN 列表格式错误');$clean=[];foreach($serials as $serial){if(!is_array($serial))iv('SN 行格式错误');$clean[]=['sn'=>invSN($serial['sn']??'',true),'manufacturer_sn'=>invSN($serial['manufacturer_sn']??'',true)];}if(!$p['sn_enabled']&&count($clean))iv('非 SN 商品不可附加 SN');if($p['sn_enabled']&&$kind==='issue'&&count($clean)!==$qty)iv('出库 SN 数量必须等于出库数量');if($p['sn_enabled']&&$kind==='receipt'&&count($clean)&&count($clean)!==$qty)iv('入库 SN 数量须等于数量，或整列留空自动生成');$lid=iid('L');iq($d,'INSERT INTO inv_lines VALUES(?,?,?,?,?,?,?)',[$lid,$id,$pid,$qty,$price,$sl,json_encode($clean)]);if($kind==='sale')iq($d,'INSERT INTO inv_sale_line_settings VALUES(?,?)',[$lid,$lineWh]);if(p2Ready($d))iq($d,'INSERT INTO inv_allocations VALUES(?,?)',[$lid,json_encode($l['allocations']??[])]);}
 if(in_array($kind,['sale','issue'])){
 $saved=iq($d,'SELECT * FROM inv_delivery WHERE doc_id=?',[$source?:$id])->fetch(PDO::FETCH_ASSOC);
 $customer=iq($d,'SELECT * FROM inv_parties WHERE id=?',[$party])->fetch(PDO::FETCH_ASSOC);
 $delivery=[];foreach(['phone'=>200,'contact'=>200,'address'=>500] as $field=>$max)$delivery[]=it($kind==='issue'?($saved[$field]??$customer[$field]):($a['delivery_'.$field]??$saved[$field]??$customer[$field]),$max);
 if($kind==='sale'&&array_key_exists('delivery_address',$a)&&(!$delivery[0]||!$delivery[2]))iv('请填写送货电话和地址');
 iq($d,'INSERT OR REPLACE INTO inv_delivery VALUES(?,?,?,?)',[$id,...$delivery]);
 }
 ilog($d,'document.save',$id);return ['id'=>$id];
}
function invSN(mixed $s,bool $empty=false):string{$s=strtoupper(it($s,100));if($empty&&$s==='')return '';if(!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/D',$s))iv('SN 只允许字母数字、点、短横线、下划线，最长 80 位');return $s;}
function invNewSN(PDO $d,string $sn,string $manufacturer,string $pid,string $wh,?string $line,string $doc):string{
 $sn=$sn?:iid('DA');$manufacturer=$manufacturer?:null;
 foreach(array_filter([$sn,$manufacturer]) as $value){if(iq($d,'SELECT 1 FROM inv_sn WHERE sn=? OR manufacturer_sn=?',[$value,$value])->fetchColumn())iv('SN 或厂家 SN 已存在：'.$value);}
 if($manufacturer===$sn)iv('厂家 SN 不能和系统 SN 相同');iq($d,"INSERT INTO inv_sn VALUES(?,?,?,?, 'available',?,NULL,?)",[$sn,$manufacturer,$pid,$wh,$line,gmdate('c')]);iq($d,'INSERT INTO inv_sn_events(sn,doc_id,status,warehouse_id,created_at) VALUES(?,?,?,?,?)',[$sn,$doc,'available',$wh,gmdate('c')]);$state=json_decode($d->query('SELECT body FROM state WHERE id=1')->fetchColumn(),true);foreach($state['cards'] as $card){if(in_array(strtoupper(trim($card['sn'])),array_filter([$sn,$manufacturer]),true))iq($d,'INSERT INTO inv_card_links(card_id,sn) VALUES(?,?)',[$card['id'],$sn]);}return $sn;
}
function invTransition(PDO $d,array $a):array{
 $id=it($a['id']??'');$doc=iq($d,'SELECT * FROM inv_docs WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$doc)iv('单据不存在');$act=$a['transition']??'';
 if($act==='approve'&&$doc['status']==='approved')return ['id'=>$id,'repeated'=>true];if((int)$doc['version']!==($a['version']??null))iv('单据已更新，请刷新');$status=$doc['status'];$reason=it($a['reason']??'',1000);
 $next=match($act){'submit'=>in_array($status,['draft','rejected'])?'submitted':null,'approve'=>$status==='submitted'?'approved':null,'reject'=>$status==='submitted'?'rejected':null,'void'=>in_array($status,['draft','rejected'])?'void':null,default=>null};if(!$next)iv('当前状态不支持该操作');if(in_array($act,['reject','void'])&&$reason==='')iv('请填写原因');
 if($act==='approve'){
 if(!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$doc['warehouse_id']])->fetchColumn()||!iq($d,'SELECT 1 FROM inv_parties WHERE id=? AND active=1',[$doc['party_id']])->fetchColumn())iv('仓库或往来已停用');
 $lines=iq($d,'SELECT l.*,p.sn_enabled,p.active FROM inv_lines l JOIN inv_products p ON p.id=l.product_id WHERE doc_id=?',[$id])->fetchAll(PDO::FETCH_ASSOC);if(!$lines)iv('单据无明细');
 foreach($lines as $l){if(!$l['active'])iv('商品已停用');$qty=(int)$l['qty'];$pid=$l['product_id'];$wh=$doc['warehouse_id'];
 if(in_array($doc['kind'],['receipt','issue'])){
 $src=iq($d,"SELECT l.qty FROM inv_lines l JOIN inv_docs s ON s.id=l.doc_id WHERE l.id=? AND s.id=? AND s.status='approved'",[$l['source_line'],$doc['source_id']])->fetchColumn();$used=(int)iq($d,"SELECT COALESCE(SUM(l.qty),0) FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.source_line=? AND x.status='approved'",[$l['source_line']])->fetchColumn();if($src===false||$used+$qty>(int)$src)iv('累计收发数量超过来源订单剩余数量');
 iq($d,'INSERT OR IGNORE INTO inv_balances VALUES(?,?,0,0)',[$pid,$wh]);$b=iq($d,'SELECT * FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$pid,$wh])->fetch(PDO::FETCH_ASSOC);$in=$doc['kind']==='receipt';if(!$in&&$qty>(int)$b['qty']-(int)$b['unidentified'])iv('可用库存不足，待补录数量不可出库');
 $serials=json_decode($l['serials'],true);p2V1Post($d,$doc,$l,$serials);if($l['sn_enabled']){if($in){if(!$serials)$serials=array_fill(0,$qty,['sn'=>'','manufacturer_sn'=>'']);if(count($serials)!==$qty)iv('SN 数量不符');foreach($serials as &$s)$s['sn']=invNewSN($d,$s['sn'],$s['manufacturer_sn'],$pid,$wh,$l['id'],$id);unset($s);iq($d,'UPDATE inv_lines SET serials=? WHERE id=?',[json_encode($serials),$l['id']]);}else{if(count($serials)!==$qty)iv('SN 数量不符');foreach($serials as $s){$q=iq($d,"UPDATE inv_sn SET status='sold',issue_line=? WHERE sn=? AND product_id=? AND warehouse_id=? AND status='available'",[$l['id'],$s['sn'],$pid,$wh]);if($q->rowCount()!==1)iv('SN 不属于本仓商品、已出库或重复：'.$s['sn']);iq($d,'INSERT INTO inv_sn_events(sn,doc_id,status,warehouse_id,created_at) VALUES(?,?,?,?,?)',[$s['sn'],$id,'sold',$wh,gmdate('c')]);}}}
 $delta=$in?$qty:-$qty;iq($d,'UPDATE inv_balances SET qty=qty+? WHERE product_id=? AND warehouse_id=?',[$delta,$pid,$wh]);iq($d,'INSERT INTO inv_ledger(doc_id,line_id,product_id,warehouse_id,delta,price_cents,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$l['id'],$pid,$wh,$delta,$l['price_cents'],gmdate('c')]);
 }
 }
 }
 iq($d,'UPDATE inv_docs SET status=?,reason=?,version=version+1,approved_at=? WHERE id=?',[$next,$reason,$next==='approved'?gmdate('c'):null,$id]);ilog($d,'document.'.$act,$id,['reason'=>$reason]);
 // The API owns the transaction: approval and fulfillment either both commit or both roll back.
 if ($act === 'approve' && $doc['kind'] === 'sale') {
  $shipmentLines = [];
  $reservedSerials = [];
  foreach ($lines as $line) {
   $lineWh=iq($d,'SELECT warehouse_id FROM inv_sale_line_settings WHERE line_id=?',[$line['id']])->fetchColumn()?:$doc['warehouse_id'];
   $allocation = p2Ready($d) ? iq($d,'SELECT body FROM inv_allocations WHERE line_id=?',[$line['id']])->fetchColumn() : false;
   // Sales staff enter quantities only. Allocate available inventory records atomically.
   $saleSerials = [];
   if ($line['sn_enabled']) {
    $available = iq($d,"SELECT sn FROM inv_sn WHERE product_id=? AND warehouse_id=? AND status='available' ORDER BY created_at,sn",[$line['product_id'],$lineWh]);
    foreach ($available as $stock) {
     if (isset($reservedSerials[$stock['sn']])) continue;
     $saleSerials[] = ['sn'=>$stock['sn']];
     $reservedSerials[$stock['sn']] = true;
     if (count($saleSerials) === (int)$line['qty']) break;
    }
    if (count($saleSerials) !== (int)$line['qty']) iv('本仓可售库存不足，请核对入库数量');
   }
   $shipmentLines[$lineWh][] = ['product_id'=>$line['product_id'], 'qty'=>(int)$line['qty'],
    'price'=>number_format((int)$line['price_cents']/100,2,'.',''), 'source_line'=>$line['id'],
    'serials'=>$saleSerials, 'allocations'=>$allocation ? json_decode($allocation,true) : []];
  }
  foreach($shipmentLines as $shipmentWh=>$warehouseLines){
  $shipment = invSaveDoc($d,['kind'=>'issue','warehouse_id'=>$shipmentWh,
   'party_id'=>$doc['party_id'],'source_id'=>$id,'date'=>$doc['date'],
   'note'=>'销售审核自动扣库存：'.$id,'request_key'=>'AUTO_SALE_'.hash('sha256',$id.'|'.$shipmentWh),'lines'=>$warehouseLines]);
  invTransition($d,['id'=>$shipment['id'],'transition'=>'submit','version'=>1]);
  invTransition($d,['id'=>$shipment['id'],'transition'=>'approve','version'=>2]);
  }
  finSale($d,$id);
 }
 if ($act === 'approve' && $doc['kind'] === 'receipt') finPurchase($d,$id);
 return ['id'=>$id];
}
function invOpening(PDO $d,array $a):array{
 $pid=it($a['product_id']??'');$wh=it($a['warehouse_id']??'');$qty=ii($a['qty']??null,2000);$note=it($a['note']??'',1000);if(!$note)iv('请填写实物核对依据');$key=it($a['request_key']??'',80);if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))iv('缺少请求编号');$prev=iq($d,"SELECT target FROM inv_events WHERE action='opening.assign' AND json_extract(detail,'$.request_key')=?",[$key])->fetchColumn();if($prev)return ['id'=>$prev,'repeated'=>true];
 p2Unlocked($d,$wh);$p=iq($d,'SELECT * FROM inv_products WHERE id=?',[$pid])->fetch(PDO::FETCH_ASSOC);if(!$p||$wh==='LEGACY'||!iq($d,'SELECT 1 FROM inv_warehouses WHERE id=? AND active=1',[$wh])->fetchColumn())iv('商品或实际仓库无效');$b=iq($d,"SELECT * FROM inv_balances WHERE product_id=? AND warehouse_id='LEGACY'",[$pid])->fetch(PDO::FETCH_ASSOC);if(!$b||$qty>(int)$b['qty'])iv('超过待归属数量');$id=invDocumentNumber($d,'QC');$sn=$a['serials']??[];if(!is_array($sn)||!array_is_list($sn))iv('SN 格式错误');if($p['sn_enabled']){if(count($sn)!==$qty)iv('请逐台填写核实过的实物 SN，不自动生成历史身份');foreach($sn as $s)invNewSN($d,invSN($s['sn']??''),invSN($s['manufacturer_sn']??'',true),$pid,$wh,null,$id);}elseif(count($sn))iv('非 SN 商品不应填写 SN');
 iq($d,"UPDATE inv_balances SET qty=qty-?,unidentified=unidentified-? WHERE product_id=? AND warehouse_id='LEGACY'",[$qty,$p['sn_enabled']?$qty:0,$pid]);iq($d,'INSERT OR IGNORE INTO inv_balances VALUES(?,?,0,0)',[$pid,$wh]);iq($d,'UPDATE inv_balances SET qty=qty+? WHERE product_id=? AND warehouse_id=?',[$qty,$pid,$wh]);foreach(['LEGACY'=>-$qty,$wh=>$qty] as $w=>$delta)iq($d,'INSERT INTO inv_ledger(doc_id,line_id,product_id,warehouse_id,delta,created_at) VALUES(?,?,?,?,?,?)',[$id,$pid,$pid,$w,$delta,gmdate('c')]);if(p2Ready($d)&&!$p['sn_enabled']){foreach(p2LotsTake($d,$pid,'LEGACY','available',$qty,[],$id) as $lot)p2LotAdd($d,$pid,$wh,'available',$lot['qty'],$lot['origin'],$lot['price_cents'],$id);}
 ilog($d,'opening.assign',$id,['product_id'=>$pid,'qty'=>$qty,'warehouse_id'=>$wh,'note'=>$note,'request_key'=>$key]);return ['id'=>$id];
}
function invSnapshot(PDO $d):array{$out=[];foreach(['products','warehouses','parties','docs','lines','balances','sn','ledger','images','card_links'] as $t)$out[$t]=$d->query('SELECT * FROM inv_'.$t)->fetchAll(PDO::FETCH_ASSOC);$categoryPaths=$d->query('SELECT product_id,path FROM inv_product_categories')->fetchAll(PDO::FETCH_KEY_PAIR);foreach($out['products'] as &$product)$product['category_path']=$categoryPaths[$product['id']]??'';unset($product);$deliveries=[];foreach($d->query('SELECT * FROM inv_delivery') as $row)$deliveries[$row['doc_id']]=$row;foreach($out['docs'] as &$doc){if(isset($deliveries[$doc['id']]))foreach(['phone','contact','address'] as $f)$doc['delivery_'.$f]=$deliveries[$doc['id']][$f];}unset($doc);$lineWarehouses=$d->query('SELECT line_id,warehouse_id FROM inv_sale_line_settings')->fetchAll(PDO::FETCH_KEY_PAIR);foreach($out['lines'] as &$line){if(isset($lineWarehouses[$line['id']]))$line['warehouse_id']=$lineWarehouses[$line['id']];}unset($line);$out['events']=$d->query('SELECT * FROM inv_events ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);$out['sn_events']=$d->query('SELECT * FROM inv_sn_events ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);foreach(['ops','lots','holds','freezes','label_profiles','exchange_links','allocations'] as $t)$out[$t]=$d->query('SELECT * FROM inv_'.$t)->fetchAll(PDO::FETCH_ASSOC);$out['sale_default_warehouse']=invDefaultWarehouse($d);$out['product_brands']=invBrands($d);$out['product_names']=invProductNames($d);$out['product_code_rule']=invCodeRule($d);$out['number_rules']=[];foreach(invNumberTypes() as $type=>$label)$out['number_rules'][$type]=invNumberRule($d,$type)+['label'=>$label];$out['category_fingerprint']=hash('sha256',json_encode($d->query("SELECT p.id,p.version,p.category,COALESCE(c.path,'') AS path FROM inv_products p LEFT JOIN inv_product_categories c ON c.product_id=p.id ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC)));$out['finance']=finSnapshot($d);$out['can_adjust_stock']=($_SESSION['user']??'')==='admin';$out['csrf']=$_SESSION['csrf'];return $out;}
function handleInventory():never{
 $d=null;$transaction=false;
 try{
 if(($_SESSION['user']??'')!=='admin')jsonReply(403,['error'=>'没有库存管理权限']);$d=database();finSchema($d);invCategorySchema($d);if(!iq($d,"SELECT v FROM inv_meta WHERE k='version'")->fetchColumn())throw new RuntimeException('未完成库存迁移');
 if($_SERVER['REQUEST_METHOD']==='GET')jsonReply(200,invSnapshot($d));if($_SERVER['REQUEST_METHOD']!=='POST')jsonReply(405,['error'=>'不支持的方法']);if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))jsonReply(403,['error'=>'页面已过期，请刷新']);$raw=file_get_contents('php://input',false,null,0,2000001);if(strlen($raw)>2000000)jsonReply(413,['error'=>'请求超过 2MB']);$a=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($a))iv('请求格式错误');$d->exec('BEGIN IMMEDIATE');$transaction=true;
 $r=match($a['action']??''){'save_brands'=>invSaveBrands($d,$a),'number_rule'=>invSaveNumberRule($d,$a),'reserve_number'=>invReserveNumber($d,$a),'product_code_rule'=>invSaveCodeRule($d,$a),'move_category'=>invMoveCategory($d,$a),'adjust_stock'=>invAdjustStock($d,$a),'finance'=>finAction($d,$a),'lot_reconcile'=>p2ReconcileLot($d,$a),'p2_create'=>p2Create($d,$a),'p2_step'=>p2Step($d,$a),'label_save'=>p2SaveLabel($d,$a),'label_print'=>p2Print($d,$a),'set_default_warehouse'=>invSetDefaultWarehouse($d,$a),'master'=>invMaster($d,$a),'save_doc'=>invSaveDoc($d,$a),'transition'=>invTransition($d,$a),'opening'=>invOpening($d,$a),'print'=>invPrint($d,$a),'archive'=>invArchive($d,$a),'save_product_names'=>invSaveProductNames($d,$a),'delete_products'=>invDeleteProducts($d,$a),'test_cleanup_preview','test_cleanup_execute'=>invTestCleanup($d,$a),default=>throw new InvalidArgumentException('未知操作')};$d->exec('COMMIT');$transaction=false;jsonReply(200,$r);
 }catch(Throwable $e){if($transaction)$d->exec('ROLLBACK');if($e instanceof InvalidArgumentException||$e instanceof JsonException)jsonReply(422,['error'=>$e->getMessage()]);if($e instanceof PDOException&&str_contains($e->getMessage(),'UNIQUE'))jsonReply(409,['error'=>'编码、名称或 SN 重复，本次没有保存']);error_log('Inventory: '.$e->getMessage());jsonReply(503,['error'=>'库存操作未确认成功，请刷新重试']);}
}
function invPrint(PDO $d,array $a):array{$sn=$a['serials']??[];if(!is_array($sn)||!array_is_list($sn)||count($sn)<1||count($sn)>500)iv('每次打印 1–500 个标签');foreach($sn as $s)if(!iq($d,'SELECT 1 FROM inv_sn WHERE sn=?',[invSN($s)])->fetchColumn())iv('标签 SN 不存在');ilog($d,'labels.print_requested',iid('PRINT'),['serials'=>$sn,'template'=>it($a['template']??'')]);return ['requested'=>true];}

function invArchive(PDO $d,array $a):array{
 $ids=$a['ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>200)iv('请选择 1–200 个商品');
 foreach($ids as $id){if(p2Ready($d)&&iq($d,"SELECT 1 FROM inv_ops WHERE state IN ('draft','submitted','rejected','transit') AND json_extract(body,'$.product_id')=?",[$id])->fetchColumn())iv('商品有未完成第二期业务');$p=iq($d,'SELECT * FROM inv_products WHERE id=?',[it($id)])->fetch(PDO::FETCH_ASSOC);if(!$p)iv('商品不存在');if(iq($d,'SELECT 1 FROM inv_balances WHERE product_id=? AND qty>0',[$id])->fetchColumn()||iq($d,"SELECT 1 FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.product_id=? AND x.status IN ('draft','submitted','rejected')",[$id])->fetchColumn())iv('所选商品有库存或未完成单据，不能批量停用');iq($d,'UPDATE inv_products SET active=0,version=version+1 WHERE id=?',[$id]);ilog($d,'product.archive',$id);}
 return ['count'=>count($ids)];
}

// Called within the API write transaction. Validate the whole batch before deleting.
function invDeleteProducts(PDO $d,array $a):array {
 $ids=$a['ids']??[];
 if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>200)iv('请选择 1–200 个商品');
 foreach($ids as $id)if(!is_string($id)||$id==='')iv('商品编号格式错误');
 $ids=array_values(array_unique($ids));
 $tables=$d->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
 $quote=fn($s)=>'"'.str_replace('"','""',$s).'"';
 $products=[];
 foreach($ids as $id){
  $p=iq($d,'SELECT * FROM inv_products WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$p)iv('商品不存在，请刷新列表');
  foreach($tables as $t){
   if(in_array($t,['inv_products','inv_images','inv_label_profiles'],true)||str_starts_with($t,'sqlite_'))continue;
   $cols=$d->query('PRAGMA table_info('.$quote($t).')')->fetchAll(PDO::FETCH_ASSOC);
   foreach($cols as $c){
    if($c['name']==='product_id'&&iq($d,'SELECT 1 FROM '.$quote($t).' WHERE product_id=? LIMIT 1',[$id])->fetchColumn())iv($p['name'].' 已有关联库存或业务记录，不能删除，请使用停用');
    if(in_array($t,['inv_ops','inv_print_jobs','state'],true)&&in_array($c['name'],['body','snapshot'],true)&&iq($d,'SELECT 1 FROM '.$quote($t).' WHERE instr('.$quote($c['name']).',?)>0 LIMIT 1',[$id])->fetchColumn())iv($p['name'].' 已有关联业务记录，不能删除，请使用停用');
   }
  }
  $products[]=$p;
 }
 foreach($products as $p){
  iq($d,'DELETE FROM inv_images WHERE product_id=?',[$p['id']]);
  if(in_array('inv_label_profiles',$tables,true))iq($d,'DELETE FROM inv_label_profiles WHERE product_id=?',[$p['id']]);
  iq($d,'DELETE FROM inv_products WHERE id=?',[$p['id']]);
  ilog($d,'product.delete',$p['id'],['code'=>$p['code'],'name'=>$p['name']]);
 }
 return ['count'=>count($products)];
}

// Structured name defaults live separately so old name lists remain recoverable.
function invProductNames(PDO $d):array {
 $raw=iq($d,"SELECT v FROM inv_meta WHERE k='product_name_defaults'")->fetchColumn();
 if($raw!==false)$entries=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 else {
  $old=iq($d,"SELECT v FROM inv_meta WHERE k='product_names'")->fetchColumn();
  $names=$old===false?['油烟机','燃气灶','热水器']:json_decode($old,true,512,JSON_THROW_ON_ERROR);
  $entries=array_map(fn($name)=>['name'=>$name,'kind'=>in_array($name,['油烟机','燃气灶','热水器'],true)?'整机':'','families'=>in_array($name,['油烟机','燃气灶','热水器'],true)?[$name]:[],'unit'=>in_array($name,['油烟机','燃气灶','热水器'],true)?'台':'个','sn_enabled'=>in_array($name,['油烟机','燃气灶','热水器'],true)],$names);
 }
 return ['entries'=>$entries,'names'=>array_column($entries,'name'),'version'=>hash('sha256',json_encode($entries,JSON_UNESCAPED_UNICODE))];
}
function invSaveProductNames(PDO $d,array $a):array {
 $current=invProductNames($d);
 if(($a['version']??'')!==$current['version'])iv('名称配置已更新，请关闭后重新打开');
 $entries=$a['entries']??null;
 if(!is_array($entries)||!array_is_list($entries)||count($entries)<1||count($entries)>200)iv('请保留 1–200 个名称配置');
 $clean=[];$seen=[];
 foreach($entries as $entry){
  if(!is_array($entry))iv('名称配置格式错误');
  $name=it($entry['name']??'',200);if($name===''||preg_match('/[\r\n\t]/u',$name))iv('名称不能为空或包含换行');
  if(in_array($name,$seen,true))iv('名称不能重复：'.$name);$seen[]=$name;
  $kind=$entry['kind']??'';$families=$entry['families']??[];$unit=$entry['unit']??'';$sn=$entry['sn_enabled']??null;
  if(!in_array($kind,['整机','配件','元器件','器材'],true))iv($name.'：请选择商品类型');
  if(!is_array($families)||!array_is_list($families)||!count($families)||count($families)>3)iv($name.'：请选择所属产品');
  foreach($families as $family)if(!in_array($family,['油烟机','燃气灶','热水器'],true))iv('所属产品无效');
  if(count(array_unique($families))!==count($families)||($kind==='整机'&&count($families)!==1))iv('整机只能选择一个所属产品；其他类型支持多个');
  if(!in_array($unit,['台','个','件','套','米'],true)||!is_bool($sn))iv('单位或 SN 设置无效');
  $clean[]=['name'=>$name,'kind'=>$kind,'families'=>$families,'unit'=>$unit,'sn_enabled'=>$sn];
 }
 iq($d,"INSERT INTO inv_meta(k,v) VALUES('product_name_defaults',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",[json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
 ilog($d,'product_names.update','product_name_defaults',['before'=>$current['entries'],'after'=>$clean]);
 return invProductNames($d);
}
