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
                for width in [1440,1024,390]:
                    page=browser.new_page(viewport={'width':width,'height':900})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    errors=[]
                    page.on('pageerror',lambda error: errors.append(str(error)))
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    for module in ['sales','purchasing','stock','accounts','customers','orders','settings']:
                        page.evaluate('(m)=>location.hash="workflow-"+m',module)
                        page.wait_for_function('(m)=>location.hash==="#workflow-"+m',arg=module)
                        expect(page.locator('.workflow-page')).to_be_visible()
                        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width,module,'overflow')
                        assert not errors, errors
                        if width > 700:
                            box=page.locator('.workflow-page').bounding_box()
                            assert box['width'] <= 1041, (width,module,'oversized workflow',box)
                            nodes=page.locator('.workflow-node').all()
                            assert all(n.bounding_box()['height'] <= 90 for n in nodes), (width,module,'oversized nodes')
                        if module=='sales':page.screenshot(path=str(REPORT/f'flow-{width}.png'),full_page=True)
                    page.evaluate('location.hash="workflow-orders"')
                    page.locator('.workflow-node[data-filter="处理中"]').first.click()
                    expect(page.locator('#filter')).to_have_value('处理中')
                    if width>700:
                        page.locator('#nav [data-section=all]').click()
                        menu=page.locator('#workflow-menu')
                        expect(menu).to_be_visible()
                        star=menu.locator('[data-workflow-favorite=sales]')
                        old=star.get_attribute('aria-pressed')
                        star.click()
                        expect(menu.locator('[data-workflow-favorite=sales]')).to_have_attribute('aria-pressed','false' if old=='true' else 'true')
                        expect(page.locator('#nav [data-section=sales]')).to_be_hidden()
                        link=menu.locator('.workflow-app:has([data-workflow-favorite=sales]) a').bounding_box()
                        starbox=menu.locator('[data-workflow-favorite=sales]').bounding_box()
                        assert 0 <= starbox['x']-link['x']-link['width'] <= 8
                        # Empty favorites must retain a usable All menu and dashboard.
                        for key in ['purchasing','stock','orders']:
                            menu.locator(f'[data-workflow-favorite={key}]').click()
                        expect(page.locator('#nav [data-section=all]')).to_be_visible()
                        expect(page.locator('#nav [data-section=dashboard]')).to_be_visible()
                        assert page.locator('#nav .workflow-not-favorite').count()==7
                        page.reload();page.wait_for_load_state('networkidle')
                        expect(page.locator('#nav [data-section=sales]')).to_be_hidden()
                        page.locator('#nav [data-section=all]').click()
                        menu.locator('[data-workflow-favorite=sales]').click()
                        expect(page.locator('#nav [data-section=sales]')).to_be_visible()
                        expect(menu).to_be_visible()
                        page.screenshot(path=str(REPORT/f'menu-{width}.png'),full_page=True)
                        page.keyboard.press('Escape')
                        expect(menu).to_be_hidden()
                        page.locator('#nav [data-section=sales]').click()
                        expect(menu).to_be_visible()
                        menu.get_by_role('link',name='销售导航',exact=True).click()
                        expect(page.locator('.workflow-page')).to_be_visible()
                        expect(menu).to_be_hidden()
                        page.locator('.workflow-node[href="#inventory/sale/new"]').click()
                        frame=page.frame_locator('#inventory-workspace-frame')
                        expect(frame.locator('#doc-form')).to_be_visible()
                    else:
                        page.evaluate('location.hash="workflow-sales"')
                        page.locator('.workflow-node[href="#inventory/sale"]').click()
                        expect(page.frame_locator('#inventory-workspace-frame').locator('#mobile-sales')).to_be_visible()
                    assert not errors,errors
                    print('PASS workflows, status filtering and navigation',width)
                    page.close()
                browser.close()
                print('Report:',REPORT)
        finally:
            server.terminate();server.wait(timeout=5)
