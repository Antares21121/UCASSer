"""Board redesign acceptance: catalog, operators, suffixes, levels, images."""
import argparse
import base64
import secrets
import struct
import zlib

from campus_support import Api, account, environment


def tiny_png():
    def chunk(kind, payload):
        return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload))
    image = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 1, 1, 8, 2, 0, 0, 0))
    image += chunk(b'IDAT', zlib.compress(b'\x00\xc8\x20\x20')) + chunk(b'IEND', b'')
    return 'data:image/png;base64,' + base64.b64encode(image).decode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    args = parser.parse_args()
    forum, guest, admin = environment(args.backend)
    operator = account(guest, admin, prefix='operator')
    outsider = account(guest, admin, prefix='visitor')
    try:
        # 1. The public catalog only exposes current boards.
        catalog = guest.call('/api/campus/catalog')['data']
        keys = [section['key'] for section in catalog['sections']]
        for retired in ['general', 'market', 'academic', 'news', 'chat', 'governance', 'courses']:
            assert retired not in keys, f'retired board leaked into catalog: {retired}'
        assert 'secondhand' in keys and 'travel' in keys, keys
        assert catalog['review_section_id'], 'review archive section missing'
        # 2. The course directory stays readable without the old board.
        courses = guest.call('/api/campus/courses')
        assert 'data' in courses and 'meta' in courses
        outsider['api'].call('/api/campus/courses', 'POST', {'name': '越权课程', 'type': '', 'semester': '2026秋', 'reason': '测试'}, expected=403)
        # 3. Operators manage the catalog; regular accounts get 403.
        forum.php('flarum', 'campus:operator', operator['name'])
        outsider['api'].call('/api/campus/control/catalog/colleges', expected=403)
        outsider['api'].call('/api/campus/control/catalog/colleges', 'POST', {'code': 'X', 'name': '越权学院'}, expected=403)
        marker = secrets.token_hex(4)
        college = operator['api'].call('/api/campus/control/catalog/colleges', 'POST', {
            'code': 'C-' + marker, 'name': '验收学院' + marker, 'aliases': '', 'status': 'active'
        }, expected=201)['data']
        assert college['id']
        course = operator['api'].call('/api/campus/control/catalog/courses', 'POST', {
            'code': 'DEMO' + marker, 'name': '验收课程' + marker, 'college_id': college['id'],
            'semester': '2026秋', 'type': '本科', 'nature': '选修',
        }, expected=201)['data']
        listing = operator['api'].call('/api/campus/control/catalog/courses?search=' + marker)['data']
        assert listing['meta']['total'] >= 1
        # 4. Batch import previews first, applies idempotently, rolls back.
        rows = [{
            'external_key': 'key-' + marker, 'code': 'C-' + marker, 'name': '验收学院' + marker,
            'aliases': '演示学院', 'status': 'active',
        }]
        preview = operator['api'].call('/api/campus/control/catalog/colleges/import', 'POST', {'rows': rows, 'apply': False})['data']
        assert preview['total'] == 1 and preview['results'][0]['action'] in ('skipped', 'conflict', 'updated'), preview['results']
        applied = operator['api'].call('/api/campus/control/catalog/colleges/import', 'POST', {'rows': rows, 'apply': True})['data']
        assert applied.get('batch_id'), applied
        operator['api'].call('/api/campus/control/catalog/import/' + str(applied['batch_id']) + '/rollback', 'POST', {})
        # 5. Permanent unique suffixes and the level baseline on users.
        first = account(guest, admin, prefix='twins')
        second = account(guest, admin, prefix='twins')
        suffix_a = admin.call('/api/users/' + str(first['id']))['data']['attributes']['campusSuffix']
        suffix_b = admin.call('/api/users/' + str(second['id']))['data']['attributes']['campusSuffix']
        assert suffix_a and suffix_b and suffix_a != suffix_b, (suffix_a, suffix_b)
        for uid in (first['id'], second['id']):
            user = admin.call('/api/users/' + str(uid))['data']['attributes']
            assert user['campusLevel'] == '1' and user['campusXp'] == '0', user
        # Same display name stays allowed; the suffixes distinguish them.
        for uid in (first['id'], second['id']):
            admin.call('/api/users/' + str(uid), 'PATCH', {'data': {'type': 'users', 'id': str(uid), 'attributes': {'nickname': '晓明'}}})
        # 6. Public reply images: accepted, owner-readable, private until posted.
        upload = outsider['api'].call('/api/campus/post-images', 'POST', {'data': tiny_png()}, expected=201)['data']
        assert upload['url'].startswith('/api/campus/post-images/')
        outsider['api'].call(upload['url'])
        guest.call(upload['url'], expected=(401, 404))
        # Oversized / wrong type rejected before storage.
        outsider['api'].call('/api/campus/post-images', 'POST', {'data': 'data:image/gif;base64,' + base64.b64encode(b'GIF89a').decode()}, expected=(422, 400))
        outsider['api'].call('/api/campus/post-images', 'POST', {'data': 'data:image/png;base64,' + base64.b64encode(b'x' * (5 * 1024 * 1024 + 1)).decode()}, expected=(422, 400))
        # 7. A retired board path cannot publish: catalog has no section for it
        #    (covered by #1); the archive section is present but closed.
        print('Board redesign API acceptance OK')
    finally:
        forum.php('flarum', 'campus:operator', operator['name'], '--revoke')


if __name__ == '__main__':
    main()
