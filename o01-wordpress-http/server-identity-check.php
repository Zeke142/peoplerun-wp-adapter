<?php
declare(strict_types=1);
/** Disposable actual WordPress/G1/G2 API checks. No commercial receipt authority. */
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require __DIR__.'/wordpress/wp-load.php';
require __DIR__.'/O01ServerIdentityContext.php';
function identity_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function identity_denied(Closure $run,string $code,string $label):void {
 try{$run();}catch(RuntimeException $e){identity_check($e->getMessage()===$code,$label);return;}
 throw new RuntimeException('FAIL '.$label.': unexpectedly accepted');
}
$owner=(int)getenv('O01_TEST_OWNER_ID');$now=time();
if($owner<1)throw new RuntimeException('test_owner_missing');
wp_set_current_user($owner);
$postIds=[];
try {
 $project=pr_iw_g1_v2_create_project($owner,'Identity fixture','identity-project');
 if(!is_array($project))throw new RuntimeException('project_fixture_failed');$pid=$project['project_id'];$postIds[]=$pid;
 $html="<!doctype html>\r\n<main>Identity café</main>  \r\n";
 $rev=pr_iw_g1_v2_add_revision($pid,$owner,$html,0,'identity-revision');
 if(!is_array($rev))throw new RuntimeException('revision_fixture_failed');$rid=$rev['revision_id'];$postIds[]=$rid;
 $reservation=pr_iw_g2_v1_reserve($pid,$rid,$owner,'identity-fixture','identity-reservation',3600);
 if(!is_array($reservation))throw new RuntimeException('reservation_fixture_failed');$sid=$reservation['reservation_id'];$postIds[]=$sid;
 identity_check($pid>0&&$rid>0&&$sid>0,'actual WordPress G1 project revision and G2 reservation created in disposable database');
 $binding=['schema'=>'o01-server-identity-binding-candidate-v1','owner_id'=>$owner,'project_id'=>$pid,'revision_id'=>$rid,'reservation_id'=>$sid,'canonical_revision'=>1,
  'approval_owner_id'=>$owner,'approval_project_id'=>'opaque-project-alpha','approval_revision_id'=>'opaque-revision-alpha','customer_id'=>'fixture-customer',
  'binding_record_id'=>'fixture-mapping-1','slug'=>'identity-fixture','html_sha256'=>hash('sha256',$html),'manifest_sha256'=>hash('sha256','FIXTURE_MANIFEST_ONLY'),
  'quote_digest_contract'=>'dn29_manifest_candidate_v1'];
 $load=static fn(...$ignored)=>$binding;
 $run=static fn(?Closure $loader=null,int $at=0)=>O01ServerIdentityContext::load($pid,$rid,$sid,$at?:$now,$loader??$load);
 $before=$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}");
 $snapshot=$run();
 identity_check($snapshot['source_bytes']===$html&&$snapshot['html_sha256']===hash('sha256',$html),'loader preserves actual G1 exact bytes and digest');
 identity_check($snapshot['mapping']['project_id']===$pid&&$snapshot['mapping']['revision_id']===$rid&&$snapshot['mapping']['reservation_id']===$sid,'mapping matches actual generated server identities');
 identity_check($snapshot['kind']==='READ_ONLY_IDENTITY_SNAPSHOT_NOT_AUTHORITY'&&$snapshot['shared_publication_lock_proven']===false,'read-only snapshot explicitly denies authority and shared-lock claim');
 identity_check($GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}")===$before,'mapping load creates no WordPress posts');
 identity_denied(static fn()=>$run(static fn(...$ignored)=>null),'persisted_mapping_missing','missing trusted persisted mapping denies');
 foreach(['owner_id','project_id','revision_id','reservation_id','canonical_revision'] as $key){
  $bad=$binding;$bad[$key]++;
  identity_denied(static fn()=>$run(static fn(...$ignored)=>$bad),'persisted_mapping_identity_mismatch','changed mapped '.$key.' denies');
 }
 foreach(['approval_owner_id'=>$owner+1,'slug'=>'different-slug','html_sha256'=>str_repeat('f',64)] as $key=>$value){
  $bad=$binding;$bad[$key]=$value;
  identity_denied(static fn()=>$run(static fn(...$ignored)=>$bad),'persisted_mapping_snapshot_mismatch','changed mapped '.$key.' denies');
 }
 foreach(['approval_project_id','approval_revision_id','customer_id','binding_record_id'] as $key){
  $bad=$binding;$bad[$key]='';
  identity_denied(static fn()=>$run(static fn(...$ignored)=>$bad),'persisted_mapping_reference_invalid','missing mapped '.$key.' denies');
 }
 $bad=$binding;$bad['quote_digest_contract']='unresolved';
 identity_denied(static fn()=>$run(static fn(...$ignored)=>$bad),'persisted_quote_binding_missing','unknown quote digest contract denies');
 $bad=$binding;$bad['manifest_sha256']='invalid';
 identity_denied(static fn()=>$run(static fn(...$ignored)=>$bad),'persisted_quote_binding_missing','invalid manifest digest denies');
 wp_set_current_user(0);
 identity_denied($run,'owner_context_denied','anonymous owner context denies');
 wp_set_current_user($owner);
 $expiry=(int)get_post_meta($sid,'_pr_iw_g2_expires_at',true);
 identity_denied(static fn()=>$run(null,$expiry),'reservation_inactive_or_stale','exact reservation expiry denies');
 foreach(['revision_id'=>'01','expires_at'=>'1e10','state'=>'withdrawn'] as $key=>$value){
  $meta='_pr_iw_g2_'.$key;$saved=get_post_meta($sid,$meta,true);update_post_meta($sid,$meta,$value);
  try{identity_denied($run,'reservation_inactive_or_stale','malformed or inactive reservation '.$key.' denies');}
  finally{update_post_meta($sid,$meta,$saved);}
 }
 $meta='_pr_iw_g2_revision_sha256';$saved=get_post_meta($sid,$meta,true);update_post_meta($sid,$meta,str_repeat('f',64));
 try{identity_denied($run,'revision_snapshot_mismatch','reservation revision digest mismatch denies');}finally{update_post_meta($sid,$meta,$saved);}
 $meta='_pr_iw_html_b64';$saved=get_post_meta($rid,$meta,true);update_post_meta($rid,$meta,base64_encode($html.' '));
 try{identity_denied($run,'revision_or_reservation_denied','actual G1 tampered bytes denied before mapping');}finally{update_post_meta($rid,$meta,$saved);}
 update_post_meta($pid,'_pr_iw_version','2');
 try{identity_denied($run,'revision_or_reservation_denied','actual G2 rejects superseded G1 revision');}finally{update_post_meta($pid,'_pr_iw_version','1');}
 identity_check(!function_exists('pr_iw_payment_verify_receipt_v1'),'no payment verifier or payment authority created by identity adapter');
}finally{
 wp_set_current_user($owner);
 foreach(array_reverse($postIds) as $id)wp_delete_post($id,true);
}
foreach($postIds as $id)identity_check(get_post($id)===null,'disposable WordPress fixture post '.$id.' removed');
echo "SKIP production mapping persistence and shared WordPress/PDO publication lock\n";
echo "SKIP independently admitted issuer and genuine provider receipt success\n";
echo "ACTUAL_WORDPRESS_G1_G2_IDENTITY_LOADER_COMPLETED_ONLY\n";
