"""Validate the exact private archive and stage only its pinned runtime files."""
import hashlib,json,pathlib,stat,sys,zipfile
EXPECTED='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871'
archive=pathlib.Path(sys.argv[1])
assert hashlib.sha256(archive.read_bytes()).hexdigest()==EXPECTED,'DN29 archive mismatch'
with zipfile.ZipFile(archive) as z:
    names=z.namelist()
    assert len(names)==len(set(names)),'Duplicate archive names'
    for item in z.infolist():
        p=pathlib.PurePosixPath(item.filename)
        assert not p.is_absolute() and '..' not in p.parts and not stat.S_ISLNK(item.external_attr>>16)
    manifest=json.loads(z.read('evidence/manifest.json'))
    assert len(manifest['files'])==23
    for name,digest in manifest['files'].items():
        assert hashlib.sha256(z.read(name)).hexdigest()==digest,name
    runtime={name:digest for name,digest in manifest['files'].items() if name.startswith('src/')}
    assert len(runtime)==8
    if len(sys.argv)>2 and sys.argv[2]=='--stage':
        destination=pathlib.Path('/work/wp/wp-content/plugins/o01-dn29')
        assert not destination.exists(),'Refusing overwrite or side-by-side publisher installation'
        destination.mkdir(mode=0o700)
        for name in runtime:
            target=destination/pathlib.PurePosixPath(name).name
            target.write_bytes(z.read(name));target.chmod(0o400)
        pathlib.Path('/run/o01-dn29-staged.json').write_text(json.dumps({'archive_sha256':EXPECTED,'files':runtime},sort_keys=True))
print('PASS: exact DN29 archive and 23 manifest hashes verified; no original/regression code staged')
