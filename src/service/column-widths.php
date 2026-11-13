<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
function handleColumnWidths():never {
 header('Content-Type: application/json; charset=utf-8');
 try {
 $db=database();$db->exec('CREATE TABLE IF NOT EXISTS ui_column_widths(actor TEXT NOT NULL,view_key TEXT NOT NULL,widths TEXT NOT NULL,PRIMARY KEY(actor,view_key))');
 $actor=(string)$_SESSION['user'];
 if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=$db->prepare('SELECT view_key,widths FROM ui_column_widths WHERE actor=?');$q->execute([$actor]);$views=[];foreach($q as $r)$views[$r['view_key']]=json_decode($r['widths'],true);
 echo json_encode(['views'=>$views,'csrf'=>$_SESSION['csrf']]);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){http_response_code(403);exit;}
 $raw=file_get_contents('php://input',false,null,0,16001);$v=json_decode($raw,true);
 if(strlen($raw)>16000||!is_array($v)||!is_string($v['key']??null)||strlen($v['key'])>2000||!is_array($v['widths']??null)||!array_is_list($v['widths'])||count($v['widths'])>100)throw new InvalidArgumentException();
 // Visibility uses a separate key; existing width records remain compatible.
 $visibility=str_starts_with($v['key'],'visibility:');
 if($visibility && (!count($v['widths']) || !in_array(false,$v['widths'],true)))throw new InvalidArgumentException();
 foreach($v['widths'] as $w)if($visibility ? !is_bool($w) : (!is_int($w)||$w<40||$w>1600))throw new InvalidArgumentException();
 $q=$db->prepare('INSERT INTO ui_column_widths VALUES(?,?,?) ON CONFLICT(actor,view_key) DO UPDATE SET widths=excluded.widths');$q->execute([$actor,$v['key'],json_encode($v['widths'])]);echo '{"ok":true}';
 }catch(InvalidArgumentException $e){http_response_code(400);echo '{"error":"列宽参数无效"}';}catch(Throwable $e){http_response_code(503);echo '{"error":"列宽保存失败，请重试"}';}
 exit;
}
