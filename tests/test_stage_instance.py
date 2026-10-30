import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('stage',ROOT/'scripts/stage_instance.py')
stage=importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage)


class StagePolicyTest(unittest.TestCase):
    def test_mount_points_exist_before_source_is_mounted_read_only(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            stage.prepare_mount_points(root)
            for relative in ['vendor','storage','public/assets']:self.assertTrue((root/relative).is_dir())

    def test_target_must_be_new_sibling_with_separate_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            parent=Path(directory)
            source=parent/'live';source.mkdir()
            target=parent/'ucasser-stage-check'
            self.assertEqual(stage.validate_target(source,target,'ucasser-stage-check','live',18081,'live_db'),'ucasser_stage_check')
            for bad,project,source_project,port,db in [
                (source,'ucasser-stage-check','live',18081,'live_db'),
                (source/'ucasser-stage-check','ucasser-stage-check','live',18081,'live_db'),
                (target,'ucasser-stage-check','ucasser-stage-check',18081,'live_db'),
                (target,'ucasser-stage-check','live',443,'live_db'),
                (target,'ucasser-stage-check','live',18081,'ucasser_stage_check'),
            ]:
                with self.assertRaises(ValueError):stage.validate_target(source,bad,project,source_project,port,db)
            target.mkdir()
            with self.assertRaises(ValueError):stage.validate_target(source,target,'ucasser-stage-check','live',18081,'live_db')

    def test_stage_has_no_public_listener_worker_or_source_database_credentials(self):
        config=stage.stage_compose(Path('/isolated/ucasser-stage-test'),'ucasser-stage-test',{'app':'image-app','db':'image-db','web':'image-web'},18081,'ucasser_stage_test')
        self.assertTrue(config['networks']['default']['internal'])
        self.assertEqual(config['services']['web']['networks'],['default','preview'])
        self.assertNotIn('networks',config['services']['app'])
        self.assertNotIn('networks',config['services']['db'])
        self.assertEqual(set(config['services']),{'app','db','web'})
        self.assertEqual(config['services']['web']['ports'],['127.0.0.1:18081:80'])
        self.assertNotIn('ports',config['services']['db'])
        self.assertNotIn('ports',config['services']['app'])
        self.assertEqual(config['services']['app']['environment']['DB_ROOT_PASSWORD'],'')
        self.assertEqual(config['services']['db']['environment']['MARIADB_DATABASE'],'ucasser_stage_test')
        self.assertEqual(config['services']['db']['environment']['MARIADB_PASSWORD'],'${DB_PASSWORD}')
        mounts=config['services']['app']['volumes']
        self.assertTrue(mounts[0]['read_only'])
        self.assertTrue(all(x.startswith('ucasser-stage-test_') for x in mounts[1:]))
        self.assertTrue(all(x['name'].startswith('ucasser-stage-test_') for x in config['volumes'].values()))
        for service in config['services'].values():
            for mount in service.get('volumes',[]):
                if isinstance(mount,str):self.assertIn(mount.split(':')[0],config['volumes'])


if __name__=='__main__':unittest.main()
