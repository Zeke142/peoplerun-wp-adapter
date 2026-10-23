<?php
declare(strict_types=1);
// CLI harness: every SQL operation delegates to real wpdb/MariaDB.
function ec_assert(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
ec_assert(PHP_SAPI==='cli'&&getenv('O01_ENGINEERING_EXECUTE')==='1','explicit_cli_selection_required');
ec_assert(preg_match('/^Uid:\s+10001\s/m',file_get_contents('/proc/self/status'))===1,'unprivileged_uid_required');
require '/work/wp/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
global $wpdb;
$binding=getenv('O01_RUN_BINDING');
ec_assert(is_string($binding)&&preg_match('/^[0-9]+:[0-9]+$/D',$binding)===1,'run_binding_required');
ec_assert($wpdb->get_var('SELECT DATABASE()')==='o01_engineering','isolated_database_required');
ec_assert(!defined('O01_DN21_AUTHORITY_ADMITTED')||O01_DN21_AUTHORITY_ADMITTED!==true,'authority_must_remain_off');
foreach(['O01_DN20_APPROVAL_VERIFIER_KEY','O01_DN20_STRIPE_WEBHOOK_SECRET'] as $k)ec_assert(!defined($k)&&getenv($k)===false,'no_verifier_secrets');
$pin='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871';
$proof=json_decode(file_get_contents('/run/o01-rollback-proof.json'),true,512,JSON_THROW_ON_ERROR);
$deny=json_decode(file_get_contents('/run/o01-denials-pass.json'),true,512,JSON_THROW_ON_ERROR);
ec_assert(($proof['status']??'')==='VERIFIED'&&($proof['run_binding']??'')===$binding&&($proof['source_sha256']??'')===$pin,'fresh_rollback_required');
ec_assert(($deny['run_binding']??'')===$binding&&($deny['source_sha256']??'')===$pin&&($deny['rollback_archive_sha256']??'')===$proof['archive_sha256'],'same_checkpoint_denials_required');
$stage=json_decode(file_get_contents('/run/o01-dn29-staged.json'),true,512,JSON_THROW_ON_ERROR);
ec_assert(($stage['archive_sha256']??'')===$pin,'staged_pin');
foreach($stage['files'] as $name=>$hash)ec_assert(hash_file('sha256',WP_PLUGIN_DIR.'/o01-dn29/'.basename($name))===$hash,'original_source_changed');
ec_assert(is_plugin_active('o01-dn29/o01-dn29-scoped-publisher.php'),'original_plugin_active');
$routes=rest_get_server()->get_routes();ec_assert(isset($routes['/peoplerun-o01-dn29/v1/publish']),'original_route_missing');
foreach(['dn25','dn22'] as $old)ec_assert(!isset($routes['/peoplerun-o01-'.$old.'/v1/publish']),'legacy_route');
ec_assert(o01_dn25_publish([])['code']==='unsigned_or_untrusted_review_denied','original_denial_regression');
$r=o01_dn22_release_table();$l=o01_dn22_ledger_table();
ec_assert($r==='o01s_pr_o01_dn22_releases'&&$l==='o01s_pr_o01_dn22_receipts','exact_tables');
if(!defined('O01_DN21_LEDGER_TABLE'))define('O01_DN21_LEDGER_TABLE',$l);
ec_assert(O01_DN21_LEDGER_TABLE===$l,'ledger_binding');
$dir=getenv('O01_ENGINEERING_DIR');ec_assert(is_string($dir)&&preg_match('~^/run/o01-eng-[a-f0-9]{12}$~D',$dir)===1&&!is_link($dir),'owned_run_dir');
$lock=json_decode(file_get_contents(__DIR__.'/sources.lock.json'),true,512,JSON_THROW_ON_ERROR);
ec_assert(hash_file('sha256',$dir.'/mechanics.php')===$lock['generated_sha256'],'derived_pin');
require $dir.'/mechanics.php';
function ec_write(string $file,array $data): void {global $dir;file_put_contents($dir.'/'.$file,json_encode($data,JSON_THROW_ON_ERROR));}
function ec_emit(array $data): void {echo 'EC_JSON '.json_encode($data,JSON_THROW_ON_ERROR)."\n";}
function ec_fixture(string $tag): array {
    global $binding;
    ec_assert(preg_match('/^[a-z0-9-]{1,30}$/D',$tag)===1,'fixture_tag');
    $id='eng_'.str_replace(':','_',$binding).'_'.$tag;
    $studio=['schema'=>'peoplerun.o01.e2e.local.v1','notice'=>'SYNTHETIC_LOCAL_ONLY','project'=>['id'=>$id,'owner'=>'ENGINEERING_FIXTURE_NOT_CUSTOMER','business'=>'Engineering Fixture','description'=>'No consent or payment','slug'=>'eng-'.str_replace(':','-',$binding).'-'.$tag,'theme'=>'blue','revision'=>1,'html'=>'<p>ENGINEERING_FIXTURE_NOT_CUSTOMER</p>']];
    $offer=['customer_id'=>$id,'offer_id'=>$id,'price_cents'=>1,'currency'=>'USD','term'=>'1_week','expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)];
    $h=o01_dn24_build($studio,$offer);$m=$h['publisher_manifest'];
    return ['candidate'=>$h['candidate'],'manifest'=>$m,'render'=>$h['final_preview_html'],'ids'=>['manifest_sha256'=>$m['manifest_sha256'],'customer_id'=>$id,'approval_issuer'=>'eng_untrusted','consent_ref'=>$id,'payment_provider'=>'eng_no_payment','event_id'=>$id,'payment_id'=>$id,'timestamp'=>time()]];
}
class EngineeringDB {
    public int $insertErrno=0;
    public function __construct(public $real,public string $fault,public bool $hold){}
    public function prepare(...$a){return $this->real->prepare(...$a);}
    public function get_var(...$a){$v=$this->real->get_var(...$a);if($this->hold&&str_contains($a[0],'GET_LOCK(')&&$v==='1')$this->barrier('locked');return $v;}
    public function barrier(string $point): void {global $dir;ec_write('barrier.json',['point'=>$point,'connection'=>(int)$this->real->get_var('SELECT CONNECTION_ID()')]);$end=microtime(true)+8;while(!file_exists($dir.'/release')){if(microtime(true)>$end)throw new RuntimeException('barrier_timeout');usleep(20000);}}
    public function query($sql){
        if($sql==='COMMIT'&&$this->fault==='after_release'){$this->barrier('after_release');throw new RuntimeException('engineering_before_commit');}
        if($sql==='COMMIT'&&$this->fault==='disconnect')$this->barrier('before_commit');
        $v=$this->real->query($sql);
        if(str_starts_with($sql,'INSERT INTO')){
            if($v===false)$this->insertErrno=mysqli_errno($this->real->dbh);
            if($this->fault==='after_receipt'&&$v===1){$this->barrier('after_receipt');throw new RuntimeException('engineering_after_receipt');}
        }
        if($sql==='COMMIT'&&$this->fault==='lost_ack'&&$v!==false)throw new RuntimeException('engineering_lost_ack');
        return $v;
    }
    public function insert(...$a){return $this->real->insert(...$a);}
}
$action=$argv[1]??'';$path=$dir.'/fixture.json';
if($action==='fixture'){ec_write('fixture.json',ec_fixture($argv[2]??'base'));ec_emit(['created'=>true]);exit;}
if($action==='observe'){
    $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$wpdb->query('SET autocommit=1');
    $rows=$wpdb->get_results("SELECT * FROM `$r` ORDER BY id",ARRAY_A);$receipts=$wpdb->get_results("SELECT * FROM `$l` ORDER BY id",ARRAY_A);
    ec_assert(is_array($rows)&&is_array($receipts)&&$wpdb->last_error==='','observer_query');
    ec_emit(['releases'=>$rows,'receipts'=>$receipts]);exit;
}
$f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$m=$f['manifest'];
if($action==='worker'){
    $real=$wpdb;$wpdb=new EngineeringDB($real,$argv[2]??'none',($argv[3]??'')==='hold');
    try{$result=o01_eng_publish($f['candidate'],$f['ids']);ec_emit(['result'=>$result,'insert_errno'=>$wpdb->insertErrno]);}finally{$wpdb=$real;}exit;
}
if($action==='atomic'){
    // Explicit atomic-layer replay case; callback must not be reached on duplicate manifest.
    $proxy=new EngineeringDB($wpdb,'none',false);$called=false;
    $v=o01_eng_atomic($proxy,$f['ids'],static function()use(&$called){$called=true;return false;});
    ec_emit(['atomic'=>$v,'callback_reached'=>$called,'insert_errno'=>$proxy->insertErrno]);exit;
}
if($action==='reset'){
    // Refuse broad cleanup if anything is outside this run's synthetic fixture family.
    $prefix='eng_'.str_replace(':','_',$binding).'_';$slugprefix='eng-'.str_replace(':','-',$binding).'-';
    foreach($wpdb->get_col("SELECT customer_id FROM `$l`") as $id)ec_assert(str_starts_with($id,$prefix),'foreign_receipt');
    foreach($wpdb->get_col("SELECT slug FROM `$r`") as $slug)ec_assert(str_starts_with($slug,$slugprefix),'foreign_release');
    ec_assert($wpdb->query("DELETE FROM `$r`")!==false&&$wpdb->query("DELETE FROM `$l`")!==false,'reset_failed');ec_emit(['reset'=>true]);exit;
}
if($action==='lock'){
    $name='pr_o01_dn22_'.substr(hash('sha256',$r.'|'.$m['slug']),0,40);
    $v=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));if($v==='1')$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));ec_emit(['lock'=>$v]);exit;
}
if($action==='reader'){
    $url='http://127.0.0.1:8080/?o01_dn22_page='.rawurlencode($m['slug']);$ctx=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]);
    $body=file_get_contents($url,false,$ctx);$headers=$http_response_header??[];
    ec_assert(str_contains($headers[0]??'',' 200 ')&&$body===$f['render'],'publisher_created_http_bytes');
    ec_assert((bool)array_filter($headers,static fn($h)=>stripos($h,'Cache-Control:')===0&&stripos($h,'no-store')!==false),'http_no_store');
    $expired=$f['candidate'];$expired['expires_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-1);$em=o01_manifest($expired);
    ec_assert($wpdb->update($r,['manifest_sha256'=>$em['manifest_sha256'],'manifest_json'=>o01_canon($em),'expires_at_utc'=>gmdate('Y-m-d H:i:s',time()-1)],['slug'=>$m['slug']])===1,'expiry_update');
    file_get_contents($url,false,$ctx);ec_assert(str_contains(($http_response_header??[])[0]??'',' 410 '),'http_expiry');
    ec_assert($wpdb->update($r,['manifest_sha256'=>$m['manifest_sha256'],'manifest_json'=>o01_canon($m),'expires_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($m['expires_at_utc'])),'render_html'=>'CORRUPTED_ENGINEERING_FIXTURE'],['slug'=>$m['slug']])===1,'corrupt_update');
    file_get_contents($url,false,$ctx);ec_assert(str_contains(($http_response_header??[])[0]??'',' 503 '),'http_corrupt');ec_emit(['http_bytes'=>true,'no_store'=>true,'expired'=>410,'corrupt'=>503]);exit;
}
throw new RuntimeException('unknown_engineering_action');
