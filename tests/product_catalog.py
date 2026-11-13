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
        text = file.read_text().replace('/var/lib/deaoe-service-auth', str(root / 'auth'))
        text = text.replace('/var/lib/deaoe-service-data', str(root / 'data'))
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
                    page.goto(base+'/service/#settings')
                    expect(page.locator('.settings-entry')).to_have_count(3)
                    expect(page.locator('#settings-form')).to_be_visible()
                    entry=page.get_by_role('link',name='编号管理',exact=False)
                    expect(entry).to_be_visible()
                    assert entry.bounding_box()['height']>=44
                    page.screenshot(path=str(REPORT/f'settings-{width}.png'),full_page=True)
                    entry.click()
                    expect(page.frame_locator('#inventory-workspace-frame').locator('#main h1')).to_have_text('编号管理')
                    page.goto(base+'/service/#customers')
                    directory=page.frame_locator('#inventory-workspace-frame')
                    expect(directory.locator('#main h1')).to_have_text('客户与往来单位')
                    expect(directory.locator('[data-party-row]')).to_have_count(2)
                    directory.locator('[data-party-kind="supplier"]').click()
                    expect(directory.locator('[data-party-row]')).to_have_count(1)
                    expect(directory.locator('.party-details')).to_contain_text('测试供应商')
                    directory.locator('[data-party-kind="customer"]').click()
                    expect(directory.locator('.party-details')).to_contain_text('测试联系人')
                    if width==1440:
                        directory.locator('[data-action=master][data-id=parties]').click()
                        expect(directory.locator('#master-form [name=kind]')).to_have_value('customer')
                        page.screenshot(path=str(REPORT/'customer-new-1440.png'),full_page=True)
                        directory.locator('#modal [data-action=close]').click()
                        directory.locator('[data-party-category-new]').click()
                        directory.locator('#party-category-form [name=name]').fill('示例品牌')
                        directory.locator('#party-category-form [type=submit]').click()
                        expect(directory.locator('#modal')).not_to_be_visible()
                        directory.locator('[data-party-category-new]').click()
                        directory.locator('#party-category-form [name=name]').fill('零售客户')
                        directory.locator('#party-category-form [type=submit]').click()
                        expect(directory.locator('#modal')).not_to_be_visible()
                        directory.locator('[data-party-kind="customer"]').click()
                        directory.locator('[data-party-edit]').click()
                        directory.locator('#master-form [name=category_id]').select_option(label='示例品牌 › 零售客户')
                        page.screenshot(path=str(REPORT/'customer-edit-1440.png'),full_page=True)
                        directory.locator('[data-party-category-open]').click()
                        directory.locator('#master-form [type=submit]').click()
                        expect(directory.locator('#master-form .form-error')).to_contain_text('请先添加分类')
                        directory.locator('[data-category-name]').fill('分销客户')
                        directory.locator('[data-party-category-add]').click()
                        expect(directory.locator('[data-party-category-create]')).not_to_be_visible()
                        directory.locator('#master-form [type=submit]').click()
                        expect(directory.locator('#modal')).not_to_be_visible()
                        expect(directory.locator('.party-details')).to_contain_text('分销客户')
                        directory.get_by_role('button',name='示例品牌',exact=True).click()
                        expect(directory.locator('[data-party-row]')).to_have_count(1)
                        directory.locator('[data-party-category-edit]').click()
                        directory.locator('#party-category-form [name=name]').fill('示例品牌改名')
                        directory.locator('#party-category-form [type=submit]').click()
                        expect(directory.locator('#modal')).not_to_be_visible()
                        page.reload()
                        expect(directory.locator('.party-details')).to_contain_text('示例品牌改名')
                    page.screenshot(path=str(REPORT/f'parties-{width}.png'),full_page=True)
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
                        grip=frame.locator('.catalog-scroll .column-grip').nth(1)
                        expect(grip).to_be_visible()
                        grip.focus()
                        with page.expect_response(lambda r:'/api/column-widths' in r.url and r.request.method=='POST') as saved:
                            page.keyboard.press('ArrowRight')
                        assert saved.value.status==200
                        state=page.request.get(base+'/service/api/column-widths').json()
                        assert state['views']
                        chooser=frame.locator('.catalog-scroll .column-menu').first
                        chooser.locator('summary').click()
                        with page.expect_response(lambda r:'/api/column-widths' in r.url and r.request.method=='POST') as chosen:
                            chooser.get_by_label('品牌',exact=True).uncheck()
                        assert chosen.value.status==200
                        expect(table.locator('th').filter(has_text='品牌')).to_be_hidden()
                        # A list rerender restores the saved preference, rather than resetting it.
                        frame.locator('[data-catalog-reset]').click()
                        expect(table.locator('th').filter(has_text='品牌')).to_be_hidden()
                        chooser=frame.locator('.catalog-scroll .column-menu').first
                        chooser.locator('summary').click()
                        with page.expect_response(lambda r:'/api/column-widths' in r.url and r.request.method=='POST'):
                            chooser.get_by_role('button',name='恢复默认',exact=True).click()
                        expect(table.locator('th').filter(has_text='品牌')).to_be_visible()
                        chooser.locator('summary').click()
                        expect(frame.locator('.category-tree small')).to_have_count(0)
                        frame.locator('.catalog-more summary').click()
                        frame.locator('[data-code-rule]').click()
                        expect(frame.locator('#code-rule-form')).to_be_visible()
                        frame.locator('#code-rule-form [type=submit]').click()
                        expect(frame.locator('#code-rule-form')).to_be_hidden()
                        frame.locator('#search').fill('TEST-PART')
                        expect(table.locator('[data-catalog-row]')).to_have_count(1)
                        frame.locator('[data-catalog-copy]').click()
                        expect(frame.locator('#master-form')).to_have_attribute('data-id','')
                        expect(frame.locator('#master-form')).to_have_attribute('data-number-ready','true')
                        assert frame.locator('#master-form [name=code]').input_value()!='TEST-PART'
                        expect(frame.locator('#master-form [name=name]')).to_have_value('测试配件')
                        frame.locator('#modal [data-action=close]').click()
                        frame.locator('[data-catalog-edit]').click()
                        expect(frame.locator('#master-form')).to_be_visible()
                        expect(frame.locator('.product-tabs')).to_have_count(0)
                        expect(frame.locator('.category-editor')).to_be_visible()
                        expect(frame.locator('.product-image-editor')).to_be_visible()
                        expect(frame.locator('.code-edit-hint')).to_have_count(0)
                        expect(frame.locator('#master-form [name=code]')).to_have_value('TEST-PART')
                        frame.locator('[data-category-add]').click()
                        frame.locator('.category-new:visible input').fill('未确认分类')
                        frame.locator('#master-form button[type=submit]').click()
                        expect(frame.locator('#master-form .form-error')).to_contain_text('分类尚未添加')
                        expect(frame.locator('#master-form')).to_be_visible()
                        frame.locator('[data-category-cancel]').last.click()
                        for level in ['厨电','配件','点火','示例品牌']:
                            frame.locator('[data-category-add]').click()
                            frame.locator('.category-new:visible input').fill(level)
                            frame.locator('.category-new:visible [data-category-confirm]').click()
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
                        frame.locator('[data-action=master][data-id=products]').click()
                        expect(frame.locator('[name=category_path]')).to_have_value('厨电')
                        expect(frame.locator('#master-form')).to_have_attribute('data-number-ready','true')
                        expect(frame.get_by_role('button',name='重试获取编号')).to_be_hidden()
                        preview_code=frame.locator('#master-form [name=code]').input_value()
                        frame.locator('#modal [data-action=close]').click()
                        frame.locator('[data-action=master][data-id=products]').click()
                        expect(frame.locator('#master-form')).to_have_attribute('data-number-ready','true')
                        expect(frame.locator('#master-form [name=code]')).to_have_value(preview_code)
                        page.screenshot(path=str(REPORT/f'category-picker-{width}.png'),full_page=True)
                        frame.locator('#modal [data-action=close]').click()
                        page.screenshot(path=str(REPORT/f'category-tree-{width}.png'),full_page=True)
                        frame.locator('[data-catalog-edit]').click()
                        expect(frame.locator('[name=category_path]')).to_have_value('厨电 / 配件 / 点火 / 示例品牌')
                        frame.locator('[data-category-search]').fill('配件')
                        frame.locator('[data-category-destination]').select_option('厨电 / 配件')
                        expect(frame.locator('[name=category_path]')).to_have_value('厨电 / 配件')
                        frame.locator('[data-category-reset]').click()
                        frame.locator('#master-form button[type=submit]').click()
                        frame.locator('[data-catalog-reset]').click()
                        frame.locator('#search').fill('TEST-PART')
                        frame.locator('[data-catalog-edit]').click()
                        frame.locator('#modal [data-action=close]').click()
                        table.locator('[data-catalog-image="TEST-PART"]').dblclick()
                        expect(frame.locator('#master-form')).to_have_attribute('data-id','TEST-PART')
                        expect(frame.locator('.product-image-editor')).to_be_visible()
                        frame.locator('#master-form [name=model]').fill('unsaved-model')
                        import struct,zlib
                        def chunk(kind,payload):
                            return struct.pack('!I',len(payload))+kind+payload+struct.pack('!I',zlib.crc32(kind+payload))
                        png=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',1,1,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(b'\x00\xff\x00\x00'))+chunk(b'IEND',b'')
                        frame.locator('[data-product-image-file]').set_input_files([{'name':f'synthetic-{n}.png','mimeType':'image/png','buffer':png} for n in range(13)]+[{'name':'invalid.png','mimeType':'image/png','buffer':b'not an image'}])
                        with page.expect_response(lambda r:'/api/images' in r.url and r.request.method=='POST') as uploaded:
                            frame.locator('[data-upload-product-image]').click()
                        assert uploaded.value.status==200,uploaded.value.text()
                        expect(frame.locator('[data-image-status]')).to_contain_text('已上传 13 张；1 张未确认成功',timeout=30000)
                        expect(frame.locator('[data-image-status]')).to_contain_text('invalid.png')
                        with sqlite3.connect(db_path) as check_db:
                            assert check_db.execute("SELECT COUNT(*) FROM inv_images WHERE product_id='TEST-PART'").fetchone()[0]>=13
                        expect(frame.locator('#master-form [name=model]')).to_have_value('unsaved-model')
                        expect(frame.locator('.product-edit-gallery img')).not_to_have_count(0)
                        page.screenshot(path=str(REPORT/f'product-edit-images-{width}.png'),full_page=True)
                        frame.locator('#modal [data-action=close]').click()
                        frame.locator('[data-catalog-reset]').click()
                        table.locator('[data-catalog-image="TEST-PART"]').dblclick()
                        expect(frame.locator('#master-form')).to_have_attribute('data-id','TEST-PART')
                        expect(frame.locator('.product-image-editor')).to_be_visible()
                        frame.locator('#modal [data-action=close]').click()
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
                    if width==1440:
                        frame.locator('[data-brand-manage]').click()
                        expect(frame.locator('#brands-form tbody tr').first.locator('input')).to_have_count(1)
                        custom_brand='自定义 Custom 完整品牌名称不截断'
                        frame.locator('#brands-form tbody input').last.fill(custom_brand)
                        frame.locator('#brands-form [type=submit]').click()
                        expect(frame.locator('#brands-form')).to_be_hidden()
                        frame.locator('[data-action=master][data-id=products]').click()
                        form=frame.locator('#master-form')
                        expect(form.locator('[name=active]')).to_be_checked()
                        form.locator('[name=name]').fill('DEAOE 自定义完整产品名称 CZBJ4Z【816】不锈钢四层直网')
                        expect(form).to_have_attribute('data-number-ready','true')
                        assert form.locator('[name=code]').input_value().startswith('P')
                        form.locator('[name=code]').fill('NEW-TEST')
                        form.locator('[name=brand]').select_option(label=custom_brand)
                        form.locator('[data-product-image-file]').set_input_files([
                            {'name':'new-one.png','mimeType':'image/png','buffer':png},
                            {'name':'new-two.png','mimeType':'image/png','buffer':png},
                            {'name':'bad.png','mimeType':'image/png','buffer':b'invalid'}])
                        with page.expect_response(lambda r:'/api/inventory' in r.url and r.request.method=='POST') as created:
                            form.locator('[type=submit]').click()
                        assert created.value.status==200,created.value.text()
                        created_id=created.value.json()['id']
                        expect(form.locator('.form-error')).to_contain_text('产品档案已保存',timeout=30000)
                        expect(form).to_have_attribute('data-id',created_id)
                        expect(form.locator('.product-edit-gallery img')).to_have_count(2)
                        form.locator('.product-edit-gallery img').first.hover()
                        expect(frame.locator('#modal > div[aria-hidden=true]')).to_be_visible()
                        form.locator('[data-product-image-file]').set_input_files([{'name':'retry.png','mimeType':'image/png','buffer':png}])
                        form.locator('[type=submit]').click()
                        expect(form).to_be_hidden()
                        with sqlite3.connect(db_path) as db:
                            assert db.execute('SELECT COUNT(*) FROM inv_products WHERE code=?',('NEW-TEST',)).fetchone()[0]==1
                            assert db.execute('SELECT COUNT(*) FROM inv_images WHERE product_id=?',(created_id,)).fetchone()[0]==3
                        expect(frame.locator('.product-brand-full').filter(has_text=custom_brand)).to_be_visible()
                        frame.locator('.catalog-image-list img').first.hover()
                        expect(frame.locator('body > div[aria-hidden=true]')).to_be_visible()
                        page.screenshot(path=str(REPORT/'catalog-custom-brand-preview.png'))
                        print('PASS custom brand, create multi-images, partial failure retry without duplicate product, modal and bottom hover')
                        with sqlite3.connect(db_path) as db:
                            assert db.execute('SELECT active,name FROM inv_products WHERE id=?',(created_id,)).fetchone()==(1,'DEAOE 自定义完整产品名称 CZBJ4Z【816】不锈钢四层直网'),created.value.request.post_data
                        expect(frame.locator('[data-catalog-row="'+created_id+'"]')).to_be_visible()
                        page.reload()
                        expect(frame.locator('[data-catalog-row="'+created_id+'"]')).to_be_visible()
                        page.goto(base+'/service/inventory#products')
                        page.wait_for_url(base+'/service/#inventory/products')
                        expect(frame.locator('[data-catalog-row="'+created_id+'"]')).to_be_visible()
                        for route in ['sale','purchase','receipt','balances']:
                            page.goto(base+'/service/inventory#'+route)
                            page.wait_for_url(base+'/service/#inventory/'+route)
                            expect(page.locator('#inventory-workspace-frame')).to_be_visible()
                        page.goto(base+'/service/#stock')
                        page.wait_for_url(base+'/service/#inventory/balances')
                        expect(frame.locator('#main')).to_contain_text('库存')
                        child=page.locator('#inventory-workspace-frame').element_handle().content_frame()
                        child.evaluate("()=>{const a=document.createElement('a');a.href='/service/#applications';document.body.append(a);a.click();a.remove();}")
                        page.wait_for_url(base+'/service/#applications')
                        page.goto(base+'/service/#inventory/numbering')
                        expect(frame.locator('#main')).to_contain_text('编号管理')
                        expect(frame.locator('#main')).to_contain_text('固定规则')
                        frame.locator('[data-number-rule="CG"]').click()
                        rule=frame.locator('#number-rule-form')
                        rule.locator('[name=prefix]').fill('PO')
                        rule.locator('[name=digits]').fill('4')
                        rule.locator('[type=submit]').click()
                        expect(rule).to_be_hidden()
                        expect(frame.locator('#main')).to_contain_text('PO')
                        frame.locator('[data-code-rule]').click()
                        expect(frame.locator('#code-rule-form')).to_be_visible()
                        frame.locator('#modal [data-action=close]').click()
                        page.goto(base+'/service/#inventory/operations')
                        frame.locator('[data-action="p2-new"][data-id="transfer"]').click()
                        transfer=frame.locator('#p2-form')
                        expect(transfer).to_have_attribute('data-number-ready','true')
                        transfer_number=transfer.locator('[name=reserved_number]').input_value()
                        assert transfer_number.startswith('DB-')
                        transfer.locator('[name=warehouse_id]').select_option('TEST-W')
                        transfer.locator('[name=to_warehouse]').select_option('TEST-W2')
                        transfer.locator('[name=product_id]').select_option('TEST-PART')
                        transfer.locator('[name=note]').fill('Synthetic transfer draft')
                        transfer.locator('[type=submit]').click()
                        expect(transfer).to_be_hidden()
                        with sqlite3.connect(db_path) as db:
                            assert db.execute('SELECT id FROM inv_ops WHERE id=?',(transfer_number,)).fetchone()==(transfer_number,)
                        with sqlite3.connect(db_path) as db:
                            db.execute('DELETE FROM inv_product_categories WHERE product_id=?',(created_id,))
                            db.execute('DELETE FROM inv_products WHERE id=?',(created_id,))
                        print('PASS newly created product stays enabled and visible after reload')
                    assert not errors,errors
                    page.close()
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=5)
print('Report:',REPORT)
