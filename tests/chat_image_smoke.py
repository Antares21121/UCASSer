"""Check that a sent chat image is visible only to dialog members."""
import argparse
import base64
import struct
import zlib

from campus_support import account, environment


def png_bytes():
    def chunk(kind, payload):
        return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 1, 1, 8, 2, 0, 0, 0)) +
            chunk(b'IDAT', zlib.compress(b'\x00\xc8\x20\x20')) + chunk(b'IEND', b''))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    args = parser.parse_args()
    forum, guest, admin = environment(args.backend)
    recipient = account(guest, admin, prefix='chatrec')
    outsider = account(guest, admin, prefix='chatout')
    recipient['api'].call('/api/users/' + str(recipient['id']), 'PATCH', {'data': {
        'type': 'users', 'id': str(recipient['id']),
        'attributes': {'preferences': {'notify_messageReceived_email': False}}
    }})
    encoded = base64.b64encode(png_bytes()).decode()
    image_url = admin.call('/api/campus/chat-images', 'POST',
        {'data': 'data:image/png;base64,' + encoded}, expected=201)['data']['url']
    outsider['api'].call(image_url, expected=404)
    admin.call('/api/dialog-messages', 'POST', {'data': {
        'type': 'dialog-messages', 'attributes': {
            'content': '![图片](' + forum.values['APP_URL'] + image_url + ')',
            'users': [{'id': str(recipient['id'])}]
        }
    }}, expected=201)
    recipient['api'].call(image_url)
    outsider['api'].call(image_url, expected=404)
    guest.call(image_url, expected=(401, 403))
    print('Chat image is visible to dialog participants only')


if __name__ == '__main__':
    main()
