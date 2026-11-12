<?php
$source=file_get_contents(__DIR__.'/../src/service/mini.php');
$start=strpos($source,'function miniLimit(');
$end=strpos($source,'function exchangeWechatCode(', $start);
eval(substr($source,$start,$end-$start));
function jsonReply($status,$body): never { throw new RuntimeException((string)$status); }
function expect($ok) { if (!$ok) throw new RuntimeException('Assertion failed'); }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE mini_rate (key TEXT PRIMARY KEY, since INTEGER NOT NULL, count INTEGER NOT NULL)');
$source=file_get_contents(__DIR__.'/../src/service/mini.php');
expect(str_contains($source,"'unknown'),120,600)"));
expect(str_contains($source,"'phone:'.\$user['id'],60,3600)"));
foreach ([['login:test',120,600,40],['phone:test',60,3600,10]] as [$key,$limit,$period,$old]) {
 $q=$db->prepare('INSERT INTO mini_rate VALUES(?,?,?)');$q->execute([$key,time(),$old]);
 for($i=$old;$i<$limit;$i++) miniLimit($db,$key,$limit,$period);
 try { miniLimit($db,$key,$limit,$period); throw new RuntimeException('No limit'); } catch(RuntimeException $e) { expect($e->getMessage()==='429'); }
 expect(!$db->inTransaction());
 $q=$db->prepare('UPDATE mini_rate SET since=? WHERE key=?');$q->execute([time()-$period,$key]);
 miniLimit($db,$key,$limit,$period);
 $q=$db->prepare('SELECT count FROM mini_rate WHERE key=?');$q->execute([$key]);expect((int)$q->fetchColumn()===1);
}
echo "PASS: existing counters, new boundaries, rejection and expiry reset\n";
