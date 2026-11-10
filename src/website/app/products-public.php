<?php
require_once APP.'/products-lib.php';
header('Cache-Control: no-cache');
$store=productStore();$items=$store['items'];
if(isset($_GET['product'])){
 $id=is_string($_GET['product'])?$_GET['product']:'';$p=$items[$id]['live']??null;
 if(!$p){http_response_code(404);productHeader('产品暂未上架');echo '<main><h1>产品暂未上架</h1><a href="/?products=1">返回产品列表</a></main>';productFooter();return;}
 renderProduct($p);return;
}
$category=is_string($_GET['category']??null)?$_GET['category']:'';
$series=is_string($_GET['series']??null)?$_GET['series']:'';
$live=array_filter($items,fn($r)=>!empty($r['live']));$seriesOptions=[];
foreach($live as $r)if(($category==='' || $r['live']['category']===$category) && $r['live']['series']!=='')$seriesOptions[$r['live']['series']]=true;
productHeader('全部产品');
echo '<main class="catalog"><p class="eyebrow">DEAOE PRODUCTS</p><h1>探索德澳产品</h1><nav class="categories"><a class="'.($category===''?'active':'').'" href="/?products=1">全部产品</a>';
foreach(productCategories() as $k=>$label)echo '<a class="'.($category===$k?'active':'').'" href="/?products=1&amp;category='.$k.'">'.h($label).'</a>';
echo '</nav><div class="catalog-layout"><aside><h2>筛选产品</h2>';
if(in_array($category,['hood','hob','heater'],true)){
 echo '<p class="catalog-series-title" id="catalog-series-title">产品系列</p><nav class="catalog-series" aria-labelledby="catalog-series-title">';
 $options=array_merge([''],array_map('strval',array_keys($seriesOptions)));
 foreach($options as $s){
  $url='/?'.http_build_query(['products'=>1,'category'=>$category,'series'=>$s],'','&',PHP_QUERY_RFC3986);
  echo '<a href="'.h($url).'"'.($s===$series?' class="active" aria-current="page"':'').'>'.h($s===''?'全部系列':$s).'</a>';
 }
 echo '</nav>';
}else{
 echo '<form method="get"><input type="hidden" name="products" value="1"><input type="hidden" name="category" value="'.h($category).'"><label for="series">产品系列</label><select id="series" name="series"><option value="">全部系列</option>';
 foreach(array_keys($seriesOptions) as $s)echo '<option value="'.h((string)$s).'" '.((string)$s===$series?'selected':'').'>'.h((string)$s).'</option>';
 echo '</select><button class="button">应用筛选</button></form>';
}
echo '<a class="catalog-mobile-reset" href="'.h('/?'.http_build_query(['products'=>1,'category'=>$category])).'">重置</a><a class="text-link" href="/?products=1">清除筛选</a></aside><div class="product-grid">';$count=0;
foreach($live as $id=>$r){$p=$r['live'];if(($category!==''&&$p['category']!==$category)||($series!==''&&$p['series']!==$series))continue;$count++;
 echo '<a class="product-card" href="/?product='.h($id).'"><div class="card-image"><img loading="lazy" src="'.h($p['main']).'" alt="'.h($p['name']).'"></div><p class="eyebrow">'.h($p['series']?:productCategories()[$p['category']]).'</p><h2>'.h($p['model']).'</h2><p>'.h($p['name']).'</p><p class="muted">'.h($p['summary']).'</p><span class="text-link">了解详情 →</span></a>';
}
if(!$count)echo '<div class="empty-state"><h2>暂无符合条件的产品</h2><p>您可以查看其他分类，或联系我们了解产品。</p><a href="/#contact">联系咨询 ↗</a></div>';
echo '</div></div></main>';productFooter();
