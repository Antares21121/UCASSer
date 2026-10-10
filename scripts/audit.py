#!/usr/bin/env python3
"""Report all advisories; fail on any vulnerability or abandoned dependency."""
import argparse
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def check_report(data):
    advisories = data.get('advisories') or {}
    abandoned = data.get('abandoned') or {}
    if not isinstance(advisories, dict) or not isinstance(abandoned, dict):
        raise ValueError('Unexpected Composer audit report structure')
    unexpected = []
    for package, entries in advisories.items():
        for entry in entries:
            print('SECURITY ADVISORY: ' + package + ': ' + entry['title'])
            unexpected.append(package)
    for package in abandoned:
        print('ABANDONED: ' + package)
        unexpected.append(package)
    if unexpected:
        raise ValueError('Security or maintenance findings: ' + ', '.join(unexpected))
    print('Audit OK: no reported vulnerabilities or abandoned dependencies in the locked packages')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['native', 'docker'], default='docker')
    args = parser.parse_args()
    if args.backend == 'native':
        command = [str(ROOT / '.tools/php/php.exe'), str(ROOT / '.tools/composer.phar')]
    else:
        command = ['docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'app', 'composer']
    result = subprocess.run([*command, 'audit', '--locked', '--format=json'], cwd=ROOT, capture_output=True)
    if result.returncode not in (0, 1, 2, 3):
        raise RuntimeError('Composer audit failed to execute')
    check_report(json.loads(result.stdout))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
