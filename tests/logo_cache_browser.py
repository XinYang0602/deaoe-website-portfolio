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
        INSERT INTO inv_warehouses VALUES('TEST-W','测试仓',1,1),('TEST-W2','第二测试仓',1,1);
        INSERT INTO inv_parties VALUES('TEST-C2','customer','第二测试客户','','000001','测试地址二',1,1);
        INSERT INTO inv_parties VALUES('TEST-C','customer','测试客户','测试联系人','000000','测试地址',1,1);
        INSERT INTO inv_parties VALUES('TEST-S','supplier','测试供应商','','','','1',1);
        INSERT INTO inv_products VALUES('TEST-PART','TEST-PART','测试配件','配件','德澳','','件',0,NULL,1000,1,'',1);
        INSERT INTO inv_products VALUES('TEST-P','TEST-P','测试油烟机','整机','德澳','TEST','台',1,NULL,1250,1,'',1);
        INSERT INTO inv_balances VALUES('TEST-P','TEST-W',5,1),('TEST-P','LEGACY',3,3);
        INSERT INTO inv_holds VALUES('TEST-P','TEST-W','quarantine',2);
        INSERT INTO inv_sn VALUES('TEST-SN-desktop',NULL,'TEST-P','TEST-W','available',NULL,NULL,'now'),
        ('TEST-SN-mobile',NULL,'TEST-P','TEST-W','available',NULL,NULL,'now');
        """)
    with sqlite3.connect(db_path) as db:
        for n in range(25):
            db.execute("INSERT INTO inv_products VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",(f'DIS-{n}',f'DIS-{n}','停用测试产品','配件','示例品牌','','件',0,None,1000,0,'',1))
    (root / 'router.php').write_text("<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/favicon.ico'){http_response_code(204);exit;} if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/service/assets/deaoe-logo.png')file_put_contents(__DIR__.'/logo-requests.log',\"request\\n\",FILE_APPEND); require __DIR__.'/site/index.php';")
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
            with sqlite3.connect(db_path) as db:
                db.execute("UPDATE inv_products SET purchase_cents=750")
                # Simulate upgrading an existing installation, not a fresh database.
                db.execute("DROP TABLE inv_product_categories")
            logo='/service/assets/deaoe-logo.png?v=cc34c68decaf'
            response=urllib.request.urlopen(base+logo)
            assert response.read()==(SOURCE/'assets/deaoe-logo.png').read_bytes()
            assert response.headers['Cache-Control']=='public, max-age=31536000, immutable'
            assert response.headers.get('Set-Cookie') is None
            etag=response.headers['ETag']
            try:
                urllib.request.urlopen(urllib.request.Request(base+logo,headers={'If-None-Match':etag}))
                raise AssertionError('expected conditional 304')
            except urllib.error.HTTPError as error:
                assert error.code==304
            plain=urllib.request.urlopen(base+'/service/assets/deaoe-logo.png')
            assert plain.headers['Cache-Control']=='public, max-age=3600'
            assert 'no-store' in urllib.request.urlopen(base+'/service/').headers['Cache-Control']
            counts=root/'logo-requests.log'
            counts.write_text('')
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                context=browser.new_context(viewport={'width':1440,'height':900})
                page=context.new_page()
                errors=[]
                page.on('pageerror',lambda error:errors.append(str(error)))
                page.goto(base+'/service/')
                page.wait_for_load_state('networkidle')
                expect(page.locator('svg.brand image')).to_have_css('opacity','1')
                first=counts.read_text().count('request')
                assert first==1,first
                page.goto(base+'/service/login')
                page.wait_for_load_state('networkidle')
                expect(page.locator('svg.brand image')).to_have_css('opacity','1')
                assert counts.read_text().count('request')==first,'Second entry downloaded logo again'
                page.locator('[name=password]').fill('local-test-password')
                page.get_by_role('button',name='登录管理平台').click()
                page.wait_for_load_state('networkidle')
                expect(page.locator('.brand-logo image').first).to_have_css('opacity','1')
                assert counts.read_text().count('request')==first,'Workspace did not reuse cached logo'
                page.screenshot(path=str(REPORT/'cached-workspace-logo.png'))
                context.set_offline(True)
                assert page.evaluate("url=>new Promise(resolve=>{const image=new Image();image.onload=()=>resolve(image.naturalWidth===8661);image.onerror=()=>resolve(false);image.src=url;})",base+logo)
                assert not errors,errors
                context.set_offline(False)
                browser.close()
                print('PASS public versioned logo, original bytes, 304, no session cookie, private-page no-store, cache reuse across entry/login and offline decode')
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
