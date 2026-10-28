#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export WP_CLI_ALLOW_ROOT=1
curl --fail --silent --show-error --location --retry 3 -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar core download --path=wordpress --version=6.8.3 --skip-content --quiet
php wp-cli.phar config create --path=wordpress --dbname=o01_ephemeral --dbuser=o01_temp --dbpass=local-only-test-password --dbhost=127.0.0.1:3306 --skip-check --quiet
php wp-cli.phar core install --path=wordpress --url=http://127.0.0.1:8765 --title='O01 Ephemeral' --admin_user=synthetic-admin --admin_password=local-only-admin --admin_email=synthetic@example.invalid --skip-email --quiet
php -r 'require "synthetic-fixture.php"; $d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);o01_fixture($d);'
# WordPress front controller and isolated O01 endpoint share the PHP loopback server.
php -S 127.0.0.1:8765 -t . router.php >/dev/null 2>&1 & srv=$!
trap 'kill "$srv" 2>/dev/null || true; rm -rf wordpress wp-cli.phar' EXIT
for n in $(seq 1 30); do if curl --silent --output /dev/null http://127.0.0.1:8765/o01/synthetic-page;then break;fi;sleep 1;done
get(){ curl --silent --show-error --output /tmp/o01-response --write-out '%{http_code}' -H "X-O01-Test-Clock: $2" "http://127.0.0.1:8765/o01/$1"; }
start=$(date -u -d '2027-01-31 00:00:00' +%s); end=$(date -u -d '2027-02-28 00:00:00' +%s)
code=$(get synthetic-page "$((start+1))"); test "$code" = 200; grep -Fq 'O01 SYNTHETIC WORDPRESS DELIVERY' /tmp/o01-response; echo 'PASS HTTP WordPress bootstrapped exact committed bytes'
code=$(get synthetic-page "$end"); test "$code" = 410; echo 'PASS exact UTC expiry'
code=$(get synthetic-page/private "$((start+1))"); test "$code" != 200; echo 'PASS private child denied without password'
code=$(get absent-page "$((start+1))"); test "$code" != 200; echo 'PASS absent page denied'
php -r '$d=new PDO("mysql:host=127.0.0.1;dbname=o01_ephemeral","o01_temp","local-only-test-password");$d->exec("UPDATE o01_temp_o01_paid_routes SET record_json=JSON_SET(record_json, '''$.state''', '''withdrawn''') WHERE slug='''synthetic-page'''");'
code=$(get synthetic-page "$((start+1))"); test "$code" = 410; echo 'PASS withdrawn parent denied'
echo REAL_WORDPRESS_HTTP_INNODB_SYNTHETIC_ACCEPTANCE_COMPLETED
