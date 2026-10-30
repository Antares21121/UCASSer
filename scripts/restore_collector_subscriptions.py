#!/usr/bin/env python3
"""Restore reviewed public subscription identifiers after live publisher verification."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

from authorize_collector import CollectorAPI
from connect_collector import IMAGE, RESULT, SERVICE_EXEC, connection_plan, load_managed
from forum import read_env

# Same stable WeRead publisher IDs observed on the owner's server, 2026-10-30.
# Keep the historical source label so existing forum source IDs and review links survive.
REVIEWED_NAMES = {
    'MP_WXS_3894823465': ('中国社科大国际政治经济学院', '中国社科大全球与区域国别学院'),
    'MP_WXS_3888538840': ('社科大哲学院', '中国社会科学院大学哲学院'),
    'MP_WXS_3093663298': ('社科大科研处', '中国社科大科研处'),
}

SEED_FEEDS = r'''
import base64,json,sqlite3,sys
from datetime import datetime,timezone
x=json.load(sys.stdin)
c=sqlite3.connect('/app/data/db.db',timeout=10)
try:
    required={'id','mp_name','mp_cover','mp_intro','status','sync_time','update_time','created_at','updated_at','faker_id'}
    assert required<={r[1] for r in c.execute('PRAGMA table_info(feeds)')}
    c.execute('BEGIN IMMEDIATE')
    before=c.execute('SELECT COUNT(*) FROM feeds').fetchone()[0]
    articles=c.execute('SELECT COUNT(*) FROM articles').fetchone()[0]
    added=0;preserved=0
    for row in x['subscriptions']:
        feed_id=row['public_feed_id'];name=row['publisher_name'];current=row.get('current_name',name)
        assert feed_id.startswith('MP_WXS_') and feed_id[7:].isdigit() and 2<=len(name)<=120
        assert 2<=len(current)<=120
        faker_id=base64.b64encode(feed_id[7:].encode()).decode()
        existing=c.execute('SELECT id,mp_name,faker_id FROM feeds WHERE id=? OR faker_id=?',(feed_id,faker_id)).fetchall()
        if existing:
            assert len(existing)==1 and existing[0][0]==feed_id and existing[0][1] in (name,current) and existing[0][2]==faker_id, 'Existing publisher identity conflict'
            preserved+=1
            continue
        now=datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S.%f')
        c.execute('INSERT INTO feeds(id,mp_name,mp_cover,mp_intro,status,sync_time,update_time,created_at,updated_at,faker_id) VALUES(?,?,?,?,?,?,?,?,?,?)',
                  (feed_id,name,'','当前公开名称：'+current if current!=name else '',1,0,0,now,now,faker_id))
        added+=1
    after=c.execute('SELECT COUNT(*) FROM feeds').fetchone()[0]
    assert after==before+added and c.execute('SELECT COUNT(*) FROM articles').fetchone()[0]==articles
    if x['apply']:c.commit()
    else:c.rollback()
    print('UCASSER_RESULT:'+json.dumps({'apply':x['apply'],'feeds_before':before,'added':added,'existing_preserved':preserved,
                                       'feeds_after':after if x['apply'] else before,'articles_unchanged':True}))
except BaseException:
    c.rollback();raise
finally:c.close()
'''


def subscriptions_from(manifest):
    rows = manifest.get('public_subscriptions')
    if not isinstance(rows, list) or len(rows) != 19:
        raise ValueError('Expected the reviewed set of 19 public publishers')
    ids, names = set(), set()
    for row in rows:
        feed_id, name = row.get('public_feed_id'), row.get('publisher_name')
        if not isinstance(feed_id, str) or not re.fullmatch(r'MP_WXS_[0-9]{6,16}', feed_id):
            raise ValueError('Invalid public publisher identifier')
        if not isinstance(name, str) or not 2 <= len(name) <= 120 or name.strip() != name or any(ord(c) < 32 for c in name):
            raise ValueError('Invalid public publisher name')
        if feed_id in ids or name in names:
            raise ValueError('Duplicate publisher in reviewed manifest')
        ids.add(feed_id); names.add(name)
    return rows


def inspect_publishers(api, rows):
    if api.request('/weread/test', b'{}').get('ok') is not True:
        raise RuntimeError('Saved authorization has not passed the WeRead connection test')
    identities = []
    for row in rows:
        payload = json.dumps({'mp_id': row['public_feed_id']}).encode()
        result = api.request('/weread/mp/test', payload)
        actual = result.get('mp_name')
        if result.get('mp_id') != row['public_feed_id'] or not isinstance(actual, str) or not 2 <= len(actual) <= 120 or any(ord(c) < 32 or 127 <= ord(c) <= 159 for c in actual):
            raise RuntimeError('Collector returned an invalid public publisher identity')
        reviewed = REVIEWED_NAMES.get(row['public_feed_id']) == (row['publisher_name'], actual)
        identities.append({'public_feed_id': row['public_feed_id'], 'expected_name': row['publisher_name'],
                           'current_name': actual, 'matches': actual == row['publisher_name'] or reviewed,
                           'reviewed_name_change': reviewed})
    return identities


def verify_publishers(api, rows, output=print):
    identities = inspect_publishers(api, rows)
    for identity in identities:
        if not identity['matches']:
            output('发布者身份不一致，已停止；未写入任何订阅：' + identity['expected_name'] + ' → ' + identity['current_name'], flush=True)
            raise RuntimeError('Live publisher identity differs from the reviewed manifest')
        suffix = '（现名：' + identity['current_name'] + '）' if identity['reviewed_name_change'] else ''
        output('发布者核验通过：' + identity['expected_name'] + suffix, flush=True)
    return identities


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument('--env-file', default='.env.test')
    parser.add_argument('--collector', default='ucasser-we-mp-rss')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--inspect-publishers', action='store_true', help='Read all current public publisher names; do not write subscriptions')
    args = parser.parse_args()
    if args.inspect_publishers and args.apply:
        parser.error('--inspect-publishers cannot be combined with --apply')
    if os.name != 'posix' or os.geteuid() != 0:
        parser.error('Run with sudo on the Linux server')
    root = Path(args.root).resolve()
    env = root / args.env_file
    if not env.is_file() or env.resolve().parent != root or (root / '.runtime').is_symlink():
        raise ValueError('Invalid forum root or environment file')
    project = read_env(env).get('COMPOSE_PROJECT_NAME', '')
    rows = subscriptions_from(json.loads((root / 'data/reconciliation/public-content.json').read_text(encoding='utf-8')))

    def run(command, payload=None):
        result = subprocess.run(command, input=payload, stdin=subprocess.DEVNULL if payload is None else None,
                                capture_output=True, timeout=60, cwd=root)
        if result.returncode:
            raise RuntimeError('Collector operation failed; private backup retained if created')
        return result.stdout

    def inspect(*command):
        return json.loads(run(['docker', *command]))[0]

    def seed(apply):
        out = run(['docker', 'exec', '-i', args.collector, 'python3', '-c', SERVICE_EXEC, SEED_FEEDS],
                  json.dumps({'subscriptions': rows, 'apply': apply}).encode())
        return json.loads(next(line[len(RESULT):] for line in out.decode().splitlines() if line.startswith(RESULT)))

    collector = inspect('inspect', args.collector)
    if not any(x.endswith('@' + IMAGE) for x in inspect('image', 'inspect', collector['Image']).get('RepoDigests', [])):
        raise ValueError('Collector image differs from the verified server version')
    plan = connection_plan(collector, inspect('inspect', project + '-app-1'), inspect('network', 'inspect', project + '_default'), project)
    if not plan['already_connected']:
        raise ValueError('Collector internal connection is incomplete')
    credential = root / '.runtime' / 'we-mp-rss.env'
    settings = load_managed(credential, plan)
    info = credential.stat()
    if info.st_uid != 0 or info.st_gid != 33 or info.st_mode & 0o007:
        raise ValueError('Collector credential permissions differ from the managed connection')
    import fcntl
    with (root / '.runtime' / 'collector-subscriptions.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        api = CollectorAPI(plan['host_url'], settings)
        if args.inspect_publishers:
            print(json.dumps({'publishers': inspect_publishers(api, rows), 'subscriptions_changed': False}, ensure_ascii=False))
            return 0
        identities = verify_publishers(api, rows)
        rows = [row | {'current_name': identity['current_name']} for row, identity in zip(rows, identities)]
        preview = seed(False)  # Real SQL validation, then rollback before taking any action.
        if not args.apply:
            print(json.dumps(preview | {'publishers_verified': len(rows), 'collection_started': False})); return 0
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3)
        backup = root / '.runtime' / ('collector-subscriptions-' + stamp)
        backup.mkdir(mode=0o700); backup.chmod(0o700)
        temporary = '/tmp/ucasser-subscriptions-' + secrets.token_hex(16) + '.sqlite'
        code = "import sqlite3,os; a=sqlite3.connect('file:/app/data/db.db?mode=ro',uri=True); b=sqlite3.connect(" + repr(temporary) + "); a.backup(b); b.close(); a.close(); os.chmod(" + repr(temporary) + ",0o600)"
        run(['docker', 'exec', args.collector, 'python3', '-c', code])
        run(['docker', 'cp', args.collector + ':' + temporary, str(backup / 'collector.sqlite')])
        (backup / 'collector.sqlite').chmod(0o600)
        run(['docker', 'exec', args.collector, 'python3', '-c', 'from pathlib import Path; Path(' + repr(temporary) + ').unlink()'])
        result = seed(True)
        print(json.dumps(result | {'publishers_verified': len(rows), 'backup': str(backup), 'collection_started': False}))
        return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        print('公众号订阅恢复未完成；没有输出任何登录凭据。请发回最后的文字结果，不要发送服务日志。', file=sys.stderr)
        raise SystemExit(1)
