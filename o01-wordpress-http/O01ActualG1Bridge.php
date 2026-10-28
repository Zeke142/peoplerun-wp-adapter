<?php
declare(strict_types=1);
require_once __DIR__.'/O01G1SubpageStore.php';
/** Source-only adapter to the verified G1 v3 API; never reachable from unauthenticated input. */
final class O01ActualG1Bridge {
    private static function bind(int $projectId,int $revisionId,int $ownerId):?array {
        if ($projectId<1||$revisionId<1||$ownerId<1 || !function_exists('pr_iw_g1_v2_owner') || !function_exists('pr_iw_g1_v2_read_revision')) return null;
        if (function_exists('get_current_user_id') && get_current_user_id()!==$ownerId) return null;
        if (!pr_iw_g1_v2_owner($projectId,$ownerId)) return null;
        $r=pr_iw_g1_v2_read_revision($projectId,$revisionId,$ownerId);
        if (!is_array($r)||($r['revision_id']??null)!==$revisionId || !is_string($r['html']??null) || !is_string($r['content_sha256']??null))return null;
        $sha=$r['content_sha256'];
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$sha) || !hash_equals($sha,hash('sha256',$r['html']))) return null;
        return ['post_id'=>$revisionId,'revision_sha256'=>$sha,'source'=>'trusted_g1_server'];
    }
    public static function save(int $projectId,int $revisionId,int $ownerId,string $path,string $html,?string $password=null):bool {
        $g1=self::bind($projectId,$revisionId,$ownerId);
        if ($g1===null)return false;
        // Immutable G1 revisions: subpages attach to an exact revision post, never project or caller-selected postmeta.
        return O01G1SubpageStore::save($revisionId,$g1,$path,$html,$password);
    }
    public static function deliver(int $projectId,int $revisionId,int $ownerId,array $authorizedParent,string $path,?string $candidate=null):array {
        $deny=['status'=>503,'body'=>'','reason'=>'authoritative_g1_unavailable','published'=>false];
        $g1=self::bind($projectId,$revisionId,$ownerId);
        if($g1===null)return $deny;
        // Parent must be server-generated, not supplied by an HTTP request. This is only a low-level API.
        if(($authorizedParent['revision_id']??null)!==$revisionId || ($authorizedParent['revision_sha256']??null)!==$g1['revision_sha256']) return $deny;
        return O01G1SubpageStore::deliver($revisionId,$g1,$authorizedParent,$path,$candidate);
    }
}
