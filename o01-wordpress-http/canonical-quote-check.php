<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require_once __DIR__.'/quote-source/o01-core.php';
require_once __DIR__.'/O01CanonicalQuoteBinding.php';
function quote_check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$p=['customer_id'=>'fixture-customer','offer_id'=>'fixture-offer','price_cents'=>4900,'currency'=>'USD','page_count'=>1,'term'=>'1_month','privacy'=>'public','slug'=>'fixture-page','revision'=>1,'expires_at_utc'=>'2027-02-28T00:00:00Z','sanitized_html'=>'<p>Fixture café</p>','css'=>O01_DN17_CSS,'asset_manifest'=>[]];
$m=o01_manifest($p);$bytes=o01_render($p);
$a=['amount_minor'=>4900,'currency'=>'USD','term'=>'one_month','canonical_revision'=>1,'customer_id'=>'fixture-customer'];
quote_check(O01CanonicalQuoteBinding::compare($p,$bytes,$m['manifest_sha256'],$a)['ok'],'original manifest and approved bytes match canonical tuple');
quote_check(o01_canon(['b'=>2,'a'=>1])===o01_canon(['a'=>1,'b'=>2]),'original top-level key sorting retained');
quote_check(o01_canon(['nested'=>['b'=>2,'a'=>1]])!==o01_canon(['nested'=>['a'=>1,'b'=>2]]),'original nested key order retained without recursive rewrite');
foreach(['price_cents'=>7900,'currency'=>'EUR','page_count'=>2,'term'=>'1_year','privacy'=>'password_protected','slug'=>'changed-page','revision'=>2,'expires_at_utc'=>'2027-03-01T00:00:00Z','sanitized_html'=>'<p>Changed</p>','css'=>'body{}','asset_manifest'=>['logo'=>'changed'],'customer_id'=>'other-customer','offer_id'=>'other-offer']as$key=>$value){$bad=$p;$bad[$key]=$value;quote_check(!O01CanonicalQuoteBinding::compare($bad,$bytes,$m['manifest_sha256'],$a)['ok'],'changed canonical '.$key.' denied');}
quote_check(!O01CanonicalQuoteBinding::compare($p,$bytes.' ',$m['manifest_sha256'],$a)['ok'],'one approved preview byte change denied');
quote_check(!O01CanonicalQuoteBinding::compare($p,$bytes,str_repeat('a',64),$a)['ok'],'wrong frozen manifest digest denied');
foreach(['amount_minor'=>7900,'currency'=>'EUR','term'=>'one_year','canonical_revision'=>2,'customer_id'=>'other-customer']as$key=>$value){$bad=$a;$bad[$key]=$value;quote_check(!O01CanonicalQuoteBinding::compare($p,$bytes,$m['manifest_sha256'],$bad)['ok'],'mismatched approved '.$key.' denied');}
foreach(['1_week'=>'one_week','1_month'=>'one_month','1_year'=>'one_year']as$from=>$to){$c=$p;$c['term']=$from;$b=$a;$b['term']=$to;$mm=o01_manifest($c);quote_check(O01CanonicalQuoteBinding::compare($c,o01_render($c),$mm['manifest_sha256'],$b)['ok'],'explicit term mapping '.$from.' to '.$to);}
echo "SKIP live approval issuer and Stripe receipt verification\n";
echo "SKIP production quote_sha256-to-manifest mapping and canonical G1 identity binding\n";
echo "ORIGINAL_CANONICAL_QUOTE_COMPARISON_ONLY\n";
