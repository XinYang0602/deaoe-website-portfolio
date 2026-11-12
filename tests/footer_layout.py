"""Verify compact footer alignment using current home and product rendering."""
from pathlib import Path
import base64
import re
import subprocess
import tempfile
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
APP=ROOT/'src/website/app'
REPORT=Path(tempfile.mkdtemp(prefix='deaoe-footer-layout-'))
logo='data:image/png;base64,'+base64.b64encode((ROOT/'src/website/public/deaoe-brand-logo.png').read_bytes()).decode()
php='define("APP",$argv[1]);function h($s){return htmlspecialchars($s,ENT_QUOTES,"UTF-8");}require APP."/products-lib.php";productHeader("Demo");productFooter();'
product=subprocess.check_output(['php','-r',php,str(APP)],text=True)
home=re.sub(r'\{\{\w+\}\}','Demo',(APP/'template.html').read_text())
with sync_playwright() as pw:
    browser=pw.chromium.launch(channel='chrome')
    for name,html in [('home',home),('product',product)]:
        for width in (320,390,850,1024,1440,1920):
            page=browser.new_page(viewport={'width':width,'height':900})
            page.route('**/*',lambda route:route.abort())
            page.set_content(html.replace('/deaoe-brand-logo.png',logo))
            footer=page.locator('footer.deaoe-footer');footer.scroll_into_view_if_needed()
            boxes=page.locator('.deaoe-footer-row > *').evaluate_all('(els)=>els.map(el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height}})')
            assert all(b['x']>=-1 and b['x']+b['w']<=width+1 for b in boxes),(name,width,boxes)
            assert footer.evaluate('(el)=>el.scrollWidth<=el.clientWidth'),(name,width)
            if width>=1440:
                centers=[b['y']+b['h']/2 for b in boxes]
                assert max(centers)-min(centers)<2,(name,width,boxes)
                assert footer.bounding_box()['height']<=110,(name,width)
            assert page.locator('#deaoe-icp-filing a').count()==2
            for a in page.locator('#deaoe-icp-filing a').all():
                assert a.bounding_box()['height']>=44
            assert page.locator('#deaoe-icp-filing img').evaluate('(i)=>i.complete&&i.naturalWidth===20')
            if width in (390,1440):footer.screenshot(path=str(REPORT/f'{name}-{width}.png'))
            page.close();print(f'PASS {name} footer {width}px: alignment, containment, filing links and icon')
    browser.close()
print('Report:',REPORT)
