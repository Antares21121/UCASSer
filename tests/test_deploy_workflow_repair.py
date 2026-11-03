import argparse
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location('release', SCRIPTS / 'deploy_workflow_repair.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTest(unittest.TestCase):
    def attempt(self, *, fail=None, changed='compose.yaml', apply=True, mismatch=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'ucasser-test'; root.mkdir()
            stage = Path(directory) / 'ucasser-stage-fixture'; (stage / '.runtime').mkdir(parents=True)
            (root / '.env.test').write_text('COMPOSE_PROJECT_NAME=ucasser-test\nDB_PASSWORD=private-fixture-value\n')
            target = 'a' * 40
            report = stage / '.runtime/stage-report.json'
            report.write_text(json.dumps({'ready_for_review': True, 'source_commit': release.BASE,
                'stage_commit': target, 'source_project': 'ucasser-test', 'stage_project': stage.name}))
            calls = []; reads = 0
            def run(command, **kwargs):
                nonlocal reads
                calls.append(command)
                output = b''; code = 0
                if command[0] == 'git':
                    if 'rev-parse' in command: output = release.BASE.encode()
                    elif '--show-current' in command: output = b'2.x'
                    elif '--name-only' in command: output = changed.encode()
                if 'exec' in command and 'php' in command and '-r' in command:
                    reads += 1; output = json.dumps({'users': 2 + int(mismatch and reads == 2)}).encode()
                if 'backup' in command: output = b'Backup: fixture.zip\n'
                if fail and fail in command: code = 1; output = b'failure private-fixture-value'
                return subprocess.CompletedProcess(command, code, output, b'')
            args = argparse.Namespace(root=str(root), env_file='.env.test', target=target, report=str(report), apply=apply)
            with patch.object(release.subprocess, 'run', side_effect=run), patch.dict(release.os.environ, {}, clear=False), contextlib.redirect_stdout(io.StringIO()):
                error = None
                try: release.deploy(args)
                except (ValueError, RuntimeError) as e: error = str(e)
            diagnostic = root / '.runtime/workflow-deploy-error.log'
            if diagnostic.exists(): self.assertNotIn('private-fixture-value', diagnostic.read_text())
            return calls, error

    def test_preview_does_not_stop_services_or_change_source(self):
        calls, error = self.attempt(apply=False)
        self.assertIsNone(error)
        self.assertTrue(all(c[0] == 'git' for c in calls))

    def test_success_preserves_business_counts_and_restarts_services(self):
        calls, error = self.attempt()
        self.assertIsNone(error)
        self.assertTrue(any('backup' in c for c in calls))
        self.assertTrue(any('migrate' in c for c in calls))
        self.assertTrue(any(c[-4:] == ['start', 'app', 'web', 'runner'] for c in calls))

    def test_unexpected_edit_stops_before_mutation(self):
        calls, error = self.attempt(changed='extensions/campus/js/native.js')
        self.assertIn('Unexpected', error)
        self.assertFalse(any('stop' in c or 'merge' in c for c in calls))

    def test_migration_failure_is_redacted_and_services_resume_without_reset(self):
        calls, error = self.attempt(fail='migrate')
        self.assertIn('private diagnostic', error)
        self.assertTrue(any(c[-4:] == ['start', 'app', 'web', 'runner'] for c in calls))
        self.assertFalse(any('reset' in c for c in calls))

    def test_count_mismatch_is_not_reported_as_success(self):
        _, error = self.attempt(mismatch=True)
        self.assertIn('preservation check failed', error)

    def test_stale_or_other_instance_rehearsal_is_rejected(self):
        with self.assertRaises(ValueError): release.validate_report({'ready_for_review': True}, release.BASE, 'a'*40, 'ucasser-test')
