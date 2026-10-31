<?php
declare(strict_types=1);
/** Isolated composition candidate, no route/hook/install or receipt authority.
 * PDO, prefix, mapping table and verifiers are server-owned dependencies.
 * Fixture injection does not establish production consent/payment authority.
 */
require_once __DIR__.'/O01PersistedIdentity.php';
require_once __DIR__.'/O01IdentityLockedStore.php';
require_once __DIR__.'/WPMySQLPaidRouteStore.php';
require_once __DIR__.'/O01PublisherReceiptBinding.php';
require_once __DIR__.'/AtomicPaidRoute.php';
final class O01PersistedPublisher {
 public static function publish(PDO $db,string $wpPrefix,string $mappingTable,string $routePrefix,
  int $project,int $revision,int $reservation,string $slug,int $mappingVersion,string $requestKey,
  array $approval,array $payment,array $handoff,O01ReceiptTransaction $receipts,
  Closure $publicationSource,Closure $publicationVerifier,Closure $clock,?Closure $testStoreDecorator=null):array {
  $owner=function_exists('get_current_user_id')?get_current_user_id():0;
  if(!is_int($owner)||$owner<1)throw new RuntimeException('authenticated_owner_required');
  $loader=new O01PersistedIdentity($db,$wpPrefix,$mappingTable);
  $reload=static function(int $now)use($loader,$owner,$project,$revision,$reservation,$slug,$mappingVersion):array {
   if(get_current_user_id()!==$owner)throw new RuntimeException('owner_context_changed');
   return $loader->load($owner,$project,$revision,$reservation,$slug,$mappingVersion,$now);
  };
  $inner=new O01WPMySQLPaidRouteStore($db,$routePrefix);
  // Test-only fault seam; never bind it from a public request.
  if($testStoreDecorator!==null)$inner=$testStoreDecorator($inner);
  $store=new O01IdentityLockedStore($db,$inner,$project,$slug,$reload,$clock);
  $authority=static function($op,$command,$current,$now)use($db,$store,$approval,$payment,$handoff,$receipts,$publicationSource,$publicationVerifier):array {
   $s=$store->snapshot();$m=$s['mapping'];
   $canonical=$m['canonical_quote']??null;
   if(!is_array($canonical))throw new RuntimeException('persisted_canonical_quote_missing');
   $publication=$publicationSource($s,$now);
   if(!is_array($publication))throw new RuntimeException('publication_source_missing');
   return O01PublisherReceiptBinding::checkAndReserve($db,$m['approval_project_id'],$m['approval_revision_id'],
    $m,$publication,$s['source_bytes'],$canonical,$s['context'],$approval,$payment,$handoff,$now,$receipts,$publicationVerifier);
  };
  $parent=static function($record,$verified)use($store):array {
   $s=$store->snapshot();$m=$s['mapping'];
   return ['html'=>$s['source_bytes'],'html_sha256'=>$s['html_sha256'],'owner_id'=>$m['owner_id'],
    'project_id'=>$m['project_id'],'revision_id'=>$m['revision_id']];
  };
  $result=O01AtomicPaidRoute::transition($store,'publish',['slug'=>$slug,'request_key'=>$requestKey],$authority,$clock(),null,$parent);
  if($store->commitOutcomeUnknown())return ['result'=>'COMMIT_OUTCOME_UNKNOWN_LOCAL_CANDIDATE',
   'reason'=>'fresh_connection_reconciliation_required','slug'=>$slug,'request_key'=>$requestKey,
   'publication_status'=>'UNVERIFIED','blind_retry_allowed'=>false];
  return $result;
 }
}
