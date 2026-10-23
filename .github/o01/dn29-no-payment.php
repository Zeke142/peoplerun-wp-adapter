<?php
declare(strict_types=1);
// Real WordPress only. No fake DB, issuer, payment signature or trust activation.
const DN29_SHA='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871';
function require_check(bool $ok,string $name): void {if(!$ok){fwrite(STDERR,"FAIL $name\n");throw new RuntimeException($name);}echo "PASS $name\n";}
require '/work/wp/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
global $wpdb;
require_check($wpdb->get_var('SELECT DATABASE()')==='o01_engineering','exact isolated database');
require_check(!defined('O01_DN21_AUTHORITY_ADMITTED')||O01_DN21_AUTHORITY_ADMITTED!==true,'authority remains unadmitted');
foreach(['O01_DN20_APPROVAL_VERIFIER_KEY','O01_DN20_STRIPE_WEBHOOK_SECRET'] as $key)
    require_check(!defined($key)&&getenv($key)===false,'no verifier secret '.$key);
$phase=$argv[1]??'';
if($phase==='activate') {
    $stage=json_decode(file_get_contents('/run/o01-dn29-staged.json'),true,512,JSON_THROW_ON_ERROR);
    require_check($stage['archive_sha256']===DN29_SHA,'staged source pin');
    foreach($stage['files'] as $name=>$sha)require_check(hash_file('sha256',WP_PLUGIN_DIR.'/o01-dn29/'.basename($name))===$sha,'source '.$name);
    $error=activate_plugin('o01-dn29/o01-dn29-scoped-publisher.php','',false,false);
    require_check(!is_wp_error($error),'DN29 staging-only schema activation');
    exit;
}
$proof=json_decode(file_get_contents('/run/o01-rollback-proof.json'),true,512,JSON_THROW_ON_ERROR);
require_check(($proof['status']??null)==='VERIFIED'&&($proof['source_sha256']??null)===DN29_SHA&&($proof['run_binding']??null)===getenv('O01_RUN_BINDING'),'DN29 rollback readback bound to this run');
require_check(is_plugin_active('o01-dn29/o01-dn29-scoped-publisher.php'),'DN29 plugin active');
$stage=json_decode(file_get_contents('/run/o01-dn29-staged.json'),true,512,JSON_THROW_ON_ERROR);
foreach($stage['files'] as $name=>$sha)require_check(hash_file('sha256',WP_PLUGIN_DIR.'/o01-dn29/'.basename($name))===$sha,'restored source '.$name);
require_check((new ReflectionFunction('o01_dn25_publish'))->getFileName()===WP_PLUGIN_DIR.'/o01-dn29/o01-dn29-scoped-publisher.php','publisher function belongs to DN29');
$server=rest_get_server();$routes=$server->get_routes();
require_check(isset($routes['/peoplerun-o01-dn29/v1/publish']),'NP01 DN29 route present');
foreach(['dn25','dn22'] as $old)require_check(!isset($routes['/peoplerun-o01-'.$old.'/v1/publish']),'NP01 legacy '.$old.' route absent');
require_check(o01_dn22_engine_verified($wpdb),'NP01 real InnoDB pairing');
$releases=o01_dn22_release_table();$receipts=o01_dn22_ledger_table();
require_check($releases==='o01s_pr_o01_dn22_releases'&&$receipts==='o01s_pr_o01_dn22_receipts','exact staging table binding');
function counts(): array {global $wpdb,$releases,$receipts;return [(int)$wpdb->get_var("SELECT COUNT(*) FROM `$releases`"),(int)$wpdb->get_var("SELECT COUNT(*) FROM `$receipts`")];}
function request_as(int $user,array $payload) {wp_set_current_user($user);$r=new WP_REST_Request('POST','/peoplerun-o01-dn29/v1/publish');$r->set_header('content-type','application/json');$r->set_body(json_encode($payload,JSON_THROW_ON_ERROR));return rest_do_request($r);}
$before=counts();require_check($before===[0,0],'clean release and receipt ledgers');
if($phase==='verify') {echo "O01_DN29_SOURCE_BOUND_ROLLBACK_AND_ACTIVATION_READBACK_PASS\n";exit;}
if($phase==='denials') {
    $r=request_as(0,[]);require_check(in_array($r->get_status(),[401,403],true)&&counts()===$before,'NP02 anonymous denied without publisher row changes');
    foreach([[],['status'=>'approved','paid'=>true],['status'=>'QUARANTINED_DENY_PUBLISH','publish_authorized'=>false],['review_email'=>'UNSIGNED ENGINEERING INPUT']] as $payload){
        $r=request_as(1,$payload);$d=$r->get_data();
        require_check($r->get_status()===409&&($d['message']??null)==='unsigned_or_untrusted_review_denied'&&counts()===$before,'NP03 actual guarded callback denial');
    }
    $now=time();$studio=['schema'=>'peoplerun.o01.e2e.local.v1','notice'=>'SYNTHETIC_LOCAL_ONLY','project'=>['id'=>'engineering','owner'=>'ENGINEERING_FIXTURE_NOT_CUSTOMER','business'=>'Engineering Fixture','description'=>'No customer or purchase','slug'=>'engineering-denial','theme'=>'blue','revision'=>1,'html'=>'<p>Engineering</p>']];
    $offer=['customer_id'=>'engineering','offer_id'=>'engineering','price_cents'=>1,'currency'=>'USD','term'=>'1_week','expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600)];
    $h=o01_dn24_build($studio,$offer);$c=$h['candidate'];$m=$h['publisher_manifest'];$scope=$h['approval_scope']['approval_scope_sha256'];
    $payload=['candidate'=>$c,'studio_snapshot'=>$studio,'offer'=>$offer,'approval'=>['status'=>'approved','customer_id'=>'engineering','manifest_sha256'=>$m['manifest_sha256'],'approval_scope_sha256'=>$scope,'approval_proof'=>['schema'=>'o01-customer-approval-scope-v2','issuer'=>'INVALID_ENGINEERING_INPUT','customer_id'=>'engineering','manifest_sha256'=>$m['manifest_sha256'],'approval_scope_sha256'=>$scope,'consent_ref'=>'INVALID','approved_at'=>$now,'expires_at'=>$now+60,'signature'=>str_repeat('0',64)]],'payment'=>['status'=>'completed','customer_id'=>'engineering','offer_id'=>'engineering','price_cents'=>1,'currency'=>'USD','term'=>'1_week','revision'=>1,'manifest_sha256'=>$m['manifest_sha256'],'stripe_proof'=>['body'=>'{}','signature'=>'INVALID_ENGINEERING_INPUT']]];
    require_check(O01DN29Boundary::admissiblePublisherEnvelope($payload),'NP04 structured invalid input reaches verifier boundary');
    $r=request_as(1,$payload);require_check($r->get_status()===409&&counts()===$before,'NP04 untrusted proof denied; no payment verification claimed');
    file_put_contents('/run/o01-denials-pass.json',json_encode(['run_binding'=>getenv('O01_RUN_BINDING'),'source_sha256'=>DN29_SHA,'rollback_archive_sha256'=>$proof['archive_sha256']],JSON_THROW_ON_ERROR));
    echo "O01_DN29_DENIAL_PHASE_PASS\n";exit;
}
if($phase==='faults') {
    // This phase is never invoked by the preparation/admission workflow.
    require_check(getenv('O01_ALLOW_FAULT_TESTS')==='1','explicitly selected fault phase');
    $denials=json_decode(file_get_contents('/run/o01-denials-pass.json'),true,512,JSON_THROW_ON_ERROR);
    require_check(($denials['run_binding']??null)===getenv('O01_RUN_BINDING')&&($denials['source_sha256']??null)===DN29_SHA&&($denials['rollback_archive_sha256']??null)===$proof['archive_sha256'],'same-checkpoint denials passed before SQL fault phase');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $a=$wpdb->dbh;$b=new mysqli('localhost',DB_USER,DB_PASSWORD,DB_NAME,0,'/run/db.sock');
    $lock='o01_engineering_'.substr(hash('sha256',getenv('O01_RUN_BINDING')),0,20);
    require_check((string)$a->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0]==='1','NP05 session A lock');
    require_check((string)$b->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0]==='0','NP05 session B contention');
    $a->query("SELECT RELEASE_LOCK('$lock')");
    require_check((string)$b->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0]==='1','NP05 released lock reusable');$b->query("SELECT RELEASE_LOCK('$lock')");
    $table='o01s_engineering_tx_probe';
    require_check((int)$a->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetch_row()[0]===0,'probe table absent before create');
    $a->query("CREATE TABLE `$table` (id INT PRIMARY KEY) ENGINE=InnoDB");
    try {
        $a->begin_transaction();$a->query("INSERT INTO `$table` VALUES(1)");
        require_check((int)$b->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'NP06 uncommitted row invisible to independent observer');
        $a->rollback();require_check((int)$b->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'NP06 explicit rollback readback');
        $a->begin_transaction();$a->query("INSERT INTO `$table` VALUES(1)");$a->commit();
        require_check((int)$b->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===1,'NP06 commit readback');
        $duplicate=false;try{$b->query("INSERT INTO `$table` VALUES(1)");}catch(mysqli_sql_exception $e){$duplicate=$e->getCode()===1062;}
        require_check($duplicate,'NP06 unique-key enforcement');
        $b->query("DELETE FROM `$table`");$a->begin_transaction();$a->query("INSERT INTO `$table` VALUES(2)");$a->close();
        require_check((int)$b->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0]===0,'NP06 owned connection interruption rollback');
    } finally {$b->query("DROP TABLE `$table`");$b->close();}
    echo "O01_SQL_PRIMITIVE_FAULT_PHASE_PASS_NOT_DN29_COMMIT_PROOF\n";exit;
}
if($phase==='reader') {
    require_check(getenv('O01_ALLOW_READER_FIXTURES')==='1','explicitly selected reader fixture phase');
    $now=time();$c=['customer_id'=>'ENGINEERING_FIXTURE_NOT_CUSTOMER','offer_id'=>'engineering','price_cents'=>1,'currency'=>'USD','page_count'=>1,'term'=>'1_week','privacy'=>'public','slug'=>'engineering-reader-fixture','revision'=>1,'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+600),'sanitized_html'=>'<p>ENGINEERING_FIXTURE_NOT_CUSTOMER</p>','css'=>O01_DN17_CSS,'asset_manifest'=>[]];
    $m=o01_manifest($c);$html=o01_render($c);
    try {
        require_check($wpdb->insert($releases,['slug'=>$m['slug'],'manifest_sha256'=>$m['manifest_sha256'],'render_sha256'=>$m['render_sha256'],'render_html'=>$html,'manifest_json'=>o01_canon($m),'expires_at_utc'=>gmdate('Y-m-d H:i:s',$now+600),'created_at_utc'=>gmdate('Y-m-d H:i:s')])===1,'NP07 labelled synthetic reader row inserted directly');
        $r=o01_dn22_delivery($m['slug'],$now);require_check($r['status']===200&&hash_equals(hash('sha256',$html),hash('sha256',$r['body'])),'NP07 exact reader bytes');
        $url='http://127.0.0.1:8080/?o01_dn22_page='.rawurlencode($m['slug']);
        $ctx=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]);
        $body=file_get_contents($url,false,$ctx);$headers=$http_response_header??[];
        require_check(is_string($body)&&hash_equals(hash('sha256',$html),hash('sha256',$body))&&str_contains($headers[0]??'',' 200 '),'NP07 actual loopback HTTP exact fixture bytes');
        require_check((bool)array_filter($headers,static fn($h)=>stripos($h,'Cache-Control:')===0&&stripos($h,'no-store')!==false),'NP07 actual HTTP no-store header');
        require_check(o01_dn22_delivery($m['slug'],$now+600)['status']===410,'NP07 expiry without clock change');
        $expired=$c;$expired['expires_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$now-1);$em=o01_manifest($expired);
        $wpdb->update($releases,['manifest_sha256'=>$em['manifest_sha256'],'manifest_json'=>o01_canon($em),'expires_at_utc'=>gmdate('Y-m-d H:i:s',$now-1)],['slug'=>$m['slug']]);
        file_get_contents($url,false,$ctx);require_check(str_contains(($http_response_header??[])[0]??'',' 410 '),'NP07 actual HTTP expired fixture denied');
        $wpdb->update($releases,['render_html'=>'CORRUPTED_ENGINEERING_FIXTURE'],['slug'=>$m['slug']]);
        require_check(o01_dn22_delivery($m['slug'],$now)['status']===503,'NP07 corrupt reader data denied');
        require_check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$receipts`")===0,'NP07 no invented payment receipt');
    } finally {$wpdb->delete($releases,['slug'=>$m['slug']]);}
    echo "O01_READER_FIXTURE_PHASE_PASS_NOT_PUBLICATION_PROOF\n";exit;
}
throw new RuntimeException('Unknown no-payment phase');
