<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
require_once __DIR__.'/upload-webp.php';
function handleImages():never{
 $paths=[];$d=null;$tx=false;
 try{
 if(($_SESSION['user']??'')!=='admin')jsonReply(403,['error'=>'无图片权限']);$d=database();$dir=DATA_DIR.'/images';
 if($_SERVER['REQUEST_METHOD']==='GET'){$id=it($_GET['id']??'');if(!preg_match('/^IMG[A-Z0-9]+$/D',$id)||!($name=iq($d,'SELECT name FROM inv_images WHERE id=?',[$id])->fetchColumn()))jsonReply(404,['error'=>'图片不存在']);$path=$dir.'/'.$id.(isset($_GET['thumb'])?'-thumb':'').'.webp';if(!is_file($path))jsonReply(404,['error'=>'图片文件不存在']);serviceWebpHeaders($id,$name,filesize($path),isset($_GET['download']));readfile($path);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST')jsonReply(405,['error'=>'请求方法错误']);if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))jsonReply(403,['error'=>'页面过期']);
 $pid=it($_POST['product_id']??'');if(!iq($d,'SELECT 1 FROM inv_products WHERE id=?',[$pid])->fetchColumn())iv('请先保存商品');$f=$_FILES['image']??null;$variants=serviceWebpVariants($f??[],[''=>1800,'-thumb'=>320]);
 if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('无法建立图片目录');$id=iid('IMG');
 foreach($variants as $suffix=>$bytes){
  $path=$dir.'/'.$id.$suffix.'.webp';$paths[]=$path;
  if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('图片写入失败');
  chmod($path,0600);
 }
 $d->exec('BEGIN IMMEDIATE');$tx=true;iq($d,'INSERT INTO inv_images VALUES(?,?,?,?,?)',[$id,$pid,serviceWebpName($f['name']),filesize($paths[0]),gmdate('c')]);ilog($d,'image.upload',$id,['product_id'=>$pid]);$d->exec('COMMIT');$tx=false;jsonReply(200,['id'=>$id]);
 }catch(Throwable $e){if($tx)$d->exec('ROLLBACK');foreach($paths as $p)if(is_file($p))unlink($p);if($e instanceof InvalidArgumentException)jsonReply(422,['error'=>$e->getMessage()]);error_log('Images: '.$e->getMessage());jsonReply(503,['error'=>'图片未保存，请稍后重试']);}
}
