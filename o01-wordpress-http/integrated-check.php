<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require __DIR__.'/../wordpress/wp-load.php';
require_once __DIR__.'/receipt-source/o01-receipt-admission-dn25.php';
require_once __DIR__.'/o01-approval/recovered/o01_candidate_v11/caller_owned_approval.php';
require_once __DIR__.'/O01PersistedPublisher.php';
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
 foreach(['good','receipt-failure','unconfigured','mapping-revoked','quote-change','revision-change','expiry-at-commit'] as $case){
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
  $receipts=$case==='unconfigured'?new O01ReceiptTransaction():new O01ReceiptTransaction(static fn(...$ignored)=>$syntheticIds);
  $clock=$now;$probe=false;
  $source=static function($snapshot,$at)use($publication,$case,$owner,$pid,$rid,$slug,&$probe,&$clock,$sid):array {
   if($case==='good'&&!$probe){
    $jobs=[];
    foreach(['revision','reservation'] as $kind){
     $args=[PHP_BINARY,'-n','-d','extension_dir='.getenv('O01_EXTENSION_DIR'),'-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=pdo','-d','extension=pdo_mysql',__FILE__,'--probe',$kind,(string)$owner,(string)$pid,(string)$rid,$slug];
     $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('probe_failed');fclose($pipes[0]);$jobs[]=[$kind,$proc,$pipes];
    }
    foreach($jobs as [$kind,$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$response=json_decode($out,true);check($exit===0&&($response['denied']??false)===true&&($response['code']??null)==='lock_unavailable','actual competing G1/G2 '.$kind.' API denied by shared lock');}
    $probe=true;
   }
   if($case==='expiry-at-commit')$clock=(int)get_post_meta($sid,'_pr_iw_g2_expires_at',true);
   return $publication;
  };
  $verifier=static fn(...$ignored)=>$publication;
  $fault=static fn($inner)=>new class($inner){
   public function __construct(private object $s){}
   public function begin($a,$b){return $this->s->begin($a,$b);}public function findRequest($k){return $this->s->findRequest($k);}public function findSlug($s){return $this->s->findSlug($s);}public function put($s,$r){return $this->s->put($s,$r);}public function receipt($k,$r){return false;}public function commit(){return $this->s->commit();}public function rollback(){return $this->s->rollback();}
  };
  if($case==='mapping-revoked')$d->exec("UPDATE o01_mapping_candidate SET state='revoked' WHERE project_id=".$pid);
  if($case==='quote-change'){$bad=$m;$bad['canonical_quote']['price_cents']=7900;$q=$d->prepare('UPDATE o01_mapping_candidate SET binding_json=? WHERE project_id=?');$q->execute([json_encode($bad),$pid]);}
  if($case==='revision-change'){$next=pr_iw_g1_v2_add_revision($pid,$owner,$html.' ',1,'supersede');if(!is_array($next))throw new RuntimeException('superseding_revision_failed');$ids[]=$next['revision_id'];}
  $clockFn=static function()use(&$clock){return $clock;};
  $run=static fn($decorator=null)=>O01PersistedPublisher::publish($d,'wp_','o01_mapping_candidate','o01_integrated_',$pid,$rid,$sid,$slug,1,'integrated-request-'.$case,[],[],[],$receipts,$source,$verifier,$clockFn,$decorator);
  $before=count_rows($fresh,'o01_receipt_candidate_ledger');$result=$run($case==='receipt-failure'?$fault:null);
  check(!$d->inTransaction(),'transaction closed '.$case);
  $q=$fresh->prepare('SELECT record_json FROM o01_integrated_o01_paid_routes WHERE slug=?');$q->execute([$slug]);$record=$q->fetchColumn();
  $q=$fresh->prepare('SELECT COUNT(*) FROM o01_integrated_o01_paid_receipts WHERE request_key=?');$q->execute(['integrated-request-'.$case]);$receiptCount=(int)$q->fetchColumn();
  if($case==='good'){
   if(($result['result']??null)!=='COMMITTED_LOCAL_CANDIDATE')echo json_encode($result)."\n";
   check(($result['result']??null)==='COMMITTED_LOCAL_CANDIDATE','complete atomic publisher commits with persisted identity');
   check($receiptCount===1&&count_rows($fresh,'o01_receipt_candidate_ledger')===$before+1,'receipt reservation and publisher receipt committed together');
   $decoded=json_decode($record,true,512,JSON_THROW_ON_ERROR);check(base64_decode($decoded['parent_html_b64'],true)===$html,'committed parent matches actual G1 exact bytes');
   check($decoded['project_id']===$pid&&$decoded['revision_id']===$rid&&$decoded['reservation_id']===$sid,'route preserves actual G1/G2 identities');
  }else{
   check(($result['result']??null)==='DENIED','complete publisher denies '.$case);
   check($record===false&&$receiptCount===0&&count_rows($fresh,'o01_receipt_candidate_ledger')===$before,'no partial route ledger or receipt '.$case);
   if($case==='receipt-failure'){
    check(($result['reason']??null)==='atomic_commit_failed','failure occurred at publisher route/receipt write boundary');
    $retry=$run();check(($retry['result']??null)==='COMMITTED_LOCAL_CANDIDATE','retry after receipt-write rollback succeeds with same IDs');
    check(count_rows($fresh,'o01_receipt_candidate_ledger')===$before+1,'rolled-back consent event and payment IDs reusable exactly once');
   }
  }
  foreach(['revision:'.$pid,'g2:url:'.$slug] as $scope){$q=$fresh->prepare('SELECT IS_USED_LOCK(?)');$q->execute(['priw:'.substr(hash('sha256',$scope),0,48)]);check($q->fetchColumn()===null,'shared lock released '.$scope);}
 }
 check(!defined('O01_DN21_AUTHORITY_ADMITTED')&&!function_exists('pr_iw_payment_verify_receipt_v1'),'no genuine payment authority or credentials installed');
 echo "SKIP genuine signed consent/provider/payment/publication evidence; successful callbacks explicitly synthetic\n";
 echo "SKIP HTTP customer login, public HTTP serving, session-loss and commit-ack fault tests\n";
}finally{
 if($d->inTransaction())$d->rollBack();foreach($tables as $table)$d->exec('DROP TABLE IF EXISTS `'.$table.'`');
 wp_set_current_user($owner);foreach(array_reverse($ids) as $id)wp_delete_post($id,true);
 require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($owner);
}
foreach($ids as $id)check(get_post($id)===null,'created G1/G2 fixture post removed '.$id);
check(count_rows($fresh,'wp_posts')===$baselinePosts,'WordPress post count restored to pre-fixture baseline');
check((int)$fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'o01_%' OR table_name LIKE 'pr_o01_%')")->fetchColumn()===0,'all candidate mapping ledger publisher approval tables removed');
