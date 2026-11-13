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
    with sqlite3.connect(db_path) as db:
        for n in range(25):
            db.execute("INSERT INTO inv_products VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",(f'DIS-{n}',f'DIS-{n}','停用测试产品','配件','示例品牌','','件',0,None,1000,0,'',1))
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
                # Simulate upgrading an existing installation, not a fresh database.
                db.execute("DROP TABLE inv_product_categories")
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                for width,height in [(1440,900),(1024,600),(720,700),(390,844)]:
                    page=browser.new_page(viewport={'width':width,'height':height})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    errors=[]
                    page.on('pageerror',lambda error:errors.append(str(error)))
                    page.on('dialog',lambda dialog:dialog.accept())
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    expect(page.locator('#nav')).to_contain_text('服务管理')
                    expect(page.locator('#nav')).to_contain_text('客户管理')
                    assert '数据存储于服务器' not in page.locator('body').inner_text()
                    assert '服务器数据' not in page.locator('body').inner_text()
                    page.goto(base+'/service/#inventory/products')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    if width==390:
                        expect(frame.locator('.product-catalog')).to_have_count(0)
                        expect(frame.locator('#main table')).to_be_visible()
                        print('PASS original mobile products')
                    else:
                        table=frame.locator('.catalog-scroll table')
                        expect(table).to_be_visible()
                        expect(table.locator('[data-catalog-row]')).to_have_count(2)
                        frame.locator('.catalog-disabled input').check()
                        expect(table.locator('[data-catalog-row]')).to_have_count(20)
                        frame.locator('[data-action=product-next]').click()
                        expect(table.locator('[data-catalog-row]')).to_have_count(7)
                        frame.locator('#product-page-size').select_option('50')
                        expect(table.locator('[data-catalog-row]')).to_have_count(27)
                        frame.locator('.catalog-disabled input').uncheck()
                        expect(table.locator('[data-catalog-row]')).to_have_count(2)
                        frame.locator('#product-page-size').select_option('20')
                        frame.locator('[data-catalog-category="配件"]').click()
                        expect(table.locator('[data-catalog-row]')).to_have_count(1)
                        expect(frame.locator('.catalog-selected-name')).to_contain_text('测试配件')
                        frame.locator('.catalog-all').check()
                        expect(frame.locator('[data-product]:checked')).to_have_count(1)
                        frame.locator('[data-catalog-reset]').click()
                        expect(frame.locator('[data-product]:checked')).to_have_count(0)
                        frame.locator('#search').fill('TEST-PART')
                        expect(table.locator('[data-catalog-row]')).to_have_count(1)
                        frame.locator('[data-catalog-edit]').click()
                        expect(frame.locator('#master-form')).to_be_visible()
                        expect(frame.locator('#master-form [name=code]')).to_have_value('TEST-PART')
                        frame.locator('[name=category_path]').fill('厨电 / 配件 / 点火 / 示例品牌')
                        expect(frame.locator('.category-path-preview')).to_have_text('厨电 › 配件 › 点火 › 示例品牌')
                        frame.locator('#master-form button[type=submit]').click()
                        expect(frame.locator('#master-form')).to_be_hidden()
                        expect(frame.locator('.catalog-category-path')).to_contain_text('厨电 › 配件 › 点火 › 示例品牌')
                        frame.locator('[data-catalog-reset]').click()
                        frame.locator('[data-catalog-category="厨电"]').click()
                        expect(table.locator('[data-catalog-row]')).to_have_count(1)
                        expect(frame.locator('.catalog-path')).to_contain_text('厨电')
                        frame.locator('[data-category-toggle="厨电"]').click()
                        expect(frame.locator('[data-catalog-category="厨电 / 配件"]')).to_be_hidden()
                        frame.locator('[data-category-toggle="厨电"]').click()
                        expect(frame.locator('[data-catalog-category="厨电 / 配件"]')).to_be_visible()
                        page.screenshot(path=str(REPORT/f'category-tree-{width}.png'),full_page=True)
                        frame.locator('[data-catalog-edit]').click()
                        expect(frame.locator('[name=category_path]')).to_have_value('厨电 / 配件 / 点火 / 示例品牌')
                        frame.locator('[name=category_path]').fill('')
                        frame.locator('#master-form button[type=submit]').click()
                        frame.locator('[data-catalog-reset]').click()
                        frame.locator('#search').fill('TEST-PART')
                        frame.locator('[data-catalog-edit]').click()
                        frame.locator('#modal [data-action=close]').click()
                        frame.locator('[data-catalog-images]').click()
                        expect(frame.locator('#image-form')).to_be_visible()
                        expect(frame.locator('#image-form')).to_have_attribute('data-id','TEST-PART')
                        frame.locator('#modal [data-action=close]').click()
                        frame.locator('[data-catalog-reset]').click()
                        table.locator('[data-catalog-row="TEST-P"]').click()
                        frame.locator('[data-catalog-delete]').click()
                        expect(frame.locator('#notice')).to_contain_text('不能删除')
                        expect(table.locator('[data-catalog-row="TEST-P"]')).to_be_visible()
                        frame.locator('[data-catalog-row="TEST-PART"]').focus()
                        page.keyboard.press('Enter')
                        expect(frame.locator('.catalog-selected-name')).to_contain_text('测试配件')
                        frame.locator('.catalog-all').check()
                        expect(frame.locator('[data-product]:checked')).to_have_count(2)
                        frame.locator('.catalog-more summary').click()
                        with page.expect_download() as download:
                            frame.get_by_role('button',name='导出本页 Excel',exact=True).click()
                        assert download.value.suggested_filename.endswith('.xlsx')
                        frame.locator('.catalog-more summary').click()
                        assert frame.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth+1')
                        page.screenshot(path=str(REPORT/f'catalog-{width}.png'),full_page=True)
                        page.set_viewport_size({'width':390,'height':844})
                        expect(frame.locator('.product-catalog')).to_have_count(0)
                        page.set_viewport_size({'width':width,'height':height})
                        expect(frame.locator('.product-catalog')).to_be_visible()
                        print('PASS catalog category/search/select/edit/images/delete guard/export/resize',width,height)
                    assert not errors,errors
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
