<?php
declare(strict_types=1);
// Runner-only HTTP endpoint. Caller cannot supply receipts, clock, keys, or ownership.
require __DIR__.'/wordpress/wp-load.php';
require_once __DIR__.'/O01UninstalledWordPressDeliveryEndpoint.php';
$uri=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if(!is_string($uri)||!preg_match('~\A/o01/([a-z0-9-]{3,63})(?:/(about|private))?/?\z~D',$uri,$m)) {http_response_code(404);exit;}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Fixture-only callbacks are explicitly NOT trusted production authorities.
$receiptAuthority=static function(array $record,array $html,int $now):array {
  if(($record['slug']??null)!=='synthetic-page')return [];
  return ['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,
    'consent_sha256'=>str_repeat('1',64),'payment_sha256'=>str_repeat('2',64),'publication_sha256'=>str_repeat('3',64)];
};
$now=(int)($_SERVER['HTTP_X_O01_TEST_CLOCK']??0); // Synthetic clock injected by local acceptance harness ONLY.
if($now<1){http_response_code(403);exit;}
$endpoint=new O01UninstalledWordPressDeliveryEndpoint($db,'o01_temp_',$receiptAuthority);
$result=$endpoint->resolve($m[1],$m[2]??null,$now,null);
http_response_code((int)$result['status']);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
echo (string)($result['body']??'');
