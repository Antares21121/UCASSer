#!/usr/bin/env python3
"""Run one public-source refresh and, when configured, one WeChat subscription import."""

import argparse
from pathlib import Path
import subprocess
import sys

from forum import ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('--limit', type=int, default=5, help='Latest articles per public website (1–10)')
    args = parser.parse_args()
    if not 1 <= args.limit <= 10:
        parser.error('--limit must be between 1 and 10')

    common = ['--backend', args.backend, '--env-file', args.env_file]
    commands = [
        ['aggregation_sources.py', *common],
        ['aggregation.py', '--limit', str(args.limit), *common],
    ]
    if (ROOT / '.runtime' / 'we-mp-rss.env').is_file():
        commands.append(['aggregation_weread.py', *common])
    else:
        print('公众号订阅服务尚未配置；本次只更新公开网站。', flush=True)

    failed = False
    for command in commands:
        result = subprocess.run([sys.executable, str(ROOT / 'scripts' / command[0]), *command[1:]], cwd=ROOT)
        if result.returncode:
            print(f'{command[0]} 失败，退出代码 {result.returncode}', file=sys.stderr, flush=True)
            failed = True
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
