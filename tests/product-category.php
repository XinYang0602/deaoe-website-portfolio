<?php
require_once __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);invSchema($d);
$d->exec('DROP TABLE inv_product_categories');invCategorySchema($d);invCategorySchema($d);
$d->exec("INSERT INTO inv_products VALUES('P','P','示例产品','整机','示例品牌','','台',1,NULL,NULL,1,'',1)");
function verify($v){if(!$v)throw new RuntimeException('Category assertion failed');}
$a=['type'=>'products','id'=>'P','version'=>1,'name'=>'示例产品','code'=>'P','unit'=>'台','sn_enabled'=>true,'category_path'=>' 厨电 >> 热水器 / 示例品牌 '];
invMaster($d,$a);
verify(iq($d,'SELECT path FROM inv_product_categories')->fetchColumn()==='厨电 / 热水器 / 示例品牌');
verify(iq($d,'SELECT category FROM inv_products')->fetchColumn()==='整机');
try{invMaster($d,$a);throw new RuntimeException('Stale update accepted');}catch(InvalidArgumentException $e){}
foreach(['a//b','a / ','a/b/c/d/e/f/g/h/i',str_repeat('字',61)] as $bad){try{invCategoryPath($bad);throw new RuntimeException('Bad path accepted');}catch(InvalidArgumentException $e){}}
$a['version']=2;unset($a['category_path']);invMaster($d,$a);
verify(iq($d,'SELECT path FROM inv_product_categories')->fetchColumn()==='厨电 / 热水器 / 示例品牌');
$a['version']=3;$a['category_path']='';invMaster($d,$a);verify(iq($d,'SELECT path FROM inv_product_categories')->fetchColumn()==='');
$d->exec("DELETE FROM inv_products WHERE id='P'");verify((int)$d->query('SELECT COUNT(*) FROM inv_product_categories')->fetchColumn()===0);
echo "PASS category normalization, validation, stale writes, legacy defaults, optional field and cascade\n";
