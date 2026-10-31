<?php
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
define('WP_INSTALLING',true);
require __DIR__.'/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(!is_blog_installed())wp_install('O01 disposable','localadmin','synthetic@example.invalid',false,'','local-only-admin-password');
echo "DISPOSABLE_WORDPRESS_INSTALLED\n";
