<?php
declare(strict_types=1);
/** SOURCE-ONLY CANDIDATE. No hooks, installation, table creation, or external side effects.
 * Storage driver owns atomic locking, persistence and a UNIQUE slug/request key constraint.
 * No stored client-provided flags establish consent or payment.
 */
require_once __DIR__.'/O01PaidCalendarTerms.php';
require_once __DIR__.'/O01AtomicChildSnapshot.php';
final class O01AtomicPaidRoute {
    private static function deny(string $reason): array {return ['result'=>'DENIED','reason'=>$reason,'published'=>false];}
    /**
     * $store implements begin(), findSlug(), findRequest(), put(), receipt(), commit(), rollback().
     * begin/commit MUST be a real DB transaction and lock slug and idempotency key consistently;
     * put + receipt MUST share transaction. Any DB failure must rollback atomically.
     * $authority must be a server-injected callback, independently verifying source G1/G2
     * + authenticated consent/payment/publication, and must return verified exact-byte pins.
     */
    public static function transition(object $store, string $op, array $command, callable $authority, int $now, ?callable $prepareChildren=null, ?callable $prepareParent=null): array {
        if (!in_array($op,['publish','withdraw'],true)) return self::deny('invalid_operation');
        $slug=$command['slug']??null; $key=$command['request_key']??null;
        if (!is_string($slug)||strlen($slug)<3||strlen($slug)>63||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)
            ||!is_string($key)||strlen($key)<16||strlen($key)>120||!preg_match('/\A[a-zA-Z0-9_.-]+\z/D',$key)
            ||$now<1) return self::deny('invalid_request');
        try {
            if ($store->begin($slug,$key)!==true) return self::deny('transaction_unavailable');
            $previous=$store->findRequest($key);
            if ($previous!==null) {
                if (!is_array($previous)||($previous['slug']??null)!==$slug||($previous['op']??null)!==$op||
                    ($previous['intent_hash']??null)!==hash('sha256',json_encode($command,JSON_THROW_ON_ERROR))) {
                    $store->rollback();return self::deny('idempotency_conflict');
                }
                // A historical publish receipt is not evidence that its route remains live.
                // Compare the current committed record with the original receipt before replay.
                $live=$store->findSlug($slug);
                if (!is_array($live) ||
                    ($previous['record_sha256']??null)!==hash('sha256',json_encode($live,JSON_THROW_ON_ERROR)) ||
                    ($op==='publish' && (($live['state']??null)!=='published'||
                        !is_int($live['expires_at']??null)||$now >= $live['expires_at'])) ||
                    ($op==='withdraw' && ($live['state']??null)!=='withdrawn')) {
                    $store->rollback();return self::deny('stale_idempotent_replay');
                }
                $store->rollback();return ['result'=>'REPLAY','receipt'=>$previous,'published'=>false];
            }
            $current=$store->findSlug($slug);
            if ($op==='publish'&&$current!==null) { $store->rollback();return self::deny('slug_already_bound'); }
            if ($op==='withdraw'&&(!is_array($current)||($current['state']??null)!=='published')) {
                $store->rollback();return self::deny('no_live_route_to_withdraw');
            }
            // Do not permit direct public commands to mint trusted evidence: callback must be server-owned.
            $verified=$authority($op,$command,$current,$now);
            if (!is_array($verified)||($verified['verified']??null)!==true||
                !is_string($verified['authority_receipt_sha256']??null)||
                !preg_match('/\A[a-f0-9]{64}\z/D',$verified['authority_receipt_sha256'])) {
                $store->rollback();return self::deny('independent_evidence_unavailable');
            }
            // Identity is obtained solely from the independently verified server authority callback.
            // A caller's ids, even if equal, are never the source of persisted identity.
            foreach (['owner_id','project_id','revision_id','reservation_id'] as $identity) {
                if (!is_int($verified[$identity]??null) || $verified[$identity]<1) {
                    $store->rollback(); return self::deny('verified_identity_missing');
                }
            }
            if ($op==='withdraw') {
                foreach (['owner_id','project_id','revision_id','reservation_id'] as $identity) {
                    if (($current[$identity]??null)!==$verified[$identity]) {
                        $store->rollback(); return self::deny('withdraw_identity_mismatch');
                    }
                }
            }
            if ($op==='publish') {
                foreach (['revision_sha256','html_sha256','consent_sha256','payment_sha256','publication_sha256'] as $field) {
                    if (!is_string($verified[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$verified[$field])) {
                        $store->rollback();return self::deny('receipt_pins_incomplete');
                    }
                }
                if (!hash_equals($verified['revision_sha256'],$verified['html_sha256']) ||
                    ($verified['slug']??null)!==$slug || !is_int($verified['started_at']??null)||
                    !is_int($verified['expires_at']??null)||!is_int($verified['term_seconds']??null)||
                    !is_string($verified['term']??null)||
                    !O01PaidCalendarTerms::valid($verified['term'],$verified['started_at'],$verified['expires_at'])||
                    $verified['started_at']>$now||$verified['expires_at']<=$now||
                    $verified['expires_at']-$verified['started_at']!==$verified['term_seconds']) {
                    $store->rollback();return self::deny('term_or_revision_invalid');
                }
                $record=['slug'=>$slug,'state'=>'published','revision_sha256'=>$verified['revision_sha256'],
                    'html_sha256'=>$verified['html_sha256'],'started_at'=>$verified['started_at'],
                    'expires_at'=>$verified['expires_at'],'term_seconds'=>$verified['term_seconds'],'term'=>$verified['term'],
                    'consent_sha256'=>$verified['consent_sha256'],'payment_sha256'=>$verified['payment_sha256'],
                    'publication_sha256'=>$verified['publication_sha256'],
                    'owner_id'=>$verified['owner_id'],'project_id'=>$verified['project_id'],
                    'revision_id'=>$verified['revision_id'],'reservation_id'=>$verified['reservation_id']];
                // An anonymously deliverable route must freeze parent bytes at publication time.
                // Never retrieve owner-only G1 data from the public reader.
                if ($prepareParent === null) { $store->rollback();return self::deny('parent_snapshot_preparation_missing'); }
                $parent=$prepareParent($record,$verified);
                if (!is_array($parent)||!is_string($parent['html']??null)||$parent['html']===''||
                    strlen($parent['html'])>250000||strpos($parent['html'], "\0")!==false||
                    !is_string($parent['html_sha256']??null)||
                    !hash_equals($record['html_sha256'],$parent['html_sha256'])||
                    !hash_equals($record['html_sha256'],hash('sha256',$parent['html']))||
                    ($parent['revision_id']??null)!==$record['revision_id']||
                    ($parent['project_id']??null)!==$record['project_id']||
                    ($parent['owner_id']??null)!==$record['owner_id']) {
                    $store->rollback();return self::deny('parent_snapshot_not_authorized');
                }
                $record['parent_html_b64']=base64_encode($parent['html']);
                if ($prepareChildren !== null) {
                    // Only trusted owner-side preparation may read G1 subpages. Store child
                    // snapshots in the SAME route row and transaction as the parent receipt.
                    $snapshot=$prepareChildren($record,$verified);
                    if (!is_array($snapshot) || !O01AtomicChildSnapshot::valid($snapshot,$record)) {
                        $store->rollback();return self::deny('child_snapshot_not_authorized');
                    }
                    $record['subpages_snapshot']=$snapshot;
                }
            } else {
                if (($verified['slug']??null)!==$slug||($verified['revision_sha256']??null)!==$current['revision_sha256']||
                    ($verified['withdraw_authorized']??null)!==true) { $store->rollback();return self::deny('withdraw_authority_mismatch'); }
                $record=$current; $record['state']='withdrawn';$record['withdrawn_at']=$now;
            }
            $receipt=['request_key'=>$key,'intent_hash'=>hash('sha256',json_encode($command,JSON_THROW_ON_ERROR)),
                'op'=>$op,'slug'=>$slug,'authority_receipt_sha256'=>$verified['authority_receipt_sha256'],
                'record_sha256'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR)),
                'committed_at'=>$now,'kind'=>'SOURCE_ONLY_TRANSITION_RECEIPT'];
            if ($store->put($slug,$record)!==true||$store->receipt($key,$receipt)!==true||$store->commit()!==true) {
                $store->rollback();return self::deny('atomic_commit_failed');
            }
            return ['result'=>'COMMITTED_LOCAL_CANDIDATE','receipt'=>$receipt,'record'=>$record,'published'=>false];
        } catch (Throwable $e) { try {$store->rollback();}catch(Throwable $ignored){}return self::deny('storage_or_authority_exception'); }
    }
}
