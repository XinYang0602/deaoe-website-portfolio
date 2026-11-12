"""Render the actual PHP login locally; check focus layout without submitting credentials."""
import pathlib
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[1]
source = ROOT / 'src/service/index.php'
# No POST, authentication files, databases, or network requests are used.
html = subprocess.check_output(['php', '-r',
    '$_SERVER["REQUEST_URI"]="/service/";$_SERVER["REQUEST_METHOD"]="GET";require '+repr(str(source))+';'], text=True)
with sync_playwright() as pw:
    browser = pw.chromium.launch(channel='chrome', headless=True)
    for width, height in [(320,640),(375,667),(390,844),(430,932),(390,340),(1440,900)]:
        page = browser.new_page(viewport={'width':width,'height':height})
        page.route('**/*', lambda route: route.abort())
        page.set_content(html)
        for selector in ['[name=username]', '[name=password]']:
            field = page.locator(selector)
            field.focus()
            field.fill('synthetic-input')
            result = page.evaluate('''() => {
                const r=document.querySelector('.panel').getBoundingClientRect();
                return {left:r.left,right:r.right,center:(r.left+r.right)/2,
                  overflow:document.documentElement.scrollWidth>innerWidth,
                  font:parseFloat(getComputedStyle(document.activeElement).fontSize)};
            }''')
            assert not result['overflow'], (width,result)
            assert result['left']>=0 and result['right']<=width, (width,result)
            assert abs(result['center']-width/2)<1, (width,result)
            if width<=600:
                assert result['font']>=16, (width,result)
        if width==390 and height==844:
            page.screenshot(path=str(pathlib.Path(tempfile.gettempdir())/'deaoe-login-layout.png'),full_page=True)
        page.close()
    browser.close()
print('PASS: login centered before/after field focus across six viewports; mobile inputs >=16px. Physical iOS autofill is not emulated.')
