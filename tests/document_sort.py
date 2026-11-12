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
            with sqlite3.connect(db_path) as db:
                for i in range(40):
                    db.execute("INSERT INTO inv_parties VALUES(?,?,?,?,?,?,?,?)",(f'LIST-{i}','customer',f'测试客户 {i} 长名称测试','测试联系人','000000','示例地址',1,1))
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                for width in [1440,390]:
                    page=browser.new_page(viewport={'width':width,'height':900})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    for kind in ['sale','purchase']:
                        page.evaluate('(kind)=>location.hash="inventory/"+kind',kind)
                        frame=page.frame_locator('#inventory-workspace-frame')
                        if width<700 and kind=='sale':
                            expect(frame.locator('#sales-filter-panel')).not_to_have_attribute('open','')
                            frame.locator('#sales-filter-panel>summary').click()
                        expect(frame.locator('[data-document-sort="asc"]:visible')).to_be_visible()
                        child=page.locator('#inventory-workspace-frame').element_handle().content_frame()
                        child.evaluate("(kind)=>{data.docs=['2026-11-02','2026-11-01','2026-11-03','2026-11-02'].map((date,i)=>({id:'SORT-'+i,kind,date,status:'draft',party_id:'TEST-C',warehouse_id:'TEST-W',note:''}));render()}",kind)
                        selector='#mobile-sales .sales-card .mono' if width<700 and kind=='sale' else '#main>.panel table tbody .mono'
                        assert frame.locator(selector).all_text_contents()==['SORT-2','SORT-3','SORT-0','SORT-1']
                        frame.locator('[data-document-sort="asc"]:visible').click()
                        assert frame.locator(selector).all_text_contents()==['SORT-1','SORT-0','SORT-3','SORT-2']
                        expect(frame.locator('[data-document-sort="asc"]:visible')).to_have_attribute('aria-pressed','true')
                        frame.locator('[data-document-sort="desc"]:visible').click()
                        assert frame.locator(selector).all_text_contents()==['SORT-2','SORT-3','SORT-0','SORT-1']
                        print('PASS both date sort directions and same-day tie break',width,kind)
                    page.close()
                browser.close()
        finally:
            server.terminate();server.wait(timeout=5)
