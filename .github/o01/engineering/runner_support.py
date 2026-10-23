#!/usr/bin/env python3
"""Dormant runner lifecycle support. No Actions dispatch or workflow mutation API."""
import argparse, hashlib, json, os, pathlib, re, shutil, signal, subprocess, sys, time
ROOT=pathlib.Path(__file__).resolve().parent
MAX=2*1024*1024
PIN='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871'

def check(ok,msg):
    if not ok:raise RuntimeError(msg)
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def identity():
    run=os.environ.get('GITHUB_RUN_ID','');attempt=os.environ.get('GITHUB_RUN_ATTEMPT','');commit=os.environ.get('GITHUB_SHA','')
    check(run.isdecimal() and attempt.isdecimal() and re.fullmatch('[a-f0-9]{40}',commit),'real job binding required')
    return {'run_id':run,'attempt':attempt,'orchestration_commit':commit,'run_binding':run+':'+attempt}
def output():
    i=identity();base=pathlib.Path(os.environ['RUNNER_TEMP']).resolve();out=pathlib.Path(os.environ['O01_ENGINEERING_OUTPUT_DIR'])
    check(out==base/('o01-engineering-result-'+i['run_id']+'-'+i['attempt']) and not out.is_symlink(),'fixed private output path required')
    return out

def record(name,obj):
    out=output();raw=(json.dumps(obj,indent=2)+'\n').encode()
    check(sum(p.stat().st_size for p in out.iterdir() if p.is_file() and p.name!=name)+len(raw)<=MAX,'evidence budget exceeded')
    (out/name).write_bytes(raw);(out/name).chmod(0o600)
def bounded(args,timeout,env=None,input_bytes=None):
    # Temporary output is on runner scratch; capped in real time, never unlimited communicate().
    import tempfile, contextlib
    with contextlib.ExitStack() as stack:
        f=stack.enter_context(tempfile.TemporaryFile())
        stream=subprocess.DEVNULL
        if input_bytes is not None:
            stream=stack.enter_context(tempfile.TemporaryFile());stream.write(input_bytes);stream.seek(0)
        p=subprocess.Popen(args,stdin=stream,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True)
        try:
            deadline=time.monotonic()+timeout
            while p.poll() is None:
                check(time.monotonic()<deadline,'child deadline exceeded')
                check(os.fstat(f.fileno()).st_size<=MAX,'child output exceeded evidence cap')
                time.sleep(.02)
            check(os.fstat(f.fileno()).st_size<=MAX,'child output exceeded evidence cap')
            f.seek(0);return p.returncode,f.read(MAX+1)
        finally:
            if p.poll() is None:
                os.killpg(p.pid,signal.SIGKILL);p.wait(timeout=5)

def preflight(base):
    identity();check(os.environ.get('O01_ENGINEERING_RUNNER_EXECUTE')=='1','explicit runner execution selection required')
    check(os.environ.get('GITHUB_REPOSITORY')=='Zeke142/peoplerun-wp-adapter' and os.environ.get('O01_REPOSITORY')=='Zeke142/peoplerun-wp-adapter','repository mismatch')
    check(os.environ.get('O01_REPOSITORY_VISIBILITY')=='public','public repository required')
    check(os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted' and os.environ.get('RUNNER_OS')=='Linux' and os.environ.get('RUNNER_ARCH')=='X64','standard hosted Linux runner required')
    check(bool(os.environ.get('O01_HANDSHAKE_TOKEN')),'one-run receive token required')
    check(os.environ.get('O01_RUN_SQL_TESTS','0')=='0' and os.environ.get('O01_RUN_READER_TESTS','0')=='0','unrelated phases must be off')
    lock=json.loads((ROOT/'runner.lock.json').read_text());check(lock['candidate_revision']=='c7cafeba9943e7c5bcaebd2cf66863500b3c00d3','candidate revision')
    check(base.is_absolute() and not base.is_symlink(),'absolute helper directory required')
    for name,h in lock['base_helpers'].items():check(not (base/name).is_symlink() and sha(base/name)==h,'base helper drift: '+name)
    for name,h in lock['runner_files'].items():check(not (ROOT/name).is_symlink() and sha(ROOT/name)==h,'runner source drift: '+name)
    check(sha(ROOT/'sources.lock.json')==lock['candidate_lock_sha256'],'candidate lock drift')
    candidate=json.loads((ROOT/'sources.lock.json').read_text())
    for name,h in candidate['files'].items():check(sha(ROOT/name)==h,'engineering source drift: '+name)
    out=output();check(not out.exists(),'output directory already exists');out.mkdir(mode=0o700)
    record('preflight.json',{'status':'SOURCE_GATES_PASS','identity':identity(),'runner_lock_sha256':sha(ROOT/'runner.lock.json'),'candidate_revision':lock['candidate_revision'],'selected_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())})

def receive(base,private):
    safe_owned(private,'o01-eng-private.')
    archive=private/'dn29.zip'
    rc,log=bounded(['/usr/bin/python3',str(base/'private_receive.py'),str(archive)],680)
    # Log contains public key metadata and verifier status only; no token/environment dump.
    check(log.count(b'O01_PRIVATE_ARCHIVE_VERIFIED ')==1 and rc==0,'fresh private receive failed')
    row=json.loads(next(x.split(b'O01_PRIVATE_ARCHIVE_VERIFIED ',1)[1] for x in log.splitlines() if b'O01_PRIVATE_ARCHIVE_VERIFIED ' in x))
    i=identity();check(row['run_id']==i['run_id'] and row['attempt']==i['attempt'] and row['archive_sha256']==PIN,'receive binding mismatch')
    check(archive.stat().st_size==1047421 and sha(archive)==PIN,'archive mismatch')
    record('private-binding.json',{'status':'FRESH_RECEIVE_VERIFIED','identity':i,'archive_sha256':PIN,'bytes':archive.stat().st_size,'receive_log_sha256':hashlib.sha256(log).hexdigest()})

def owner_check(container,owner):
    p=subprocess.run(['docker','inspect',container],capture_output=True,timeout=10)
    if p.returncode!=0:
        # Distinguish absent container from daemon/permission errors before claiming cleanup.
        q=subprocess.run(['docker','ps','-a','--format','{{.Names}}'],capture_output=True,timeout=10)
        check(q.returncode==0,'Docker state unavailable');check(container not in q.stdout.decode().splitlines(),'container inspect failed');return False
    d=json.loads(p.stdout)[0];check(d['Config'].get('Labels',{}).get('peoplerun.engineering.owner')==owner,'container ownership mismatch');return True

def hook(container,owner):
    check(owner_check(container,owner),'owned container missing')
    c=['docker','exec',container]
    subprocess.run(c+['mkdir','-m','700','/tmp/o01-engineering'],check=True,timeout=10)
    for name in ['generate.py','harness.php','run.py','test_generate.py','README.md','sources.lock.json','allowed-diff.json']:
        rc,_=bounded(['docker','exec','-i',container,'sh','-c','umask 077; cat > /tmp/o01-engineering/'+name],10,input_bytes=(ROOT/name).read_bytes());check(rc==0,'helper transfer failed')
    binding=identity()['run_binding'];eng='/run/o01-eng-'+hashlib.sha256(binding.encode()).hexdigest()[:12]
    # Source hashes are checked by preflight and again by run.py; all writes are container-local.
    rc,log=bounded(c+['python3','/tmp/o01-engineering/generate.py','/tmp/dn29.zip',eng],15);check(rc==0,'private generation failed')
    rc,log=bounded(['timeout','--kill-after=5','190','docker','exec','-e','O01_ENGINEERING_EXECUTE=1','-e','O01_ENGINEERING_DIR='+eng,container,'timeout','-k','5','185','python3','/tmp/o01-engineering/run.py','--execute-engineering','--directory',eng],197)
    record('phase-exit.json',{'status':'EXIT_RECORDED','identity':identity(),'returncode':rc,'log_sha256':hashlib.sha256(log).hexdigest(),'log':log.decode(errors='replace')})
    check(rc==0,'engineering phase failed')

def safe_owned(path,prefix):
    check(path.is_absolute() and path.parent==pathlib.Path(os.environ['RUNNER_TEMP']).resolve() and path.name.startswith(prefix) and not path.is_symlink(),'owned temporary path required')

def finish(container,owner,build,private,exit_code):
    errors=[];receipt=None;removed=False;proof=None;denial=None
    try:
        if owner_check(container,owner):
            eng='/run/o01-eng-'+hashlib.sha256(identity()['run_binding'].encode()).hexdigest()[:12]
            script='import pathlib,sys; p=pathlib.Path(sys.argv[1]); assert p.stat().st_size<=1572864; sys.stdout.buffer.write(p.read_bytes())'
            rc,data=bounded(['docker','exec',container,'python3','-c',script,eng+'/receipt.json'],10)
            if rc==0:
                receipt=json.loads(data);record('engineering-receipt.json',receipt)
                for remote,local in [('/run/o01-rollback-proof.json','rollback-proof.json'),('/run/o01-denials-pass.json','denial-proof.json')]:
                    rc,raw=bounded(['docker','exec',container,'python3','-c',script,remote],10)
                    check(rc==0,'prerequisite proof collection failed');record(local,json.loads(raw))
                proof=json.loads((output()/'rollback-proof.json').read_text());denial=json.loads((output()/'denial-proof.json').read_text())
            else:errors.append('engineering receipt unavailable')
    except Exception as exc:errors.append('evidence collection: '+str(exc))
    try:
        if owner_check(container,owner):
            p=subprocess.run(['docker','rm','-f',container],capture_output=True,timeout=20);check(p.returncode==0,'owned container removal failed')
        removed=not owner_check(container,owner)
    except Exception as exc:errors.append('container cleanup: '+str(exc))
    cleaned=[]
    for path,prefix in [(build,'o01-eng-build.'),(private,'o01-eng-private.')]:
        try:safe_owned(path,prefix);shutil.rmtree(path);cleaned.append(path.name)
        except Exception as exc:errors.append('private cleanup: '+str(exc))
    valid=False
    try:
        candidate=json.loads((ROOT/'sources.lock.json').read_text())
        binding=json.loads((output()/'private-binding.json').read_text())
        check(binding['status']=='FRESH_RECEIVE_VERIFIED' and binding['identity']==identity() and binding['archive_sha256']==PIN,'private binding evidence')
        check(proof is not None and proof['status']=='VERIFIED' and proof['run_binding']==identity()['run_binding'] and proof['source_sha256']==PIN,'rollback evidence')
        check(denial is not None and denial['run_binding']==identity()['run_binding'] and denial['source_sha256']==PIN and denial['rollback_archive_sha256']==proof['archive_sha256'],'denial evidence')
        check(receipt is not None and receipt['status']=='ENGINEERING_DERIVATIVE_COMMIT_VERIFIED','suite incomplete')
        check(receipt['classification']=='ENGINEERING_DERIVATIVE_ONLY' and receipt['run_binding']==identity()['run_binding'],'receipt scope')
        check(receipt['source_lock']==candidate,'receipt source lock')
        check([c['case'] for c in receipt['cases']]==['EC'+str(i).zfill(2) for i in range(1,11)],'case coverage')
        check(all(c['status']=='PASS' for c in receipt['cases']),'case failure');valid=True
    except Exception as exc:errors.append('receipt validation: '+str(exc))
    status='ENGINEERING_DERIVATIVE_COMMIT_VERIFIED' if exit_code==0 and valid and removed and len(cleaned)==2 and not errors else 'FAILED_OR_UNVERIFIED'
    record('runner-result.json',{'status':status,'identity':identity(),'wrapper_exit':exit_code,'container_removed':removed,'private_paths_removed':cleaned,'receipt_sha256':sha(output()/'engineering-receipt.json') if (output()/'engineering-receipt.json').exists() else None,'errors':errors,'genuine_payment_verified':False,'original_dn29_accepted':False})
    print('O01_ENGINEERING_RUNNER_RESULT '+status)
    return 0 if status=='ENGINEERING_DERIVATIVE_COMMIT_VERIFIED' else 1

def main():
    def interrupted(number,frame):raise SystemExit(128+number)
    signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
    ap=argparse.ArgumentParser();ap.add_argument('action',choices=['preflight','receive','hook','finish']);ap.add_argument('args',nargs='*');a=ap.parse_args()
    check(os.environ.get('O01_ENGINEERING_RUNNER_EXECUTE')=='1','explicit runner execution selection required')
    if a.action=='preflight':preflight(pathlib.Path(a.args[0]))
    elif a.action=='receive':receive(pathlib.Path(a.args[0]),pathlib.Path(a.args[1]))
    elif a.action=='hook':hook(*a.args)
    else:sys.exit(finish(a.args[0],a.args[1],pathlib.Path(a.args[2]),pathlib.Path(a.args[3]),int(a.args[4])))
if __name__=='__main__':main()
