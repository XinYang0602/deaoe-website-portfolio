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
        INSERT INTO inv_balances VALUES('TEST-P','TEST-W',5,1),('TEST-P','LEGACY',3,3);
        INSERT INTO inv_holds VALUES('TEST-P','TEST-W','quarantine',2);
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
                db.execute("UPDATE inv_products SET purchase_cents=750")
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                for width,height in [(1440,900),(1024,600),(720,700),(390,844)]:
                    page=browser.new_page(viewport={'width':width,'height':height})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    errors=[]
                    page.on('pageerror',lambda error:errors.append(str(error)))
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    expect(page.locator('#nav')).to_contain_text('服务管理')
                    expect(page.locator('#nav')).to_contain_text('客户管理')
                    assert '数据存储于服务器' not in page.locator('body').inner_text()
                    assert '服务器数据' not in page.locator('body').inner_text()
                    page.goto(base+'/service/#inventory/balances')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    if width==390:
                        expect(frame.locator('.stock-workspace')).to_have_count(0)
                        expect(frame.locator('#main table')).to_be_visible()
                        print('PASS original mobile stock table')
                    else:
                        table=frame.locator('.stock-product-table')
                        expect(table).to_be_visible()
                        expect(table.locator('[data-stock-row]')).to_have_count(2)
                        table.locator('[data-stock-row="TEST-P"]').click()
                        expect(frame.locator('.stock-selected-name')).to_contain_text('测试油烟机')
                        expect(frame.locator('.stock-breakdown')).to_contain_text('第二测试仓')
                        expect(frame.locator('.stock-breakdown')).to_contain_text('待补录 3')
                        expect(frame.locator('.stock-breakdown')).to_contain_text('待核对')
                        expect(table.locator('[data-stock-row="TEST-P"] td').nth(3)).to_have_text('8')
                        expect(table.locator('[data-stock-row="TEST-P"] td').nth(4)).to_have_text('2')
                        filters=frame.locator('.stock-filters')
                        filters.locator('[name=nonzero]').check()
                        filters.get_by_role('button',name='筛选',exact=True).click()
                        expect(table.locator('[data-stock-row]')).to_have_count(1)
                        filters.locator('[name=warehouse]').select_option('TEST-W2')
                        filters.get_by_role('button',name='筛选',exact=True).click()
                        expect(table.locator('[data-stock-row]')).to_have_count(0)
                        expect(frame.locator('.stock-breakdown')).to_contain_text('没有可显示')
                        filters.get_by_role('button',name='重置',exact=True).click()
                        expect(table.locator('[data-stock-row]')).to_have_count(2)
                        filters.locator('[name=q]').fill('TEST-PART')
                        filters.get_by_role('button',name='筛选',exact=True).click()
                        expect(table.locator('[data-stock-row]')).to_have_count(1)
                        table.locator('[data-stock-row]').focus()
                        page.keyboard.press('Enter')
                        expect(frame.locator('.stock-selected-name')).to_contain_text('测试配件')
                        filters.get_by_role('button',name='重置',exact=True).click()
                        assert frame.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth+1')
                        with page.expect_download() as download:
                            frame.get_by_role('button',name='导出 Excel',exact=True).click()
                        assert download.value.suggested_filename.endswith('.xlsx')
                        page.screenshot(path=str(REPORT/f'stock-{width}.png'),full_page=True)
                        page.set_viewport_size({'width':390,'height':844})
                        expect(frame.locator('.stock-workspace')).to_have_count(0)
                        page.set_viewport_size({'width':width,'height':height})
                        expect(frame.locator('.stock-workspace')).to_be_visible()
                        print('PASS product totals, warehouse detail, filtering, keyboard, export and resize',width,height)
                    assert not errors,errors
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
