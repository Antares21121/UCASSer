import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest
from types import SimpleNamespace

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('inventory',ROOT/'scripts/instance_inventory.py')
inventory=importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)

class InventoryTest(unittest.TestCase):
    def test_collector_reads_counts_without_credentials_or_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'collector.db'
            db=sqlite3.connect(path)
            db.executescript('CREATE TABLE users (username TEXT,password_hash TEXT); CREATE TABLE feeds(id TEXT,mp_name TEXT);')
            db.execute('INSERT INTO users VALUES (?,?)',('operator','DO_NOT_EXPORT_PASSWORD'))
            db.execute('INSERT INTO feeds VALUES (?,?)',('MP_WXS_1','Public account'))
            db.commit()
            db.close()
            before=hashlib.sha256(path.read_bytes()).hexdigest()
            output=io.StringIO()
            with contextlib.redirect_stdout(output):
                exec(compile(inventory.COLLECTOR.replace("Path('/app/data')",f'Path({directory!r})'),'<collector>','exec'),{})
            report=json.loads(output.getvalue())
            self.assertEqual(report['sqlite_databases']['collector.db']['users']['count'],1)
            self.assertNotIn('DO_NOT_EXPORT_PASSWORD',output.getvalue())
            self.assertNotIn('operator',output.getvalue())
            self.assertEqual(before,hashlib.sha256(path.read_bytes()).hexdigest())

    def test_source_inventory_excludes_accidentally_tracked_secrets(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            subprocess.run(['git','init','-q'],cwd=root,check=True)
            for name,value in [('.env','PASSWORD=SECRET'),('private.key','SECRET KEY'),('index.php','<?php echo 1;')]:
                (root/name).write_text(value)
            subprocess.run(['git','add','-A'],cwd=root,check=True)
            subprocess.run(['git','-c','user.name=Inventory Test','-c','user.email=inventory@example.invalid','commit','-qm','fixture'],cwd=root,check=True)
            report=inventory.code_inventory(root)
            self.assertEqual(set(report['excluded_sensitive_paths']),{'.env','private.key'})
            self.assertNotIn('SECRET',json.dumps(report))
            self.assertEqual(report['groups']['index.php']['files'],1)

    def test_compare_detects_an_extension_disabled_with_unchanged_table_counts(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            base={'code':{'commit':'one','groups':{}},'database':{'tables':{'users':{'count':2}},'settings':{'extensions_enabled':'["ucasser-campus","flarum-messages"]'}}}
            target={'code':{'commit':'two','groups':{}},'database':{'tables':{'users':{'count':2}},'settings':{'extensions_enabled':'["ucasser-campus"]'}}}
            (root/'base.json').write_text(json.dumps(base))
            (root/'target.json').write_text(json.dumps(target))
            output=io.StringIO()
            with contextlib.redirect_stdout(output):
                inventory.compare(SimpleNamespace(baseline=root/'base.json',target=root/'target.json'))
            result=json.loads(output.getvalue())
            self.assertEqual(result['differences']['enabled_extensions']['only_baseline'],['flarum-messages'])
            self.assertNotIn('tables',result['differences'])

if __name__=='__main__':
    unittest.main()
