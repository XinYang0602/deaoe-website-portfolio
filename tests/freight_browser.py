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
        INSERT INTO inv_docs(id,kind,status,warehouse_id,party_id,date,note,created_at) VALUES('S','sale','approved','W','C','2026-11-13','','now');
        INSERT INTO inv_docs(id,kind,status,source_id,warehouse_id,party_id,date,note,created_at) VALUES('I','issue','approved','S','W','C','2026-11-13','','now');
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
                    page.goto(base+'/service/#inventory/shipments')
                    frame=page.frame_locator('#inventory-workspace-frame')
                    frame.get_by_role('button',name='登记发货单').click()
                    f=frame.locator('#freight-entry')
                    f.get_by_label('货运部').fill('合成货运部')
                    files=f.locator('input[type=file]')
                    png=subprocess.check_output(['php','-r','imagepng(imagecreatetruecolor(8,8));'])
                    files.set_input_files([{'name':'receipt.png','mimeType':'image/png','buffer':png},{'name':'bad.png','mimeType':'image/png','buffer':b'invalid'}])
                    f.get_by_role('button',name='保存发货单').click()
                    expect(f.get_by_role('button',name='重试未上传照片')).to_be_visible()
                    files.set_input_files({'name':'second.png','mimeType':'image/png','buffer':png})
                    f.get_by_role('button',name='重试未上传照片').click()
                    photos=frame.locator('dialog a[download]')
                    expect(photos).to_have_count(2)
                    response=page.request.get(base+photos.first.get_attribute('href'))
                    assert response.body()[8:12]==b'WEBP' and response.headers['content-type']=='image/webp'
                    frame.locator('dialog').get_by_role('button',name='S',exact=True).click()
                    expect(frame.locator('dialog')).to_contain_text('关联发货单')
                    frame.locator('dialog').get_by_role('button',name='登记发货单').click()
                    frame.locator('#freight-entry').get_by_label('货运部').fill('未保存')
                    manifest=root/'site/release.json'
                    release={'version':str(width),'summary':'合成更新说明'}
                    manifest.write_text(json.dumps(release))
                    page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
                    banner=page.locator('#release-notice')
                    expect(banner).to_contain_text('合成更新说明')
                    expect(frame.locator('#freight-entry').get_by_label('货运部')).to_have_value('未保存')
                    banner.get_by_role('button',name='刷新网页').click()
                    expect(page.locator('meta[name=service-release]')).to_have_attribute('content',str(width))
                    expect(banner).to_have_count(0)
                    with sqlite3.connect(db_path) as db:
                        assert db.execute('SELECT COUNT(*) FROM inv_shipments').fetchone()[0]==(1 if width==1440 else 2)
                    assert not errors,errors
                    print('PASS linked dispatch, multi-upload/retry, WebP download and release banner',width)
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
