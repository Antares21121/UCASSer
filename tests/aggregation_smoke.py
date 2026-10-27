#!/usr/bin/env python3
"""Exercise aggregation submissions, review, visibility and source registration.

Run only after tests/smoke.py in a fresh, isolated development instance.
"""

import argparse
from pathlib import Path
import secrets

from campus_support import account, environment


ROOT = Path(__file__).resolve().parents[1]
API = '/api/campus/aggregation'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], required=True)
    options = parser.parse_args()
    if not (ROOT / '.runtime/admin-initial-password.txt').exists():
        raise RuntimeError('Aggregation smoke requires a fresh tests/smoke.py instance')

    _, guest, admin = environment(options.backend)
    member = account(guest, admin, prefix='aggregation')['api']
    marker = secrets.token_hex(5)
    url = f'https://example.org/aggregation-{marker}'
    submission = {
        'origin': 'public_web', 'category': 'activity',
        'title': f'聚合验收活动 {marker}', 'summary': '仅用于隔离开发实例的活动信息验收。',
        'source_name': '验收网页', 'source_url': url, 'show_submitter': True,
    }

    guest.call(API + '/submissions', 'POST', submission, expected=(401, 403))
    guest.call(API + '/queue', expected=(401, 403))
    member.call(API + '/queue', expected=(401, 403))
    member.call(API + '/sources', expected=(401, 403))
    member.call(API + '/submissions', 'POST', submission | {'source_url': 'javascript:alert(1)'}, expected=422)
    member.call(API + '/submissions', 'POST', submission | {'show_submitter': 'yes'}, expected=422)

    first = member.call(API + '/submissions', 'POST', submission, expected=201)['data']['id']
    mine = member.call(API + '/mine')['data']
    assert any(row['id'] == first and row['status'] == 'pending' for row in mine)
    queue = admin.call(API + '/queue')['data']
    assert any(row['id'] == first and row['source_url'] == url for row in queue)
    published = admin.call(f'{API}/candidates/{first}/review', 'POST',
                           {'action': 'publish', 'verified': False, 'reason': '验收公开线索'}, expected=200)['data']
    item_id = published['item_id']
    assert published['status'] == 'published' and item_id
    item = guest.call(f'{API}/items/{item_id}')['data']
    assert item['verification'] == 'unverified' and item['visibility'] == 'public'
    assert item['sources'][0]['url'] is None and item['sources'][0]['submitter']
    assert any(row['id'] == item_id for row in guest.call(API + '/items')['data'])
    admin.call(f'{API}/candidates/{first}/review', 'POST', {'action': 'publish', 'reason': ''}, expected=422)

    duplicate = member.call(API + '/submissions', 'POST', submission, expected=201)['data']['id']
    mine = member.call(API + '/mine')['data']
    assert next(row for row in mine if row['id'] == duplicate)['item_id'] == item_id
    admin.call(f'{API}/candidates/{duplicate}/review', 'POST',
               {'action': 'merge', 'item_id': item_id, 'verified': False, 'reason': '重复来源合并'})
    assert len(guest.call(f'{API}/items/{item_id}')['data']['sources']) == 1

    revised = member.call(API + '/submissions', 'POST',
                          submission | {'title': f'聚合验收活动更新 {marker}'}, expected=201)['data']['id']
    admin.call(f'{API}/candidates/{revised}/review', 'POST',
               {'action': 'update', 'item_id': item_id, 'verified': True, 'reason': '验收复核更新'})
    item = guest.call(f'{API}/items/{item_id}')['data']
    assert item['title'].endswith(marker) and '更新' in item['title']
    assert item['verification'] == 'verified' and len(item['sources']) == 1
    assert item['sources'][0]['url'] == url

    private = member.call(API + '/submissions', 'POST', {
        'origin': 'private_tip', 'category': 'notice',
        'title': f'聚合验收群内线索 {marker}', 'summary': '仅在隔离开发实例验证会员可见性。',
        'source_name': '学院群线索', 'source_url': '', 'show_submitter': False,
    }, expected=201)['data']['id']
    private_id = admin.call(f'{API}/candidates/{private}/review', 'POST',
                            {'action': 'publish', 'verified': False, 'reason': '验收会员可见线索'})['data']['item_id']
    guest.call(f'{API}/items/{private_id}', expected=404)
    assert private_id not in {row['id'] for row in guest.call(API + '/items')['data']}
    assert private_id in {row['id'] for row in member.call(API + '/items')['data']}
    assert member.call(f'{API}/items/{private_id}')['data']['sources'][0]['submitter'] is None

    rejected = member.call(API + '/submissions', 'POST',
                           submission | {'title': f'聚合验收拒绝项 {marker}'}, expected=201)['data']['id']
    admin.call(f'{API}/candidates/{rejected}/review', 'POST',
               {'action': 'reject', 'verified': False, 'reason': '验收拒绝无效信息'})
    assert next(row for row in member.call(API + '/mine')['data'] if row['id'] == rejected)['status'] == 'rejected'
    assert rejected not in {row['id'] for row in admin.call(API + '/queue')['data']}

    admin.call(API + '/sources', 'POST',
               {'kind': 'web', 'name': '验收来源', 'url': 'http://localhost/list'}, expected=422)
    listing = f'https://example.org/aggregation-list-{marker}'
    source = admin.call(API + '/sources', 'POST',
                        {'kind': 'web', 'name': '验收来源', 'url': listing}, expected=201)['data']
    assert source['status'] == 'pending_adapter'
    sources = admin.call(API + '/sources')['data']
    assert any(row['id'] == source['id'] and not row['enabled'] for row in sources)
    admin.call(API + '/sources', 'POST',
               {'kind': 'web', 'name': '验收来源', 'url': listing}, expected=422)
    print('PASS: aggregation submission, review, deduplication, visibility and source registration')


if __name__ == '__main__':
    main()
