#!/usr/bin/env python3
"""Connect a pristine co-located collector without resetting its administrator or exposing its port."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
from urllib.parse import urlencode
from urllib.request import ProxyHandler, Request, build_opener

from forum import read_env

MARKER = '# UCASSer managed collector connection v1'
IMAGE = 'sha256:af771f21b3f7958a5dea16911fba050a6d7b92eac2fb2499c467c1b11f07ef34'
RESULT = 'UCASSER_RESULT:'
SERVICE_EXEC = r'''
import os,platform,sys
paths=['/app/env_'+platform.machine()+'/bin/python3','/app/env/bin/python3']
interpreter=next((p for p in paths if os.path.isfile(p) and os.access(p,os.X_OK)),None)
assert interpreter, 'Collector virtual environment not found'
os.execv(interpreter,[interpreter,'-c',sys.argv[1]])
'''
INSPECT_DB = r'''
import sqlite3,json
c=sqlite3.connect('file:/app/data/db.db?mode=ro',uri=True)
c.execute('PRAGMA query_only=ON')
users=[{'username':u,'role':r,'active':bool(a)} for u,r,a in c.execute('SELECT username,role,is_active FROM users')]
out={'users':users,'feeds':c.execute('SELECT COUNT(*) FROM feeds').fetchone()[0],'articles':c.execute('SELECT COUNT(*) FROM articles').fetchone()[0]}
c.close();print('UCASSER_RESULT:'+json.dumps(out))
'''
CREATE_USER = r'''
import ast,bcrypt,json,sqlite3,sys,uuid
from datetime import datetime,timezone
from pathlib import Path
x=json.load(sys.stdin)
assert x['username'].startswith('ucasser_connector_') and len(x['password'])>=32
# Confirm the pinned service uses this hash format without importing its DB initializer.
tree=ast.parse(Path('/app/core/auth.py').read_text())
hasher=next(n for n in tree.body if isinstance(n,ast.ClassDef) and n.name=='PasswordHasher')
source=ast.unparse(hasher)
assert 'bcrypt.hashpw' in source and 'bcrypt.checkpw' in source
hashed=bcrypt.hashpw(x['password'].encode(),bcrypt.gensalt()).decode()
assert bcrypt.checkpw(x['password'].encode(),hashed.encode())
c=sqlite3.connect('/app/data/db.db',timeout=10)
try:
    columns={r[1] for r in c.execute('PRAGMA table_info(users)')}
    needed={'id','username','password_hash','is_active','role','permissions','nickname','created_at','updated_at'}
    assert needed<=columns, 'Unsupported collector user schema'
    c.execute('BEGIN IMMEDIATE')
    assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==1, 'Service no longer pristine'
    assert c.execute('SELECT COUNT(*) FROM feeds').fetchone()[0]==0
    assert c.execute('SELECT COUNT(*) FROM articles').fetchone()[0]==0
    owner=c.execute('SELECT role,is_active FROM users').fetchone()
    assert owner==('admin',1)
    now=datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S.%f')
    c.execute('INSERT INTO users (id,username,password_hash,is_active,role,permissions,nickname,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        (str(uuid.uuid4()),x['username'],hashed,1,'user','[]','UCASSer connector',now,now))
    if x.get('apply') is True: c.commit()
    else: c.rollback()
    print('UCASSER_RESULT:'+json.dumps({'created':x.get('apply') is True,'model_validated':True}))
except Exception:
    c.rollback();raise
finally:c.close()
'''


def pristine(state):
    return state['feeds'] == 0 and state['articles'] == 0 and len(state['users']) == 1 and state['users'][0]['role'] == 'admin' and state['users'][0]['active']


def connection_plan(collector, app, network, project):
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,31}', project):
        raise ValueError('Unsupported Compose project name')
    bindings = collector['NetworkSettings']['Ports'].get('8001/tcp') or []
    if len(bindings) != 1 or bindings[0]['HostIp'] != '127.0.0.1':
        raise ValueError('Collector port must remain exclusively bound to 127.0.0.1')
    port = bindings[0]['HostPort']
    if not str(port).isdigit() or not 1024 <= int(port) <= 65535:
        raise ValueError('Invalid collector loopback port')
    default = project + '_default'
    if default not in app['NetworkSettings']['Networks'] or (network.get('Labels') or {}).get('com.docker.compose.project') != project:
        raise ValueError('Expected the forum project default network')
    name = collector['Name'].lstrip('/')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,80}', name):
        raise ValueError('Invalid collector container name')
    return {'network': default, 'base_url': 'http://' + name + ':8001',
            'host_url': 'http://127.0.0.1:' + str(port), 'username': 'ucasser_connector_' + project.replace('-', '_'),
            'already_connected': default in collector['NetworkSettings']['Networks']}


def private_write(path, text, group=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
            stream.write(text)
        if group is not None:
            os.chown(path, 0, group)
            path.chmod(0o640)
    except BaseException:
        # Preserve any partially written credential for recovery; never print it.
        raise


def load_managed(path, plan):
    if path.is_symlink() or not path.read_text(encoding='utf-8').startswith(MARKER + '\n'):
        raise ValueError('Existing collector credentials are not managed by this tool; left unchanged')
    values = read_env(path)
    if values.get('USERNAME') != plan['username'] or values.get('BASE_URL') != plan['base_url'] or len(values.get('PASSWORD', '')) < 32:
        raise ValueError('Existing connection identity differs; left unchanged')
    return values


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument('--env-file', default='.env.test')
    parser.add_argument('--collector', default='ucasser-we-mp-rss')
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if os.name != 'posix' or os.geteuid() != 0:
        parser.error('Run on the Linux server using sudo; no changes made')
    root = Path(args.root).resolve()
    env = root / args.env_file
    if env.resolve().parent != root or not env.is_file():
        parser.error('Environment file must be in the selected forum root')
    values = read_env(env)
    project = values.get('COMPOSE_PROJECT_NAME', '')
    runtime = root / '.runtime'
    if runtime.is_symlink():
        parser.error('Runtime directory must not be a symlink')
    credential = runtime / 'we-mp-rss.env'

    def run(command, payload=None):
        result = subprocess.run(command, input=payload, stdin=subprocess.DEVNULL if payload is None else None,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=root, timeout=60)
        if result.returncode:
            # Captured output may contain an ORM hash or access token. Never log it.
            raise RuntimeError('A Docker or collector operation failed; private backups retained')
        return result.stdout

    def docker_json(*command):
        return json.loads(run(['docker', *command]))

    def service_code(code, data=None):
        out = run(['docker', 'exec', '-i', args.collector, 'python3', '-c', SERVICE_EXEC, code],
                  json.dumps(data).encode() if data is not None else None)
        for line in out.decode('utf-8', 'replace').splitlines():
            if line.startswith(RESULT):
                return json.loads(line[len(RESULT):])
        raise RuntimeError('Collector did not return a setup result')

    dc = ['docker', 'compose', '--env-file', str(env), '-f', str(root / 'compose.yaml')]
    # Explicit --env-file also supplies the interpolation used by the forum compose bridge.
    os.environ['FORUM_ENV_FILE'] = str(env)
    app_id = run([*dc, 'ps', '-q', 'app']).decode().strip()
    if not app_id:
        raise ValueError('Forum app must be running')
    app = docker_json('inspect', app_id)[0]
    run([*dc, 'exec', '-T', '-u', 'www-data', 'app', 'php', '-r', 'exit(is_executable(".runtime") ? 0 : 1);'])
    collector = docker_json('inspect', args.collector)[0]
    digests = docker_json('image', 'inspect', collector['Image'])[0].get('RepoDigests', [])
    if not any(x.endswith('@' + IMAGE) for x in digests):
        raise ValueError('Collector image differs from the tested server version')
    network = docker_json('network', 'inspect', project + '_default')[0]
    plan = connection_plan(collector, app, network, project)
    state = service_code(INSPECT_DB)
    settings = load_managed(credential, plan) if credential.exists() else None
    managed_exists = any(x['username'] == plan['username'] for x in state['users'])
    if not managed_exists and not pristine(state):
        raise ValueError('Collector is not pristine; existing accounts, feeds and authorization left unchanged')
    if managed_exists and settings is None:
        raise ValueError('Service account already exists without its managed credential; not reset')
    report = {'apply': args.apply, 'network_connected': plan['already_connected'],
              'service_account_exists': managed_exists, 'feeds': state['feeds'], 'articles': state['articles'],
              'administrator_password_changed': False, 'public_port_opened': False}
    if not args.apply:
        if not managed_exists:
            report['model_validation'] = service_code(CREATE_USER, {'username': plan['username'], 'password': secrets.token_urlsafe(36), 'apply': False})
        print(json.dumps(report)); return 0
    if settings is None:
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3)
        backup = runtime / ('collector-connect-' + stamp)
        backup.mkdir(mode=0o700)
        backup.chmod(0o700)
        # Take a consistent independent collector database backup, without moving its live files.
        temporary = '/tmp/ucasser-collector-' + secrets.token_hex(16) + '.sqlite'
        code = "import sqlite3,os; a=sqlite3.connect('file:/app/data/db.db?mode=ro',uri=True); b=sqlite3.connect(" + repr(temporary) + "); a.backup(b); b.close(); a.close(); os.chmod(" + repr(temporary) + ",0o600)"
        run(['docker', 'exec', args.collector, 'python3', '-c', code])
        run(['docker', 'cp', args.collector + ':' + temporary, str(backup / 'collector.sqlite')])
        (backup / 'collector.sqlite').chmod(0o600)
        run(['docker', 'exec', args.collector, 'python3', '-c', 'from pathlib import Path; Path(' + repr(temporary) + ').unlink()'])
        settings = {'USERNAME': plan['username'], 'PASSWORD': secrets.token_urlsafe(36), 'BASE_URL': plan['base_url']}
        content = MARKER + '\n' + ''.join(k + '=' + v + '\n' for k, v in settings.items())
        private_write(backup / 'managed-credential.env', content)
        private_write(credential, content, group=33)
        report['backup'] = str(backup)
    else:
        if credential.stat().st_mode & 0o007 or credential.stat().st_uid != 0 or credential.stat().st_gid != 33:
            raise ValueError('Managed credential must be root:www-data with no world access')
    if not managed_exists:
        service_code(CREATE_USER, {'username': settings['USERNAME'], 'password': settings['PASSWORD'], 'apply': True})
    opener = build_opener(ProxyHandler({}))
    form = urlencode({'username': settings['USERNAME'], 'password': settings['PASSWORD']}).encode()
    with opener.open(Request(plan['host_url'] + '/api/v1/wx/auth/login', data=form,
                             headers={'Content-Type': 'application/x-www-form-urlencoded'}), timeout=15) as response:
        login = json.load(response)
    token = (login.get('data') or {}).get('access_token')
    if not token:
        raise RuntimeError('Managed service login failed; existing accounts were not reset')
    if not plan['already_connected']:
        run(['docker', 'network', 'connect', plan['network'], args.collector])
    # Use the same client as the operator backend, under its actual runtime identity.
    code = "require 'vendor/autoload.php'; $c=new UCASSer\\Campus\\CollectorClient(); echo json_encode($c->state());"
    result = json.loads(run([*dc, 'exec', '-T', '-u', 'www-data', 'app', 'php', '-r', code]))
    if result['state'] not in ('ready', 'authorization_required'):
        raise RuntimeError('Forum cannot use the connection yet; credential retained for safe retry; check runtime directory traversal permissions')
    report.update(network_connected=True, service_account_exists=True, state=result['state'])
    print(json.dumps(report, ensure_ascii=False)); return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (ValueError, RuntimeError) as error:
        print('Collector connection stopped: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
    except Exception:
        print('Collector connection did not complete. Existing administrator password was not reset. Inspect container/image/network/credential ownership; retry uses the same managed credential. No secrets printed.', file=sys.stderr)
        raise SystemExit(1)
