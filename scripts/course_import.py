#!/usr/bin/env python3
"""Preview or import an authorized, local UTF-8 CSV of public course fields."""

import argparse

from forum import Forum, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('--apply', action='store_true', help='write the verified preview')
    parser.add_argument('file', help='CSV below .runtime, for example .runtime/course-import.csv')
    args = parser.parse_args()
    args.profile = 'development'
    forum = Forum(args)
    forum.validate()
    command = ['flarum', 'campus:courses:import', args.file]
    if args.apply:
        command.append('--apply')
    # Docker app normally runs as www-data; export files should remain private.
    forum.php(*command, root=args.backend == 'docker')


if __name__ == '__main__':
    main()
