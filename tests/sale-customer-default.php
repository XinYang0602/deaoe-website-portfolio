<?php
require (getenv('DEAOE_TEST_INVENTORY') ?: __DIR__.'/../src/service/inventory.php');
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);p2Schema($d);
function verify($ok){if(!$ok)throw new RuntimeException('assertion failed');}
$d->exec("INSERT INTO inv_warehouses VALUES('W','销售仓',1,1),('OFF','停用仓',0,1)");
verify(invDefaultWarehouse($d)==='');
invSetDefaultWarehouse($d,['warehouse_id'=>'W']);verify(invDefaultWarehouse($d)==='W');
foreach(['OFF','LEGACY','MISSING'] as $id){try{invSetDefaultWarehouse($d,['warehouse_id'=>$id]);throw new RuntimeException('unexpected success');}catch(InvalidArgumentException $e){}}
verify(invDefaultWarehouse($d)==='W');
$d->exec("UPDATE inv_warehouses SET active=0 WHERE id='W'");verify(invDefaultWarehouse($d)==='');
$c=invMaster($d,['type'=>'parties','kind'=>'customer','name'=>'测试客户','contact'=>'测试联系人','phone'=>'000','address'=>'测试地址','active'=>true]);
verify(iq($d,'SELECT kind FROM inv_parties WHERE id=?',[$c['id']])->fetchColumn()==='customer');
echo "PASS: default persistence, invalid/disabled warehouse rejection, customer creation\n";
