<?php
require __DIR__.'/../src/service/inventory.php';
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);p2Schema($d);$_SESSION=['user'=>'admin'];
function checkNumber($v){if(!$v)throw new RuntimeException('numbering assertion failed');}
$r=invNumberRule($d,'CG');
$a=['type'=>'CG','version'=>0,'prefix'=>'PO','date_format'=>'Ymd','separator'=>'_','digits'=>4,'start'=>7];
invSaveNumberRule($d,$a);checkNumber(invDocumentNumber($d,'CG','2026-11-12')==='PO_20261112_0007');
try{invSaveNumberRule($d,$a);throw new RuntimeException('stale accepted');}catch(InvalidArgumentException $e){}
try{invSaveNumberRule($d,array_merge($a,['type'=>'XS']));throw new RuntimeException('sale changed');}catch(InvalidArgumentException $e){}
checkNumber(invDocumentNumber($d,'XS','2026-11-12')==='XS-261112-001');
$request=['type'=>'DB','request_key'=>str_repeat('a',24),'date'=>'2026-11-12'];
$first=invReserveNumber($d,$request)['number'];checkNumber($first===invReserveNumber($d,$request)['number']);
checkNumber(invReservedDocument($d,$request+['reserved_number'=>$first],'DB','2026-11-12')===$first);
try{invReservedDocument($d,$request+['reserved_number'=>'BAD'],'DB','2026-11-12');throw new RuntimeException('forged accepted');}catch(InvalidArgumentException $e){}
try{invReservedDocument($d,$request+['reserved_number'=>$first],'DB','2026-11-13');throw new RuntimeException('wrong date accepted');}catch(InvalidArgumentException $e){}
invSaveNumberRule($d,['type'=>'DB','version'=>0,'prefix'=>'MOVE','date_format'=>'none','separator'=>'','digits'=>4,'start'=>1]);
checkNumber(invReserveNumber($d,$request)['number']===$first);
checkNumber(invReserveNumber($d,array_merge($request,['request_key'=>str_repeat('b',24)]))['number']==='MOVE0001');
checkNumber(invDocumentNumber($d,'DB','2026-11-13')==='MOVE0002');
// Resetting a rule must not reuse numbers already issued before the edit.
invSaveNumberRule($d,['type'=>'DB','version'=>1,'prefix'=>'DB','date_format'=>'ymd','separator'=>'-','digits'=>3,'start'=>1]);
checkNumber(invDocumentNumber($d,'DB','2026-11-12')==='DB-261112-002');
$_SESSION['user']='other';try{invSaveNumberRule($d,array_merge($a,['version'=>1]));throw new RuntimeException('permission bypass');}catch(InvalidArgumentException $e){}
try{invReservedDocument($d,$request+['reserved_number'=>$first],'DB','2026-11-12');throw new RuntimeException('owner bypass');}catch(InvalidArgumentException $e){}
$_SESSION['user']='admin';$product=['type'=>'product','request_key'=>str_repeat('p',24),'date'=>'2026-11-12'];checkNumber(invReserveNumber($d,$product)['number']==='P00001');checkNumber(invReserveNumber($d,$product)['number']==='P00001');
echo "PASS editable rules, protected sales, reservations, idempotence, ownership, collision and date guards\n";

$r=invCodeRule($d);invSaveCodeRule($d,['version'=>$r['version'],'prefix'=>'P','digits'=>5,'next'=>1]);checkNumber(invNextProductCode($d)==='P00001');
echo "PASS product preview does not consume a code\n";

iq($d,"INSERT INTO inv_meta(k,v) VALUES(?,?)",["document_sequence:RK:2026-11-12","12"]);checkNumber(invDocumentNumber($d,"RK","2026-11-12")==="RK-261112-013");
echo "PASS legacy counters retained\n";

checkNumber(invReservedDocument($d,$request+["reserved_number"=>$first,"reserved_date"=>"2026-11-12"],"DB")===$first);
echo "PASS reserved date retained across timezone or midnight boundary\n";

$before=invCodeRule($d);$count=$d->query('SELECT COUNT(*) FROM inv_meta')->fetchColumn();
for($i=0;$i<5;$i++)checkNumber(invReserveNumber($d,['type'=>'product','request_key'=>str_repeat((string)$i,24)])['number']==='P00002');
checkNumber(invCodeRule($d)===$before);checkNumber($d->query('SELECT COUNT(*) FROM inv_meta')->fetchColumn()===$count);
echo "PASS repeated canceled previews leave sequence and metadata unchanged\n";

// A manually lowered next value wins over higher existing codes and canceled legacy previews.
$d->exec("INSERT INTO inv_products VALUES('HIGH','P00999','示例','','','','台',0,NULL,NULL,1,'',1)");
$r=invCodeRule($d);invSaveCodeRule($d,['version'=>$r['version'],'prefix'=>'P','digits'=>5,'next'=>1]);
checkNumber(invNextProductCode($d,true)==='P00001');
checkNumber(invNextProductCode($d,true)==='P00001');
$d->exec("INSERT INTO inv_products VALUES('LOW','P00001','示例','','','','台',0,NULL,NULL,1,'',1)");
checkNumber(invNextProductCode($d,true)==='P00002');
echo "PASS configured next wins over maximum, skips occupied codes, ignores canceled legacy preview\n";

// Sales previews may race; only saving consumes a number, inside the caller transaction.
$req=['type'=>'XS','request_key'=>str_repeat('s',24),'date'=>'2026-11-13'];
$before=$d->query('SELECT k,v FROM inv_meta ORDER BY k')->fetchAll();
for($i=0;$i<5;$i++)checkNumber(invReserveNumber($d,array_merge($req,['request_key'=>str_repeat((string)$i,24)]))['number']==='XS-261113-001');
checkNumber($d->query('SELECT k,v FROM inv_meta ORDER BY k')->fetchAll()===$before);
$save=$req+['reserved_number'=>'XS-261113-001'];
checkNumber(invReservedDocument($d,$save,'XS','2026-11-13')==='XS-261113-001');
checkNumber(invReservedDocument($d,$save,'XS','2026-11-13')==='XS-261113-002');
$d->beginTransaction();invReservedDocument($d,$save,'XS','2026-11-13');$d->rollBack();
checkNumber(invReserveNumber($d,$req)['number']==='XS-261113-003');
checkNumber(invReserveNumber($d,array_merge($req,['date'=>'2026-11-14']))['number']==='XS-261114-001');
echo "PASS canceled sales leave metadata unchanged, simultaneous previews allocate distinct save numbers, rollback and date reset\n";
