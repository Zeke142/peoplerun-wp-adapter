"""Fetch only captured dependency bytes; never resolve a replacement version."""
import hashlib
import json
from pathlib import Path
import sys
import urllib.error
import urllib.request

lock_path, destination = map(Path, sys.argv[1:])
lock = json.loads(lock_path.read_text())
assert lock['architecture'] == 'x86_64'
assert lock['base_image'].startswith('alpine@sha256:')
destination.mkdir(parents=True, exist_ok=True)
apk_dir = destination / 'apks'
apk_dir.mkdir(exist_ok=True)

def fetch(url, expected, path):
    with urllib.request.urlopen(url, timeout=30) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != expected:
        raise RuntimeError('Dependency digest mismatch: ' + path.name)
    path.write_bytes(data)

for package in lock['packages']:
    filename = package['filename']
    assert Path(filename).name == filename and filename.endswith('.apk')
    for repository in lock['repositories']:
        try:
            fetch(repository + filename, package['sha256'], apk_dir / filename)
            break
        except urllib.error.HTTPError as exc:
            if exc.code != 404:
                raise
    else:
        raise RuntimeError('Pinned APK unavailable; refusing substitution: ' + filename)
fetch(lock['wordpress']['url'], lock['wordpress']['sha256'], destination / 'wordpress.tar.gz')
(destination / 'runtime.lock.json').write_text(json.dumps(lock, sort_keys=True, indent=2) + '\n')
(destination / 'Dockerfile').write_text('''FROM %s
RUN --network=none --mount=type=bind,source=apks,target=/vendor,ro apk add --no-network --no-cache /vendor/*.apk && adduser -D -u 10001 runtime
RUN --network=none --mount=type=bind,source=wordpress.tar.gz,target=/vendor-wordpress.tar.gz,ro mkdir /opt/wp && tar -xzf /vendor-wordpress.tar.gz --strip-components=1 -C /opt/wp
COPY runtime.lock.json /opt/runtime.lock.json
USER 10001:10001
CMD ["sleep","1800"]
''' % lock['base_image'])
print('PASS: %d APK SHA-256 pins and versioned WordPress archive verified' % len(lock['packages']))
