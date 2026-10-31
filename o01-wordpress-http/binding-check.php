<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require_once __DIR__.'/O01ApprovalPublicationBinding.php';
function expect(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$html="<main>Exact café preview</main>\r\n";$hash=hash('sha256',$html);
$row=['project_id'=>'test-project','revision_id'=>'revision-1','owner_id'=>5,'html_sha256'=>$hash,'quote_sha256'=>str_repeat('b',64),'amount_minor'=>4900,'currency'=>'USD','term'=>'one_month','status'=>'approved'];
$receipt=str_repeat('c',64);
$v=['identity'=>'wp_user:5','revision_id'=>'revision-1','html_sha256'=>$hash,'receipt_id'=>$receipt,'amount_minor'=>4900,'currency'=>'USD','term'=>'one_month'];
$claim=array_intersect_key($row,array_flip(['project_id','revision_id','owner_id','html_sha256','quote_sha256']));
$claim['receipt_id']=$receipt;$claim['approval_sha256']=hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$mapping=['approval_project_id'=>'test-project','approval_revision_id'=>'revision-1','approval_owner_id'=>5,'owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13];
$candidate=['owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13,'html_sha256'=>$hash,'revision_sha256'=>$hash,'quote_sha256'=>str_repeat('b',64),'amount_minor'=>4900,'currency'=>'USD','term'=>'month'];
$check=static fn($r,$c,$m,$p,$h)=>O01ApprovalPublicationBinding::check($r,$c,$m,$p,$h);
expect($check($row,$claim,$mapping,$candidate,$html)['ok'],'matching synthetic approved tuple');
foreach(['amount_minor'=>7900,'currency'=>'EUR','term'=>'one_year'] as $key=>$value){$r=$row;$r[$key]=$value;expect(!$check($r,$claim,$mapping,$candidate,$html)['ok'],'changed approved '.$key.' denied');}
$r=$row;$r['amount_minor']='4900';expect(!$check($r,$claim,$mapping,$candidate,$html)['ok'],'changed JSON scalar type fails closed');
foreach(['awaiting_approval','withdrawn'] as $status){$r=$row;$r['status']=$status;expect(!$check($r,$claim,$mapping,$candidate,$html)['ok'],'nonapproved state '.$status.' denied');}
expect(!$check($row,$claim,$mapping,$candidate,$html.' ')['ok'],'altered preview byte denied');
foreach(['project_id','revision_id','owner_id','html_sha256','quote_sha256','receipt_id','approval_sha256'] as $key){$c=$claim;$c[$key]='wrong';expect(!$check($row,$c,$mapping,$candidate,$html)['ok'],'altered claim '.$key.' denied');}
foreach(['project_id','revision_id','reservation_id','owner_id'] as $key){$p=$candidate;$p[$key]++;expect(!$check($row,$claim,$mapping,$p,$html)['ok'],'wrong publication '.$key.' denied');}
foreach(['amount_minor'=>7900,'currency'=>'EUR','term'=>'year','quote_sha256'=>str_repeat('d',64),'html_sha256'=>str_repeat('d',64),'revision_sha256'=>str_repeat('d',64)] as $key=>$value){$p=$candidate;$p[$key]=$value;expect(!$check($row,$claim,$mapping,$p,$html)['ok'],'changed candidate '.$key.' denied');}
$m=$mapping;$m['approval_project_id']='9';expect(!$check($row,$claim,$m,$candidate,$html)['ok'],'implicit project-ID cast denied');
echo "SKIP PDO lock/race integration: requires isolated MySQL run\n";
echo "SKIP production mapping, quote semantics and independent payment/publication receipt verification\n";
echo "BINDING_COMPARISON_FIXTURES_ONLY\n";
