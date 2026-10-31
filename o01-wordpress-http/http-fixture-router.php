<?php
declare(strict_types=1);
// LOCAL LOOPBACK TEST ROUTER. No production hooks or routes.
require_once __DIR__.'/http-fixture-common.php';
header('Cache-Control: no-store');
function reply(int $status,array $data):never {http_response_code($status);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$d=http_db();
if($path==='/health')reply(200,['disposable'=>true]);
if(preg_match('~\A/page/([a-z0-9-]+)\z~D',$path,$match)){
 $slug=$match[1];$q=$d->prepare('SELECT binding_json FROM o01_mapping_candidate WHERE slug=?');$q->execute([$slug]);$raw=$q->fetchColumn();
 if(!is_string($raw)){http_response_code(404);exit;}$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 try{
  $publication=fixture_publication($d,$m);$expected=synthetic_ids($m,time())+['slug'=>$slug,'request_key'=>'http-request-'.$m['binding_record_id'],'record'=>$publication];
  $verified=O01PublicationReconciler::inspect(http_db(),'o01_integrated_',$expected,time());
  $authority=static function()use($verified,$publication){
   if(($verified['result']??null)!=='COMMITTED_LOCAL_CANDIDATE')return [];
   return ['owner'=>true,'reservation'=>true,'consent'=>true,'payment'=>true,'publication'=>true,'receipts'=>true,'consent_sha256'=>$publication['consent_sha256'],'payment_sha256'=>$publication['payment_sha256'],'publication_sha256'=>$publication['publication_sha256']];
  };
  $r=(new O01UninstalledWordPressDeliveryEndpoint($d,'o01_integrated_',$authority))->resolve($slug,null,time());
  http_response_code($r['status']);header('Content-Type: text/html; charset=UTF-8');echo $r['body'];exit;
 }catch(Throwable $e){http_response_code(503);exit;}
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')reply(405,['code'=>'POST_ONLY']);
if($path==='/login'){
 $u=wp_signon(['user_login'=>(string)($_POST['username']??''),'user_password'=>(string)($_POST['password']??''),'remember'=>false],false);
 if(is_wp_error($u))reply(401,['code'=>'LOGIN_FAILED']);reply(200,['code'=>'SIGNED_IN']);
}
if(!is_user_logged_in()||!wp_get_session_token())reply(401,['code'=>'AUTH_REQUIRED']);
try{$m=mapping($d,(string)($_POST['project_id']??''));}catch(Throwable $e){reply(404,['code'=>'NOT_FOUND']);}
if($m['owner_id']!==get_current_user_id())reply(403,['code'=>'NOT_OWNER']);
$action='o01_approve_'.$m['approval_project_id'].'_'.$m['approval_revision_id'];
$publishAction='o01_publish_'.$m['approval_project_id'].'_'.$m['approval_revision_id'];
if($path==='/nonce')reply(200,['approval_nonce'=>wp_create_nonce($action),'publish_nonce'=>wp_create_nonce($publishAction)]);
if($path==='/preview'){
 $s=pr_iw_g2_v1_revision($m['project_id'],$m['revision_id'],get_current_user_id());
 if(!is_array($s))reply(409,['code'=>'PREVIEW_STALE']);header('Content-Type: text/html; charset=UTF-8');echo $s['html'];exit;
}
if($path==='/approve'){
 $loader=new O01PersistedIdentity($d,'wp_','o01_mapping_candidate');
 $reload=static fn($now)=>$loader->load(get_current_user_id(),$m['project_id'],$m['revision_id'],$m['reservation_id'],$m['slug'],1,$now);
 $store=new O01IdentityLockedStore($d,new O01WPMySQLPaidRouteStore($d,'o01_integrated_'),$m['project_id'],$m['slug'],$reload,static fn()=>time());
 try{
  $store->begin($m['slug'],'approval-'.$m['binding_record_id']);$s=$store->snapshot();
  $request=[];foreach(['project_id','revision_id','html_sha256','quote_sha256','nonce'] as $key)$request[$key]=(string)($_POST[$key]??'');
  $wpVerify=o01_wp_verifier_v31($request);
  $verify=static function($claim,$locked,$once)use($wpVerify,$s,$m):array {
   $compared=O01CanonicalQuoteBinding::compare($m['canonical_quote'],$s['source_bytes'],$m['manifest_sha256'],['amount_minor'=>$locked['amount_minor'],'currency'=>$locked['currency'],'term'=>$locked['term'],'canonical_revision'=>$m['canonical_revision'],'customer_id'=>$m['customer_id']]);
   return ($compared['ok']??false)?$wpVerify($claim,$locked,$once):['ok'=>false,'code'=>'QUOTE_CHANGED'];
  };
  $result=(new O01CallerOwnedApproval($d))->verifyAndClaim(['project_id'=>$request['project_id'],'revision_id'=>$request['revision_id']],$verify);
  if(($result['ok']??false)!==true){$store->rollback();reply(409,['code'=>'APPROVAL_DENIED']);}
  if(!$store->commit())reply(503,['code'=>'APPROVAL_COMMIT_UNCONFIRMED']);reply(200,['code'=>'COMMITTED_APPROVAL']);
 }catch(Throwable $e){try{$store->rollback();}catch(Throwable $ignored){}reply(409,['code'=>'APPROVAL_DENIED']);}
}
if($path==='/publish'){
 if(wp_verify_nonce((string)($_POST['nonce']??''),$publishAction)!==1)reply(403,['code'=>'PUBLISH_NONCE_DENIED']);
 try{
  $publication=fixture_publication($d,$m);
  $receipts=new O01ReceiptTransaction(static fn($a,$p,$c,$h,$now)=>synthetic_ids($m,$now));
  $source=static fn($s,$now)=>fixture_publication($d,$s['mapping']);
  $verifier=static fn(...$ignored)=>$publication;
  $r=O01PersistedPublisher::publish($d,'wp_','o01_mapping_candidate','o01_integrated_',$m['project_id'],$m['revision_id'],$m['reservation_id'],$m['slug'],1,'http-request-'.$m['binding_record_id'],[],[],[],$receipts,$source,$verifier,static fn()=>time());
  reply(in_array($r['result']??null,['COMMITTED_LOCAL_CANDIDATE','REPLAY'],true)?200:409,$r);
 }catch(Throwable $e){reply(409,['code'=>'PUBLICATION_DENIED']);}
}
reply(404,['code'=>'NOT_FOUND']);
