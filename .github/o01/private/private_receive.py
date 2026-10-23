"""One-run RSA-OAEP/AES-GCM receive; publish public metadata, never secret material."""
import base64,hashlib,json,os,pathlib,re,sys,time,urllib.error,urllib.parse,urllib.request
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import padding,rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

REPO='Zeke142/peoplerun-wp-adapter'
BRANCH='o01-isolated-runtime-admission-v1'
PIN='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871'
assert os.environ['GITHUB_REPOSITORY']==REPO
run=os.environ['GITHUB_RUN_ID'];attempt=os.environ['GITHUB_RUN_ATTEMPT'];commit=os.environ['GITHUB_SHA']
assert run.isdecimal() and attempt.isdecimal() and re.fullmatch('[a-f0-9]{40}',commit)
token=os.environ['O01_HANDSHAKE_TOKEN']
root=f'.github/o01/transfer/{run}-{attempt}'
aad=f'{REPO}:{run}:{attempt}:{commit}:{PIN}'.encode()

def api(method,path,data=None):
    url='https://api.github.com/repos/'+REPO+'/contents/'+path
    if method=='GET':url+='?ref='+urllib.parse.quote(BRANCH,safe='')+'&nonce='+str(time.time_ns())
    headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json','X-GitHub-Api-Version':'2022-11-28','Cache-Control':'no-cache'}
    payload=None if data is None else json.dumps(data).encode()
    request=urllib.request.Request(url,data=payload,headers=headers,method=method)
    with urllib.request.urlopen(request,timeout=20) as response:return json.load(response)

private=rsa.generate_private_key(public_exponent=65537,key_size=3072)
public=private.public_key().public_bytes(serialization.Encoding.PEM,serialization.PublicFormat.SubjectPublicKeyInfo)
handshake={'schema':'peoplerun.o01.ephemeral-private-input.v1','repository':REPO,'run_id':run,'attempt':attempt,'source_commit':commit,'archive_sha256':PIN,'public_key_pem':public.decode(),'public_key_sha256':hashlib.sha256(public).hexdigest(),'created_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'expires_in_seconds':600}
api('PUT',root+'/handshake.json',{'branch':BRANCH,'message':'Publish one-run public input key '+run,'content':base64.b64encode(json.dumps(handshake,sort_keys=True).encode()).decode()})
print('O01_PRIVATE_INPUT_WAITING '+json.dumps({k:handshake[k] for k in ['run_id','attempt','source_commit','archive_sha256','public_key_sha256']}),flush=True)
deadline=time.monotonic()+600
manifest=None
while time.monotonic()<deadline:
    try:
        obj=api('GET',root+'/manifest.json')
        manifest=json.loads(base64.b64decode(obj['content'],validate=False));break
    except urllib.error.HTTPError as exc:
        if exc.code!=404:raise RuntimeError('Private input read failed HTTP '+str(exc.code)) from None
    time.sleep(5)
if manifest is None:raise RuntimeError('Private input wait expired; refusing execution')
for key,value in [('run_id',run),('attempt',attempt),('source_commit',commit),('archive_sha256',PIN),('public_key_sha256',handshake['public_key_sha256'])]:
    if manifest.get(key)!=value:raise RuntimeError('Private input binding mismatch: '+key)
assert 1<=len(manifest['parts'])<=64
assert manifest['ciphertext_size']==1047421+16
parts=[]
for i,part in enumerate(manifest['parts']):
    assert part['name']==f'part-{i:03}.txt'
    obj=api('GET',root+'/'+part['name'])
    encoded=base64.b64decode(obj['content'],validate=False)
    assert hashlib.sha256(encoded).hexdigest()==part['sha256']
    parts.append(encoded)
ciphertext=base64.b64decode(b''.join(parts),validate=True)
assert len(ciphertext)==manifest['ciphertext_size']
assert hashlib.sha256(ciphertext).hexdigest()==manifest['ciphertext_sha256']
key=private.decrypt(base64.b64decode(manifest['wrapped_key'],validate=True),padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=aad))
assert len(key)==32
plain=AESGCM(key).decrypt(base64.b64decode(manifest['nonce'],validate=True),ciphertext,aad)
assert len(plain)==1047421 and hashlib.sha256(plain).hexdigest()==PIN
path=pathlib.Path(sys.argv[1]);path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,'wb') as output:output.write(plain)
print('O01_PRIVATE_ARCHIVE_VERIFIED '+json.dumps({'run_id':run,'attempt':attempt,'archive_sha256':PIN,'bytes':len(plain),'plaintext_committed':False,'key_persisted':False}),flush=True)
