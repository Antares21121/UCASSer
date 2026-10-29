"""Exercise the separate operator permission and private chat image API on a test forum."""
import argparse
import base64
import secrets
import struct
import zlib

from campus_support import Api, account, environment


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    args = parser.parse_args()
    forum, guest, admin = environment(args.backend)
    operator = account(guest, admin, prefix='operator')
    outsider = account(guest, admin, prefix='visitor')
    try:
        guest.call('/api/campus/control', expected=(401, 403))
        admin.call('/api/campus/control', expected=403)
        operator['api'].call('/api/campus/control', expected=403)
        forum.php('flarum', 'campus:operator', operator['name'])
        dashboard = operator['api'].call('/api/campus/control')['data']
        assert 'collection_job' in dashboard and 'sources' in dashboard
        operator['api'].call('/api/campus/aggregation/queue')
        operator['api'].call('/api/campus/aggregation/sources')
        operator['api'].call('/api/campus/identity/queue')
        operator['api'].call('/api/campus/cases?status=open')
        marker = secrets.token_hex(5)
        candidate = outsider['api'].call('/api/campus/aggregation/submissions', 'POST', {
            'origin': 'public_web', 'category': 'notice',
            'title': '运营审核验收 ' + marker,
            'summary': '仅用于隔离测试环境的运营后台审核验收。',
            'source_name': '验收来源', 'source_url': 'https://example.org/operator-' + marker,
            'show_submitter': False,
        }, expected=201)['data']['id']
        published = operator['api'].call('/api/campus/aggregation/candidates/' + str(candidate) + '/review',
            'POST', {'action': 'publish', 'verified': False, 'reason': '运营人员审核验收'})['data']
        assert published['item_id'] and published['status'] == 'published'
        application = outsider['api'].call('/api/campus/identity', 'POST', {
            'consent': True, 'evidence': '仅用于隔离测试环境的校园身份申请验收。'
        }, expected=201)['data']['id']
        operator['api'].call('/api/campus/identity/' + str(application), 'PATCH', {
            'status': 'rejected', 'reason': '测试申请不对应真实身份'
        })
        operator['api'].call('/api/campus/control/collect', 'POST', {}, expected=202)
        job = operator['api'].call('/api/campus/control')['data']['collection_job']
        assert job['status'] in ('queued', 'running')
        admin.call('/api/campus/control/collect', 'POST', {}, expected=403)
        outsider['api'].call('/api/campus/control/import', 'POST', {'snapshot': '{}'}, expected=403)
        # A genuine encoded PNG is accepted. Until a message binds it to a dialog,
        # its URL must remain visible only to the sender.
        def chunk(kind, payload):
            return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload))
        image_bytes = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 1, 1, 8, 2, 0, 0, 0))
        image_bytes += chunk(b'IDAT', zlib.compress(b'\x00\xc8\x20\x20')) + chunk(b'IEND', b'')
        png = base64.b64encode(image_bytes).decode()
        image_response = operator['api'].call('/api/campus/chat-images', 'POST',
            {'data': 'data:image/png;base64,' + png}, expected=201)
        assert 'data' in image_response, image_response
        uploaded = image_response['data']['url']
        operator['api'].call(uploaded)
        outsider['api'].call(uploaded, expected=404)
        guest.call(uploaded, expected=(401, 403))
        print('Operator backend access, queueing, and private chat image access OK')
    finally:
        forum.php('flarum', 'campus:operator', operator['name'], '--revoke')


if __name__ == '__main__':
    main()
