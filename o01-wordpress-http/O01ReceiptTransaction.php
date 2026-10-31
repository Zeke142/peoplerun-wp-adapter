<?php
declare(strict_types=1);
/** Candidate only. Caller owns transaction and rollback on every failure.
 * Verifier is trusted server configuration, never request-controlled. Default
 * uses recovered authentic verifiers; injected test callbacks grant no authority.
 */
final class O01ReceiptTransaction {
 private Closure $verify;
 public function __construct(?Closure $trustedVerifier=null) {
  $this->verify=$trustedVerifier??static fn($a,$p,$c,$h,$n)=>o01_dn25_identifiers($a,$p,$c,$h,$n);
 }
 public function reserve(PDO $db,array $approval,array $payment,array $candidate,array $handoff,int $now,array $locked):array {
  if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||!$db->inTransaction())throw new RuntimeException('caller_transaction_required');
  $ids=($this->verify)($approval,$payment,$candidate,$handoff,$now);
  if(!is_array($ids))throw new RuntimeException('receipt_verification_denied');
  foreach(['manifest_sha256','customer_id'] as $key)
   if(!is_string($locked[$key]??null)||!is_string($ids[$key]??null)||!hash_equals($locked[$key],$ids[$key]))throw new RuntimeException('locked_receipt_context_mismatch');
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$ids['manifest_sha256']))throw new RuntimeException('manifest_invalid');
  foreach(['customer_id','approval_issuer','consent_ref','payment_provider','event_id','payment_id'] as $key)
   if(!is_string($ids[$key]??null)||!preg_match('/\A[A-Za-z0-9_:.\-]{1,180}\z/D',$ids[$key]))throw new RuntimeException('receipt_identifier_invalid');
  if($ids['payment_provider']!=='stripe_live'||($ids['timestamp']??null)!==$now)throw new RuntimeException('receipt_context_invalid');
  $q=$db->prepare('INSERT INTO o01_receipt_candidate_ledger(manifest_sha256,customer_id,approval_issuer,consent_ref,payment_provider,event_id,payment_id,admitted_at) VALUES(?,?,?,?,?,?,?,?)');
  $q->execute([$ids['manifest_sha256'],$ids['customer_id'],$ids['approval_issuer'],$ids['consent_ref'],$ids['payment_provider'],$ids['event_id'],$ids['payment_id'],$now]);
  if($q->rowCount()!==1)throw new RuntimeException('receipt_ledger_write_denied');
  return $ids; // reservation only; caller must write publication and commit once.
 }
}
