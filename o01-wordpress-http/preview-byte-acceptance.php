<?php
declare(strict_types=1);
/** SYNTHETIC TEST ONLY. Does not create customer consent, payment or host authority. */
if (PHP_SAPI !== 'cli' || getenv('O01_DISPOSABLE_ACCEPTANCE') !== 'YES') exit(77);
require_once __DIR__.'/PersistedRouteReader.php';
function assert_case(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name."\n";
}
// Deliberately retain UTF-8, CRLF, whitespace and terminal newline.
$preview="<!doctype html>\r\n<main>Example Test Workshop — café</main>  \r\n";
$start=strtotime('2027-01-31T00:00:00Z');
$end=strtotime('2027-02-28T00:00:00Z');
$route=['slug'=>'preview-byte-test','owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13,
 'state'=>'published','parent_html_b64'=>base64_encode($preview),
 'html_sha256'=>hash('sha256',$preview),'revision_sha256'=>hash('sha256',$preview),
 'consent_sha256'=>str_repeat('1',64),'payment_sha256'=>str_repeat('2',64),'publication_sha256'=>str_repeat('3',64),
 'term'=>'month','term_seconds'=>$end-$start,'started_at'=>$start,'expires_at'=>$end];
// Fixture-only authority accepts ONLY the frozen original identity, terms and pins.
// This is a test double, never the missing production approval-to-publication verifier.
$fixtureAuthority=static function(array $record,array $html,int $now) use ($route): array {
 foreach(['slug','owner_id','project_id','revision_id','reservation_id','term','term_seconds','started_at','expires_at','html_sha256','revision_sha256','consent_sha256','payment_sha256','publication_sha256'] as $key)
  if(($record[$key]??null)!==$route[$key])return [];
 return ['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,
  'consent_sha256'=>$route['consent_sha256'],'payment_sha256'=>$route['payment_sha256'],'publication_sha256'=>$route['publication_sha256']];
};
$forbidG1=static function():never {throw new RuntimeException('anonymous_g1_read_forbidden');};
$read=static function(array $record,?callable $authority=null,?int $now=null) use ($fixtureAuthority,$forbidG1,$start): array {
 return O01PersistedRouteReader::resolve('preview-byte-test',$now??($start+1),static fn(string $slug)=>$record,$forbidG1,$authority??$fixtureAuthority);
};
$r=$read($route);
assert_case($r['status']===200 && $r['body']===$preview && strlen($r['body'])===strlen($preview) && hash('sha256',$r['body'])===$route['html_sha256'],'committed reader preserves exact UTF-8/CRLF/trailing-whitespace bytes');
assert_case(($r['headers']['Cache-Control']??'')==='private, no-store, max-age=0','reader marks delivered preview no-store');
$bad=$route;$bad['parent_html_b64']=base64_encode($preview.' ');
$r=$read($bad);assert_case($r['status']===503 && $r['body']==='' && $r['reason']==='parent_snapshot_integrity_failed','altered committed byte rejected');
$bad=$route;$bad['parent_html_b64']=base64_encode($preview.' ');$bad['html_sha256']=hash('sha256',$preview.' ');$bad['revision_sha256']=$bad['html_sha256'];
$r=$read($bad);assert_case($r['status']===503 && $r['body']==='','rehashed replacement rejected by frozen fixture authority');
$bad=$route;$bad['revision_sha256']=str_repeat('4',64);
$r=$read($bad);assert_case($r['status']===503 && $r['body']==='','mismatched revision digest rejected');
$bad=$route;$bad['parent_html_b64']='!invalid-base64!';
$r=$read($bad);assert_case($r['status']===503 && $r['body']==='','invalid base64 rejected');
$bad=$route;unset($bad['parent_html_b64']);
$r=$read($bad);assert_case($r['status']===503 && $r['body']==='','missing committed snapshot rejected');
foreach(['owner_id','project_id','revision_id','reservation_id'] as $id){
 $bad=$route;$bad[$id]++;
 $r=$read($bad);assert_case($r['status']===503 && $r['body']==='','changed '.$id.' rejected by frozen fixture authority');
}
$r=$read($route,static fn()=>[]);assert_case($r['status']===503 && $r['body']==='','missing authority rejected');
$wrongReceipt=static fn()=>['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,
 'consent_sha256'=>str_repeat('4',64),'payment_sha256'=>str_repeat('2',64),'publication_sha256'=>str_repeat('3',64)];
$r=$read($route,$wrongReceipt);assert_case($r['status']===503 && $r['reason']==='receipt_pin_mismatch','mismatched consent receipt pin rejected');
$bad=$route;$bad['state']='withdrawn';$r=$read($bad);assert_case($r['status']===410 && $r['body']==='','withdrawn preview denied');
$r=$read($route,null,$end);assert_case($r['status']===410 && $r['body']==='','exact fixture expiry denies delivery');
$bad=$route;$bad['term']='year';$r=$read($bad);assert_case($r['status']===503 && $r['body']==='','changed term rejected by frozen fixture authority');
echo "SKIP real HTTP response byte equality: CLI reader test has no HTTP transport\n";
echo "SKIP v34 approval claim to publication authority: server-owned mapping/verifier not supplied\n";
echo "SKIP price and full quote edit invalidation: route stores no amount/currency/quote digest\n";
echo "SKIP full revision edit lifecycle: no version-bound live receipt revocation integration\n";
echo "SYNTHETIC_READER_BYTE_CASES_COMPLETED_ONLY\n";
