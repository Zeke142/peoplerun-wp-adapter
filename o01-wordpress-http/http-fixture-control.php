<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES')exit(77);
require_once __DIR__.'/http-fixture-common.php';
$cmd=$argv[1]??'';
if($cmd==='seed'){
 $owner=wp_create_user('http-owner','local-only-http-password','http-owner@example.invalid');
 $other=wp_create_user('http-other','local-only-other-password','http-other@example.invalid');
 if(is_wp_error($owner)||is_wp_error($other))throw new RuntimeException('test_users_failed');
 wp_set_current_user($owner);
$d=http_db();$fresh=http_db();$baselinePosts=(int)$fresh->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn();$ids=[];$tables=['o01_mapping_candidate','o01_receipt_candidate_ledger','o01_integrated_o01_paid_routes','o01_integrated_o01_paid_receipts','pr_o01_approval_claims','pr_o01_revision_authority'];


 $d->exec(file_get_contents(__DIR__.'/o01-approval/recovered/o01_candidate_v11/schema.sql'));
 $d->exec('CREATE TABLE o01_mapping_candidate(owner_id BIGINT,project_id BIGINT,reservation_id BIGINT,mapping_version BIGINT,state VARCHAR(20),approval_project_id VARCHAR(64) UNIQUE,slug VARCHAR(63) UNIQUE,binding_json LONGTEXT,PRIMARY KEY(owner_id,project_id,reservation_id)) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_receipt_candidate_ledger(manifest_sha256 CHAR(64) NOT NULL,customer_id VARCHAR(180) NOT NULL,approval_issuer VARCHAR(180) NOT NULL,consent_ref VARCHAR(180) NOT NULL,payment_provider VARCHAR(180) NOT NULL,event_id VARCHAR(180) NOT NULL,payment_id VARCHAR(180) NOT NULL,admitted_at BIGINT NOT NULL,UNIQUE KEY consent_once(approval_issuer,consent_ref),UNIQUE KEY event_once(payment_provider,event_id),UNIQUE KEY payment_once(payment_provider,payment_id)) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_integrated_o01_paid_routes(slug VARCHAR(63) PRIMARY KEY,record_json LONGTEXT NOT NULL) ENGINE=InnoDB');
 $d->exec('CREATE TABLE o01_integrated_o01_paid_receipts(request_key VARCHAR(120) PRIMARY KEY,receipt_json LONGTEXT NOT NULL) ENGINE=InnoDB');

 $meta=['owner'=>$owner,'other'=>$other,'baseline_posts'=>$baselinePosts,'fixtures'=>[]];
 foreach(['good','content','price','term','quote','withdraw','stale-approve'] as $case){
  $slug='http-'.$case;$now=time();
  $startDate=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'));
  $target=$startDate->setDate((int)$startDate->format('Y'),(int)$startDate->format('m'),1)->modify('+1 month');
  $end=$target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$startDate->format('d'),(int)$target->format('t')))->getTimestamp();
  $canonical=['customer_id'=>'synthetic-customer','offer_id'=>'synthetic-offer','price_cents'=>4900,'currency'=>'USD','page_count'=>1,'term'=>'1_month','privacy'=>'public','slug'=>$slug,'revision'=>1,'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$end),'sanitized_html'=>'<p>Exact café preview</p>','css'=>O01_DN17_CSS,'asset_manifest'=>[]];
  $html=o01_render($canonical);$manifest=o01_manifest($canonical)['manifest_sha256'];$hash=hash('sha256',$html);
  $p=pr_iw_g1_v2_create_project($owner,'Integrated '.$case,'project-'.$case);if(!is_array($p))throw new RuntimeException('project_failed');$pid=$p['project_id'];$ids[]=$pid;
  $r=pr_iw_g1_v2_add_revision($pid,$owner,$html,0,'revision-'.$case);if(!is_array($r))throw new RuntimeException('revision_failed');$rid=$r['revision_id'];$ids[]=$rid;
  $v=pr_iw_g2_v1_reserve($pid,$rid,$owner,$slug,'reserve-'.$case,3600);if(!is_array($v))throw new RuntimeException('reserve_failed');$sid=$v['reservation_id'];$ids[]=$sid;
  
  $approvalProject='opaque-'.$case;$approvalRevision='opaque-revision';
  $m=['schema'=>'o01-server-identity-binding-candidate-v1','owner_id'=>$owner,'approval_owner_id'=>$owner,'project_id'=>$pid,'revision_id'=>$rid,'reservation_id'=>$sid,'canonical_revision'=>1,'slug'=>$slug,'html_sha256'=>$hash,'manifest_sha256'=>$manifest,'quote_digest_contract'=>'dn29_manifest_candidate_v1','binding_record_id'=>'mapping-'.$case,'approval_project_id'=>$approvalProject,'approval_revision_id'=>$approvalRevision,'customer_id'=>'synthetic-customer','canonical_quote'=>$canonical,'publication_started_at'=>$now];

  $q=$d->prepare('INSERT INTO o01_mapping_candidate VALUES(?,?,?,1,?,?,?,?)');$q->execute([$owner,$pid,$sid,'active',$approvalProject,$slug,json_encode($m,JSON_THROW_ON_ERROR)]);
  $q=$d->prepare('INSERT INTO pr_o01_revision_authority VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$approvalProject,$approvalRevision,$owner,$hash,$manifest,4900,'USD','one_month','awaiting_approval']);
  $meta['fixtures'][$case]=['project_id'=>$approvalProject,'revision_id'=>$approvalRevision,'html_sha256'=>$hash,'quote_sha256'=>$manifest,'slug'=>$slug,'html_b64'=>base64_encode($html)];
 }
 $meta['post_ids']=$ids;
 file_put_contents(__DIR__.'/../http-seed.json',json_encode($meta,JSON_THROW_ON_ERROR));echo "SEEDED_ACTUAL_G1_G2_HTTP_FIXTURES\n";exit;
}
$d=http_db();$meta=json_decode(file_get_contents(__DIR__.'/../http-seed.json'),true);$case=$argv[2]??'good';
$m=mapping($d,$meta['fixtures'][$case]['project_id']);
if($cmd==='mutate'){
 if(in_array($case,['content','stale-approve'],true)){
  wp_set_current_user($meta['owner']);$r=pr_iw_g1_v2_add_revision($m['project_id'],$meta['owner'],'<p>Changed after preview</p>',1,'edit-'.$case);
  if(!is_array($r))throw new RuntimeException('g1_edit_failed');$meta['post_ids'][]=$r['revision_id'];file_put_contents(__DIR__.'/../http-seed.json',json_encode($meta));
 }elseif($case==='quote'){
  $m['canonical_quote']['price_cents']=7900;$q=$d->prepare('UPDATE o01_mapping_candidate SET binding_json=? WHERE project_id=?');$q->execute([json_encode($m),$m['project_id']]);
 }else{
  // Deliberate DB fault injection, not admitted product edit/withdraw APIs.
  $field=['price'=>'amount_minor','term'=>'term','withdraw'=>'status'][$case];$value=['price'=>7900,'term'=>'one_year','withdraw'=>'withdrawn'][$case];
  $q=$d->prepare('UPDATE pr_o01_revision_authority SET '.$field.'=? WHERE project_id=?');$q->execute([$value,$m['approval_project_id']]);
 }
 echo "MUTATED_FIXTURE\n";exit;
}
if($cmd==='tamper'||$cmd==='restore'){
 $q=$d->prepare('SELECT record_json FROM o01_integrated_o01_paid_routes WHERE slug=?');$q->execute([$m['slug']]);$raw=$q->fetchColumn();
 if($cmd==='tamper'){
  file_put_contents(__DIR__.'/../http-route-original.json',$raw);$r=json_decode($raw,true);$r['parent_html_b64']=base64_encode('tampered');$raw=json_encode($r);
 }else $raw=file_get_contents(__DIR__.'/../http-route-original.json');
 $q=$d->prepare('UPDATE o01_integrated_o01_paid_routes SET record_json=? WHERE slug=?');$q->execute([$raw,$m['slug']]);exit;
}
if($cmd==='counts'){
 $result=[];foreach(['o01_receipt_candidate_ledger','o01_integrated_o01_paid_routes','o01_integrated_o01_paid_receipts','pr_o01_approval_claims'] as $table)$result[$table]=(int)$d->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
 echo json_encode($result);exit;
}
if($cmd==='cleanup'){
 $tables=['o01_mapping_candidate','o01_receipt_candidate_ledger','o01_integrated_o01_paid_routes','o01_integrated_o01_paid_receipts','pr_o01_approval_claims','pr_o01_revision_authority'];
 foreach($tables as $table)$d->exec('DROP TABLE IF EXISTS `'.$table.'`');
 wp_set_current_user($meta['owner']);foreach(array_reverse($meta['post_ids']) as $id)wp_delete_post($id,true);
 require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($meta['owner']);wp_delete_user($meta['other']);
 foreach($meta['post_ids'] as $id)if(get_post($id)!==null)throw new RuntimeException('post_cleanup_failed');
 if((int)$d->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn()!==$meta['baseline_posts'])throw new RuntimeException('baseline_not_restored');
 if((int)$d->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'o01_%' OR table_name LIKE 'pr_o01_%')")->fetchColumn()!==0)throw new RuntimeException('tables_not_removed');
 echo "CLEANUP_VERIFIED\n";exit;
}
throw new RuntimeException('unknown_command');
