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
        INSERT INTO inv_parties VALUES('TEST-C','customer','测试客户','测试联系人','000000','测试地址',1,1);
        INSERT INTO inv_parties VALUES('TEST-S','supplier','测试供应商','','','','1',1);
        INSERT INTO inv_products VALUES('TEST-PART','TEST-PART','测试配件','配件','德澳','','件',0,NULL,1000,1,'',1);
        INSERT INTO inv_products VALUES('TEST-P','TEST-P','测试油烟机','整机','德澳','TEST','台',1,NULL,1250,1,'',1);
        INSERT INTO inv_balances VALUES('TEST-P','TEST-W',2,0);
        INSERT INTO inv_sn VALUES('TEST-SN-desktop',NULL,'TEST-P','TEST-W','available',NULL,NULL,'now'),
        ('TEST-SN-mobile',NULL,'TEST-P','TEST-W','available',NULL,NULL,'now');
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
                browser = pw.chromium.launch(channel='chrome', headless=True)
                context = browser.new_context(viewport={'width':390,'height':844})
                def route_request(route):
                    if route.request.url.endswith('/api/inventory'):time.sleep(.3)
                    route.continue_() if route.request.url.startswith(base + '/') else route.abort()
                context.route('**/*', route_request)
                page = context.new_page()
                errors=[]
                page.on('pageerror',lambda error:errors.append(str(error)))
                reads=[]
                page.on('request',lambda request:reads.append(request.url) if request.url.endswith('/api/inventory') else None)
                page.goto(base+'/service/')
                page.locator('[name=password]').fill('local-test-password')
                page.get_by_role('button',name='登录管理平台').click()
                frame=page.frame_locator('#inventory-workspace-frame')
                expect(frame.get_by_role('heading',name='库存与期初归属',exact=True,include_hidden=True)).to_be_attached(timeout=15000)
                assert page.locator('#inventory-workspace-frame').is_hidden()
                assert page.url.endswith('/service/')
                initial=len(reads)
                assert initial==1,reads
                timings=[]
                for route,title in [('balances','库存与期初归属'),('finance_ar','应收款'),('balances','库存与期初归属')]:
                    started=time.monotonic()
                    page.evaluate('(route)=>location.hash="inventory/"+route',route)
                    expect(frame.get_by_role('heading',name=title,exact=True)).to_be_visible()
                    timings.append(round((time.monotonic()-started)*1000))
                assert len(reads)==initial,reads
                assert page.locator('#inventory-workspace-frame').count()==1
                # Advance snapshot age without a wall-clock wait; entering an old route refreshes once.
                child=page.locator('#inventory-workspace-frame').element_handle().content_frame()
                child.evaluate('snapshotAt=Date.now()-16000')
                page.evaluate('location.hash="inventory/finance_ar"')
                page.wait_for_function('document.querySelector("#inventory-workspace-frame").contentWindow.eval("snapshotFlight===null && Date.now()-snapshotAt<15000")')
                assert len(reads)==initial+1,reads
                assert not errors,errors
                print('PASS hidden preload, single frame, no GET for 3 fresh navigations, stale revalidation; navigation ms:',timings)
                context.close();browser.close()
        finally:
            server.terminate();server.wait(timeout=5)
