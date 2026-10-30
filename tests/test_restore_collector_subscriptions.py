import contextlib
import copy
import io
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from restore_collector_subscriptions import SEED_FEEDS, inspect_publishers, subscriptions_from, verify_publishers


class SubscriptionRecoveryTests(unittest.TestCase):
    def setUp(self):
        manifest = json.loads((Path(__file__).resolve().parents[1] / 'data/reconciliation/public-content.json').read_text(encoding='utf-8'))
        self.rows = subscriptions_from(manifest)

    def test_reviewed_manifest_excludes_virtual_and_duplicate_publishers(self):
        self.assertEqual(len(self.rows), 19)
        for rows in [self.rows[:-1], self.rows[:-1] + [self.rows[0]], self.rows[:-1] + [{'public_feed_id': 'FEATURED', 'publisher_name': '精选文章'}],
                     self.rows[:-1] + [self.rows[-1] | {'publisher_name': 'bad\nname'}]]:
            with self.assertRaises(ValueError):
                subscriptions_from({'public_subscriptions': rows})

    def test_all_live_publisher_identities_must_match(self):
        rows = self.rows

        class API:
            def __init__(self): self.checked = []
            def request(self, path, payload):
                if path == '/weread/test': return {'ok': True, 'cookie': 'PRIVATE_COOKIE'}
                feed_id = json.loads(payload)['mp_id']
                self.checked.append(feed_id)
                name = next(row['publisher_name'] for row in rows if row['public_feed_id'] == feed_id)
                return {'mp_id': feed_id, 'mp_name': name, 'private': 'PRIVATE_TOKEN'}

        api = API()
        with contextlib.redirect_stdout(io.StringIO()) as out:
            verify_publishers(api, rows)
        self.assertEqual(api.checked, [row['public_feed_id'] for row in rows])
        self.assertNotIn('PRIVATE_', out.getvalue())
        mismatched = copy.deepcopy(rows)
        mismatched[-1]['publisher_name'] = 'Wrong publisher'
        identities = inspect_publishers(API(), mismatched)
        self.assertEqual(identities[-1]['current_name'], rows[-1]['publisher_name'])
        self.assertFalse(identities[-1]['matches'])
        with self.assertRaises(RuntimeError), contextlib.redirect_stdout(io.StringIO()):
            verify_publishers(API(), mismatched)

    def test_sql_preview_rollback_apply_repeat_and_preserve_disabled_subscriptions(self):
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / 'collector.sqlite'
            c = sqlite3.connect(database)
            c.executescript('CREATE TABLE feeds(id TEXT PRIMARY KEY,mp_name TEXT,mp_cover TEXT,mp_intro TEXT,status INTEGER,sync_time INTEGER,update_time INTEGER,created_at TEXT,updated_at TEXT,faker_id TEXT); CREATE TABLE articles(id TEXT); CREATE TABLE users(id TEXT,password_hash TEXT); INSERT INTO articles VALUES("existing-article"); INSERT INTO users VALUES("original-admin","original-hash");')
            code = SEED_FEEDS.replace("'/app/data/db.db'", repr(str(database)))

            def execute(apply, rows=None):
                payload = {'apply': apply, 'subscriptions': self.rows if rows is None else rows}
                with patch('sys.stdin', io.StringIO(json.dumps(payload))), contextlib.redirect_stdout(io.StringIO()) as out:
                    exec(compile(code, '<subscription-recovery>', 'exec'), {})
                return json.loads(out.getvalue().split('UCASSER_RESULT:')[-1])

            self.assertEqual(execute(False)['added'], 19)
            self.assertEqual(c.execute('SELECT COUNT(*) FROM feeds').fetchone()[0], 0)
            self.assertEqual(execute(True)['feeds_after'], 19)
            c.execute('UPDATE feeds SET status=0,mp_intro="owner note" WHERE id=?', (self.rows[0]['public_feed_id'],)); c.commit()
            before = c.execute('SELECT * FROM feeds ORDER BY id').fetchall()
            again = execute(True)
            self.assertEqual((again['added'], again['existing_preserved']), (0, 19))
            self.assertEqual(c.execute('SELECT * FROM feeds ORDER BY id').fetchall(), before)
            self.assertEqual(c.execute('SELECT * FROM articles').fetchall(), [('existing-article',)])
            self.assertEqual(c.execute('SELECT * FROM users').fetchall(), [('original-admin', 'original-hash')])
            c.close()

    def test_late_identity_conflict_rolls_back_all_new_feed_inserts(self):
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / 'collector.sqlite'
            c = sqlite3.connect(database)
            c.executescript('CREATE TABLE feeds(id TEXT PRIMARY KEY,mp_name TEXT,mp_cover TEXT,mp_intro TEXT,status INTEGER,sync_time INTEGER,update_time INTEGER,created_at TEXT,updated_at TEXT,faker_id TEXT); CREATE TABLE articles(id TEXT);')
            c.execute('INSERT INTO feeds(id,mp_name) VALUES (?,?)', (self.rows[-1]['public_feed_id'], 'Existing different identity')); c.commit()
            original = c.execute('SELECT * FROM feeds').fetchall()
            code = SEED_FEEDS.replace("'/app/data/db.db'", repr(str(database)))
            with self.assertRaises(AssertionError), patch('sys.stdin', io.StringIO(json.dumps({'apply': True, 'subscriptions': self.rows}))):
                exec(compile(code, '<subscription-recovery>', 'exec'), {})
            self.assertEqual(c.execute('SELECT * FROM feeds').fetchall(), original)
            c.close()


if __name__ == '__main__':
    unittest.main()
