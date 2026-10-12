#!/usr/bin/env python3
"""Fetch registered public information sources into the campus review queue."""

import argparse
from forum import Forum, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('--source', type=int, help='Only fetch this registered source ID')
    parser.add_argument('--limit', type=int, default=5, help='Articles per source, from 1 to 10')
    args = parser.parse_args()
    if not 1 <= args.limit <= 10:
        parser.error('--limit must be between 1 and 10')
    args.profile = 'development'
    forum = Forum(args)
    forum.validate()
    command = ['flarum', 'campus:aggregation:poll', f'--limit={args.limit}']
    if args.source is not None:
        if args.source < 1:
            parser.error('--source must be a positive ID')
        command.append(f'--source={args.source}')
    forum.php(*command)


if __name__ == '__main__':
    main()
