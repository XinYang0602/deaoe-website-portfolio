#!/usr/bin/env python3
"""Disposable local CMS preview. No production connections or persistent data."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def prepare(work, password):
    work = Path(work)
    shutil.copytree(ROOT / 'src/website', work / 'website')
    for name in ('data', 'sessions', 'website/public/uploads'):
        (work / name).mkdir(parents=True, exist_ok=True)
    hashed = subprocess.check_output(['php', '-r', 'echo password_hash(getenv("PREVIEW_PASSWORD"), PASSWORD_DEFAULT);'], env={**os.environ, 'PREVIEW_PASSWORD': password}, text=True)
    (work / 'data/account.json').write_text(json.dumps({'hash': hashed}))
    (work / 'data/account.json').chmod(0o600)
    env = {**os.environ, 'DEAOE_APP': str(work / 'website/app'), 'DEAOE_DATA': str(work / 'data'), 'DEAOE_UPLOADS': str(work / 'website/public/uploads')}
    router = work / 'router.php'
    router.write_text('''<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$public=__DIR__.'/website/public';
if(in_array($path,['/','/index.php'],true)){require $public.'/index.php';return true;}
if(in_array($path,['/admin/','/admin/index.php'],true)){require $public.'/admin/index.php';return true;}
if($path==='/admin'){header('Location: /admin/');return true;}
$file=realpath($public.rawurldecode($path));
if($file && str_starts_with($file,$public.'/') && is_file($file) && in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['png','jpg','jpeg','webp','svg','ico','css','js'],true)){return false;}
http_response_code(404);echo 'Local preview: resource unavailable';
''')
    return env, router


def command(work, port, router):
    return ['php', '-d', 'session.save_path='+str(work / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(work / 'website/public'), str(router)]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8765)
    args = parser.parse_args()
    password = secrets.token_urlsafe(12)
    with tempfile.TemporaryDirectory(prefix='deaoe-website-preview-') as tmp:
        work = Path(tmp)
        env, router = prepare(work, password)
        print(f'Local preview: http://127.0.0.1:{args.port}\nAdmin: /admin/\nUser: admin\nTemporary password: {password}\nData and uploads are temporary. Ctrl+C stops preview. Restart to load source edits.', flush=True)
        process = subprocess.Popen(command(work, args.port, router), env=env)
        try:
            process.wait()
        except KeyboardInterrupt:
            pass
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
        if process.returncode not in (0, -2, -15):
            raise SystemExit(process.returncode)


if __name__ == '__main__':
    main()
