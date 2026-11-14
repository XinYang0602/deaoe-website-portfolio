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
        INSERT INTO inv_warehouses VALUES('W','合成仓',1,1);
        INSERT INTO inv_parties VALUES('C','customer','合成客户','','','',1,1);
        INSERT INTO inv_products VALUES('P','SKU1','合成商品','','','','件',0,NULL,1000,1,'',1);
        INSERT INTO inv_salespeople VALUES('SP','合成业务员','','',1,1);
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
                    page.goto(base+'/service/#inventory/platform_orders')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    frame.get_by_role('button',name='导入订单文件').click()
                    f=frame.locator('#platform-import-editor')
                    f.locator('[name=shop]').fill('合成店'+str(width))
                    f.locator('[name=warehouse_id]').select_option('W')
                    f.locator('[name=party_id]').select_option('C')
                    csv='平台订单号,订单日期,产品编号,数量,优惠后单价,收货电话,收货地址\n00123456789012345678,2026-11-13,SKU1,2,19.99,000000,合成地址'
                    f.locator('[name=file]').set_input_files({'name':'orders.csv','mimeType':'text/csv','buffer':csv.encode()})
                    expect(f.locator('[data-map=order_id]')).to_have_value('0')
                    f.get_by_role('button',name='校验并预览').click()
                    expect(f.locator('[data-order-preview]')).to_contain_text('39.98')
                    f.get_by_role('button',name='确认生成销售草稿').click()
                    expect(f.locator('[data-order-result]')).to_contain_text('已生成 1 张销售草稿')
                    f.get_by_role('button',name='校验并预览').click()
                    expect(f.locator('[data-order-preview]')).to_contain_text('已导入，跳过')
                    f.get_by_role('button',name='取消').click()
                    frame.get_by_role('link',name='业务员提成模板').click()
                    frame.get_by_role('button',name='新建提成模板').click()
                    f=frame.locator('#commission-editor')
                    f.locator('[name=name]').fill('合成模板'+str(width))
                    f.locator('[name=rate]').fill('3.25')
                    f.get_by_role('button',name='保存模板').click()
                    expect(f).to_be_hidden()
                    frame.locator('[data-template-trial]').last.click()
                    f=frame.locator('#commission-trial');f.get_by_role('button',name='计算提成').click()
                    expect(f.locator('[data-trial-result]')).to_contain_text('32.50')
                    f.get_by_role('button',name='取消').click()
                    frame.locator('[data-template-assign]').first.click()
                    f=frame.locator('#commission-assignment');f.locator('select').select_option(label='合成模板'+str(width));f.get_by_role('button',name='保存分配').click()
                    expect(f).to_be_hidden()
                    assert not errors,errors
                    print('PASS import preview, draft, duplicate, template, trial and assignment',width)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
