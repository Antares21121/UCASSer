#!/usr/bin/env python3
"""Real install/persistence/backup/isolated restore. Run only on a fresh dev workspace."""
import argparse
import importlib.util
import json
from pathlib import Path
import secrets
import shutil
import sys
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('forum', ROOT / 'scripts/forum.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def args(backend, root):
    return argparse.Namespace(backend=backend, profile='development', env_file=str(root / '.env'), archive=None, target_db=None, confirm=None)


def wait_health(instance):
    last_error = None
    for attempt in range(30):
        try:
            instance.health()
            return
        except (OSError, RuntimeError, ValueError, urllib.error.URLError) as error:
            last_error = error
            time.sleep(1)
    raise RuntimeError(f'Health never became ready: {last_error}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['native', 'docker'], required=True)
    parser.add_argument('--resume', action='store_true', help='Resume only a previous smoke-created development instance')
    options = parser.parse_args()
    original = module.Forum(args(options.backend, ROOT))
    if (ROOT / 'config.php').exists() and not options.resume:
        raise ValueError('Smoke test requires a fresh development instance, refusing existing config')
    if options.resume and not (ROOT / '.runtime/admin-initial-password.txt').exists():
        raise ValueError('Cannot resume an instance not created by this smoke test')
    original.validate()
    if original.values.get('APP_ENV') != 'development':
        raise ValueError('Smoke test is development-only')
    original.start()
    if not options.resume:
        original.php('scripts/db.php', 'empty')
        password = secrets.token_urlsafe(32)
        original.install_with_admin('phase0_admin', 'phase0-admin@example.invalid', password)
        module.write_private(ROOT / '.runtime/admin-initial-password.txt', password + '\n')
    wait_health(original)
    token = secrets.token_hex(20)
    # Fixture exercises a real persistent Flarum setting and an uploaded asset.
    php = '$c=require "config/environment.php";$d=$c["database"];$p=new PDO("mysql:host=".$d["host"].";port=".$d["port"].";dbname=".$d["database"],$d["username"],$d["password"]);'
    insert = php + '$s=$p->prepare("INSERT INTO `".$d["prefix"]."settings` (`key`,`value`) VALUES (?,?)");$s->execute(["phase0_verification","' + token + '"]);'
    if options.resume:
        query = php + '$s=$p->query("SELECT value FROM `".$d["prefix"]."settings` WHERE `key`=\'phase0_verification\'");echo $s->fetchColumn();'
        token = original.php('-r', query, capture=True).decode().strip()
        if not token:
            raise ValueError('No previous smoke fixture; do not modify an unrelated instance')
    else:
        original.php('-r', insert)
    fixture = b'Phase 0 uploaded file persistence: ' + token.encode()
    asset_name = 'public/assets/phase0-probe.txt'
    if options.backend == 'native':
        (ROOT / asset_name).write_bytes(fixture)
        (ROOT / 'public/assets/phase0-denied.php').write_text('<?php echo "must-not-execute";')
    else:
        original.compose('exec', '-T', 'app', 'sh', '-c', 'cat > ' + asset_name, stdin=fixture)
        original.compose('exec', '-T', 'app', 'sh', '-c', 'cat > public/assets/phase0-denied.php', stdin=b'<?php echo "must-not-execute";')
    original.stop()
    original.start()
    wait_health(original)

    def verify(instance):
        administrator_password = (ROOT / '.runtime/admin-initial-password.txt').read_text().strip()
        request = urllib.request.Request(instance.values['APP_URL'] + '/api/token',
                                         data=json.dumps({'identification': 'phase0_admin', 'password': administrator_password}).encode(),
                                         headers={'Content-Type': 'application/json'}, method='POST')
        with urllib.request.urlopen(request, timeout=15) as response:
            result = json.load(response)
            assert response.status == 200 and result.get('token'), 'Administrator login failed'
        query = php + '$s=$p->query("SELECT value FROM `".$d["prefix"]."settings` WHERE `key`=\'phase0_verification\'");echo $s->fetchColumn();'
        assert instance.php('-r', query, capture=True).decode().strip() == token, 'Database setting mismatch'
        with urllib.request.urlopen(instance.values['APP_URL'] + '/assets/phase0-probe.txt', timeout=10) as response:
            assert response.read() == fixture, 'Uploaded asset mismatch'
        for path in ('/.env', '/config.php', '/composer.json', '/storage/logs', '/assets/phase0-denied.php'):
            try:
                urllib.request.urlopen(instance.values['APP_URL'] + path, timeout=10)
            except urllib.error.HTTPError as error:
                assert error.code in (403, 404), f'Sensitive path status {path}: {error.code}'
            else:
                raise AssertionError('Sensitive path accessible: ' + path)

    verify(original)
    print('PASS: database and asset survive a real restart; private paths denied', flush=True)
    original.php('scripts/campus.php','enable')
    original.php('flarum','migrate')
    original.php('flarum','campus:setup')
    original.php('scripts/campus.php','seed')
    original.php('flarum','cache:clear')
    def campus_state(instance):
        with urllib.request.urlopen(instance.values['APP_URL']+'/api/campus/home',timeout=15) as response:
            home=json.load(response)['data']
        assert len(home['sections'])==10 and home['latest'], 'Business extension missing after restore'
        with urllib.request.urlopen(instance.values['APP_URL']+'/api/campus/governance',timeout=15) as response:
            stats=json.load(response)['data']['statistics']
        return {'sections':[(s['id'],s['key']) for s in home['sections']], 'statistics':stats}
    expected_campus=campus_state(original)
    backup = original.backup()
    wait_health(original)
    # Isolated workspace, separate DB/server port or Compose project/volumes.
    target = ROOT / ('.runtime/restore-verification-' + secrets.token_hex(4))
    if target.exists():
        raise ValueError('Restore verification target exists; choose a fresh workspace, never delete prior data automatically')
    target.mkdir()
    for path in ('composer.json', 'composer.lock', 'site.php', 'flarum', 'extend.php', 'compose.yaml', 'compose.production.yaml', '.env.example', '.dockerignore', '.nginx.conf'):
        shutil.copy2(ROOT / path, target / path)
    for folder in ('config', 'scripts', 'docker', 'extensions'):
        shutil.copytree(ROOT / folder, target / folder, ignore=shutil.ignore_patterns('__pycache__','node_modules'))
    shutil.copytree(ROOT / 'public', target / 'public', ignore=shutil.ignore_patterns('assets'))
    (target / 'public/assets/avatars').mkdir(parents=True)
    (target / 'storage').mkdir()
    if options.backend == 'native':
        shutil.copytree(ROOT / 'vendor', target / 'vendor')
    values = dict(original.values)
    values.update(DB_NAME='phase0_restore_' + secrets.token_hex(4), COMPOSE_PROJECT_NAME='phase0-restore-' + secrets.token_hex(4),
                  HTTP_PORT='8081', APP_URL='http://127.0.0.1:8081', NATIVE_DB_PORT='3308',
                  DB_PASSWORD=secrets.token_hex(24), DB_ROOT_PASSWORD=secrets.token_hex(24))
    module.write_env(target / '.env', values)
    saved_root = module.ROOT
    module.ROOT = target
    restored = module.Forum(args(options.backend, target))
    module.ROOT = saved_root
    restored.tools = original.tools
    restored.args.archive = str(backup)
    restored.args.target_db = values['DB_NAME']
    restored.args.confirm = values['DB_NAME']
    try:
        if options.backend == 'docker':
            restored.setup()
        restored.start()
        source=target/'extensions/campus/extend.php'
        exact_source=source.read_bytes()
        source.write_bytes(exact_source+b'\n// development restore mismatch probe\n')
        try:
            restored.restore()
        except ValueError as error:
            assert 'Extension source mismatch' in str(error)
            print('PASS: mismatched local extension refused before database import',flush=True)
        else:
            raise AssertionError('Mismatched source unexpectedly restored')
        finally:
            source.write_bytes(exact_source)
        restored.restore()
        wait_health(restored)
        verify(restored)
        assert campus_state(restored)==expected_campus, 'Restored business sections mismatch'
        # Safety check: a second restore into the installed DB must be refused.
        try:
            restored.restore()
        except ValueError:
            print('PASS: repeat restore refused before importing', flush=True)
        else:
            raise AssertionError('Repeat restore unexpectedly succeeded')
    finally:
        restored.stop()
    verify(original)
    report = {'backend': options.backend, 'flarum': original.core_version(), 'result': 'passed', 'checks': ['resumed smoke-created instance' if options.resume else 'fresh install', 'administrator login API', 'HTTP API and homepage', 'restart database persistence', 'upload persistence', 'private paths denied including existing uploaded PHP', 'real SQL and file backup including local extension', 'new isolated database restore and business statistics', 'extension source mismatch refusal before import', 'restore overwrite refusal', 'original instance unchanged'], 'backup': backup.name, 'restore_workspace': target.name}
    module.write_private(ROOT / '.runtime/verification.json', json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print('PASS: isolated restore matches database and uploaded bytes; original still healthy')
    print('Development administrator password: .runtime/admin-initial-password.txt (never printed)')


if __name__ == '__main__':
    main()
