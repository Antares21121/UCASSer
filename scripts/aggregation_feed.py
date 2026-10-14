#!/usr/bin/env python3
"""Import a locally hosted RSS feed into the campus admin review queue."""

import argparse
from datetime import timezone
from email.utils import parsedate_to_datetime
import json
from pathlib import Path
import re
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener
import xml.etree.ElementTree as ET

from forum import Forum, ROOT


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def child_text(node, *names):
    for name in names:
        value = node.findtext(name)
        if value:
            return value.strip()
    return ''


def read_feed(feed_url, limit):
    parsed = urlsplit(feed_url)
    if parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost') or parsed.username or parsed.password:
        raise ValueError('只接受本机 HTTP RSS 地址，请勿传入公网或带凭据的网址')
    request = Request(feed_url, headers={'User-Agent': 'UCASSer-feed-import/1.0', 'Accept': 'application/rss+xml, application/atom+xml, application/xml'})
    with build_opener(ProxyHandler({}), NoRedirect).open(request, timeout=15) as response:
        content = response.read(1024 * 1024 + 1)
        if len(content) > 1024 * 1024:
            raise ValueError('RSS 超过 1 MB')
    root = ET.fromstring(content)
    entries = root.findall('./channel/item') if root.tag == 'rss' else root.findall('{http://www.w3.org/2005/Atom}entry')
    result = []
    for entry in entries:
        if len(result) >= limit:
            break
        title = child_text(entry, 'title', '{http://www.w3.org/2005/Atom}title')
        link = child_text(entry, 'link')
        if not link:
            for node in entry.findall('{http://www.w3.org/2005/Atom}link'):
                if node.get('rel', 'alternate') == 'alternate':
                    link = node.get('href', '')
                    break
        url = urlsplit(link)
        if (url.scheme != 'https' or url.hostname != 'mp.weixin.qq.com'
                or not (re.fullmatch(r'/s/[A-Za-z0-9_-]+', url.path)
                        or url.path == '/s' and re.fullmatch(r'[A-Za-z0-9_=&%.-]+', url.query))):
            continue
        if not 3 <= len(title) <= 180:
            continue
        date = child_text(entry, 'pubDate', '{http://www.w3.org/2005/Atom}published', '{http://www.w3.org/2005/Atom}updated')
        published = None
        if date:
            try:
                published = parsedate_to_datetime(date).astimezone(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
            except (TypeError, ValueError, IndexError):
                try:
                    from datetime import datetime
                    published = datetime.fromisoformat(date.replace('Z', '+00:00')).astimezone(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
                except ValueError:
                    pass
        result.append({'url': link, 'title': title, 'summary': '公众号订阅源发现的文章；请管理员打开原文核对发布账号及内容。', 'published_at': published})
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--feed-url', required=True, help='Local RSS URL from the separately running collector')
    parser.add_argument('--name', required=True, help='Publisher name shown in review')
    parser.add_argument('--limit', type=int, default=20)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    args = parser.parse_args()
    if not 1 <= args.limit <= 20:
        parser.error('--limit must be 1–20')
    rows = read_feed(args.feed_url, args.limit)
    if not rows:
        print('订阅源中没有可用的微信原文链接；未导入')
        return
    snapshot = Path(ROOT / '.runtime' / 'aggregation-feed-import.json')
    snapshot.parent.mkdir(exist_ok=True)
    snapshot.write_text(json.dumps(rows, ensure_ascii=False), encoding='utf-8')
    args.profile = 'development'
    forum = Forum(args)
    forum.validate()
    forum.php('flarum', 'campus:aggregation:import-feed', '.runtime/aggregation-feed-import.json', args.name)


if __name__ == '__main__':
    main()
