<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require __DIR__.'/../wordpress/wp-load.php';
require_once __DIR__.'/receipt-source/o01-receipt-admission-dn25.php';
require_once __DIR__.'/o01-approval/recovered/o01_candidate_v11/caller_owned_approval.php';
require_once __DIR__.'/O01PersistedPublisher.php';
require_once __DIR__.'/O01PublicationReconciler.php';
function dbc():PDO {
 $d=new PDO('mysql:host=127.0.0.1;port=33309;dbname=o01_integrated_disposable;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
 if($d->query('SELECT DATABASE()')->fetchColumn()!=='o01_integrated_disposable')throw new RuntimeException('wrong_database');return $d;
}
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
function count_rows(PDO $d,string $table):int {return (int)$d->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
if(($argv[1]??'')==='--probe'){
 wp_set_current_user((int)$argv[3]);$pid=(int)$argv[4];$rid=(int)$argv[5];$slug=$argv[6];
 $r=$argv[2]==='revision'?pr_iw_g1_v2_add_revision($pid,get_current_user_id(),'<p>competing edit</p>',1,'competing-edit'):
   pr_iw_g2_v1_reserve($pid,$rid,get_current_user_id(),$slug,'competing-reservation',3600);
 echo json_encode(['denied'=>is_wp_error($r),'code'=>is_wp_error($r)?$r->get_error_code():null]);exit;
}
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(!is_blog_installed())wp_install('O01 disposable','localadmin','synthetic@example.invalid',false,'','local-only-admin-password');
$owner=wp_create_user('o01-owner','local-only-owner-password','owner@example.invalid');
if(is_wp_error($owner))throw new RuntimeException('owner_creation_failed');
wp_set_current_user($owner);check(get_current_user_id()===$owner,'actual WordPress CLI owner context (not HTTP login)');
$d=dbc();$fresh=dbc();$baselinePosts=count_rows($fresh,'wp_posts');$ids=[];$tables=['o01_mapping_candidate','o01_receipt_candidate_ledger','o01_integrated_o01_paid_routes','o01_integrated_o01_paid_receipts','pr_o01_approval_claims','pr_o01_revision_authority'];
check(get_bloginfo('version')==='6.8.3','WordPress version 6.8.3 verified');
try{
 $d->exec(file_get_contents(__DIR__.'/o01-approval/recovered/o01_candidate_v11/schema.sql'));
 $d->exec('CREATE TABLE o01_mapping_candidate(owner_id BIGINT,project_id BIGINT,reservation_id BIGINT,mapping_version BIGINT,state VARCHAR(20),binding_json LONGTEXT,PRIMARY KEY(owner_id,project_id,reservation_id)) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_receipt_candidate_ledger(manifest_sha256 CHAR(64) NOT NULL,customer_id VARCHAR(180) NOT NULL,approval_issuer VARCHAR(180) NOT NULL,consent_ref VARCHAR(180) NOT NULL,payment_provider VARCHAR(180) NOT NULL,event_id VARCHAR(180) NOT NULL,payment_id VARCHAR(180) NOT NULL,admitted_at BIGINT NOT NULL,UNIQUE KEY consent_once(approval_issuer,consent_ref),UNIQUE KEY event_once(payment_provider,event_id),UNIQUE KEY payment_once(payment_provider,payment_id)) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_integrated_o01_paid_routes(slug VARCHAR(63) PRIMARY KEY,record_json LONGTEXT NOT NULL) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_integrated_o01_paid_receipts(request_key VARCHAR(120) PRIMARY KEY,receipt_json LONGTEXT NOT NULL) ENGINE=InnoDB');
 foreach(['session-before-write','session-at-commit','lost-commit-ack'] as $case){
  $slug='integrated-'.$case;$now=time();
  $startDate=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'));
  $target=$startDate->setDate((int)$startDate->format('Y'),(int)$startDate->format('m'),1)->modify('+1 month');
  $end=$target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$startDate->format('d'),(int)$target->format('t')))->getTimestamp();
  $canonical=['customer_id'=>'synthetic-customer','offer_id'=>'synthetic-offer','price_cents'=>4900,'currency'=>'USD','page_count'=>1,'term'=>'1_month','privacy'=>'public','slug'=>$slug,'revision'=>1,'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$end),'sanitized_html'=>'<p>Exact café preview</p>','css'=>O01_DN17_CSS,'asset_manifest'=>[]];
  $html=o01_render($canonical);$manifest=o01_manifest($canonical)['manifest_sha256'];$hash=hash('sha256',$html);
  $p=pr_iw_g1_v2_create_project($owner,'Integrated '.$case,'project-'.$case);if(!is_array($p))throw new RuntimeException('project_failed');$pid=$p['project_id'];$ids[]=$pid;
  $r=pr_iw_g1_v2_add_revision($pid,$owner,$html,0,'revision-'.$case);if(!is_array($r))throw new RuntimeException('revision_failed');$rid=$r['revision_id'];$ids[]=$rid;
  $v=pr_iw_g2_v1_reserve($pid,$rid,$owner,$slug,'reserve-'.$case,3600);if(!is_array($v))throw new RuntimeException('reserve_failed');$sid=$v['reservation_id'];$ids[]=$sid;
  check($pid>0&&$rid>0&&$sid>0,'actual G1/G2 generated identities '.$case);
  $approvalProject='opaque-'.$case;$approvalRevision='opaque-revision';
  $m=['schema'=>'o01-server-identity-binding-candidate-v1','owner_id'=>$owner,'approval_owner_id'=>$owner,'project_id'=>$pid,'revision_id'=>$rid,'reservation_id'=>$sid,'canonical_revision'=>1,'slug'=>$slug,'html_sha256'=>$hash,'manifest_sha256'=>$manifest,'quote_digest_contract'=>'dn29_manifest_candidate_v1','binding_record_id'=>'mapping-'.$case,'approval_project_id'=>$approvalProject,'approval_revision_id'=>$approvalRevision,'customer_id'=>'synthetic-customer','canonical_quote'=>$canonical];
  $q=$d->prepare('INSERT INTO o01_mapping_candidate VALUES(?,?,?,1,?,?)');$q->execute([$owner,$pid,$sid,'active',json_encode($m,JSON_THROW_ON_ERROR)]);
  $q=$d->prepare('INSERT INTO pr_o01_revision_authority VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$approvalProject,$approvalRevision,$owner,$hash,$manifest,4900,'USD','one_month','awaiting_approval']);
  $d->beginTransaction();$claim=(new O01CallerOwnedApproval($d))->verifyAndClaim(['project_id'=>$approvalProject,'revision_id'=>$approvalRevision],static function($c,$locked,$once):array {
   // Synthetic consent callback ONLY. No HTTP customer login or signed consent claimed.
   $verified=['identity'=>'wp_user:'.$locked['owner_id'],'revision_id'=>$locked['revision_id'],'html_sha256'=>$locked['html_sha256'],'receipt_id'=>bin2hex(random_bytes(32)),'amount_minor'=>$locked['amount_minor'],'currency'=>$locked['currency'],'term'=>$locked['term']];
   return ['ok'=>$once(hash('sha256',implode("\0",[$locked['project_id'],$locked['revision_id'],'customer_approval'])),$verified)];
  });if(($claim['ok']??false)!==true)throw new RuntimeException('synthetic_approval_seed_failed');$d->commit();
  $q=$d->prepare('SELECT approval_sha256 FROM pr_o01_approval_claims WHERE project_id=?');$q->execute([$approvalProject]);$consent=$q->fetchColumn();
  $publication=['verified'=>true,'authority_receipt_sha256'=>hash('sha256','SYNTHETIC_AUTHORITY'),'owner_id'=>$owner,'project_id'=>$pid,'revision_id'=>$rid,'reservation_id'=>$sid,'slug'=>$slug,'revision_sha256'=>$hash,'html_sha256'=>$hash,'quote_sha256'=>$manifest,'amount_minor'=>4900,'currency'=>'USD','term'=>'month','started_at'=>$now,'expires_at'=>$end,'term_seconds'=>$end-$now,'consent_sha256'=>$consent,'payment_sha256'=>hash('sha256','SYNTHETIC_PAYMENT'),'publication_sha256'=>hash('sha256','SYNTHETIC_PUBLICATION')];
  $syntheticIds=['manifest_sha256'=>$manifest,'customer_id'=>'synthetic-customer','approval_issuer'=>'synthetic-issuer','consent_ref'=>'synthetic-consent-'.$case,'payment_provider'=>'stripe_live','event_id'=>'synthetic-event-'.$case,'payment_id'=>'synthetic-payment-'.$case,'timestamp'=>$now];
  $sourceCalls=0;$verifyCalls=0;$once=false;$innerCaptured=null;$faultReached=false;
  $receipts=new O01ReceiptTransaction(static function(...$ignored)use($syntheticIds,&$verifyCalls){$verifyCalls++;return $syntheticIds;});
  $session=(int)$d->query('SELECT CONNECTION_ID()')->fetchColumn();$killer=dbc();
  $source=static function($snapshot,$at)use($publication,$case,$killer,$session,&$sourceCalls,&$once):array {
   $sourceCalls++;
   if($case==='session-before-write'&&!$once){$once=true;$killer->exec('KILL CONNECTION '.$session);}
   return $publication;
  };
  $verifier=static fn(...$ignored)=>$publication;$clockFn=static fn()=>$now;
  $fault=static function($inner)use($case,$killer,$session,&$innerCaptured,&$faultReached){
   $innerCaptured=$inner;
   return new class($inner,$case,$killer,$session,$faultReached){
    public function __construct(private object $s,private string $case,private PDO $killer,private int $id,private bool &$reached){}
    public function begin($a,$b){return $this->s->begin($a,$b);}public function findRequest($k){return $this->s->findRequest($k);}public function findSlug($slug){return $this->s->findSlug($slug);}public function put($slug,$r){return $this->s->put($slug,$r);}public function receipt($k,$r){return $this->s->receipt($k,$r);}
    public function commit(){
     $this->reached=true;
     if($this->case==='lost-commit-ack'){
      if($this->s->commit()!==true)throw new RuntimeException('real_commit_failed');
      $this->killer->exec('KILL CONNECTION '.$this->id);
      // Deliberately discard successful application-level COMMIT acknowledgement.
      throw new RuntimeException('INJECTED_LOST_ACK_AFTER_REAL_COMMIT');
     }
     $this->killer->exec('KILL CONNECTION '.$this->id);
     return $this->s->commit();
    }
    public function rollback(){return $this->s->rollback();}
   };
  };
  $run=static fn(PDO $connection,$decorator=null)=>O01PersistedPublisher::publish($connection,'wp_','o01_mapping_candidate','o01_integrated_',$pid,$rid,$sid,$slug,1,'integrated-request-'.$case,[],[],[],$receipts,$source,$verifier,$clockFn,$decorator);
  $before=count_rows($fresh,'o01_receipt_candidate_ledger');$result=$run($d,$case==='session-before-write'?static function($inner)use(&$innerCaptured){$innerCaptured=$inner;return $inner;}:$fault);
  check($sourceCalls===1,'fault attempt entered actual integrated authority '.$case);
  check((int)$fresh->query('SELECT COUNT(*) FROM information_schema.processlist WHERE ID='.$session)->fetchColumn()===0,'lock-owning session really terminated '.$case);
  $reuseDenied=false;
  try{$reuseDenied=$innerCaptured->begin($slug,'discarded-connection-test')===false;}
  catch(PDOException $e){$reuseDenied=($e->errorInfo[1]??null)===2006;}
  check($reuseDenied,'terminated connection cannot start another publication '.$case);
  $d=null;$d=dbc(); // Discard failed PDO; all subsequent checks use fresh sessions.
  $q=$fresh->prepare('SELECT record_json FROM o01_integrated_o01_paid_routes WHERE slug=?');$q->execute([$slug]);$raw=$q->fetchColumn();
  if($case==='session-before-write'){
   check(($result['result']??null)==='DENIED','session loss before writes fails closed');
   check($verifyCalls===0,'no receipt verifier reached after pre-write session loss');
  }else{
   check($faultReached&&$verifyCalls===1,'fault reached COMMIT after receipt reservation '.$case);
   check(($result['result']??null)==='COMMIT_OUTCOME_UNKNOWN_LOCAL_CANDIDATE'&&($result['blind_retry_allowed']??true)===false,'uncertain commit explicitly blocks blind retry '.$case);
  }
  $expected=$syntheticIds;$expected['slug']=$slug;$expected['request_key']='integrated-request-'.$case;$expected['record']=$publication;
  $reconcile=static fn($e,$at=null)=>O01PublicationReconciler::inspect(dbc(),'o01_integrated_',$e,$at??$now);
  $busyLock='priw:'.substr(hash('sha256','revision:'.$pid),0,48);$blocker=dbc();
  $q=$blocker->prepare('SELECT GET_LOCK(?,0)');$q->execute([$busyLock]);if((string)$q->fetchColumn()!=='1')throw new RuntimeException('busy_recovery_fixture_failed');
  try{$busyResult=$reconcile($expected);check(($busyResult['result']??null)==='UNRESOLVED_LOCAL_CANDIDATE'&&($busyResult['retry_eligible']??true)===false&&($busyResult['reason']??null)==='publication_locks_still_owned','reconciliation blocks while another publisher still owns lock '.$case);}
  finally{$q=$blocker->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$busyLock]);}
  $resolved=$reconcile($expected);
  $ledgerCount=count_rows($fresh,'o01_receipt_candidate_ledger');
  if($case==='lost-commit-ack'){
   check(is_string($raw)&&$ledgerCount===$before+1,'real COMMIT persisted despite lost acknowledgement');
   check(($resolved['result']??null)==='COMMITTED_LOCAL_CANDIDATE'&&($resolved['retry_eligible']??true)===false,'fresh connection reconciles committed tuple and forbids retry');
   $beforeCalls=[$sourceCalls,$verifyCalls];$replay=$run($d);
   check(($replay['result']??null)==='REPLAY'&&[$sourceCalls,$verifyCalls]===$beforeCalls,'same request replays original receipt without authority or receipt reconsumption');
   check(count_rows($fresh,'o01_receipt_candidate_ledger')===$ledgerCount,'lost-ack replay consumes no duplicate consent event or payment');
   $q=$fresh->prepare('SELECT COUNT(*) FROM o01_integrated_o01_paid_receipts WHERE request_key=?');$q->execute([$expected['request_key']]);check((int)$q->fetchColumn()===1,'one original publisher receipt after lost-ack recovery');
   $expired=$reconcile($expected,$end);
   check(($expired['result']??null)==='COMMITTED_LOCAL_CANDIDATE'&&($expired['delivery_state']??null)==='EXPIRED'&&($expired['retry_eligible']??true)===false,'expired committed result remains consumed and cannot be republished as retry');
   foreach(['owner','request','event'] as $mismatch){
    $bad=$expected;
    if($mismatch==='owner')$bad['record']['owner_id']++;
    if($mismatch==='request')$bad['request_key']='different-original-request';
    if($mismatch==='event')$bad['event_id']='different-event';
    $badResult=$reconcile($bad);check(($badResult['result']??null)==='UNRESOLVED_LOCAL_CANDIDATE'&&($badResult['retry_eligible']??true)===false,'misbound recovery context denied '.$mismatch);
   }
   $q=$d->prepare('UPDATE o01_integrated_o01_paid_routes SET record_json=? WHERE slug=?');$bad=json_decode($raw,true);$bad['parent_html_b64']=base64_encode($html.'tampered');$q->execute([json_encode($bad),$slug]);
   try{$badResult=$reconcile($expected);check(($badResult['result']??null)==='UNRESOLVED_LOCAL_CANDIDATE'&&($badResult['retry_eligible']??true)===false,'persisted route corruption cannot be reconciled or retried');}finally{$q->execute([$raw,$slug]);}
   check(($reconcile($expected)['result']??null)==='COMMITTED_LOCAL_CANDIDATE','read-only reconciliation succeeds after fixture restoration');
  }else{
   check($raw===false&&$ledgerCount===$before,'session loss rolls back route and receipt reservation '.$case);
   check(($resolved['result']??null)==='ABSENT_LOCAL_CANDIDATE'&&($resolved['retry_eligible']??false)===true&&($resolved['automatic_retry']??true)===false,'fresh connection proves absent tuple before deliberate retry '.$case);
   $retry=$run($d);check(($retry['result']??null)==='COMMITTED_LOCAL_CANDIDATE','same original identifiers succeed after proven rollback '.$case);
   check(count_rows($fresh,'o01_receipt_candidate_ledger')===$before+1,'exactly one receipt consumption after recovery '.$case);
   $calls=[$sourceCalls,$verifyCalls];$replay=$run($d);check(($replay['result']??null)==='REPLAY'&&[$sourceCalls,$verifyCalls]===$calls,'repeated retry uses original receipt without duplicate verification '.$case);
   check(count_rows($fresh,'o01_receipt_candidate_ledger')===$before+1,'repeat retry leaves receipt ledger unchanged '.$case);
  }
  foreach(['revision:'.$pid,'g2:url:'.$slug] as $scope){$q=$fresh->prepare('SELECT IS_USED_LOCK(?)');$q->execute(['priw:'.substr(hash('sha256',$scope),0,48)]);check($q->fetchColumn()===null,'shared lock released after session recovery '.$scope);}
 }
 check(!defined('O01_DN21_AUTHORITY_ADMITTED')&&!function_exists('pr_iw_payment_verify_receipt_v1'),'no genuine authority or credentials introduced');
 echo "SKIP genuine signed consent, provider payments and production admission; fixture callbacks remain synthetic\n";
 echo "SKIP packet-level TCP COMMIT acknowledgement drop, HTTP customer login and public delivery\n";
}finally{
 $d=dbc();foreach($tables as $table)$d->exec('DROP TABLE IF EXISTS `'.$table.'`');
 wp_set_current_user($owner);foreach(array_reverse($ids) as $id)wp_delete_post($id,true);
 require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($owner);
}
foreach($ids as $id)check(get_post($id)===null,'created fixture post removed '.$id);
check(count_rows($fresh,'wp_posts')===$baselinePosts,'WordPress baseline restored after recovery fixtures');
check((int)$fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'o01_%' OR table_name LIKE 'pr_o01_%')")->fetchColumn()===0,'all recovery fixture tables removed');
