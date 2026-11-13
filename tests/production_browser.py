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
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome',headless=True)
                page=browser.new_page(viewport={'width':1440,'height':900})
                page.route('**/*',lambda r:r.continue_() if r.request.url.startswith(base+'/') else r.abort())
                errors=[]
                page.on('pageerror',lambda e:errors.append(str(e)))
                page.on('dialog',lambda d:d.accept())
                page.goto(base+'/service/')
                page.locator('[name=password]').fill('local-test-password')
                page.get_by_role('button',name='登录管理平台').click()
                page.wait_for_load_state('networkidle')
                page.goto(base+'/service/#inventory/products')
                f=page.frame_locator('#inventory-workspace-frame')
                f.locator('[data-catalog-row="TEST-PART"]').click()
                f.locator('[data-catalog-edit]').click()
                f.get_by_text('所在仓库 · 仓位',exact=True).click()
                f.get_by_label('测试仓货位',exact=True).fill('A-01 · 2')
                f.get_by_label('第二测试仓货位',exact=True).fill('B-03')
                f.get_by_text('预设售价',exact=True).click()
                f.get_by_label('预设售价1',exact=True).fill('358.00')
                page.screenshot(path=str(REPORT/'product-locations.png'))
                f.locator('#master-form [type=submit]').click()
                expect(f.locator('#modal')).to_be_hidden()
                with sqlite3.connect(db_path) as check_db:
                    assert json.loads(check_db.execute("SELECT v FROM inv_meta WHERE k='prices:TEST-PART'").fetchone()[0])[0] == 35800
                page.goto(base+'/service/#inventory/locations')
                expect(f.locator('#main')).to_contain_text('A-01')
                expect(f.locator('#main')).to_contain_text('B-03')
                frame=page.frames[1]
                frame.evaluate("""() => {
                  const saved=data.docs,lines=data.lines;
                  data.docs=[{id:'OLD',kind:'sale',status:'approved',party_id:'C',date:'2026-01-01'},{id:'NEW',kind:'sale',status:'approved',party_id:'C',date:'2026-02-01'},{id:'DRAFT',kind:'sale',status:'draft',party_id:'C',date:'2026-03-01'}];
                  data.lines=[{doc_id:'OLD',product_id:'TEST-PART',price_cents:100},{doc_id:'NEW',product_id:'TEST-PART',price_cents:250},{doc_id:'DRAFT',product_id:'TEST-PART',price_cents:999}];
                  const f=document.createElement('form'),o=document.createElement('form');f.innerHTML='<div class="item-fields"></div><input name="unit"><input name="discount">';o.innerHTML='<input name="party_id" value="C">';document.body.append(f);
                  setupSalePricing(f,o,{id:'TEST-PART',sale_cents:700},()=>{});
                  f.querySelector('[data-track-price]').click();if(f.elements.unit.value!=='2.50')throw Error('history selection');
                  o.elements.party_id.value='OTHER';f.querySelector('[data-track-price]').click();f.querySelector('[data-track-price]').click();if(f.elements.unit.value!=='7.00')throw Error('fallback');
                  f.querySelector('[data-pick-price]').click();document.querySelector('[data-price-index="0"]').click();if(f.elements.unit.value!=='358.00')throw Error('preset selection');
                  f.remove();data.docs=saved;data.lines=lines;
                }""")
                page.goto(base+'/service/#inventory/boms')
                f.locator('[data-production=template]').click()
                f.locator('[name=name]').fill('Synthetic assembly')
                f.locator('#production-template [name=product_id]').select_option('TEST-P')
                f.locator('[name=part]').select_option('TEST-PART')
                f.locator('[name=usage]').fill('2')
                f.locator('#production-template [type=submit]').click()
                expect(f.locator('#modal')).to_be_hidden()
                # Seed component stock through existing business adjustment, not raw balances.
                subprocess.run(['php','-r',"require "+repr(str(root/'site/inventory.php'))+"; $d=new PDO('sqlite:"+str(db_path)+"'); $d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $_SESSION=['user'=>'admin']; $d->beginTransaction(); invAdjustStock($d,['product_id'=>'TEST-PART','warehouse_id'=>'TEST-W','expected_qty'=>0,'qty'=>10,'note'=>'synthetic','request_key'=>bin2hex(random_bytes(12))]); $d->commit();"],check=True)
                page.goto(base+'/service/#inventory/production')
                for kind in ['assembly','disassembly']:
                    f.locator('[data-production='+kind+']').click()
                    form=f.locator('#production-create')
                    expect(form.locator('[name=number]')).not_to_have_value('')
                    number=form.locator('[name=number]').input_value()
                    with sqlite3.connect(db_path) as db:
                        bom=db.execute('SELECT id FROM inv_boms').fetchone()[0]
                    form.locator('[name=bom_id]').select_option(bom)
                    form.locator('[name=warehouse_id]').select_option('TEST-W')
                    page.screenshot(path=str(REPORT/(kind+'-create.png')))
                    form.locator('[type=submit]').click()
                    expect(f.locator('[data-production=submit]')).to_be_visible()
                    f.locator('[data-production=submit]').click()
                    expect(f.locator('[data-production=approve]')).to_be_visible()
                    if kind=='disassembly':
                        f.locator('[data-input-product]').first.check()
                    f.locator('[data-production=approve]').click()
                    expect(f.locator('#modal')).to_contain_text('已审核')
                    expect(f.locator('#modal')).to_contain_text('产出记录')
                    page.screenshot(path=str(REPORT/(kind+'-approved.png')))
                    f.locator('#modal [data-action=close]').click()
                    with sqlite3.connect(db_path) as db:
                        assert db.execute('SELECT status FROM inv_production WHERE id=?',(number,)).fetchone()[0]=='approved'
                for width,height in [(1440,900),(1024,700)]:
                    page.set_viewport_size({'width':width,'height':height})
                    expect(f.locator('#main h1')).to_have_text('组装与拆装')
                    page.wait_for_timeout(350)
                    page.screenshot(path=str(REPORT/f'production-{width}.png'))
                assert not errors,errors
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('PASS warehouse location editor and assembly/disassembly browser flow. Report:',REPORT)
