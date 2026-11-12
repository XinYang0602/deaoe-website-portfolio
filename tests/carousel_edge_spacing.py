"""Check real carousel controls with reserved scrollbar space and synthetic slides."""
from pathlib import Path
import json
import re
import tempfile
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'src/website/app'
svg = lambda color: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="800" height="450"%3E%3Crect width="800" height="450" fill="'+color+'"/%3E%3C/svg%3E'
slides = [svg('gray'), svg('navy')]
html = re.sub(r'\{\{\w+\}\}', 'Demo', (APP/'template.html').read_text())
html += '<style>html{overflow-y:scroll;scrollbar-gutter:stable}body{min-height:2400px}::-webkit-scrollbar{width:20px}</style>'
html += '<style>'+(APP/'home-carousel.css').read_text()+'</style>'
html += '<script id="home-carousel-config" type="application/json">'+json.dumps({'interval':60,'groups':{'hero':slides}})+'</script>'
html += '<script>'+(APP/'home-carousel.js').read_text()+'</script>'
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'index.html';path.write_text(html)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(channel='chrome')
        for width in (320,390,850,1024,1440,1920):
            page=browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
            page.route('**/*',lambda route:route.abort() if route.request.url.startswith(('http:','https:')) else route.continue_())
            page.goto(path.as_uri())
            stage=page.locator('.hero .home-carousel-stage');stage.hover()
            prev=stage.get_by_role('button',name='上一张');nxt=stage.get_by_role('button',name='下一张')
            expect(nxt).to_be_visible()
            bounds=stage.bounding_box();left=prev.bounding_box();right=nxt.bounding_box()
            assert left['x']-bounds['x'] >= 15.9,(width,left,bounds)
            assert bounds['x']+bounds['width']-right['x']-right['width'] >= 15.9,(width,right,bounds)
            assert right['x']+right['width'] <= page.evaluate('document.documentElement.clientWidth')-15.9
            assert page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
            nxt.click()
            expect(stage.locator('img:not([hidden])')).to_have_count(1)
            expect(stage.locator('img:not([hidden])')).to_have_attribute('alt','产品场景 2')
            prev.focus();expect(prev).to_be_focused();page.keyboard.press('Enter')
            expect(stage.locator('img:not([hidden])')).to_have_count(1)
            expect(stage.locator('img:not([hidden])')).to_have_attribute('alt','产品场景 1')
            page.close();print(f'PASS carousel controls {width}px: inset, scrollbar gutter, next click, keyboard previous')
        browser.close()
