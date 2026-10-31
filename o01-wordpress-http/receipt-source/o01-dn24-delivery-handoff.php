<?php
declare(strict_types=1);
/** DN24 new independent adapter. Builds a NEW DN22 final preview from a Studio LOCAL fixture.
 * Studio's local simulated preview/approval are NEVER production proofs.
 * Nothing here calls WP, accepts a real payment, or publishes a page.
 */
require_once __DIR__.'/o01-core.php';
const O01_DN24_STUDIO_SHA='a6e1d5e1045d47a1b46d48d781c41df456bd8c81a6007d41d8100c6c491f61d2';
function o01_dn24_text($v, string $key, int $max=5000): string {
    o01_assert(is_string($v)&&trim($v)!==''&&strlen($v)<=$max,'invalid_'.$key);
    return $v;
}
function o01_dn24_build(array $studio, array $offer): array {
    o01_assert(($studio['schema']??null)==='peoplerun.o01.e2e.local.v1','unsupported_studio_schema');
    o01_assert(($studio['notice']??null)==='SYNTHETIC_LOCAL_ONLY','studio_local_marker_missing');
    $q=$studio['project']??null;
    o01_assert(is_array($q),'project_missing');
    foreach(['id','owner','business','description','slug','theme','revision','html'] as $key) o01_assert(array_key_exists($key,$q),'studio_missing_'.$key);
    $studioId=o01_dn24_text($q['id'],'studio_id',180);
    o01_dn24_text($q['owner'],'owner',180);
    $business=o01_dn24_text($q['business'],'business',200);
    $description=o01_dn24_text($q['description'],'description');
    $extra=$q['extra']??'';o01_assert(is_string($extra)&&strlen($extra)<=5000,'invalid_extra');
    $sourceHtml=o01_dn24_text($q['html'],'studio_html',250000);
    o01_assert(is_int($q['revision'])&&$q['revision']>0,'invalid_revision');
    o01_assert(in_array($q['theme'],['green','blue','amber'],true),'invalid_theme');
    $slug=o01_dn24_text($q['slug'],'slug',180);
    o01_assert(preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)===1,'invalid_slug');
    // Historical Studio simulated approval is checked for consistency, but never trusted.
    $old=$q['approval']??null;
    if($old!==null){
        o01_assert(is_array($old),'invalid_studio_approval');
        o01_assert(($old['revision']??null)===$q['revision'],'studio_approval_revision_stale');
        o01_assert(is_string($old['sha']??null)&&hash_equals($old['sha'],hash('sha256',$sourceHtml)),'studio_html_changed_since_local_approval');
    }
    $customer=o01_dn24_text($offer['customer_id']??null,'customer_id',180);
    $offerId=o01_dn24_text($offer['offer_id']??null,'offer_id',180);
    $currency=$offer['currency']??null; $price=$offer['price_cents']??null; $term=$offer['term']??null;
    o01_assert($currency==='USD','invalid_currency');
    o01_assert(is_int($price)&&$price>0,'invalid_price');
    o01_assert(in_array($term,['1_week','1_month','1_year'],true),'invalid_term');
    $exp=o01_dn24_text($offer['expires_at_utc']??null,'expires_at_utc',32);
    // DN22 supports only fixed CSS, sanitized fragment, public 1-page, no assets.
    // Therefore the Studio's original HTML is NOT the approved/delivered preview.
    $s=static fn($v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $fragment='<div class="o01-theme-'.$q['theme'].'"><h1>'.$s($business).'</h1><p>'.$s($description).'</p><h2>Welcome</h2><p>'.$s($description).'</p><p>'.$s($extra).'</p><p>Get in touch</p></div>';
    $candidate=[
      'customer_id'=>$customer,'offer_id'=>$offerId,'price_cents'=>$price,'currency'=>'USD',
      'page_count'=>1,'term'=>$term,'privacy'=>'public','slug'=>$slug,'revision'=>$q['revision'],
      'expires_at_utc'=>$exp,'sanitized_html'=>$fragment,'css'=>O01_DN17_CSS,'asset_manifest'=>[]
    ];
    $m=o01_manifest($candidate);$preview=o01_render($candidate);
    $scope=['schema'=>'o01-dn24-preview-scope-v1','studio_id'=>$studioId,'studio_revision'=>$q['revision'],
      'studio_source_html_sha256'=>hash('sha256',$sourceHtml),
      'manifest_sha256'=>$m['manifest_sha256'],'render_sha256'=>$m['render_sha256'],
      'offer_sha256'=>hash('sha256',o01_canon(['customer_id'=>$customer,'offer_id'=>$offerId,'price_cents'=>$price,'currency'=>'USD','term'=>$term,'expires_at_utc'=>$exp]))];
    $scope['approval_scope_sha256']=hash('sha256',o01_canon($scope));
    return ['schema'=>'o01-dn24-local-handoff-v1','status'=>'FINAL_PREVIEW_AWAITING_INDEPENDENT_CUSTOMER_APPROVAL',
      'legacy_studio_approval_is_trusted'=>false,'real_payment_verified'=>false,
      'production_publish_authorized'=>false,'candidate'=>$candidate,'publisher_manifest'=>$m,
      'approval_scope'=>$scope,'final_preview_html'=>$preview];
}
function o01_dn24_scope_matches(array $handoff, array $approvedScope): bool {
    $s=$handoff['approval_scope']??null;
    return is_array($s)&&($handoff['status']??null)==='FINAL_PREVIEW_AWAITING_INDEPENDENT_CUSTOMER_APPROVAL' &&
       isset($s['approval_scope_sha256'],$approvedScope['approval_scope_sha256'])&&
       is_string($s['approval_scope_sha256'])&&is_string($approvedScope['approval_scope_sha256'])&&
       hash_equals($s['approval_scope_sha256'],$approvedScope['approval_scope_sha256']);
}
