<?php
declare(strict_types=1);
/** DN25 scoped approval verification, candidate only. No issuer creation or real customer approval. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/o01-proof-bridge.php';
require_once __DIR__.'/o01-dn24-delivery-handoff.php';
function o01_dn25_approval_verify(array $approval,array $candidate,array $handoff,int $now):bool {
    $secret=o01_dn20_secret('O01_DN20_APPROVAL_VERIFIER_KEY');
    if($secret===null)return false;
    $scope=$handoff['approval_scope']??null;
    if(!is_array($scope)||!isset($scope['approval_scope_sha256'])||!is_string($scope['approval_scope_sha256'])||
       !preg_match('/^[a-f0-9]{64}$/D',$scope['approval_scope_sha256'])) return false;
    try {
        if(($handoff['status']??null)!=='FINAL_PREVIEW_AWAITING_INDEPENDENT_CUSTOMER_APPROVAL')return false;
        $base=$scope;unset($base['approval_scope_sha256']);
        if(!hash_equals(hash('sha256',o01_canon($base)),$scope['approval_scope_sha256']))return false;
        $m=o01_manifest($candidate);
        if(($scope['manifest_sha256']??null)!==$m['manifest_sha256'] ||
           ($scope['render_sha256']??null)!==$m['render_sha256'] ||
           ($handoff['publisher_manifest']??null)!==$m ||
           ($handoff['candidate']??null)!==$candidate ||
           ($handoff['final_preview_html']??null)!==o01_render($candidate))return false;
    } catch(Throwable $e){return false;}
    $p=$approval['approval_proof']??null;
    if(!is_array($p))return false;
    $fields=['schema','issuer','customer_id','manifest_sha256','approval_scope_sha256','consent_ref','approved_at','expires_at','signature'];
    if(array_keys($p)!==$fields)return false;
    if($p['schema']!=='o01-customer-approval-scope-v2'||!o01_dn20_string($p['issuer'])||!o01_dn20_string($p['consent_ref']))return false;
    if($p['customer_id']!==$m['customer_id']||$p['manifest_sha256']!==$m['manifest_sha256']||
       $p['approval_scope_sha256']!==$scope['approval_scope_sha256'])return false;
    if(($approval['status']??null)!=='approved'||($approval['customer_id']??null)!==$m['customer_id']||
       ($approval['manifest_sha256']??null)!==$m['manifest_sha256']||
       ($approval['approval_scope_sha256']??null)!==$scope['approval_scope_sha256'])return false;
    $issued=$p['approved_at'];$expires=$p['expires_at'];
    if(!is_int($issued)||!is_int($expires)||$issued>$now||$expires<=$now||$expires<$issued||$expires-$issued>86400)return false;
    if(!is_string($p['signature'])||preg_match('/^[a-f0-9]{64}$/D',$p['signature'])!==1)return false;
    $signed=$p;unset($signed['signature']);
    return hash_equals(hash_hmac('sha256',o01_canon($signed),$secret),$p['signature']);
}
