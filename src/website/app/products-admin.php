<?php
require_once APP.'/products-lib.php';
if(empty($_SESSION['auth'])){header('Location: /admin/');exit;}
header('Cache-Control: no-store');
$error='';$notice=$_SESSION['product_notice']??'';unset($_SESSION['product_notice']);
$id=is_string($_GET['edit']??null)?$_GET['edit']:'';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('页面已过期，请刷新后重试。');
  $action=(string)($_POST['action']??'');$id=(string)($_POST['id']??'');
  if(!in_array($action,['new','duplicate','delete','save','publish','unpublish','restore'],true))throw new RuntimeException('无效操作。');
  $id=locked(function()use($action,$id){
   $s=productStore();if((int)($_POST['revision']??-1)!==$s['revision'])throw new RuntimeException('另一窗口已修改产品，请刷新后再编辑。');
   if($action==='new'){$id=bin2hex(random_bytes(8));$s['items'][$id]=['draft'=>emptyProduct(),'live'=>null,'previous'=>null];}
   /* delete-product-v1 */
   elseif($action==='delete'){
    if(!isset($s['items'][$id]))throw new RuntimeException('未找到要删除的产品，请刷新页面。');
    // Preserve a recoverable private record; uploaded images may be shared by copies.
    $deleted=readData('deleted-products',['items'=>[]]);
    $key=$id.'-'.bin2hex(random_bytes(8));
    $deleted['items'][$key]=['id'=>$id,'deleted_at'=>date('c'),'record'=>$s['items'][$id]];
    writeData('deleted-products',$deleted);
    unset($s['items'][$id]);
   }
   elseif($action==='duplicate'){
    if(!isset($s['items'][$id]))throw new RuntimeException('未找到要复制的产品。');
    $copy=array_merge(emptyProduct(),$s['items'][$id]['draft']);
    $copy['name']=$copy['name'].'（副本）';
    $id=bin2hex(random_bytes(8));
    $s['items'][$id]=['draft'=>$copy,'live'=>null,'previous'=>null];
   }
   else{
    if(!isset($s['items'][$id]))throw new RuntimeException('未找到产品。');
    $r=&$s['items'][$id];
    if($action==='save'){
     $p=array_merge(emptyProduct(),$r['draft']);
     foreach(productFields() as $key=>$label){if($key==='specs')continue;$p[$key]=trim((string)($_POST[$key]??''));if(strlen($p[$key])>18000)throw new RuntimeException($label.'内容过长。');}
     $p['category']=(string)($_POST['category']??'hood');
     if(!isset(productCategories()[$p['category']]))throw new RuntimeException('请选择有效产品分类。');
     foreach(['purchase','video','manual'] as $key)if(!productURL($p[$key]))throw new RuntimeException('链接需以 https:// 或 http:// 开头，或留空。');
     foreach([] as $line){$pair=explode('|',$line,2);if(count($pair)!==2 || trim($pair[0])==='' || trim($pair[1])==='')throw new RuntimeException('技术参数每行请使用“参数名称 | 参数值”，例如“产品型号 | CXW-300-805”。');}
     foreach(productImages() as $key=>$label){if($key==='size')continue;if(in_array($key,['gallery1','gallery2','detail1','detail2'],true))continue;if(isset($_POST['remove_'.$key]))$p[$key]='';$new=productUpload($key);if($new!==null)$p[$key]=$new;}
     $p=saveProductGallery($p);
     $p=saveProductDetails($p);
     $r['draft']=$p;
    }elseif($action==='publish'){
     $p=$r['draft'];if(!$p['name']||!$p['model']||!$p['main'])throw new RuntimeException('上架前请填写产品名称、型号并上传主图，然后保存草稿。');
     $r['previous']=$r['live'];$r['live']=$p;
    }elseif($action==='unpublish'){
     if($r['live'])$r['previous']=$r['live'];$r['live']=null;
    }else{
     if(!$r['previous'])throw new RuntimeException('暂无上一版可恢复。');
     $previous=$r['previous'];$r['previous']=$r['live'];$r['live']=$previous;$r['draft']=$previous;
    }
   }
   $s['revision']++;writeData('product-catalog',$s);return $id;
  });
  $_SESSION['product_notice']=['delete'=>'产品已删除，官网已移除。','duplicate'=>'已复制为新产品草稿，请修改名称和型号后发布。','new'=>'已创建产品草稿。','save'=>'草稿已保存，官网尚未更新。','publish'=>'产品已上架，官网已更新。','unpublish'=>'产品已下架，资料仍保留在后台。','restore'=>'已恢复上一版，并同步到官网及草稿。'][$action];
  header('Location: /admin/?products=1'.($action==='delete'?'':'&edit='.rawurlencode($id)));exit;
 }
}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'保存失败，请检查服务器日志。';error_log($e->getMessage());}
$store=productStore();
if(isset($_GET['preview'])){
 $preview=is_string($_GET['preview'])?$_GET['preview']:'';
 if(!isset($store['items'][$preview])){http_response_code(404);echo '产品不存在';exit;}
 renderProduct($store['items'][$preview]['draft'],true);exit;
}
if($id!==''&&!isset($store['items'][$id])){$error='产品不存在，请从列表重新选择。';$id='';}
$p=$id!==''?array_merge(emptyProduct(),$store['items'][$id]['draft']):null;
if($error && ($_POST['action']??'')==='save' && $p){foreach(productFields() as $key=>$label)$p[$key]=(string)($_POST[$key]??$p[$key]);$p['category']=(string)($_POST['category']??$p['category']);}
function productHidden(string $id): void {global $store;echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="revision" value="'.$store['revision'].'"><input type="hidden" name="id" value="'.h($id).'">';}
productHeader('产品管理');
?><main class="admin-main"><div class="admin-heading"><div><p class="eyebrow">CONTENT MANAGEMENT</p><h1>产品管理</h1><p>添加产品，保存草稿，预览确认后上架。</p></div><a href="/admin/">返回首页内容管理 ↗</a></div>
<?php if($error):?><p class="message error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($notice):?><p class="message" role="status"><?=h($notice)?></p><?php endif;?>
<div class="admin-toolbar"><form method="post"><?php productHidden('');?><button class="button" name="action" value="new">＋ 添加产品</button></form><a href="/?products=1" target="_blank" rel="noopener">查看官网产品列表 ↗</a></div>
<div class="admin-layout"><aside class="admin-list admin-product-tree" aria-label="按分类和系列管理产品">
<h2>全部产品 <small><?=count($store['items'])?></small></h2>
<?php
$tree=[];$treeLabels=productCategories();
foreach($treeLabels as $categoryKey=>$categoryLabel)$tree[$categoryKey]=[];
foreach($store['items'] as $treeId=>$treeRecord){
 $treeProduct=$treeRecord['draft']??$treeRecord['live']??[];
 $treeCategory=(string)($treeProduct['category']??'other');
 if(!isset($treeLabels[$treeCategory])){$treeCategory='other';$treeLabels['other']='其他产品';}
 $treeSeries=trim((string)($treeProduct['series']??''));
 $tree[$treeCategory][$treeSeries][$treeId]=$treeRecord;
}
foreach($treeLabels as $treeCategory=>$categoryLabel):
 $treeGroups=$tree[$treeCategory]??[];ksort($treeGroups,SORT_NATURAL);
 $categoryCount=0;$categorySelected=false;
 foreach($treeGroups as $treeRows){$categoryCount+=count($treeRows);if(isset($treeRows[$id]))$categorySelected=true;}
?>
<details class="admin-category" <?=($categorySelected||$id==='')?'open':''?>>
<summary><?=h($categoryLabel)?> <small><?=$categoryCount?></small></summary>
<?php foreach($treeGroups as $treeSeries=>$treeRows):?>
<details class="admin-series" <?=(isset($treeRows[$id])||$id==='')?'open':''?>>
<summary><?=h((string)$treeSeries!==''?(string)$treeSeries:'未分类系列')?> <small><?=count($treeRows)?></small></summary>
<?php foreach($treeRows as $treeId=>$treeRecord):$treeProduct=$treeRecord['draft']??$treeRecord['live']??[];?>
<a class="product-row <?=$id===(string)$treeId?'selected':''?>" <?= $id===(string)$treeId?'aria-current="page"':''?> href="/admin/?products=1&amp;edit=<?=h((string)$treeId)?>">
<b><?=h($treeProduct['model']??'')?:'未填写型号'?></b><span><?=h($treeProduct['name']??'')?:'新产品'?></span><small><?=!empty($treeRecord['live'])?'已上架':'未上架'?></small></a>
<?php endforeach;?></details>
<?php endforeach;?>
<?php if(!$categoryCount):?><p class="tree-empty">暂无产品</p><?php endif;?>
</details>
<?php endforeach;?>
</aside>
<div>
<?php if($p):?>
<form method="post" class="duplicate-product" onsubmit="return productConfirm('复制新增产品')"><?php productHidden($id);?><button class="button outline" name="action" value="duplicate">复制新增产品</button><p>复制最近保存的资料及图片，生成未上架的新草稿。请先保存当前修改。</p></form>
<form id="product-editor" method="post" enctype="multipart/form-data"><?php productHidden($id);?><input type="hidden" name="action" value="save">
<fieldset><legend>基本信息</legend><p class="muted">上架必填：产品名称、型号、主图。其余栏目按实际资料填写。</p><label for="category">产品分类</label><select id="category" name="category"><?php foreach(productCategories() as $key=>$label):?><option value="<?=$key?>" <?=$p['category']===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select>
<?php foreach(productFields() as $key=>$label):if($key==='specs')continue;?><label for="<?=h($key)?>"><?=h($label)?></label>
<?php if(in_array($key,['features','description','specs','service'])):?><textarea id="<?=$key?>" name="<?=$key?>" rows="<?=$key==='specs'?7:4?>" maxlength="6000" <?=$key==='specs'?'placeholder="产品型号 | CXW-300-805&#10;请按实际产品资料继续填写参数"':''?>><?=h($p[$key])?></textarea>
<?php else:?><input id="<?=$key?>" name="<?=$key?>" type="<?=in_array($key,['purchase','manual','video'])?'url':'text'?>" value="<?=h($p[$key])?>" maxlength="1000"><?php endif;endforeach;?></fieldset>
<fieldset><legend>产品图片</legend><p class="muted">支持 JPG、PNG、WebP，每张不超过 5 MB，一次提交不超过 30 MB。未选择新图片时保留原图。本次所有图片最多16张，可分批保存后继续添加。</p><div class="image-fields">
<?php foreach(productImages() as $key=>$label):if($key==='size')continue;if(in_array($key,['gallery1','gallery2','detail1','detail2'],true))continue;?><div><label for="<?=$key?>"><?=h($label)?></label><?php if($p[$key]):?><img class="admin-thumb" src="<?=h($p[$key])?>" alt="<?=h($label)?>"><label class="checkbox"><input type="checkbox" name="remove_<?=$key?>">移除此图片</label><?php endif;?><input type="file" id="<?=$key?>" name="<?=$key?>" accept="image/jpeg,image/png,image/webp"></div><?php endforeach;?></div></fieldset>
<?php renderGalleryEditor($p);renderDetailEditor($p);?>
<div class="save-bar"><button class="button">保存草稿</button><a class="button outline" href="/admin/?products=1&amp;preview=<?=h($id)?>" target="_blank" rel="noopener">预览已保存草稿 ↗</a></div></form>
<div class="publish-controls"><h2>发布管理</h2><p>发布的是最近保存的草稿。下架会隐藏官网产品，但保留后台资料。</p><div class="actions">
<?php foreach(['publish'=>'发布上架','unpublish'=>'下架产品','restore'=>'恢复上一版'] as $action=>$label):?><form method="post" onsubmit="return productConfirm('<?=$label?>')"><?php productHidden($id);?><button class="button <?=$action==='publish'?'':'outline'?>" name="action" value="<?=$action?>"><?=$label?></button></form><?php endforeach;?>
</div><form method="post" style="margin-top:24px" onsubmit="return confirm('确定删除这个产品？已上架产品将立即从官网移除，当前未保存的修改也会丢失。')"><?php productHidden($id);?><button class="button outline" name="action" value="delete">删除产品</button><p class="muted">已上架、未上架产品均可删除。删除后将从后台列表和官网移除。</p></form></div><script>
let productDirty=false;const editor=document.getElementById('product-editor');editor.addEventListener('input',()=>productDirty=true);editor.addEventListener('change',()=>productDirty=true);editor.addEventListener('submit',e=>{let total=0;let fileCount=0;for(const input of editor.querySelectorAll('input[type=file]'))fileCount+=input.files.length;if(fileCount>16){alert('本次所有图片最多16张，请分批保存。');e.preventDefault();return}if(document.getElementById('detail_uploads').files.length>12){alert('每批详情图最多12张，请分批保存。');e.preventDefault();return}if(document.getElementById('gallery_uploads').files.length>12){alert('每批最多12张，请分批保存后继续添加。');e.preventDefault();return}for(const f of editor.querySelectorAll('input[type=file]')){for(const file of f.files){if(file.size>5*1024*1024){alert('每张图片不能超过 5 MB。');e.preventDefault();return}total+=file.size}}if(total>30*1024*1024){alert('一次上传图片总量不能超过 30 MB。');e.preventDefault();return}productDirty=false});function productConfirm(label){if(productDirty){alert('请先保存草稿。');return false}return confirm('确认'+label+'？')}window.addEventListener('beforeunload',e=>{if(productDirty){e.preventDefault();e.returnValue=''}});
</script>
<?php else:?><section class="empty-state"><h2>为每款产品建立独立页面</h2><p>点击“添加产品”，填写型号与介绍，上传产品图片。保存后可预览产品详情、技术参数和售后服务。</p></section><?php endif;?></div></div></main><?php productFooter();?>
