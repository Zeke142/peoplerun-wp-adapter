<?php
declare(strict_types=1);
require_once __DIR__.'/O01SubpageDelivery.php';
require_once __DIR__.'/O01SubpageAccessGate.php';
/** Uninstalled WordPress postmeta adapter. The caller MUST resolve $postId and $parent
 * from trusted server-side G1 state. Neither may be accepted from an HTTP request.
 * G1-specific hook and transaction/lock are NOT established by this source.
 */
final class O01G1SubpageStore {
    private const META = '_o01_subpages_v1';
    public static function save(int $postId,array $g1,string $path,string $html,?string $password=null): bool {
        if ($postId<1 || !in_array($path,['about','private'],true) || !self::trustedRevision($g1,$postId)) return false;
        if ($html===''||strlen($html)>250000) return false;
        if ($path==='private' && ($password===null||strlen($password)<12||strlen($password)>256)) return false;
        if ($path==='about' && $password!==null) return false;
        $revision=$g1['revision_sha256'];
        return O01SubpageAccessGate::locked('subpage-save',$postId,static function() use ($postId,$revision,$path,$html,$password):bool {
        $existing=get_post_meta($postId,self::META,true);
        if ($existing!=='' && (!is_array($existing)||($existing['revision_sha256']??null)!==$revision)) return false;
        $pages=is_array($existing)?($existing['pages']??[]):[];
        if (!is_array($pages)) return false;
        $record=['schema'=>'peoplerun.o01.subpage.v1','path'=>$path,'visibility'=>$path==='private'?'password':'public',
            'revision_sha256'=>$revision,'html'=>$html,'html_sha256'=>hash('sha256',$html)];
        if ($path==='private') $record['password_hash']=password_hash($password,PASSWORD_DEFAULT);
        $pages[$path]=$record;
        // One WP postmeta value is persisted; host integration must supply serialization lock.
        return update_post_meta($postId,self::META,['revision_sha256'=>$revision,'pages'=>$pages])!==false;
        })===true;
    }
    public static function deliver(int $postId,array $g1,array $parent,string $path,?string $candidate=null):array {
        $deny=static fn()=>['status'=>503,'body'=>'','reason'=>'g1_revision_not_authorized','published'=>false];
        if (!self::trustedRevision($g1,$postId)||($parent['revision_sha256']??null)!==$g1['revision_sha256']) return $deny();
        $stored=get_post_meta($postId,self::META,true);
        if (!is_array($stored)||($stored['revision_sha256']??null)!==$g1['revision_sha256']) return $deny();
        $child=$stored['pages'][$path]??null;
        $verified=false;
        if ($path==='private' && is_array($child) && isset($child['password_hash']) && is_string($child['password_hash']) && is_string($candidate)) {
            $verified=O01SubpageAccessGate::checkPassword($postId,$path,$child['password_hash'],$candidate);
        }
        // Fail closed on parent withdrawal/expiry through O01SubpageDelivery's parent result.
        return O01SubpageDelivery::resolve($parent,is_array($child)?$child:null,$path,$verified);
    }
    private static function trustedRevision(array $g1,int $postId):bool {
        return ($g1['post_id']??null)===$postId && is_string($g1['revision_sha256']??null) &&
            preg_match('/\A[a-f0-9]{64}\z/D',$g1['revision_sha256'])===1 && ($g1['source']??null)==='trusted_g1_server';
    }
}
