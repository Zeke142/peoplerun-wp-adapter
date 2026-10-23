import base64,hashlib,json,os,pathlib,sys
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

archive,handshake,destination=map(pathlib.Path,sys.argv[1:])
h=json.loads(handshake.read_text());plain=archive.read_bytes()
assert h['repository']=='Zeke142/peoplerun-wp-adapter'
assert hashlib.sha256(plain).hexdigest()==h['archive_sha256']=='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871'
public=h['public_key_pem'].encode()
assert hashlib.sha256(public).hexdigest()==h['public_key_sha256']
aad=f"{h['repository']}:{h['run_id']}:{h['attempt']}:{h['source_commit']}:{h['archive_sha256']}".encode()
key=AESGCM.generate_key(bit_length=256);nonce=os.urandom(12)
cipher=AESGCM(key).encrypt(nonce,plain,aad)
assert AESGCM(key).decrypt(nonce,cipher,aad)==plain
wrapped=serialization.load_pem_public_key(public).encrypt(key,padding.OAEP(mgf=padding.MGF1(hashes.SHA256()),algorithm=hashes.SHA256(),label=aad))
destination.mkdir(mode=0o700,parents=True,exist_ok=True)
encoded=base64.b64encode(cipher);parts=[]
for i,offset in enumerate(range(0,len(encoded),32768)):
    name=f'part-{i:03}.txt';data=encoded[offset:offset+32768]
    (destination/name).write_bytes(data)
    parts.append({'name':name,'sha256':hashlib.sha256(data).hexdigest()})
manifest={k:h[k] for k in ['run_id','attempt','source_commit','archive_sha256','public_key_sha256']}
manifest.update(schema='peoplerun.o01.encrypted-private-input.v1',ciphertext_size=len(cipher),ciphertext_sha256=hashlib.sha256(cipher).hexdigest(),nonce=base64.b64encode(nonce).decode(),wrapped_key=base64.b64encode(wrapped).decode(),parts=parts)
(destination/'manifest.json').write_text(json.dumps(manifest,sort_keys=True))
print(json.dumps({'run_id':h['run_id'],'parts':len(parts),'ciphertext_bytes':len(cipher),'archive_sha256':h['archive_sha256'],'private_key_or_clear_symmetric_key_written':False}))
