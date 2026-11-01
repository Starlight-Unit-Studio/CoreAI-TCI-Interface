#!/usr/bin/env python3
"""Offline input regressions; database and Docker calls use explicit doubles."""
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import ember_inputs as inputs
import ember_py_worker as worker

UUID = 'a' * 32

class Cursor:
    def __init__(self, turn, ids, media):
        self.turn, self.ids, self.media = turn, ids, media
        self.query = ''
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def execute(self, query, params):
        self.query = query
        self.params = params
    def fetchone(self):
        return self.turn if 'FROM stu_chat_messages' in self.query else self.media
    def fetchall(self): return [{'media_uuid': uid} for uid in self.ids]
    def cursor(self): return self

class InputTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.media = self.root / 'var/console_media'
        self.media.mkdir(parents=True)
        self.source = self.media / (UUID + '.zip')
        with zipfile.ZipFile(self.source, 'w') as z:
            for i in range(12): z.writestr(f'pages/page{i}.html', f'<html>Original {i}</html>')
            z.writestr('style.css', 'body{color:red}')
        self.row = {'uuid': UUID, 'orig_name': 'CyberKawaii.zip', 'mime_type':'application/zip',
                    'rel_path': 'var/console_media/' + self.source.name,
                    'file_size': self.source.stat().st_size}
        self.job = {'id':1, 'channel':'console', 'recipient_uid':7, 'session_id':'s',
                    'trigger_message_id':9, 'trigger_revision':2}
    def tearDown(self): self.temp.cleanup()
    def stage(self): return inputs.stage_inputs(self.root, [self.row], self.root / 'input')
    def test_original_zip_more_than_nine_files(self):
        manifest = self.stage()
        target = self.root / 'input' / UUID
        self.assertEqual(target.read_bytes(), self.source.read_bytes())
        self.assertEqual(manifest['files'][0]['sha256'], hashlib.sha256(target.read_bytes()).hexdigest())
        self.assertEqual(manifest['files'][0]['path'], '/work/input/' + UUID)
        self.assertEqual(len(zipfile.ZipFile(target).namelist()), 13)
        self.assertEqual(target.stat().st_mode & 0o222, 0)
    def test_missing_file_fails(self):
        self.source.unlink()
        with self.assertRaises(inputs.InputError): self.stage()
    def test_size_mismatch_fails(self):
        self.row['file_size'] += 1
        with self.assertRaises(inputs.InputError): self.stage()
    def test_source_symlink_and_hardlink_fail(self):
        outside = self.root / 'outside'
        self.source.rename(outside)
        for link in ('symlink', 'hardlink'):
            if link == 'symlink': self.source.symlink_to(outside)
            else: os.link(outside, self.source)
            with self.assertRaises((inputs.InputError, OSError)):
                inputs.copy_input(self.root, self.row, self.root / link)
            self.source.unlink()
    def test_directory_symlink_fails(self):
        self.media.rename(self.root / 'real')
        self.media.symlink_to(self.root / 'real', target_is_directory=True)
        with self.assertRaises(inputs.InputError): self.stage()
    def test_db_path_cannot_escape_media(self):
        self.row['rel_path'] = 'var/console_media/../../api/config.local.php'
        with self.assertRaises(inputs.InputError): self.stage()
    def test_zip_traversal_symlink_duplicate_and_collision_fail(self):
        cases = [('../escape',), ('/absolute',), ('a\\b',), ('C:/a',), ('same','same'), ('a','a/b')]
        for names in cases:
            with self.subTest(names=names):
                with zipfile.ZipFile(self.source, 'w') as z:
                    for name in names: z.writestr(name, 'bad')
                with self.assertRaises(inputs.InputError): inputs.validate_zip(self.source)
        with zipfile.ZipFile(self.source, 'w') as z:
            info = zipfile.ZipInfo('link')
            info.create_system = 3
            info.external_attr = (stat.S_IFLNK | 0o777) << 16
            z.writestr(info, '../../secret')
        with self.assertRaises(inputs.InputError): inputs.validate_zip(self.source)
    def test_zip_expansion_limit(self):
        with patch.object(inputs, 'MAX_ZIP_BYTES', 10):
            with self.assertRaises(inputs.InputError): inputs.validate_zip(self.source)
    def test_owner_turn_revision_and_legacy_resolution(self):
        for ids, legacy in [([UUID], None), ([], UUID)]:
            conn = Cursor({'revision':2,'file_uuid':legacy}, ids, self.row)
            self.assertEqual(inputs.resolve_inputs(conn,self.job), [self.row])
            self.assertEqual(conn.params, (UUID,7))
            self.assertIn('user_id=%s',conn.query)
        for turn in (None, {'revision':3}):
            with self.assertRaises(inputs.InputError):
                inputs.resolve_inputs(Cursor(turn,[UUID],self.row),self.job)
        with self.assertRaises(inputs.InputError):
            inputs.resolve_inputs(Cursor({'revision':2},[UUID],None),self.job)
        self.assertEqual(inputs.resolve_inputs(None, {'channel':'global'}), [])
    def test_worker_readonly_mount_and_input_not_recovered_as_output(self):
        cfg = {**worker.DEFAULTS, 'STU_EMBER_PY_WORKDIR':str(self.root/'jobs')}
        def docker(cmd, **kwargs):
            if cmd[1] == 'rm': return subprocess.CompletedProcess(cmd,0,b'',b'')
            mounts = [cmd[i+1] for i,x in enumerate(cmd[:-1]) if x == '-v']
            mount = next(x for x in mounts if x.endswith(':/work/input:ro'))
            source = Path(mount.removesuffix(':/work/input:ro'))
            self.assertEqual((source/UUID).read_bytes(), self.source.read_bytes())
            self.assertFalse(any(str(self.media) in x for x in mounts))
            self.assertIn('EMBER_INPUT_MANIFEST=/work/input/manifest.json',cmd)
            return subprocess.CompletedProcess(cmd,0,b'',b'')
        with patch.object(worker,'PROJECT_ROOT',str(self.root)), patch.object(worker.subprocess,'run',side_effect=docker):
            result = worker.run_in_container(cfg,1,'pass',publish_outputs=True,inputs=[self.row])
        self.assertIsNone(result[4])
        self.assertEqual(result[5]['files'], [])
        self.assertEqual(result[5]['inputs'][0]['upload_id'], UUID)
        self.assertEqual(list((self.root/'jobs').glob('job*')), [])
    def test_missing_input_never_starts_docker(self):
        self.source.unlink()
        cfg = {**worker.DEFAULTS, 'STU_EMBER_PY_WORKDIR':str(self.root/'jobs')}
        with patch.object(worker,'PROJECT_ROOT',str(self.root)), patch.object(worker.subprocess,'run') as run:
            result = worker.run_in_container(cfg,1,'pass',publish_outputs=True,inputs=[self.row])
            run.assert_not_called()
        self.assertTrue(result[4].startswith('input_unavailable:'))
        self.assertEqual(result[5]['files'], [])
    def test_nonzero_job_status_is_error(self):
        with patch.object(worker,'run_in_container',return_value=('', '', 1, 5, None, worker.empty_manifest())), patch.object(worker,'finish_job',return_value=True) as finish:
            worker.process_job(None,worker.DEFAULTS,{'id':1,'code':'bad','channel':'global'})
        self.assertEqual(finish.call_args.args[2], 'error')
        self.assertEqual(finish.call_args.args[7], 'python_exit_nonzero')

if __name__ == '__main__': unittest.main(verbosity=2)
