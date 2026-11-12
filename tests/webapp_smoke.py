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
            with sync_playwright() as pw:
                browser = pw.chromium.launch(channel='chrome', headless=True)
                try:
                    for name, width, height in [('desktop', 1440, 1000), ('mobile', 390, 844)]:
                        context = browser.new_context(viewport={'width': width, 'height': height})
                        # Block accidental external requests from the isolated copy.
                        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base + '/') else route.abort())
                        page = context.new_page()
                        errors = []
                        page.on('pageerror', lambda error: errors.append(str(error)))
                        try:
                            page.goto(base + '/service/')
                            page.locator('[name=password]').fill('local-test-password')
                            page.get_by_role('button', name='登录管理平台').click()
                            page.wait_for_load_state('networkidle')
                            expect(page.locator('#logout-form')).to_be_attached()
                            page.goto(base + '/service/#inventory/sale')
                            frame = page.frame_locator('#inventory-workspace-frame')
                            expect(frame.get_by_role('heading', name='销售单', exact=True)).to_be_visible()
                            page.on('dialog', lambda dialog: dialog.accept())
                            if name == 'desktop':
                                page.locator('#nav a[data-section="sales"]').hover()
                                expect(page.locator('#inventory-flyout').get_by_role('link', name='销售单', exact=False)).to_be_visible()
                                page.locator('#inventory-flyout').get_by_role('link', name='销售开单', exact=False).click()
                            else:
                                frame.locator('[data-action="new-doc"][data-id="sale"]:visible').click()
                            form = frame.locator('#doc-form')
                            form.get_by_role('button', name='保存草稿', exact=True).click()
                            expect(form.locator('.form-error')).to_contain_text('请选择客户')
                            if name == 'mobile':
                                controls = [form.locator('.sales-customer-trigger'), form.locator('[name=date]'), form.locator('[name=delivery_phone]'), form.locator('[name=delivery_contact]'), form.locator('[name=delivery_address]')]
                                boxes = [c.bounding_box() for c in controls]
                                assert max(b['x'] for b in boxes)-min(b['x'] for b in boxes) < 2
                                assert max(b['height'] for b in boxes)-min(b['height'] for b in boxes) < 2
                                assert max(b['width'] for b in boxes)-min(b['width'] for b in boxes) < 2
                            expect(form.get_by_role('button', name='选择产品', exact=True)).to_be_visible()
                            assert form.locator('.product-choose').evaluate('(el) => parseFloat(getComputedStyle(el).fontSize)') >= 14
                            form.get_by_role('button', name='添加商品行', exact=True).click()
                            expect(form.get_by_role('button', name='选择产品', exact=True)).to_have_count(2)
                            assert form.locator('.product-choose').last.evaluate('(el) => parseFloat(getComputedStyle(el).fontSize)') >= 14
                            form.locator('.line').last.get_by_role('button', name='移除').click()
                            page.screenshot(path=str(REPORT / f'{name}-empty-product.png'), full_page=True)
                            form.locator('.product-choose').click()
                            products = frame.locator('.sales-product-picker')
                            products.get_by_role('searchbox', name='搜索商品', exact=True).fill('TEST-P')
                            products.locator('[data-filter=category]').select_option('整机')
                            expect(products.locator('.picker-product-row')).to_have_count(1)
                            products.locator('[data-product-id="TEST-P"]').click()
                            item = frame.locator('.sales-item-sheet')
                            item.locator('[name=store]').select_option('TEST-W')
                            item.locator('[name=unit]').fill('25')
                            item.locator('[name=discount]').fill('0.5')
                            expect(item.locator('[name=amount]')).to_have_value('12.50')
                            item.get_by_role('button', name='确认商品').click()
                            products.locator('[data-product-id="TEST-P"]').click()
                            item.locator('[name=store]').select_option('TEST-W2')
                            item.get_by_role('button', name='确认商品').click()
                            products.get_by_role('button', name='选好了').click()
                            expect(form.locator('.line')).to_have_count(2)
                            expect(form.locator('.line').last).to_have_attribute('data-warehouse','TEST-W2')
                            form.locator('.line').last.get_by_role('button', name='移除').click()
                            expect(form.locator('[name=product]')).to_have_value('TEST-P')
                            expect(form.locator('[name=price]')).to_have_value('12.50')
                            form.locator('.product-choose').click()
                            item.locator('[name=unit]').fill('99')
                            item.get_by_role('button', name='取消', exact=True).click()
                            expect(form.locator('[name=price]')).to_have_value('12.50')
                            form.locator('.product-choose').click()
                            item.locator('[name=amount]').fill('12.50')
                            page.screenshot(path=str(REPORT / f'{name}-product-item.png'), full_page=True)
                            item.get_by_role('button', name='确认商品').click()
                            expect(form.locator('[name=serials]')).to_have_count(0)
                            form.locator('[name=note]').fill('browser-' + name)
                            form.locator('.sales-customer-trigger').click()
                            picker = frame.locator('.sales-customer-picker')
                            picker.get_by_role('button', name='返回销售单').click()
                            expect(picker).to_have_count(0)
                            expect(form.locator('[name=serials]')).to_have_count(0)
                            form.locator('.sales-customer-trigger').click()
                            picker.locator('.customer-search').fill('000000')
                            expect(picker.locator('.customer-result')).to_have_count(1)
                            expect(picker.locator('.customer-result strong')).to_contain_text('000000　测试客户')
                            page.screenshot(path=str(REPORT / f'{name}-compact-customer.png'), full_page=True)
                            picker.locator('.customer-result').click()
                            expect(form.locator('[name=party_id]')).to_have_value('TEST-C')
                            expect(form.locator('.form-error')).not_to_contain_text('请选择客户')
                            # Returning from customer selection must retain the unsaved lines.
                            expect(form.locator('[name=serials]')).to_have_count(0)
                            form.locator('.sales-customer-trigger').click()
                            picker.locator('.customer-new').click()
                            picker.locator('[name=name]').fill('新建测试客户-' + name)
                            picker.locator('[name=phone]').fill('000001')
                            picker.get_by_role('button', name='保存并选择').click()
                            expect(picker).to_have_count(0)
                            expect(form.locator('.sales-customer-trigger')).to_contain_text('新建测试客户-' + name)
                            form.locator('[name=delivery_address]').fill('测试本单送货地址')
                            page.screenshot(path=str(REPORT / f'{name}-delivery-form.png'), full_page=True)
                            form.get_by_role('button', name='保存草稿', exact=True).click()
                            modal = frame.locator('#modal')
                            expect(modal.get_by_role('heading')).to_contain_text('草稿')
                            with sqlite3.connect(db_path) as db:
                                doc_id, status = db.execute("SELECT id,status FROM inv_docs WHERE note=?", ('browser-' + name,)).fetchone()
                                before = db.execute("SELECT qty FROM inv_balances WHERE product_id='TEST-P'").fetchone()[0]
                                assert status == 'draft'
                                assert db.execute('SELECT address FROM inv_delivery WHERE doc_id=?',(doc_id,)).fetchone()[0] == '测试本单送货地址'
                            expect(modal.locator('.delivery-print')).to_contain_text('测试本单送货地址')
                            modal.get_by_role('button', name='提交审核', exact=True).click()
                            expect(modal.get_by_role('heading')).to_contain_text('待审核')
                            modal.get_by_role('button', name='审核通过并生效', exact=True).click()
                            expect(modal.get_by_role('heading')).to_contain_text('已审核')
                            with sqlite3.connect(db_path) as db:
                                assert db.execute("SELECT qty FROM inv_balances WHERE product_id='TEST-P'").fetchone()[0] == before - 1
                                assert db.execute("SELECT status FROM inv_sn WHERE sn=?", ('TEST-SN-' + name,)).fetchone()[0] == 'sold'
                                assert db.execute("SELECT COUNT(*) FROM inv_docs WHERE source_id=? AND kind='issue'", (doc_id,)).fetchone()[0] == 1
                            page.screenshot(path=str(REPORT / f'{name}-sale.png'), full_page=True)
                            modal.get_by_role('button', name='关闭', exact=True).click()
                            if name == 'mobile':
                                expect(frame.locator('.sales-card').first).to_contain_text('已审核已出库')
                                expect(frame.locator('.sales-card').first).to_contain_text('XS-')
                                page.screenshot(path=str(REPORT / 'mobile-sales-register.png'), full_page=True)
                            page.goto(base + '/service/#inventory/finance')
                            frame = page.frame_locator('#inventory-workspace-frame')
                            expect(frame.get_by_role('heading', name='应收款 · 客户', exact=True)).to_be_visible()
                            frame.get_by_role('button',name='收付款账户设置',exact=True).click()
                            frame.get_by_role('button',name='新增账户',exact=True).click()
                            account_form=frame.locator('#finance-account-form')
                            account_form.locator('[name=name]').fill('测试现金-'+name)
                            account_form.locator('[name=type]').select_option('cash')
                            account_form.get_by_role('button',name='保存账户').click()
                            expect(frame.locator('#body')).to_contain_text('测试现金-'+name)
                            frame.locator('#body').get_by_role('button',name='关闭',exact=True).click()
                            frame.get_by_role('button', name='登记收款', exact=True).first.click()
                            frame.locator('#finance-form [name=amount]').fill('1.00')
                            frame.locator('#finance-form [name=account_id]').select_option(label='测试现金-'+name)
                            frame.locator('#finance-form [name=note]').fill('浏览器隔离测试')
                            frame.locator('#finance-form').get_by_role('button', name='确认登记').click()
                            expect(frame.locator('#modal')).not_to_be_visible()
                            expect(frame.get_by_text('浏览器隔离测试', exact=True)).to_have_count(1 if name == 'desktop' else 2)
                            # Exercise the four finance tabs through real forms and API-backed documents.
                            def finance_form(action, party_id, amount, reference=''):
                                frame.locator(f'[data-action="fin-{action}"][data-id="{party_id}"]').click()
                                form = frame.locator('#finance-form')
                                form.locator('[name=amount]').fill(amount)
                                if form.locator('[name=account_id]').count():
                                    form.locator('[name=account_id]').select_option(label='测试现金-'+name)
                                form.locator('[name=note]').fill('四类账款浏览器验证')
                                if reference:
                                    form.locator('[name=reference]').select_option(reference)
                                form.get_by_role('button', name='确认登记').click()
                                expect(frame.locator('#modal')).not_to_be_visible()
                            with sqlite3.connect(db_path) as db:
                                finance_customer = db.execute('SELECT party_id FROM inv_docs WHERE id=?', (doc_id,)).fetchone()[0]
                            frame.locator('[data-action="fin-tab"][data-id="advance_receipt"]').click()
                            finance_form('advance_receipt', finance_customer, '2')
                            finance_form('offset_receipt', finance_customer, '1', doc_id)
                            finance_form('return_advance_receipt', finance_customer, '1')
                            snap = context.request.get(base + '/service/api/inventory').json()
                            def api_action(payload):
                                response = context.request.post(base + '/service/api/inventory', data=payload, headers={'X-CSRF-Token': snap['csrf']})
                                assert response.status == 200, response.text()
                                return response.json()
                            import uuid
                            purchase_id = api_action({'action':'save_doc','kind':'purchase','warehouse_id':'TEST-W','party_id':'TEST-S','date':'2026-11-09','request_key':str(uuid.uuid4()),'lines':[{'product_id':'TEST-PART','qty':1,'price':'10'}]})['id']
                            for transition,version in [('submit',1),('approve',2)]:
                                api_action({'action':'transition','id':purchase_id,'transition':transition,'version':version})
                            snap = context.request.get(base + '/service/api/inventory').json()
                            source_line = next(l['id'] for l in snap['lines'] if l['doc_id']==purchase_id)
                            receipt_id = api_action({'action':'save_doc','kind':'receipt','source_id':purchase_id,'warehouse_id':'TEST-W','party_id':'TEST-S','date':'2026-11-09','request_key':str(uuid.uuid4()),'lines':[{'product_id':'TEST-PART','qty':1,'price':'10','source_line':source_line}]})['id']
                            for transition,version in [('submit',1),('approve',2)]:
                                api_action({'action':'transition','id':receipt_id,'transition':transition,'version':version})
                            page.reload()
                            frame = page.frame_locator('#inventory-workspace-frame')
                            frame.locator('[data-action="fin-tab"][data-id="advance_payment"]').click()
                            finance_form('advance_payment','TEST-S','5',purchase_id)
                            finance_form('offset_payment','TEST-S','3',receipt_id)
                            finance_form('return_advance_payment','TEST-S','2',purchase_id)
                            frame.locator('[data-action="fin-tab"][data-id="ap"]').click()
                            finance_form('payment','TEST-S','7',receipt_id)
                            expect(frame.locator('[data-action="fin-payment"][data-id="TEST-S"]')).to_have_count(0)
                            with sqlite3.connect(db_path) as db:
                                assert db.execute("SELECT COALESCE(SUM(cents),0) FROM inv_fin_aux WHERE party_id='TEST-S' AND bucket='ap'").fetchone()[0]==0
                                assert db.execute("SELECT COALESCE(SUM(cents),0) FROM inv_fin_aux WHERE party_id='TEST-S' AND bucket='advance_payment'").fetchone()[0]==0
                            assert frame.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth'), 'Finance mobile overflow'
                            for bucket in ('ar','advance_receipt','ap','advance_payment'):
                                if name == 'mobile':
                                    page.locator('.staff-mobile-tabs a[href="#applications"]').click()
                                    page.locator('#staff-mobile-page a[href="#inventory/finance_'+bucket+'"]').click()
                                else:
                                    page.goto(base+'/service/#inventory/finance_'+bucket)
                                frame=page.frame_locator('#inventory-workspace-frame')
                                expect(frame.locator('[data-action="fin-tab"][data-id="'+bucket+'"]').first).to_have_attribute('aria-pressed','true')
                                page.reload()
                                frame=page.frame_locator('#inventory-workspace-frame')
                                expect(frame.locator('[data-action="fin-tab"][data-id="'+bucket+'"]').first).to_have_attribute('aria-pressed','true')
                            frame.locator('[data-action="fin-tab"][data-id="ar"]').click()
                            if name=='mobile':
                                assert frame.locator('.finance-summary').evaluate_all('(es)=>es.map(e=>e.dataset.id)')==['ar','advance_receipt','ap','advance_payment']
                                boxes=frame.locator('.finance-summary').evaluate_all('(es)=>es.map(e=>e.getBoundingClientRect().top)')
                                assert max(boxes)-min(boxes)<2, 'Four balances should share one compact row'
                                assert frame.locator('.finance-summary').first.bounding_box()['height']<85
                                expect(frame.locator('.finance-details')).not_to_have_attribute('open','')
                                assert frame.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth')
                            page.screenshot(path=str(REPORT / f'{name}-finance.png'), full_page=True)
                            api_action({'action':'p2_create','kind':'transfer','request_key':str(uuid.uuid4()),'body':{'warehouse_id':'TEST-W','to_warehouse':'TEST-W2','product_id':'TEST-PART','qty':1,'note':'测试总览调拨'}})
                            # Shared receiving/purchase/stock registers keep their real actions and filters.
                            for route in ['purchase','receipt','issue','operations','counting','balances','lots','ledger','documents','parties','products']:
                                page.goto(base + '/service/#inventory/' + route)
                                frame = page.frame_locator('#inventory-workspace-frame')
                                expect(frame.locator('#main')).to_have_class(__import__('re').compile('compact-register'))
                                assert frame.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth')
                                if route in ['purchase','receipt','issue']:
                                    expect(frame.locator('#main tbody tr').first).to_be_visible()
                                    frame.locator('#main [data-action="doc"]').first.click()
                                    expect(frame.locator('#doc-print')).to_be_visible()
                                    frame.locator('#modal').get_by_role('button',name='关闭',exact=True).click()
                                if route == 'receipt':
                                    frame.locator('[data-register-filter="warehouse"]').select_option('TEST-W2')
                                    expect(frame.locator('.register-count')).to_have_text('共 0 条记录')
                                    expect(frame.locator('#main tbody tr')).to_have_count(0)
                                    with page.expect_download() as dl:
                                        frame.get_by_role('button',name='导出 Excel',exact=True).click()
                                    import zipfile
                                    with zipfile.ZipFile(dl.value.path()) as book:
                                        assert book.read('xl/worksheets/sheet1.xml').count(b'<row ') == 1
                                    frame.locator('[data-register-filter="warehouse"]').select_option('')
                                    frame.locator('[data-register-filter="status"]').select_option('approved')
                                    expect(frame.locator('#main tbody tr').first).to_be_visible()
                                    page.screenshot(path=str(REPORT / f'{name}-receipts-register.png'), full_page=True)
                                    page.emulate_media(media='print')
                                    assert frame.locator('#main tbody tr').first.evaluate('(el)=>getComputedStyle(el).display') == 'table-row'
                                    page.emulate_media(media='screen')
                                if route == 'operations':
                                    frame.get_by_role('button',name='刷新',exact=True).click()
                                    frame.locator('#main [data-action="p2-detail"]').first.click()
                                    expect(frame.locator('#doc-print')).to_contain_text('测试总览调拨')
                                    frame.locator('#modal').get_by_role('button',name='关闭',exact=True).click()
                                if route in ['parties','products']:
                                    page.screenshot(path=str(REPORT / f'{name}-{route}-compact.png'), full_page=True)
                                    if name == 'mobile':
                                        assert frame.locator('#main tbody tr').first.bounding_box()['height'] < 300
                                if route == 'balances':
                                    page.screenshot(path=str(REPORT / f'{name}-stock-register.png'), full_page=True)
                            page.goto(base + '/service/#customers')
                            expect(page.locator('.trade-customer').filter(has_text='新建测试客户-' + name)).to_be_visible()
                            expect(page.locator('.trade-customer').filter(has_text='测试供应商')).to_have_count(0)
                            page.locator('#search').fill('新建测试客户-' + name)
                            expect(page.locator('.trade-customer')).to_have_count(1)
                            item = page.locator('.trade-customer').first
                            assert item.bounding_box()['height'] <= 110
                            button_style = item.locator('.customer-view').evaluate('(el)=>({align:getComputedStyle(el).alignItems,justify:getComputedStyle(el).justifyContent})')
                            assert button_style == {'align':'center','justify':'center'}
                            item.locator('.customer-view').click()
                            expect(page.locator('.customer-profile')).to_contain_text('新建测试客户-' + name)
                            page.locator('#modal').get_by_role('button',name='关闭',exact=True).click()
                            page.locator('#search').fill('')
                            page.screenshot(path=str(REPORT / f'{name}-shared-customers.png'), full_page=True)
                            shared = context.request.get(base + '/service/api/inventory').json()
                            customer = next(p for p in shared['parties'] if p['id'] == 'TEST-C')
                            api_action({'action':'master','type':'parties',**customer,'active':True,'contact':'统一更新联系人'})
                            page.locator('#search').fill('测试客户')
                            page.locator('[data-customer-refresh]').click()
                            expect(page.locator('.trade-customer').filter(has_text='000000')).to_contain_text('统一更新联系人')
                            assert not errors, errors
                            response = context.request.get(base + '/service/api/inventory')
                            assert response.status == 200, response.status
                            print(f'PASS {name}: customer search/create, preserved draft, sale submit/approve, stock/SN deduction, API, no JS errors')
                        except Exception:
                            page.screenshot(path=str(REPORT / f'{name}-failure.png'), full_page=True)
                            (REPORT / f'{name}-errors.json').write_text(json.dumps(errors, ensure_ascii=False))
                            raise
                        finally:
                            context.close()
                finally:
                    browser.close()
        finally:
            server.terminate()
            server.wait(timeout=10)
            print(f'Report: {REPORT}')
