#!/bin/sh
# Executes only inside the owned, network-denied, ephemeral staging container.
set -eu
umask 077
test "$(id -u)" = 10001
test -n "$O01_RUN_BINDING"
test -n "$O01_CHECKPOINT_SOURCE"
test "$(realpath /work/wp)" = /work/wp
test "$(mariadb --socket=/run/db.sock --user=root --batch --skip-column-names -e 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME="o01_engineering"')" = o01_engineering
if test -f /run/http.pid && kill -0 "$(cat /run/http.pid)" 2>/dev/null; then
  echo 'STOP: quiesce the owned HTTP process before checkpoint'; exit 1
fi
rm -f /run/o01-rollback-proof.json
mkdir -p /backup/checkpoint
test ! -e /work/wp/.o01-rollback-marker
test "$(mariadb --socket=/run/db.sock --user=root --batch --skip-column-names -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA="o01_engineering" AND TABLE_NAME="o01_runtime_rollback_marker"')" = 0
cat > /tmp/file_manifest.py <<'PY'
import hashlib,json,pathlib,stat
result={}
for root in [pathlib.Path('/work/wp'),pathlib.Path('/work/php.ini')]:
    files=[root] if root.is_file() else sorted(root.rglob('*'))
    for p in files:
        if p.is_symlink(): raise RuntimeError('Unexpected checkpoint symlink')
        if p.is_file():
            result[str(p)]={'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'mode':stat.S_IMODE(p.stat().st_mode)}
print(json.dumps(result,sort_keys=True,separators=(',',':')))
PY
python3 /tmp/file_manifest.py > /backup/checkpoint/files.before.json
mariadb-dump --socket=/run/db.sock --user=root --single-transaction --skip-comments --skip-dump-date --order-by-primary --skip-extended-insert o01_engineering > /backup/checkpoint/database.sql
tar -czf /backup/checkpoint/files.tar.gz -C /work wp php.ini
cd /backup/checkpoint
sha256sum files.before.json database.sql files.tar.gz > hashes.sha256
sha256sum -c hashes.sha256 >/dev/null
printf 'ENGINEERING_ROLLBACK_MARKER' > /work/wp/.o01-rollback-marker
mariadb --socket=/run/db.sock --user=root o01_engineering -e 'CREATE TABLE o01_runtime_rollback_marker (id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO o01_runtime_rollback_marker VALUES (1);'
test -f /work/wp/.o01-rollback-marker
test "$(mariadb --socket=/run/db.sock --user=root --batch --skip-column-names o01_engineering -e 'SELECT id FROM o01_runtime_rollback_marker')" = 1
# Restore only these fixed staging objects after verifying the matched pair.
sha256sum -c hashes.sha256 >/dev/null
rm -rf /work/wp
tar -xzf /backup/checkpoint/files.tar.gz -C /work
mariadb --socket=/run/db.sock --user=root -e 'DROP DATABASE o01_engineering; CREATE DATABASE o01_engineering;'
mariadb --socket=/run/db.sock --user=root o01_engineering < /backup/checkpoint/database.sql
python3 /tmp/file_manifest.py > /backup/checkpoint/files.after.json
cmp /backup/checkpoint/files.before.json /backup/checkpoint/files.after.json
mariadb-dump --socket=/run/db.sock --user=root --single-transaction --skip-comments --skip-dump-date --order-by-primary --skip-extended-insert o01_engineering > /backup/checkpoint/database.after.sql
cmp /backup/checkpoint/database.sql /backup/checkpoint/database.after.sql
test ! -e /work/wp/.o01-rollback-marker
test "$(mariadb --socket=/run/db.sock --user=root --batch --skip-column-names -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA="o01_engineering" AND TABLE_NAME="o01_runtime_rollback_marker"')" = 0
python3 - <<'PY'
import hashlib,json,os,pathlib,time
p=pathlib.Path('/backup/checkpoint')
proof={'schema':'peoplerun.o01.rollback-readback.v1','status':'VERIFIED','run_binding':os.environ['O01_RUN_BINDING'],'source_sha256':os.environ['O01_CHECKPOINT_SOURCE'],'database':'o01_engineering','files_manifest_sha256':hashlib.sha256((p/'files.before.json').read_bytes()).hexdigest(),'database_dump_sha256':hashlib.sha256((p/'database.sql').read_bytes()).hexdigest(),'archive_sha256':hashlib.sha256((p/'files.tar.gz').read_bytes()).hexdigest(),'verified_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
pathlib.Path('/run/o01-rollback-proof.json').write_text(json.dumps(proof,sort_keys=True))
print('O01_ROLLBACK_READBACK_PASS '+json.dumps(proof,sort_keys=True))
PY
