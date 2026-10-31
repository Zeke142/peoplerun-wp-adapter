<?php
declare(strict_types=1);
/** Source-only comparison. Original core must be loaded. Never issues consent/payment/publication. */
final class O01CanonicalQuoteBinding {
 public static function compare(array $candidate,string $approvedBytes,string $frozenManifestSha,array $approved):array {
  try {
   if(!preg_match('/\A[a-f0-9]{64}\z/D',$frozenManifestSha))return ['ok'=>false,'code'=>'FROZEN_MANIFEST_DIGEST_INVALID'];
   $manifest=o01_manifest($candidate);
   if(!hash_equals($frozenManifestSha,$manifest['manifest_sha256']))return ['ok'=>false,'code'=>'CANONICAL_MANIFEST_CHANGED'];
   // Validation recomputes the ORIGINAL renderer only for equality; publishing must use stored approvedBytes.
   if($approvedBytes!==o01_render($candidate)||!hash_equals(hash('sha256',$approvedBytes),$manifest['render_sha256']))
    return ['ok'=>false,'code'=>'APPROVED_PREVIEW_BYTES_DIFFER'];
   $terms=['1_week'=>'one_week','1_month'=>'one_month','1_year'=>'one_year'];
   if(($approved['amount_minor']??null)!==$manifest['price_cents']||($approved['currency']??null)!==$manifest['currency']||
      ($approved['term']??null)!==$terms[$manifest['term']]||($approved['canonical_revision']??null)!==$manifest['revision']||
      ($approved['customer_id']??null)!==$manifest['customer_id'])return ['ok'=>false,'code'=>'APPROVED_QUOTE_TUPLE_DIFFERS'];
   return ['ok'=>true,'code'=>'CANONICAL_QUOTE_COMPARED_ONLY','manifest_sha256'=>$manifest['manifest_sha256'],
     'html_sha256'=>$manifest['render_sha256'],'amount_minor'=>$manifest['price_cents'],'currency'=>$manifest['currency'],'term'=>$terms[$manifest['term']]];
  }catch(Throwable $e){return ['ok'=>false,'code'=>'CANONICAL_QUOTE_INVALID'];}
 }
}
