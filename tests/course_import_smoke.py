#!/usr/bin/env python3
"""Exercise authorized CSV preview, import, privacy and review-directory linkage on fresh CI data."""

import argparse
import csv
from pathlib import Path
import secrets

from campus_support import environment, ROOT


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], required=True)
    args = parser.parse_args()
    if not (ROOT / '.runtime/admin-initial-password.txt').exists():
        raise RuntimeError('Course import smoke requires a fresh development instance')

    forum, guest, _admin = environment(args.backend)
    marker = secrets.token_hex(5)
    code = 'CI' + marker.upper()
    title = '【验收】课程 ' + marker
    path = ROOT / '.runtime/course-import-smoke.csv'
    with path.open('w', encoding='utf-8', newline='') as output:
        writer = csv.writer(output)
        writer.writerow(['课程代码', '课程名称', '开课学院', '开课学期', '教师姓名', '教室'])
        writer.writerow([code, title, '【验收】学院', '2026秋', '隐私字段教师', '隐私字段教室'])
        writer.writerow([code, title, '【验收】学院', '2026秋', '另一教师', '另一教室'])

    try:
        relative = path.relative_to(ROOT).as_posix()
        before = guest.call('/api/campus/courses?search=' + code)
        assert not before['data']
        forum.php('flarum', 'campus:courses:import', relative, root=args.backend == 'docker')
        assert not guest.call('/api/campus/courses?search=' + code)['data'], 'Preview wrote course data'

        for _ in range(2):
            forum.php('flarum', 'campus:courses:import', relative, '--apply', root=args.backend == 'docker')
        result = guest.call('/api/campus/courses?search=' + code)
        assert result['meta']['total'] == 1 and len(result['data']) == 1, 'Import was not idempotent'
        course = result['data'][0]
        assert course['name'] == title and course['offer']['code'] == code
        assert course['offer']['college'] == '【验收】学院'
        assert course['offer']['resource_category_id'], 'Resource directory category was not linked'
        assert '隐私字段教师' not in str(course) and '隐私字段教室' not in str(course)
        guest.call('/api/campus/records?type=review&course_id=' + str(course['id']))
        print('PASS: course CSV preview, idempotent import, privacy and resource/review linkage')
    finally:
        path.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
