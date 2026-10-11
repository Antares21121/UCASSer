#!/usr/bin/env python3
"""Create coherent development posts; optionally archive recognized test fixtures."""
import argparse
from forum import Forum, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['native', 'docker'], default='docker')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('--tidy-tests', action='store_true', help='Back up first, then soft-hide recognized automated test discussions')
    args = parser.parse_args()
    args.profile = 'development'
    instance = Forum(args)
    instance.validate()
    if instance.values.get('APP_ENV') != 'development':
        raise RuntimeError('Demo content is development-only')
    if args.tidy_tests:
        instance.backup()
    instance.php('scripts/demo.php', 'tidy-tests' if args.tidy_tests else 'seed')


if __name__ == '__main__':
    main()
