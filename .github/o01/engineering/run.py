#!/usr/bin/env python3
"""Dormant, bounded inside-container orchestrator. No GitHub/workflow API."""
import argparse, hashlib, json, os, pathlib, signal, subprocess, time, shutil
ROOT=pathlib.Path(__file__).resolve().parent
CHILDREN=[]
START=time.monotonic()
PHASE_LIMIT=150

def check(ok,why):
    if not ok: raise RuntimeError(why)
def remaining():
    n=PHASE_LIMIT-(time.monotonic()-START)
    check(n>0,'mechanics phase timeout');return min(30,n)
def command(args,**kw):
    return subprocess.run(args,check=True,timeout=remaining(),**kw)
def php_args(*args): return ['php84','-c','/work/php.ini','-d','max_execution_time=15',str(ROOT/'harness.php'),*args]
def parse(out):
    rows=[json.loads(x[8:]) for x in out.splitlines() if x.startswith('EC_JSON ')]
    check(len(rows)==1,'missing or multiple result records');return rows[0]
def call(*args):return parse(command(php_args(*args),capture_output=True,text=True).stdout)
def worker(fault='none',hold=False):
    p=subprocess.Popen(php_args('worker',fault,'hold' if hold else ''),stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    CHILDREN.append(p);return p
def finish(p):
    out,err=p.communicate(timeout=remaining());check(p.returncode==0,'worker failed: '+err[-500:]);return parse(out)
def observe():return call('observe')
def empty():
    v=observe();check(v=={'releases':[],'receipts':[]},'half-pair or unexpected rows');return v
def fixture():return json.loads((DIR/'fixture.json').read_text())
def paired():
    v=observe();f=fixture();check(len(v['releases'])==len(v['receipts'])==1,'expected exactly one pair')
    r=v['releases'][0];l=v['receipts'][0];m=f['manifest']
    check(all(r[k]==m[k] for k in ['slug','manifest_sha256','render_sha256']),'release identity mismatch')
    check(r['render_html']==f['render'] and json.loads(r['manifest_json'])==m,'release bytes mismatch')
    check(r['expires_at_utc']==time.strftime('%Y-%m-%d %H:%M:%S',time.strptime(m['expires_at_utc'],'%Y-%m-%dT%H:%M:%SZ')),'expiry binding')
    for k,value in f['ids'].items():
        col='admitted_at' if k=='timestamp' else k
        check(str(l[col])==str(value),'receipt identity mismatch: '+col)
    return v
def clear_barrier():
    for name in ['barrier.json','release']:(DIR/name).unlink(missing_ok=True)
def barrier(point):
    end=time.monotonic()+10
    while time.monotonic()<end:
        remaining()
        try:
            b=json.loads((DIR/'barrier.json').read_text())
            if b['point']==point:return b
        except (FileNotFoundError,json.JSONDecodeError): pass
        time.sleep(.02)
    raise RuntimeError('missing synchronization barrier')
def reset(tag):
    call('reset');empty();clear_barrier();call('fixture',tag)
def result(name,data):
    if name!='EC10':check(call('lock')['lock']=='1','post-case publisher lock unreleased: '+name)
    RECEIPT['cases'].append({'case':name,'status':'PASS','at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'readback':data})
    save()
def save():OUT.write_text(json.dumps(RECEIPT,indent=2)+'\n')
def restore():
    cp=pathlib.Path('/backup/checkpoint');proof=json.loads(pathlib.Path('/run/o01-rollback-proof.json').read_text())
    for name,key in [('files.tar.gz','archive_sha256'),('database.sql','database_dump_sha256'),('files.before.json','files_manifest_sha256')]:
        check(hashlib.sha256((cp/name).read_bytes()).hexdigest()==proof[key],'checkpoint integrity')
    command(['sha256sum','-c','hashes.sha256'],cwd=cp,capture_output=True)
    check(pathlib.Path('/work/wp').resolve()==pathlib.Path('/work/wp'),'fixed restore root')
    shutil.rmtree('/work/wp');command(['tar','-xzf',str(cp/'files.tar.gz'),'-C','/work'])
    db=['mariadb','--socket=/run/db.sock','--user=root']
    command(db+['-e','DROP DATABASE o01_engineering; CREATE DATABASE o01_engineering;'])
    with (cp/'database.sql').open('rb') as f:command(db+['o01_engineering'],stdin=f)
    actual=command(['python3','/tmp/file_manifest.py'],capture_output=True).stdout
    check(actual==(cp/'files.before.json').read_bytes(),'file restore readback')
    dump=command(['mariadb-dump','--socket=/run/db.sock','--user=root','--single-transaction','--skip-comments','--skip-dump-date','--order-by-primary','--skip-extended-insert','o01_engineering'],capture_output=True).stdout
    check(dump==(cp/'database.sql').read_bytes(),'DB restore readback')
    # Existing original verifier runs against restored source and empty ledgers.
    command(['php84','-c','/work/php.ini','-d','max_execution_time=15','/tmp/dn29-no-payment.php','verify'],capture_output=True)
    empty();return {'files_sha256':hashlib.sha256(actual).hexdigest(),'database_sha256':hashlib.sha256(dump).hexdigest()}
def main():
    global DIR,OUT,RECEIPT,PHASE_LIMIT
    ap=argparse.ArgumentParser();ap.add_argument('--execute-engineering',action='store_true');ap.add_argument('--directory',required=True);a=ap.parse_args()
    check(a.execute_engineering and os.environ.get('O01_ENGINEERING_EXECUTE')=='1','explicit engineering selection required')
    check(os.getuid()==10001,'must be unprivileged container uid10001')
    binding=os.environ.get('O01_RUN_BINDING','');check(binding and all(x.isdigit() for x in binding.split(':')) and binding.count(':')==1,'run binding')
    DIR=pathlib.Path(a.directory);check(str(DIR)=='/run/o01-eng-'+hashlib.sha256(binding.encode()).hexdigest()[:12],'run directory mismatch')
    check(DIR.is_dir() and not DIR.is_symlink(),'private generated module directory required')
    os.environ['O01_ENGINEERING_DIR']=str(DIR)
    locks=json.loads((ROOT/'sources.lock.json').read_text())
    for name,digest in locks['files'].items():check(hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,'harness source pin '+name)
    check(hashlib.sha256((DIR/'mechanics.php').read_bytes()).hexdigest()==locks['generated_sha256'],'generated pin')
    check(not pathlib.Path('/run/http.pid').exists(),'HTTP must be stopped at entry')
    for p in [ROOT/'harness.php',DIR/'mechanics.php']:
        command(['php84','-l',str(p)],capture_output=True)
    OUT=DIR/'receipt.json';RECEIPT={'schema':'peoplerun.engineering.commit-candidate.v1','status':'RUNNING','classification':'ENGINEERING_DERIVATIVE_ONLY','run_binding':binding,'source_lock':locks,'cases':[]};save()
    server=None;restored=False
    try:
        empty();call('fixture','success');check(finish(worker())['result']['ok'],'EC01 reported failure');result('EC01',paired())
        reset('contention');a=worker(hold=True);barrier('locked');b=worker();time.sleep(.15)
        check(call('lock')['lock']=='0','EC02 lock not held');check(b.poll() is None,'EC02 competing worker did not wait');empty()
        (DIR/'release').touch();ra=finish(a);rb=finish(b);check(sum(bool(x['result']['ok']) for x in [ra,rb])==1,'EC02 exactly one winner');result('EC02',paired())
        reset('replay');check(finish(worker())['result']['ok'],'EC03 baseline');base=fixture();baseline=paired()
        check(not finish(worker())['result']['ok'],'exact replay accepted');check(observe()==baseline,'exact replay changed pair')
        for field in ['event_id','payment_id','consent_ref','manifest_sha256']:
            call('fixture','collision-'+field.replace('_','-'));f=fixture();f['ids'][field]=base['ids'][field];(DIR/'fixture.json').write_text(json.dumps(f))
            answer=call('atomic') if field=='manifest_sha256' else finish(worker())
            check((answer.get('atomic') is False and answer['callback_reached'] is False) if field=='manifest_sha256' else not answer['result']['ok'],'EC03 collision accepted')
            check(answer['insert_errno']==1062,'EC03 did not reach actual unique-key rejection')
            check(observe()==baseline,'EC03 changed original pair')
        result('EC03',{'separate_conflicts':['exact','event','payment','consent','manifest_atomic_layer'],'baseline':baseline})
        for name,fault in [('EC04','after_receipt'),('EC05','after_release')]:
            reset(name.lower());p=worker(fault);barrier(fault);empty();(DIR/'release').touch();check(not finish(p)['result']['ok'],'expected rollback failure');result(name,empty())
        reset('interruption');p=worker('disconnect');barrier('before_commit');empty();p.terminate();p.communicate(timeout=remaining());check(p.returncode!=0,'worker was not interrupted');result('EC06',empty())
        reset('ack');answer=finish(worker('lost_ack'));check(not answer['result']['ok'],'lost ack must report failure');v=paired();check(not finish(worker())['result']['ok'],'lost ack replay accepted');check(observe()==v,'lost ack retry changed data');result('EC07',{'reported':answer,'readback':v})
        check(call('lock')['lock']=='1','lock unreleased');result('EC08',{'lock_reusable':True})
        reset('reader');check(finish(worker())['result']['ok'],'EC09 baseline');paired()
        log=(DIR/'http.log').open('w');server=subprocess.Popen(['php84','-c','/work/php.ini','-d','max_execution_time=15','-S','127.0.0.1:8080','-t','/work/wp'],stdout=log,stderr=log);CHILDREN.append(server)
        # Readiness is local TCP only; no outbound path.
        import socket
        end=time.monotonic()+3
        while True:
            try:
                with socket.create_connection(('127.0.0.1',8080),timeout=.2):break
            except OSError:
                check(time.monotonic()<end,'reader startup timeout');time.sleep(.03)
        result('EC09',call('reader'));server.terminate();server.wait(timeout=5);server=None;log.close()
        PHASE_LIMIT=180
        result('EC10',restore());restored=True;RECEIPT['status']='ENGINEERING_DERIVATIVE_COMMIT_VERIFIED';save()
    except BaseException as exc:
        RECEIPT['status']='FAILED';RECEIPT['error']=str(exc);save();raise
    finally:
        for child in CHILDREN:
            if child.poll() is None:
                child.terminate()
                try:child.wait(timeout=2)
                except subprocess.TimeoutExpired:child.kill();child.wait(timeout=2)
        if not restored:
            PHASE_LIMIT=180
            try:RECEIPT['failure_restore']=restore()
            except Exception as e:RECEIPT['failure_restore_error']=str(e)
            save()
    print(json.dumps({'status':RECEIPT['status'],'receipt':str(OUT)}))
if __name__=='__main__':main()
