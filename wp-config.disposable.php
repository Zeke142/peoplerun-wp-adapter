<?php
// Disposable local WordPress only. No production credentials.
define('DB_NAME','o01_integrated_disposable');
define('DB_USER','root');define('DB_PASSWORD','');define('DB_HOST','127.0.0.1:33309');
define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
$table_prefix='wp_';
define('WP_HTTP_BLOCK_EXTERNAL',true);define('DISABLE_WP_CRON',true);
define('WP_SITEURL','http://127.0.0.1:8769');define('WP_HOME','http://127.0.0.1:8769');
define('WP_DEBUG',false);
foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key)define($key,'disposable-local-'.hash('sha256',$key));
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require_once ABSPATH.'wp-settings.php';
