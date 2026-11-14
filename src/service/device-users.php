<?php
declare(strict_types=1);
require_once __DIR__.'/upload-webp.php';
require_once __DIR__.'/storage.php';

function deviceUserSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS service_machine_registrations (
        id TEXT PRIMARY KEY, customer TEXT NOT NULL, phone TEXT NOT NULL, address TEXT NOT NULL,
        product TEXT NOT NULL, sn TEXT NOT NULL, date TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS service_machine_photos (
        id TEXT PRIMARY KEY, machine_key TEXT NOT NULL, webp_base64 TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS service_photo_machine ON service_machine_photos(machine_key)");
}
function deviceUserKeys(array $machine, string $source): array {
    $phone = preg_replace('/[\s()-]+/u', '', trim($machine['phone']));
    $owner = hash('sha256', json_encode([trim($machine['customer']), $phone ?: $source]));
    $serial = mb_strtoupper(trim($machine['sn']));
    return [$owner, hash('sha256', json_encode([$owner, $serial !== '' ? ['sn',$serial] : ['record',$source]]))];
}
// Build from the saved service records, so older cards and mini-program orders also appear.
function deviceUserDirectory(PDO $db): array {
    $state = json_decode($db->query('SELECT body FROM state WHERE id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $manual = $db->query('SELECT * FROM service_machine_registrations ORDER BY rowid DESC')->fetchAll(PDO::FETCH_ASSOC);
    $users = [];
    $photos = [];
    foreach ($db->query('SELECT id,machine_key FROM service_machine_photos ORDER BY rowid') as $photo) {
        $photos[$photo['machine_key']][] = $photo['id'];
    }
    foreach (['orders'=>$state['orders'], 'cards'=>$state['cards'], 'manual'=>$manual] as $type=>$records) {
        foreach ($records as $record) {
            [$owner,$key] = deviceUserKeys($record, $type.':'.$record['id']);
            if (!isset($users[$owner])) $users[$owner] = ['name'=>$record['customer'],'phone'=>$record['phone'],'machines'=>[]];
            $machine = &$users[$owner]['machines'][$key];
            if (!$machine) $machine = ['key'=>$key,'product'=>$record['product'],'sn'=>$record['sn'],'address'=>$record['address']??'','orders'=>[],'cards'=>[],'photos'=>$photos[$key]??[]];
            if (!$machine['address']) $machine['address'] = $record['address']??'';
            if ($type !== 'manual') $machine[$type][] = $record['id'];
            unset($machine);
        }
    }
    foreach ($users as &$user) $user['machines'] = array_values($user['machines']);
    return array_values($users);
}
function deviceUserPhoto(PDO $db, string $key, array $file): void {
    $bytes = serviceWebpVariants($file, ['photo'=>3000])['photo'];
    $stmt = $db->prepare('INSERT INTO service_machine_photos VALUES(?,?,?)');
    $stmt->bindValue(1, bin2hex(random_bytes(16)));
    $stmt->bindValue(2, $key);
    $stmt->bindValue(3, base64_encode($bytes));
    $stmt->execute();
}
function handleDeviceUsers(): never {
    if (($_SESSION['user']??'') !== 'admin') jsonReply(403,['error'=>'无档案管理权限']);
    $db = database();
    try {
        deviceUserSchema($db);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (isset($_GET['image'])) {
                $id = $_GET['image'];
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) jsonReply(404,['error'=>'图片不存在']);
                $q = $db->prepare('SELECT webp_base64 FROM service_machine_photos WHERE id=?');
                $q->execute([$id]);
                $bytes = $q->fetchColumn();
                if ($bytes === false) jsonReply(404,['error'=>'图片不存在']);
                $bytes = base64_decode($bytes, true);
                if ($bytes === false) throw new RuntimeException('二维码照片读取失败');
                serviceWebpHeaders($id,'机身二维码.webp',strlen($bytes),isset($_GET['download']));
                echo $bytes;
                exit;
            }
            jsonReply(200,['users'=>deviceUserDirectory($db)]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonReply(405,['error'=>'不支持的方法']);
        if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) jsonReply(403,['error'=>'请刷新后重试']);
        $db->beginTransaction();
        $file = $_FILES['image']??[];
        if (($_POST['operation']??'') === 'register') {
            $machine = [];
            foreach (['customer'=>80,'phone'=>40,'product'=>200,'sn'=>200,'address'=>500] as $field=>$limit) {
                $value = $_POST[$field]??'';
                if (!is_string($value) || mb_strlen($value)>$limit) throw new InvalidArgumentException('字段格式或长度不正确');
                $machine[$field] = trim($value);
            }
            if (!$machine['customer'] || !$machine['phone'] || !$machine['product']) throw new InvalidArgumentException('请填写使用人、联系电话和产品型号');
            $id = $_POST['request_id']??'';
            if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D',$id)) throw new InvalidArgumentException('登记编号无效');
            $hasImage = ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
            if (!$machine['sn'] && !$hasImage) throw new InvalidArgumentException('请填写机身编号或上传机身二维码');
            $q=$db->prepare('INSERT INTO service_machine_registrations VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO NOTHING');
            $q->execute([$id,$machine['customer'],$machine['phone'],$machine['address'],$machine['product'],$machine['sn'],gmdate('Y-m-d')]);
            [, $key] = deviceUserKeys($machine,'manual:'.$id);
            if ($q->rowCount() && $hasImage) deviceUserPhoto($db,$key,$file);
        } elseif (($_POST['operation']??'') === 'photo') {
            $key = $_POST['machine_key']??'';
            $found = false;
            foreach (deviceUserDirectory($db) as $user) {
                if (in_array($key,array_column($user['machines'],'key'),true)) { $found=true; break; }
            }
            if (!$found) throw new InvalidArgumentException('机器记录不存在，请刷新');
            deviceUserPhoto($db,$key,$file);
        } else throw new InvalidArgumentException('未知档案操作');
        $db->commit();
        jsonReply(200,['ok'=>true]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof InvalidArgumentException) jsonReply(422,['error'=>$error->getMessage()]);
        error_log('Machine profiles: '.$error->getMessage());
        jsonReply(503,['error'=>'档案操作失败，请刷新后重试']);
    }
}
