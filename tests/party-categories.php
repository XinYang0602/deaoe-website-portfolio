<?php
require __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);$_SESSION=['user'=>'admin'];
function pcCheck($ok){if(!$ok)throw new RuntimeException('party category assertion');}
function pcReject($f){try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('invalid category accepted');}
$a=invSavePartyCategory($d,['name'=>'示例品牌']);$b=invSavePartyCategory($d,['name'=>'零售客户','parent_id'=>$a['id']]);
$p=invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'合成客户','category_id'=>$b['id']]);
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_assignments WHERE party_id=?',[$p['id']])->fetchColumn()===$b['id']);
pcReject(fn()=>invSavePartyCategory($d,['name'=>'零售客户','parent_id'=>$a['id']]));
pcReject(fn()=>invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'示例','parent_id'=>$b['id']]));
invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'示例改名']);
pcReject(fn()=>invSavePartyCategory($d,['id'=>$a['id'],'version'=>1,'name'=>'过时编辑']));
invMaster($d,['type'=>'parties','id'=>$p['id'],'version'=>1,'kind'=>'customer','name'=>'合成客户改名']);
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_assignments WHERE party_id=?',[$p['id']])->fetchColumn()===$b['id']);
pcReject(fn()=>invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'无效','category_id'=>'MISSING']));
pcCheck(!iq($d,"SELECT 1 FROM inv_parties WHERE name='无效'")->fetchColumn());
invMaster($d,['type'=>'parties','id'=>$p['id'],'version'=>2,'kind'=>'customer','name'=>'合成客户改名','category_id'=>'']);
pcCheck(!iq($d,'SELECT 1 FROM inv_party_category_assignments WHERE party_id=?',[$p['id']])->fetchColumn());
$parent=$b['id'];for($i=3;$i<=8;$i++)$parent=invSavePartyCategory($d,['name'=>'第'.$i.'级','parent_id'=>$parent])['id'];
pcReject(fn()=>invSavePartyCategory($d,['name'=>'第9级','parent_id'=>$parent]));
$d->exec('DROP TABLE inv_party_category_assignments; DROP TABLE inv_party_category_tree');invCategorySchema($d);invCategorySchema($d);
pcCheck(iq($d,'SELECT name FROM inv_parties WHERE id=?',[$p['id']])->fetchColumn()==='合成客户改名');
echo "PASS nested categories, duplicate/cycle/depth/stale guards, stable assignment, legacy save and additive upgrade\n";

$supplier=invSavePartyCategory($d,['name'=>'示例改名','kind'=>'supplier']);
pcReject(fn()=>invSavePartyCategory($d,['name'=>'跨类型','kind'=>'customer','parent_id'=>$supplier['id']]));
pcReject(fn()=>invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'跨类型客户','category_id'=>$supplier['id']]));
// Simulate a pre-upgrade shared category assigned to both accounting identities.
$d->exec("CREATE TABLE inv_party_categories(id TEXT PRIMARY KEY,name TEXT,parent_id TEXT,version INTEGER);CREATE TABLE inv_party_category_links(party_id TEXT PRIMARY KEY,category_id TEXT);
INSERT INTO inv_party_categories VALUES('old-root','旧分类','',1),('old-leaf','旧子类','old-root',1);
DELETE FROM inv_meta WHERE k='party_category_scopes';");
$sp=invMaster($d,['type'=>'parties','kind'=>'supplier','name'=>'合成供应商']);
iq($d,'INSERT INTO inv_party_category_links VALUES(?,?)',[$p['id'],'old-leaf']);iq($d,'INSERT INTO inv_party_category_links VALUES(?,?)',[$sp['id'],'old-leaf']);
invCategorySchema($d);invCategorySchema($d);
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_assignments WHERE party_id=?',[$p['id']])->fetchColumn()==='old-leaf');
pcCheck(iq($d,'SELECT category_id FROM inv_party_category_assignments WHERE party_id=?',[$sp['id']])->fetchColumn()==='SUP-old-leaf');
pcCheck(iq($d,"SELECT parent_id FROM inv_party_category_tree WHERE id='SUP-old-leaf'")->fetchColumn()==='SUP-old-root');
$sr=invSavePartyCategory($d,['name'=>'同名','kind'=>'supplier']);$cr=invSavePartyCategory($d,['name'=>'同名','kind'=>'customer']);pcCheck($sr['id']!==$cr['id']);
echo "PASS scope isolation, same-name roots, shared legacy category migration and idempotence\n";
$free=invMaster($d,['type'=>'parties','name'=>'可删除合成客户','kind'=>'customer']);
pcReject(fn()=>invDeleteParty($d,['id'=>$free['id'],'version'=>0]));
invDeleteParty($d,['id'=>$free['id'],'version'=>1]);pcCheck(!iq($d,'SELECT 1 FROM inv_parties WHERE id=?',[$free['id']])->fetchColumn());
$d->exec('CREATE TABLE test_business_ref(party_id TEXT)');iq($d,'INSERT INTO test_business_ref VALUES(?)',[$p['id']]);
pcReject(fn()=>invDeleteParty($d,['id'=>$p['id'],'version'=>3]));pcCheck(iq($d,'SELECT 1 FROM inv_parties WHERE id=?',[$p['id']])->fetchColumn());
echo "PASS delete, stale guard and business reference protection\n";
