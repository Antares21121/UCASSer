import contextlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
from pathlib import Path
import sys
import threading
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from authorize_collector import CollectorAPI, authorize, confirm_url, terminal_qr


class FakeCollector:
    def __init__(self, statuses, configured=False, invalid=False):
        self.statuses = iter(statuses)
        self.configured = configured
        self.invalid = invalid
        self.paths = []

    def request(self, path, payload=None):
        self.paths.append(path)
        if path == '/weread':
            return {'mp_configured': self.configured, 'cookie': 'PRIVATE_COOKIE'}
        if path == '/weread/qr/code':
            return {'uid': 'PRIVATE_SCAN_IDENTIFIER', 'code': 'https://untrusted.example/do-not-fetch'}
        if path == '/weread/qr/status':
            logged = next(self.statuses)
            self.configured = logged
            return {'login_status': logged, 'data': {'accessToken': 'PRIVATE_TOKEN', 'cookies': 'PRIVATE_COOKIE',
                                                    'logicCode': 'COOKIE_INVALID' if self.invalid else ''}}
        if path == '/weread/test':
            return {'ok': not self.invalid, 'private_response_field': 'PRIVATE_DATA'}
        if path == '/weread/qr/over':
            return {'cookies': 'PRIVATE_COOKIE', 'accessToken': 'PRIVATE_TOKEN'}
        raise AssertionError('Unexpected operation')


class TerminalAuthorizationTests(unittest.TestCase):
    def setUp(self):
        self.matrix = [[False] * 37 for _ in range(37)]

    def execute(self, api, **options):
        with contextlib.redirect_stdout(io.StringIO()) as out:
            result = authorize(api, lambda url: self.matrix, **options)
        text = out.getvalue()
        for secret in ('PRIVATE_COOKIE', 'PRIVATE_TOKEN', 'PRIVATE_DATA', 'PRIVATE_SCAN_IDENTIFIER', 'untrusted.example'):
            self.assertNotIn(secret, text)
        return result, text

    def test_status_does_not_request_a_qr_or_disclose_credentials(self):
        api = FakeCollector([])
        result, text = self.execute(api)
        self.assertEqual(result, 0)
        self.assertIn('authorization_required', text)
        self.assertEqual(api.paths, ['/weread'])

    def test_scan_polls_verifies_and_cleans_up_without_subscription_changes(self):
        api = FakeCollector([False, True])
        result, text = self.execute(api, scan=True, sleep=lambda seconds: None)
        self.assertEqual(result, 0)
        self.assertIn('"authorization_verified": true', text)
        self.assertEqual(api.paths[-2:], ['/weread/test', '/weread/qr/over'])
        self.assertEqual(api.paths.count('/weread/qr/code'), 1)
        self.assertEqual(api.paths.count('/weread/qr/status'), 2)

    def test_verified_saved_authorization_does_not_get_replaced(self):
        api = FakeCollector([], configured=True)
        result, text = self.execute(api, scan=True)
        self.assertEqual(result, 0)
        self.assertIn('"qr_requested": false', text)
        self.assertEqual(api.paths, ['/weread', '/weread/test'])

    def test_timeout_invalid_cookie_and_narrow_terminal_always_close_qr(self):
        api = FakeCollector([])
        self.assertEqual(self.execute(api, scan=True, wait=0)[0], 2)
        self.assertEqual(api.paths[-1], '/weread/qr/over')
        api = FakeCollector([True], invalid=True)
        with self.assertRaises(RuntimeError):
            self.execute(api, scan=True)
        self.assertEqual(api.paths[-1], '/weread/qr/over')
        api = FakeCollector([])
        with self.assertRaises(ValueError):
            self.execute(api, scan=True, columns=20)
        self.assertEqual(api.paths[-1], '/weread/qr/over')

    def test_fixed_weread_origin_and_matrix_validation(self):
        self.assertEqual(confirm_url('fixture_uid123'), 'https://weread.qq.com/web/confirm?uid=fixture_uid123')
        for value in [None, '', 'abc\nPRIVATE', 'uid&redirect=http://example.org', 'x' * 257]:
            with self.assertRaises(ValueError):
                confirm_url(value)
        with self.assertRaises(ValueError):
            terminal_qr([[False] * 29] * 30)
        with self.assertRaises(ValueError):
            terminal_qr([['\x1b'] * 29] * 29)

    def test_http_redirect_cannot_forward_the_login_password_or_token(self):
        calls = []

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                calls.append(self.path)
                self.rfile.read(int(self.headers.get('Content-Length', 0)))
                self.send_response(307)
                self.send_header('Location', '/credential-sink')
                self.end_headers()

            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            with self.assertRaises(Exception):
                CollectorAPI('http://127.0.0.1:' + str(server.server_port), {'USERNAME': 'fixture', 'PASSWORD': 'PRIVATE_PASSWORD'})
            self.assertEqual(calls, ['/api/v1/wx/auth/login'])
        finally:
            server.shutdown()
            server.server_close()
            worker.join()


if __name__ == '__main__':
    unittest.main()
