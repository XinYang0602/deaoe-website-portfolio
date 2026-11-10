"""Isolated real gallery rendering, native touch swipe, animation and reduced motion."""
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='deaoe-gallery-') as tmp:
    work = Path(tmp)
    for name in ('product-gallery.php', 'products.css'):
        (work/name).write_bytes((ROOT/'src/website/app'/name).read_bytes())
    for i, color in enumerate(('#a44', '#468', '#596')):
        (work/f'{i}.svg').write_text(f'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="{600+i*100}"><rect width="100%" height="100%" fill="{color}"/><text x="100" y="200" fill="white" font-size="80">PHOTO {i+1}</text></svg>')
    (work/'index.php').write_text('''<?php
function h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function productGalleryImages($p){return $p['gallery'];}
function productURL($s){return true;}
require 'product-gallery.php';
echo '<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="products.css"><main style="max-width:700px">';
$n=(int)($_GET['n']??3);
renderProductGallery(['main'=>$n?'/0.svg':'','gallery'=>$n>1?['/1.svg','/2.svg']:[],'name'=>'测试产品']);
echo '</main><div style="height:1500px">下方详情</div>';
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    with (work/'server.log').open('w') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=log,stderr=log)
        try:
            for _ in range(50):
                try: urllib.request.urlopen(base); break
                except OSError: time.sleep(.1)
            with sync_playwright() as pw:
                browser=pw.chromium.launch(channel='chrome')
                for width in (390,1440):
                    context=browser.new_context(viewport={'width':width,'height':900},has_touch=True,is_mobile=width==390)
                    page=context.new_page(); errors=[]
                    page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base); page.wait_for_load_state('networkidle')
                    track=page.locator('.carousel-track')
                    first_height=track.bounding_box()['height']
                    page.locator('.carousel-next').click()
                    page.wait_for_function('(()=>{const t=document.querySelector(".carousel-track"); return t.scrollLeft>0 && t.scrollLeft<t.clientWidth-1;})()')
                    expect(page.locator('.carousel-count')).to_have_text('2 / 3')
                    page.wait_for_timeout(600)
                    assert abs(track.bounding_box()['height']-first_height)<1
                    page.locator('.carousel-thumbs button').nth(2).click()
                    expect(page.locator('.carousel-count')).to_have_text('3 / 3')
                    page.wait_for_timeout(600)
                    page.locator('.carousel-next').click()
                    expect(page.locator('.carousel-count')).to_have_text('1 / 3')
                    page.wait_for_timeout(600)
                    page.locator('.carousel-stage').focus();page.keyboard.press('ArrowLeft')
                    expect(page.locator('.carousel-count')).to_have_text('3 / 3')
                    page.wait_for_timeout(600)
                    page.emulate_media(reduced_motion='reduce')
                    page.locator('.carousel-thumbs button').first.click()
                    expect(page.locator('.carousel-count')).to_have_text('1 / 3')
                    page.emulate_media(reduced_motion='no-preference')
                    if width==390:
                        cdp=context.new_cdp_session(page);box=track.bounding_box()
                        x=box['x']+box['width']*.8;y=box['y']+box['height']*.5
                        cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
                        for step in range(1,9):
                            cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':x-step*box['width']*.075,'y':y}]})
                            page.wait_for_timeout(25)
                        assert track.evaluate('(e)=>e.scrollLeft')>0, 'Image must follow finger before release'
                        cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
                        expect(page.locator('.carousel-count')).to_have_text('2 / 3')
                        page.wait_for_timeout(700)
                        assert track.evaluate('(e)=>Math.abs(e.scrollLeft-e.clientWidth)<2')
                        # Vertical gestures still scroll the page.
                        cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
                        cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':x,'y':y-120}]})
                        cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
                        page.wait_for_timeout(400)
                        assert page.evaluate('scrollY')>0
                    page.set_viewport_size({'width':width-30,'height':850});page.wait_for_timeout(400)
                    assert track.evaluate('(e)=>Math.abs(e.scrollLeft/e.clientWidth-Math.round(e.scrollLeft/e.clientWidth))<.01')
                    for n in (0,1):
                        page.goto(base+f'/?n={n}')
                        expect(page.locator('.carousel-next')).to_have_count(0)
                    assert not errors,errors
                    context.close();print(f'PASS gallery {width}px animation, navigation, reduced motion, resize, empty/single image')
                browser.close()
        finally:
            server.terminate();server.wait(timeout=10)
    assert 'PHP Warning' not in (work/'server.log').read_text()
