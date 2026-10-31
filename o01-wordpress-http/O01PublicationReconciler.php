<?php
declare(strict_types=1);
/** Read-only candidate. Pass a NEW server-owned PDO after discarding the failed
 * connection, plus trusted pinned expected context. No public endpoint, writes,
 * receipt issuance, payment authority or automatic publication retry.
 */
final class O01PublicationReconciler {
 public static function inspect(PDO $fresh,string $routePrefix,array $expected,int $now):array {
  if($fresh->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$fresh->inTransaction()||
   !preg_match('/\A[A-Za-z0-9_]{1,48}\z/D',$routePrefix))throw new InvalidArgumentException('fresh_mysql_connection_required');
  foreach(['slug','request_key','manifest_sha256','customer_id','approval_issuer','consent_ref','payment_provider','event_id','payment_id'] as $key)
   if(!is_string($expected[$key]??null)||$expected[$key]===''||strlen($expected[$key])>180)throw new InvalidArgumentException('trusted_recovery_context_missing');
  $recordExpected=$expected['record']??null;
  if(!is_array($recordExpected)||($recordExpected['slug']??null)!==$expected['slug']||$now<1)throw new InvalidArgumentException('trusted_record_context_missing');
  foreach(['owner_id','project_id','revision_id','reservation_id','started_at','expires_at','term_seconds'] as $key)
   if(!is_int($recordExpected[$key]??null)||$recordExpected[$key]<1)throw new InvalidArgumentException('trusted_record_identity_missing');
  foreach(['html_sha256','revision_sha256','consent_sha256','payment_sha256','publication_sha256'] as $key)
   if(!is_string($recordExpected[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$recordExpected[$key]))throw new InvalidArgumentException('trusted_record_digest_missing');
  if(!in_array($recordExpected['term']??null,['week','month','year'],true))throw new InvalidArgumentException('trusted_record_term_missing');
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$expected['manifest_sha256']))throw new InvalidArgumentException('trusted_manifest_invalid');
  // An absent snapshot while a publisher still owns locks does not prove rollback.
  foreach(['priw:'.substr(hash('sha256','revision:'.$recordExpected['project_id']),0,48),
   'priw:'.substr(hash('sha256','g2:url:'.$expected['slug']),0,48),
   'o01:'.substr(hash('sha256',$expected['slug']),0,48)] as $lock){
   $q=$fresh->prepare('SELECT IS_USED_LOCK(?)');$q->execute([$lock]);
   if($q->fetchColumn()!==null)return ['result'=>'UNRESOLVED_LOCAL_CANDIDATE','retry_eligible'=>false,
    'automatic_retry'=>false,'reason'=>'publication_locks_still_owned'];
  }
  $fresh->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $fresh->exec('SET TRANSACTION READ ONLY');$fresh->beginTransaction();
  try {
   $q=$fresh->prepare('SELECT receipt_json FROM `'.$routePrefix.'o01_paid_receipts` WHERE request_key=?');$q->execute([$expected['request_key']]);$receiptRaw=$q->fetchColumn();
   $q=$fresh->prepare('SELECT record_json FROM `'.$routePrefix.'o01_paid_routes` WHERE slug=?');$q->execute([$expected['slug']]);$routeRaw=$q->fetchColumn();
   // Check collisions across ALL three once-only identifiers, not just one row.
   $q=$fresh->prepare('SELECT manifest_sha256,customer_id,approval_issuer,consent_ref,payment_provider,event_id,payment_id FROM o01_receipt_candidate_ledger WHERE (approval_issuer=? AND consent_ref=?) OR (payment_provider=? AND (event_id=? OR payment_id=?))');
   $q->execute([$expected['approval_issuer'],$expected['consent_ref'],$expected['payment_provider'],$expected['event_id'],$expected['payment_id']]);$ledger=$q->fetchAll(PDO::FETCH_ASSOC);
   if($receiptRaw===false&&$routeRaw===false&&count($ledger)===0){$fresh->commit();return ['result'=>'ABSENT_LOCAL_CANDIDATE','retry_eligible'=>true,'automatic_retry'=>false,'retry_constraint'=>'same_original_request_and_identifiers_through_locked_publisher_only'];}
   if(!is_string($receiptRaw)||!is_string($routeRaw)||count($ledger)!==1)throw new RuntimeException('partial_or_conflicting_state');
   $receipt=json_decode($receiptRaw,true,32,JSON_THROW_ON_ERROR);$record=json_decode($routeRaw,true,32,JSON_THROW_ON_ERROR);
   if(!is_array($receipt)||!is_array($record))throw new RuntimeException('invalid_persisted_json');
   $intent=hash('sha256',json_encode(['slug'=>$expected['slug'],'request_key'=>$expected['request_key']],JSON_THROW_ON_ERROR));
   foreach(['request_key'=>$expected['request_key'],'slug'=>$expected['slug'],'op'=>'publish','intent_hash'=>$intent,'record_sha256'=>hash('sha256',$routeRaw)] as $key=>$value)
    if(($receipt[$key]??null)!==$value)throw new RuntimeException('receipt_binding_mismatch');
   if(($record['state']??null)!=='published')throw new RuntimeException('route_state_changed');
   foreach(['owner_id','project_id','revision_id','reservation_id','slug','html_sha256','revision_sha256','started_at','expires_at','term_seconds','term','consent_sha256','payment_sha256','publication_sha256'] as $key)
    if(($record[$key]??null)!==($recordExpected[$key]??null))throw new RuntimeException('record_context_mismatch');
   $html=base64_decode($record['parent_html_b64']??'',true);
   if(!is_string($html)||$html===''||!hash_equals($record['html_sha256'],hash('sha256',$html)))throw new RuntimeException('stored_bytes_mismatch');
   foreach(['manifest_sha256','customer_id','approval_issuer','consent_ref','payment_provider','event_id','payment_id'] as $key)
    if(($ledger[0][$key]??null)!==$expected[$key])throw new RuntimeException('ledger_binding_mismatch');
   $fresh->commit();return ['result'=>'COMMITTED_LOCAL_CANDIDATE','retry_eligible'=>false,'automatic_retry'=>false,
    'delivery_state'=>$now<$record['expires_at']?'WITHIN_STORED_TERM':'EXPIRED','receipt'=>$receipt];
  }catch(Throwable $e){
   if($fresh->inTransaction())$fresh->rollBack();
   return ['result'=>'UNRESOLVED_LOCAL_CANDIDATE','retry_eligible'=>false,'automatic_retry'=>false,'reason'=>$e instanceof RuntimeException?$e->getMessage():'read_or_integrity_failure'];
  }
 }
}
