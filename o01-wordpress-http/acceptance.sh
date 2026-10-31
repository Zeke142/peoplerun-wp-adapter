#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export WP_CLI_ALLOW_ROOT=1
curl --fail --silent --show-error --location --retry 3 -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar core download --path=wordpress --version=6.8.3 --skip-content --quiet
php wp-cli.phar config create --path=wordpress --dbname=o01_ephemeral --dbuser=o01_temp --dbpass=local-only-test-password --dbhost=127.0.0.1:3306 --skip-check --quiet
php wp-cli.phar core install --path=wordpress --url=http://127.0.0.1:8765 --title='O01 Ephemeral' --admin_user=synthetic-admin --admin_password=local-only-admin --admin_email=synthetic@example.invalid --skip-email --quiet
O01_TEST_OWNER_ID=$(php wp-cli.phar user create o01-bound-user o01-bound@example.invalid --path=wordpress --role=subscriber --user_pass=local-only-bound-user --porcelain)
export O01_TEST_OWNER_ID
O01_TEST_COOKIE_FILE=$(mktemp)
export O01_TEST_COOKIE_FILE
O01_DISPOSABLE_ACCEPTANCE=YES php -S 127.0.0.1:8765 -t . router.php >/tmp/o01-wp-binding-server.log 2>&1 & srv=$!
trap 'kill "$srv" 2>/dev/null || true; rm -rf wordpress wp-cli.phar; rm -f "$O01_TEST_COOKIE_FILE"' EXIT
for n in $(seq 1 30); do if curl --silent --output /dev/null http://127.0.0.1:8765/; then break; fi; sleep 1; done
curl -fsS -c "$O01_TEST_COOKIE_FILE" -d 'username=o01-bound-user&password=local-only-bound-user' http://127.0.0.1:8765/o01-approval/login | grep -Fq SIGNED_IN
echo 'PASS real WordPress disposable user login'
O01_DISPOSABLE_ACCEPTANCE=YES php binding-wp-acceptance.php
