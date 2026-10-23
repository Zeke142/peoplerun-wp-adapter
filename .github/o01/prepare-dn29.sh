#!/bin/sh
# Prepared operator entrypoint, not called by any push workflow.
set -eu
test "$(id -u)" = 10001
test -n "$O01_RUN_BINDING"
test "$#" -ge 1
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
python3 "$script_dir/stage_dn29.py" "$1"
if test "${2:-}" != --stage-and-verify-rollback; then
  echo 'PREPARED_ONLY: archive validated; no installation or DN29 tests executed'; exit 0
fi
if test -f /run/http.pid && kill -0 "$(cat /run/http.pid)" 2>/dev/null; then
  echo 'STOP: owned HTTP server must be quiesced before integration'; exit 1
fi
python3 "$script_dir/stage_dn29.py" "$1" --stage
php84 -c /work/php.ini -d max_execution_time=15 "$script_dir/dn29-no-payment.php" activate
O01_CHECKPOINT_SOURCE=b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871 sh "$script_dir/rollback.sh"
echo 'DN29_STAGE_AND_ROLLBACK_VERIFIED: fault and reader phases remain uninvoked'
