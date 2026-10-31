<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
define('ABSPATH',__DIR__.'/');
// Source verifier registers WP filters; this CLI fixture never invokes WP hooks.
function add_filter(...$args):void {}
require_once __DIR__.'/receipt-source/o01-receipt-admission-dn25.php';
require_once __DIR__.'/O01ReceiptTransaction.php';
function db():PDO {return new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
function ok(bool $v,string $label):void {if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$d=db();$fresh=db();
$d->exec('CREATE TABLE o01_receipt_candidate_ledger(manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,customer_id VARCHAR(180) NOT NULL,approval_issuer VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,consent_ref VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payment_provider VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,event_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payment_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,admitted_at BIGINT NOT NULL,UNIQUE KEY consent_once(approval_issuer,consent_ref),UNIQUE KEY event_once(payment_provider,event_id),UNIQUE KEY payment_once(payment_provider,payment_id)) ENGINE=InnoDB');
$d->exec('CREATE TABLE o01_receipt_candidate_routes(slug VARCHAR(63) PRIMARY KEY,body LONGTEXT NOT NULL) ENGINE=InnoDB');
$now=1800000000;$manifest=hash('sha256','SYNTHETIC_CANONICAL_CONTEXT');
$base=['manifest_sha256'=>$manifest,'customer_id'=>'fixture-customer','approval_issuer'=>'fixture-issuer','consent_ref'=>'consent-base','payment_provider'=>'stripe_live','event_id'=>'evt-base','payment_id'=>'pi-base','timestamp'=>$now];
$locked=['manifest_sha256'=>$manifest,'customer_id'=>'fixture-customer'];
$count=static fn(PDO $c,string $t)=>(int)$c->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();
$run=static function(O01ReceiptTransaction $adapter,string $slug,array $context,bool $fail=false)use($d,$now):bool {
 $d->beginTransaction();try {
  $adapter->reserve($d,[],[],[],[],$now,$context);
  if($fail&&$slug==='fail-before-route')throw new RuntimeException('injected');
  $q=$d->prepare('INSERT INTO o01_receipt_candidate_routes VALUES(?,?)');$q->execute([$slug,'SYNTHETIC_APPROVED_BYTES']);
  if($fail)throw new RuntimeException('injected');
  $d->commit();return true;
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();return false;}
};
try {
 $real=new O01ReceiptTransaction();
 ok(!$run($real,'unconfigured',$locked),'original verifier denies absent admitted trust configuration');
 ok($count($fresh,'o01_receipt_candidate_ledger')===0&&$count($fresh,'o01_receipt_candidate_routes')===0,'unconfigured verifier leaves no ledger or route');
 $synthetic=new O01ReceiptTransaction(static fn(...$args)=>$base);
 try{$synthetic->reserve($d,[],[],[],[],$now,$locked);$denied=false;}catch(RuntimeException $e){$denied=$e->getMessage()==='caller_transaction_required';}
 ok($denied,'reserve requires existing caller transaction');
 $d->beginTransaction();$synthetic->reserve($d,[],[],[],[],$now,$locked);
 ok($d->inTransaction(),'reservation leaves caller transaction open');
 ok($count($fresh,'o01_receipt_candidate_ledger')===0,'uncommitted reservation invisible to independent connection');
 $d->rollBack();ok($count($fresh,'o01_receipt_candidate_ledger')===0,'caller rollback removes reservation');
 foreach(['manifest_sha256','customer_id'] as $field){$bad=$locked;$bad[$field]='wrong';ok(!$run($synthetic,'wrong-'.$field,$bad),'locked '.$field.' mismatch denied');}
 ok($run($synthetic,'committed',$locked),'synthetic reservation and fixture route commit together');
 ok($count($fresh,'o01_receipt_candidate_ledger')===1&&$count($fresh,'o01_receipt_candidate_routes')===1,'independent connection sees committed ledger and fixture route');
 foreach(['event_id','payment_id','consent_ref'] as $field){
  $ids=$base;foreach(['event_id','payment_id','consent_ref'] as $key)if($key!==$field)$ids[$key].='-new-'.$field;
  $adapter=new O01ReceiptTransaction(static fn(...$args)=>$ids);
  ok(!$run($adapter,'repeat-'.$field,$locked),'duplicate '.$field.' rejected by real unique constraint');
  ok($count($fresh,'o01_receipt_candidate_ledger')===1&&$count($fresh,'o01_receipt_candidate_routes')===1,'duplicate '.$field.' creates no partial writes');
 }
 foreach(['fail-before-route','fail-after-route'] as $slug){
  $before=$count($fresh,'o01_receipt_candidate_ledger');
  $ids=$base;foreach(['event_id','payment_id','consent_ref'] as $key)$ids[$key].='-'.$slug;
  $adapter=new O01ReceiptTransaction(static fn(...$args)=>$ids);
  ok(!$run($adapter,$slug,$locked,true),'injected failure '.$slug.' rejected');
  ok($count($fresh,'o01_receipt_candidate_ledger')===$before&&$count($fresh,'o01_receipt_candidate_routes')===$before,'injected failure '.$slug.' rolls back ledger and route');
  ok($run($adapter,'retry-'.$slug,$locked),'rolled-back receipt reservation reusable after '.$slug);
 }
 ok(!$d->inTransaction(),'all caller transactions closed');
}finally{if($d->inTransaction())$d->rollBack();$d->exec('DROP TABLE o01_receipt_candidate_routes');$d->exec('DROP TABLE o01_receipt_candidate_ledger');}
ok((int)$fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('o01_receipt_candidate_routes','o01_receipt_candidate_ledger')")->fetchColumn()===0,'disposable ledger and route tables removed');
echo "SKIP genuine signed approval and Stripe event success\nSKIP existing publisher adapter integration, production digest and identity mapping\nRECEIPT_TRANSACTION_SYNTHETIC_FIXTURE_ONLY\n";
