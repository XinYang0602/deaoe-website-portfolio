<?php
require (getenv('DEAOE_TEST_INVENTORY') ?: __DIR__.'/../src/service/inventory.php');
set_error_handler(function($level,$message){throw new RuntimeException($message);});
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
function tx($f){global $d;$d->beginTransaction();try{$v=$f();$d->commit();return $v;}catch(Throwable $e){$d->rollBack();throw $e;}}
function bad($f){try{tx($f);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected rejection');}
function v($sql,$a=[]){global $d;return iq($d,$sql,$a)->fetchColumn();}
function action($a){global $d;return tx(fn()=>productionAction($d,$a));}
function step($id,$transition,$serials=[]){return action(['operation'=>'transition','id'=>$id,'transition'=>$transition,'version'=>(int)v('SELECT version FROM inv_production WHERE id=?',[$id]),'serials'=>$serials]);}
function createDoc($kind,$qty=1,$key=null){global $bom;return action(['operation'=>'create','kind'=>$kind,'bom_id'=>$bom,'bom_version'=>(int)v('SELECT version FROM inv_boms WHERE id=?',[$bom]),'warehouse_id'=>'W','qty'=>$qty,'date'=>'2026-11-13','request_key'=>$key??bin2hex(random_bytes(12))])['id'];}
function qty($p){return (int)v('SELECT qty FROM inv_balances WHERE product_id=? AND warehouse_id=?',[$p,'W']);}
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);invSchema($d);p2Schema($d);productionSchema($d);productionSchema($d);$_SESSION=['user'=>'admin','csrf'=>'test'];
$d->exec("CREATE TABLE state(id INTEGER PRIMARY KEY,revision INTEGER,body TEXT,updated_at TEXT);CREATE TABLE audit(revision INTEGER,user TEXT,created_at TEXT,body TEXT);
 INSERT INTO state VALUES(1,1,'{\"cards\":[]}', 'now');
 INSERT INTO inv_warehouses VALUES('W','Test warehouse',1,1),('W2','Second warehouse',1,1);
 INSERT INTO inv_products VALUES('F','F','Finished','Test','','','unit',1,NULL,NULL,1,'',1),('C','C','Component','Test','','','unit',0,NULL,NULL,1,'',1),('D','D','Second component','Test','','','unit',0,NULL,NULL,1,'',1);");
foreach(['C'=>20,'D'=>10] as $p=>$n)tx(fn()=>invAdjustStock($d,['product_id'=>$p,'warehouse_id'=>'W','expected_qty'=>0,'qty'=>$n,'note'=>'synthetic','request_key'=>bin2hex(random_bytes(12))]));
$before=qty('C');tx(fn()=>productionLocations($d,'C',[['warehouse_id'=>'W','shelf'=>'A','level'=>'2','version'=>0],['warehouse_id'=>'W2','shelf'=>'B','level'=>'3','version'=>0]]));check(qty('C')===$before,'location is metadata only');
bad(fn()=>productionLocations($d,'C',[['warehouse_id'=>'W','shelf'=>'stale','version'=>0]]));check(v("SELECT shelf FROM inv_locations WHERE product_id='C' AND warehouse_id='W'")==='A','stale note rejected');
// Product fields and warehouse notes share one transaction.
$version=(int)v("SELECT version FROM inv_products WHERE id='C'");
bad(fn()=>invMaster($d,['type'=>'products','id'=>'C','version'=>$version,'name'=>'Changed','free_product_name'=>true,'code'=>'C','unit'=>'unit','sn_enabled'=>false,'locations'=>[['warehouse_id'=>'W','version'=>0,'shelf'=>'stale']]]));
check(v("SELECT name FROM inv_products WHERE id='C'")==='Component','product change rolled back on stale location');
$bom=action(['operation'=>'template','name'=>'Test BOM','product_id'=>'F','parts'=>[['product_id'=>'C','qty'=>2],['product_id'=>'D','qty'=>1]]])['id'];
bad(fn()=>productionAction($d,['operation'=>'template','name'=>'bad','product_id'=>'F','parts'=>[['product_id'=>'F','qty'=>1]]]));
$key=bin2hex(random_bytes(12));$doc=createDoc('assembly',2,$key);check(createDoc('assembly',2,$key)===$doc,'create retry');check(qty('C')===20,'draft leaves stock');
// Template edits must not alter existing documents.
action(['operation'=>'template','id'=>$bom,'version'=>1,'name'=>'Revised BOM','product_id'=>'F','parts'=>[['product_id'=>'C','qty'=>3],['product_id'=>'D','qty'=>1]]]);
step($doc,'submit');step($doc,'approve');check(qty('F')===2&&qty('C')===16&&qty('D')===8,'assembly snapshot stock');
check(step($doc,'approve')['repeated'],'approve retry');check(qty('F')===2,'no duplicate outputs');
$sns=iq($d,"SELECT sn FROM inv_sn WHERE product_id='F' AND status='available'")->fetchAll(PDO::FETCH_COLUMN);check(count($sns)===2,'output SNs');
$dis=createDoc('disassembly');step($dis,'submit');bad(fn()=>productionAction($d,['operation'=>'transition','id'=>$dis,'transition'=>'approve','version'=>2]));check(qty('F')===2,'SN selection required');step($dis,'approve',['F'=>[$sns[0]]]);check(qty('F')===1&&qty('C')===19&&qty('D')===9,'disassembly reverses new BOM');
check(v('SELECT status FROM inv_sn WHERE sn=?',[$sns[0]])==='production_consumed','input SN lineage');
$fail=createDoc('assembly',10);step($fail,'submit');$ledger=(int)v('SELECT COUNT(*) FROM inv_ledger');bad(fn()=>productionAction($d,['operation'=>'transition','id'=>$fail,'transition'=>'approve','version'=>2]));check(qty('C')===19&&qty('D')===9&&(int)v('SELECT COUNT(*) FROM inv_ledger')===$ledger,'shortage atomic rollback');
// Fail on the second input after the first deduction: everything must roll back.
action(['operation'=>'template','id'=>$bom,'version'=>2,'name'=>'Shortage BOM','product_id'=>'F','parts'=>[['product_id'=>'C','qty'=>1],['product_id'=>'D','qty'=>10]]]);
$partial=createDoc('assembly');step($partial,'submit');$ledger=(int)v('SELECT COUNT(*) FROM inv_ledger');
bad(fn()=>productionAction($d,['operation'=>'transition','id'=>$partial,'transition'=>'approve','version'=>2]));
check(qty('C')===19&&qty('D')===9&&(int)v('SELECT COUNT(*) FROM inv_ledger')===$ledger,'partial deduction rolled back');
check(v('SELECT status FROM inv_production WHERE id=?',[$partial])==='submitted','failed approval preserves state');
$frozen=createDoc('assembly');step($frozen,'submit');$d->exec("INSERT INTO inv_freezes VALUES('W','COUNT-TEST')");bad(fn()=>productionAction($d,['operation'=>'transition','id'=>$frozen,'transition'=>'approve','version'=>2]));$d->exec('DELETE FROM inv_freezes');
step($frozen,'reject');step($frozen,'void');bad(fn()=>productionAction($d,['operation'=>'transition','id'=>$frozen,'transition'=>'approve','version'=>4]));
check((int)v("SELECT SUM(qty) FROM inv_lots WHERE product_id='C'")===qty('C'),'lot balance agrees');
// Cleanup graph must include related production records and the whole BOM.
$plan=cleanupPlan($d,['ids'=>['C']]);check(isset($plan['picked']['inv_production'])&&isset($plan['picked']['inv_production_parts'])&&isset($plan['picked']['inv_boms']),'cleanup graph includes production');
$_SESSION['user']='staff';bad(fn()=>productionAction($d,['operation'=>'create']));
check(!$d->query('PRAGMA foreign_key_check')->fetchAll(),'foreign keys');
echo "PASS production: templates, snapshots, assembly/disassembly, SN, lots, locations, retries, rollback, freezes, states, cleanup and permissions\n";
