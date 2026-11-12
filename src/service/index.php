<?php
declare(strict_types=1);
const AUTH_DIR = '/var/lib/showcase-auth';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$miniPath=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'');
// Only the inventory workspace may be embedded, and only by this exact origin.
$embeddedInventory=$miniPath==='/service/inventory' && ($_GET['embedded']??null)==='1';
header('X-Frame-Options: '.($embeddedInventory?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: frame-ancestors ".($embeddedInventory?"'self'":"'none'"));
if(is_file('/var/lib/showcase-data/phase1-maintenance')&&str_starts_with($miniPath,'/service/mini/')&&$miniPath!=='/service/mini/health'){http_response_code(503);exit('系统正在升级，请稍后重试');}
if(str_starts_with($miniPath,'/service/mini/')){require __DIR__.'/mini.php';handleMini($miniPath);}
session_name('DEAOE_SERVICE');
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/service/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$path=rawurldecode($path ?: '/service/');
$now=time();
if(isset($_SESSION['last']) && $now-(int)$_SESSION['last']>1800){$_SESSION=[];session_regenerate_id(true);}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function redirectHome():never{header('Location: /service/',true,303);exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST' && !in_array($path,['/service/api/state','/service/api/inventory','/service/api/images'],true)){
 if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);exit('页面已过期，请刷新后重试。');}
 if(($_POST['action']??'')==='logout'){$_SESSION=[];session_destroy();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/service/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);redirectHome();}
 if(($_POST['action']??'')==='login'){
  $account=json_decode((string)@file_get_contents(AUTH_DIR.'/account.json'),true);
  $lock=@fopen(AUTH_DIR.'/attempts.json','c+');
  if(!is_array($account)||!isset($account['hash'])||!$lock||!flock($lock,LOCK_EX)){http_response_code(503);exit('登录配置不可用，请联系管理员。');}
  $state=json_decode(stream_get_contents($lock),true)?:['count'=>0,'since'=>$now];
  if($now-(int)$state['since']>=900)$state=['count'=>0,'since'=>$now];
  if((int)$state['count']>=10){http_response_code(429);$error='尝试次数过多，请在 15 分钟后重试。';}
  else{
   $password=is_string($_POST['password']??null)?$_POST['password']:'';
   $valid=password_verify($password,$account['hash']);
   if(($_POST['username']??'')==='admin' && $valid){
    $state=['count'=>0,'since'=>$now];$_SESSION=[];session_regenerate_id(true);$_SESSION['user']='admin';$_SESSION['last']=$now;
if(is_file('/var/lib/showcase-data/phase1-maintenance')){http_response_code(503);exit('系统正在升级，请稍后刷新');}$_SESSION['csrf']=bin2hex(random_bytes(32));
   }else{$state['count']++;$error='账号或密码不正确。';http_response_code(401);}
  }
  rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($state));fflush($lock);flock($lock,LOCK_UN);fclose($lock);
  if(isset($_SESSION['user']))redirectHome();
 }
}
// Logo is the only public asset. All application HTML, CSS and JS require a session.
if($path==='/service/assets/deaoe-logo.png'){header('Content-Type: image/png');readfile(__DIR__.'/assets/deaoe-logo.png');exit;}
if(!isset($_SESSION['user'])){
 if(!in_array($path,['/service/','/service/index.php','/service/login'],true)){http_response_code(401);exit('请先登录服务管理平台。');}
 header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>管理员登录 · 服务管理平台</title><style>
*{box-sizing:border-box}body{margin:0;background:#202733;color:#252d38;font:14px -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;min-height:100vh;display:grid;place-items:center;padding:30px}.wrap{width:400px;max-width:100%}.brand{width:235px;display:block;margin:0 auto 18px}.subtitle{text-align:center;color:#aab4c2;letter-spacing:5px;margin:0 0 35px;font-size:14px}.panel{background:#fff;border-radius:12px;padding:36px;box-shadow:0 20px 60px #0002}h1{font-size:23px;margin:0 0 10px}.intro{font-size:12px;color:#8a929d;margin-bottom:28px}label{display:block;font-size:12px;color:#606c7a;margin-bottom:19px}input{width:100%;margin-top:9px;padding:12px;border:1px solid #dfe3e8;border-radius:5px;font:inherit}input:focus{outline:2px solid #d9b3af}button{width:100%;padding:13px;border:0;border-radius:5px;background:#c84039;color:#fff;font:inherit;cursor:pointer}.error{color:#b34036;background:#fff0ee;padding:12px;font-size:12px;line-height:1.6;border-radius:5px}.foot{text-align:center;font-size:11px;color:#8d98a9;margin-top:26px;line-height:1.8}

/* Mobile login: readable inputs prevent focus zoom; shrinkable grid keeps the card centered. */
@media(max-width:600px){
 body{grid-template-columns:minmax(0,1fr);padding:24px 16px;min-height:100svh}
 .wrap{width:100%;max-width:400px;min-width:0;justify-self:center}
 .brand{max-width:100%;height:auto}
 .panel{padding:24px;min-width:0}
 input{min-width:0;max-width:100%;font-size:16px;line-height:1.4}
}
</style></head><body><div class="wrap"><svg class="brand" viewBox="365 1223 7952 908" role="img" aria-label="DEAOE 德澳"><defs><filter id="white" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 -0.2126 -0.7152 -0.0722 0 1"/><feComposite in2="SourceAlpha" operator="in"/></filter></defs><image href="/service/assets/deaoe-logo.png" width="8661" height="3465" filter="url(#white)"/></svg><p class="subtitle">服务管理平台</p><div class="panel"><h1>管理员登录</h1><p class="intro">登录后管理业务、电子保修卡与库存。</p><?php if($error):?><p class="error" role="alert"><?=e($error)?></p><?php endif;?><form method="post" action="/service/"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><label>管理员账号<input name="username" value="admin" autocomplete="username" required></label><label>登录密码<input name="password" type="password" autocomplete="current-password" placeholder="请输入管理员密码" required maxlength="256"></label><button type="submit">登录管理平台</button></form></div><p class="foot">DEAOE 德澳 · 管理员工作空间<br>仅限授权人员访问</p></div></body></html>
<?php exit; }
$_SESSION['last']=$now;
if(is_file('/var/lib/showcase-data/phase1-maintenance')){http_response_code(503);exit('系统正在升级，请稍后刷新');}
if($path==='/service/api/inventory'){require __DIR__.'/inventory.php';handleInventory();}
if($path==='/service/api/images'){require __DIR__.'/images.php';handleImages();}
if($path==='/service/inventory'){header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/inventory.html');exit;}
if($path==='/service/api/state'){require __DIR__.'/storage.php';handleDataApi();}
if(in_array($path,['/service/','/service/index.php','/service/index.html'],true)){
 header('Content-Type: text/html; charset=utf-8');
 $html=file_get_contents(__DIR__.'/index.html');
 $logout='<form id="logout-form" method="post" action="/service/" style="margin:0"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'"><button type="submit">退出登录</button></form>';
 echo str_replace('<span id="today"></span>','<span id="today"></span>'.$logout,$html);exit;
}
$files=['/service/finance.js'=>['finance.js','application/javascript; charset=utf-8'],'/service/phase2.js'=>['phase2.js','application/javascript; charset=utf-8'],'/service/label-templates.js'=>['label-templates.js','application/javascript; charset=utf-8'],'/service/inventory.js'=>['inventory.js','application/javascript; charset=utf-8'],'/service/inventory.css'=>['inventory.css','text/css; charset=utf-8'],'/service/labels.js'=>['labels.js','application/javascript; charset=utf-8'],'/service/qrcode.js'=>['qrcode.js','application/javascript; charset=utf-8'],'/service/app.js'=>['app.js','application/javascript; charset=utf-8'],'/service/style.css'=>['style.css','text/css; charset=utf-8']];
if(isset($files[$path])){header('Content-Type: '.$files[$path][1]);readfile(__DIR__.'/'.$files[$path][0]);exit;}
http_response_code(404);echo '页面不存在';
