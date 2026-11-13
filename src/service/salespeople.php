<?php
declare(strict_types=1);
// Customer ownership is separate from login accounts and service dispatch staff.
function salespersonSchema(PDO $d):void {
 $d->exec("CREATE TABLE IF NOT EXISTS inv_salespeople(id TEXT PRIMARY KEY,name TEXT NOT NULL,phone TEXT NOT NULL DEFAULT '',note TEXT NOT NULL DEFAULT '',active INTEGER NOT NULL DEFAULT 1,version INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS inv_customer_salespeople(party_id TEXT PRIMARY KEY REFERENCES inv_parties(id) ON DELETE CASCADE,salesperson_id TEXT NOT NULL REFERENCES inv_salespeople(id));
 CREATE INDEX IF NOT EXISTS inv_customer_salesperson_idx ON inv_customer_salespeople(salesperson_id);");
}
function salespersonSave(PDO $d,array $a):array {
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可以管理业务员');
 $id=it($a['id']??'');$old=$id?iq($d,'SELECT * FROM inv_salespeople WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;
 if($id&&(!$old||(int)$old['version']!==($a['version']??null)))iv('业务员资料已变化，请刷新');
 $name=it($a['name']??'',80);if($name==='')iv('请填写业务员姓名');
 $phone=it($a['phone']??'',80);$note=it($a['note']??'',1000);$active=$a['active']??true;if(!is_bool($active))iv('启用状态无效');
 $id=$id?:iid('SP');
 if($old)iq($d,'UPDATE inv_salespeople SET name=?,phone=?,note=?,active=?,version=version+1 WHERE id=?',[$name,$phone,$note,(int)$active,$id]);
 else iq($d,'INSERT INTO inv_salespeople(id,name,phone,note,active) VALUES(?,?,?,?,?)',[$id,$name,$phone,$note,(int)$active]);
 ilog($d,'salesperson.save',$id,['name'=>$name,'active'=>$active]);return ['id'=>$id];
}
function salespersonAssign(PDO $d,array $a):array {
 if(($_SESSION['user']??'')!=='admin')iv('仅管理员可以分配业务员');
 $staff=it($a['salesperson_id']??'');
 if($staff&&!iq($d,'SELECT 1 FROM inv_salespeople WHERE id=? AND active=1',[$staff])->fetchColumn())iv('请选择启用的业务员');
 $rows=$a['customers']??null;if(!is_array($rows)||!array_is_list($rows)||!count($rows)||count($rows)>200)iv('请选择1至200位客户');
 $seen=[];$before=[];
 foreach($rows as $row){if(!is_array($row))iv('客户格式无效');$id=it($row['id']??'');if(isset($seen[$id]))iv('客户重复');$seen[$id]=true;
  $p=iq($d,'SELECT kind,version FROM inv_parties WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$p||$p['kind']!=='customer')iv('只能给客户分配业务员');
  if((int)$p['version']!==($row['version']??null))iv('客户资料或分配已变化，请刷新后重试');
  $before[$id]=iq($d,'SELECT salesperson_id FROM inv_customer_salespeople WHERE party_id=?',[$id])->fetchColumn()?:'';
 }
 // Validate the entire batch before writing; the API wraps this action in BEGIN IMMEDIATE.
 foreach($before as $id=>$previous){
  if($previous===$staff)continue;
  if($staff)iq($d,'INSERT INTO inv_customer_salespeople VALUES(?,?) ON CONFLICT(party_id) DO UPDATE SET salesperson_id=excluded.salesperson_id',[$id,$staff]);
  else iq($d,'DELETE FROM inv_customer_salespeople WHERE party_id=?',[$id]);
  iq($d,'UPDATE inv_parties SET version=version+1 WHERE id=?',[$id]);
  ilog($d,'customer.salesperson',$id,['from'=>$previous,'to'=>$staff]);
 }
 return ['count'=>count($rows)];
}
