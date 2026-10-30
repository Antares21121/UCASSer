import copy
import contextlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import sqlite3
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from connect_collector import CREATE_USER, MARKER, connection_plan, load_managed, pristine, private_write


class CollectorConnectionTests(unittest.TestCase):
    def setUp(self):
        self.collector = {'Name': '/ucasser-we-mp-rss', 'NetworkSettings': {
            'Ports': {'8001/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '8001'}]}, 'Networks': {'bridge': {}}}}
        self.app = {'NetworkSettings': {'Networks': {'ucasser-test_default': {}}}}
        self.network = {'Labels': {'com.docker.compose.project': 'ucasser-test'}}

    def test_use_container_dns_keep_host_listener_private(self):
        before = copy.deepcopy(self.collector)
        p = connection_plan(self.collector, self.app, self.network, 'ucasser-test')
        self.assertEqual(p['base_url'], 'http://ucasser-we-mp-rss:8001')
        self.assertEqual(p['host_url'], 'http://127.0.0.1:8001')
        self.assertFalse(p['already_connected'])
        self.assertEqual(self.collector, before)
        self.collector['NetworkSettings']['Networks']['ucasser-test_default'] = {}
        self.assertTrue(connection_plan(self.collector, self.app, self.network, 'ucasser-test')['already_connected'])

    def test_reject_public_port_or_wrong_project_network(self):
        for address in ['0.0.0.0', '::', '192.0.2.1']:
            c = copy.deepcopy(self.collector)
            c['NetworkSettings']['Ports']['8001/tcp'][0]['HostIp'] = address
            with self.assertRaises(ValueError):
                connection_plan(c, self.app, self.network, 'ucasser-test')
        with self.assertRaises(ValueError):
            connection_plan(self.collector, self.app, {'Labels': {'com.docker.compose.project': 'other'}}, 'ucasser-test')
        with self.assertRaises(ValueError):
            connection_plan(self.collector, {'NetworkSettings': {'Networks': {}}}, self.network, 'ucasser-test')

    def test_never_initialize_existing_subscription_or_account_data(self):
        state = {'feeds': 0, 'articles': 0, 'users': [{'role': 'admin', 'active': True}]}
        self.assertTrue(pristine(state))
        for change in [{'feeds': 1}, {'articles': 1}, {'users': []}, {'users': [{'role': 'user', 'active': True}]},
                       {'users': [{'role': 'admin', 'active': False}]}, {'users': state['users'] * 2}]:
            self.assertFalse(pristine(state | change))

    def test_managed_secret_is_private_reusable_and_never_overwritten(self):
        plan = connection_plan(self.collector, self.app, self.network, 'ucasser-test')
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'we-mp-rss.env'
            text = MARKER + '\nUSERNAME=' + plan['username'] + '\nPASSWORD=' + 'a' * 48 + '\nBASE_URL=' + plan['base_url'] + '\n'
            private_write(path, text)
            before = path.read_bytes()
            if os.name == 'posix':
                self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertEqual(load_managed(path, plan)['USERNAME'], plan['username'])
            with self.assertRaises(FileExistsError):
                private_write(path, 'must not overwrite')
            self.assertEqual(path.read_bytes(), before)
            with self.assertRaises(ValueError):
                load_managed(path, plan | {'base_url': 'http://other:8001'})
            path.write_text('USERNAME=existing\nPASSWORD=leave-this-alone\n')
            with self.assertRaises(ValueError):
                load_managed(path, plan)

    def test_real_sqlite_preview_rollback_and_admin_preservation(self):
        # Exercise real SQLite transactions. Cryptographic compatibility is separately
        # exercised with the real collector; only its bcrypt module is stubbed here.
        bcrypt = types.SimpleNamespace(hashpw=lambda p, s: b'fixture-hash', gensalt=lambda: b'fixture-salt', checkpw=lambda p, h: h == b'fixture-hash')
        with tempfile.TemporaryDirectory() as directory:
            db_path = Path(directory) / 'db.db'
            auth = Path(directory) / 'auth.py'
            auth.write_text('class PasswordHasher:\n def hash(self,p): return bcrypt.hashpw(p,bcrypt.gensalt())\n def verify(self,p,h): return bcrypt.checkpw(p,h)\n')
            c = sqlite3.connect(db_path)
            c.executescript('CREATE TABLE users(id TEXT,username TEXT UNIQUE,password_hash TEXT,is_active INTEGER,role TEXT,permissions TEXT,nickname TEXT,created_at TEXT,updated_at TEXT); CREATE TABLE feeds(id TEXT); CREATE TABLE articles(id TEXT);')
            owner = ('owner-id', 'admin', 'original-fixture-owner-hash', 1, 'admin', '[]', 'Owner', 'before', 'before')
            c.execute('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?)', owner); c.commit()
            code = CREATE_USER.replace("'/app/core/auth.py'", repr(str(auth))).replace("'/app/data/db.db'", repr(str(db_path)))
            payload = {'username': 'ucasser_connector_fixture', 'password': 'a' * 48, 'apply': False}
            def execute(data):
                with patch.dict(sys.modules, {'bcrypt': bcrypt}), patch('sys.stdin', io.StringIO(json.dumps(data))), contextlib.redirect_stdout(io.StringIO()) as out:
                    exec(compile(code, '<collector-user-helper>', 'exec'), {})
                return out.getvalue()
            self.assertIn('"model_validated": true', execute(payload))
            self.assertEqual(c.execute('SELECT * FROM users').fetchall(), [owner])
            execute(payload | {'apply': True})
            self.assertEqual(c.execute('SELECT * FROM users WHERE username=?', ('admin',)).fetchone(), owner)
            self.assertEqual(c.execute('SELECT role FROM users WHERE username=?', (payload['username'],)).fetchone()[0], 'user')
            with self.assertRaises(AssertionError):
                execute(payload | {'apply': True})
            self.assertEqual(c.execute('SELECT COUNT(*) FROM users').fetchone()[0], 2)
            c.close()


if __name__ == '__main__':
    unittest.main()
