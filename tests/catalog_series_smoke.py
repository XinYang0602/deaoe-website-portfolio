"""Category-specific series controls, using disposable local fixtures only."""
import json
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
from preview_website import prepare, command

report = Path(tempfile.mkdtemp(prefix='deaoe-series-report-'))
with tempfile.TemporaryDirectory() as tmp:
    work = Path(tmp)
    env, router = prepare(work, 'local-test-only')
    items = {}
    special = '特别 & "净化" <系列> +'
    for category in ('hood', 'hob', 'heater', 'other'):
        for index, series in enumerate(('标准系列', special)):
            key = f'{category}-{index}'
            items[key] = {'live': dict(category=category, series=series, main='/deaoe-brand-logo.png', name=key, model=key, summary='本地测试产品')}
    (work / 'data/product-catalog.json').write_text(json.dumps({'items': items}))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (report / 'server.log').open('w') as log:
        server = subprocess.Popen(command(work, port, router), env=env, stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    urllib.request.urlopen(base, timeout=1)
                    break
                except OSError:
                    time.sleep(.1)
            with sync_playwright() as pw:
                browser = pw.chromium.launch(channel='chrome')
                for width in (1440, 768, 601, 600, 390, 320):
                    page = browser.new_page(viewport={'width': width, 'height': 900})
                    page.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base+'/') else r.abort())
                    for category in ('hood', 'hob', 'heater'):
                        page.goto(base+'/?products=1&category='+category)
                        expect(page.locator('#series')).to_have_count(0)
                        links = page.locator('.catalog-series a')
                        expect(links).to_have_count(3)
                        expect(page.locator('.catalog-series [aria-current=page]')).to_have_text('全部系列')
                        expect(page.locator('.product-card')).to_have_count(2)
                        links.filter(has_text=special).click()
                        expect(page.locator('.product-card')).to_have_count(1)
                        expect(page.locator('.product-card h2')).to_have_text(category+'-1')
                        expect(page.locator('.catalog-series .active')).to_have_text(special)
                        assert page.locator('.catalog-series .active').evaluate('(e)=>getComputedStyle(e).color') == 'rgb(181, 31, 37)'
                        page.reload()
                        expect(page.locator('.catalog-series .active')).to_have_text(special)
                        page.locator('.catalog-series a').first.focus()
                        page.keyboard.press('Enter')
                        expect(page.locator('.product-card')).to_have_count(2)
                        page.go_back()
                        expect(page.locator('.catalog-series .active')).to_have_text(special)
                        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                    page.wait_for_load_state('networkidle')
                    if width <= 600:
                        expect(page.locator('aside h2')).not_to_be_visible()
                        expect(page.get_by_role('link', name='重置', exact=True)).to_be_visible()
                        page.get_by_role('link', name='重置', exact=True).click()
                        expect(page.locator('.catalog-series .active')).to_have_text('全部系列')
                        expect(page.locator('.product-card')).to_have_count(2)
                        assert 'category=heater' in page.url
                        grid = page.locator('.product-grid').bounding_box()
                        categories = page.locator('.categories').bounding_box()
                        assert abs(grid['width'] - (width - 32)) < 1
                        assert categories['y'] < page.locator('aside').bounding_box()['y']
                        assert page.locator('.catalog-series a').evaluate_all('(es)=>es.every(e=>e.getBoundingClientRect().height>=44)')
                        assert page.locator('.categories').evaluate('(e)=>getComputedStyle(e).flexDirection') == 'row'
                        page.locator('.categories a').last.click()
                        expect(page.locator('#series')).to_be_visible()
                        page.goto(base+'/?products=1&category=heater')
                    else:
                        expect(page.get_by_role('link', name='重置', exact=True)).not_to_be_visible()
                        # Removing only the new styles must leave desktop pixel-identical.
                        original = page.locator('head style').text_content()
                        before = page.screenshot(full_page=True)
                        page.locator('head style').evaluate('(e, css)=>e.textContent=css', original.split('/* catalog-mobile-filters-v1 */')[0] + '.catalog-mobile-reset{display:none}')
                        assert page.screenshot(full_page=True) == before, 'Desktop layout changed'
                        page.locator('head style').evaluate('(e, css)=>e.textContent=css', original)
                    page.screenshot(path=str(report / f'series-{width}.png'), full_page=True)
                    for suffix in ('', '&category=other'):
                        page.goto(base+'/?products=1'+suffix)
                        expect(page.locator('#series')).to_be_visible()
                        expect(page.locator('.catalog-series')).to_have_count(0)
                        page.locator('#series').select_option(special)
                        page.get_by_role('button', name='应用筛选').click()
                        expect(page.locator('#series')).to_have_value(special)
                        expect(page.locator('.product-card')).to_have_count(1 if suffix else 4)
                    page.close()
                    print(f'PASS {width}px category options, filtering, escaping, highlight, keyboard, history, dropdown and overflow')
                browser.close()
        finally:
            server.terminate()
            server.wait(timeout=10)
    assert 'PHP Warning' not in (report / 'server.log').read_text()
    assert 'PHP Fatal' not in (report / 'server.log').read_text()
print('Report:', report)
