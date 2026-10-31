<?php
declare(strict_types=1);
/** Source only. Caller owns begin/commit/rollback. No endpoint or real authorization. */
final class O01CallerOwnedApproval {
    public function __construct(private PDO $db) {}
    public function verifyAndClaim(array $claim, callable $verifyLocked): array {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new LogicException('mysql_required');
        if (!$this->db->inTransaction()) return ['ok'=>false,'code'=>'CALLER_TRANSACTION_REQUIRED'];
        foreach (['project_id','revision_id'] as $k)
            if (!isset($claim[$k]) || !is_string($claim[$k]) || $claim[$k]==='' || strlen($claim[$k])>64)
                return ['ok'=>false,'code'=>'INVALID_ID'];
        $stmt=$this->db->prepare('SELECT project_id,revision_id,owner_id,html_sha256,quote_sha256,amount_minor,currency,term,status FROM pr_o01_revision_authority WHERE project_id=:project_id AND revision_id=:revision_id FOR UPDATE');
        $stmt->execute(['project_id'=>$claim['project_id'],'revision_id'=>$claim['revision_id']]);
        $locked=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$locked) return ['ok'=>false,'code'=>'AUTHORITY_ROW_MISSING'];
        if (($locked['status']??null)!=='awaiting_approval') return ['ok'=>false,'code'=>'REVISION_WITHDRAWN_OR_CLOSED'];
        if (!hash_equals((string)$locked['project_id'],$claim['project_id']) || !hash_equals((string)$locked['revision_id'],$claim['revision_id']))
            return ['ok'=>false,'code'=>'AUTHORITY_MISMATCH'];
        // The callback must be the authenticated WordPress verifier. It MUST use the
        // locked revision supplied here, not an earlier request snapshot.
        $calls=0; $written=false;
        $claimOnce=function(string $key,array $verified) use (&$calls,&$written,$locked):bool {
            $calls++;
            if ($calls!==1) return false;
            $expected=hash('sha256',implode("\0",[(string)$locked['project_id'],(string)$locked['revision_id'],'customer_approval']));
            if (!hash_equals($expected,$key)) return false;
            if (!isset($verified['identity'],$verified['revision_id'],$verified['html_sha256'],$verified['receipt_id'],$verified['amount_minor'],$verified['currency'],$verified['term'])) return false;
            if (!hash_equals('wp_user:'.(string)$locked['owner_id'],(string)$verified['identity']) ||
                !hash_equals((string)$locked['revision_id'],(string)$verified['revision_id']) ||
                !hash_equals((string)$locked['html_sha256'],(string)$verified['html_sha256']) ||
                (string)$locked['amount_minor'] !== (string)$verified['amount_minor'] ||
                (string)$locked['currency'] !== (string)$verified['currency'] || (string)$locked['term'] !== (string)$verified['term']) return false;
            if (!preg_match('/\A[a-f0-9]{64}\z/D',(string)$locked['quote_sha256'])) return false;
            $record=['project_id'=>$locked['project_id'],'revision_id'=>$locked['revision_id'],
                'owner_id'=>$locked['owner_id'], 'approval_sha256'=>hash('sha256',json_encode($verified,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
                'html_sha256'=>$locked['html_sha256'],'quote_sha256'=>$locked['quote_sha256'],'receipt_id'=>$verified['receipt_id']];
            try {
                $insert=$this->db->prepare('INSERT INTO pr_o01_approval_claims (project_id,revision_id,owner_id,approval_sha256,html_sha256,quote_sha256,receipt_id,claimed_at) VALUES (:project_id,:revision_id,:owner_id,:approval_sha256,:html_sha256,:quote_sha256,:receipt_id,UTC_TIMESTAMP(6))');
                $insert->execute($record);
                $written=$insert->rowCount()===1;
                return $written;
            } catch (PDOException $e) {
                if ((string)$e->getCode()==='23000') return false;
                throw $e;
            }
        };
        $result=$verifyLocked($claim,$locked,$claimOnce);
        if (!is_array($result) || ($result['ok']??false)!==true || !$written || $calls!==1)
            return ['ok'=>false,'code'=>'APPROVAL_OR_INSERT_REJECTED_CALLER_MUST_ROLLBACK'];
        // The locked authority and unique claim share this caller-owned transaction.
        // A concurrent withdrawal must acquire the same row lock and may only
        // change awaiting_approval -> withdrawn; approved -> withdrawn is forbidden.
        $transition=$this->db->prepare('UPDATE pr_o01_revision_authority SET status=:approved WHERE project_id=:project_id AND revision_id=:revision_id AND status=:awaiting');
        $transition->execute(['approved'=>'approved','project_id'=>$locked['project_id'],
            'revision_id'=>$locked['revision_id'],'awaiting'=>'awaiting_approval']);
        if ($transition->rowCount()!==1)
            return ['ok'=>false,'code'=>'AUTHORITY_TRANSITION_REJECTED_CALLER_MUST_ROLLBACK'];
        // STAGED is not committed, durable customer consent, or host authorization.
        return ['ok'=>true,'code'=>'CLAIM_AND_APPROVED_STATE_STAGED_CALLER_MUST_COMMIT'];
    }
}
