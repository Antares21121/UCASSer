#!/usr/bin/env python3
"""Collect locally subscribed WeRead public accounts and import RSS into review."""

import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import urlencode
from urllib.request import ProxyHandler, Request, build_opener

from forum import Forum, ROOT

API = 'http://127.0.0.1:8001/api/v1/wx'
AUTH_CODES = {'-2012', '-2041', '-2010'}


class CollectorAuthorizationRequired(RuntimeError):
    pass


def record_collector_state(args, state):
    """Make scheduled failures visible to admins without exposing collector secrets."""
    try:
        args.profile = 'development'
        forum = Forum(args)
        forum.validate()
        forum.php('flarum', 'campus:aggregation:collector-status', state)
    except Exception as exc:
        print(f'无法记录公众号授权状态：{exc}', file=sys.stderr)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--limit', type=int, default=20, help='Maximum RSS items per account')
    parser.add_argument('--max-feeds', type=int, default=100, help='Maximum subscribed accounts per run')
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    args = parser.parse_args()
    if not 1 <= args.limit <= 20 or not 1 <= args.max_feeds <= 100:
        parser.error('--limit must be 1–20 and --max-feeds must be 1–100')

    settings_path = ROOT / '.runtime' / 'we-mp-rss.env'
    if not settings_path.is_file():
        record_collector_state(args, 'unconfigured')
        parser.error('缺少本机订阅服务凭据 .runtime/we-mp-rss.env')
    settings = dict(line.split('=', 1) for line in settings_path.read_text(encoding='utf-8').splitlines() if '=' in line)
    if not settings.get('USERNAME') or not settings.get('PASSWORD'):
        record_collector_state(args, 'unconfigured')
        parser.error('订阅服务凭据缺少 USERNAME 或 PASSWORD')

    opener = build_opener(ProxyHandler({}))

    def request(path, token=None, payload=None, timeout=30):
        headers = {'Accept': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if payload is not None:
            headers['Content-Type'] = 'application/json'
            payload = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        with opener.open(Request(API + path, data=payload, headers=headers), timeout=timeout) as response:
            result = json.load(response)
        if result.get('code') != 0:
            code = str(result.get('code'))
            if code in AUTH_CODES:
                record_collector_state(args, 'authorization_required')
                raise CollectorAuthorizationRequired(f'订阅服务认证或风控错误代码 {code}')
            raise RuntimeError(f'订阅服务返回错误代码 {code}')
        return result.get('data') or {}

    form = urlencode({'username': settings['USERNAME'], 'password': settings['PASSWORD']}).encode()
    try:
        with opener.open(Request(API + '/auth/login', data=form,
                                 headers={'Content-Type': 'application/x-www-form-urlencoded'}), timeout=15) as response:
            login = json.load(response)
    except Exception:
        record_collector_state(args, 'unavailable')
        raise
    token = (login.get('data') or {}).get('access_token')
    if not token:
        record_collector_state(args, 'unavailable')
        raise RuntimeError('订阅服务登录失败，请在本机服务界面核对账号')

    try:
        status = request('/weread', token)
    except CollectorAuthorizationRequired:
        raise
    except Exception:
        record_collector_state(args, 'unavailable')
        raise
    if not status.get('mp_configured'):
        record_collector_state(args, 'authorization_required')
        raise RuntimeError('微信读书未授权，请在本机订阅服务重新扫码')
    record_collector_state(args, 'ready')

    feeds = []
    offset = 0
    while True:
        page = request(f'/mps?limit=100&offset={offset}&status=1', token)
        batch = page.get('list') or []
        feeds.extend(batch)
        offset += len(batch)
        if not batch or offset >= (page.get('total') or 0):
            break
    feeds = [row for row in feeds if re.fullmatch(r'MP_WXS_[0-9]+', str(row.get('id', '')))][:args.max_feeds]

    log_path = ROOT / '.runtime' / 'aggregation-weread.log'
    log_path.parent.mkdir(exist_ok=True)
    failures = 0
    with log_path.open('a', encoding='utf-8') as log:
        def report(message):
            line = datetime.now(timezone.utc).isoformat(timespec='seconds') + ' ' + message
            print(line, flush=True)
            log.write(line + '\n')
            log.flush()

        report(f'开始处理 {len(feeds)} 个已启用公众号')
        for row in feeds:
            feed_id = row['id']
            name = row.get('mp_name') or feed_id
            try:
                collected = request('/weread/collect', token, {
                    'mp_id': feed_id, 'faker_id': feed_id, 'mp_name': name,
                    'max_page': 1, 'gather_content': False,
                }, timeout=180)
                command = [sys.executable, str(ROOT / 'scripts' / 'aggregation_feed.py'),
                           '--feed-url', f'http://127.0.0.1:8001/rss/{feed_id}',
                           '--name', name, '--limit', str(args.limit), '--backend', args.backend,
                           '--env-file', args.env_file]
                result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True,
                                        encoding='utf-8', errors='replace', timeout=180)
                if result.returncode:
                    raise RuntimeError(f'导入失败，退出代码 {result.returncode}；详见 .runtime/last-error.log')
                report(f'{name}：采集 {collected.get("collected", 0)} 篇；{result.stdout.strip()}')
            except Exception as exc:
                failures += 1
                report(f'{name}：失败（{type(exc).__name__}：{exc}）')
                if isinstance(exc, CollectorAuthorizationRequired):
                    report('认证或风控异常，停止本轮公众号采集，等待人工检查授权')
                    break
        report(f'结束，失败 {failures} 个；文章仅进入待审队列')
    return 1 if failures else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as exc:
        print(f'公众号定时采集失败：{type(exc).__name__}：{exc}', file=sys.stderr)
        sys.exit(1)
