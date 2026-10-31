<?php
declare(strict_types=1);
/** DISPOSABLE ENGINEERING TEST. Synthetic consent/payment/publication callbacks, no real customer. */
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
define('ABSPATH',__DIR__.'/');
function add_filter(...$args):void {}
require_once __DIR__.'/receipt-source/o01-receipt-admission-dn25.php';
require_once __DIR__.'/O01PublisherReceiptBinding.php';
require_once __DIR__.'/o01-approval/recovered/o01_candidate_v11/caller_owned_approval.php';
require_once __DIR__.'/AtomicPaidRoute.php';
require_once __DIR__.'/WPMySQLPaidRouteStore.php';
require_once __DIR__.'/PersistedRouteReader.php';
function binding_db():PDO {
 $d=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $d->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);
 if($d->query('SELECT DATABASE()')->fetchColumn()!=='o01_ephemeral')throw new RuntimeException('wrong_database');return $d;
}
function check_db(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
// Child attempts a REAL concurrent edit while publication owns the approval row lock.
if(($argv[1]??'')==='--edit-probe') {
 $project=$argv[2]??'';if(!preg_match('/\Abind-[a-z0-9-]+\z/D',$project))exit(78);
 $d=binding_db();$d->exec('SET SESSION innodb_lock_wait_timeout=1');$d->beginTransaction();
 try{$q=$d->prepare('UPDATE pr_o01_revision_authority SET amount_minor=amount_minor+1 WHERE project_id=? AND revision_id=?');$q->execute([$project,'revision-1']);$d->rollBack();echo json_encode(['blocked'=>false]);}
 catch(PDOException $e){if($d->inTransaction())$d->rollBack();echo json_encode(['blocked'=>($e->errorInfo[1]??0)===1205,'errno'=>$e->errorInfo[1]??null]);}
 exit;
}
function canonical_fixture(string $slug):array {
 return ['customer_id'=>'fixture-customer','offer_id'=>'fixture-offer','price_cents'=>4900,'currency'=>'USD','page_count'=>1,'term'=>'1_month','privacy'=>'public','slug'=>$slug,'revision'=>1,'expires_at_utc'=>'2027-02-28T00:00:00Z','sanitized_html'=>'<p>Bound café preview</p>','css'=>O01_DN17_CSS,'asset_manifest'=>[]];
}
function original_approval(PDO $d,string $project,string $html):array {
 $hash=hash('sha256',$html);$quote=o01_manifest(canonical_fixture(str_replace('bind-','bound-',$project)))['manifest_sha256'];
 $q=$d->prepare('INSERT INTO pr_o01_revision_authority(project_id,revision_id,owner_id,html_sha256,quote_sha256,amount_minor,currency,term,status) VALUES(?,?,?,?,?,?,?,?,?)');
 $q->execute([$project,'revision-1',5,$hash,$quote,4900,'USD','one_month','awaiting_approval']);
 $d->beginTransaction();
 $result=(new O01CallerOwnedApproval($d))->verifyAndClaim(['project_id'=>$project,'revision_id'=>'revision-1'],static function(array $claim,array $locked,callable $once):array {
  // Exact v3.1 preimage structure, but synthetic verifier: no WordPress login claimed.
  $v=['identity'=>'wp_user:'.$locked['owner_id'],'revision_id'=>$locked['revision_id'],'html_sha256'=>$locked['html_sha256'],
      'receipt_id'=>bin2hex(random_bytes(32)),'amount_minor'=>$locked['amount_minor'],'currency'=>$locked['currency'],'term'=>$locked['term']];
  $key=hash('sha256',implode("\0",[$locked['project_id'],$locked['revision_id'],'customer_approval']));
  return ['ok'=>$once($key,$v)];
 });
 if(($result['ok']??false)!==true){$d->rollBack();throw new RuntimeException('seed_approval_failed');}$d->commit();
 $q=$d->prepare('SELECT * FROM pr_o01_approval_claims WHERE project_id=? AND revision_id=?');$q->execute([$project,'revision-1']);return $q->fetch(PDO::FETCH_ASSOC);
}
function route_count(PDO $d,string $slug):int {$q=$d->prepare('SELECT COUNT(*) FROM o01_binding_o01_paid_routes WHERE slug=?');$q->execute([$slug]);return (int)$q->fetchColumn();}
function request_count(PDO $d,string $key):int {$q=$d->prepare('SELECT COUNT(*) FROM o01_binding_o01_paid_receipts WHERE request_key=?');$q->execute([$key]);return (int)$q->fetchColumn();}
function publish_case(PDO $d,string $project,string $slug,string $html,array $claim,bool $probe=false,bool $failReceipt=false,string $testCase='good'):array {
 $hash=hash('sha256',$html);$start=strtotime('2027-01-31T00:00:00Z');$end=strtotime('2027-02-28T00:00:00Z');
 $candidate=['verified'=>true,'authority_receipt_sha256'=>hash('sha256','SYNTHETIC_AUTHORITY'),
  'owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13,'slug'=>$slug,
  'revision_sha256'=>$claim['html_sha256'],'html_sha256'=>$claim['html_sha256'],'quote_sha256'=>$claim['quote_sha256'],
  'amount_minor'=>4900,'currency'=>'USD','term'=>'month','started_at'=>$start,'expires_at'=>$end,'term_seconds'=>$end-$start,
  'consent_sha256'=>$claim['approval_sha256'],'payment_sha256'=>hash('sha256','SYNTHETIC_PAYMENT'),
  'publication_sha256'=>hash('sha256','SYNTHETIC_PUBLICATION')];
 $mapping=['approval_project_id'=>$project,'approval_revision_id'=>'revision-1','approval_owner_id'=>5,
  'owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13,'customer_id'=>'fixture-customer','canonical_revision'=>1];
 $authority=static function($op,$command,$current,$now)use($d,$project,$mapping,$candidate,$html,$probe,$testCase):array {
  $result=O01ApprovalPublicationBinding::lockAndCheck($d,$project,'revision-1',$mapping,$candidate,$html);
  if(($result['ok']??false)!==true)return [];
  if($probe){
   $proc=proc_open([PHP_BINARY,__FILE__,'--edit-probe',$project],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
   if(!is_resource($proc))throw new RuntimeException('probe_start_failed');fclose($pipes[0]);
   $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);
   $r=json_decode($out,true,512,JSON_THROW_ON_ERROR);check_db($status===0&&($r['blocked']??false)===true&&($r['errno']??0)===1205,'concurrent price edit blocked by publication approval-row lock');
  }
  $canonical=canonical_fixture($candidate['slug']);
  $context=['digest_contract'=>'dn29_manifest_candidate_v1','manifest_sha256'=>$candidate['quote_sha256'],'customer_id'=>'fixture-customer'];
  $ids=['manifest_sha256'=>$candidate['quote_sha256'],'customer_id'=>'fixture-customer','approval_issuer'=>'fixture-issuer','consent_ref'=>'consent-'.$testCase,'payment_provider'=>'stripe_live','event_id'=>'event-'.$testCase,'payment_id'=>'payment-'.$testCase,'timestamp'=>$now];
  if(str_starts_with($testCase,'replay-')){
   $field=['event'=>'event_id','payment'=>'payment_id','consent'=>'consent_ref'][substr($testCase,7)];$ids[$field]=['event_id'=>'event-good','payment_id'=>'payment-good','consent_ref'=>'consent-good'][$field];
  }
  if($testCase==='bad-digest-contract')$context['digest_contract']='unknown';
  if($testCase==='bad-canonical')$canonical['price_cents']=7900;
  if($testCase==='bad-customer')$ids['customer_id']='different';
  if($testCase==='bad-manifest')$ids['manifest_sha256']=str_repeat('f',64);
  $adapter=$testCase==='unconfigured'?new O01ReceiptTransaction():new O01ReceiptTransaction(static fn(...$ignored)=>$ids);
  $publicationVerifier=static function(...$ignored)use($candidate,$testCase):array {
   if($testCase==='missing-publication')return [];
   $v=$candidate;if($testCase==='wrong-publication-owner')$v['owner_id']=6;return $v;
  };
  return O01PublisherReceiptBinding::checkAndReserve($d,$project,'revision-1',$mapping,$candidate,$html,$canonical,$context,[],[],[],$now,$adapter,$publicationVerifier);
 };
 $inner=new O01WPMySQLPaidRouteStore($d,'o01_binding_');
 // Inject failure AFTER actual route insert and BEFORE commit to test DB rollback.
 $store=$failReceipt?new class($inner){
  public function __construct(private O01WPMySQLPaidRouteStore $s){}
  public function begin($a,$b){return $this->s->begin($a,$b);}public function findRequest($k){return $this->s->findRequest($k);}
  public function findSlug($s){return $this->s->findSlug($s);}public function put($s,$r){return $this->s->put($s,$r);}
  public function receipt($k,$r){return false;}public function commit(){return $this->s->commit();}public function rollback(){return $this->s->rollback();}
 }:$inner;
 $parent=static fn($record,$verified)=>['html'=>$html,'html_sha256'=>hash('sha256',$html),'owner_id'=>5,'project_id'=>9,'revision_id'=>12];
 return O01AtomicPaidRoute::transition($store,'publish',['slug'=>$slug,'request_key'=>'binding-request-'.$slug],$authority,$start+1,null,$parent);
}
$d=binding_db();$fresh=binding_db();
$d->exec(file_get_contents(__DIR__.'/o01-approval/recovered/o01_candidate_v11/schema.sql'));
$d->exec('CREATE TABLE o01_receipt_candidate_ledger(manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,customer_id VARCHAR(180) NOT NULL,approval_issuer VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,consent_ref VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payment_provider VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,event_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payment_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,admitted_at BIGINT NOT NULL,UNIQUE KEY consent_once(approval_issuer,consent_ref),UNIQUE KEY event_once(payment_provider,event_id),UNIQUE KEY payment_once(payment_provider,payment_id)) ENGINE=InnoDB');
$d->exec('CREATE TABLE o01_binding_o01_paid_routes(slug VARCHAR(63) PRIMARY KEY,record_json LONGTEXT NOT NULL) ENGINE=InnoDB');
$d->exec('CREATE TABLE o01_binding_o01_paid_receipts(request_key VARCHAR(120) PRIMARY KEY,receipt_json LONGTEXT NOT NULL) ENGINE=InnoDB');
$html="";
$projects=[];$slugs=[];$keys=[];
try {
 foreach(['good','price','term','revision','withdraw','bytes','rollback','unconfigured','bad-digest-contract','bad-canonical','bad-customer','bad-manifest','missing-publication','wrong-publication-owner','replay-event','replay-payment','replay-consent'] as $case){
  $project='bind-'.$case;$slug='bound-'.$case;$key='binding-request-'.$slug;$projects[]=$project;$slugs[]=$slug;$keys[]=$key;
  $html=o01_render(canonical_fixture($slug));$claim=original_approval($d,$project,$html);
  $ledgerBefore=(int)$fresh->query('SELECT COUNT(*) FROM o01_receipt_candidate_ledger')->fetchColumn();
  if($case==='price'){$q=$d->prepare('UPDATE pr_o01_revision_authority SET amount_minor=7900 WHERE project_id=?');$q->execute([$project]);}
  if($case==='term'){$q=$d->prepare('UPDATE pr_o01_revision_authority SET term=? WHERE project_id=?');$q->execute(['one_year',$project]);}
  if($case==='revision'){$q=$d->prepare('UPDATE pr_o01_revision_authority SET html_sha256=? WHERE project_id=?');$q->execute([hash('sha256',$html.'changed'),$project]);}
  if($case==='withdraw'){$q=$d->prepare('UPDATE pr_o01_revision_authority SET status=? WHERE project_id=?');$q->execute(['withdrawn',$project]);}
  // Approved->withdrawn here is deliberate direct SQL fault injection, NOT an approved production transition API.
  $result=publish_case($d,$project,$slug,$case==='bytes'?$html.' ':$html,$claim,$case==='good',$case==='rollback',$case);
  check_db(!$d->inTransaction(),'publication transaction closed for '.$case);
  if($case==='good'){
   check_db(($result['result']??'')==='COMMITTED_LOCAL_CANDIDATE','binding and atomic route commit share real PDO transaction');
   check_db((int)$fresh->query('SELECT COUNT(*) FROM o01_receipt_candidate_ledger')->fetchColumn()===$ledgerBefore+1,'receipt reservation committed with actual publisher route');
   check_db(route_count($fresh,$slug)===1&&request_count($fresh,$key)===1,'fresh DB sees exactly one route and one publication receipt');
   $q=$fresh->prepare('SELECT record_json FROM o01_binding_o01_paid_routes WHERE slug=?');$q->execute([$slug]);$record=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
   check_db(base64_decode($record['parent_html_b64'],true)===$html&&$record['html_sha256']===hash('sha256',$html),'committed route contains exact approved fixture bytes');
   $authority=static fn($r,$h,$n)=>['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,
    'consent_sha256'=>$claim['approval_sha256'],'payment_sha256'=>hash('sha256','SYNTHETIC_PAYMENT'),'publication_sha256'=>hash('sha256','SYNTHETIC_PUBLICATION')];
   $r=O01PersistedRouteReader::resolve($slug,strtotime('2027-01-31T00:00:01Z'),static fn()=>$record,static fn()=>null,$authority);
   check_db($r['status']===200&&$r['body']===$html,'reader returns exact bytes from committed bound fixture');
   // Real loopback HTTP transport, using the existing endpoint and committed DB row.
   $server=proc_open([PHP_BINARY,'-S','127.0.0.1:8766',__DIR__.'/binding-http-read.php'],[0=>['pipe','r'],1=>['file','/tmp/o01-binding-http.out','a'],2=>['file','/tmp/o01-binding-http.err','a']],$serverPipes);
   if(!is_resource($server))throw new RuntimeException('http_server_start_failed');fclose($serverPipes[0]);
   try {
    $response=false;$headers=[];
    for($attempt=0;$attempt<40;$attempt++){
     $response=@file_get_contents('http://127.0.0.1:8766/bound-good',false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>2]]));
     $headers=$http_response_header??[];
     if($response!==false)break;usleep(50000);
    }
    check_db(is_string($response)&&str_contains($headers[0]??'','200'),'actual loopback HTTP returns success');
    check_db($response===$html&&strlen($response)===strlen($html)&&hash('sha256',$response)===$claim['html_sha256'],'actual HTTP bytes match approved fixture byte-for-byte');
    $q=$d->prepare('SELECT record_json FROM o01_binding_o01_paid_routes WHERE slug=?');$q->execute([$slug]);$savedJson=$q->fetchColumn();
    $tampered=json_decode($savedJson,true,512,JSON_THROW_ON_ERROR);$tampered['parent_html_b64']=base64_encode($html.' ');
    $q=$d->prepare('UPDATE o01_binding_o01_paid_routes SET record_json=? WHERE slug=?');$q->execute([json_encode($tampered,JSON_THROW_ON_ERROR),$slug]);
    try {
     $denied=file_get_contents('http://127.0.0.1:8766/bound-good',false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>2]]));
     $denialHeaders=$http_response_header??[];
     check_db(str_contains($denialHeaders[0]??'','503')&&$denied==='','actual HTTP rejects tampered committed preview without serving bytes');
    } finally {$q=$d->prepare('UPDATE o01_binding_o01_paid_routes SET record_json=? WHERE slug=?');$q->execute([$savedJson,$slug]);}
   } finally {proc_terminate($server);proc_close($server);}
  }else{
   check_db(($result['result']??'')==='DENIED','publication denied for '.$case);
   check_db((int)$fresh->query('SELECT COUNT(*) FROM o01_receipt_candidate_ledger')->fetchColumn()===$ledgerBefore,'denial rolls back receipt reservation for '.$case);
   check_db(route_count($fresh,$slug)===0&&request_count($fresh,$key)===0,'fresh DB confirms no partial route or receipt for '.$case);
   if($case==='rollback'){
    $retry=publish_case($d,$project,$slug,$html,$claim,false,false,$case);
    check_db(($retry['result']??'')==='COMMITTED_LOCAL_CANDIDATE','actual publisher retry reuses rolled-back receipt IDs');
    check_db(route_count($fresh,$slug)===1&&request_count($fresh,$key)===1&&(int)$fresh->query('SELECT COUNT(*) FROM o01_receipt_candidate_ledger')->fetchColumn()===$ledgerBefore+1,'retry commits ledger route and publisher receipt together');
   }
  }
 }
} finally {
 if($d->inTransaction())$d->rollBack();
 $d->exec('DROP TABLE o01_receipt_candidate_ledger');
 foreach($slugs as $slug){$q=$d->prepare('DELETE FROM o01_binding_o01_paid_routes WHERE slug=?');$q->execute([$slug]);}
 foreach($keys as $key){$q=$d->prepare('DELETE FROM o01_binding_o01_paid_receipts WHERE request_key=?');$q->execute([$key]);}
 foreach($projects as $project){$q=$d->prepare('DELETE FROM pr_o01_approval_claims WHERE project_id=?');$q->execute([$project]);$q=$d->prepare('DELETE FROM pr_o01_revision_authority WHERE project_id=?');$q->execute([$project]);}
}
check_db((int)$fresh->query('SELECT COUNT(*) FROM o01_binding_o01_paid_routes')->fetchColumn()===0&&(int)$fresh->query('SELECT COUNT(*) FROM o01_binding_o01_paid_receipts')->fetchColumn()===0&&(int)$fresh->query('SELECT COUNT(*) FROM pr_o01_revision_authority')->fetchColumn()===0&&(int)$fresh->query('SELECT COUNT(*) FROM pr_o01_approval_claims')->fetchColumn()===0,'fixture cleanup readback all four tables empty');
check_db((int)$fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='o01_receipt_candidate_ledger'")->fetchColumn()===0,'disposable receipt ledger removed');
echo "SKIP actual WordPress authenticated approval in this harness\n";
echo "SKIP genuine provider receipts and production digest/identity mapping; successful callbacks synthetic\n";
echo "PUBLISHER_RECEIPT_CANONICAL_DISPOSABLE_INTEGRATION_COMPLETED\n";
