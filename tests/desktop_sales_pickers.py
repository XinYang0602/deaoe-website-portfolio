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
                for width,height in [(1440,900),(1024,720),(1024,600),(390,844)]:
                    page=browser.new_page(viewport={'width':width,'height':height})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    errors=[]
                    page.on('pageerror',lambda error:errors.append(str(error)))
                    page.on('dialog',lambda dialog:dialog.accept())
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    page.goto(base+'/service/#inventory/sale/new')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    def check_bounds(dialog):
                        assert dialog.evaluate('(el)=>{const r=el.getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1&&el.scrollWidth<=el.clientWidth+1}'), 'dialog viewport overflow'
                        expect(dialog.locator('footer button').last).to_be_in_viewport()
                    form=frame.locator('#doc-form')
                    expect(form).to_have_attribute('data-number-ready','true')
                    assert form.locator('[name=reserved_number]').input_value().startswith('XS-')
                    expect(form).to_be_visible()
                    form.locator('.sales-customer-trigger').click()
                    customers=frame.locator('.sales-customer-picker')
                    customers.locator('.customer-search').fill('测试客户')
                    if width>700:
                        check_bounds(customers)
                        customers.locator('[data-customer="TEST-C"]').click()
                        expect(form.locator('[name=party_id]')).to_have_value('')
                        page.screenshot(path=str(REPORT/f'customer-{width}.png'),full_page=True)
                        customers.locator('.customer-confirm').click()
                    else:
                        assert not customers.evaluate("el=>el.classList.contains('desktop-selector')")
                        customers.locator('[data-customer="TEST-C"]').click()
                    expect(form.locator('[name=party_id]')).to_have_value('TEST-C')
                    form.locator('.product-choose').click()
                    products=frame.locator('.desktop-product-picker' if width>700 else '.sales-product-picker')
                    products.get_by_role('searchbox',name='搜索产品').fill('TEST-P')
                    products.locator('[data-filter=category]').select_option('整机')
                    if width>700:
                        check_bounds(products)
                        expect(products.locator('[data-focus-product]')).to_have_count(1)
                        products.locator('[data-focus-product="TEST-P"]').click()
                        expect(products.locator('.desktop-stock-results')).to_contain_text('测试仓')
                        products.locator('[data-add-product="TEST-P"]').click()
                    else:
                        products.locator('[data-product-id="TEST-P"]').click()
                    item=frame.locator('.sales-item-sheet')
                    if width>700:
                        item.locator('.warehouse-choose').click()
                        stores=frame.locator('.warehouse-selector')
                        check_bounds(stores)
                        page.keyboard.press('Escape')
                        expect(stores).to_have_count(0)
                        expect(item).to_be_visible()
                        item.locator('.warehouse-choose').click()
                        stores.get_by_role('searchbox',name='搜索仓库').fill('测试仓')
                        stores.locator('[data-warehouse="TEST-W"]').click()
                        page.screenshot(path=str(REPORT/f'warehouse-{width}.png'),full_page=True)
                        stores.locator('.warehouse-confirm').click()
                    else:
                        item.locator('[name=store]').select_option('TEST-W')
                    item.locator('[name=unit]').fill('25')
                    item.locator('[name=discount]').fill('0.5')
                    expect(item.locator('[name=amount]')).to_have_value('12.50')
                    item.get_by_role('button',name='确认产品').click()
                    if width>700:
                        products.locator('[data-add-product="TEST-P"]').click()
                        item.locator('.warehouse-choose').click()
                        stores.locator('[data-warehouse="TEST-W2"]').click()
                        stores.locator('.warehouse-confirm').click()
                    else:
                        products.locator('[data-product-id="TEST-P"]').click()
                        item.locator('[name=store]').select_option('TEST-W2')
                    item.get_by_role('button',name='确认产品').click()
                    page.screenshot(path=str(REPORT/f'product-{width}.png'),full_page=True)
                    products.locator('.picker-done').click()
                    expect(form.locator('.line')).to_have_count(2)
                    expect(form.locator('.line').last).to_have_attribute('data-warehouse','TEST-W2')
                    expect(form.locator('.line').first.locator('[name=price]')).to_have_value('12.50')
                    form.locator('.product-choose').first.click()
                    item.locator('[name=unit]').fill('99')
                    item.get_by_role('button',name='取消',exact=True).click()
                    expect(form.locator('.line').first.locator('[name=price]')).to_have_value('12.50')
                    if width>700:
                        form.locator('.sales-customer-trigger').click()
                        customers.locator('.customer-search').fill('no-match-zzz')
                        expect(customers.locator('.customer-confirm')).to_be_disabled()
                        customers.locator('.picker-back').click()
                        expect(form.locator('[name=party_id]')).to_have_value('TEST-C')
                    assert not errors,errors
                    print('PASS selectors, multiwarehouse, discount and cancel',width,height)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
