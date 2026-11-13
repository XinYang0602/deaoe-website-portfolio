<?php
require __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);$_SESSION=['user'=>'admin'];
$r=invBrands($d);if($r['entries'][1]!==['name'=>'狄澳','english'=>'DIAOR'])throw new RuntimeException('alias');
$a=['version'=>0,'entries'=>array_merge($r['entries'],[['name'=>'测试品牌','english'=>'TEST']])];
invSaveBrands($d,$a);if(count(invBrands($d)['entries'])!==3)throw new RuntimeException('save');
try{invSaveBrands($d,$a);throw new RuntimeException('stale accepted');}catch(InvalidArgumentException $e){}
try{invSaveBrands($d,['version'=>1,'entries'=>[['name'=>'A','english'=>'TEST'],['name'=>'B','english'=>'TEST']]]);throw new RuntimeException('duplicate accepted');}catch(InvalidArgumentException $e){}
$_SESSION['user']='other';try{invSaveBrands($d,['version'=>1,'entries'=>$r['entries']]);throw new RuntimeException('permission');}catch(InvalidArgumentException $e){}
echo "PASS brand aliases, persistence, concurrency and permission\n";
$_SESSION['user']='admin';
$r=invSaveBrands($d,['version'=>1,'entries'=>[['name'=>'自定义 English 组合品牌'],['name'=>'English only']]]);
if($r['entries'][0]['name']!=='自定义 English 组合品牌'||$r['entries'][0]['english']!=='')throw new RuntimeException('custom name');
try{invSaveBrands($d,['version'=>2,'entries'=>[['name'=>'Brand'],['name'=>'brand']]]);throw new RuntimeException('duplicate accepted');}catch(InvalidArgumentException $e){}
echo "PASS single custom brand names and case-insensitive uniqueness\n";
