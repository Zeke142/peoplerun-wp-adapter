<?php
declare(strict_types=1);
/** SOURCE CANDIDATE ONLY. Read-only snapshot, not publication/payment authority.
 * Call only from trusted owner-side server code. $loadBinding must read an
 * independently admitted persisted mapping; never pass request data as a loader.
 * This does not establish shared locking between WordPress and publisher PDO.
 * Production use requires that separate lock/revalidation contract first.
 */
final class O01ServerIdentityContext {
 private static function positiveDecimal(mixed $value):?int {
  if(is_int($value))return $value>0?$value:null;
  if(!is_string($value)||!preg_match('/\A[1-9][0-9]*\z/D',$value))return null;
  $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  return is_int($n)&&(string)$n===$value?$n:null;
 }
 public static function load(int $project,int $revision,int $reservation,int $now,Closure $loadBinding):array {
  foreach(['get_current_user_id','pr_iw_g1_v2_owner','pr_iw_g2_v1_revision','pr_iw_g2_v1_reservation','get_post_meta'] as $fn)
   if(!function_exists($fn))throw new RuntimeException('host_identity_interface_missing');
  $owner=get_current_user_id();
  if(!is_int($owner)||$owner<1||min($project,$revision,$reservation,$now)<1||!pr_iw_g1_v2_owner($project,$owner))throw new RuntimeException('owner_context_denied');
  $source=pr_iw_g2_v1_revision($project,$revision,$owner);
  $reserved=pr_iw_g2_v1_reservation($reservation,$project,$owner);
  if(!is_array($source)||!is_array($reserved))throw new RuntimeException('revision_or_reservation_denied');
  foreach(['project_id'=>$project,'reservation_id'=>$reservation,'owner_id'=>$owner] as $key=>$expected)
   if(($reserved[$key]??null)!==$expected)throw new RuntimeException('reservation_identity_mismatch');
  if(($source['revision_id']??null)!==$revision||self::positiveDecimal($reserved['revision_id']??null)!==$revision||
     ($reserved['state']??null)!=='reserved'||($expiry=self::positiveDecimal($reserved['expires_at']??null))===null||$expiry<=$now)throw new RuntimeException('reservation_inactive_or_stale');
  $bytes=$source['html']??null;$hash=$source['content_sha256']??null;
  if(!is_string($bytes)||$bytes===''||strlen($bytes)>250000||str_contains($bytes,"\0")||
     !is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||
     !hash_equals($hash,hash('sha256',$bytes))||($reserved['revision_sha256']??null)!==$hash)throw new RuntimeException('revision_snapshot_mismatch');
  $slug=$reserved['slug']??null;
  if(!is_string($slug)||strlen($slug)<3||strlen($slug)>63||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug))throw new RuntimeException('reservation_slug_invalid');
  $number=self::positiveDecimal(get_post_meta($revision,'_pr_iw_revision_number',true));
  $current=self::positiveDecimal(get_post_meta($project,'_pr_iw_version',true));
  if($number===null||$number!==$current)throw new RuntimeException('revision_no_longer_current');
  // No numeric cast of opaque approval IDs, no inferred customer identity.
  $binding=$loadBinding($owner,$project,$revision,$reservation);
  if(!is_array($binding)||($binding['schema']??null)!=='o01-server-identity-binding-candidate-v1')throw new RuntimeException('persisted_mapping_missing');
  foreach(['owner_id'=>$owner,'project_id'=>$project,'revision_id'=>$revision,'reservation_id'=>$reservation,'canonical_revision'=>$number] as $key=>$expected)
   if(($binding[$key]??null)!==$expected)throw new RuntimeException('persisted_mapping_identity_mismatch');
  if(($binding['approval_owner_id']??null)!==$owner||($binding['slug']??null)!==$slug||($binding['html_sha256']??null)!==$hash)throw new RuntimeException('persisted_mapping_snapshot_mismatch');
  foreach(['approval_project_id','approval_revision_id','customer_id','binding_record_id'] as $key)
   if(!is_string($binding[$key]??null)||!preg_match('/\A[A-Za-z0-9_.:-]{1,180}\z/D',$binding[$key]))throw new RuntimeException('persisted_mapping_reference_invalid');
  if(($binding['quote_digest_contract']??null)!=='dn29_manifest_candidate_v1'||
     !is_string($binding['manifest_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$binding['manifest_sha256']))throw new RuntimeException('persisted_quote_binding_missing');
  return ['kind'=>'READ_ONLY_IDENTITY_SNAPSHOT_NOT_AUTHORITY','mapping'=>$binding,
   'source_bytes'=>$bytes,'html_sha256'=>$hash,'reservation_expires_at'=>$expiry,
   'context'=>['digest_contract'=>$binding['quote_digest_contract'],'manifest_sha256'=>$binding['manifest_sha256'],'customer_id'=>$binding['customer_id']],
   'shared_publication_lock_proven'=>false];
 }
}
