<?php
require_once APP.'/image-controls.php';
function productDetailImages(array $p): array {
 if(isset($p['detail']) && is_array($p['detail']))return array_values(array_filter($p['detail'],fn($s)=>is_string($s)&&$s!==''));
 return array_values(array_filter([$p['detail1']??'',$p['detail2']??'']));
}
function saveProductDetails(array $p): array {
 $images=orderedProductImages(productDetailImages($p),'detail');$remove=$_POST['remove_detail']??[];
 if(!is_array($remove))throw new RuntimeException('图片移除参数无效。');
 $images=array_values(array_filter($images,fn($s,$i)=>!in_array((string)$i,$remove,true),ARRAY_FILTER_USE_BOTH));
 $batch=$_FILES['detail_uploads']??null;
 if($batch){
  if(!is_array($batch['error'])||count($batch['error'])>12)throw new RuntimeException('每批最多添加12张图片，可以保存后继续追加。');
  foreach($batch['error'] as $i=>$error){
   $_FILES['detail_item']=['error'=>$error,'size'=>$batch['size'][$i],'tmp_name'=>$batch['tmp_name'][$i],'name'=>$batch['name'][$i]];
   $new=productUpload('detail_item');if($new!==null)$images[]=$new;
  }
  unset($_FILES['detail_item']);
 }
 $p['detail']=$images;$p['detail1']='';$p['detail2']='';return $p;
}
function renderDetailEditor(array $p): void {
 renderSortableImages(productDetailImages($p),'detail','详情图');
}
