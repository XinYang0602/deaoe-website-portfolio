"""Test actual home template anchor placement with local synthetic images."""
from pathlib import Path
import re
import tempfile
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
html=(ROOT/'src/website/app/template.html').read_text()
svg='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="800" height="450"%3E%3Crect width="800" height="450" fill="gray"/%3E%3C/svg%3E'
# Encode quotes as well, since the template uses quoted attributes.
svg=svg.replace('"','%22')
html=re.sub(r'\{\{(\w+)\}\}',lambda m:svg if 'image' in m[1] else '本地测试内容',html)
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'index.html';path.write_text(html)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(channel='chrome')
        for width in (320,390,850,851,1024,1440,1920):
            page=browser.new_page(viewport={'width':width,'height':900})
            page.goto(path.as_uri());page.wait_for_load_state('networkidle')
            for target in ('hood','hob','heater','hood'):
                page.locator('.cats a[href="#'+target+'"]').click()
                page.wait_for_timeout(1300)
                image=page.locator('#'+target+'>img').bounding_box()
                header=page.locator('body>header').bounding_box()
                assert abs(image['y']-(header['y']+header['height']))<2,(width,target,image,header)
                assert image['y']+image['height']<=900,(width,target)
            page.close();print(f'PASS home anchors {width}px')
        browser.close()
