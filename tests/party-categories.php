<?php
require __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);$_SESSION=['user'=>'admin'];
function pcCheck($ok){if(!$ok)throw new RuntimeException('party category assertion');}
function pcReject($f){try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('invalid category accepted');}
$a=invSavePartyCategory($d,['name'=>'示例品牌']);$b=invSavePartyCategory($d,['name'=>'零售客户','parent_id'=>$a['id']]);
$p=invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'合成客户','category_id'=>$b['id']]);
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_links WHERE party_id=?',[$p['id']])->fetchColumn()===$b['id']);
pcReject(fn()=>invSavePartyCategory($d,['name'=>'零售客户','parent_id'=>$a['id']]));
pcReject(fn()=>invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'示例','parent_id'=>$b['id']]));
invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'示例改名']);
pcReject(fn()=>invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'过时编辑']));
invMaster($d,['type'=>'parties','id'=>$p['id'],'version'=>1,'kind'=>'customer','name'=>'合成客户改名']);
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_links WHERE party_id=?',[$p['id']])->fetchColumn()===$b['id']);
pcReject(fn()=>invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'无效','category_id'=>'MISSING']));
pcCheck(!iq($d,"SELECT 1 FROM inv_parties WHERE name='无效'")->fetchColumn());
invMaster($d,['type'=>'parties','id'=>$p['id'],'version'=>2,'kind'=>'customer','name'=>'合成客户改名','category_id'=>'']);
pcCheck(!iq($d,'SELECT 1 FROM inv_party_category_links WHERE party_id=?',[$p['id']])->fetchColumn());
$parent=$b['id'];for($i=3;$i<=8;$i++)$parent=invSavePartyCategory($d,['name'=>'第'.$i.'级','parent_id'=>$parent])['id'];
pcReject(fn()=>invSavePartyCategory($d,['name'=>'第9级','parent_id'=>$parent]));
$d->exec('DROP TABLE inv_party_category_links; DROP TABLE inv_party_categories');invCategorySchema($d);invCategorySchema($d);
pcCheck(iq($d,'SELECT name FROM inv_parties WHERE id=?',[$p['id']])->fetchColumn()==='合成客户改名');
echo "PASS nested categories, duplicate/cycle/depth/stale guards, stable assignment, legacy save and additive upgrade\n";
