<?php
declare(strict_types=1);

// Test-only destructive cleanup. Caller holds BEGIN IMMEDIATE throughout planning,
// backup and deletion. Master data for indirectly affected products is retained.
function cleanupValues(mixed $value, string $key = ''): array {
    $references = ['id','product_id','doc_id','source_id','source_line','line_id',
        'sale_id','reference','request_key','receipt_line','issue_line','sn','lot_id','op_id','return_id','card_id',
        'inventorySN','manufacturer_sn','stockId','order','cardId','exchange','serials','old_serials','old_sn','new_sn'];
    if (is_string($value) && $key==='source') {
        if(preg_match('/^(sale|history|return|purchase|receipt|purchase-return|purchase-history|purchase_return|payment|offset):(.+)$/',$value,$m))return [$m[2],$value];
        return [];
    }
    if (is_string($value)) {
        $json = json_decode($value, true);
        if (is_array($json)) return cleanupValues($json, $key);
        if ($value === '' || !in_array($key, $references, true)) return [];
        return [in_array($key, ['sn','inventorySN','manufacturer_sn','serials','old_serials','old_sn','new_sn'], true) ? strtoupper($value) : $value];
    }
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $k => $v) {
        $out = array_merge($out, cleanupValues($v, is_int($k) ? $key : (string)$k));
    }
    return $out;
}
function cleanupPlan(PDO $d, array $a): array {
    $ids = $a['ids'] ?? [];
    if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 200) iv('请选择 1–200 个测试商品');
    foreach ($ids as $id) if (!is_string($id) || $id === '') iv('商品编号错误');
    $ids = array_values(array_unique($ids));
    $products = $d->query('SELECT * FROM inv_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ids as $id) if (!in_array($id, array_column($products, 'id'), true)) iv('商品已变化，请刷新');
    $tables = ['inv_docs','inv_lines','inv_balances','inv_sn','inv_ledger','inv_sn_events','inv_card_links','inv_ops','inv_lots','inv_lot_moves','inv_allocations','inv_holds','inv_return_lines','inv_exchange_links','inv_freezes'];
    foreach(['inv_fin_sales','inv_fin_entries','inv_fin_purchases','inv_fin_aux','inv_fin_links','inv_fin_requests','inv_fin_payment_accounts'] as $t)if(iq($d,"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",[$t])->fetchColumn())$tables[]=$t;
    $rows = [];
    foreach ($tables as $t) $rows[$t] = $d->query('SELECT rowid AS cleanup_rowid,* FROM '.$t.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    $state = $d->query('SELECT * FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $body = $state ? json_decode($state['body'], true, 512, JSON_THROW_ON_ERROR) : [];
    // Only transactional legacy records participate; never customer accounts/settings.
    foreach (['stock','moves','cards','orders'] as $key) $rows['state.'.$key] = $body[$key] ?? [];
    $tokens = array_fill_keys($ids, true);
    $picked = [];
    do {
        $changed = false;
        foreach ($rows as $table => $list) foreach ($list as $i => $row) {
            if (isset($picked[$table][$i])) continue;
            if (!array_intersect_key(array_fill_keys(cleanupValues($row), true), $tokens)) continue;
            $picked[$table][$i] = $row;
            $changed = true;
            // Only declared references propagate, never customer/warehouse/party IDs
            // or ordinary text such as document kind, note and product name.
            foreach (cleanupValues($row) as $reference) $tokens[$reference] = true;
        }
    } while ($changed);
    // Only explicit document/request links propagate. Never delete every entry
    // for a party: unrelated payments and unallocated advances remain intact.
    // Print jobs are snapshots, not inventory dependencies.
    foreach ($d->query('SELECT rowid AS cleanup_rowid,* FROM inv_print_jobs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
        if (array_intersect_key(array_fill_keys(cleanupValues($row), true), $tokens)) $picked['inv_print_jobs'][$i] = $row;
    }
    $affected = array_values(array_filter($products, fn($p) => isset($tokens[$p['id']])));
    $summary = ['products'=>array_map(fn($p)=>['id'=>$p['id'],'code'=>$p['code'],'name'=>$p['name'],'delete'=>in_array($p['id'],$ids,true)],$affected), 'counts'=>array_map('count',$picked)];
    $fingerprint = hash('sha256', json_encode([$ids,$picked,$affected,$state], JSON_THROW_ON_ERROR));
    return compact('ids','picked','state','body','summary','fingerprint');
}
function cleanupBackup(PDO $d): string {
    // SQL snapshot under the same write lock; outside the web root, owner-only.
    $directory = dirname($d->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file']).'/test-cleanup-backups';
    $old = umask(0077);
    try {
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('备份目录创建失败');
        $path = $directory.'/before-cleanup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sql';
        $f = fopen($path,'x');
        if (!$f) throw new RuntimeException('备份创建失败');
        $write = function(string $s) use ($f):void { if(fwrite($f,$s)!==strlen($s))throw new RuntimeException('备份写入失败'); };
        try {
            $write("PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
            $schema = $d->query("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type='table' DESC,name")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($schema as $s) if ($s['type']==='table' && $s['name']!=='sqlite_sequence') {
                $write($s['sql'].";\n");
                $q='"'.str_replace('"','""',$s['name']).'"';
                foreach($d->query('SELECT * FROM '.$q)->fetchAll(PDO::FETCH_NUM) as $row) {
                    $values=array_map(fn($v)=>$v===null?'NULL':(is_int($v)||is_float($v)?(string)$v:$d->quote((string)$v)),$row);
                    $write('INSERT INTO '.$q.' VALUES('.implode(',',$values).");\n");
                }
            }
            if($d->query("SELECT 1 FROM sqlite_master WHERE name='sqlite_sequence'")->fetchColumn()) {
                $write("DELETE FROM sqlite_sequence;\n");
                foreach($d->query('SELECT name,seq FROM sqlite_sequence') as $s)$write('INSERT INTO sqlite_sequence VALUES('.$d->quote($s['name']).','.(int)$s['seq'].");\n");
            }
            foreach($schema as $s)if($s['type']!=='table')$write($s['sql'].";\n");
            $write("COMMIT;\n");
            if(!fflush($f))throw new RuntimeException('备份刷新失败');
            if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('备份落盘失败');
        } finally { fclose($f); }
        return $path;
    } finally { umask($old); }
}
function invTestCleanup(PDO $d, array $a): array {
    $p = cleanupPlan($d,$a);
    if (($a['action']??'')==='test_cleanup_preview') return ['summary'=>$p['summary'],'fingerprint'=>$p['fingerprint']];
    if (($a['confirmation']??'')!=='删除测试数据' || !hash_equals($p['fingerprint'],(string)($a['fingerprint']??''))) iv('确认信息不匹配或数据已变化，请重新预览');
    $backup = cleanupBackup($d); // Failure aborts before the first DELETE.
    $d->exec('PRAGMA defer_foreign_keys=ON');
    foreach($p['picked'] as $table=>$rows) {
        if(str_starts_with($table,'state.')) {
            $key=substr($table,6);
            $p['body'][$key]=array_values(array_diff_key($p['body'][$key],$rows));
        } else foreach($rows as $row)iq($d,'DELETE FROM '.$table.' WHERE rowid=?',[$row['cleanup_rowid']]);
    }
    foreach($p['ids'] as $id) {
        foreach(['inv_images','inv_label_profiles'] as $table)iq($d,'DELETE FROM '.$table.' WHERE product_id=?',[$id]);
        iq($d,'DELETE FROM inv_products WHERE id=?',[$id]);
    }
    if($p['state'] && json_encode($p['body'])!==json_encode(json_decode($p['state']['body'],true))) {
        iq($d,'INSERT INTO audit VALUES(?,?,?,?)',[$p['state']['revision'],$_SESSION['user']??'admin',gmdate('c'),$p['state']['body']]);
        iq($d,'UPDATE state SET revision=revision+1,body=?,updated_at=? WHERE id=1',[json_encode($p['body'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c')]);
    }
    if($d->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('关联完整性检查失败，已回滚');
    ilog($d,'test.cleanup',implode(',',$p['ids']),['summary'=>$p['summary'],'backup'=>$backup]);
    return ['summary'=>$p['summary'],'backup'=>$backup];
}
