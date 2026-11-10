<?php
function homeCarouselGroups(): array {return ['hero'=>'首页场景大图','hood'=>'油烟机卡片','hob'=>'燃气灶卡片','heater'=>'热水器卡片'];}
function homeCarouselStore(): array {
 $fallback=readData('published',defaults());$groups=[];
 foreach(homeCarouselGroups() as $key=>$label)$groups[$key]=empty($fallback[$key.'_image'])?[]:[$fallback[$key.'_image']];
 $initial=['interval'=>5,'groups'=>$groups];
 return readData('home-carousel',['revision'=>0,'draft'=>$initial,'live'=>$initial,'previous'=>null]);
}
function homeCarouselEnhance(string $html,array $values): string {
 $store=homeCarouselStore();$config=!empty($GLOBALS['homeCarouselPreview'])?$store['draft']:$store['live'];
 $json=json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
 $html=str_replace('</head>','<style>'.file_get_contents(APP.'/home-carousel.css').'</style></head>',$html);
 return str_replace('</body>','<script type="application/json" id="home-carousel-config">'.$json.'</script><script>'.file_get_contents(APP.'/home-carousel.js').'</script></body>',$html);
}
