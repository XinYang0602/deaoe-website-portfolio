<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
function uiPreferenceSchema(PDO $db):void {$db->exec('CREATE TABLE IF NOT EXISTS ui_preferences(actor TEXT PRIMARY KEY,font_scale INTEGER NOT NULL)');}
function uiPreferenceRead(PDO $db,string $actor):int {
 $q=$db->prepare('SELECT font_scale FROM ui_preferences WHERE actor=?');$q->execute([$actor]);$n=(int)$q->fetchColumn();return in_array($n,[100,110,125,150],true)?$n:100;
}
function uiPreferenceSave(PDO $db,string $actor,mixed $scale):void {
 if($actor===''||!is_int($scale)||!in_array($scale,[100,110,125,150],true))throw new InvalidArgumentException('字号参数无效');
 $q=$db->prepare('INSERT INTO ui_preferences(actor,font_scale) VALUES(?,?) ON CONFLICT(actor) DO UPDATE SET font_scale=excluded.font_scale');$q->execute([$actor,$scale]);
}
function handleUiPreferences():never {
 header('Content-Type: application/json; charset=utf-8');
 try{
  $actor=(string)($_SESSION['user']??'');if($actor===''){http_response_code(401);echo '{"error":"请先登录"}';exit;}
  $db=database();uiPreferenceSchema($db);
  if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){http_response_code(403);echo '{"error":"页面已过期，请刷新"}';exit;}
   $raw=file_get_contents('php://input',false,null,0,1025);if(strlen($raw)>1024)throw new InvalidArgumentException('请求过大');
   $body=json_decode($raw,true,8,JSON_THROW_ON_ERROR);uiPreferenceSave($db,$actor,$body['font_scale']??null);
  }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
  echo json_encode(['font_scale'=>uiPreferenceRead($db,$actor),'csrf'=>$_SESSION['csrf']]);
 }catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo '{"error":"字号参数无效"}';}
 catch(Throwable $e){http_response_code(503);echo '{"error":"字号设置未保存，请重试"}';}
 exit;
}
