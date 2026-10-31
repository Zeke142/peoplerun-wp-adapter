import base64,hashlib,http.cookiejar,json,os,pathlib,subprocess,sys,time,urllib.request,urllib.parse,urllib.error
package=pathlib.Path(__file__).resolve().parent
runtime=pathlib.Path(os.environ['O01_RUNTIME_ROOT'])
php=[str(runtime/'bin/php8.3'),'-n','-d','extension_dir='+os.environ['O01_EXTENSION_DIR'],'-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=pdo','-d','extension=pdo_mysql']
control=package/'o01-wordpress-http/http-fixture-control.php'
def cli(*args):
 r=subprocess.run(php+[str(control),*args],capture_output=True,text=True)
 if r.returncode:raise RuntimeError('fixture command failed: '+r.stdout+r.stderr)
 return r.stdout
log=[]
def check(yes,label):
 if not yes:raise RuntimeError('FAIL '+label)
 log.append('PASS '+label)
def client():
 jar=http.cookiejar.CookieJar();return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)),jar
def request(client,path,data=None):
 req=urllib.request.Request('http://127.0.0.1:8769'+path,data=None if data is None else urllib.parse.urlencode(data).encode())
 try:
  with client.open(req,timeout=15) as r:return r.status,r.read()
 except urllib.error.HTTPError as e:return e.code,e.read()
def post(client,path,data):
 status,body=request(client,path,data)
 try:return status,json.loads(body)
 except Exception:raise RuntimeError('non-JSON endpoint response: '+path+' '+body[:300].decode(errors='replace'))
server=None;seeded=False
try:
 cli('seed');seeded=True;meta=json.loads((package/'http-seed.json').read_text())
 check(len(meta['fixtures'])==7,'seven fixture sets created through actual G1/G2 APIs')
 with (package/'http-server.log').open('w') as err:
  server=subprocess.Popen(php+['-S','127.0.0.1:8769','-t',str(package/'wordpress'),str(package/'o01-wordpress-http/http-fixture-router.php')],stdout=err,stderr=err)
  anonymous,_=client()
  for attempt in range(100):
   try:
    status,_=request(anonymous,'/health')
    if status==200:break
   except OSError:pass
   time.sleep(.05)
  else:raise RuntimeError('HTTP server not ready')
  owner,ownerjar=client();other,otherjar=client()
  status,r=post(owner,'/login',{'username':'http-owner','password':'local-only-http-password'})
  check(status==200 and r.get('code')=='SIGNED_IN' and any(c.name.startswith('wordpress_logged_in_') for c in ownerjar),'real WordPress HTTP signon issued owner session cookie')
  status,r=post(other,'/login',{'username':'http-other','password':'local-only-other-password'})
  check(status==200 and bool(list(otherjar)),'second actual HTTP account signed in')
  for case,f in meta['fixtures'].items():
   base={'project_id':f['project_id'],'revision_id':f['revision_id']}
   status,_=post(anonymous,'/nonce',base);check(status==401,'anonymous nonce denied '+case)
   status,_=post(other,'/nonce',base);check(status==403,'wrong owner denied '+case)
   status,nonces=post(owner,'/nonce',base);check(status==200 and bool(nonces.get('approval_nonce')) and bool(nonces.get('publish_nonce')),'authenticated owner receives real WordPress nonces '+case)
   status,preview=request(owner,'/preview',base);expected=base64.b64decode(f['html_b64'])
   check(status==200 and preview==expected and hashlib.sha256(preview).hexdigest()==f['html_sha256'],'HTTP owner preview exactly matches immutable G1 bytes '+case)
   status,body=request(anonymous,'/page/'+f['slug']);check(status==503 and body==b'','unpublished page serves no bytes '+case)
   approval={**base,'html_sha256':f['html_sha256'],'quote_sha256':f['quote_sha256'],'nonce':'invalid'}
   status,_=post(owner,'/approve',approval);check(status==409,'invalid approval nonce rejected '+case)
   approval['nonce']=nonces['approval_nonce']
   if case=='stale-approve':
    cli('mutate',case);status,_=post(owner,'/approve',approval)
    check(status==409,'preview superseded before approval cannot create approval claim');continue
   status,r=post(owner,'/approve',approval);check(status==200 and r.get('code')=='COMMITTED_APPROVAL','actual HTTP cookie nonce verifier commits approval '+case)
   status,_=post(owner,'/approve',approval);check(status==409,'authenticated approval replay rejected '+case)
   status,_=post(owner,'/publish',{**base,'nonce':'invalid'});check(status==403,'invalid publication nonce rejected '+case)
   if case!='good':cli('mutate',case)
   status,r=post(owner,'/publish',{**base,'nonce':nonces['publish_nonce']})
   if case=='good':
    check(status==200 and r.get('result')=='COMMITTED_LOCAL_CANDIDATE','authenticated HTTP publication commits through recovery-tested publisher')
    status,body=request(anonymous,'/page/'+f['slug'])
    check(status==200 and body==preview and len(body)==len(preview) and hashlib.sha256(body).hexdigest()==f['html_sha256'],'anonymous HTTP delivery byte-identical to actual owner-approved HTTP preview')
    before=json.loads(cli('counts'));status,r=post(owner,'/publish',{**base,'nonce':nonces['publish_nonce']})
    check(status==200 and r.get('result')=='REPLAY' and json.loads(cli('counts'))==before,'HTTP publication replay preserves original receipt and consumes no duplicate ledger entry')
    cli('tamper',case)
    try:
     status,body=request(anonymous,'/page/'+f['slug']);check(status==503 and body==b'','HTTP delivery denies tampered stored parent without leaking bytes')
    finally:cli('restore',case)
    status,body=request(anonymous,'/page/'+f['slug']);check(status==200 and body==preview,'restored fixture serves original approved HTTP bytes')
   else:
    check(status==409 and r.get('result',r.get('code')) in ['DENIED','PUBLICATION_DENIED'],'publication invalidated after '+case+' change')
    status,body=request(anonymous,'/page/'+f['slug']);check(status in [404,503] and body==b'','invalidated approval cannot expose HTTP content '+case)
  counts=json.loads(cli('counts'))
  check(counts['o01_receipt_candidate_ledger']==counts['o01_integrated_o01_paid_routes']==counts['o01_integrated_o01_paid_receipts']==1,'fresh DB has exactly one successful route receipt and synthetic payment consumption')
  check(counts['pr_o01_approval_claims']==6,'failed stale-preview approval left no seventh claim')
  log.append('SKIP genuine payment provider, signed external customer consent and production admission; positive payment/publication callbacks synthetic')
  log.append('SKIP physical iPhone UI, optional subpages, TLS/public hosting and new transport-fault injection in this HTTP suite')
finally:
 if server is not None:
  server.terminate();server.wait(timeout=10)
 if seeded:
  cleaned=cli('cleanup');check('CLEANUP_VERIFIED' in cleaned,'all fixture posts users and candidate tables removed; baseline verified')
 (package/'http-test-result.txt').write_text('\n'.join(log)+'\n')
 print('\n'.join(log))
