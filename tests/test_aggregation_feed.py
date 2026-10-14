import importlib.util
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
import sys
import threading
import unittest


SCRIPTS = Path(__file__).resolve().parents[1] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location('aggregation_feed', SCRIPTS / 'aggregation_feed.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FeedHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        body = b'''<rss><channel><item><title>College lecture announcement</title>
        <link>https://mp.weixin.qq.com/s/AbCdEf123</link>
        <pubDate>Mon, 12 Oct 2026 09:00:00 +0800</pubDate></item>
        <item><title>Collector's own page</title><link>https://example.org/post/1</link></item>
        </channel></rss>'''
        self.send_response(200)
        self.send_header('Content-Type', 'application/rss+xml')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


class AggregationFeedTests(unittest.TestCase):
    def test_only_original_wechat_article_enters_snapshot(self):
        server = HTTPServer(('127.0.0.1', 0), FeedHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            entries = module.read_feed(f'http://127.0.0.1:{server.server_port}/rss/test', 20)
        finally:
            server.shutdown()
            server.server_close()
        self.assertEqual(len(entries), 1)
        self.assertEqual(entries[0]['url'], 'https://mp.weixin.qq.com/s/AbCdEf123')
        self.assertEqual(entries[0]['published_at'], '2026-10-12 01:00:00')

    def test_rejects_remote_feed(self):
        with self.assertRaises(ValueError):
            module.read_feed('https://example.org/feed.xml', 20)


if __name__ == '__main__':
    unittest.main()
