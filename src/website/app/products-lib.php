<?php
/* automatic-webp-v1 */
require_once APP.'/webp-helper.php';
/* deaoe-admin-no-specifications-v1 */
/* deaoe-hide-specs-size-v1 */
require_once APP.'/detail-list.php';
require_once APP.'/gallery-list.php';
function productCategories(): array {return ['hood'=>'油烟机','hob'=>'燃气灶','heater'=>'热水器','other'=>'其他产品'];}
function productFields(): array {return ['name'=>'产品名称','model'=>'产品型号','series'=>'产品系列','summary'=>'一句话介绍','features'=>'主要卖点（每行一条）','description'=>'产品介绍','service'=>'售后服务说明','purchase'=>'商城购买链接（选填）','video'=>'视频地址（选填，可直接播放的 HTTPS MP4 链接）','manual'=>'说明书链接（选填）'];}
function productImages(): array {return ['main'=>'产品主图','gallery1'=>'展示图 1','gallery2'=>'展示图 2','detail1'=>'详情长图 1','detail2'=>'详情长图 2','size'=>'安装尺寸图'];}
function emptyProduct(): array {return array_merge(array_fill_keys(array_keys(productFields()),''),array_fill_keys(array_keys(productImages()),''),['category'=>'hood']);}
function productStore(): array {return readData('product-catalog',['revision'=>0,'items'=>[]]);}
function productURL(string $s): bool {return $s==='' || (filter_var($s,FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($s,PHP_URL_SCHEME)??''),['https','http'],true));}
function productUpload(string $key): ?string {
 $f=$_FILES[$key]??null;if(!$f || $f['error']===UPLOAD_ERR_NO_FILE)return null;
 if($f['error']!==UPLOAD_ERR_OK || $f['size']>5*1024*1024)throw new RuntimeException('图片上传失败，每张图片请控制在 5 MB 内。');
 $info=@getimagesize($f['tmp_name']);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
 if(!$info || !$ext || $info[0]>12000 || $info[1]>24000 || $info[0]*$info[1]>60000000)throw new RuntimeException('请上传有效 JPG、PNG 或 WebP，宽高和总像素不要过大。');
 $dir=getenv('DEAOE_UPLOADS')?:'/var/www/deaoe/uploads';$name='product-'.bin2hex(random_bytes(16)).'.webp';
 deaoeWebp($f['tmp_name'],$dir.'/'.$name);chmod($dir.'/'.$name,0644);return '/uploads/'.$name;
}
function productLines(string $text): array {return array_values(array_filter(array_map('trim',preg_split('/\R/u',$text)),fn($s)=>$s!==''));}
function productHeader(string $title): void {header('Content-Type: text/html; charset=utf-8');header('X-Content-Type-Options: nosniff');echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · 德澳 DEAOE</title><style>'.file_get_contents(APP.'/products.css').'</style></head><body><header class="site-header"><a class="brand" href="/" aria-label="德澳首页"><svg class="deaoe-brand-logo" viewBox="365 1223 7952 908" role="img" aria-label="DEAOE 德澳"><image href="/deaoe-brand-logo.png" width="8661" height="3465"/></svg></a><nav><a href="/?products=1">全部产品</a><a href="/#brand">品牌故事</a><a href="/#service">服务支持</a></nav><button type="button" class="catalog-menu-toggle" aria-label="打开菜单" aria-expanded="false" aria-controls="catalog-header-menu"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button><script>(function(){var h=document.currentScript.parentElement,b=h.querySelector(".catalog-menu-toggle"),n=h.querySelector("nav");if(!b||!n)return;n.id="catalog-header-menu";function close(){n.classList.remove("catalog-menu-open");b.setAttribute("aria-expanded","false");b.setAttribute("aria-label","打开菜单");}b.addEventListener("click",function(){var open=n.classList.toggle("catalog-menu-open");b.setAttribute("aria-expanded",String(open));b.setAttribute("aria-label",open?"关闭菜单":"打开菜单");});n.addEventListener("click",function(e){if(e.target.closest("a"))close();});document.addEventListener("click",function(e){if(!h.contains(e.target))close();});document.addEventListener("keydown",function(e){if(e.key==="Escape"){close();b.focus();}});window.addEventListener("resize",function(){if(window.innerWidth>850)close();});})();</script></header>';}
function productFooter(): void {echo '<footer class="site-footer" style="flex-wrap:wrap"><svg class="footer-brand-logo" viewBox="365 1223 7952 908" role="img" aria-label="DEAOE 德澳"><image href="/deaoe-brand-logo.png" width="8661" height="3465"/></svg><span>品质厨房电器</span> <a href="/#contact">联系我们 <svg class="contact-arrow" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 19 19 5M5 5h14v14"/></svg></a><div id="deaoe-icp-filing" style="flex-basis:100%;text-align:center;font-size:12px;color:#999;line-height:1.8"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:none">Demo website — no production registration</a></div></footer><script>'.file_get_contents(APP.'/image-controls.js').'</script><script>'.file_get_contents(APP.'/site-icons.js').'</script></body></html>';}
function renderProduct(array $p,bool $preview=false): void {
 productHeader($p['model']?:$p['name']);
 if($preview)echo '<div class="preview-note">草稿预览 · 尚未发布 <a href="/admin/?products=1">返回产品管理</a></div>';
 /* product-back-button-v1 */
 echo '<div class="product-back-row"><a class="product-back-link" id="product-back-link" href="/?products=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg>返回产品列表</a></div>';
 echo <<<'BACKJS'
<script>
(()=>{
 const link=document.getElementById('product-back-link');
 try{
  const previous=new URL(document.referrer);
  if(previous.origin===location.origin && previous.pathname==='/' && previous.searchParams.has('products') && !previous.searchParams.has('product')){
   link.href=previous.href;
   link.addEventListener('click',event=>{
    if(event.button===0&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey&&history.length>1){event.preventDefault();history.back();}
   });
  }
 }catch(e){}
})();
</script>
BACKJS;
 echo '<div class="product-nav"><div class="crumb"><a href="/?products=1">产品</a> / '.h(productCategories()[$p['category']]??'产品').' / '.h($p['model']).'</div><nav><a href="#overview">产品详情</a><a href="#support">售后服务</a><a class="button small" href="/#contact">联系咨询</a></nav></div>';
 require_once APP.'/product-gallery.php';
 echo '<main class="detail"><section id="overview" class="product-hero">';
 renderProductGallery($p);
 echo '<div class="product-copy"><p class="eyebrow">'.h($p['series']?:productCategories()[$p['category']]).'</p><h1>'.h($p['model']?:$p['name']).'</h1><h2>'.h($p['name']).'</h2><p class="summary">'.h($p['summary']).'</p><ul class="features">';
 foreach(productLines($p['features']) as $line)echo '<li>'.h($line).'</li>';
 echo '</ul><div class="actions">';

 echo '<a class="button" href="/#contact">联系咨询</a>';
 if($p['purchase'] && productURL($p['purchase']))echo '<a class="button outline" href="'.h($p['purchase']).'" target="_blank" rel="noopener noreferrer">前往购买 ↗</a>';
 echo '</div></div></section>';
 if($p['description'] || productDetailImages($p)){echo '<section class="description"><h2>产品介绍</h2><p>'.nl2br(h($p['description'])).'</p>';foreach(productDetailImages($p) as $detailImage)echo '<img loading="lazy" src="'.h($detailImage).'" alt="'.h($p['name']).' 产品详情">';echo '</section>';}
 /* deaoe-no-specifications-v1 */
 if($p['manual'] && productURL($p['manual'])){
 echo '<section class="product-resources">';

 if($p['manual'] && productURL($p['manual']))echo '<a class="text-link" target="_blank" rel="noopener noreferrer" href="'.h($p['manual']).'">查看产品说明书 ↗</a>';
 echo '</section>';
 }
 echo '<section id="support"><p class="eyebrow">SERVICE & SUPPORT</p><h2>售后服务</h2><p>'.nl2br(h($p['service']?:'有关安装与售后服务，请联系我们。')).'</p><a class="button" href="/#contact">联系售后</a></section></main>';productFooter();
}
