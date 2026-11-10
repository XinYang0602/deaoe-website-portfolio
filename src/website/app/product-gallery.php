<?php
function renderProductGallery(array $p): void {
 $images=productGalleryImages($p);if(!empty($p['main']))array_unshift($images,$p['main']);
 echo '<div class="gallery product-carousel" aria-label="产品图片">';
 if(!$images)echo '<div class="image-empty">产品主图待上传</div>';
 else{
  echo '<div class="carousel-stage" tabindex="0" aria-label="使用左右方向键切换图片"><div class="carousel-track">';
  foreach($images as $i=>$src)echo '<img class="carousel-slide" draggable="false" src="'.h($src).'" alt="'.h($p['name']).' 展示图 '.($i+1).'">';
  echo '</div>';
  if(count($images)>1)echo '<button type="button" class="carousel-prev" aria-label="上一张">&#10094;</button><button type="button" class="carousel-next" aria-label="下一张">&#10095;</button>';
  echo '</div>';
  if(count($images)>1){echo '<div class="carousel-thumbs" aria-label="选择图片">';foreach($images as $i=>$src)echo '<button type="button" aria-label="查看第 '.($i+1).' 张图片" aria-pressed="'.($i?'false':'true').'"><img src="'.h($src).'" alt=""></button>';echo '</div><p class="carousel-count" aria-live="polite">1 / '.count($images).'</p>';}
 }
 if(!empty($p['video']) && productURL($p['video']))echo '<video controls preload="none" src="'.h($p['video']).'">您的浏览器不支持视频播放。</video>';
 echo '</div>';
 ?>
 <script>
 document.querySelectorAll('.product-carousel').forEach(root=>{
 if(root.dataset.ready)return;root.dataset.ready='1';
 const slides=[...root.querySelectorAll('.carousel-slide')],thumbs=[...root.querySelectorAll('.carousel-thumbs button')],stage=root.querySelector('.carousel-stage'),track=root.querySelector('.carousel-track');
 if(!slides.length)return;
 // Keep the first image's proportions throughout a swipe, without cropping other images.
 const size=()=>{if(slides[0].naturalWidth&&slides[0].naturalHeight)track.style.aspectRatio=slides[0].naturalWidth+'/'+slides[0].naturalHeight;};
 slides[0].addEventListener('load',size);size();
 if(slides.length<2)return;
 let active=0,target=0,frame=0,settle=0;
 const reduced=window.matchMedia('(prefers-reduced-motion: reduce)');
 const update=()=>{
  active=Math.max(0,Math.min(slides.length-1,Math.round(track.scrollLeft/track.clientWidth)));
  thumbs.forEach((b,i)=>b.setAttribute('aria-pressed',String(i===active)));
  root.querySelector('.carousel-count').textContent=`${active+1} / ${slides.length}`;
 };
 const show=n=>{target=(n+slides.length)%slides.length;track.scrollTo({left:target*track.clientWidth,behavior:reduced.matches?'instant':'smooth'});};
 root.querySelector('.carousel-prev').onclick=()=>show(target-1);
 root.querySelector('.carousel-next').onclick=()=>show(target+1);
 thumbs.forEach((b,i)=>b.onclick=()=>show(i));
 stage.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();show(target+(e.key==='ArrowLeft'?-1:1));}});
 track.addEventListener('scroll',()=>{
  cancelAnimationFrame(frame);frame=requestAnimationFrame(update);
  clearTimeout(settle);settle=setTimeout(()=>{update();target=active;},160);
 },{passive:true});
 track.addEventListener('pointerdown',()=>{target=active;},{passive:true});
 // Re-align after rotation/resizing rather than stopping between images.
 new ResizeObserver(()=>{track.scrollTo({left:active*track.clientWidth,behavior:'instant'});target=active;}).observe(track);

 });
 </script>
 <?php
}
