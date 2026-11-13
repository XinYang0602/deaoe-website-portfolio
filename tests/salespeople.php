<?php
require __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);p2Schema($d);$_SESSION=['user'=>'admin'];
function spCheck($ok){if(!$ok)throw new RuntimeException('salespeople assertion');}
function spReject(callable $f){try{$f();throw new RuntimeException('unexpected accepted input');}catch(InvalidArgumentException $e){}}
function spTx(PDO $d,callable $f){$d->beginTransaction();try{$r=$f();$d->commit();return $r;}catch(Throwable $e){$d->rollBack();throw $e;}}
$a=salespersonSave($d,['name'=>'测试业务员甲','phone'=>'000','active'=>true])['id'];
$b=salespersonSave($d,['name'=>'测试业务员乙','active'=>true])['id'];
foreach(['C1'=>'customer','C2'=>'customer','S1'=>'supplier'] as $id=>$kind)iq($d,'INSERT INTO inv_parties VALUES(?,?,?,?,?,?,1,1)',[$id,$kind,$id,'','','']);
$rows=[['id'=>'C1','version'=>1],['id'=>'C2','version'=>1]];
spTx($d,fn()=>salespersonAssign($d,['salesperson_id'=>$a,'customers'=>$rows]));
spCheck((int)$d->query('SELECT COUNT(*) FROM inv_customer_salespeople')->fetchColumn()===2);
spCheck((int)iq($d,'SELECT version FROM inv_parties WHERE id=?',['C1'])->fetchColumn()===2);
spReject(fn()=>salespersonAssign($d,['salesperson_id'=>$b,'customers'=>[['id'=>'C1','version'=>2],['id'=>'C2','version'=>1]]]));
spCheck(iq($d,'SELECT salesperson_id FROM inv_customer_salespeople WHERE party_id=?',['C1'])->fetchColumn()===$a);
spReject(fn()=>salespersonAssign($d,['salesperson_id'=>$a,'customers'=>[['id'=>'S1','version'=>1]]]));
spReject(fn()=>salespersonAssign($d,['salesperson_id'=>$a,'customers'=>[['id'=>'C1','version'=>2],['id'=>'C1','version'=>2]]]));
salespersonSave($d,['id'=>$a,'version'=>1,'name'=>'改名业务员甲','active'=>false]);
spCheck(iq($d,'SELECT salesperson_id FROM inv_customer_salespeople WHERE party_id=?',['C1'])->fetchColumn()===$a);
spReject(fn()=>salespersonAssign($d,['salesperson_id'=>$a,'customers'=>[['id'=>'C1','version'=>2]]]));
spReject(fn()=>salespersonSave($d,['id'=>$a,'version'=>1,'name'=>'过期编辑','active'=>true]));
spTx($d,fn()=>salespersonAssign($d,['salesperson_id'=>$b,'customers'=>[['id'=>'C1','version'=>2],['id'=>'C2','version'=>2]]]));
spTx($d,fn()=>salespersonAssign($d,['salesperson_id'=>'','customers'=>[['id'=>'C1','version'=>3]]]));
spCheck(!iq($d,'SELECT salesperson_id FROM inv_customer_salespeople WHERE party_id=?',['C1'])->fetchColumn());
spCheck((int)iq($d,'SELECT version FROM inv_parties WHERE id=?',['C1'])->fetchColumn()===4);
// Existing customer edits are stale after reassignment; deletion clears only the relationship.
spReject(fn()=>invMaster($d,['type'=>'parties','id'=>'C2','version'=>2,'kind'=>'customer','name'=>'C2','active'=>true]));
invDeleteParty($d,['id'=>'C2','version'=>3]);spCheck((int)$d->query('SELECT COUNT(*) FROM inv_customer_salespeople')->fetchColumn()===0);
$_SESSION['user']='other';spReject(fn()=>salespersonSave($d,['name'=>'不允许','active'=>true]));spReject(fn()=>salespersonAssign($d,['salesperson_id'=>$b,'customers'=>[['id'=>'C1','version'=>4]]]));
// Idempotent schema upgrade preserves staff and all legacy customer columns.
salespersonSchema($d);spCheck((int)$d->query('SELECT COUNT(*) FROM inv_salespeople')->fetchColumn()===2);
echo "PASS salespeople CRUD, disable retention, batch assignment/reassignment/removal, supplier guard, duplicate/stale batch rejection, permissions, customer version conflicts and deletion\n";
