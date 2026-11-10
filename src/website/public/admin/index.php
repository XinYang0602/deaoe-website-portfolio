<?php
/* automatic-webp-v1 */
require_once (getenv('DEAOE_APP') ?: '/opt/showcase-cms').'/webp-helper.php';
require (getenv('DEAOE_APP') ?: '/opt/showcase-cms').'/common.php';
startSession();
if(isset($_GET['home_carousel'])){require APP.'/home-carousel-admin.php';exit;}
if(isset($_GET['products'])){require APP.'/products-admin.php';exit;}
$notice='';$error='';
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('页面已过期，请刷新后重试。');
  $action=$_POST['action']??'';
  if($action==='login'){
   $ok=locked(function(){
    $s=readData('attempts',['count'=>0,'until'=>0]);
    if($s['until']>time()) throw new RuntimeException('尝试次数过多，请 15 分钟后再试。');
    if($s['until'] && $s['until']<=time()) $s=['count'=>0,'until'=>0];
    $account=readData('account');
    $ok=($_POST['user']??'')==='admin' && password_verify((string)($_POST['password']??''),$account['hash']??'');
    if($ok) writeData('attempts',['count'=>0,'until'=>0]);
    else { $s['count']++;if($s['count']>=10)$s['until']=time()+900;writeData('attempts',$s); }
    return $ok;
   });
   if(!$ok) throw new RuntimeException('账号或密码不正确。');
   session_regenerate_id(true);$_SESSION['auth']=true;$_SESSION['csrf']=bin2hex(random_bytes(32));
   header('Location: /admin/');exit;
  }
  else {
   if(empty($_SESSION['auth'])) throw new RuntimeException('请先登录。');
   if($action==='logout'){$_SESSION=[];session_destroy();header('Location: /admin/');exit;}
   if(!in_array($action,['save','publish','restore'],true)) throw new RuntimeException('无效操作。');
   locked(function()use($action){
    $rev=readData('revision',['value'=>0]);
    if((int)($_POST['revision']??-1)!==$rev['value']) throw new RuntimeException('其他窗口已保存内容，请刷新后再编辑。');
    if($action==='save'){
     $v=readData('draft',readData('published',defaults()));
     foreach(fields() as $k=>$f){
      if($f['type']==='image'){
       $upload=$_FILES[$k]??null;
       if(!$upload || $upload['error']===UPLOAD_ERR_NO_FILE)continue;
       if($upload['error']!==UPLOAD_ERR_OK || $upload['size']>5*1024*1024)throw new RuntimeException('图片上传失败，每张请控制在 5 MB 内。');
       $tmp=$upload['tmp_name'];$info=@getimagesize($tmp);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
       $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
       if(!$info || !$ext || $info[0]>10000 || $info[1]>10000)throw new RuntimeException('请上传有效的 JPG、PNG 或 WebP 图片，边长不超过 10000 像素。');
       $name=bin2hex(random_bytes(16)).'.webp';
       $dir=dirname(__DIR__).'/uploads';
       deaoeWebp($tmp,$dir.'/'.$name);
       chmod($dir.'/'.$name,0644);$v[$k]='/uploads/'.$name;
      }else{
       $value=trim((string)($_POST[$k]??''));
       if(strlen($value)>6000)throw new RuntimeException('文字过长。');
       if($f['type']==='url' && $value!=='#' && !(filter_var($value,FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value,PHP_URL_SCHEME)??''),['https','http'],true)))throw new RuntimeException('链接须以 https:// 或 http:// 开头。');
       $v[$k]=$value;
      }
     }
     writeData('draft',$v);
    }elseif($action==='publish'){
     writeData('previous',readData('published',defaults()));
     writeData('published',readData('draft',readData('published',defaults())));
    }else{
     $p=readData('previous');if(!$p)throw new RuntimeException('还没有可以恢复的历史版本。');
     writeData('previous',readData('published',defaults()));writeData('published',$p);writeData('draft',$p);
    }
    writeData('revision',['value'=>$rev['value']+1]);
   });
   $_SESSION['notice']=['save'=>'草稿已保存。可先预览，再发布。','publish'=>'已发布，官网已更新。','restore'=>'已恢复上一版，官网已更新。'][$action];
   header('Location: /admin/');exit;
  }
 }
}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'处理失败，请联系网站管理员检查服务器日志。';error_log($e->getMessage());}
if(!empty($_SESSION['auth']) && isset($_GET['preview'])){header('Content-Type: text/html; charset=utf-8');echo renderPage(readData('draft',readData('published',defaults())));exit;}
$notice=$_SESSION['notice']??$notice;unset($_SESSION['notice']);
$v=readData('draft',readData('published',defaults()));$revision=readData('revision',['value'=>0])['value'];
function hiddenFields(): void {global $revision;echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="revision" value="'.$revision.'">';}
?><!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>德澳 · 网站内容管理</title><style>
*{box-sizing:border-box}body{margin:0;background:#f5f4f1;color:#20201e;font:16px/1.6 system-ui,sans-serif}header{background:#fff;border-bottom:1px solid #ddd;padding:22px max(20px,calc((100vw - 1000px)/2));display:flex;justify-content:space-between;align-items:center}header b{font-size:24px}small{color:#666}main{max-width:1000px;margin:32px auto;padding:0 20px}h1{font-size:30px;margin-bottom:6px}p{margin-top:4px}fieldset{background:white;border:1px solid #ddd;border-radius:10px;margin:28px 0;padding:24px}legend{font-weight:700;font-size:21px;padding:0 10px}label{display:block;margin:18px 0 6px;font-weight:600}input,textarea{font:inherit;width:100%;padding:12px;border:1px solid #aaa;border-radius:5px;background:white}textarea{min-height:86px;resize:vertical}input[type=file]{border-style:dashed}button,.button{display:inline-block;background:#b51f25;color:white;border:0;padding:12px 20px;border-radius:5px;font:inherit;cursor:pointer;text-decoration:none}.secondary{background:#ecebe7;color:#222}.actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.actions form{margin:0}.thumb{display:block;width:180px;height:110px;object-fit:contain;background:#f3f3f3;margin:10px 0}.message{padding:14px;background:#e6f1e8;border-radius:6px}.error{background:#ffe7e4}.login{max-width:430px;margin:60px auto}.login button{margin-top:22px;width:100%}.save{position:sticky;bottom:0;background:#fff;padding:16px;border-top:1px solid #ddd}.hint{color:#666;font-size:14px}a{color:#8b151b}.tools{padding:24px 0 50px;border-top:1px solid #ddd}

/* admin-brand-logo-v1 */
header .admin-brand-logo{display:block;width:240px;max-width:55vw;height:auto;aspect-ratio:7952/908;flex-shrink:0;}
header{gap:16px;}
@media(max-width:600px){header .admin-brand-logo{width:190px;}header small{font-size:12px;white-space:nowrap;}}

/* site-icons-v1 */
.site-line-arrow{display:inline-block;width:1.1em;height:1.1em;vertical-align:-.15em;flex-shrink:0;margin-left:.2em;}
.service #address .address-map{display:inline-flex;align-items:center;justify-content:center;vertical-align:-.25em;width:1.65em;height:1.4em;margin-left:.15em;line-height:1;padding:0;}
.service #address .address-map svg{display:block;width:1.1em;height:1.1em;}
@media(max-width:850px){.service #address .address-map{width:1.9em;height:1.4em;}}
</style><header><svg class="admin-brand-logo" viewBox="365 1223 7952 908" role="img" aria-label="DEAOE 德澳"><image href="/deaoe-brand-logo.png" width="8661" height="3465"/></svg><small>网站内容管理</small></header><main>
<?php if($error):?><p class="message error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($notice):?><p class="message" role="status"><?=h($notice)?></p><?php endif;?>
<?php if(empty($_SESSION['auth'])):?>
<form method="post" class="login"><?php hiddenFields();?><h1>登录管理后台</h1><p>修改网站文字、图片和联系方式。</p><input type="hidden" name="action" value="login"><label for="user">账号</label><input id="user" name="user" autocomplete="username" value="admin" required><label for="password">密码</label><input id="password" type="password" name="password" autocomplete="current-password" required><button>登录</button></form>
<?php else:?>
<p><a class="button" href="/admin/?products=1">管理产品 →</a></p><p><a class="button" href="/admin/?home_carousel=1">首页轮播管理 →</a></p><h1>更新网站内容</h1><p>首页大图及三张产品卡片的轮播图片，请到“首页轮播管理”修改；原有图片会作为第一张保留。</p><p>① 修改内容　② 保存草稿　③ 预览　④ 发布</p><p class="hint">未选择新图片时保留原图。保存草稿不会改变官网。发布的是最近保存的草稿。</p>
<form id="editor" method="post" enctype="multipart/form-data"><?php hiddenFields();?><input type="hidden" name="action" value="save">
<?php $group='';foreach(fields() as $k=>$f):if(in_array($k,['hero_image','hood_image','hob_image','heater_image'],true))continue;if($group!==$f['group']):if($group):?></fieldset><?php endif;$group=$f['group'];?><fieldset><legend><?=h($group)?></legend><?php endif;?>
<label for="<?=h($k)?>"><?=h($f['label'])?></label>
<?php if($f['type']==='image'):?>
<?php if($v[$k]??''):?><img class="thumb" src="<?=h($v[$k])?>" alt="<?=h($f['label'])?>当前图片"><?php endif;?>
<input id="<?=h($k)?>" name="<?=h($k)?>" type="file" accept="image/png,image/jpeg,image/webp"><div class="hint">JPG、PNG、WebP，每张不超过 5 MB；图片较大时建议先压缩。</div>
<?php elseif($f['type']==='hero_underline'):?>
<select id="<?=h($k)?>" name="<?=h($k)?>" style="font:inherit;padding:12px;width:100%;border:1px solid #aaa;border-radius:5px;background:white">
<?php foreach(['none'=>'不显示红线','em'=>'仅第二行后半句显示红线','line'=>'第二行整行显示红线'] as $option=>$label):?>
<option value="<?=h($option)?>" <?=($v[$k]??$f['default'])===$option?'selected':''?>><?=h($label)?></option>
<?php endforeach;?></select>
<?php elseif($f['type']==='url'):?><input id="<?=h($k)?>" name="<?=h($k)?>" value="<?=h($v[$k]??$f['default']??'')?>" maxlength="6000">
<?php else:?><textarea id="<?=h($k)?>" name="<?=h($k)?>" maxlength="2000"><?=h($v[$k]??$f['default']??'')?></textarea><?php endif;?>
<?php endforeach;?></fieldset><div class="save actions"><button>保存草稿</button><a class="button secondary" href="?preview=1" target="_blank" rel="noopener">预览已保存草稿 ↗</a></div></form>
<div class="tools"><h2>确认后发布</h2><div class="actions">
<form method="post" onsubmit="return checkSaved() && confirm('将已保存的草稿发布到官网？')"><?php hiddenFields();?><button name="action" value="publish">发布到官网</button></form>
<a href="/" target="_blank" rel="noopener">查看官网 ↗</a>
<form method="post" onsubmit="return confirm('将官网和草稿恢复到上一版？')"><?php hiddenFields();?><button class="secondary" name="action" value="restore">恢复上一版</button></form>
<form method="post"><?php hiddenFields();?><button class="secondary" name="action" value="logout">退出登录</button></form>
</div></div><script>let dirty=false;document.getElementById('editor').addEventListener('input',()=>dirty=true);document.getElementById('editor').addEventListener('submit',()=>dirty=false);function checkSaved(){if(dirty){alert('请先保存草稿，再发布。');return false}return true}window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue=''}});</script>
<?php endif;?></main><script>/* site-icons-v1 */
(()=>{
const paths={'↗':'M5 19 19 5M5 5h14v14','→':'M4 12h16m-6-6 6 6-6 6','←':'M20 12H4m6-6-6 6 6 6','↘':'M5 5l14 14M5 19h14V5','↖':'M19 19 5 5M5 19V5h14','↙':'M19 5 5 19M5 5v14h14'};
const convert=root=>{
 const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode(node){return node.parentElement&&!node.parentElement.closest('script,style,textarea,input,svg,select,option,[contenteditable]')&&/[↗→←↘↖↙]/u.test(node.nodeValue)?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;}});
 const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);
 nodes.forEach(node=>{
  const fragment=document.createDocumentFragment();node.nodeValue.split(/([↗→←↘↖↙][\uFE0E\uFE0F]?)/u).forEach(text=>{
   const path=paths[text[0]];if(!path){fragment.append(document.createTextNode(text));return;}
   const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('class','site-line-arrow');svg.setAttribute('width','18');svg.setAttribute('height','18');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.8');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');const p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',path);svg.append(p);fragment.append(svg);
  });node.replaceWith(fragment);
 });
};
convert(document.body);
})();
</script></html>
