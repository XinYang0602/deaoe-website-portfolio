"""Synthetic desktop column chooser regression; no production access."""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

script=(Path(__file__).resolve().parents[1]/'src/service/column-widths.js').read_text()
html='''<meta charset="utf-8"><div style="overflow:auto;height:160px"><table><thead><tr><th>编号</th><th>客户</th><th>金额</th></tr></thead><tbody><tr><td>XS-001</td><td>Example</td><td>100.00</td></tr><tr><td colspan="3">合计</td></tr></tbody></table></div>'''
with sync_playwright() as pw:
    browser=pw.chromium.launch(channel='chrome',headless=True)
    page=browser.new_page(viewport={'width':1024,'height':600})
    views={}
    def route(r):
        if r.request.url.endswith('/api/column-widths'):
            if r.request.method=='POST':
                body=r.request.post_data_json
                views[body['key']]=body['widths']
                r.fulfill(json={'ok':True})
            else:r.fulfill(json={'views':views,'csrf':'synthetic'})
        else:r.fulfill(body=html,content_type='text/html')
    page.route('**/*',route)
    def open_list(name):
        page.goto('http://synthetic.test/service/#'+name)
        page.reload()
        page.add_script_tag(content=script)
        expect(page.locator('.column-menu')).to_have_count(1)
    open_list('sales')
    page.locator('summary').click()
    page.get_by_label('客户',exact=True).uncheck()
    expect(page.get_by_role('status')).to_have_text('已保存')
    expect(page.locator('th').nth(1)).to_be_hidden()
    expect(page.locator('tbody tr').first.locator('td').nth(1)).to_be_hidden()
    expect(page.get_by_role('cell',name='合计',exact=True)).to_have_attribute('colspan','2')
    page.locator('tbody').evaluate("el => el.insertAdjacentHTML('beforeend','<tr><td>NEW</td><td>Example 2</td><td>50</td></tr>')")
    expect(page.locator('tbody tr').last.locator('td').nth(1)).to_be_hidden()
    page.get_by_label('金额',exact=True).uncheck()
    page.get_by_label('编号',exact=True).click()
    expect(page.get_by_label('编号',exact=True)).to_be_checked()
    page.keyboard.press('Escape')
    expect(page.locator('details')).not_to_have_attribute('open','')
    open_list('sales')
    expect(page.locator('th').nth(1)).to_be_hidden()
    open_list('purchases')
    expect(page.locator('th').nth(1)).to_be_visible()
    open_list('sales')
    page.set_viewport_size({'width':390,'height':844})
    expect(page.locator('.column-menu')).to_have_count(0)
    expect(page.locator('th').nth(1)).to_be_visible()
    expect(page.get_by_role('cell',name='合计',exact=True)).to_have_attribute('colspan','3')
    page.set_viewport_size({'width':1024,'height':600})
    expect(page.locator('th').nth(1)).to_be_hidden()
    page.locator('summary').click()
    page.get_by_role('button',name='恢复默认').click()
    expect(page.get_by_role('status')).to_have_text('已保存')
    expect(page.locator('th').nth(1)).to_be_visible()
    browser.close()
print('PASS visibility: persistence, independent lists, cell spans, last-column guard, keyboard and mobile reset')
