<?php
declare(strict_types=1);
/** Disposable integration candidate. No hooks, migrations or production authority.
 * Every context, mapping and callback is server-owned, never a request value.
 * digest_contract is a candidate contract, NOT proof of production adoption.
 * Invoke ONLY from AtomicPaidRoute's authority callback on its own PDO connection.
 */
require_once __DIR__.'/O01ApprovalPublicationBinding.php';
require_once __DIR__.'/O01CanonicalQuoteBinding.php';
require_once __DIR__.'/O01ReceiptTransaction.php';
final class O01PublisherReceiptBinding {
 public static function checkAndReserve(PDO $db,string $project,string $revision,array $mapping,
  array $publication,string $approvedBytes,array $canonical,array $context,
  array $approval,array $payment,array $handoff,int $now,
  O01ReceiptTransaction $receipts,Closure $trustedPublicationVerifier):array {
  if(($context['digest_contract']??null)!=='dn29_manifest_candidate_v1')throw new RuntimeException('digest_contract_unresolved');
  if(!is_string($context['manifest_sha256']??null)||
     ($publication['quote_sha256']??null)!==$context['manifest_sha256'])throw new RuntimeException('quote_manifest_mapping_mismatch');
  if(!is_string($mapping['customer_id']??null)||$mapping['customer_id']===''||
     ($context['customer_id']??null)!==$mapping['customer_id']||
     !is_int($mapping['canonical_revision']??null))throw new RuntimeException('canonical_identity_mapping_missing');
  $bound=O01ApprovalPublicationBinding::lockAndCheck($db,$project,$revision,$mapping,$publication,$approvedBytes);
  if(($bound['ok']??false)!==true)throw new RuntimeException('approval_binding_denied');
  $term=['week'=>'one_week','month'=>'one_month','year'=>'one_year'][$publication['term']??'']??null;
  $quote=O01CanonicalQuoteBinding::compare($canonical,$approvedBytes,$context['manifest_sha256'],[
   'amount_minor'=>$publication['amount_minor']??null,'currency'=>$publication['currency']??null,
   'term'=>$term,'canonical_revision'=>$mapping['canonical_revision'],'customer_id'=>$mapping['customer_id']]);
  if(($quote['ok']??false)!==true)throw new RuntimeException('canonical_quote_denied');
  if(($canonical['slug']??null)!==($publication['slug']??null)||
     strtotime($canonical['expires_at_utc']??'')!==($publication['expires_at']??null))throw new RuntimeException('canonical_route_mismatch');
  // This callback must independently verify publication evidence and exact receipt pins.
  // No hashes/verified flags are minted here. Test injection is visibly synthetic.
  $verified=$trustedPublicationVerifier($publication,$bound,$context,$now);
  if(!is_array($verified)||($verified['verified']??null)!==true)throw new RuntimeException('publication_verification_denied');
  foreach(['owner_id','project_id','revision_id','reservation_id','slug','html_sha256','revision_sha256',
    'quote_sha256','amount_minor','currency','term','started_at','expires_at','term_seconds'] as $key)
   if(!array_key_exists($key,$publication)||!array_key_exists($key,$verified)||$verified[$key]!==$publication[$key])throw new RuntimeException('verified_publication_context_mismatch');
  $receipts->reserve($db,$approval,$payment,$canonical,$handoff,$now,[
   'manifest_sha256'=>$context['manifest_sha256'],'customer_id'=>$mapping['customer_id']]);
  return $verified; // AtomicPaidRoute owns every write, commit and rollback.
 }
}
