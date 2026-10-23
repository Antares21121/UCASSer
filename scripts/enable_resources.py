#!/usr/bin/env python3
"""Enable the bundled ucasser-resources extension in a development instance.

Flarum 2.x core has no `extension:enable` console command (framework/core/src/Console
contains only tinker/server entries); the supported programmatic path is the admin
API `PATCH /api/extensions/{id}` handled by UpdateExtensionController, which runs
migrations and publishes assets. The Flarum extension id is the composer package
name with `/` replaced by `-` (see Extension::getId): ucasser/resources ->
ucasser-resources.
"""

import argparse
import importlib.util
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXTENSION = 'ucasser-resources'
spec = importlib.util.spec_from_file_location('forum', ROOT / 'scripts/forum.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def wait_health(instance):
    last = None
    for _ in range(30):
        try:
            instance.health()
            return
        except (OSError, RuntimeError, ValueError, urllib.error.URLError) as error:
            last = error
            time.sleep(1)
    raise RuntimeError(f'health never became ready: {last}')


def call(url, method='GET', body=None, token=None, expected=200):
    data = json.dumps(body).encode() if body is not None else None
    headers = {'Content-Type': 'application/json'} if data is not None else {}
    if token:
        headers['Authorization'] = 'Token ' + token
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(request, timeout=30) as response:
            status, payload = response.status, response.read()
    except urllib.error.HTTPError as error:
        status, payload = error.code, error.read()
    if status != expected:
        raise AssertionError(f'{method} {url}: expected {expected}, got {status}: {payload[:400]!r}')
    return json.loads(payload) if payload else {}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--admin-user', required=True,
                        help='administrator username of the installed forum (tests/smoke.py installs phase0_admin)')
    parser.add_argument('--password-file', type=Path, required=True,
                        help='file holding the administrator password on its first line '
                             '(smoke.py writes .runtime/admin-initial-password.txt)')
    options = parser.parse_args()

    password_path = options.password_file if options.password_file.is_absolute() else ROOT / options.password_file
    if not password_path.exists():
        raise SystemExit(f'admin password file not found: {password_path}; smoke.py writes '
                         '.runtime/admin-initial-password.txt for the instances it installs')
    password = password_path.read_text(encoding='utf-8').strip()

    args = argparse.Namespace(backend=options.backend, profile='development', env_file=str(ROOT / '.env'),
                              archive=None, target_db=None, confirm=None)
    forum = module.Forum(args)
    forum.validate()
    forum.start()
    wait_health(forum)

    base = forum.values['APP_URL'].rstrip('/')
    token = call(base + '/api/token', 'POST', {'identification': options.admin_user, 'password': password})['token']
    call(base + '/api/extensions/' + EXTENSION, 'PATCH', {'enabled': True}, token, expected=204)

    categories = call(base + '/api/ucasser/categories')['categories']
    names = {item['name'] for item in categories}
    assert {'校内资料', '校外资料', '四六级', '计算机二级'} <= names, names
    print(f'enabled {EXTENSION}: {len(categories)} seeded categories present')


if __name__ == '__main__':
    sys.exit(main())
