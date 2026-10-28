<?php
declare(strict_types=1);
require_once __DIR__.'/O01ActualG1Bridge.php';
require_once __DIR__.'/O01AtomicChildSnapshot.php';
require_once __DIR__.'/AtomicPaidRoute.php';
require_once __DIR__.'/O01PublicSubpageRoute.php';
require_once __DIR__.'/O01SubpageAccessGate.php';
/** Uninstalled source binding. No routes/hooks. Caller injects independently authorized receipt callbacks and transactional store. */
final class O01WordPressPublicationAdapter {
    /** Trusted owner-side only; read actual G1 v3 identity/revision and revision-post subpage meta. */
    public static function prepareChildren(array $record,array $verified):?array {
        $owner=$record['owner_id']??null;$project=$record['project_id']??null;$revision=$record['revision_id']??null;
        if(!is_int($owner)||!is_int($project)||!is_int($revision)||!function_exists('get_current_user_id')||get_current_user_id()!==$owner||
           !function_exists('pr_iw_g1_v2_owner')||!pr_iw_g1_v2_owner($project,$owner)||
           !function_exists('pr_iw_g1_v2_read_revision')||!function_exists('get_post_meta'))return null;
        $source=pr_iw_g1_v2_read_revision($project,$revision,$owner);
        if(!is_array($source)||($source['revision_id']??null)!==$revision||!is_string($source['html']??null)||
           !is_string($source['content_sha256']??null)||!hash_equals($record['revision_sha256'],$source['content_sha256'])||
           !hash_equals($source['content_sha256'],hash('sha256',$source['html'])))return null;
        $g1=['source'=>'trusted_g1_server','post_id'=>$revision,'revision_sha256'=>$source['content_sha256']];
        return O01AtomicChildSnapshot::fromG1($record,$revision,$g1,static fn(int $post,string $key)=>get_post_meta($post,$key,true));
    }
    /** Trusted owner-only preparation of the immutable parent, never used by public delivery. */
    public static function prepareParent(array $record,array $verified):?array {
        $owner=$record['owner_id']??null;$project=$record['project_id']??null;$revision=$record['revision_id']??null;
        if(!is_int($owner)||!is_int($project)||!is_int($revision)||!function_exists('get_current_user_id')||
            get_current_user_id()!==$owner||!function_exists('pr_iw_g1_v2_owner')||
            !pr_iw_g1_v2_owner($project,$owner)||!function_exists('pr_iw_g1_v2_read_revision'))return null;
        $source=pr_iw_g1_v2_read_revision($project,$revision,$owner);
        if(!is_array($source)||($source['revision_id']??null)!==$revision||
            !is_string($source['html']??null)||!is_string($source['content_sha256']??null)||
            !hash_equals($record['html_sha256'],$source['content_sha256'])||
            !hash_equals($source['content_sha256'],hash('sha256',$source['html'])))return null;
        return ['html'=>$source['html'],'html_sha256'=>$source['content_sha256'],
            'owner_id'=>$owner,'project_id'=>$project,'revision_id'=>$revision];
    }
    /** Publication transition uses actual atomic writer; independent authority callback MUST be server-owned. */
    public static function transition(object $store,string $op,array $command,callable $authority,int $now):array {
        return O01AtomicPaidRoute::transition($store,$op,$command,$authority,$now,
            $op==='publish'?[self::class,'prepareChildren']:null,
            $op==='publish'?[self::class,'prepareParent']:null);
    }
    /** No anonymous G1 calls. Host must inject read-only committed route loader and independently admitted parent revision+authority callbacks. */
    public static function deliver(string $slug,string $path,int $now,callable $routeLoad,callable $committedRevision,callable $authority,?string $password=null):array {
        $childLoad=static fn(array $route)=>O01AtomicChildSnapshot::loadFromCommittedRoute($route);
        $passwordCheck=static function(array $route,array $child,string $candidate):bool {
            $revision=$route['revision_id']??null;
            if(!is_int($revision)||$revision<1||($child['path']??null)!=='private'||!is_string($child['password_hash']??null))return false;
            // G1 is NEVER consulted here; verification applies to the committed hash and a server-side attempt ledger.
            return O01SubpageAccessGate::checkPassword($revision,'private',$child['password_hash'],$candidate);
        };
        return O01PublicSubpageRoute::resolve($slug,$path,$now,$routeLoad,$committedRevision,$authority,$childLoad,$password,$passwordCheck);
    }
}
