#!/usr/bin/env python3
"""Prepare, migrate and seed the campus extension via the existing launcher."""
import argparse
from pathlib import Path
from forum import Forum, ROOT

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['native', 'docker'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('command', choices=['enable', 'migrate', 'seed', 'status'])
    args = parser.parse_args()
    args.profile = 'development'
    instance = Forum(args)
    instance.validate()
    if args.command == 'migrate':
        instance.php('flarum', 'migrate')
        instance.php('flarum', 'campus:setup')
        instance.php('flarum', 'cache:clear')
    else:
        instance.php('scripts/campus.php', args.command)

if __name__ == '__main__':
    main()
