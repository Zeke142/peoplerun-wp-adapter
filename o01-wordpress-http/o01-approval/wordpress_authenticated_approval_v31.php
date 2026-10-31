<?php
declare(strict_types=1);
/** Source-only binding: must be called inside authenticated WordPress request. No endpoint registration. */
require_once __DIR__.'/recovered/o01_candidate_v11/integration_hook.php';
function o01_wp_verifier_v31(array $request): callable {
    return static function(array $claim, array $locked, callable $writerClaimOnce) use ($request): array {
        // Reject absent WordPress identity/session/nonce rather than trusting request-supplied values.
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() ||
            !function_exists('get_current_user_id') || !function_exists('wp_get_session_token') ||
            !function_exists('wp_verify_nonce')) return ['ok'=>false,'code'=>'WP_AUTH_UNAVAILABLE'];
        $uid=(int)get_current_user_id();
        $session=(string)wp_get_session_token();
        $nonce=$request['nonce']??null;
        if ($uid<=0 || $session==='' || !is_string($nonce) ||
            wp_verify_nonce($nonce,'o01_approve_'.$locked['project_id'].'_'.$locked['revision_id'])!==1)
            return ['ok'=>false,'code'=>'WP_SESSION_OR_FRESH_NONCE_REJECTED'];
        if ((string)$locked['owner_id']!==(string)$uid ||
            !hash_equals((string)$locked['revision_id'],(string)($request['revision_id']??'')) ||
            !hash_equals((string)$locked['html_sha256'],(string)($request['html_sha256']??'')) ||
            !hash_equals((string)$locked['quote_sha256'],(string)($request['quote_sha256']??'')))
            return ['ok'=>false,'code'=>'LOCKED_PREVIEW_OR_OWNER_MISMATCH'];
        if (($claim['project_id']??null)!==$locked['project_id'] || ($claim['revision_id']??null)!==$locked['revision_id'])
            return ['ok'=>false,'code'=>'CLAIM_MISMATCH'];
        // Session token never persists in receipt. Lock + unique claim is replay boundary.
        $verified=['identity'=>'wp_user:'.$uid,'revision_id'=>$locked['revision_id'],
            'html_sha256'=>$locked['html_sha256'],'receipt_id'=>bin2hex(random_bytes(32)),
            'amount_minor'=>$locked['amount_minor'],'currency'=>$locked['currency'],'term'=>$locked['term']];
        $key=hash('sha256',implode("\0",[$locked['project_id'],$locked['revision_id'],'customer_approval']));
        return $writerClaimOnce($key,$verified) ? ['ok'=>true,'code'=>'VERIFIED_AND_CLAIM_STAGED'] :
            ['ok'=>false,'code'=>'CLAIM_REJECTED'];
    };
}
/** Caller chooses commit; never commit on a failed result. This function never commits itself. */
function o01_stage_wp_approval_v31(PDO $pdo, array $request): array {
    foreach (['project_id','revision_id','html_sha256','quote_sha256','nonce'] as $key)
        if (!isset($request[$key]) || !is_string($request[$key])) return ['ok'=>false,'code'=>'REQUEST_FIELD_MISSING'];
    $claim=['project_id'=>$request['project_id'],'revision_id'=>$request['revision_id']];
    return o01_caller_owned_boundary($pdo,$claim,o01_wp_verifier_v31($request));
}
