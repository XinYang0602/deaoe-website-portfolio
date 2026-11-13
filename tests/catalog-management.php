<?php
require __DIR__.'/product-category.php';
$_SESSION=['user'=>'admin'];
if(invNextProductCode($d)!=='P00001')throw new Exception('default');
$d->exec("INSERT INTO inv_products VALUES('X','P00002','示例','A / B','','','台',0,NULL,NULL,1,'',1)");
if(invNextProductCode($d)!=='P00003')throw new Exception('duplicate');
$r=invCodeRule($d);invSaveCodeRule($d,['version'=>$r['version'],'prefix'=>'CP','digits'=>4,'next'=>10]);if(invNextProductCode($d)!=='CP0010')throw new Exception('rule');
$rows=$d->query("SELECT p.id,p.version,p.category,COALESCE(c.path,'') AS path FROM inv_products p LEFT JOIN inv_product_categories c ON c.product_id=p.id ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
invMoveCategory($d,['from'=>'A','parent'=>'C','fingerprint'=>hash('sha256',json_encode($rows))]);if(iq($d,"SELECT path FROM inv_product_categories WHERE product_id='X'")->fetchColumn()!=='C / A / B')throw new Exception('move');
try{invMoveCategory($d,['from'=>'C','parent'=>'C / A']);throw new Exception('cycle');}catch(InvalidArgumentException $e){}
if(cleanupValues('payment:key','source')!==['key','payment:key'])throw new Exception('payment closure');
if(cleanupValues('customer','party_id')!==[])throw new Exception('party leaked');
echo "PASS numbering, collisions, rule updates, subtree move and cleanup reference scoping\n";
