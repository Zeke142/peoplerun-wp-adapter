<?php
declare(strict_types=1);
/** Disposable acceptance only: tests transaction races, not a commercial customer or payment. */
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require_once __DIR__.'/recovered/o01_candidate_v11/caller_owned_approval.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function db():PDO {$d=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);return $d;}
function seed(PDO $d,string $project):void {
 $q=$d->prepare('INSERT INTO pr_o01_revision_authority(project_id,revision_id,owner_id,html_sha256,quote_sha256,amount_minor,currency,term,status) VALUES(?,?,?,?,?,?,?,?,?)');
 $q->execute([$project,'rev-1',999, str_repeat('a',64),str_repeat('b',64),4900,'USD','one_month','awaiting_approval']);
}
function approve(PDO $d,string $project):array {
 $writer=new O01CallerOwnedApproval($d);
 return $writer->verifyAndClaim(['project_id'=>$project,'revision_id'=>'rev-1'],static function(array $claim,array $locked,callable $once):array {
  $key=hash('sha256',implode("\0",[$locked['project_id'],$locked['revision_id'],'customer_approval']));
  $v=['identity'=>'wp_user:'.$locked['owner_id'],'revision_id'=>$locked['revision_id'],'html_sha256'=>$locked['html_sha256'],
      'receipt_id'=>'race-'.$locked['project_id'],'amount_minor'=>$locked['amount_minor'],'currency'=>$locked['currency'],'term'=>$locked['term']];
  return $once($key,$v)?['ok'=>true]:['ok'=>false];
 });
}
function inspect(PDO $d,string $project):array {
 $q=$d->prepare('SELECT status FROM pr_o01_revision_authority WHERE project_id=? AND revision_id=?');$q->execute([$project,'rev-1']);$state=$q->fetchColumn();
 $q=$d->prepare('SELECT COUNT(*) FROM pr_o01_approval_claims WHERE project_id=? AND revision_id=?');$q->execute([$project,'rev-1']);return [$state,(int)$q->fetchColumn()];
}
function worker(string $project):array {
 $marker=tempnam(sys_get_temp_dir(),'o01-race-');check($marker!==false,'marker_creation_failed');
 $cmd=[PHP_BINARY,__DIR__.'/withdraw_worker.php',$project,'rev-1',$marker];
 $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['O01_DISPOSABLE_ACCEPTANCE'=>'YES']);
 check(is_resource($proc),'worker_start_failed');fclose($pipes[0]);return [$proc,$pipes,$marker];
}
function awaitAttempt(string $marker):void {
 $until=microtime(true)+5;
 while(microtime(true)<$until){clearstatcache(true,$marker);if(@file_get_contents($marker)==='LOCK_ATTEMPT')return;usleep(20000);}
 throw new RuntimeException('worker_lock_attempt_not_observed');
}
function finish(array $w):array {[$p,$pipes,$marker]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);@unlink($marker);check($code===0,'worker_failed:'.$code.':'.$err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);check(is_array($r),'bad_worker_receipt');return $r;}
$a=db();$b=db();
// Approve wins: connection A locks first. B signals attempt then waits for lock.
seed($a,'race-approve');$a->beginTransaction();
$q=$a->prepare('SELECT status FROM pr_o01_revision_authority WHERE project_id=? AND revision_id=? FOR UPDATE');$q->execute(['race-approve','rev-1']);check($q->fetchColumn()==='awaiting_approval','prelock_state');
$w=worker('race-approve');awaitAttempt($w[2]);
$r=approve($a,'race-approve');check(($r['ok']??false)===true,'approve_stage_failed');$a->commit();
$wr=finish($w);check($wr['withdrawn_rows']===0 && $wr['prior_state']==='approved','approve_wins_withdraw_rejected');
check(inspect($b,'race-approve')===['approved',1],'approve_wins_db_receipt');
// Withdraw wins: other connection commits withdrawal before approval attempts lock.
seed($a,'race-withdraw');$w=worker('race-withdraw');awaitAttempt($w[2]);$wr=finish($w);
check($wr['withdrawn_rows']===1,'withdraw_wins_transition');
$a->beginTransaction();$r=approve($a,'race-withdraw');check(($r['ok']??false)===false,'withdrawn_revision_approved');$a->rollBack();
check(inspect($b,'race-withdraw')===['withdrawn',0],'withdraw_wins_db_receipt');
// Replay on the approved revision must reject without creating a second claim.
$a->beginTransaction();$r=approve($a,'race-approve');check(($r['ok']??false)===false,'replay_allowed');$a->rollBack();
check(inspect($b,'race-approve')===['approved',1],'replay_changed_db_receipt');
echo "DISPOSABLE_TWO_CONNECTION_APPROVE_WITHDRAW_AND_REPLAY_PASS\n";
