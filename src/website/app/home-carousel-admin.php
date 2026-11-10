<?php
require_once APP.'/home-carousel.php';require_once APP.'/products-lib.php';
if(empty($_SESSION['auth'])){header('Location: /admin/');exit;}
header('Cache-Control: no-store');
$error='';$notice=$_SESSION['home_carousel_notice']??'';unset($_SESSION['home_carousel_notice']);
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('页面已过期，请刷新。');
  $action=(string)($_POST['action']??'');
  locked(function()use($action){
   $s=homeCarouselStore();if((int)($_POST['revision']??-1)!==$s['revision'])throw new RuntimeException('内容已在其他窗口修改，请刷新后重试。');
   if($action==='save'){
    $key=(string)($_POST['group']??'');if(!isset(homeCarouselGroups()[$key]))throw new RuntimeException('无效图片分组。');
    $order=json_decode((string)($_POST['order']??''),true);$old=$s['draft']['groups'][$key];
    if(!is_array($order)||count($order)!==count(array_unique($order,SORT_REGULAR)))throw new RuntimeException('图片顺序无效。');
    $batch=$_FILES['pictures']??null;$errors=$batch['error']??[];
    if(!is_array($errors)||count($errors)>12)throw new RuntimeException('每批最多上传12张。');
    if(array_sum($batch['size']??[])>30*1024*1024)throw new RuntimeException('每批上传总量不能超过30 MB。');
    $next=[];
    foreach($order as $token){
     if(!is_string($token)||!preg_match('/^(old|new)_(0|[1-9][0-9]*)$/D',$token,$match))throw new RuntimeException('图片顺序无效。');
     $index=(int)$match[2];
     if($match[1]==='old'){
      if(!array_key_exists($index,$old))throw new RuntimeException('图片已变化，请刷新。');$next[]=$old[$index];
     }else{
      if(!array_key_exists($index,$errors)||$errors[$index]===UPLOAD_ERR_NO_FILE)throw new RuntimeException('上传图片丢失，请重新选择。');
      $_FILES['home_picture']=['error'=>$errors[$index],'size'=>$batch['size'][$index],'tmp_name'=>$batch['tmp_name'][$index],'name'=>$batch['name'][$index]];
      $next[]=productUpload('home_picture');
     }
    }
    if(!$next)throw new RuntimeException('请至少保留一张图片。');
    $s['draft']['groups'][$key]=$next;
   }elseif($action==='interval'){
    $value=filter_var($_POST['interval']??null,FILTER_VALIDATE_INT);if($value===false||$value<3||$value>30)throw new RuntimeException('轮播间隔请填写3至30秒。');$s['draft']['interval']=$value;
   }elseif($action==='publish'){$s['previous']=$s['live'];$s['live']=$s['draft'];}
   elseif($action==='restore'){
    if(!$s['previous'])throw new RuntimeException('没有上一版可恢复。');$p=$s['previous'];$s['previous']=$s['live'];$s['live']=$p;$s['draft']=$p;
   }else throw new RuntimeException('无效操作。');
   $s['revision']++;writeData('home-carousel',$s);
  });
  $_SESSION['home_carousel_notice']=$action==='publish'?'轮播已发布，官网已更新。':($action==='restore'?'已恢复上一版。':'草稿已保存。请预览后发布。');header('Location: /admin/?home_carousel=1');exit;
 }
}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'操作失败，请检查服务器日志。';}
if(isset($_GET['preview'])){$GLOBALS['homeCarouselPreview']=true;echo renderPage(readData('published',defaults()));exit;}
$s=homeCarouselStore();
function homeHidden():void {global $s;echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="revision" value="'.$s['revision'].'">';}
productHeader('首页轮播管理');
?>
<main class="admin-main"><h1>首页轮播管理</h1><p><a href="/admin/">← 返回首页内容管理</a></p><p>每个位置独立管理图片。悬停、键盘操作及触摸时暂停，离开后继续轮播。单张图片不自动切换。</p>
<?php if($error):?><p class="message error" role="alert"><?=h($error)?> 本次未保存的修改需重新操作。</p><?php endif;?>
<?php if($notice):?><p class="message" role="status"><?=h($notice)?></p><?php endif;?>
<form method="post"><?php homeHidden();?><fieldset><legend>轮播时间</legend><label>每隔几秒切换（3—30秒）<input type="number" name="interval" min="3" max="30" value="<?=$s['draft']['interval']?>"></label><button class="button" name="action" value="interval">保存轮播时间</button></fieldset></form>
<?php foreach(homeCarouselGroups() as $key=>$label):?>
<form method="post" enctype="multipart/form-data" class="home-image-form"><?php homeHidden();?><input type="hidden" name="group" value="<?=$key?>"><input type="hidden" name="action" value="save"><input type="hidden" name="order" value="">
<fieldset><legend><?=h($label)?></legend><p class="muted">拖动排序，或点击前移、后移。可多次添加图片，每批最多12张、合计30 MB，单张5 MB。确认删除后先从编辑界面移除，保存才生效。</p>
<div class="home-editor-list">
<?php foreach($s['draft']['groups'][$key] as $i=>$src):?><div class="home-editor-card" data-token="old_<?=$i?>"><button type="button" class="home-drag">⠿ 拖动排序</button><img src="<?=h($src)?>" alt="<?=h($label)?>图片<?=$i+1?>" draggable="false"><div><button type="button" data-move="-1">前移</button><button type="button" data-move="1">后移</button><button type="button" data-remove>删除</button></div></div><?php endforeach;?>
</div><label>添加图片（可多选）<input type="file" accept="image/jpeg,image/png,image/webp" name="pictures[]" multiple></label><button class="button">保存本组草稿</button><span class="home-dirty" role="status"></span></fieldset></form>
<?php endforeach;?>
<div class="publish-controls"><a class="button outline" target="_blank" rel="noopener" href="/admin/?home_carousel=1&amp;preview=1">预览首页轮播</a><form method="post" class="home-publish"><?php homeHidden();?><button class="button" name="action" value="publish">发布全部轮播</button><button class="button outline" name="action" value="restore">恢复上一版</button></form></div>
</main><style><?=file_get_contents(APP.'/home-editor.css')?></style><script><?=file_get_contents(APP.'/home-editor.js')?></script><?php productFooter();?>
