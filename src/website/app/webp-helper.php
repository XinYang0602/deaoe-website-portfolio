<?php
function deaoeWebp(string $source,string $destination): void {
 if(!function_exists('imagewebp'))throw new RuntimeException('服务器需要安装 PHP GD WebP 支持。');
 $info=@getimagesize($source);if(!$info)throw new RuntimeException('无法读取图片。');
 $mime=$info['mime'];

 if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('不支持的图片格式。');
 $limit=ini_get('memory_limit');$bytes=(int)$limit;$unit=strtolower(substr($limit,-1));if($unit==='g')$bytes*=1073741824;elseif($unit==='m')$bytes*=1048576;elseif($unit==='k')$bytes*=1024;
 if($bytes>0 && $info[0]*$info[1]*8+memory_get_usage(true)+16777216>$bytes)throw new RuntimeException('图片像素过大，请缩小后再上传。');
 $im=match($mime){'image/png'=>@imagecreatefrompng($source),'image/webp'=>@imagecreatefromwebp($source),default=>@imagecreatefromjpeg($source)};
 if(!$im)throw new RuntimeException('图片解码失败。');
 try{
  if(!imageistruecolor($im))imagepalettetotruecolor($im);
  imagealphablending($im,false);imagesavealpha($im,true);
  if($mime==='image/jpeg' && function_exists('exif_read_data')){
   $exif=@exif_read_data($source);$orientation=(int)($exif['Orientation']??1);
   if(in_array($orientation,[2,5,7],true))imageflip($im,IMG_FLIP_HORIZONTAL);
   if($orientation===4)imageflip($im,IMG_FLIP_VERTICAL);
   $angle=[3=>180,5=>90,6=>-90,7=>-90,8=>90][$orientation]??0;
   if($angle){$rotated=imagerotate($im,$angle,0);if(!$rotated)throw new RuntimeException('旋转图片失败。');imagedestroy($im);$im=$rotated;}
  }
  // WebP quality 80; preserve original resolution and alpha.
  if(!imagewebp($im,$destination,80)||!is_file($destination)||filesize($destination)===0)throw new RuntimeException('WebP转换失败。');
  chmod($destination,0644);
 }finally{imagedestroy($im);}
}
