<?php
$dir=sys_get_temp_dir().'/cleanup-check-'.bin2hex(random_bytes(6));mkdir($dir);define('DATA_DIR',$dir);
require __DIR__.'/../src/service/mini.php';require __DIR__.'/../src/service/inventory.php';
$_SESSION=['user'=>'admin'];$d=database();miniSchema($d);invMigrate($d);p2Migrate($d);finSchema($d);
$d->exec("INSERT INTO inv_parties VALUES('C','customer','示例客户','','','',1,1);
INSERT INTO inv_warehouses VALUES('W','示例仓库',1,1);
INSERT INTO inv_products VALUES('P','P','示例产品','整机','','','台',0,NULL,100,1,'',1);
INSERT INTO inv_docs(id,kind,status,date,warehouse_id,party_id,note,created_at) VALUES('S','sale','approved','2026-11-12','W','C','','now');
INSERT INTO inv_lines VALUES('L','S','P',1,100,NULL,'[]');
INSERT INTO inv_fin_sales VALUES('S','confirmed',100,'');
INSERT INTO inv_fin_entries(party_id,source,kind,cents,note,account,created_at) VALUES('C','sale:S','sale',100,'','','now'),('C','payment:K','receipt',-50,'','','now'),('C','offset:O','offset',-50,'','','now'),('C','payment:UNRELATED','receipt',-10,'','','now');
INSERT INTO inv_fin_links VALUES('payment:K','S'),('offset:O','S');
INSERT INTO inv_fin_aux(party_id,bucket,source,kind,cents,reference,note,account,created_at) VALUES('C','advance_receipt','payment:A','advance_receipt',50,'S','','','now'),('C','advance_receipt','payment:O','offset',-50,'S','','','now'),('C','advance_receipt','payment:KEEP','advance_receipt',200,'','','','now');");
$d->exec('BEGIN IMMEDIATE');$p=cleanupPlan($d,['ids'=>['P']]);if(count($p['picked']['inv_fin_entries'])!==3||count($p['picked']['inv_fin_aux'])!==2)throw new Exception('closure');
invTestCleanup($d,['ids'=>['P'],'action'=>'test_cleanup_execute','fingerprint'=>$p['fingerprint'],'confirmation'=>'删除测试数据']);$d->exec('COMMIT');
if($d->query('SELECT count(*) FROM inv_docs')->fetchColumn()!=0||$d->query('SELECT count(*) FROM inv_fin_entries')->fetchColumn()!=1||$d->query('SELECT SUM(cents) FROM inv_fin_aux')->fetchColumn()!=200)throw new Exception('remaining balances');
if($d->query('PRAGMA foreign_key_check')->fetch())throw new Exception('foreign keys');
echo "PASS linked financial cleanup, offsets, unrelated balances and foreign keys\n";
$d=null;foreach(glob($dir.'/test-cleanup-backups/*') as $f)unlink($f);rmdir($dir.'/test-cleanup-backups');foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);
