<?php
declare(strict_types=1);
require_once __DIR__.'/PersistedRouteReader.php';
require_once __DIR__.'/O01SubpageDelivery.php';
/** Uninstalled source-level publication reader. The four callbacks MUST be server-owned:
 * route load, immutable published revision snapshot, receipt authority, committed child snapshot.
 * No caller-provided authorization flags and NO owner-only G1 API are used here.
 */
final class O01PublicSubpageRoute {
    private static function deny(int $s,string $r): array {return ['status'=>$s,'body'=>'','reason'=>$r,'published'=>false];}
    public static function resolve(string $slug,string $path,int $now,callable $load,callable $revision,callable $authority,callable $childLoad,?string $password=null,?callable $passwordCheck=null):array {
        if (!in_array($path,['about','private'],true))return self::deny(404,'unknown_subpage');
        // Parent authority, expiry and withdrawal are determined by the EXISTING paid route reader.
        // Freeze one route observation for parent/child decisions; never mix two reads.
        try {
            $route=$load($slug);
            $oneLoad=static fn(string $requested)=>$requested===$slug?$route:null;
            $parent=O01PersistedRouteReader::resolve($slug,$now,$oneLoad,$revision,$authority);
            if (($parent['status']??null)!==200) return $parent;
            if(!is_array($route)||($route['slug']??null)!==$slug || ($route['state']??null)!=='published') return self::deny(503,'route_binding_unavailable');
            foreach(['project_id','revision_id','owner_id'] as $k)if(!is_int($route[$k]??null)||$route[$k]<1)return self::deny(503,'route_binding_unavailable');
            $sha=$route['revision_sha256']??null;
            if(!is_string($sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$sha))return self::deny(503,'route_binding_unavailable');
            // Snapshot must be admitted for THIS committed route and revision, not live owner-scoped postmeta.
            $snapshot=$childLoad($route);
            if(!is_array($snapshot)||($snapshot['schema']??null)!=='peoplerun.o01.published_subpages.v1'||
                ($snapshot['slug']??null)!==$slug || ($snapshot['project_id']??null)!==$route['project_id'] ||
                ($snapshot['revision_id']??null)!==$route['revision_id'] ||($snapshot['owner_id']??null)!==$route['owner_id'] ||
                ($snapshot['revision_sha256']??null)!==$sha || ($snapshot['publication_sha256']??null)!==($route['publication_sha256']??null))return self::deny(503,'child_snapshot_binding_invalid');
            $child=$snapshot['pages'][$path]??null;
            // Require explicit non-forgeable child exact-byte binding from committed publication snapshot.
            if(!is_array($child))return self::deny(404,'subpage_not_found');
            if(($child['revision_sha256']??null)!==$sha)return self::deny(503,'child_revision_mismatch');
            $verified=false;
            if($path==='private'){
                if($password===null||$passwordCheck===null)return self::deny(401,'password_required');
                // Caller supplies password candidate only; verifier is server-owned and rate-limited.
                $verified=$passwordCheck($route,$child,$password)===true;
            }
            $parent['revision_sha256']=$sha;
            return O01SubpageDelivery::resolve($parent,$child,$path,$verified);
        }catch(Throwable $e){return self::deny(503,'child_snapshot_unavailable');}
    }
}
