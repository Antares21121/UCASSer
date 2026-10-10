import argparse
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('forum', ROOT / 'scripts/forum.py')
forum = importlib.util.module_from_spec(spec)
spec.loader.exec_module(forum)
audit_spec = importlib.util.spec_from_file_location('audit', ROOT / 'scripts/audit.py')
audit = importlib.util.module_from_spec(audit_spec)
audit_spec.loader.exec_module(audit)


class LauncherTests(unittest.TestCase):
    def test_audit_accepts_actual_empty_array_report(self):
        with mock.patch('sys.stdout', new=io.StringIO()):
            audit.check_report({'advisories': [], 'abandoned': []})

    def test_audit_rejects_any_advisory(self):
        with mock.patch('sys.stdout', new=io.StringIO()), self.assertRaises(ValueError):
            audit.check_report({'advisories': {'example/package': [{'title': 'reported vulnerability'}]}, 'abandoned': []})

    def test_audit_rejects_any_abandoned_package(self):
        with mock.patch('sys.stdout', new=io.StringIO()), self.assertRaises(ValueError):
            audit.check_report({'advisories': [], 'abandoned': {'old/package': None}})

    def test_env_supports_unicode_and_equals_without_expansion(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / '.env'
            path.write_text('# comment\nFORUM_TITLE=校园论坛\nDB_PASSWORD="a=b$HOME"\n', encoding='utf-8-sig')
            self.assertEqual(forum.read_env(path), {'FORUM_TITLE': '校园论坛', 'DB_PASSWORD': 'a=b$HOME'})

    def test_env_rejects_invalid_lines(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / '.env'
            for value in ('secret without equals', 'export KEY=value', 'key=value'):
                path.write_text(value)
                with self.assertRaises(ValueError):
                    forum.read_env(path)

    def test_backup_member_traversal_is_rejected(self):
        for name in ('../.env', '/etc/passwd', 'C:/secret', 'assets\\..\\.env'):
            stream = io.BytesIO()
            with zipfile.ZipFile(stream, 'w') as archive:
                archive.writestr(name, 'data')
            stream.seek(0)
            with zipfile.ZipFile(stream) as archive, self.assertRaises(ValueError):
                forum.safe_members(archive)

    def test_backup_symlink_is_rejected(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            info = zipfile.ZipInfo('storage/link')
            info.external_attr = (0o120777 << 16)
            archive.writestr(info, '/etc/passwd')
        stream.seek(0)
        with zipfile.ZipFile(stream) as archive, self.assertRaises(ValueError):
            forum.safe_members(archive)

    def test_multiline_env_rejected(self):
        with tempfile.TemporaryDirectory() as directory, self.assertRaises(ValueError):
            forum.write_env(Path(directory) / '.env', {'DB_PASSWORD': 'secret\nOTHER=value'})

    def make_forum(self, directory):
        args = argparse.Namespace(backend='native', profile='development', env_file=str(Path(directory) / '.env'), target_db='restored', archive=None, confirm='restored')
        with mock.patch.object(forum, 'ROOT', Path(directory)):
            instance = forum.Forum(args)
        instance.values = forum.read_env(ROOT / '.env.example')
        instance.values.update(DB_PASSWORD='local-test-only', DB_ROOT_PASSWORD='local-test-root', DB_NAME='restored')
        (Path(directory) / 'composer.lock').write_text(json.dumps({'packages': [{'name': 'flarum/core', 'version': 'v2.0.0'}]}))
        return instance

    def test_restore_refuses_configured_target_before_any_database_call(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            (Path(directory) / 'config.php').write_text('existing configuration')
            with mock.patch.object(instance, 'php') as php, self.assertRaises(ValueError):
                instance.restore()
            php.assert_not_called()

    def test_restore_requires_explicit_matching_database(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            for value in (None, 'other_database', 'bad;DROP'):
                instance.args.target_db = value
                with mock.patch.object(instance, 'php') as php, self.assertRaises(ValueError):
                    instance.restore()
                php.assert_not_called()

    def test_production_rejects_http_and_debug(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            instance.args.backend = 'docker'
            instance.values['APP_ENV'] = 'production'
            with self.assertRaises(ValueError):
                instance.validate()
            instance.values.update(APP_URL='https://forum.example.invalid', APP_DEBUG='true')
            with self.assertRaises(ValueError):
                instance.validate()
            instance.values['APP_DEBUG'] = 'false'
            instance.validate()

    def test_native_refuses_public_binding(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            instance.values['HTTP_BIND'] = '0.0.0.0'
            with self.assertRaises(ValueError):
                instance.validate()

    def test_production_refuses_rc_beta_and_old_major(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            instance.args.backend = 'docker'
            instance.values.update(APP_ENV='production', APP_URL='https://forum.example.invalid', APP_DEBUG='false')
            for version in ('v2.0.0-rc.8', 'v2.0.0-beta.8', '2.x-dev', 'v1.8.20'):
                (Path(directory) / 'composer.lock').write_text(json.dumps({'packages': [{'name': 'flarum/core', 'version': version}]}))
                with self.assertRaises(ValueError):
                    instance.validate()

    def test_failed_database_dump_does_not_publish_backup_and_restarts_web(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = self.make_forum(directory)
            (Path(directory) / 'config.php').write_text(forum.BRIDGE)
            with mock.patch.object(instance, 'php'), mock.patch.object(instance, 'stop_web'), mock.patch.object(instance, 'db_credentials') as creds, mock.patch.object(instance, 'db_bin'), mock.patch.object(instance, 'run', side_effect=RuntimeError('dump failed')), mock.patch.object(instance, 'start_process') as restart:
                creds.return_value.__enter__.return_value = ['--defaults-extra-file=ignored', 'restored']
                with self.assertRaises(RuntimeError):
                    instance.backup()
                self.assertEqual(list(instance.backups_dir().glob('*.zip')), [])
                restart.assert_called_once()


if __name__ == '__main__':
    unittest.main()
