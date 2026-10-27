#!/usr/bin/env python3
"""Register the reviewed official-source catalog without changing existing pauses."""

import argparse

from forum import Forum, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    args = parser.parse_args()
    args.profile = 'development'
    forum = Forum(args)
    forum.validate()
    forum.php('flarum', 'campus:aggregation:sync-sources')


if __name__ == '__main__':
    main()
