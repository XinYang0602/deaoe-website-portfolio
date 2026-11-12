"""Exercise merged CMS with temporary data, uploads and loopback-only requests."""
import json
import re
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

report = Path(tempfile.mkdtemp(prefix='deaoe-website-report-'))
def check_filings(page):
    footer = page.locator('#deaoe-icp-filing')
    expect(footer).to_have_count(1)
    expect(footer.get_by_role('link', name='ICP filing (demo)')).to_have_attribute('href', 'https://beian.miit.gov.cn/')
    police = footer.get_by_role('link', name='Public security filing (demo)')
    expect(police).to_have_attribute('href', 'https://beian.mps.gov.cn/#/query/webSearch?code=00000000000000')
    assert police.locator('img').evaluate('(img) => img.complete && img.naturalWidth === 20')
    assert footer.evaluate('(el) => el.scrollWidth <= el.clientWidth')

with tempfile.TemporaryDirectory(prefix='deaoe-website-test-') as tmp:
    work = Path(tmp)
    password = 'temporary-local-cms-test'
    env, router = prepare(work, password)
    # Synthetic upload fixture, generated locally; no customer or production images.
    fixture = work / 'fixture.png'
    subprocess.run(['php', '-r', '$im=imagecreatetruecolor(64,64);imagefill($im,0,0,imagecolorallocate($im,180,180,180));imagepng($im,$argv[1]);', str(fixture)], check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (report / 'server.log').open('w') as log:
        server = subprocess.Popen(command(work, port, router), env=env, stdout=log, stderr=log)
        try:
            for _ in range(60):
                try:
                    urllib.request.urlopen(base, timeout=1)
                    break
                except OSError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Local PHP server did not start')
            with sync_playwright() as pw:
                browser = pw.chromium.launch(channel='chrome', headless=True)
                try:
                    for width in (1440, 390):
                        context = browser.new_context(viewport={'width': width, 'height': 900})
                        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base+'/') else route.abort())
                        page = context.new_page()
                        errors, bad_responses = [], []
                        page.on('pageerror', lambda error: errors.append(str(error)))
                        page.on('response', lambda response: bad_responses.append(response.url) if response.status >= 400 and not response.url.endswith('/deaoe-kitchen-hero.png') else None)
                        page.on('dialog', lambda dialog: dialog.accept())
                        page.goto(base)
                        check_filings(page)
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('h1')).to_contain_text('净烟有度')
                        assert '{{' not in page.content()
                        for section in page.locator('.reveal').all():
                            section.scroll_into_view_if_needed()
                            expect(section).to_have_class(re.compile(r'\bshow\b'))
                        page.evaluate('window.scrollTo(0,0)')
                        page.screenshot(path=str(report / f'home-{width}.png'), full_page=True)
                        page.goto(base+'/admin/?products=1')
                        page.wait_for_load_state('networkidle')
                        # Product admin must not bypass authentication.
                        expect(page.locator('input[name=password]')).to_be_visible()
                        page.locator('input[name=user]').fill('admin')
                        page.locator('input[name=password]').fill(password)
                        page.get_by_role('button', name='登录', exact=True).click()
                        page.wait_for_load_state('networkidle')
                        page.goto(base+'/admin/?products=1')
                        page.get_by_role('button', name='＋ 添加产品').click()
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('#specs')).to_have_count(0)
                        expect(page.locator('input[type=file][name=size]')).to_have_count(0)
                        page.locator('#name').fill(f'本地测试产品{width}')
                        page.locator('#model').fill(f'LOCAL-{width}')
                        page.locator('#main').set_input_files(str(fixture))
                        page.locator('#gallery_uploads').set_input_files(str(fixture))
                        page.locator('#detail_uploads').set_input_files(str(fixture))
                        page.get_by_role('button', name='保存草稿', exact=True).click()
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('body')).to_contain_text('草稿已保存')
                        page.get_by_role('button', name='发布上架', exact=True).click()
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('body')).to_contain_text('产品已上架')
                        store = json.loads((work / 'data/product-catalog.json').read_text())
                        product_id = next(k for k, v in store['items'].items() if v['live'] and v['live']['model']==f'LOCAL-{width}')
                        assert store['items'][product_id]['live']['main'].endswith('.webp')
                        page.goto(base+'/?products=1&category=hood')
                        check_filings(page)
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('.product-card').filter(has_text=f'LOCAL-{width}')).to_have_count(1)
                        if width == 390:
                            menu = page.locator('.catalog-menu-toggle')
                            menu.click()
                            expect(menu).to_have_attribute('aria-expanded', 'true')
                            page.keyboard.press('Escape')
                            expect(menu).to_have_attribute('aria-expanded', 'false')
                        page.goto(base+'/?product='+product_id)
                        check_filings(page)
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('body')).to_contain_text(f'LOCAL-{width}')
                        expect(page.locator('#specifications')).to_have_count(0)
                        assert page.locator('img').evaluate_all('(es)=>es.every(e=>!e.getAttribute("src") || (e.complete && e.naturalWidth>0))')
                        page.screenshot(path=str(report / f'product-{width}.png'), full_page=True)
                        page.goto(base+'/admin/?home_carousel=1')
                        page.wait_for_load_state('networkidle')
                        expect(page.get_by_role('button', name='发布全部轮播')).to_be_visible()
                        page.goto(base+'/admin/')
                        page.wait_for_load_state('networkidle')
                        expect(page.locator('select[name=hero_underline]')).to_be_visible()
                        assert not errors, errors
                        assert not bad_responses, bad_responses
                        context.close()
                        print(f'PASS website {width}px: login, draft, WebP upload, gallery/detail images, publish, catalog, product, menu, carousel and hero controls')
                finally:
                    browser.close()
        finally:
            server.terminate()
            server.wait(timeout=10)
    logs = (report / 'server.log').read_text()
    assert 'PHP Fatal error' not in logs and 'PHP Warning' not in logs, logs
print('Report:', report)
