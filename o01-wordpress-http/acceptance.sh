#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export WP_CLI_ALLOW_ROOT=1
export O01_DISPOSABLE_ACCEPTANCE=YES
trap 'rm -rf wordpress wp-cli.phar' EXIT
curl --fail --silent --show-error --location --retry 3 -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar core download --path=wordpress --version=6.8.3 --skip-content --quiet
php wp-cli.phar config create --path=wordpress --dbname=o01_ephemeral --dbuser=o01_temp --dbpass=local-only-test-password --dbhost=127.0.0.1:3306 --skip-check --quiet
mkdir -p wordpress/wp-content/mu-plugins
cp host-g1-source.php wordpress/wp-content/mu-plugins/o01-g1.php
cp host-g2-source.php wordpress/wp-content/mu-plugins/o01-g2.php
php wp-cli.phar core install --path=wordpress --url=http://127.0.0.1:8765 --title='O01 Identity Ephemeral' --admin_user=synthetic-admin --admin_password=local-only-admin --admin_email=synthetic@example.invalid --skip-email --quiet
O01_TEST_OWNER_ID=$(php wp-cli.phar user create o01-identity-user o01-identity@example.invalid --path=wordpress --role=subscriber --user_pass=local-only-identity-user --porcelain)
export O01_TEST_OWNER_ID
php -l O01ServerIdentityContext.php
php -l server-identity-check.php
php server-identity-check.php
