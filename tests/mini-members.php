<?php
require __DIR__.'/../src/service/mini.php';
function check($ok){if(!$ok)throw new RuntimeException('check failed');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
miniSchema($db);
$db->exec("INSERT INTO mini_users VALUES('WXOLD','app','old','2026-01-01'),('WXNEW','app','new','2026-02-01')");
$db->exec("INSERT INTO mini_sessions VALUES('existing-token','WXOLD',4102444800)");
miniSchema($db);
check($db->query("SELECT user_id FROM mini_sessions WHERE token_hash='existing-token'")->fetchColumn()==='WXOLD');
check(miniPublicUser($db,'WXOLD')['memberNo']==='DE8000001');
check(miniPublicUser($db,'WXNEW')['memberNo']==='DE8000002');
miniSchema($db);
check(miniPublicUser($db,'WXOLD')['memberNo']==='DE8000001');
$db->exec("INSERT INTO mini_users VALUES('WXTHIRD','app','third','2026-03-01')");
miniSchema($db);
check(miniPublicUser($db,'WXTHIRD')['memberNo']==='DE8000003');
check(miniPublicUser($db,'WXOLD')['id']==='WXOLD');
$q=$db->prepare('SELECT user_id FROM mini_members WHERE sequence=?');$q->execute([8000002-8000000]);check($q->fetchColumn()==='WXNEW');
echo "PASS: member allocation, backfill, stability, lookup and internal ID preservation\n";
