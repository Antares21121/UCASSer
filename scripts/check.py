#!/usr/bin/env python3
"""Static checks that correspond to real project files. No runtime required."""
import ast
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from forum import read_env


def main():
    for path in list((ROOT / 'scripts').glob('*.py')) + list((ROOT / 'tests').glob('*.py')):
        ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
    for path in (ROOT / 'composer.json', ROOT / 'scripts/runtime-lock.json', ROOT / 'composer.lock'):
        json.loads(path.read_text())
    import yaml  # CI/developer check dependency only, not needed by launcher.
    for path in [ROOT / 'compose.yaml', ROOT / 'compose.production.yaml', ROOT / '.github/workflows/ci.yaml']:
        with path.open(encoding='utf-8') as file:
            yaml.safe_load(file)
    values = read_env(ROOT / '.env.example')
    assert not values['DB_PASSWORD'] and not values['DB_ROOT_PASSWORD'] and not values['MAIL_PASSWORD']
    tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    forbidden = [path for path in tracked if path == '.env' or path.startswith(('.tools/', '.runtime/', 'backups/', 'vendor/')) or (path.startswith('.env.') and path != '.env.example') or path in ('config.php', 'auth.json') or path.endswith(('.sql', '.key', '.pem'))]
    if forbidden:
        raise ValueError('Sensitive/generated files are tracked: ' + ', '.join(forbidden))
    for sample in ('.env', '.env.production', '.tools/php/php.exe', '.runtime/db/ibdata1', 'backups/forum.zip', 'config.php', 'auth.json', 'storage/logs/flarum.log', 'public/assets/avatars/example.png'):
        subprocess.run(['git', 'check-ignore', '-q', '--no-index', sample], cwd=ROOT, check=True)
    result = subprocess.run(['git', 'check-ignore', '-q', '--no-index', 'composer.lock'], cwd=ROOT)
    assert result.returncode == 1, 'composer.lock must be versioned'
    print('PASS: Python AST, JSON, YAML, env template and Git sensitive-file exclusions')


if __name__ == '__main__':
    main()
