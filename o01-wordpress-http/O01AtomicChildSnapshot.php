<?php
declare(strict_types=1);
/** Private publication-preparation boundary. No HTTP input and no public G1 reads. */
final class O01AtomicChildSnapshot {
    public static function fromG1(array $record, int $postId, array $g1, callable $getMeta):?array {
        if (($g1['source']??null)!=='trusted_g1_server'||($g1['post_id']??null)!==$postId||
            ($record['revision_id']??null)!==$postId||($g1['revision_sha256']??null)!==($record['revision_sha256']??null))return null;
        $stored=$getMeta($postId,'_o01_subpages_v1');
        if(!is_array($stored)||($stored['revision_sha256']??null)!==$record['revision_sha256'])return null;
        $snapshot=['schema'=>'peoplerun.o01.published_subpages.v1', 'slug'=>$record['slug'],
            'project_id'=>$record['project_id'],'owner_id'=>$record['owner_id'],
            'revision_id'=>$postId,'revision_sha256'=>$record['revision_sha256'],
            'publication_sha256'=>$record['publication_sha256'],'pages'=>$stored['pages']??null];
        return self::valid($snapshot,$record)?$snapshot:null;
    }
    public static function valid(array $snapshot,array $record):bool {
        if(($snapshot['schema']??null)!=='peoplerun.o01.published_subpages.v1')return false;
        foreach(['slug','project_id','owner_id','revision_id','revision_sha256','publication_sha256'] as $k)
            if(($snapshot[$k]??null)!==($record[$k]??null))return false;
        $pages=$snapshot['pages']??null;
        if(!is_array($pages)||count($pages)>2)return false;
        foreach($pages as $path=>$p){
            if(!in_array($path,['about','private'],true)||!is_array($p)||($p['schema']??null)!=='peoplerun.o01.subpage.v1'||($p['path']??null)!==$path||
                ($p['revision_sha256']??null)!==$record['revision_sha256'])return false;
            if(($p['visibility']??null)!==($path==='private'?'password':'public'))return false;
            $html=$p['html']??null;$sha=$p['html_sha256']??null;
            if(!is_string($html)||$html===''||strlen($html)>250000||!is_string($sha)||
                !preg_match('/\A[a-f0-9]{64}\z/D',$sha)||!hash_equals($sha,hash('sha256',$html)))return false;
            if($path==='private' && (!is_string($p['password_hash']??null)||!password_get_info($p['password_hash'])['algo']))return false;
            if($path==='about'&&array_key_exists('password_hash',$p))return false;
        }
        return true;
    }
    /** The paid route record is the committed source; no second child table/read race. */
    public static function loadFromCommittedRoute(array $route):?array {
        $snapshot=$route['subpages_snapshot']??null;
        return is_array($snapshot)&&self::valid($snapshot,$route)?$snapshot:null;
    }
}
