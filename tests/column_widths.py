"""Isolated account preference API checks; no production access."""
import pathlib, tempfile, subprocess, json
source=pathlib.Path(__file__).resolve().parents[1]/'src/service/column-widths.php'
with tempfile.TemporaryDirectory() as d:
    def call(user,method='GET',body='',token='test'):
        code="<?php define('DATA_DIR',"+json.dumps(d)+"); $_SESSION=['user'=>"+json.dumps(user)+",'csrf'=>'test']; $_SERVER['REQUEST_METHOD']="+json.dumps(method)+"; $_SERVER['HTTP_X_CSRF_TOKEN']="+json.dumps(token)+";"
        text=source.read_text().replace('<?php','',1).replace('declare(strict_types=1);','').replace("__DIR__.'/storage.php'",json.dumps(str(source.parent/'storage.php'),ensure_ascii=False)).replace("file_get_contents('php://input',false,null,0,16001)",json.dumps(body))
        f=pathlib.Path(d)/'run.php';f.write_text(code+text+'\nhandleColumnWidths();')
        return subprocess.check_output(['php',str(f)],text=True)
    payload=json.dumps({'key':'products','widths':[100,220]})
    assert json.loads(call('account-a','POST',payload))['ok']
    assert json.loads(call('account-a'))['views']['products']==[100,220]
    assert not json.loads(call('account-b'))['views']
    assert call('account-b','POST',payload,'wrong')==''
    assert 'error' in json.loads(call('account-a','POST',json.dumps({'key':'products','widths':[-1]})))
    assert json.loads(call('account-a'))['views']['products']==[100,220]
    visibility=json.dumps({'key':'visibility:products','widths':[False,True]})
    assert json.loads(call('account-a','POST',visibility))['ok']
    assert json.loads(call('account-a'))['views']['visibility:products']==[False,True]
    assert not json.loads(call('account-b'))['views']
    for values in [[True,True],[0,1],[]]:
        assert 'error' in json.loads(call('account-a','POST',json.dumps({'key':'visibility:products','widths':values})))
    print('PASS account isolation, persistence, CSRF and width validation')
