#!/usr/bin/env python3
"""Run local source checks and regressions; never executes archived installers."""
import ast
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def run(*args):
    subprocess.run(args, cwd=ROOT, check=True)


def main():
    for path in sorted((ROOT / 'src').rglob('*.php')):
        subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
    for path in sorted((ROOT / 'src').rglob('*.js')):
        run('node', '--check', str(path))
    for folder in ('tools', 'tests'):
        for path in (ROOT / folder).glob('*.py'):
            ast.parse(path.read_text(), filename=str(path))
    for path in (ROOT / 'tests').glob('*.sh'):
        run('bash', '-n', str(path))
    run('bash', 'tests/run-sales-regression.sh')
    run('bash', 'tests/run-purchase-regression.sh')
    for name in ('document-number.php', 'numbering-rules.php', 'sale-multiwarehouse.php', 'finance-ledger.php', 'finance-four.php', 'finance-accounts.php', 'quantity-adjustments.php', 'mini-members.php', 'mini-rate-limits.php'):
        run('php', 'tests/'+name)
    for path in sorted((ROOT / 'tests').glob('*.cjs')):
        run('node', str(path))
    print('PASS source syntax and business regressions. Browser tests are separate.')


if __name__ == '__main__':
    main()
