#!/usr/bin/env bash
set -euo pipefail
umask 077
export O01_DISPOSABLE_ACCEPTANCE=YES O01_WP_ROOT=/srv/wordpress
test -f /opt/o01/harness.zip
test "$(sha256sum /opt/o01/harness.zip | awk '{print $1}')" = "1197fe1a53d3ddb021fef8f6cfad3f78b3d2f0bc3612fbdd7d553a5b2859d4e3"
mkdir -p /run/mysqld /var/lib/mysql /run/o01 /srv/wordpress/wp-content/mu-plugins
chown mysql:mysql /run/mysqld /var/lib/mysql
chmod 700 /run/o01
dbpid=''
cleanup() {
  if test -n "$dbpid"; then kill "$dbpid" 2>/dev/null || true; wait "$dbpid" 2>/dev/null || true; fi
}
trap cleanup EXIT
if test ! -d /var/lib/mysql/mysql; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=normal >/run/o01/init.log 2>&1
fi
mariadbd --user=mysql --datadir=/var/lib/mysql --bind-address=127.0.0.1 --port=3306 --socket=/run/mysqld/mysqld.sock --skip-networking=0 >/run/o01/mariadb.log 2>&1 &
dbpid=$!
for i in $(seq 1 60); do
  if mariadb-admin --protocol=socket --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then break; fi
  kill -0 "$dbpid"
  sleep 1
done
mariadb-admin --protocol=socket --socket=/run/mysqld/mysqld.sock ping >/dev/null
export O01_DB_PASSWORD="$(openssl rand -hex 32)"
export O01_ADMIN_PASSWORD="$(openssl rand -hex 32)"
mariadb --protocol=socket --socket=/run/mysqld/mysqld.sock <<SQL
CREATE DATABASE o01_ephemeral CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'o01_local'@'127.0.0.1' IDENTIFIED BY '${O01_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON o01_ephemeral.* TO 'o01_local'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
cat > /srv/wordpress/wp-config.php <<'PHP'
<?php
define('DB_NAME','o01_ephemeral');
define('DB_USER','o01_local');
define('DB_PASSWORD',getenv('O01_DB_PASSWORD'));
define('DB_HOST','127.0.0.1');
define('DB_CHARSET','utf8mb4');
define('DB_COLLATE','');
$table_prefix='o01_acceptance_';
define('WP_HOME','https://127.0.0.1:8443');
define('WP_SITEURL','https://127.0.0.1:8443');
define('DISALLOW_FILE_EDIT',true);
define('AUTOMATIC_UPDATER_DISABLED',true);
define('WP_DEBUG',false);
define('AUTH_KEY',getenv('O01_AUTH_KEY'));
define('SECURE_AUTH_KEY',getenv('O01_SECURE_AUTH_KEY'));
define('LOGGED_IN_KEY',getenv('O01_LOGGED_IN_KEY'));
define('NONCE_KEY',getenv('O01_NONCE_KEY'));
define('AUTH_SALT',getenv('O01_AUTH_SALT'));
define('SECURE_AUTH_SALT',getenv('O01_SECURE_AUTH_SALT'));
define('LOGGED_IN_SALT',getenv('O01_LOGGED_IN_SALT'));
define('NONCE_SALT',getenv('O01_NONCE_SALT'));
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once ABSPATH.'wp-settings.php';
PHP
for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
  export "O01_${k}=$(openssl rand -hex 48)"
done
php -r 'if (!extension_loaded("mysqli") || !extension_loaded("pdo_mysql")) exit(20);'
php /opt/o01/bootstrap-wp.php
install -d -m 700 /opt/o01/harness
unzip -q /opt/o01/harness.zip -d /opt/o01/harness
bash /opt/o01/harness/run-http-local.sh
