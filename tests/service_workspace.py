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
                db.execute("UPDATE inv_parties SET phone='00000000000' WHERE id='TEST-C'")
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
                    page.goto(base+'/service/#orders')
                    if width==390:
                        expect(page.locator('.service-register')).to_have_count(0)
                        page.locator('[data-action=new-order]').click()
                        expect(page.locator('#modal [name=product]')).to_be_visible()
                        expect(page.locator('[data-service-pick]')).to_have_count(0)
                        print('PASS mobile original service entry')
                        page.close()
                        continue
                    expect(page.locator('.service-register')).to_be_visible()
                    page.get_by_role('button',name='服务开单',exact=True).click()
                    form=page.locator('#modal form')
                    expect(form.locator('[name=customer]')).to_be_visible()
                    form.locator('[data-service-pick=customer]').click()
                    picker=page.get_by_role('dialog',name='选择客户',exact=True)
                    picker.get_by_role('searchbox').fill('测试客户')
                    expect(picker.locator('[data-choice]')).to_be_visible()
                    page.screenshot(path=str(REPORT/f'service-customer-{width}.png'),full_page=False)
                    picker.locator('[data-choice]').click()
                    picker.get_by_role('button',name='确定选择').click()
                    expect(form.locator('[name=customer]')).to_have_value('测试客户')
                    expect(form.locator('[name=phone]')).to_have_value('00000000000')
                    expect(form.locator('[name=address]')).to_have_value('测试地址')
                    form.locator('[data-service-pick=product]').click()
                    picker=page.get_by_role('dialog',name='选择产品',exact=True)
                    picker.get_by_role('searchbox').fill('TEST-P')
                    expect(picker.locator('[data-choice]').filter(has_text='测试油烟机')).to_be_visible()
                    page.screenshot(path=str(REPORT/f'service-product-{width}.png'),full_page=False)
                    picker.locator('[data-choice]').filter(has_text='测试油烟机').dblclick()
                    expect(form.locator('[name=product]')).to_have_value('测试油烟机 TEST')
                    form.locator('[data-service-pick=customer]').click()
                    page.keyboard.press('Escape')
                    expect(form.locator('[name=customer]')).to_have_value('测试客户')
                    form.locator('[name=sn]').fill('SERVICE-TEST-'+str(width))
                    form.locator('[name=note]').fill('合成测试安装需求')
                    page.screenshot(path=str(REPORT/f'service-entry-{width}.png'),full_page=False)
                    assert form.get_by_role('button',name='保存工单').evaluate('(e)=>e.getBoundingClientRect().bottom<=innerHeight')
                    assert page.locator('#modal').evaluate('(e)=>e.getBoundingClientRect().right<=innerWidth && e.getBoundingClientRect().bottom<=innerHeight')
                    form.get_by_role('button',name='保存工单').click()
                    expect(page.locator('#modal')).not_to_be_visible()
                    row=page.locator('.service-register tbody tr').filter(has_text='SERVICE-TEST-'+str(width))
                    expect(row).to_be_visible()
                    row.click()
                    expect(page.locator('.service-register-summary')).to_contain_text('合成测试安装需求')
                    row.locator('[data-detail]').click()
                    page.locator('#modal [name=record]').fill('合成测试派工')
                    page.locator('#modal [name=status]').select_option('处理中')
                    page.get_by_role('button',name='更新工单').click()
                    expect(row).to_contain_text('处理中')
                    page.locator('#filter').select_option('处理中')
                    page.locator('#search').fill('SERVICE-TEST-'+str(width))
                    expect(page.locator('.service-register tbody tr')).to_have_count(1)
                    page.screenshot(path=str(REPORT/f'service-list-{width}.png'),full_page=False)
                    page.get_by_role('button',name='服务开单',exact=True).click()
                    page.locator('#modal [name=note]').fill('取消应不保存')
                    page.locator('#modal').get_by_role('button',name='取消',exact=True).click()
                    expect(page.locator('.service-register tbody tr')).to_have_count(1)
                    page.get_by_role('button',name='处理过程',exact=True).click()
                    expect(page.locator('.service-register-summary')).to_contain_text('合成测试派工')
                    page.get_by_role('button',name='工单详情',exact=True).click()
                    page.evaluate('''() => {
                        const order=db.orders.find(o=>o.status==='处理中');
                        for(let i=0;i<21;i++) db.orders.unshift({...order,id:'fixture-'+i,customer:'分页客户'+i});
                        query='分页客户';render();
                    }''')
                    expect(page.locator('[data-service-row]')).to_have_count(20)
                    page.get_by_role('button',name='下一页',exact=True).click()
                    expect(page.locator('[data-service-row]')).to_have_count(1)
                    expect(page.locator('.service-register-summary')).to_contain_text('fixture-0')
                    page.locator('.service-register .column-menu summary').click()
                    choices=page.locator('.service-register .column-options')
                    choices.get_by_label('联系电话',exact=True).uncheck()
                    expect(page.get_by_role('columnheader',name='联系电话 调整联系电话列宽',exact=True)).to_have_count(0)
                    expect(page.get_by_role('columnheader',name='客户 调整客户列宽',exact=True)).to_be_visible()
                    assert not errors,errors
                    print('PASS desktop service selection, save, update, list filtering and cancel',width,height)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
