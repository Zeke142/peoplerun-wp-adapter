<?php
/** O01 DN21 new candidate: transaction-coordinated replay ledger. No production authority. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/o01-dn25-scope-verifier.php';

/** Immutable trust configuration must be admitted separately, outside the package. */
function o01_dn21_trust_configured(): bool {
    return defined('O01_DN21_AUTHORITY_ADMITTED') && constant('O01_DN21_AUTHORITY_ADMITTED') === true
        && defined('O01_DN21_APPROVAL_ISSUER') && is_string(constant('O01_DN21_APPROVAL_ISSUER'))
        && constant('O01_DN21_APPROVAL_ISSUER') !== ''
        && defined('O01_DN21_PAYMENT_PROVIDER') && constant('O01_DN21_PAYMENT_PROVIDER') === 'stripe_live'
        && o01_dn20_secret('O01_DN20_APPROVAL_VERIFIER_KEY') !== null
        && o01_dn20_secret('O01_DN20_STRIPE_WEBHOOK_SECRET') !== null;
}
function o01_dn25_identifiers(array $approval,array $payment,array $candidate,array $handoff,int $now): ?array {
    if (!o01_dn21_trust_configured()) return null;
    if (!o01_dn25_approval_verify($approval,$candidate,$handoff,$now) || !o01_dn20_stripe_verify($payment,$candidate,$now)) return null;
    $p=$approval['approval_proof']??[];
    if (($p['issuer']??null) !== constant('O01_DN21_APPROVAL_ISSUER')) return null;
    try {
        $event=json_decode($payment['stripe_proof']['body']??'',true,512,JSON_THROW_ON_ERROR);
        $m=o01_manifest($candidate);
        $decision=o01_release_decision($candidate,$approval,$payment,$now,true,true);
        if (!$decision['ok']) return null;
        $ev=$event['id']??null; $pi=$event['data']['object']['id']??null; $consent=$p['consent_ref']??null;
        foreach ([$ev,$pi,$consent] as $x) {
            if (!is_string($x)||strlen($x)<3||strlen($x)>180||preg_match('/^[A-Za-z0-9_:\.-]+$/D',$x)!==1) return null;
        }
        return ['manifest_sha256'=>$m['manifest_sha256'],'customer_id'=>$m['customer_id'],
            'approval_issuer'=>$p['issuer'],'consent_ref'=>$consent,'payment_provider'=>'stripe_live',
            'event_id'=>$ev,'payment_id'=>$pi,'timestamp'=>$now];
    }catch(Throwable $e){return null;}
}
/**
 * Requires a dedicated InnoDB table installed by a separately authorized migration.
 * Transaction and publication MUST use the same database connection.
 * $publish MUST NOT begin/commit a nested transaction and must throw on any failure.
 * Returns false on conflict/DB error; never bypasses a failed ledger write.
 */
function o01_dn25_admit_atomic($db,array $approval,array $payment,array $candidate,array $handoff,int $now,callable $publish): bool {
    $ids=o01_dn25_identifiers($approval,$payment,$candidate,$handoff,$now);
    if ($ids===null) return false;
    $table=defined('O01_DN21_LEDGER_TABLE')?constant('O01_DN21_LEDGER_TABLE'):'';
    if (!is_string($table)||preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)!==1) return false;
    if (!method_exists($db,'query')||!method_exists($db,'prepare')) return false;
    $active=false;
    try {
        if ($db->query('START TRANSACTION')===false) return false;
        $active=true;
        $sql=$db->prepare('INSERT INTO `'.$table.'` (manifest_sha256,customer_id,approval_issuer,consent_ref,payment_provider,event_id,payment_id,admitted_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%d)',
            $ids['manifest_sha256'],$ids['customer_id'],$ids['approval_issuer'],$ids['consent_ref'],
            $ids['payment_provider'],$ids['event_id'],$ids['payment_id'],$ids['timestamp']);
        if (!is_string($sql)||$db->query($sql)!==1) throw new RuntimeException('receipt_ledger_denied');
        // A callback must perform ALL publishing writes within this transaction.
        if ($publish($db,$ids)!==true) throw new RuntimeException('publish_failed');
        if ($db->query('COMMIT')===false) throw new RuntimeException('commit_failed');
        $active=false;
        return true;
    }catch(Throwable $e){
        if ($active) {try{$db->query('ROLLBACK');}catch(Throwable $ignored){}}
        return false;
    }
}
