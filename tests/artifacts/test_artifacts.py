"""Real filesystem/PHP contract tests; Docker/DB boundaries are explicit doubles."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import ember_artifacts as artifacts
import ember_py_worker as worker


class ArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.base = self.root / 'worker'
        self.out = self.root / 'out'
        self.base.mkdir()
        self.out.mkdir()

    def tearDown(self):
        self.temp.cleanup()

    def collect(self, **kw):
        return artifacts.collect_outputs(self.out, self.base, 7, **kw)

    def php(self, manifest, delete=False, **kwargs):
        code = "define('STU_EMBER_PY_WORKDIR',$argv[1]); require $argv[2]; $m=file_get_contents('php://stdin'); "
        code += "try { $files=coreui_console_artifact_validate_manifest(['id'=>7,'status'=>'done','artifacts_json'=>$m]); "
        code += "echo json_encode(coreui_console_artifact_delete_files(array_column($files,'storage_key')));" if delete else "echo json_encode($files);"
        code += " } catch(Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(2); }"
        return subprocess.run(['php', '-r', code, str(self.base), str(ROOT / 'api/console_artifact_store.php')],
                              input=json.dumps(manifest).encode(), capture_output=True, **kwargs)

    def test_html_zip_unicode_empty_and_php_contract(self):
        name = 'Gr\u00fc\u00dfe \u661f.html'
        body = b'<!doctype html><title>Test</title><script>window.NOT_ALLOWED=1</script>'
        (self.out / name).write_bytes(body)
        (self.out / 'empty.txt').write_bytes(b'')
        with zipfile.ZipFile(self.out / 'Projekt.zip', 'w') as z:
            z.writestr(name, body)
            z.writestr('data/info.txt', 'ZIP content')
        manifest = self.collect()
        self.assertEqual(len(manifest['files']), 3)
        self.assertEqual(manifest['warnings'], [])
        source = {p.name:p.read_bytes() for p in self.out.iterdir()}
        shutil.rmtree(self.out)
        for f in manifest['files']:
            saved = self.base / 'artifacts' / f['storage_key']
            self.assertEqual(saved.read_bytes(), source[f['name']])
            self.assertEqual(hashlib.sha256(saved.read_bytes()).hexdigest(), f['sha256'])
            self.assertEqual(saved.stat().st_mode & 0o777, 0o640)
        result = self.php(manifest)
        self.assertEqual(result.returncode, 0, result.stderr)
        archive = next(f for f in manifest['files'] if f['name'].endswith('.zip'))
        with zipfile.ZipFile(self.base / 'artifacts' / archive['storage_key']) as z:
            self.assertIsNone(z.testzip())
            self.assertEqual(z.read(name), body)

    @unittest.skipUnless(os.geteuid() == 0, 'requires root to exercise PHP service UID/GID 33')
    def test_php_service_can_read_and_delete(self):
        self.root.chmod(0o755)
        os.chown(self.base, 0, 33)
        self.base.chmod(0o770)
        (self.out / 'x').write_bytes(b'content')
        m = self.collect()
        result = self.php(m, delete=True, user=33, group=33, extra_groups=[])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['artifact_files_deleted'], 1)
        self.assertFalse((self.base / 'artifacts' / '7').exists())

    def test_php_rejects_same_size_tamper(self):
        (self.out / 'x').write_bytes(b'good')
        m = self.collect()
        (self.base / 'artifacts' / m['files'][0]['storage_key']).write_bytes(b'evil')
        self.assertEqual(self.php(m).returncode, 2)

    def test_php_rejects_missing_file(self):
        (self.out / 'x').write_bytes(b'x')
        m = self.collect()
        (self.base / 'artifacts' / m['files'][0]['storage_key']).unlink()
        self.assertEqual(self.php(m).returncode, 2)

    def test_php_rejects_forged_job_or_path(self):
        (self.out / 'x').write_bytes(b'x')
        m = self.collect()
        for key in ('8/' + 'a'*32, '../outside'):
            m['files'][0]['storage_key'] = key
            self.assertEqual(self.php(m).returncode, 2)

    def test_symlink_hardlink_fifo_and_directory_link(self):
        outside = self.root / 'secret'
        outside.write_text('secret')
        (self.out / 'symlink').symlink_to(outside)
        (self.out / 'linked-dir').symlink_to(self.root, target_is_directory=True)
        os.link(outside, self.out / 'hardlink')
        os.mkfifo(self.out / 'fifo')
        (self.out / 'good.txt').write_text('good')
        m = self.collect()
        self.assertEqual([f['name'] for f in m['files']], ['good.txt'])
        self.assertIn('non_regular_or_link', m['warnings'])
        self.assertEqual(outside.read_text(), 'secret')

    def test_invalid_names_and_utf8(self):
        for name in ('bad\\name', 'bad\nname', 'bad\x7fname', 'bad\udcff'):
            (self.out / name).write_bytes(b'x')
        self.assertEqual(self.collect()['files'], [])
        self.assertFalse(artifacts.valid_name('\u661f'*86))

    def test_nested_duplicate_names(self):
        for name in ('a','b'):
            d = self.out / name
            d.mkdir()
            (d / 'index.html').write_text(name)
        m = self.collect()
        self.assertEqual(len(m['files']), 2)
        self.assertNotEqual(m['files'][0]['storage_key'], m['files'][1]['storage_key'])

    def test_oversize_does_not_hide_good_file(self):
        with (self.out / 'large').open('wb') as f:
            f.truncate(artifacts.MAX_FILE_BYTES+1)
        (self.out / 'good').write_bytes(b'good')
        m = self.collect()
        self.assertEqual([f['name'] for f in m['files']], ['good'])
        self.assertIn('file_size_limit', m['warnings'])

    def test_count_limit(self):
        for i in range(12): (self.out / str(i)).write_bytes(b'x')
        m = self.collect()
        self.assertEqual(len(m['files']), 10)
        self.assertIn('file_count_limit', m['warnings'])

    def test_total_limit(self):
        for i in range(4): (self.out / str(i)).write_bytes(b'abcd')
        with mock.patch.object(artifacts, 'MAX_TOTAL_BYTES', 10): m = self.collect()
        self.assertEqual(len(m['files']), 2)
        self.assertIn('total_size_limit', m['warnings'])

    def test_entry_and_depth_limits(self):
        for i in range(12): (self.out / str(i)).mkdir()
        with mock.patch.object(artifacts, 'MAX_ENTRIES', 10):
            self.assertIn('entry_limit', self.collect()['warnings'])
        shutil.rmtree(self.out)
        self.out.mkdir()
        d = self.out
        for _ in range(10):
            d = d / 'sub'
            d.mkdir()
        (d / 'hidden').write_bytes(b'x')
        m = self.collect()
        self.assertEqual(m['files'], [])
        self.assertIn('depth_limit', m['warnings'])

    def test_partial_and_repeat_run(self):
        (self.out / 'x').write_bytes(b'x')
        a = self.collect(partial=True)
        b = self.collect()
        self.assertTrue(a['files'][0]['partial'])
        self.assertFalse(b['files'][0]['partial'])
        self.assertNotEqual(a['files'][0]['storage_key'], b['files'][0]['storage_key'])
        artifacts.discard_outputs(self.base, a)
        self.assertTrue((self.base / 'artifacts' / b['files'][0]['storage_key']).is_file())

    def test_store_symlink_cannot_publish_outside(self):
        d = self.root / 'outside'
        d.mkdir()
        (self.base / 'artifacts').symlink_to(d, target_is_directory=True)
        (self.out / 'x').write_bytes(b'x')
        m = self.collect()
        self.assertEqual(m['files'], [])
        self.assertIn('storage_unavailable', m['warnings'])
        self.assertEqual(list(d.iterdir()), [])

    def test_short_writes(self):
        (self.out / 'x').write_bytes(b'0123456789')
        original = os.write
        with mock.patch.object(artifacts.os, 'write', side_effect=lambda fd,data:original(fd,data[:3])):
            m = self.collect()
        self.assertEqual((self.base / 'artifacts' / m['files'][0]['storage_key']).read_bytes(), b'0123456789')

    def test_failed_copy_removes_partial_blob(self):
        (self.out / 'x').write_bytes(b'0123456789')
        with mock.patch.object(artifacts.os, 'write', side_effect=OSError('disk full')): m = self.collect()
        self.assertEqual(m['files'], [])
        self.assertEqual(list((self.base / 'artifacts' / '7').iterdir()), [])

    def test_invalid_job(self):
        for job_id in (0,-1,True,1.5,'7',4294967296):
            with self.assertRaises(ValueError): artifacts.collect_outputs(self.out,self.base,job_id)


class FakeCursor:
    def __init__(self, rowcount=1): self.rowcount, self.call = rowcount, None
    def __enter__(self): return self
    def __exit__(self,*args): pass
    def execute(self,sql,params): self.call = (sql,params)


class FakeConnection:
    def __init__(self,rowcount=1): self.cur = FakeCursor(rowcount)
    def cursor(self): return self.cur


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.cfg = {**worker.DEFAULTS, 'STU_EMBER_PY_WORKDIR':str(self.base)}
    def tearDown(self): self.temp.cleanup()

    def docker_double(self, mode='success', stop=True):
        def run(cmd,**kwargs):
            if cmd[1] == 'rm':
                return subprocess.CompletedProcess(cmd,0 if stop else 1,b'',b'engine unavailable' if not stop else b'')
            mounts = [cmd[i+1] for i,word in enumerate(cmd[:-1]) if word == '-v']
            self.assertEqual(len(mounts),2)
            mount = next(x for x in mounts if x.endswith(':/work/output:rw'))
            output = Path(mount[:-len(':/work/output:rw')])
            self.assertIn('EMBER_OUTPUT_DIR=/work/output',cmd)
            self.assertTrue(all('/artifacts' not in x for x in mounts))
            (output / 'test.html').write_text('<html>real bytes</html>')
            if mode == 'timeout': raise subprocess.TimeoutExpired(cmd,1,b'partial stdout',b'partial stderr')
            return subprocess.CompletedProcess(cmd,1 if mode == 'error' else 0,b'output',b'')
        return run

    def test_collect_before_cleanup(self):
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double()):
            r = worker.run_in_container(self.cfg,7,'pass',publish_outputs=True)
        self.assertEqual(len(r[5]['files']),1)
        self.assertEqual(list(self.base.glob('job7_*')),[])
        self.assertTrue((self.base / 'artifacts' / r[5]['files'][0]['storage_key']).is_file())

    def test_nonzero_is_partial(self):
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double('error')):
            r = worker.run_in_container(self.cfg,7,'pass',publish_outputs=True)
        self.assertTrue(r[5]['files'][0]['partial'])

    def test_timeout_confirmed_stop(self):
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double('timeout')):
            r = worker.run_in_container(self.cfg,7,'pass',publish_outputs=True)
        self.assertIn('timeout',r[4])
        self.assertEqual(r[0],'partial stdout')
        self.assertTrue(r[5]['files'][0]['partial'])
        self.assertEqual(list(self.base.glob('job7_*')),[])

    def test_unconfirmed_stop_retains_work(self):
        for mode in ('success','timeout'):
            with self.subTest(mode=mode), mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double(mode,False)):
                r = worker.run_in_container(self.cfg,7,'pass',publish_outputs=True)
            self.assertIn('sandbox_stop_unconfirmed',r[4])
            self.assertEqual(r[5]['files'],[])
        self.assertEqual(len(list(self.base.glob('job7_*'))),2)

    def test_missing_docker(self):
        with mock.patch.object(worker.subprocess,'run',side_effect=FileNotFoundError()):
            r = worker.run_in_container(self.cfg,7,'pass',publish_outputs=True)
        self.assertIsNotNone(r[4])
        self.assertEqual(list(self.base.glob('job7_*')),[])

    def test_unbound_job_publishes_nothing(self):
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double()):
            r = worker.run_in_container(self.cfg,7,'pass')
        self.assertEqual(r[5]['files'],[])
        self.assertFalse((self.base/'artifacts').exists())

    def test_finish_is_conditional_and_atomic(self):
        c = FakeConnection(0)
        self.assertFalse(worker.finish_job(c,7,'done',manifest=artifacts.empty_manifest()))
        sql,params = c.cur.call
        self.assertIn("WHERE id=%s AND status='running' AND worker_pid=%s",sql)
        self.assertIn('artifacts_json=%s',sql)
        self.assertEqual(params[-2:],(7,os.getpid()))
        self.assertEqual(json.loads(params[-3])['version'],1)

    def test_rejected_completion_discards_files(self):
        job = {'id':7,'code':'pass','channel':'console','recipient_uid':1,'session_id':'s','trigger_message_id':2}
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double()):
            worker.process_job(FakeConnection(0),self.cfg,job)
        self.assertFalse((self.base/'artifacts'/'7').exists())

    def test_unknown_commit_keeps_files(self):
        job = {'id':7,'code':'pass','channel':'console','recipient_uid':1,'session_id':'s','trigger_message_id':2}
        with mock.patch.object(worker.subprocess,'run',side_effect=self.docker_double()), mock.patch.object(worker,'finish_job',side_effect=OSError('lost DB')):
            with self.assertRaises(OSError): worker.process_job(FakeConnection(),self.cfg,job)
        self.assertEqual(len(list((self.base/'artifacts'/'7').iterdir())),1)

    def test_relative_workdir(self):
        self.assertEqual(worker.work_base({'STU_EMBER_PY_WORKDIR':'var/custom'}),str(ROOT/'var/custom'))


if __name__ == '__main__': unittest.main(verbosity=2)
