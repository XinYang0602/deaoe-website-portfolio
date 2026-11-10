<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
const MINI_APP_ID='wx0000000000000000';
const MINI_CONFIG='/var/lib/showcase-auth/wechat.json';
function miniSchema(PDO $db):void{
 $db->exec('CREATE TABLE IF NOT EXISTS mini_phones (user_id TEXT PRIMARY KEY, phone TEXT NOT NULL, country_code TEXT NOT NULL, verified_at TEXT NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS mini_avatars (user_id TEXT PRIMARY KEY, image TEXT NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS mini_users (id TEXT PRIMARY KEY, appid TEXT NOT NULL, openid TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(appid,openid))');
 // Keep internal IDs and existing device/order ownership unchanged.
 $db->exec('CREATE TABLE IF NOT EXISTS mini_members (sequence INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL UNIQUE REFERENCES mini_users(id))');
 $db->exec('INSERT INTO mini_members(user_id) SELECT id FROM mini_users WHERE id NOT IN (SELECT user_id FROM mini_members) ORDER BY created_at,id');
 $db->exec('CREATE TABLE IF NOT EXISTS mini_sessions (token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL, expires_at INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS mini_rate (key TEXT PRIMARY KEY, since INTEGER NOT NULL, count INTEGER NOT NULL)');
}
function miniBody(int $limit=16000):array{
 if((int)($_SERVER['CONTENT_LENGTH']??0)>$limit)jsonReply(413,['error'=>'提交内容过长']);
 $raw=file_get_contents('php://input',false,null,0,$limit+1);if(strlen($raw)>$limit)jsonReply(413,['error'=>'提交内容过长']);
 $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($value))throw new InvalidArgumentException('请求格式不正确');return $value;
}
function miniText(array $input,string $key,int $max=500):string{
 $v=$input[$key]??null;if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max)throw new InvalidArgumentException('请检查字段：'.$key);return trim($v);
}
function miniLimit(PDO $db,string $key,int $limit,int $period):void{
 $now=time();$db->exec('BEGIN IMMEDIATE');
 $q=$db->prepare('SELECT * FROM mini_rate WHERE key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r||$now-(int)$r['since']>=$period){$q=$db->prepare('INSERT OR REPLACE INTO mini_rate VALUES(?,?,1)');$q->execute([$key,$now]);}
 elseif((int)$r['count']>=$limit){$db->exec('ROLLBACK');jsonReply(429,['error'=>'操作过于频繁，请稍后重试']);}
 else{$q=$db->prepare('UPDATE mini_rate SET count=count+1 WHERE key=?');$q->execute([$key]);}
 $q=$db->prepare('DELETE FROM mini_rate WHERE since<?');$q->execute([$now-86400]);$db->exec('COMMIT');
}
function exchangeWechatCode(string $code):string{
 $config=json_decode((string)@file_get_contents(MINI_CONFIG),true);
 if(!is_array($config)||($config['appid']??'')!==MINI_APP_ID||empty($config['secret']))throw new RuntimeException('微信登录未配置，请联系管理员');
 $url='https://api.weixin.qq.com/sns/jscode2session?'.http_build_query(['appid'=>MINI_APP_ID,'secret'=>$config['secret'],'js_code'=>$code,'grant_type'=>'authorization_code']);
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$status!==200)throw new RuntimeException('微信服务暂时不可用，请稍后重试');
 $response=json_decode($raw,true);
 if(!is_array($response)||!empty($response['errcode'])||empty($response['openid'])){
  // Never log the URL, secret, code, session_key, or upstream message.
  error_log('DEAOE WeChat login rejected, errcode='.(int)($response['errcode']??-1));
  throw new RuntimeException('微信登录验证失败，请重试；持续失败请检查服务器 AppSecret');
 }
 return $response['openid'];
}
function miniUser(PDO $db):array{
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';
 if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$m))jsonReply(401,['error'=>'请先微信登录']);
 $q=$db->prepare('SELECT u.id,u.created_at FROM mini_sessions s JOIN mini_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?');$q->execute([hash('sha256',$m[1]),time()]);$u=$q->fetch(PDO::FETCH_ASSOC);
 if(!$u)jsonReply(401,['error'=>'登录已过期，请重新登录']);return $u;
}
function miniPublicUser(PDO $db,string $id):array{
 $q=$db->prepare('SELECT image FROM mini_avatars WHERE user_id=?');$q->execute([$id]);
 $avatar=$q->fetchColumn()?:'';
 $q=$db->prepare('SELECT phone,country_code FROM mini_phones WHERE user_id=?');$q->execute([$id]);$phone=$q->fetch(PDO::FETCH_ASSOC);
 $q=$db->prepare('SELECT sequence FROM mini_members WHERE user_id=?');$q->execute([$id]);$sequence=$q->fetchColumn();
 if($sequence===false)throw new RuntimeException('会员号未分配');
 return ['id'=>$id,'memberNo'=>'DE'.(8000000+(int)$sequence),'avatar'=>$avatar,'phone'=>$phone['phone']??'','countryCode'=>$phone['country_code']??''];
}
function miniWechatPost(string $url,array $body):array{
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$status!==200)throw new InvalidArgumentException('微信手机号服务暂不可用，请稍后重新授权');
 $r=json_decode($raw,true);if(!is_array($r))throw new InvalidArgumentException('微信手机号服务返回异常，请重试');return $r;
}
function miniVerifiedPhone(array $r):array{
 if((int)($r['errcode']??0)!==0)throw new InvalidArgumentException('手机号授权失败，请重新授权；持续失败请管理员检查微信手机号接口权限与额度');
 $p=$r['phone_info']??[];$phone=$p['purePhoneNumber']??'';$country=$p['countryCode']??'';
 if(!is_string($phone)||!preg_match('/^[0-9]{4,15}$/D',$phone)||!is_string($country)||!preg_match('/^[0-9]{1,4}$/D',$country)||($p['watermark']['appid']??'')!==MINI_APP_ID)throw new InvalidArgumentException('手机号验证结果不正确，请重新授权');
 return [$phone,$country];
}
function miniExchangePhone(string $code):array{
 $config=json_decode((string)@file_get_contents(MINI_CONFIG),true);
 if(!is_array($config)||($config['appid']??'')!==MINI_APP_ID||empty($config['secret']))throw new InvalidArgumentException('微信配置未就绪，请联系管理员');
 // stable_token does not invalidate other active callers; never expose credentials.
 $token=miniWechatPost('https://api.weixin.qq.com/cgi-bin/stable_token',['grant_type'=>'client_credential','appid'=>MINI_APP_ID,'secret'=>$config['secret'],'force_refresh'=>false]);
 if(empty($token['access_token']))throw new InvalidArgumentException('微信接口凭证获取失败，请管理员检查AppSecret及接口调用IP配置');
 $r=miniWechatPost('https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token='.rawurlencode($token['access_token']),['code'=>$code]);
 return miniVerifiedPhone($r);
}

function customerOrder(array $o):array{
 $created=$o['createdAt']??($o['date'].'T00:00:00+08:00');
 $events=[['title'=>'服务申请已提交','detail'=>'等待客服确认预约并安排服务。','date'=>substr($created,0,10)]];
 if($o['status']!=='待派工')$events[]=['title'=>$o['status'],'detail'=>'当前处理状态已由客服更新。','date'=>substr($o['publicUpdatedAt']??'',0,10)];
 return ['id'=>$o['id'],'deviceId'=>$o['miniDeviceId']??'','product'=>$o['product'],'model'=>'','sn'=>$o['sn'],'type'=>$o['type'],'name'=>$o['customer'],'phone'=>$o['phone'],'address'=>$o['address'],'description'=>$o['note'],'date'=>$o['date'],'status'=>$o['status'],'createdAt'=>$created,'events'=>$events];
}
function handleMini(string $path):never{
 try{
  $db=database();miniSchema($db);$method=$_SERVER['REQUEST_METHOD'];
  if($path==='/service/mini/health'&&$method==='GET')jsonReply(200,['service'=>'deaoe-mini','configured'=>is_file(MINI_CONFIG)]);
  if($path==='/service/mini/login'&&$method==='POST'){
   miniLimit($db,'login:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown'),40,600);
   $input=miniBody();$code=miniText($input,'code',256);$openid=exchangeWechatCode($code);$now=time();
   $db->exec('BEGIN IMMEDIATE');$q=$db->prepare('SELECT id FROM mini_users WHERE appid=? AND openid=?');$q->execute([MINI_APP_ID,$openid]);$id=$q->fetchColumn();$isNewUser=($id===false);
   if(!$id){$id='WX'.strtoupper(bin2hex(random_bytes(8)));$q=$db->prepare('INSERT INTO mini_users VALUES(?,?,?,?)');$q->execute([$id,MINI_APP_ID,$openid,gmdate('c')]);}
   $q=$db->prepare('INSERT INTO mini_members(user_id) SELECT ? WHERE NOT EXISTS (SELECT 1 FROM mini_members WHERE user_id=?)');$q->execute([$id,$id]);
   $q=$db->prepare('DELETE FROM mini_sessions WHERE expires_at<=?');$q->execute([$now]);
   $token=bin2hex(random_bytes(32));$expires=$now+7*86400;$q=$db->prepare('INSERT INTO mini_sessions VALUES(?,?,?)');$q->execute([hash('sha256',$token),$id,$expires]);$db->exec('COMMIT');
   jsonReply(200,['token'=>$token,'expiresAt'=>$expires,'user'=>miniPublicUser($db,$id),'isNewUser'=>$isNewUser]);
  }
  $user=miniUser($db);
  if($path==='/service/mini/me'&&$method==='GET')jsonReply(200,['user'=>miniPublicUser($db,$user['id'])]);
  if($path==='/service/mini/avatar'&&$method==='POST'){
   miniLimit($db,'avatar:'.$user['id'],20,3600);
   $input=miniBody(360000);$encoded=$input['avatar']??null;
   if(!is_string($encoded))throw new InvalidArgumentException('请选择头像');
   $bytes=base64_decode($encoded,true);
   if($bytes===false||strlen($bytes)>262144)throw new InvalidArgumentException('头像不能超过256KB');
   $info=@getimagesizefromstring($bytes);
   if(!$info||!in_array($info['mime'],['image/jpeg','image/png'],true)||$info[0]>2048||$info[1]>2048)throw new InvalidArgumentException('请选择2048像素以内的PNG或JPG头像');
   $image='data:'.$info['mime'].';base64,'.base64_encode($bytes);
   $q=$db->prepare('INSERT OR REPLACE INTO mini_avatars(user_id,image) VALUES(?,?)');$q->execute([$user['id'],$image]);
   jsonReply(200,['user'=>miniPublicUser($db,$user['id'])]);
  }
  if($path==='/service/mini/phone'&&$method==='POST'){
   miniLimit($db,'phone:'.$user['id'],10,3600);$input=miniBody();
   if(($input['agreed']??false)!==true)throw new InvalidArgumentException('请先同意服务与信息使用说明');
   $code=miniText($input,'code',512);[$phone,$country]=miniExchangePhone($code);
   $q=$db->prepare('INSERT OR REPLACE INTO mini_phones(user_id,phone,country_code,verified_at) VALUES(?,?,?,?)');$q->execute([$user['id'],$phone,$country,gmdate('c')]);
   jsonReply(200,['user'=>miniPublicUser($db,$user['id'])]);
  }
  if($path==='/service/mini/logout'&&$method==='POST'){
   $q=$db->prepare('DELETE FROM mini_sessions WHERE token_hash=?');$q->execute([hash('sha256',substr($_SERVER['HTTP_AUTHORIZATION'],7))]);jsonReply(200,['ok'=>true]);
  }
  if($path==='/service/mini/devices'&&$method==='GET'){
   $state=json_decode($db->query('SELECT body FROM state WHERE id=1')->fetchColumn(),true);$items=[];
   foreach($state['cards'] as $c)if(($c['miniCustomerId']??'')===$user['id'])$items[]=['id'=>$c['id'],'name'=>$c['product'],'model'=>'','sn'=>$c['sn'],'start'=>$c['start'],'end'=>$c['end'],'channel'=>$c['dealer'],'status'=>$c['end']>=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d')?'保修期内':'已过保'];
   jsonReply(200,['items'=>$items]);
  }
  if($path==='/service/mini/orders'&&$method==='GET'){
   $state=json_decode($db->query('SELECT body FROM state WHERE id=1')->fetchColumn(),true);$items=[];
   foreach($state['orders'] as $o)if(($o['miniCustomerId']??'')===$user['id'])$items[]=customerOrder($o);jsonReply(200,['items'=>$items]);
  }
  if($path==='/service/mini/orders'&&$method==='POST'){
   miniLimit($db,'submit:'.$user['id'],30,3600);$input=miniBody();
   $deviceId=miniText($input,'deviceId',80);$key=miniText($input,'requestKey',100);$type=miniText($input,'type',10);$name=miniText($input,'name',30);$phone=miniText($input,'phone',20);$address=miniText($input,'address',150);$note=miniText($input,'description',500);$date=miniText($input,'date',10);
   if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$key))throw new InvalidArgumentException('请求标识不正确');
   if(!in_array($type,['安装','维修'],true)||!preg_match('/^1[0-9]{10}$/D',$phone)||mb_strlen($address)<5||mb_strlen($note)<5||($input['agreed']??false)!==true)throw new InvalidArgumentException('请检查手机号、地址、需求描述及授权确认');
   $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Shanghai'));$today=new DateTimeImmutable('today',new DateTimeZone('Asia/Shanghai'));
   if(!$day||$day->format('Y-m-d')!==$date||$day<$today||$day>$today->modify('+1 year'))throw new InvalidArgumentException('请选择未来一年以内的预约日期');
   $db->exec('BEGIN IMMEDIATE');$row=$db->query('SELECT * FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);$state=json_decode($row['body'],true);
   foreach($state['orders'] as $o)if(($o['miniCustomerId']??'')===$user['id']&&($o['miniRequestKey']??'')===$key){$db->exec('ROLLBACK');jsonReply(200,['order'=>customerOrder($o)]);}
   $card=null;foreach($state['cards'] as $c)if($c['id']===$deviceId&&($c['miniCustomerId']??'')===$user['id'])$card=$c;
   if(!$card){$db->exec('ROLLBACK');jsonReply(403,['error'=>'此设备未绑定到当前微信账号，请联系门店核实']);}
   $now=gmdate('c');$order=['id'=>'GD'.strtoupper(bin2hex(random_bytes(10))),'type'=>$type,'customer'=>$name,'phone'=>$phone,'product'=>$card['product'],'sn'=>$card['sn'],'channel'=>$card['dealer']==='总部直营'?'直营':'经销商','dealer'=>$card['dealer'],'address'=>$address,'status'=>'待派工','staff'=>'未指派','date'=>$date,'note'=>$note,'history'=>['客户通过微信小程序提交服务申请'],'miniCustomerId'=>$user['id'],'miniDeviceId'=>$deviceId,'miniRequestKey'=>$key,'createdAt'=>$now,'publicUpdatedAt'=>$now];
   array_unshift($state['orders'],$order);validateData($state);$body=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($body)>8000000)throw new RuntimeException('业务数据已达容量限制，请联系管理员');
   $q=$db->prepare('INSERT INTO audit VALUES(?,?,?,?)');$q->execute([$row['revision'],$user['id'],$now,$row['body']]);$q=$db->prepare('UPDATE state SET revision=?,body=?,updated_at=? WHERE id=1');$q->execute([(int)$row['revision']+1,$body,$now]);$db->exec('COMMIT');
   jsonReply(201,['order'=>customerOrder($order)]);
  }
  jsonReply(404,['error'=>'接口不存在或请求方法不支持']);
 }catch(InvalidArgumentException|JsonException $e){jsonReply(422,['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('DEAOE mini API failed: '.get_class($e));jsonReply(503,['error'=>'服务暂不可用，请稍后重试；若持续失败请联系管理员检查微信配置与服务状态']);}
}
