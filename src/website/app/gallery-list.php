<?php
require_once APP.'/image-controls.php';
function productGalleryImages(array $p): array {
 if(isset($p['gallery']) && is_array($p['gallery']))return array_values(array_filter($p['gallery'],fn($s)=>is_string($s)&&$s!==''));
 return array_values(array_filter([$p['gallery1']??'',$p['gallery2']??'']));
}
function saveProductGallery(array $p): array {
 $images=orderedProductImages(productGalleryImages($p),'gallery');$remove=$_POST['remove_gallery']??[];
 if(!is_array($remove))throw new RuntimeException('图片移除参数无效。');
 $images=array_values(array_filter($images,fn($s,$i)=>!in_array((string)$i,$remove,true),ARRAY_FILTER_USE_BOTH));
 $batch=$_FILES['gallery_uploads']??null;
 if($batch){
  if(!is_array($batch['error'])||count($batch['error'])>12)throw new RuntimeException('每批最多添加12张图片，可以保存后继续追加。');
  foreach($batch['error'] as $i=>$error){
   $_FILES['gallery_item']=['error'=>$error,'size'=>$batch['size'][$i],'tmp_name'=>$batch['tmp_name'][$i],'name'=>$batch['name'][$i]];
   $new=productUpload('gallery_item');if($new!==null)$images[]=$new;
  }
  unset($_FILES['gallery_item']);
 }
 $p['gallery']=$images;$p['gallery1']='';$p['gallery2']='';return $p;
}
function renderGalleryEditor(array $p): void {
 renderSortableImages(productGalleryImages($p),'gallery','展示图');
}
