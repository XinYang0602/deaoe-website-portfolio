<?php
require __DIR__.'/purchase-operations.php';
$a=['operation'=>'save_account','name'=>'微信收款一','type'=>'wechat','identifier'=>'test-wx-001','holder'=>'测试出纳','active'=>1,'request_key'=>bin2hex(random_bytes(16))];
$id=tx(fn()=>finAction($d,$a))['id'];expect(tx(fn()=>finAction($d,$a))['repeated'],'账户创建重试不重复');
$pay=['operation'=>'advance_receipt','party_id'=>'C','amount'=>'10','account_id'=>$id,'account_version'=>1,'note'=>'测试预收','request_key'=>bin2hex(random_bytes(16))];
tx(fn()=>finAction($d,$pay));$label=value('SELECT account FROM inv_fin_aux WHERE source=?',['payment:'.$pay['request_key']]);expect(str_contains($label,'test-wx-001'),'账户身份写入流水');
$edit=array_replace($a,['id'=>$id,'version'=>1,'name'=>'微信收款二','identifier'=>'test-wx-002']);tx(fn()=>finAction($d,$edit));
expect(value('SELECT account FROM inv_fin_aux WHERE source=?',['payment:'.$pay['request_key']])===$label,'改账户不改历史');
expect(tx(fn()=>finAction($d,$pay))['repeated'],'改名后原付款重试仍幂等');
denied(fn()=>finAction($d,$edit));
denied(fn()=>finAction($d,array_replace($pay,['request_key'=>bin2hex(random_bytes(16))])));
$disable=array_replace($edit,['version'=>2,'active'=>0]);tx(fn()=>finAction($d,$disable));
denied(fn()=>finAction($d,array_replace($pay,['account_version'=>3,'request_key'=>bin2hex(random_bytes(16))])));
denied(fn()=>finAction($d,array_replace($pay,['account_id'=>'unknown','request_key'=>bin2hex(random_bytes(16))])));
denied(fn()=>finAction($d,array_replace($pay,['account_id'=>'','account'=>'随意手填','request_key'=>bin2hex(random_bytes(16))])));
expect(finSnapshot($d)['accounts'][0]['active']===0,'共享快照显示停用状态');
echo "PASS shared accounts: creation retry, edits, stale version rejection, immutable payment snapshot, disabled/unknown account rejection\n";
