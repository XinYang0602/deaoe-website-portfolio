<?php
require __DIR__.'/../src/service/ui-preferences.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);uiPreferenceSchema($db);
function fontCheck($value){if(!$value)throw new RuntimeException('font preference assertion');}
fontCheck(uiPreferenceRead($db,'admin')===100);
uiPreferenceSave($db,'admin',125);uiPreferenceSave($db,'second-account',150);
fontCheck(uiPreferenceRead($db,'admin')===125);fontCheck(uiPreferenceRead($db,'second-account')===150);fontCheck(uiPreferenceRead($db,'new-account')===100);
foreach([null,'150',99,151,100.0,[],true] as $bad){try{uiPreferenceSave($db,'admin',$bad);throw new RuntimeException('invalid preference accepted');}catch(InvalidArgumentException $e){}}
fontCheck(uiPreferenceRead($db,'admin')===125);uiPreferenceSave($db,'admin',100);fontCheck(uiPreferenceRead($db,'second-account')===150);
uiPreferenceSchema($db);fontCheck(uiPreferenceRead($db,'second-account')===150);
echo "PASS account-scoped font persistence, default, bounds, types and reset isolation\n";
