#!/usr/bin/env python3
"""Display the installed collector's fresh WeRead QR in the owner's private terminal."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
from urllib.parse import urlencode
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

from connect_collector import IMAGE, RESULT, SERVICE_EXEC, connection_plan, load_managed
from forum import read_env

MAX_RESPONSE = 1024 * 1024
QR_MATRIX = r'''
import json,qrcode,sys
from PIL import Image
x=json.load(sys.stdin)
qr=qrcode.QRCode(version=1,error_correction=qrcode.constants.ERROR_CORRECT_M,box_size=10,border=4)
qr.add_data(x['url']);qr.make(fit=True)
matrix=qr.get_matrix();size=len(matrix)
# Confirm the installed service generated exactly these modules. A changed
# upstream payload or concurrent QR replacement fails before anything is displayed.
image=Image.open('/app/static/weread_qrcode.png').convert('L')
assert image.size==(size*10,size*10)
assert all((image.getpixel((x*10+5,y*10+5))<128)==matrix[y][x] for y in range(size) for x in range(size))
print('UCASSER_RESULT:'+json.dumps(matrix))
'''


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never forward this collector's bearer token or password to another URL.
        return None


class CollectorAPI:
    def __init__(self, base, settings):
        if not re.fullmatch(r'http://127\.0\.0\.1:[0-9]{4,5}', base):
            raise ValueError('Collector must use its private loopback listener')
        self.base = base + '/api/v1/wx'
        self.opener = build_opener(ProxyHandler({}), NoRedirect())
        self.token = None
        form = urlencode({'username': settings['USERNAME'], 'password': settings['PASSWORD']}).encode()
        result = self.request('/auth/login', form, 'application/x-www-form-urlencoded')
        token = result.get('access_token')
        if not isinstance(token, str) or not token or len(token) > 16384:
            raise RuntimeError('Internal collector login failed')
        self.token = token

    def request(self, path, payload=None, content_type='application/json'):
        if path not in ('/auth/login', '/weread', '/weread/test', '/weread/qr/code', '/weread/qr/status', '/weread/qr/over'):
            raise ValueError('Unsupported authorization operation')
        headers = {'Accept': 'application/json'}
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        if payload is not None:
            headers['Content-Type'] = content_type
        with self.opener.open(Request(self.base + path, data=payload, headers=headers), timeout=60) as response:
            data = response.read(MAX_RESPONSE + 1)
        if len(data) > MAX_RESPONSE:
            raise RuntimeError('Unexpected collector response size')
        result = json.loads(data)
        if not isinstance(result, dict) or result.get('code') != 0 or not isinstance(result.get('data'), dict):
            # Upstream responses can contain cookies; never include their error text.
            raise RuntimeError('Collector authorization request did not succeed')
        return result['data']


def confirm_url(uid):
    if not isinstance(uid, str) or not re.fullmatch(r'[A-Za-z0-9_-]{8,256}', uid):
        raise ValueError('Collector returned an unsupported QR identifier')
    return 'https://weread.qq.com/web/confirm?uid=' + uid


def terminal_qr(matrix):
    size = len(matrix)
    if size < 29 or size > 105 or size % 2 != 1 or any(len(row) != size for row in matrix):
        raise ValueError('Unsupported QR dimensions')
    if any(type(value) is not bool for row in matrix for value in row):
        raise ValueError('Unsupported QR modules')
    # One column by half a character's height is square in a normal monospace terminal.
    # Explicit colors keep a white quiet zone even with a dark terminal theme.
    glyph = {(False, False): ' ', (True, False): '▀', (False, True): '▄', (True, True): '█'}
    blank = [False] * size
    return '\n'.join('\x1b[30;107m' + ''.join(glyph[a, b] for a, b in zip(matrix[y], matrix[y + 1] if y + 1 < size else blank)) + '\x1b[0m'
                     for y in range(0, size, 2))


def authorize(api, matrix_builder, *, scan=False, wait=240, output=print, columns=80,
              monotonic=time.monotonic, sleep=time.sleep):
    configured = api.request('/weread').get('mp_configured') is True
    if not scan:
        output(json.dumps({'state': 'configured_not_verified' if configured else 'authorization_required',
                           'qr_requested': False}))
        return 0
    if configured:
        # Validate saved credentials first. A valid session needs no replacement QR.
        try:
            if api.request('/weread/test', b'{}').get('ok') is True:
                output(json.dumps({'state': 'ready', 'authorization_verified': True, 'qr_requested': False}))
                return 0
        except Exception:
            pass
    qr = api.request('/weread/qr/code')
    try:
        # Recreate exactly the upstream qrcode payload in its installed environment.
        # No image URL, external image host or untrusted text is opened or printed.
        matrix = matrix_builder(confirm_url(qr.get('uid')))
        rendered = terminal_qr(matrix)
        if columns < len(matrix) + 2:
            raise ValueError('Terminal too narrow; enlarge the terminal and retry')
        output('请用手机微信扫描下方二维码，并在手机上确认微信读书登录。')
        output('请勿把二维码、终端截图或任何登录信息发到聊天里。二维码约五分钟后失效。')
        output(rendered)
        output('正在等待手机确认…', flush=True)
        deadline = monotonic() + wait
        while monotonic() < deadline:
            status = api.request('/weread/qr/status')
            if (status.get('data') or {}).get('logicCode') == 'COOKIE_INVALID':
                raise RuntimeError('WeRead rejected the fresh authorization; no subscription was added')
            if status.get('login_status') is True and api.request('/weread').get('mp_configured') is True:
                verified = api.request('/weread/test', b'{}').get('ok') is True
                if not verified:
                    raise RuntimeError('Fresh authorization has not passed the WeRead connection check')
                output(json.dumps({'state': 'ready', 'authorization_verified': True,
                                   'qr_requested': True, 'subscriptions_changed': False}))
                return 0
            sleep(2)
        output(json.dumps({'state': 'authorization_required', 'reason': 'scan_timeout', 'subscriptions_changed': False}))
        return 2
    finally:
        # Response includes private login data; discard it. Do not clear saved cookies.
        try:
            api.request('/weread/qr/over')
        except Exception:
            pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument('--env-file', default='.env.test')
    parser.add_argument('--collector', default='ucasser-we-mp-rss')
    parser.add_argument('--scan', action='store_true', help='Request a fresh QR if saved authorization is invalid or absent')
    args = parser.parse_args()
    if os.name != 'posix' or os.geteuid() != 0:
        parser.error('Run in the Linux server terminal with sudo')
    if args.scan and not sys.stdout.isatty():
        parser.error('Use an interactive terminal; QR output must not be redirected to a file or chat')
    root = Path(args.root).resolve()
    env = root / args.env_file
    if not env.is_file() or env.resolve().parent != root or (root / '.runtime').is_symlink():
        raise ValueError('Invalid forum environment or runtime directory')
    project = read_env(env).get('COMPOSE_PROJECT_NAME', '')

    def run(command, payload=None):
        result = subprocess.run(command, input=payload, stdin=subprocess.DEVNULL if payload is None else None,
                                capture_output=True, cwd=root, timeout=60)
        if result.returncode:
            raise RuntimeError('Collector preflight or QR rendering failed')
        return result.stdout

    def inspect(*command):
        return json.loads(run(['docker', *command]))[0]

    collector = inspect('inspect', args.collector)
    if not any(x.endswith('@' + IMAGE) for x in inspect('image', 'inspect', collector['Image']).get('RepoDigests', [])):
        raise ValueError('Collector image differs from the verified server version')
    app = inspect('inspect', project + '-app-1')
    plan = connection_plan(collector, app, inspect('network', 'inspect', project + '_default'), project)
    if not plan['already_connected']:
        raise ValueError('Complete the existing collector connection before scanning')
    credential = root / '.runtime' / 'we-mp-rss.env'
    settings = load_managed(credential, plan)
    info = credential.stat()
    if info.st_uid != 0 or info.st_gid != 33 or info.st_mode & 0o007:
        raise ValueError('Managed collector credentials must remain root:www-data and private')
    import fcntl
    # Prevent two copies of this CLI from replacing each other's fresh QR.
    with (root / '.runtime' / 'collector-authorization.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        api = CollectorAPI(plan['host_url'], settings)

        def matrix_builder(url):
            out = run(['docker', 'exec', '-i', args.collector, 'python3', '-c', SERVICE_EXEC, QR_MATRIX],
                      json.dumps({'url': url}).encode())
            return json.loads(next(line[len(RESULT):] for line in out.decode().splitlines() if line.startswith(RESULT)))

        return authorize(api, matrix_builder, scan=args.scan, columns=shutil.get_terminal_size().columns)


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print('\n扫码已停止；订阅及已保存的授权没有被清除。', file=sys.stderr)
        raise SystemExit(130)
    except Exception:
        # Never print raw HTTP/subprocess exceptions: upstream bodies contain credentials.
        print('扫码未完成。未修改账号、端口或订阅；请只发回这一行错误，不要发送二维码或服务日志。', file=sys.stderr)
        raise SystemExit(1)
