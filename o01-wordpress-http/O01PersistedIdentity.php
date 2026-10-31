<?php
declare(strict_types=1);
/** ISOLATED CANDIDATE. No hooks, migration, credentials or receipt authority.
 * Must use the publisher PDO on the SAME MySQL server as WordPress.
 * Caller supplies authenticated principal, never request-derived owner IDs.
 */
final class O01PersistedIdentity {
 public function __construct(private PDO $db, private string $wpPrefix, private string $mappingTable) {
  foreach([$wpPrefix,$mappingTable] as $name)
   if(!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name))throw new InvalidArgumentException('invalid_table');
 }
 private function meta(int $id,string $key):string {
  $q=$this->db->prepare('SELECT meta_value FROM `'.$this->wpPrefix.'postmeta` WHERE post_id=? AND meta_key=? FOR UPDATE');
  $q->execute([$id,$key]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
  if(count($rows)!==1||!is_string($rows[0]))throw new RuntimeException('metadata_missing_or_duplicate');
  return $rows[0];
 }
 private function post(int $id,string $type,int $owner,?int $parent=null):void {
  $q=$this->db->prepare('SELECT post_type,post_status,post_author,post_parent FROM `'.$this->wpPrefix.'posts` WHERE ID=? FOR UPDATE');
  $q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
  if(!$p||$p['post_type']!==$type||$p['post_status']!=='private'||
    (string)$p['post_author']!==(string)$owner||($parent!==null&&(string)$p['post_parent']!==(string)$parent))throw new RuntimeException('identity_denied');
 }
 private static function number(string $value):int {
  $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!is_int($n)||(string)$n!==$value)throw new RuntimeException('noncanonical_number');
  return $n;
 }
 public function load(int $owner,int $project,int $revision,int $reservation,string $slug,int $version,int $now):array {
  if(!$this->db->inTransaction()||min($owner,$project,$revision,$reservation,$version,$now)<1)throw new RuntimeException('transaction_or_identity_missing');
  $q=$this->db->prepare('SELECT binding_json,mapping_version,state FROM `'.$this->mappingTable.'` WHERE owner_id=? AND project_id=? AND reservation_id=? FOR UPDATE');
  $q->execute([$owner,$project,$reservation]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  if(count($rows)!==1||$rows[0]['state']!=='active'||self::number((string)$rows[0]['mapping_version'])!==$version)throw new RuntimeException('mapping_missing_stale_or_revoked');
  $m=json_decode($rows[0]['binding_json'],true,32,JSON_THROW_ON_ERROR);
  if(!is_array($m)||($m['schema']??null)!=='o01-server-identity-binding-candidate-v1')throw new RuntimeException('mapping_schema_invalid');
  foreach(['owner_id'=>$owner,'approval_owner_id'=>$owner,'project_id'=>$project,'revision_id'=>$revision,'reservation_id'=>$reservation,'slug'=>$slug] as $key=>$expected)
   if(($m[$key]??null)!==$expected)throw new RuntimeException('mapping_identity_mismatch');
  foreach(['binding_record_id','approval_project_id','approval_revision_id','customer_id'] as $key)
   if(!is_string($m[$key]??null)||!preg_match('/\A[A-Za-z0-9_.:-]{1,180}\z/D',$m[$key]))throw new RuntimeException('opaque_reference_invalid');
  if(($m['quote_digest_contract']??null)!=='dn29_manifest_candidate_v1'||!is_string($m['manifest_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$m['manifest_sha256']))throw new RuntimeException('quote_mapping_invalid');
  $this->post($project,'pr_iw_project',$owner);
  $this->post($revision,'pr_iw_revision',$owner,$project);
  $this->post($reservation,'pr_iw_url_reserve',$owner,$project);
  $current=self::number($this->meta($project,'_pr_iw_version'));
  if(self::number($this->meta($revision,'_pr_iw_revision_number'))!==$current||($m['canonical_revision']??null)!==$current)throw new RuntimeException('revision_superseded');
  $html=base64_decode($this->meta($revision,'_pr_iw_html_b64'),true);
  $hash=$this->meta($revision,'_pr_iw_sha256');
  if(!is_string($html)||$html===''||strlen($html)>250000||str_contains($html,"\0")||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals($hash,hash('sha256',$html))||($m['html_sha256']??null)!==$hash)throw new RuntimeException('bytes_mismatch');
  if(!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)<3||strlen($slug)>63)throw new RuntimeException('slug_invalid');
  foreach(['slug'=>$slug,'revision_id'=>(string)$revision,'revision_sha256'=>$hash,'state'=>'reserved'] as $key=>$expected)
   if($this->meta($reservation,'_pr_iw_g2_'.$key)!==$expected)throw new RuntimeException('reservation_mismatch');
  $expiry=self::number($this->meta($reservation,'_pr_iw_g2_expires_at'));
  if($expiry<=$now)throw new RuntimeException('reservation_expired');
  return ['kind'=>'PERSISTED_IDENTITY_CANDIDATE_NOT_PAYMENT_AUTHORITY','mapping'=>$m,'source_bytes'=>$html,
   'html_sha256'=>$hash,'reservation_expires_at'=>$expiry,'mapping_version'=>$version,
   'context'=>['digest_contract'=>$m['quote_digest_contract'],'manifest_sha256'=>$m['manifest_sha256'],'customer_id'=>$m['customer_id']]];
 }
}
