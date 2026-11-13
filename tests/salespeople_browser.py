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
                for width in [1440,390]:
                    page=browser.new_page(viewport={'width':width,'height':900})
                    page.route('**/*',lambda r:r.continue_() if r.request.url.startswith(base+'/') else r.abort())
                    errors=[]
                    page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base+'/service/')
                    page.locator('[name=password]').fill('local-test-password')
                    page.get_by_role('button',name='登录管理平台').click()
                    page.wait_for_load_state('networkidle')
                    page.goto(base+'/service/#inventory/parties')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    frame.get_by_role('link',name='业务员管理',exact=True).click()
                    expect(frame.locator('#main h1')).to_have_text('业务员管理')
                    frame.locator('[data-salesperson-new]').click()
                    form=frame.locator('#salespeople-editor')
                    form.locator('[name=name]').fill('合成业务员'+str(width))
                    form.locator('[name=phone]').fill('000000')
                    form.locator('[name=note]').fill('仅本地测试')
                    form.locator('[type=submit]').click()
                    expect(form).to_be_hidden()
                    with sqlite3.connect(db_path) as db:
                        staff=db.execute('SELECT id FROM inv_salespeople WHERE name=?',('合成业务员'+str(width),)).fetchone()[0]
                    frame.get_by_role('link',name='客户分配',exact=True).click()
                    expect(frame.locator('[data-party-row]')).to_have_count(3)
                    frame.locator('[data-party-select-all]').check()
                    expect(frame.locator('[data-party-select]:checked')).to_have_count(2)
                    frame.locator('[data-party-assign]').click()
                    assignment=frame.locator('#salespeople-assignment')
                    expect(assignment).to_contain_text('已选择 2 位客户')
                    assignment.locator('[name=salesperson_id]').select_option(staff)
                    assignment.locator('[type=submit]').click()
                    expect(assignment).to_be_hidden()
                    expect(frame.locator('[data-party-row="TEST-C"]')).to_contain_text('合成业务员'+str(width))
                    frame.locator('[data-party-salesperson]').select_option(staff)
                    expect(frame.locator('[data-party-row]')).to_have_count(2)
                    assert frame.locator('.catalog-scroll table').evaluate('(el)=>el.getBoundingClientRect().width<=innerWidth')
                    page.screenshot(path=str(REPORT/f'assignment-{width}.png'),full_page=True)
                    page.reload()
                    expect(frame.locator('[data-party-row="TEST-C"]')).to_contain_text('合成业务员'+str(width))
                    frame.get_by_role('link',name='业务员管理',exact=True).click()
                    frame.locator('[data-salesperson-edit="'+staff+'"]').click()
                    form.locator('[name=active]').uncheck()
                    form.locator('[type=submit]').click()
                    expect(form).to_be_hidden()
                    frame.locator('[data-salesperson-inactive]').check()
                    expect(frame.locator('[data-salesperson-edit="'+staff+'"]').locator('..').locator('..')).to_contain_text('停用')
                    page.screenshot(path=str(REPORT/f'salespeople-{width}.png'),full_page=True)
                    frame.get_by_role('link',name='客户分配',exact=True).click()
                    expect(frame.locator('[data-party-row="TEST-C"]')).to_contain_text('（已停用）')
                    frame.locator('[data-party-select-all]').check()
                    frame.locator('[data-party-assign]').click()
                    expect(assignment.locator('option[value="'+staff+'"]')).to_have_count(0)
                    assignment.locator('[name=salesperson_id]').select_option('')
                    assignment.locator('[type=submit]').click()
                    expect(assignment).to_be_hidden()
                    frame.locator('[data-party-salesperson]').select_option('unassigned')
                    expect(frame.locator('[data-party-row]')).to_have_count(2)
                    with sqlite3.connect(db_path) as db:
                        assert db.execute('SELECT COUNT(*) FROM inv_customer_salespeople').fetchone()[0]==0
                    assert not errors,errors
                    print('PASS staff create, batch assign, supplier exclusion, filter, reload, disable retention and unassign',width)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
