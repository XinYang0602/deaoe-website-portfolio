<?php
declare(strict_types=1);

// Entries are immutable. All callers write inside the inventory transaction.
function finSchema(PDO $d): void {
    $d->exec("CREATE TABLE IF NOT EXISTS inv_fin_sales (
        sale_id TEXT PRIMARY KEY REFERENCES inv_docs(id), state TEXT NOT NULL,
        confirmed_cents INTEGER, note TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS inv_fin_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT, party_id TEXT NOT NULL REFERENCES inv_parties(id),
        source TEXT NOT NULL UNIQUE, kind TEXT NOT NULL, cents INTEGER NOT NULL,
        note TEXT NOT NULL, account TEXT NOT NULL, created_at TEXT NOT NULL);");
    // Capture the existing population once, rather than a date cutoff.
    if (!iq($d,"SELECT 1 FROM inv_meta WHERE k='finance_v1'")->fetchColumn()) {
        $own = !$d->inTransaction();
        if ($own) $d->exec("BEGIN IMMEDIATE");
        try {
            if (iq($d,"SELECT 1 FROM inv_meta WHERE k='finance_v1'")->fetchColumn()) {
                if ($own) $d->exec("COMMIT");
                finFourSchema($d);
                return;
            }
            iq($d,"INSERT OR IGNORE INTO inv_fin_sales(sale_id,state) SELECT id,'pending' FROM inv_docs WHERE kind='sale' AND status='approved'");
            iq($d,"INSERT OR IGNORE INTO inv_meta VALUES('finance_v1',?)",[gmdate('c')]);
            if ($own) $d->exec("COMMIT");
        } catch (Throwable $e) { if ($own) $d->exec("ROLLBACK"); throw $e; }
    }
    finFourSchema($d);
}
function finEntry(PDO $d,string $party,string $source,string $kind,int $cents,string $note='',string $account=''): void {
    iq($d,'INSERT INTO inv_fin_entries(party_id,source,kind,cents,note,account,created_at) VALUES(?,?,?,?,?,?,?)',
        [$party,$source,$kind,$cents,$note,$account,gmdate('c')]);
    ilog($d,'finance.'.$kind,$source,['party_id'=>$party,'cents'=>$cents,'note'=>$note]);
}
function finSale(PDO $d,string $id): void {
    if (iq($d,'SELECT 1 FROM inv_fin_sales WHERE sale_id=?',[$id])->fetchColumn()) return;
    $doc=iq($d,'SELECT * FROM inv_docs WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if (iq($d,'SELECT 1 FROM inv_lines WHERE doc_id=? AND price_cents IS NULL',[$id])->fetchColumn()) iv('销售计入应收前请填写所有商品单价（免费商品填 0）');
    $amount=(int)iq($d,'SELECT SUM(qty*price_cents) FROM inv_lines WHERE doc_id=?',[$id])->fetchColumn();
    iq($d,"INSERT INTO inv_fin_sales VALUES(?,'confirmed',?,'新销售审核自动计入')",[$id,$amount]);
    finEntry($d,$doc['party_id'],'sale:'.$id,'sale',$amount);
}
function finOperation(PDO $d,string $id,string $kind,array &$b): void {
    finPurchaseOperation($d,$id,$kind,$b);
    if ($kind==='sales_return') {
        $mode=$b['return_mode']??'replacement';
        if (!in_array($mode,['refund','replacement'],true)) iv('请选择退货退款或退货待补发');
        $b['return_mode']=$mode;
        $line=iq($d,'SELECT l.price_cents,x.source_id,x.party_id FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.id=?',[$b['source_line']])->fetch(PDO::FETCH_ASSOC);
        if ($mode==='refund') {
            if (!$line['source_id'] || !iq($d,"SELECT 1 FROM inv_fin_sales WHERE sale_id=? AND state='confirmed'",[$line['source_id']])->fetchColumn()) iv('请先在账款管理核对原销售单欠款，再审核退款退货');
            if ($line['price_cents']===null || money($b['refund_price'])>(int)$line['price_cents']) iv('退货单价不能超过原销售单价');
            finEntry($d,$line['party_id'],'return:'.$id,'return',-(int)$b['refund_cents'],$b['note']);
        }
        $b['finance_mode']=$mode;
        $b['settlement']=$mode==='replacement'?'待补发（不变动账款）':'应收已冲减，实际退款请到账款管理登记';
    }
    if ($kind==='sales_exchange') {
        $rb=json_decode((string)iq($d,'SELECT body FROM inv_ops WHERE id=?',[$b['return_id']])->fetchColumn(),true);
        if (($rb['finance_mode']??'')!=='replacement') iv('仅退货待补发单可办理补发；历史退货请先核对，退款退货请重新销售开单');
        $b['difference_cents']=0;
        $b['settlement']='补发完成（不变动账款）';
        $b['finance_mode']='replacement';
    }
}
function finBalance(PDO $d,string $party): int {
    return (int)iq($d,'SELECT COALESCE(SUM(cents),0) FROM inv_fin_entries WHERE party_id=?',[$party])->fetchColumn();
}
function finLegacyAction(PDO $d,array $a): array {
    $op=$a['operation']??'';
    $note=it($a['note']??'',1000);
    if (!$note) iv('请填写核对依据或收退款备注');
    $amount=money($a['amount']??'');
    if ($amount===null) iv('请填写金额');
    if ($op==='confirm_history') {
        $id=it($a['sale_id']??'');
        $row=iq($d,'SELECT f.*,x.party_id FROM inv_fin_sales f JOIN inv_docs x ON x.id=f.sale_id WHERE sale_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) iv('历史销售单不存在');
        if ($row['state']==='confirmed') return ['repeated'=>true];
        $total=(int)iq($d,'SELECT SUM(qty*price_cents) FROM inv_lines WHERE doc_id=?',[$id])->fetchColumn();
        if ($amount>$total) iv('确认欠款不能超过原销售金额，请先核实原单价格');
        iq($d,"UPDATE inv_fin_sales SET state='confirmed',confirmed_cents=?,note=? WHERE sale_id=?",[$amount,$note,$id]);
        finEntry($d,$row['party_id'],'history:'.$id,'history',$amount,$note);
        return ['ok'=>true];
    }
    if (!in_array($op,['receipt','refund'],true)) iv('未知账款操作');
    $key=it($a['request_key']??'',80);
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)) iv('缺少请求编号');
    if (iq($d,'SELECT 1 FROM inv_fin_entries WHERE source=?',['payment:'.$key])->fetchColumn()) return ['repeated'=>true];
    $party=it($a['party_id']??'');
    if (!iq($d,'SELECT 1 FROM inv_parties WHERE id=?',[$party])->fetchColumn()) iv('请选择客户');
    if (iq($d,"SELECT 1 FROM inv_fin_sales f JOIN inv_docs x ON x.id=f.sale_id WHERE f.state='pending' AND x.party_id=?",[$party])->fetchColumn()) iv('请先核对该客户历史销售欠款，再登记收退款');
    $balance=finBalance($d,$party);
    if ($amount<=0 || $amount>($op==='receipt'?$balance:-$balance)) iv('金额必须大于零且不能超过当前应收／应退金额');
    $account=it($a['account']??'',100);
    if (!$account) iv('请填写收付款账户或方式');
    finEntry($d,$party,'payment:'.$key,$op,$op==='receipt'?-$amount:$amount,$note,$account);
    return ['ok'=>true];
}
function finLegacySnapshot(PDO $d): array {
    return [
        'sales'=>$d->query('SELECT * FROM inv_fin_sales')->fetchAll(PDO::FETCH_ASSOC),
        'balances'=>$d->query('SELECT party_id,SUM(cents) AS cents FROM inv_fin_entries GROUP BY party_id')->fetchAll(PDO::FETCH_ASSOC),
        'entries'=>$d->query('SELECT * FROM inv_fin_entries ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC)
    ];
}

// Four independent balances: receivables stay in the original immutable customer ledger.
function finFourSchema(PDO $d): void {
    $d->exec("CREATE TABLE IF NOT EXISTS inv_fin_accounts (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL,
        identifier TEXT NOT NULL, holder TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
        version INTEGER NOT NULL DEFAULT 1);
        CREATE TABLE IF NOT EXISTS inv_fin_payment_accounts (
        request_key TEXT PRIMARY KEY, account_id TEXT NOT NULL REFERENCES inv_fin_accounts(id), snapshot TEXT NOT NULL);");
    $d->exec("CREATE TABLE IF NOT EXISTS inv_fin_purchases (
        doc_id TEXT PRIMARY KEY REFERENCES inv_docs(id), state TEXT NOT NULL,
        confirmed_cents INTEGER, note TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS inv_fin_aux (
        id INTEGER PRIMARY KEY AUTOINCREMENT, party_id TEXT NOT NULL REFERENCES inv_parties(id),
        bucket TEXT NOT NULL CHECK(bucket IN ('ap','advance_receipt','advance_payment')),
        source TEXT NOT NULL UNIQUE, kind TEXT NOT NULL, cents INTEGER NOT NULL,
        reference TEXT NOT NULL DEFAULT '', note TEXT NOT NULL, account TEXT NOT NULL, created_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS inv_fin_links (source TEXT PRIMARY KEY, doc_id TEXT NOT NULL REFERENCES inv_docs(id));
        CREATE TABLE IF NOT EXISTS inv_fin_requests (request_key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL);");
    if (!iq($d,"SELECT 1 FROM inv_meta WHERE k='finance_four_v1'")->fetchColumn()) {
        $own=!$d->inTransaction();
        if ($own) $d->beginTransaction();
        try {
            iq($d,"INSERT OR IGNORE INTO inv_fin_purchases(doc_id,state) SELECT id,'pending' FROM inv_docs WHERE kind='receipt' AND status='approved'");
            iq($d,"INSERT OR IGNORE INTO inv_meta VALUES('finance_four_v1',?)",[gmdate('c')]);
            if ($own) $d->commit();
        } catch(Throwable $e) { if ($own) $d->rollBack(); throw $e; }
    }
}
function finAux(PDO $d,string $party,string $bucket,string $source,string $kind,int $cents,string $reference='',string $note='',string $account=''): void {
    iq($d,'INSERT INTO inv_fin_aux(party_id,bucket,source,kind,cents,reference,note,account,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$party,$bucket,$source,$kind,$cents,$reference,$note,$account,gmdate('c')]);
    ilog($d,'finance.'.$kind,$source,['party_id'=>$party,'bucket'=>$bucket,'cents'=>$cents,'reference'=>$reference,'note'=>$note]);
}
function finAuxBalance(PDO $d,string $party,string $bucket): int {
    return (int)iq($d,'SELECT COALESCE(SUM(cents),0) FROM inv_fin_aux WHERE party_id=? AND bucket=?',[$party,$bucket])->fetchColumn();
}
function finPending(PDO $d,string $party,string $kind): bool {
    $sql=$kind==='customer'?"SELECT 1 FROM inv_fin_sales f JOIN inv_docs x ON x.id=f.sale_id WHERE x.party_id=? AND f.state='pending'":"SELECT 1 FROM inv_fin_purchases f JOIN inv_docs x ON x.id=f.doc_id WHERE x.party_id=? AND f.state='pending'";
    return (bool)iq($d,$sql,[$party])->fetchColumn();
}
function finPurchase(PDO $d,string $id): void {
    if (iq($d,'SELECT 1 FROM inv_fin_purchases WHERE doc_id=?',[$id])->fetchColumn()) return;
    $doc=iq($d,"SELECT * FROM inv_docs WHERE id=? AND kind='receipt' AND status='approved'",[$id])->fetch(PDO::FETCH_ASSOC);
    if (!$doc) iv('采购应付必须关联已审核入库单');
    if (iq($d,'SELECT 1 FROM inv_lines WHERE doc_id=? AND price_cents IS NULL',[$id])->fetchColumn()) iv('计入应付前请填写所有入库商品单价（免费商品填 0）');
    $total=(int)iq($d,'SELECT SUM(qty*price_cents) FROM inv_lines WHERE doc_id=?',[$id])->fetchColumn();
    iq($d,"INSERT INTO inv_fin_purchases VALUES(?,'confirmed',?,'入库审核自动计入')",[$id,$total]);
    finAux($d,$doc['party_id'],'ap','purchase:'.$id,'purchase',$total,$id);
}
function finPurchaseOperation(PDO $d,string $id,string $kind,array &$b): void {
    if ($kind==='purchase_return') {
        $line=iq($d,'SELECT l.*,x.party_id FROM inv_lines l JOIN inv_docs x ON x.id=l.doc_id WHERE l.id=?',[$b['source_line']])->fetch(PDO::FETCH_ASSOC);
        if (!$line || !iq($d,"SELECT 1 FROM inv_fin_purchases WHERE doc_id=? AND state='confirmed'",[$line['doc_id']])->fetchColumn()) iv('请先核对原入库单历史应付，再审核采购退货');
        if ($line['price_cents']===null || money($b['refund_price'])>(int)$line['price_cents']) iv('采购退货单价不能超过原入库单价');
        finAux($d,$line['party_id'],'ap','purchase-return:'.$id,'purchase_return',-(int)$b['refund_cents'],$line['doc_id'],$b['note']);
        $b['settlement']='应付已冲减，供应商实际退款请到账款管理登记';
    }
    // Exchange generates a real receipt in p2Exchange. Charge the incoming value;
    // the preceding return already reversed its value, so the net is the difference.
    if ($kind==='purchase_exchange') {
        finPurchase($d,$id);
        $b['settlement']='换入金额已计应付，实际付款请到账款管理登记';
    }
}
function finReference(PDO $d,string $id,string $party,string $kind): void {
    $expected=$kind==='customer'?'sale':'receipt';
    if (!iq($d,"SELECT 1 FROM inv_docs WHERE id=? AND party_id=? AND kind=? AND status='approved'",[$id,$party,$expected])->fetchColumn()) iv('关联单据必须是同一往来的已审核销售单／入库单');
    $confirmed=$kind==='customer'?iq($d,"SELECT 1 FROM inv_fin_sales WHERE sale_id=? AND state='confirmed'",[$id]):iq($d,"SELECT 1 FROM inv_fin_purchases WHERE doc_id=? AND state='confirmed'",[$id]);
    if (!$confirmed->fetchColumn()) iv('请先核对关联单据');
}
function finDocumentBalance(PDO $d,string $party,string $id,string $kind): int {
    if ($kind==='supplier') return (int)iq($d,"SELECT COALESCE(SUM(cents),0) FROM inv_fin_aux WHERE party_id=? AND bucket='ap' AND reference=?",[$party,$id])->fetchColumn();
    $total=(int)iq($d,'SELECT COALESCE(SUM(e.cents),0) FROM inv_fin_entries e LEFT JOIN inv_fin_links k ON k.source=e.source WHERE e.party_id=? AND (k.doc_id=? OR e.source=? OR e.source=?)',[$party,$id,'sale:'.$id,'history:'.$id])->fetchColumn();
    if (p2Ready($d)) $total+=(int)iq($d,"SELECT COALESCE(SUM(e.cents),0) FROM inv_fin_entries e JOIN inv_ops o ON e.source='return:'||o.id JOIN inv_lines l ON l.id=json_extract(o.body,'$.source_line') JOIN inv_docs x ON x.id=l.doc_id WHERE e.party_id=? AND x.source_id=?",[$party,$id])->fetchColumn();
    return $total;
}
function finAction(PDO $d,array $a): array {
    $op=it($a['operation']??'');
    if ($op==='save_account') return finSaveAccount($d,$a);
    if ($op==='confirm_history') return finLegacyAction($d,$a);
    $party=it($a['party_id']??'');$ref=it($a['reference']??'');
    $note=it($a['note']??'',1000);$account=it($a['account']??'',100);$amount=money($a['amount']??'');
    if (!$note || $amount===null) iv('请填写金额及核对依据／备注');
    if ($op==='confirm_purchase_history') {
        $id=it($a['doc_id']??'');
        $row=iq($d,'SELECT f.*,x.party_id FROM inv_fin_purchases f JOIN inv_docs x ON x.id=f.doc_id WHERE doc_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) iv('历史入库单不存在');
        if ($row['state']==='confirmed') return ['repeated'=>true];
        $missingPrice=(bool)iq($d,'SELECT 1 FROM inv_lines WHERE doc_id=? AND price_cents IS NULL',[$id])->fetchColumn();
        $total=(int)iq($d,'SELECT SUM(qty*price_cents) FROM inv_lines WHERE doc_id=?',[$id])->fetchColumn();
        if (!$missingPrice && $amount>$total) iv('未付金额不能超过原入库金额');
        iq($d,"UPDATE inv_fin_purchases SET state='confirmed',confirmed_cents=?,note=? WHERE doc_id=?",[$amount,$note,$id]);
        finAux($d,$row['party_id'],'ap','purchase-history:'.$id,'purchase_history',$amount,$id,$note);
        return ['ok'=>true];
    }
    $customer=in_array($op,['receipt','refund','advance_receipt','return_advance_receipt','offset_receipt'],true);
    $supplier=in_array($op,['payment','supplier_refund','advance_payment','return_advance_payment','offset_payment'],true);
    if (!$customer && !$supplier) iv('未知账款操作');
    $kind=$customer?'customer':'supplier';
    if (!iq($d,'SELECT 1 FROM inv_parties WHERE id=? AND kind=?',[$party,$kind])->fetchColumn()) iv('请选择正确的客户或供应商');
    if ($amount<=0) iv('金额必须大于零');
    if (in_array($op,['advance_receipt','advance_payment'],true) && !iq($d,'SELECT 1 FROM inv_parties WHERE id=? AND active=1',[$party])->fetchColumn()) iv('已停用往来不能新增预收／预付款');
    $offset=in_array($op,['offset_receipt','offset_payment'],true);
    $selected=null;
    if (!$offset) {
        $accountId=it($a['account_id']??'');
        $selected=iq($d,'SELECT * FROM inv_fin_accounts WHERE id=?',[$accountId])->fetch(PDO::FETCH_ASSOC);
        if (!$selected) iv('请选择已设置的收付款账户');
        $account=finAccountLabel($selected);
        $a['account']=$account;
    }
    if ($offset && !$ref) iv('预收／预付抵扣必须选择关联单据');
    $advanceAction=in_array($op,['advance_receipt','advance_payment','return_advance_receipt','return_advance_payment'],true);
    if ($ref) {
        if ($advanceAction) {
            if (!iq($d,"SELECT 1 FROM inv_docs WHERE id=? AND party_id=? AND kind=? AND status='approved'",[$ref,$party,$customer?'sale':'purchase'])->fetchColumn()) iv('预收／预付只可关联同一往来的已审核销售／采购订单');
        } else finReference($d,$ref,$party,$kind);
    }
    $key=it($a['request_key']??'',80);
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)) iv('缺少请求编号');
    $fingerprint=hash('sha256',json_encode([$op,$party,$amount,$note,$selected ? $selected['id'] : $account,$ref]));
    $old=iq($d,'SELECT fingerprint FROM inv_fin_requests WHERE request_key=?',[$key])->fetchColumn();
    if ($old!==false) { if (!hash_equals($old,$fingerprint)) iv('请求编号已用于其他账款操作，请刷新后重试'); return ['repeated'=>true]; }
    if ($selected && (!(int)$selected['active'] || (int)$selected['version']!==($a['account_version']??null))) iv('收付款账户已停用或被修改，请刷新后重新选择');
    if (!in_array($op,['receipt','refund'],true) && iq($d,'SELECT 1 FROM inv_fin_entries WHERE source=?',['payment:'.$key])->fetchColumn()) iv('请求编号已用于旧收退款，请刷新');
    $balance=$customer?finBalance($d,$party):finAuxBalance($d,$party,'ap');
    if (in_array($op,['receipt','refund','payment','supplier_refund','offset_receipt','offset_payment'],true) && finPending($d,$party,$kind)) iv('请先核对该往来全部历史欠款，再登记结算');
    if ($ref && !$advanceAction) {
        $docBalance=finDocumentBalance($d,$party,$ref,$kind);
        $limit=in_array($op,['refund','supplier_refund'],true)?-$docBalance:$docBalance;
        if ($amount>$limit) iv('金额超过关联单据可结算余额；历史未关联付款请按往来余额结算');
    }
    $source='payment:'.$key;
    if ($customer && in_array($op,['receipt','refund'],true)) {
        // Compatibility with pre-upgrade payment request IDs; do not silently accept a changed payload.
        $legacy=iq($d,'SELECT * FROM inv_fin_entries WHERE source=?',[$source])->fetch(PDO::FETCH_ASSOC);
        if ($legacy && ($legacy['party_id']!==$party || $legacy['kind']!==$op || (int)$legacy['cents']!==($op==='receipt'?-$amount:$amount) || $legacy['note']!==$note || $legacy['account']!==$account || $ref!=='')) iv('请求编号已使用，请刷新');
        $result=finLegacyAction($d,$a);
        if ($ref) iq($d,'INSERT INTO inv_fin_links VALUES(?,?)',[$source,$ref]);
    } else {
        $bucket=$customer?'advance_receipt':'advance_payment';
        $advance=finAuxBalance($d,$party,$bucket);
        if (in_array($op,['advance_receipt','advance_payment'],true)) {
            finAux($d,$party,$bucket,$source,$op,$amount,$ref,$note,$account);
        } elseif (in_array($op,['return_advance_receipt','return_advance_payment'],true)) {
            if ($amount>$advance) iv('退款不能超过剩余预收／预付余额');
            finAux($d,$party,$bucket,$source,$op,-$amount,$ref,$note,$account);
        } elseif ($offset) {
            if ($amount>$advance || $amount>$balance) iv('抵扣不能超过预收／预付余额或当前应收／应付余额');
            finAux($d,$party,$bucket,$source,$op,-$amount,$ref,$note);
            if ($customer) {
                finEntry($d,$party,'offset:'.$key,$op,-$amount,$note,'预收抵扣');
                iq($d,'INSERT INTO inv_fin_links VALUES(?,?)',['offset:'.$key,$ref]);
            } else finAux($d,$party,'ap','offset:'.$key,$op,-$amount,$ref,$note,'预付抵扣');
        } else {
            $limit=$op==='payment'?$balance:-$balance;
            if ($amount>$limit) iv('金额不能超过当前应付或应收供应商退款余额');
            finAux($d,$party,'ap',$source,$op,$op==='payment'?-$amount:$amount,$ref,$note,$account);
        }
        $result=['ok'=>true];
    }
    if ($selected) iq($d,'INSERT INTO inv_fin_payment_accounts VALUES(?,?,?)',[$key,$selected['id'],$account]);
    iq($d,'INSERT INTO inv_fin_requests VALUES(?,?)',[$key,$fingerprint]);
    return $result;
}
function finSnapshot(PDO $d): array {
    $out=finLegacySnapshot($d);
    $out['accounts']=$d->query('SELECT * FROM inv_fin_accounts ORDER BY active DESC,name,id')->fetchAll(PDO::FETCH_ASSOC);
    $out['purchases']=$d->query('SELECT f.*,x.party_id FROM inv_fin_purchases f JOIN inv_docs x ON x.id=f.doc_id')->fetchAll(PDO::FETCH_ASSOC);
    $out['aux_balances']=$d->query('SELECT party_id,bucket,SUM(cents) AS cents FROM inv_fin_aux GROUP BY party_id,bucket')->fetchAll(PDO::FETCH_ASSOC);
    $out['aux_entries']=$d->query('SELECT * FROM inv_fin_aux ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $out['links']=$d->query('SELECT * FROM inv_fin_links')->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

function finAccountLabel(array $a): string {
    return $a['name'].($a['identifier']!==''?' · '.$a['identifier']:'').($a['holder']!==''?' · '.$a['holder']:'');
}
function finSaveAccount(PDO $d,array $a): array {
    $id=it($a['id']??'');
    $name=it($a['name']??'',30);$type=it($a['type']??'');
    $identifier=it($a['identifier']??'',40);$holder=it($a['holder']??'',20);
    if (!$name || !in_array($type,['wechat','alipay','bank','cash','other'],true)) iv('请填写账户名称并选择账户类型');
    if ($type!=='cash' && !$identifier) iv('请填写微信号、账号或识别信息');
    $active=($a['active']??null);
    if (!in_array($active,[0,1],true)) iv('账户状态无效');
    $fields=[$name,$type,$identifier,$holder,$active];
    if ($id) {
        $row=iq($d,'SELECT * FROM inv_fin_accounts WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['version']!==($a['version']??null)) iv('账户已被修改，请刷新再编辑');
        iq($d,'UPDATE inv_fin_accounts SET name=?,type=?,identifier=?,holder=?,active=?,version=version+1 WHERE id=?',[...$fields,$id]);
    } else {
        // A stable client-generated ID makes repeated saves of a new account safe.
        $key=it($a['request_key']??'',80);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)) iv('缺少请求编号');
        $id='ACC-'.hash('sha256',$key);
        $old=iq($d,'SELECT * FROM inv_fin_accounts WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if ([$old['name'],$old['type'],$old['identifier'],$old['holder'],(int)$old['active']]!==$fields) iv('请求编号已使用，请重新打开表单');
            return ['id'=>$id,'repeated'=>true];
        }
        iq($d,'INSERT INTO inv_fin_accounts VALUES(?,?,?,?,?,?,1)',[$id,...$fields]);
    }
    ilog($d,'finance.account.save',$id,['name'=>$name,'type'=>$type,'active'=>$active]);
    return ['id'=>$id];
}
