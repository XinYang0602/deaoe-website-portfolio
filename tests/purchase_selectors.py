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
                db.execute("UPDATE inv_products SET purchase_cents=750")
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                for width,height in [(1440,900),(1024,600),(390,844)]:
                    page=browser.new_page(viewport={'width':width,'height':height})
                    page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                    errors=[]
                    page.on('pageerror',lambda error:errors.append(str(error)))
                    page.on('dialog',lambda dialog:dialog.accept())
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    expect(page.locator('.shell>footer')).to_contain_text('DEAOE 德澳 · 管理平台')
                    page.goto(base+'/service/#inventory/purchase/new')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    form=frame.locator('#doc-form')
                    expect(form).to_be_visible()
                    expect(form).to_have_attribute('data-number-ready','true')
                    purchase_number=form.locator('[name=reserved_number]').input_value()
                    assert purchase_number.startswith('CG-')
                    if width==390:
                        expect(form.locator('[name=party_id]')).to_be_visible()
                        expect(form.locator('[name=warehouse_id]')).to_be_visible()
                        expect(form.locator('[name=product]')).to_be_visible()
                        expect(form.locator('.proc-select-products')).to_have_count(0)
                        print('PASS mobile procurement unchanged')
                        page.close()
                        continue
                    def bounds(dialog):
                        assert dialog.evaluate('(el)=>{const r=el.getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1&&el.scrollWidth<=el.clientWidth+1}'), 'dialog overflow'
                    form.locator('.proc-supplier').click()
                    suppliers=frame.get_by_role('dialog',name='选择供应商',exact=True)
                    suppliers.get_by_role('searchbox').fill('测试供应商')
                    suppliers.locator('[data-proc-id="TEST-S"]').click()
                    expect(form.locator('[name=party_id]')).to_have_value('')
                    bounds(suppliers)
                    page.screenshot(path=str(REPORT/f'supplier-{width}.png'),full_page=True)
                    suppliers.locator('.proc-confirm').click()
                    expect(form.locator('[name=party_id]')).to_have_value('TEST-S')
                    form.locator('.proc-warehouse').click()
                    stores=frame.get_by_role('dialog',name='选择仓库',exact=True)
                    stores.get_by_role('searchbox').fill('第二')
                    stores.locator('[data-proc-id="TEST-W2"]').click()
                    stores.locator('.proc-confirm').click()
                    form.locator('.proc-select-products').click()
                    products=frame.get_by_role('dialog',name='选择采购产品',exact=True)
                    products.get_by_role('searchbox').fill('TEST-P')
                    products.locator('[data-proc-product="TEST-P"]').click()
                    item=frame.get_by_role('dialog',name='编辑采购产品',exact=True)
                    item.locator('[name=qty]').fill('2')
                    expect(item.locator('[name=price]')).to_have_value('7.50')
                    item.get_by_role('button',name='加入已选').click()
                    products.locator('[data-proc-product="TEST-PART"]').click()
                    item.get_by_role('button',name='加入已选').click()
                    bounds(products)
                    page.screenshot(path=str(REPORT/f'purchase-products-{width}.png'),full_page=True)
                    products.locator('.proc-apply').click()
                    expect(form.locator('.line')).to_have_count(2)
                    form.locator('[name=price]').first.fill('')
                    form.locator('.proc-select-products').click()
                    products.locator('.proc-apply').click()
                    expect(products).to_be_visible()
                    expect(form.locator('[name=price]').first).to_have_value('')
                    products.locator('[data-proc-edit]').first.click()
                    item.locator('[name=price]').fill('7.50')
                    item.get_by_role('button',name='加入已选').click()
                    products.locator('.proc-apply').click()
                    form.get_by_role('button',name='保存草稿').click()
                    frame.locator('[data-action=submit]').click()
                    frame.locator('[data-action=approve]').click()
                    expect(frame.locator('#doc-print h2')).to_contain_text('已审核')
                    with sqlite3.connect(db_path) as db:
                        purchase_id=db.execute("SELECT id FROM inv_docs WHERE kind='purchase' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
                    assert purchase_id==purchase_number
                    frame.locator('[data-action=close]').click()
                    page.goto(base+'/service/#inventory/receipt/new')
                    orders=frame.get_by_role('dialog',name='选择采购订单',exact=True)
                    orders.get_by_role('searchbox').fill(purchase_id)
                    orders.locator('[data-proc-id]').click()
                    orders.locator('.proc-confirm').click()
                    expect(form).to_have_attribute('data-kind','receipt')
                    expect(form).to_have_attribute('data-number-ready','true')
                    receipt_number=form.locator('[name=reserved_number]').input_value()
                    expect(form.locator('[name=party_id]')).to_be_disabled()
                    expect(form.locator('[name=warehouse_id]')).to_be_disabled()
                    form.locator('.proc-warehouse').click()
                    expect(stores.locator('[data-proc-id]')).to_have_count(1)
                    expect(stores).to_contain_text('沿用来源采购订单')
                    page.keyboard.press('Escape')
                    tracked=form.locator('.line').first
                    # Existing serials must survive product subset editing.
                    tracked.locator('[name=qty]').fill('1')
                    tracked.locator('[name=serials]').fill('PROC-SN-'+str(width))
                    form.locator('.proc-select-products').click()
                    receipt=frame.get_by_role('dialog',name='选择入库产品',exact=True)
                    expect(receipt.locator('[data-proc-product]')).to_have_count(2)
                    receipt.locator('[data-proc-remove]').last.click()
                    receipt.locator('[data-proc-edit]').click()
                    expect(item.locator('[name=qty]')).to_have_attribute('max','2')
                    item.get_by_role('button',name='取消',exact=True).click()
                    bounds(receipt)
                    page.screenshot(path=str(REPORT/f'receipt-products-{width}.png'),full_page=True)
                    receipt.locator('.proc-apply').click()
                    expect(form.locator('.line')).to_have_count(1)
                    expect(form.locator('[name=serials]')).to_have_value('PROC-SN-'+str(width))
                    form.get_by_role('button',name='保存草稿').click()
                    frame.locator('[data-action=submit]').click()
                    frame.locator('[data-action=approve]').click()
                    expect(frame.locator('#doc-print h2')).to_contain_text('已审核')
                    with sqlite3.connect(db_path) as db:
                        assert db.execute("SELECT warehouse_id FROM inv_sn WHERE sn=?",('PROC-SN-'+str(width),)).fetchone()[0]=='TEST-W2'
                        rid=db.execute("SELECT id FROM inv_docs WHERE kind='receipt' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
                        assert rid==receipt_number
                        assert db.execute("SELECT source_id FROM inv_docs WHERE id=?",(rid,)).fetchone()[0]==purchase_id
                    assert not errors,errors
                    print('PASS purchase + partial receipt save/approve/source/SN',width,height)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
