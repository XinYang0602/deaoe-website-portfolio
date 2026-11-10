<?php
declare(strict_types=1);
const APP = __DIR__;
function dataDir(): string { return getenv('DEAOE_DATA') ?: '/var/lib/showcase-cms'; }
function fields(): array { return json_decode(file_get_contents(APP.'/fields.json'),true,512,JSON_THROW_ON_ERROR); }
function defaults(): array { return array_map(fn($f)=>$f['default'],fields()); }
function readData(string $name, array $fallback=[]): array {
 $p=dataDir().'/'.$name.'.json';
 return is_file($p) ? json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR) : $fallback;
}
function writeData(string $name,array $value): void {
 $p=dataDir().'/'.$name.'.json'; $tmp=tempnam(dataDir(),'.write-');
 if($tmp===false || file_put_contents($tmp,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('保存失败');
 chmod($tmp,0600); if(!rename($tmp,$p)) throw new RuntimeException('保存失败');
}
function locked(callable $fn): mixed {
 $h=fopen(dataDir().'/state.lock','c'); if(!$h || !flock($h,LOCK_EX)) throw new RuntimeException('暂时无法保存');
 try { return $fn(); } finally {flock($h,LOCK_UN);fclose($h);}
}
function h(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function renderPage(array $values): string {
 $map=[]; foreach(fields() as $k=>$f){$v=(string)($values[$k]??$f['default']);$map['{{'.$k.'}}']=$f['type']==='text'?nl2br(h($v)):h($v);}
 require_once APP.'/address-links.php';
 $map['{{address_rows}}']=deaoeAddressRows($values);
 require_once APP.'/home-carousel.php';
 return homeCarouselEnhance(strtr(file_get_contents(APP.'/template.html'),$map),$values);
}
function startSession(): void {
 ini_set('session.use_strict_mode','1');
 session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Strict','path'=>'/admin/']);
 session_start();
 if(isset($_SESSION['seen']) && time()-$_SESSION['seen']>3600) $_SESSION=[];
 $_SESSION['seen']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
 header('Cache-Control: no-store'); header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
}
