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
                    with sqlite3.connect(db_path) as db:
                        db.execute('CREATE TABLE IF NOT EXISTS ui_preferences(actor TEXT PRIMARY KEY,font_scale INTEGER NOT NULL)')
                        db.execute('DELETE FROM ui_preferences')
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
                    expect(frame.locator('[data-party-salesperson]')).to_be_visible()
                    assert '业务员筛选' not in frame.locator('body').inner_text()
                    cell=frame.locator('[data-party-row="TEST-C"] td').nth(3)
                    original=cell.evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    page.goto(base+'/service/#settings')
                    settings=page.locator('#font-preferences-form')
                    expect(settings.locator('select')).to_be_enabled()
                    expect(settings.locator('[data-font-retry]')).to_be_hidden()
                    input_baseline=page.locator('#settings-form [name=name]').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    baseline=page.locator('#main h1').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    page.emulate_media(media='print')
                    print_baseline=page.locator('#main h1').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    page.emulate_media(media='screen')
                    settings.locator('select').select_option('150')
                    settings.locator('[type=submit]').click()
                    expect(settings.locator('[role=status]')).to_contain_text('字号已保存')
                    grown=page.locator('#main h1').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    assert abs(grown-baseline*1.5)<.1,(baseline,grown)
                    assert abs(page.locator('#settings-form [name=name]').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')-input_baseline*1.5)<.1
                    page.screenshot(path=str(REPORT/f'font-settings-{width}.png'),full_page=True)
                    with sqlite3.connect(db_path) as db:
                        assert db.execute('SELECT font_scale FROM ui_preferences WHERE actor=?',('admin',)).fetchone()==(150,)
                    # Failed saves keep the currently applied preference and expose a retryable error.
                    def fail_save(route):
                        if route.request.method=='POST':route.fulfill(status=503,json={'error':'synthetic failure'})
                        else:route.continue_()
                    page.route('**/api/ui-preferences',fail_save)
                    settings.locator('select').select_option('110')
                    settings.locator('[type=submit]').click()
                    expect(settings.locator('[role=status]')).to_contain_text('保存失败')
                    assert page.locator('html').evaluate("el=>el.style.getPropertyValue('--ui-font-scale')")=='1.5'
                    page.unroute('**/api/ui-preferences',fail_save)
                    page.goto(base+'/service/#inventory/parties')
                    expect(cell).to_be_visible()
                    actual=cell.evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')
                    assert abs(actual-original*1.5)<.1,(original,actual)
                    frame.locator('body').evaluate('''el=>{const style=document.createElement('style');style.textContent='#font-dynamic{font-size:18px}';document.head.append(style);el.insertAdjacentHTML('beforeend','<span id="font-dynamic">dynamic</span><span id="font-inline" style="font-size:12px">inline</span><span id="font-variable" style="font-size:var(--list-text)">variable</span>');}''')
                    expect(frame.locator('#font-dynamic')).to_have_css('font-size','27px')
                    expect(frame.locator('#font-inline')).to_have_css('font-size','18px')
                    expect(frame.locator('#font-variable')).to_have_css('font-size','19.5px')
                    frame.locator('[data-party-edit]').click()
                    expect(frame.locator('#master-form')).to_be_visible()
                    assert frame.locator('#master-form [name=name]').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')>=18
                    page.screenshot(path=str(REPORT/f'font-dialog-{width}.png'),full_page=True)
                    frame.locator('#modal [data-action=close]').click()
                    page.reload()
                    expect(cell).to_be_visible()
                    page.wait_for_function("document.querySelector('#inventory-workspace-frame').contentDocument.documentElement.style.getPropertyValue('--ui-font-scale')==='1.5'")
                    assert abs(cell.evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')-original*1.5)<.1
                    page.goto(base+'/service/#settings')
                    expect(settings.locator('select')).to_have_value('150')
                    page.emulate_media(media='print')
                    assert abs(page.locator('#main h1').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')-print_baseline)<.1
                    page.emulate_media(media='screen')
                    settings.locator('select').select_option('100')
                    settings.locator('[type=submit]').click()
                    expect(settings.locator('[role=status]')).to_contain_text('字号已保存')
                    assert abs(page.locator('#main h1').evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)')-baseline)<.1
                    assert not errors,errors
                    print('PASS global typography, iframe/modal scaling, persistence, save failure, print and reset',width)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
