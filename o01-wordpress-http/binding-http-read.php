<?php
declare(strict_types=1);
/** Disposable loopback test endpoint; no host route, credentials or real authority. */
if(PHP_SAPI!=='cli-server'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES'){http_response_code(403);exit;}
if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/bound-good'){http_response_code(404);exit;}
require_once __DIR__.'/O01UninstalledWordPressDeliveryEndpoint.php';
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='o01_ephemeral'){http_response_code(503);exit;}
$authority=static function(array $record,array $html,int $now)use($db):array {
 if(($record['slug']??null)!=='bound-good')return [];
 $q=$db->prepare('SELECT approval_sha256 FROM pr_o01_approval_claims WHERE project_id=? AND revision_id=?');$q->execute(['bind-good','revision-1']);$sha=$q->fetchColumn();
 if(!is_string($sha))return [];
 // Fixture-only mapping and receipt verifier, never a production trust event.
 return ['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,
  'consent_sha256'=>$sha,'payment_sha256'=>hash('sha256','SYNTHETIC_PAYMENT'),'publication_sha256'=>hash('sha256','SYNTHETIC_PUBLICATION')];
};
$r=(new O01UninstalledWordPressDeliveryEndpoint($db,'o01_binding_',$authority))->resolve('bound-good',null,strtotime('2027-01-31T00:00:01Z'));
http_response_code($r['status']);foreach(($r['headers']??[])as $name=>$value)header($name.': '.$value);echo $r['body'];
