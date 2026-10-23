import hashlib,json,os,pathlib,subprocess,tempfile,unittest
from unittest.mock import patch
import runner_support as r
ROOT=pathlib.Path(__file__).resolve().parent
class RunnerReview(unittest.TestCase):
    def test_dormant_shell_rejects_without_selection(self):
        env={'PATH':os.environ['PATH']};p=subprocess.run(['bash',str(ROOT/'runner-wrapper.sh')],env=env,capture_output=True)
        self.assertNotEqual(p.returncode,0);self.assertEqual(p.stdout,b'')
    def test_support_rejects_without_selection(self):
        p=subprocess.run(['python3',str(ROOT/'runner_support.py'),'hook','invalid','invalid'],env={'PATH':os.environ['PATH']},capture_output=True)
        self.assertNotEqual(p.returncode,0);self.assertIn(b'explicit runner execution selection',p.stderr)
    def test_no_real_binding_rejected(self):
        with patch.dict(os.environ,{},clear=True),self.assertRaisesRegex(RuntimeError,'real job binding'):r.identity()
    def test_escaping_cleanup_rejected(self):
        with patch.dict(os.environ,{'RUNNER_TEMP':'/tmp'}),self.assertRaisesRegex(RuntimeError,'owned temporary'):r.safe_owned(pathlib.Path('/work/wp'),'o01-eng-private.')
    def test_ownership_mismatch_never_removed(self):
        answer=subprocess.CompletedProcess([],0,json.dumps([{'Config':{'Labels':{'peoplerun.engineering.owner':'OTHER'}}}]).encode(),b'')
        with patch.object(r.subprocess,'run',return_value=answer) as run,self.assertRaisesRegex(RuntimeError,'ownership mismatch'):r.owner_check('test','OWNED')
        self.assertEqual(run.call_count,1)
    def test_daemon_error_is_not_absence(self):
        with patch.object(r.subprocess,'run',return_value=subprocess.CompletedProcess([],1,b'',b'error')),self.assertRaisesRegex(RuntimeError,'Docker state unavailable'):r.owner_check('test','owner')
    def test_evidence_cap(self):
        with tempfile.TemporaryDirectory() as tmp:
            out=pathlib.Path(tmp)
            with patch.object(r,'output',return_value=out),patch.object(r,'MAX',32),self.assertRaisesRegex(RuntimeError,'budget exceeded'):r.record('x.json',{'too_large':'x'*64})
            self.assertEqual(list(out.iterdir()),[])
    def test_hook_after_denials_and_before_cleanup(self):
        s=(ROOT/'runner-wrapper.sh').read_text()
        self.assertLess(s.index('/tmp/dn29-no-payment.php denials'),s.index('runner_support.py" hook'))
        self.assertLess(s.index('runner_support.py" hook'),s.index('O01_ENGINEERING_PHASE_RETURNED_PENDING_OUTER_CLEANUP'))
        self.assertNotIn('O01_RUN_READER_TESTS:-0',s)
        self.assertNotIn('O01_RUN_SQL_TESTS:-0',s)
if __name__=='__main__':unittest.main()
