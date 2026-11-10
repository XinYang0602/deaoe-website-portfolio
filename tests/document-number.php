<?php
require (getenv('DEAOE_TEST_INVENTORY') ?: __DIR__.'/../src/service/inventory.php');
function must($ok){if(!$ok)throw new RuntimeException('document number assertion failed');}
if(($argv[1]??'')==='worker'){
 $d=new PDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('PRAGMA busy_timeout=10000');
 for($i=0;$i<20;$i++)echo invDocumentNumber($d,'XS','2026-11-09')."\n";
 exit;
}
$d=new PDO('sqlite::memory:');invSchema($d);
must(invDocumentNumber($d,'XS','2026-11-09')==='XS-261109-001');
must(invDocumentNumber($d,'XS','2026-11-09')==='XS-261109-002');
must(invDocumentNumber($d,'CG','2026-11-09')==='CG-261109-001');
must(invDocumentNumber($d,'XS','2026-11-10')==='XS-261110-001');
$d->beginTransaction();must(invDocumentNumber($d,'DB','2026-11-09')==='DB-261109-001');$d->rollBack();
must(invDocumentNumber($d,'DB','2026-11-09')==='DB-261109-001');
iq($d,'UPDATE inv_meta SET v=? WHERE k=?',['999','document_sequence:XS:2026-11-09']);must(invDocumentNumber($d,'XS','2026-11-09')==='XS-261109-1000');
$path=tempnam(sys_get_temp_dir(),'number-test-');
try{
 $db=new PDO('sqlite:'.$path);invSchema($db);$db=null;$workers=[];
 for($i=0;$i<4;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'worker',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
 $ids=[];foreach($workers as [$proc,$pipes]){$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);must(proc_close($proc)===0);must($err==='');$ids=array_merge($ids,array_filter(explode("\n",$output)));}
 must(count($ids)===80&&count(array_unique($ids))===80);sort($ids);must($ids[0]==='XS-261109-001'&&$ids[79]==='XS-261109-080');
}finally{unlink($path);}
echo "PASS numbering: date/type sequences, rollback, over 999, concurrent uniqueness\n";
