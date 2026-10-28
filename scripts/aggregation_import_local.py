#!/usr/bin/env python3
"""Import a transferred official-site snapshot into the administrator review queue."""

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
    forum.php('flarum', 'campus:aggregation:import-web-local')


if __name__ == '__main__':
    main()
