<?php
declare(strict_types=1);
require_once __DIR__.'/O01PaidCalendarTerms.php';
/** SOURCE ONLY; no WordPress hooks. Receipt verifiers MUST be installed server-side and trusted separately. */
final class O01G1G2AuthorityProducer {
 private static function validHash($x):bool{return is_string($x)&&preg_match('/\A[a-f0-9]{64}\z/D',$x)===1;}
 /** $receipts callbacks are server-injected, independently trusted, not request body data. */
 public static function produce(string $op,array $command,?array $current,int $now,array $receipts):?array {
   foreach(['pr_iw_g1_v2_owner','pr_iw_g1_v2_read_revision','pr_iw_g2_v1_reservation','pr_iw_g2_v1_revision','pr_iw_g2_v1_normalize_slug'] as $fn)
     if(!function_exists($fn))return null;
   foreach(['identity','consent','payment','publication'] as $key)
     if(!isset($receipts[$key])||!is_callable($receipts[$key]))return null;
   try {
     // Identity provider itself is trusted only after host admission; caller input supplies no IDs.
     $ids=$receipts['identity']($op,$command,$current,$now);
     if(!is_array($ids))return null;
     foreach(['owner_id','project_id','revision_id','reservation_id'] as $key)
       if(!isset($ids[$key])||!is_int($ids[$key])||$ids[$key]<1)return null;
     ['owner_id'=>$owner,'project_id'=>$project,'revision_id'=>$rev,'reservation_id'=>$reservation]=$ids;
     if(pr_iw_g1_v2_owner($project,$owner)!==true)return null;
     $g1=pr_iw_g1_v2_read_revision($project,$rev,$owner);
     $g2=pr_iw_g2_v1_reservation($reservation,$project,$owner);
     if(!is_array($g1)||!is_array($g2)||!is_string($g1['html']??null)||
       !self::validHash($g1['content_sha256']??null)||
       !hash_equals($g1['content_sha256'],hash('sha256',$g1['html'])))return null;
     $slug=$command['slug']??null;
     if(!is_string($slug)||pr_iw_g2_v1_normalize_slug($slug)!==$slug||($g2['slug']??null)!==$slug||
       (int)($g2['revision_id']??0)!==$rev||($g2['reservation_id']??null)!==$reservation||
       ($g2['project_id']??null)!==$project||($g2['owner_id']??null)!==$owner||
       !self::validHash($g2['revision_sha256']??null)||
       !hash_equals($g2['revision_sha256'],$g1['content_sha256']))return null;
     if($op==='publish'){
       if(($g2['state']??null)!=='reserved'||(int)($g2['expires_at']??0)<=$now)return null;
       $latest=pr_iw_g2_v1_revision($project,$rev,$owner);
       if(!is_array($latest)||($latest['content_sha256']??null)!==$g1['content_sha256'])return null;
     }
     if($op==='withdraw'){
       if(!is_array($current)||($current['revision_sha256']??null)!==$g1['content_sha256'])return null;
       foreach(['owner_id','project_id','revision_id','reservation_id'] as $key)
         if(($current[$key]??null)!==$ids[$key])return null;
     }
     $verified=[];
     foreach(['consent','payment','publication'] as $type){
       $receipt=$receipts[$type]($op,$ids,$slug,$g1['content_sha256'],$now,$current);
       if(!is_array($receipt)||($receipt['verified']??null)!==true||
         !self::validHash($receipt['sha256']??null)||($receipt['owner_id']??null)!==$owner||
         ($receipt['project_id']??null)!==$project||($receipt['revision_id']??null)!==$rev||
         ($receipt['reservation_id']??null)!==$reservation||
         ($receipt['revision_sha256']??null)!==$g1['content_sha256']||
         ($receipt['slug']??null)!==$slug)return null;
       $verified[$type]=$receipt;
     }
     $pub=$verified['publication'];
     if($op==='publish'){
       foreach(['started_at','expires_at'] as $k)if(!is_int($pub[$k]??null))return null;
       if(!is_string($pub['term']??null)||!O01PaidCalendarTerms::valid($pub['term'],$pub['started_at'],$pub['expires_at'])||
         $pub['started_at']>$now||$pub['expires_at']<=$now)return null;
       // Duration is descriptive only; a calendar month/year is not fixed seconds.
       $pub['term_seconds']=$pub['expires_at']-$pub['started_at'];
     } elseif(($pub['withdraw_authorized']??null)!==true)return null;
     return $ids+['verified'=>true,'slug'=>$slug,'revision_sha256'=>$g1['content_sha256'],
       'html_sha256'=>$g1['content_sha256'],
       'consent_sha256'=>$verified['consent']['sha256'],
       'payment_sha256'=>$verified['payment']['sha256'],
       'publication_sha256'=>$pub['sha256'],
       'authority_receipt_sha256'=>hash('sha256',json_encode([$ids,$verified,$g1['content_sha256']],JSON_THROW_ON_ERROR)),
       'started_at'=>$pub['started_at']??0,'expires_at'=>$pub['expires_at']??0,
       'term_seconds'=>$pub['term_seconds']??0,'term'=>$pub['term']??null,'withdraw_authorized'=>$pub['withdraw_authorized']??false];
   }catch(Throwable $e){return null;}
 }
 public static function callback(array $serverReceiptProviders):callable {
   return static fn(string $op,array $command,?array $current,int $now)=>self::produce($op,$command,$current,$now,$serverReceiptProviders);
 }
}
