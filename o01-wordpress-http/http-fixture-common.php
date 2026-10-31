<?php
declare(strict_types=1);
// LOCAL TEST WIRING ONLY. Never install on public WordPress.
if(getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')throw new RuntimeException('disposable_only');
require_once __DIR__.'/../wordpress/wp-load.php';
require_once __DIR__.'/receipt-source/o01-receipt-admission-dn25.php';
require_once __DIR__.'/o01-approval/wordpress_authenticated_approval_v31.php';
require_once __DIR__.'/O01PersistedPublisher.php';
require_once __DIR__.'/O01PublicationReconciler.php';
require_once __DIR__.'/O01UninstalledWordPressDeliveryEndpoint.php';
function http_db():PDO {
 $d=new PDO('mysql:host=127.0.0.1;port=33309;dbname=o01_integrated_disposable;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
 if($d->query('SELECT DATABASE()')->fetchColumn()!=='o01_integrated_disposable')throw new RuntimeException('wrong_database');return $d;
}
function mapping(PDO $d,string $approvalProject):array {
 $q=$d->prepare('SELECT binding_json FROM o01_mapping_candidate WHERE approval_project_id=?');$q->execute([$approvalProject]);$raw=$q->fetchColumn();if(!is_string($raw))throw new RuntimeException('mapping_missing');return json_decode($raw,true,32,JSON_THROW_ON_ERROR);
}
function synthetic_ids(array $m,int $now):array {return ['manifest_sha256'=>$m['manifest_sha256'],'customer_id'=>$m['customer_id'],'approval_issuer'=>'synthetic-issuer','consent_ref'=>'synthetic-consent-'.$m['binding_record_id'],'payment_provider'=>'stripe_live','event_id'=>'synthetic-event-'.$m['binding_record_id'],'payment_id'=>'synthetic-payment-'.$m['binding_record_id'],'timestamp'=>$now];}
function fixture_publication(PDO $d,array $m):array {
 $q=$d->prepare('SELECT approval_sha256 FROM pr_o01_approval_claims WHERE project_id=? AND revision_id=?');$q->execute([$m['approval_project_id'],$m['approval_revision_id']]);$consent=$q->fetchColumn();if(!is_string($consent))throw new RuntimeException('approval_missing');
 $c=$m['canonical_quote'];$start=$m['publication_started_at'];$end=strtotime($c['expires_at_utc']);
 return ['verified'=>true,'authority_receipt_sha256'=>hash('sha256','SYNTHETIC_AUTHORITY'),'owner_id'=>$m['owner_id'],'project_id'=>$m['project_id'],'revision_id'=>$m['revision_id'],'reservation_id'=>$m['reservation_id'],'slug'=>$m['slug'],'revision_sha256'=>$m['html_sha256'],'html_sha256'=>$m['html_sha256'],'quote_sha256'=>$m['manifest_sha256'],'amount_minor'=>$c['price_cents'],'currency'=>$c['currency'],'term'=>['1_week'=>'week','1_month'=>'month','1_year'=>'year'][$c['term']],'started_at'=>$start,'expires_at'=>$end,'term_seconds'=>$end-$start,'consent_sha256'=>$consent,'payment_sha256'=>hash('sha256','SYNTHETIC_PAYMENT'),'publication_sha256'=>hash('sha256','SYNTHETIC_PUBLICATION')];
}
