<?php
declare(strict_types=1);
/** SOURCE CANDIDATE ONLY. No hooks, routes, writes, credentials or publication authority.
 * Inputs MUST come from trusted server loaders. lockAndCheck must run on the SAME
 * PDO transaction as the atomic publication writer. A successful comparison is
 * not a payment/publication verifier and must never replace either verifier.
 */
final class O01ApprovalPublicationBinding {
    private static function deny(string $code): array {return ['ok'=>false,'code'=>$code];}
    public static function check(array $row,array $claim,array $mapping,array $candidate,string $html): array {
        if (($row['status']??null)!=='approved')return self::deny('APPROVAL_NOT_CURRENT');
        foreach (['project_id','revision_id','owner_id','html_sha256','quote_sha256'] as $key) {
            if (!isset($row[$key],$claim[$key]) || (string)$row[$key]!== (string)$claim[$key])
                return self::deny('CLAIM_ROW_MISMATCH');
        }
        foreach (['html_sha256','quote_sha256','approval_sha256'] as $key) {
            $v=$claim[$key]??null;
            if (!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v))return self::deny('CLAIM_DIGEST_INVALID');
        }
        if (!isset($claim['receipt_id'])||!is_string($claim['receipt_id'])||
            !preg_match('/\A[a-f0-9]{64}\z/D',$claim['receipt_id']))return self::deny('WP_RECEIPT_ID_INVALID');
        if (!isset($row['amount_minor'],$row['currency'],$row['term']) ||
            !is_string($row['currency'])||!preg_match('/\A[A-Z]{3}\z/D',$row['currency']))return self::deny('QUOTE_FIELDS_INVALID');
        $terms=['one_week'=>'week','one_month'=>'month','one_year'=>'year'];
        if (!is_string($row['term'])||!isset($terms[$row['term']]))return self::deny('TERM_UNMAPPED');
        if (!is_int($row['amount_minor'])&&!is_string($row['amount_minor']))return self::deny('AMOUNT_INVALID');
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$row['amount_minor']))return self::deny('AMOUNT_INVALID');
        // EXACT original preimage key order and JSON flags from wordpress_authenticated_approval_v31.php
        // and caller_owned_approval.php. Preserve PDO scalar type; never silently coerce JSON values.
        $approved=['identity'=>'wp_user:'.(string)$row['owner_id'],'revision_id'=>$row['revision_id'],
            'html_sha256'=>$row['html_sha256'],'receipt_id'=>$claim['receipt_id'],
            'amount_minor'=>$row['amount_minor'],'currency'=>$row['currency'],'term'=>$row['term']];
        $digest=hash('sha256',json_encode($approved,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if (!hash_equals($claim['approval_sha256'],$digest))return self::deny('APPROVED_TUPLE_CHANGED');
        if ($html===''||strlen($html)>250000||strpos($html,"\0")!==false||
            !hash_equals($claim['html_sha256'],hash('sha256',$html)))return self::deny('PREVIEW_BYTES_CHANGED');
        // Explicit trusted mapping; do not infer numeric G1 IDs by casting opaque approval IDs.
        foreach (['project_id','revision_id','owner_id'] as $key)
            if (!isset($mapping['approval_'.$key])||(string)$mapping['approval_'.$key] !== (string)$row[$key])
                return self::deny('APPROVAL_MAPPING_MISMATCH');
        foreach (['owner_id','project_id','revision_id','reservation_id'] as $key) {
            if (!is_int($mapping[$key]??null)||$mapping[$key]<1||($candidate[$key]??null)!==$mapping[$key])
                return self::deny('PUBLICATION_MAPPING_MISMATCH');
        }
        if ((string)$mapping['owner_id']!==(string)$row['owner_id'])return self::deny('OWNER_MAPPING_MISMATCH');
        foreach (['html_sha256','revision_sha256','quote_sha256'] as $key) {
            $expected=$key==='quote_sha256'?$claim['quote_sha256']:$claim['html_sha256'];
            if (!is_string($candidate[$key]??null)||!hash_equals($expected,$candidate[$key]))return self::deny('PUBLICATION_DIGEST_MISMATCH');
        }
        // Strict scalar equality: loader contracts must preserve the exact receipt preimage types.
        if (($candidate['amount_minor']??null)!==$row['amount_minor']||($candidate['currency']??null)!==$row['currency']||
            ($candidate['term']??null)!==$terms[$row['term']])return self::deny('PUBLICATION_QUOTE_MISMATCH');
        return ['ok'=>true,'code'=>'APPROVAL_BINDING_COMPARED_ONLY','approval_receipt_id'=>$claim['receipt_id'],
            'approval_sha256'=>$claim['approval_sha256'],'quote_sha256'=>$claim['quote_sha256'],
            'html_sha256'=>$claim['html_sha256']];
    }
    public static function lockAndCheck(PDO $db,string $project,string $revision,array $mapping,array $candidate,string $html):array {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||!$db->inTransaction())return self::deny('PUBLICATION_TRANSACTION_REQUIRED');
        $q=$db->prepare('SELECT project_id,revision_id,owner_id,html_sha256,quote_sha256,amount_minor,currency,term,status FROM pr_o01_revision_authority WHERE project_id=? AND revision_id=? FOR UPDATE');
        $q->execute([$project,$revision]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row))return self::deny('APPROVAL_ROW_MISSING');
        $q=$db->prepare('SELECT project_id,revision_id,owner_id,html_sha256,quote_sha256,approval_sha256,receipt_id FROM pr_o01_approval_claims WHERE project_id=? AND revision_id=? FOR UPDATE');
        $q->execute([$project,$revision]);$claim=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claim))return self::deny('APPROVAL_CLAIM_MISSING');
        // Caller owns rollback on rejection and every later failure; never commit here.
        return self::check($row,$claim,$mapping,$candidate,$html);
    }
}
