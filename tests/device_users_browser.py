"""Local-only browser smoke checks; never connects to production."""
import json
import pathlib
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

REPO = pathlib.Path(__file__).resolve().parents[1]
SOURCE = REPO / 'src/service'
REPORT = pathlib.Path(tempfile.mkdtemp(prefix='deaoe-browser-report-'))

with tempfile.TemporaryDirectory(prefix='deaoe-browser-data-') as tmp:
    root = pathlib.Path(tmp)
    shutil.copytree(SOURCE, root / 'site')
    for folder in ['auth', 'data']:
        (root / folder).mkdir()
    for file in (root / 'site').glob('*.php'):
        text = file.read_text().replace('/var/lib/showcase-auth', str(root / 'auth'))
        text = text.replace('/var/lib/showcase-data', str(root / 'data'))
        file.write_text(text.replace("'secure'=>true", "'secure'=>false"))
    hashed = subprocess.check_output(['php', '-r', 'echo password_hash("local-test-password", PASSWORD_DEFAULT);'], text=True)
    (root / 'auth/account.json').write_text(json.dumps({'hash': hashed}))
    subprocess.run(['php', '-r', "require " + repr(str(root / 'site/mini.php')) + ";require " + repr(str(root / 'site/inventory.php')) + ";$d=database();miniSchema($d);invMigrate($d);p2Migrate($d);"], check=True)
    db_path = root / 'data/business.sqlite'
    with sqlite3.connect(db_path) as db:
        db.executescript("""
        INSERT INTO inv_warehouses VALUES('W','合成仓',1,1);
        INSERT INTO inv_parties VALUES('C','customer','合成客户','','','',1,1);
        INSERT INTO inv_products VALUES('P','SKU1','合成商品','','','','件',0,NULL,1000,1,'',1);
        INSERT INTO inv_salespeople VALUES('SP','合成业务员','','',1,1);
        """)
    (root / 'router.php').write_text("<?php require __DIR__.'/site/index.php';")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (REPORT / 'server.log').open('w') as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(root / 'router.php')], stdout=log, stderr=log)
        try:
            for attempt in range(50):
                try:
                    urllib.request.urlopen(base + '/service/', timeout=1)
                    break
                except OSError:
                    time.sleep(.1)
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                for width in [1440,390]:
                    page=browser.new_page(viewport={'width':width,'height':900})
                    page.route('**/*',lambda r:r.continue_() if r.request.url.startswith(base+'/') else r.abort())
                    errors=[]
                    page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    page.goto(base+'/service/#orders')
                    page.locator('[data-action=new-order]:visible').first.click()
                    form=page.locator('form[data-form=new-order]')
                    for key,value in {'customer':'示例使用人','phone':'13000000000','product':'未入库型号'+str(width),'address':'测试地址','note':'测试登记','sn':'TEST-'+str(width)}.items():
                        form.locator('[name='+key+']').fill(value)
                    png=subprocess.check_output(['php','-r','$im=imagecreatetruecolor(64,64);imagepng($im);'])
                    form.locator('[data-machine-photo]').set_input_files({'name':'qr.png','mimeType':'image/png','buffer':png})
                    form.locator('[type=submit]').click()
                    expect(form).to_be_hidden()
                    page.goto(base+'/service/#device-users')
                    page.get_by_role('button',name='查看档案').first.click()
                    expect(page.locator('#modal')).to_contain_text('未入库型号'+str(width))
                    url=page.locator('#modal a[download]').first.get_attribute('href')
                    r=page.request.get(base+url)
                    assert r.ok and r.body()[:4]==b'RIFF' and r.body()[8:12]==b'WEBP'
                    assert r.headers['content-type']=='image/webp' and 'attachment' in r.headers['content-disposition']
                    page.locator('#modal [aria-label=关闭]').click()
                    page.get_by_role('button',name='登记机器').click()
                    f=page.locator('#device-registration')
                    for key,value in {'customer':'示例使用人','phone':'13000000000','product':'仅二维码型号'}.items():
                        f.locator('[name='+key+']').fill(value)
                    f.locator('[name=image]').set_input_files({'name':'record.png','mimeType':'image/png','buffer':png})
                    f.locator('[type=submit]').click()
                    expect(f).to_be_hidden()
                    expect(page.locator('[data-profile-index]')).to_have_count(1)
                    # Both inline and attachment responses return identical stored WebP bytes.
                    snap=page.request.get(base+'/service/api/inventory').json()
                    r=page.request.post(base+'/service/api/images',headers={'X-CSRF-Token':snap['csrf']},multipart={'product_id':'P','image':{'name':'original.png','mimeType':'image/png','buffer':png}})
                    assert r.ok,r.text()
                    url=base+'/service/api/images?id='+r.json()['id']
                    a=page.request.get(url);b=page.request.get(url+'&download=1')
                    assert a.body()==b.body() and b.body()[8:12]==b'WEBP' and 'original.webp' in b.headers['content-disposition']
                    assert not errors,errors
                    print('PASS service order, same owner, unlisted machine, QR and WebP download',width)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
