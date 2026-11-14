<?php
declare(strict_types=1);
if(!defined('DATA_DIR'))define('DATA_DIR','/var/lib/showcase-data');
function initialData():array{return ['settings'=>['name'=>'德澳总部','months'=>36],'orders'=>[],'cards'=>[],'stock'=>[],'dealers'=>[],'moves'=>[]];}
function database():PDO{
 if(!is_dir(DATA_DIR))throw new RuntimeException('数据库目录未安装');
 $db=new PDO('sqlite:'.DATA_DIR.'/business.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA busy_timeout=5000');$db->exec('PRAGMA foreign_keys=ON');
 $db->exec('CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS audit (revision INTEGER PRIMARY KEY, actor TEXT NOT NULL, updated_at TEXT NOT NULL, body TEXT NOT NULL)');
 $q=$db->prepare('INSERT OR IGNORE INTO state VALUES(1,0,?,?)');$q->execute([json_encode(initialData(),JSON_UNESCAPED_UNICODE),gmdate('c')]);return $db;
}
function validateData(mixed $d):void{
 if(!is_array($d)||!is_array($d['settings']??null))throw new InvalidArgumentException('业务数据格式不正确');
 $s=$d['settings'];if(!is_string($s['name']??null)||trim($s['name'])===''||strlen($s['name'])>200||!is_int($s['months']??null)||$s['months']<1||$s['months']>240)throw new InvalidArgumentException('设置格式不正确');
 $fields=['orders'=>['id','type','customer','phone','product','sn','channel','dealer','address','status','staff','date','note'],'cards'=>['id','customer','phone','product','sn','dealer','start','end'],'stock'=>['id','name','model','kind','unit'],'dealers'=>['id','name','contact','phone','area','status'],'moves'=>['id','name','date','type','target','order']];
 $serials=[];$dealerNames=[];
 foreach($fields as $table=>$required){
  if(!is_array($d[$table]??null)||!array_is_list($d[$table])||count($d[$table])>20000)throw new InvalidArgumentException('数据列表不正确：'.$table);
  $ids=[];
  foreach($d[$table] as $row){
   if(!is_array($row))throw new InvalidArgumentException('记录格式不正确');
   foreach($required as $key){if(!is_string($row[$key]??null)||strlen($row[$key])>10000)throw new InvalidArgumentException('字段格式不正确：'.$key);}
   if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$row['id'])||isset($ids[$row['id']]))throw new InvalidArgumentException('编号重复或格式不正确');$ids[$row['id']]=true;
   if($table==='orders'){
    if(!in_array($row['type'],['安装','维修'],true)||!in_array($row['status'],['待派工','待处理','处理中','已完工','已关闭'],true)||!is_array($row['history']??null)||!array_is_list($row['history']))throw new InvalidArgumentException('工单格式不正确');
    foreach($row['history'] as $h)if(!is_string($h)||strlen($h)>20000)throw new InvalidArgumentException('工单记录不正确');
   }
   if($table==='cards'){
    $sn=strtolower(trim($row['sn']));if($sn===''||isset($serials[$sn]))throw new InvalidArgumentException('机器编号重复或为空');$serials[$sn]=true;
    foreach(['start','end'] as $f){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$row[$f]);if(!$date||$date->format('Y-m-d')!==$row[$f])throw new InvalidArgumentException('保修日期不正确');}
    if($row['end']<$row['start'])throw new InvalidArgumentException('保修结束日期不能早于开始日期');
   }
   if($table==='stock')foreach(['qty','min'] as $f)if(!is_int($row[$f]??null)||$row[$f]<0||$row[$f]>100000000)throw new InvalidArgumentException('库存数量必须为非负整数');
   if($table==='dealers'){if(isset($dealerNames[$row['name']]))throw new InvalidArgumentException('经销商名称重复');$dealerNames[$row['name']]=true;}
   if($table==='moves'&&(!is_int($row['delta']??null)||$row['delta']===0||abs($row['delta'])>100000000))throw new InvalidArgumentException('出入库数量不正确');
  }
 }
}
function jsonReply(int $status,array $body):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function compatInventory(PDO $db,array $data):array{
 if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inv_meta'")->fetchColumn())return $data;
 $links=$db->query('SELECT card_id,sn FROM inv_card_links')->fetchAll(PDO::FETCH_KEY_PAIR);foreach($data['cards'] as &$card){if(isset($links[$card['id']]))$card['inventorySN']=$links[$card['id']];}unset($card);
 $old=[];foreach($data['stock'] as $p)$old[$p['id']]=$p;
 $data['stock']=[];
 foreach($db->query('SELECT p.*,COALESCE(SUM(b.qty),0) qty FROM inv_products p LEFT JOIN inv_balances b ON b.product_id=p.id GROUP BY p.id')->fetchAll(PDO::FETCH_ASSOC) as $p){$data['stock'][]=['id'=>$p['id'],'name'=>$p['name'],'model'=>$p['model'],'kind'=>$p['category'],'unit'=>$p['unit'],'qty'=>(int)$p['qty'],'min'=>$old[$p['id']]['min']??0,'deleted'=>!$p['active']];}
 return $data;
}
function handleDataApi():never{
 try{
  $db=database();
  if($_SERVER['REQUEST_METHOD']==='GET'){$row=$db->query('SELECT * FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);jsonReply(200,['revision'=>(int)$row['revision'],'data'=>compatInventory($db,json_decode($row['body'],true)),'csrf'=>$_SESSION['csrf']]);}
  if($_SERVER['REQUEST_METHOD']!=='POST')jsonReply(405,['error'=>'请求方法不支持']);
  if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))jsonReply(403,['error'=>'页面已过期，请刷新后重试']);
  $multipart = str_starts_with($_SERVER['CONTENT_TYPE']??'', 'multipart/form-data');
  if ($multipart && !isset($_FILES['image'])) throw new InvalidArgumentException('未收到二维码图片，请检查上传大小限制');
  $raw=$multipart ? ($_POST['state']??'') : file_get_contents('php://input',false,null,0,8000001);
  if(!is_string($raw)||strlen($raw)>8000000)jsonReply(413,['error'=>'业务数据超过8MB']);
  $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);validateData($input['data']??null);
  if(!is_int($input['revision']??null))throw new InvalidArgumentException('数据版本不正确');
  $db->exec('BEGIN IMMEDIATE');$row=$db->query('SELECT * FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
  if((int)$row['revision']!==$input['revision']){$db->exec('ROLLBACK');jsonReply(409,['error'=>'数据已被另一页面更新，本次未保存。请刷新后重新操作。']);}
  if(($input['import']??false)&&$row['revision']!=0){$db->exec('ROLLBACK');jsonReply(409,['error'=>'服务器已存在修改记录，不能覆盖导入']);}
  $previous=json_decode($row['body'],true);
  if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inv_meta'")->fetchColumn()){
   if($input['data']['stock']!==compatInventory($db,$previous)['stock']||$input['data']['moves']!==$previous['moves'])throw new InvalidArgumentException('库存已升级，请进入新版进销存，不能通过旧接口修改');
   $input['data']['stock']=$previous['stock'];
   $links=[];foreach($input['data']['cards'] as &$card){$q=$db->prepare('SELECT sn FROM inv_sn WHERE sn=? OR manufacturer_sn=?');$q->execute([$card['sn'],$card['sn']]);$sn=$q->fetchColumn();if($sn){if(isset($links[$sn]))throw new InvalidArgumentException('同一实际 SN 不能绑定多张保修卡');$links[$sn]=$card['id'];$card['inventorySN']=$sn;}else unset($card['inventorySN']);}unset($card);
   $db->exec('DELETE FROM inv_card_links');foreach($links as $sn=>$cid){$q=$db->prepare('INSERT INTO inv_card_links VALUES(?,?)');$q->execute([$cid,$sn]);}
  }
$before=[];foreach($previous['stock'] as $item)$before[$item['id']]=$item;
  $after=[];foreach($input['data']['stock'] as $item){
   $after[$item['id']]=$item;
   if(isset($item['deleted'])&&!is_bool($item['deleted']))throw new InvalidArgumentException('删除标记不正确');
   if(($item['deleted']??false)&&$item['qty']!==0)throw new InvalidArgumentException('非零库存物料不能删除');
   $old=$before[$item['id']]??null;
   if(!$old&&!in_array($item['unit'],['台','个','件'],true)&&!($input['import']??false))throw new InvalidArgumentException('请选择台、个或件');
   if($old){
    if($old['unit']!==$item['unit']&&($old['qty']!==0||!in_array($item['unit'],['台','个','件'],true)))throw new InvalidArgumentException('计量单位修改不符合要求');
    if(($item['deleted']??false)&&!($old['deleted']??false)&&$old['qty']!==0)throw new InvalidArgumentException('请先通过出库处理剩余库存');
    if(($old['deleted']??false)&&$item!==$old)throw new InvalidArgumentException('已删除物料不能修改');
   }
  }
  foreach($before as $id=>$item)if(!isset($after[$id]))throw new InvalidArgumentException('物料历史不能直接移除，请使用删除功能');
  foreach($input['data']['cards'] as &$card){
   $customer=$card['miniCustomerId']??'';
   if(!is_string($customer))throw new InvalidArgumentException('微信客户编号不正确');
   if(preg_match('/^DE([0-9]+)$/D',$customer,$member)){
    $q=$db->prepare('SELECT user_id FROM mini_members WHERE sequence=?');$q->execute([(int)$member[1]-8000000]);
    $customer=$q->fetchColumn();if(!$customer)throw new InvalidArgumentException('会员号不存在，请让客户先登录小程序');
    $card['miniCustomerId']=$customer;
   }
   if($customer!==''){$q=$db->prepare('SELECT id FROM mini_users WHERE id=?');$q->execute([$customer]);if(!$q->fetchColumn())throw new InvalidArgumentException('微信客户编号不存在，请让客户先登录小程序');}
  }
  unset($card);
  $oldOrders=[];foreach($previous['orders'] as $o)$oldOrders[$o['id']]=$o;
  foreach($input['data']['orders'] as &$o){if(isset($oldOrders[$o['id']])&&$oldOrders[$o['id']]['status']!==$o['status'])$o['publicUpdatedAt']=gmdate('c');}unset($o);
  if ($multipart) {
   require_once __DIR__.'/device-users.php';
   $source=$_POST['photo_source']??''; $id=$_POST['photo_record']??'';
   if (!in_array($source,['cards','orders'],true) || !is_string($id)) throw new InvalidArgumentException('二维码关联无效');
   $record=array_column($input['data'][$source],null,'id')[$id]??null;
   if (!$record || in_array($id,array_column($previous[$source],'id'),true)) throw new InvalidArgumentException('二维码须关联本次新增的设备');
   deviceUserSchema($db);
   [, $key]=deviceUserKeys($record,$source.':'.$id);
   deviceUserPhoto($db,$key,$_FILES['image']);
  }
  $body=json_encode($input['data'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$rev=(int)$row['revision']+1;$now=gmdate('c');
  $q=$db->prepare('INSERT INTO audit VALUES(?,?,?,?)');$q->execute([$row['revision'],$_SESSION['user'],$now,$row['body']]);
  $q=$db->prepare('UPDATE state SET revision=?,body=?,updated_at=? WHERE id=1');$q->execute([$rev,$body,$now]);$db->exec('COMMIT');
  jsonReply(200,['revision'=>$rev,'data'=>compatInventory($db,$input['data'])]);
 }catch(InvalidArgumentException|JsonException $e){jsonReply(422,['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('DEAOE data API: '.$e->getMessage());jsonReply(503,['error'=>'服务器保存失败，请稍后重试；本次未确认保存。']);}
}
