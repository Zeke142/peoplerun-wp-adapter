<?php
declare(strict_types=1);
if (getenv('O01_DISPOSABLE_ACCEPTANCE') !== 'YES') exit(10);
$root='/srv/wordpress';
if (!is_file("$root/wp-load.php")) exit(11);
require "$root/wp-load.php";
if (DB_NAME!=='o01_ephemeral' || DB_HOST!=='127.0.0.1' ||
    $GLOBALS['wpdb']->prefix!=='o01_acceptance_' ||
    home_url()!=='https://127.0.0.1:8443') exit(12);
require_once ABSPATH.'wp-admin/includes/upgrade.php';
// Fresh disposable database is expected; reject pre-existing WordPress tables.
$existing = $GLOBALS['wpdb']->get_var("SHOW TABLES LIKE 'o01_acceptance_%'");
if ($existing !== null) exit(13);
$adminpass=getenv('O01_ADMIN_PASSWORD');
if (!$adminpass || strlen($adminpass)<32) exit(14);
$result=wp_install('O01 disposable test','o01_local_admin','o01@example.invalid',false,'',$adminpass,'en_US');
if (empty($result['user_id']) || !is_blog_installed()) exit(15);
echo "DISPOSABLE_WORDPRESS_INSTALLED\n";
