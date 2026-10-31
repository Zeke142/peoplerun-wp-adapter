#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export WP_CLI_ALLOW_ROOT=1
# Fail before fixture creation if the isolated PHP runtime lacks either required driver.
php -r 'if(!extension_loaded("pdo_mysql")||!in_array("mysql",PDO::getAvailableDrivers(),true)||!extension_loaded("mysqli"))exit(2);echo "PASS v34 PDO MySQL and mysqli module probe\n";'
curl --fail --silent --show-error --location --retry 3 -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar core download --path=wordpress --version=6.8.3 --skip-content --quiet
php wp-cli.phar config create --path=wordpress --dbname=o01_ephemeral --dbuser=o01_temp --dbpass=local-only-test-password --dbhost=127.0.0.1:3306 --skip-check --quiet
php wp-cli.phar core install --path=wordpress --url=http://127.0.0.1:8765 --title='O01 Ephemeral' --admin_user=synthetic-admin --admin_password=local-only-admin --admin_email=synthetic@example.invalid --skip-email --quiet
php -r 'require "synthetic-fixture.php"; $d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);o01_fixture($d);'
# WordPress front controller and isolated O01 endpoint share the PHP loopback server.
O01_DISPOSABLE_ACCEPTANCE=YES php -S 127.0.0.1:8765 -t . router.php >/dev/null 2>&1 & srv=$!
trap 'kill "$srv" 2>/dev/null || true; rm -rf wordpress wp-cli.phar' EXIT
for n in $(seq 1 30); do if curl --silent --output /dev/null http://127.0.0.1:8765/o01/synthetic-page;then break;fi;sleep 1;done
get(){ curl --silent --show-error --output /tmp/o01-response --write-out '%{http_code}' -H "X-O01-Test-Clock: $2" "http://127.0.0.1:8765/o01/$1"; }
start=$(date -u -d '2027-01-31 00:00:00' +%s); end=$(date -u -d '2027-02-28 00:00:00' +%s)
code=$(get synthetic-page "$((start+1))"); test "$code" = 200; grep -Fq 'O01 SYNTHETIC WORDPRESS DELIVERY' /tmp/o01-response; echo 'PASS HTTP WordPress bootstrapped exact committed bytes'
code=$(get synthetic-page "$end"); test "$code" = 410; echo 'PASS exact UTC expiry'
code=$(get synthetic-page/private "$((start+1))"); test "$code" != 200; echo 'PASS private child denied without password'
code=$(get absent-page "$((start+1))"); test "$code" != 200; echo 'PASS absent page denied'
php -r '$d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$d->prepare("UPDATE o01_temp_o01_paid_routes SET record_json=JSON_SET(record_json, ?, ?) WHERE slug=?");$q->execute(["$.state","withdrawn","synthetic-page"]);if($q->rowCount()!==1){throw new RuntimeException("withdrawal_fixture_row_missing");}'
code=$(get synthetic-page "$((start+1))"); test "$code" = 410; echo 'PASS withdrawn parent denied'
echo REAL_WORDPRESS_HTTP_INNODB_SYNTHETIC_ACCEPTANCE_COMPLETED


# Isolated v3.1 approval test: actual WordPress test user, cookie, nonce, transaction + replay.
php wp-cli.phar user create o01-test-user o01-test@example.invalid --path=wordpress --role=subscriber --user_pass=local-only-test-user --porcelain > /tmp/o01-approval-userid
php -r '$d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$sql=file_get_contents("o01-approval/recovered/o01_candidate_v11/schema.sql");$d->exec($sql);$uid=(int)file_get_contents("/tmp/o01-approval-userid");$q=$d->prepare("INSERT INTO pr_o01_revision_authority(project_id,revision_id,owner_id,html_sha256,quote_sha256,amount_minor,currency,term,status) VALUES(?,?,?,?,?,?,?,?,?)");$q->execute(["test-project","revision-1",$uid,str_repeat("a",64),str_repeat("b",64),4900,"USD","one_month","awaiting_approval"]);'
COOKIE_FILE=$(mktemp); trap 'kill "$srv" 2>/dev/null || true; rm -rf wordpress wp-cli.phar; rm -f "$COOKIE_FILE" /tmp/o01-approval-userid' EXIT
curl -fsS -c "$COOKIE_FILE" -d 'username=o01-test-user&password=local-only-test-user' http://127.0.0.1:8765/o01-approval/login | grep -Fq SIGNED_IN
NONCE=$(curl -fsS -b "$COOKIE_FILE" -d 'project_id=test-project&revision_id=revision-1' http://127.0.0.1:8765/o01-approval/nonce | php -r '$r=json_decode(stream_get_contents(STDIN),true);echo $r["nonce"]??"";')
test -n "$NONCE"
# Synthetic negative requests must not change authority or create a claim.
assert_pristine(){
 php -r '$d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$d->query("SELECT status FROM pr_o01_revision_authority WHERE project_id=\"test-project\" AND revision_id=\"revision-1\"");if($q->fetchColumn()!=="awaiting_approval")exit(2);if((int)$d->query("SELECT COUNT(*) FROM pr_o01_approval_claims")->fetchColumn()!==0)exit(3);'
}
post_status(){
 local expected="$1"; shift
 local actual
 actual=$(curl -sS -o /tmp/o01-v34-negative-response -w '%{http_code}' "$@")
 test "$actual" = "$expected"
 assert_pristine
}
APPROVE_URL=http://127.0.0.1:8765/o01-approval/approve
GOOD_HTML=$(printf 'a%.0s' {1..64}); GOOD_QUOTE=$(printf 'b%.0s' {1..64})
post_status 401 -d 'project_id=test-project&revision_id=revision-1' "$APPROVE_URL"
echo 'PASS v34 anonymous request rejected without DB mutation'
post_status 401 -d 'username=o01-test-user&password=incorrect-synthetic-password' http://127.0.0.1:8765/o01-approval/login
echo 'PASS v34 invalid login rejected without DB mutation'
post_status 409 -b "$COOKIE_FILE" -d "project_id=test-project&revision_id=revision-1&nonce=invalid&html_sha256=$GOOD_HTML&quote_sha256=$GOOD_QUOTE" "$APPROVE_URL"
echo 'PASS v34 invalid nonce rejected without DB mutation'
post_status 409 -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d "project_id=test-project&revision_id=revision-1&html_sha256=$(printf 'c%.0s' {1..64})&quote_sha256=$GOOD_QUOTE" "$APPROVE_URL"
echo 'PASS v34 altered preview hash rejected without DB mutation'
post_status 409 -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d "project_id=test-project&revision_id=revision-1&html_sha256=$GOOD_HTML&quote_sha256=$(printf 'd%.0s' {1..64})" "$APPROVE_URL"
echo 'PASS v34 altered quote hash rejected without DB mutation'
post_status 409 -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d "project_id=unowned-project&revision_id=revision-1&html_sha256=$GOOD_HTML&quote_sha256=$GOOD_QUOTE" "$APPROVE_URL"
echo 'PASS v34 missing project authority rejected without DB mutation'
post_status 409 -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d "project_id=test-project&revision_id=unapproved-revision&html_sha256=$GOOD_HTML&quote_sha256=$GOOD_QUOTE" "$APPROVE_URL"
echo 'PASS v34 wrong revision rejected without DB mutation'
curl -fsS -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d 'project_id=test-project&revision_id=revision-1&html_sha256='"$(printf 'a%.0s' {1..64})"'&quote_sha256='"$(printf 'b%.0s' {1..64})" http://127.0.0.1:8765/o01-approval/approve | grep -Fq COMMITTED_APPROVAL
STATUS=$(curl -sS -o /tmp/o01-approval-replay -w '%{http_code}' -b "$COOKIE_FILE" --data-urlencode "nonce=$NONCE" -d 'project_id=test-project&revision_id=revision-1&html_sha256='"$(printf 'a%.0s' {1..64})"'&quote_sha256='"$(printf 'b%.0s' {1..64})" http://127.0.0.1:8765/o01-approval/approve); test "$STATUS" = 409
echo O01_DISPOSABLE_WORDPRESS_APPROVAL_ACCEPTANCE_COMPLETED

php -r '$d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$d->query("SELECT status FROM pr_o01_revision_authority WHERE project_id=\"test-project\" AND revision_id=\"revision-1\"");if($q->fetchColumn()!=="approved")exit(2);$q=$d->query("SELECT html_sha256,quote_sha256,receipt_id FROM pr_o01_approval_claims WHERE project_id=\"test-project\" AND revision_id=\"revision-1\"");$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1||$rows[0]["html_sha256"]!==str_repeat("a",64)||$rows[0]["quote_sha256"]!==str_repeat("b",64)||!preg_match("/^[a-f0-9]{64}$/D",$rows[0]["receipt_id"]))exit(3);'
echo 'PASS v34 approval and rejected replay leave exactly one matching committed claim'

# Isolated two-connection InnoDB race acceptance; no production targets.
O01_DISPOSABLE_ACCEPTANCE=YES php o01-approval/withdraw_race_acceptance.php
