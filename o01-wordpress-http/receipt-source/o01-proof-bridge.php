<?php
/**
 * PeopleRun O01 DN20 approval/payment proof bridge — candidate only.
 * This is NOT an approval issuer or a payment processor. It verifies externally
 * issued evidence and otherwise DENIES. Requires explicitly configured keys.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/o01-core.php';

function o01_dn20_secret(string $key): ?string {
    if (!defined($key)) return null;
    $s=constant($key);
    return is_string($s) && strlen($s)>=32 ? $s : null;
}
function o01_dn20_string($s): bool { return is_string($s)&&$s!==''; }
function o01_dn20_approval_verify(array $approval, array $candidate, int $now): bool {
    $secret=o01_dn20_secret('O01_DN20_APPROVAL_VERIFIER_KEY');
    if ($secret===null) return false;
    $proof=$approval['approval_proof']??null;
    if (!is_array($proof)) return false;
    $keys=['schema','issuer','customer_id','manifest_sha256','consent_ref','approved_at','expires_at','signature'];
    if (array_keys($proof)!==$keys) return false;
    if (($proof['schema']??null)!=='o01-customer-approval-v1' || !o01_dn20_string($proof['issuer']) || !o01_dn20_string($proof['consent_ref'])) return false;
    try { $m=o01_manifest($candidate); } catch(Throwable $e) { return false; }
    if ($proof['customer_id']!==$m['customer_id'] || $proof['manifest_sha256']!==$m['manifest_sha256']) return false;
    if (($approval['status']??null)!=='approved' || ($approval['customer_id']??null)!==$m['customer_id'] || ($approval['manifest_sha256']??null)!==$m['manifest_sha256']) return false;
    $issued=$proof['approved_at']; $expires=$proof['expires_at'];
    if (!is_int($issued)||!is_int($expires)||$issued>$now||$expires<=$now||$expires<=$issued||$expires-$issued>86400) return false;
    if (!is_string($proof['signature'])||preg_match('/^[a-f0-9]{64}$/D',$proof['signature'])!==1) return false;
    $signed=$proof;unset($signed['signature']);
    $expected=hash_hmac('sha256',o01_canon($signed),$secret);
    return hash_equals($expected,$proof['signature']);
}
function o01_dn20_stripe_verify(array $payment, array $candidate, int $now): bool {
    $secret=o01_dn20_secret('O01_DN20_STRIPE_WEBHOOK_SECRET');
    if ($secret===null) return false;
    $proof=$payment['stripe_proof']??null;
    if (!is_array($proof)||!isset($proof['body'],$proof['signature']) || !is_string($proof['body']) || !is_string($proof['signature']) || strlen($proof['body'])>50000) return false;
    if (!preg_match('/(?:^|,)t=(\d+)(?:,|$)/',$proof['signature'],$tm)||!preg_match('/(?:^|,)v1=([a-f0-9]{64})(?:,|$)/',$proof['signature'],$sg)) return false;
    $ts=filter_var($tm[1],FILTER_VALIDATE_INT);
    if ($ts===false || abs($now-$ts)>300) return false;
    $expected=hash_hmac('sha256',$tm[1].'.'.$proof['body'],$secret);
    if (!hash_equals($expected,$sg[1])) return false;
    try { $event=json_decode($proof['body'],true,512,JSON_THROW_ON_ERROR); $m=o01_manifest($candidate); }
    catch(Throwable $e) { return false; }
    if (!is_array($event)||($event['type']??null)!=='payment_intent.succeeded' || ($event['livemode']??null)!==true || !o01_dn20_string($event['id']??null)) return false;
    $pi=$event['data']['object']??null;
    if (!is_array($pi)||($pi['object']??null)!=='payment_intent'||($pi['status']??null)!=='succeeded' || !o01_dn20_string($pi['id']??null)) return false;
    if (($pi['amount_received']??null)!==$m['price_cents'] || ($pi['currency']??null)!=='usd') return false;
    $md=$pi['metadata']??null;
    if (!is_array($md)) return false;
    foreach(['manifest_sha256','customer_id','offer_id','term','slug'] as $k) if(($md[$k]??null)!==(string)$m[$k]) return false;
    if (($md['revision']??null)!==(string)$m['revision']) return false;
    if (($payment['status']??null)!=='completed' || ($payment['manifest_sha256']??null)!==$m['manifest_sha256']) return false;
    foreach(['customer_id','offer_id','price_cents','currency','term','revision'] as $k) if(($payment[$k]??null)!==$m[$k]) return false;
    // A verified, successful payment event is bound to this exact paid manifest.
    // Durable webhook event ID / payment intent replay tracking is REQUIRED
    // before production admission; DN20 does not store or consume the event.
    return true;
}
add_filter('peoplerun_o01_verified_customer_approval',static function($previous,$approval,$candidate){
    return o01_dn20_approval_verify(is_array($approval)?$approval:[],is_array($candidate)?$candidate:[],time());
},10,3);
add_filter('peoplerun_o01_verified_completed_payment',static function($previous,$payment,$candidate){
    return o01_dn20_stripe_verify(is_array($payment)?$payment:[],is_array($candidate)?$candidate:[],time());
},10,3);
