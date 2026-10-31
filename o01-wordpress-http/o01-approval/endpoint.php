<?php
/** DISPOSABLE TEST ENDPOINT ONLY. Do not deploy to public/prod WordPress. */
declare(strict_types=1);
require_once dirname(__DIR__).'/wordpress/wp-load.php';
require_once __DIR__.'/wordpress_authenticated_approval_v31.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
function o01_test_reply(int $status,array $data):never {http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')o01_test_reply(405,['code'=>'POST_ONLY']);
if(getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')o01_test_reply(403,['code'=>'DISPOSABLE_ONLY']);
// WP authenticates using the actual cookie issued by wp_signon, not request identity fields.
if($path==='/o01-approval/login') {
 $u=wp_signon(['user_login'=>(string)($_POST['username']??''),'user_password'=>(string)($_POST['password']??''),'remember'=>false],false);
 if(is_wp_error($u))o01_test_reply(401,['code'=>'LOGIN_FAILED']);
 wp_set_current_user($u->ID);
 o01_test_reply(200,['code'=>'SIGNED_IN']);
}
if(!is_user_logged_in() || !wp_get_session_token())o01_test_reply(401,['code'=>'AUTH_REQUIRED']);
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($path==='/o01-approval/nonce') {
 $q=$pdo->prepare('SELECT owner_id,status FROM pr_o01_revision_authority WHERE project_id=? AND revision_id=?');
 $project=(string)($_POST['project_id']??'');$rev=(string)($_POST['revision_id']??'');$q->execute([$project,$rev]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row || $row['status']!=='awaiting_approval' || (string)$row['owner_id']!==(string)get_current_user_id())o01_test_reply(403,['code'=>'NOT_OWNER_OR_REVISION']);
 o01_test_reply(200,['nonce'=>wp_create_nonce('o01_approve_'.$project.'_'.$rev)]);
}
if($path!=='/o01-approval/approve')o01_test_reply(404,['code'=>'NOT_FOUND']);
$req=[];foreach(['project_id','revision_id','html_sha256','quote_sha256','nonce'] as $k)$req[$k]=(string)($_POST[$k]??'');
$result=o01_stage_wp_approval_v31($pdo,$req);
if(($result['ok']??false)!==true){if($pdo->inTransaction())$pdo->rollBack();o01_test_reply(409,$result);}
try {$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();o01_test_reply(500,['code'=>'COMMIT_FAILED']);}
o01_test_reply(200,['code'=>'COMMITTED_APPROVAL']);
