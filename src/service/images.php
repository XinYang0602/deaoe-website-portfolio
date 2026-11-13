<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
function handleImages():never{
 $paths=[];$d=null;$tx=false;
 try{
 if(($_SESSION['user']??'')!=='admin')jsonReply(403,['error'=>'无图片权限']);$d=database();$dir=DATA_DIR.'/images';
 if($_SERVER['REQUEST_METHOD']==='GET'){$id=it($_GET['id']??'');if(!preg_match('/^IMG[A-Z0-9]+$/D',$id)||!iq($d,'SELECT 1 FROM inv_images WHERE id=?',[$id])->fetchColumn())jsonReply(404,['error'=>'图片不存在']);$path=$dir.'/'.$id.(isset($_GET['thumb'])?'-thumb':'').'.webp';if(!is_file($path))jsonReply(404,['error'=>'图片文件不存在']);header('Content-Type: image/webp');readfile($path);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST')jsonReply(405,['error'=>'请求方法错误']);if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))jsonReply(403,['error'=>'页面过期']);
 $pid=it($_POST['product_id']??'');if(!iq($d,'SELECT 1 FROM inv_products WHERE id=?',[$pid])->fetchColumn())iv('请先保存商品');$f=$_FILES['image']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)iv('上传失败，请选择不超过 8MB 的图片');if($f['size']>8*1024*1024)iv('图片超过 8MB');$meta=@getimagesize($f['tmp_name']);if(!$meta||!in_array($meta[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP])||$meta[0]>10000||$meta[1]>10000||$meta[0]*$meta[1]>16000000)iv('只支持 JPG/PNG/WebP，最多 1600 万像素及单边 10000 像素');$im=@imagecreatefromstring(file_get_contents($f['tmp_name']));if(!$im)iv('图片无法解码');
 if($meta[2]===IMAGETYPE_JPEG){$ex=@exif_read_data($f['tmp_name']);$o=(int)($ex['Orientation']??1);if(in_array($o,[2,4,5,7]))imageflip($im,IMG_FLIP_HORIZONTAL);$angle=match($o){3,4=>180,6,7=>-90,5,8=>90,default=>0};if($angle)$im=imagerotate($im,$angle,0);}
 if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('无法建立图片目录');$id=iid('IMG');
 foreach([''=>1800,'-thumb'=>320] as $suffix=>$edge){$scale=min(1,$edge/max(imagesx($im),imagesy($im)));$w=max(1,(int)round(imagesx($im)*$scale));$h=max(1,(int)round(imagesy($im)*$scale));$out=imagecreatetruecolor($w,$h);imagealphablending($out,false);imagesavealpha($out,true);imagefill($out,0,0,imagecolorallocatealpha($out,0,0,0,127));imagecopyresampled($out,$im,0,0,0,0,$w,$h,imagesx($im),imagesy($im));$path=$dir.'/'.$id.$suffix.'.webp';$paths[]=$path;if(!imagewebp($out,$path,85)||!filesize($path))throw new RuntimeException('转换失败');chmod($path,0600);imagedestroy($out);}imagedestroy($im);
 $d->exec('BEGIN IMMEDIATE');$tx=true;iq($d,'INSERT INTO inv_images VALUES(?,?,?,?,?)',[$id,$pid,mb_substr(basename($f['name']),0,120),filesize($paths[0]),gmdate('c')]);ilog($d,'image.upload',$id,['product_id'=>$pid]);$d->exec('COMMIT');$tx=false;jsonReply(200,['id'=>$id]);
 }catch(Throwable $e){if($tx)$d->exec('ROLLBACK');foreach($paths as $p)if(is_file($p))unlink($p);if($e instanceof InvalidArgumentException)jsonReply(422,['error'=>$e->getMessage()]);error_log('Images: '.$e->getMessage());jsonReply(503,['error'=>'图片未保存，请稍后重试']);}
}
