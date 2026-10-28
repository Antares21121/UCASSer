#!/usr/bin/env python3
"""Fetch public UCASS notices on this computer into a reviewable JSON snapshot.

This works without Docker or the local forum. Importing into the server is a
separate step; this script never sends data or credentials to the internet.
"""

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
from urllib.parse import urlsplit

from forum import ROOT

PARSER = ROOT / 'scripts' / 'aggregation_parse_local.php'
OUTPUT = ROOT / '.runtime' / 'aggregation-web-local.json'
MAX_PAGE = 1024 * 1024


def parse(php, mode, adapter=None, html=b'', base=None, limit=None):
    command = [php, str(PARSER), mode]
    if adapter is not None:
        command.append(adapter)
    if base is not None:
        command.append(base)
    if limit is not None:
        command.append(str(limit))
    result = subprocess.run(command, input=html, capture_output=True, timeout=15)
    if result.returncode:
        raise RuntimeError(result.stderr.decode('utf-8', 'replace').strip() or 'HTML parser failed')
    return json.loads(result.stdout.decode('utf-8'))


def fetch(curl, url):
    command = [curl, '--fail', '--silent', '--show-error', '--proto', '=https',
               '--max-redirs', '0', '--connect-timeout', '5', '--max-time', '15',
               '--max-filesize', str(MAX_PAGE), '--user-agent',
               'UCASSer-aggregation/0.1 (+reviewed campus information index)',
               '--write-out', '%{http_code}', '--url', url]
    result = subprocess.run(command, capture_output=True, timeout=20)
    if result.returncode or len(result.stdout) < 3:
        raise RuntimeError(result.stderr.decode('utf-8', 'replace').strip() or 'Download failed')
    status = result.stdout[-3:].decode('ascii', 'replace')
    if status != '200':
        raise RuntimeError('Source returned HTTP ' + status)
    body = result.stdout[:-3]
    if len(body) > MAX_PAGE:
        raise RuntimeError('Source page exceeds 1 MB')
    return body


def official_url(url, host=None, article=False):
    parsed = urlsplit(url)
    if (parsed.scheme != 'https' or not parsed.hostname
            or not (parsed.hostname == 'ucass.edu.cn' or parsed.hostname.endswith('.ucass.edu.cn'))
            or parsed.username or parsed.password or parsed.port not in (None, 443)):
        raise ValueError('Source is not an official UCASS HTTPS URL')
    if host is not None and parsed.hostname != host:
        raise ValueError('Article does not belong to its listed source')
    if article and not re.fullmatch(r'/info/\d+/\d+\.htm', parsed.path):
        raise ValueError('Unexpected article URL path')
    return parsed.hostname


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('--all', action='store_true', help='Fetch every cataloged public website')
    cli.add_argument('--source', action='append', help='Exact official source name; repeat for several')
    cli.add_argument('--limit', type=int, default=2, help='Latest articles per source, 1–10')
    args = cli.parse_args()
    if not 1 <= args.limit <= 10 or args.all and args.source:
        cli.error('Use --limit 1–10 and either --all or --source')

    php = shutil.which('php')
    curl = shutil.which('curl.exe' if os.name == 'nt' else 'curl')
    if not php or not curl:
        cli.error('Local PHP and curl are required')
    catalog = parse(php, 'catalog')
    wanted = set(args.source or (name for name, _ in catalog[:1]))
    if not args.all:
        unknown = wanted - {name for name, _ in catalog}
        if unknown:
            cli.error('Unknown source: ' + ', '.join(sorted(unknown)))
    selected = catalog if args.all else [(name, url) for name, url in catalog if name in wanted]

    sources = []
    failures = []
    for name, url in selected:
        try:
            host = official_url(url)
            adapter = 'ucass-notices' if host == 'jw.ucass.edu.cn' else 'ucass-vsb'
            # Look past a broken first link so a single 404 does not hide a whole source.
            listing = parse(php, 'listing', adapter, fetch(curl, url), url, min(10, args.limit + 3))
            if not listing:
                raise RuntimeError('No dated notice links found')
            entries = []
            for entry in listing:
                try:
                    official_url(entry['url'], host, article=True)
                    article = parse(php, 'article', adapter, fetch(curl, entry['url']))
                    if article.get('title') and ('...' in entry['title'] or '…' in entry['title']):
                        entry['title'] = article['title']
                    entry.update({key: article[key] for key in ('summary', 'content_hash') if key in article})
                    entries.append(entry)
                    time.sleep(.25)
                    if len(entries) >= args.limit:
                        break
                except (KeyError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
                    failures.append(f'{name} / article: {exc}')
            if not entries:
                raise RuntimeError('No usable articles')
            sources.append({'name': name, 'listing_url': url, 'adapter': adapter, 'entries': entries})
            print(f'{name}: {len(entries)} article(s)', flush=True)
        except (ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
            failures.append(f'{name}: {exc}')
            print(f'{name}: failed ({exc})', file=sys.stderr, flush=True)

    snapshot = {'version': 1, 'fetched_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
                'sources': sources, 'failures': failures}
    OUTPUT.parent.mkdir(exist_ok=True)
    temporary = OUTPUT.with_suffix('.json.tmp')
    temporary.write_text(json.dumps(snapshot, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary.replace(OUTPUT)
    print(f'Snapshot: {OUTPUT}; {len(sources)} source(s), {len(failures)} failure(s)')
    return 0 if sources else 1


if __name__ == '__main__':
    sys.exit(main())
