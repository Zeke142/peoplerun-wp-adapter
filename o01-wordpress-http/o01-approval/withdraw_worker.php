<?php
declare(strict_types=1);
// Disposable two-connection acceptance worker; never run against production.
if (PHP_SAPI !== 'cli' || getenv('O01_DISPOSABLE_ACCEPTANCE') !== 'YES') exit(77);
[$script,$project,$revision,$marker] = $argv + [null,null,null,null];
if (!is_string($project) || !preg_match('/\A[a-z0-9-]{1,64}\z/D',$project) || !is_string($marker) || !str_starts_with($marker,sys_get_temp_dir().'/o01-race-')) exit(78);
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=o01_ephemeral;charset=utf8mb4','o01_temp','local-only-test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$db->exec('SET SESSION innodb_lock_wait_timeout=12');
$db->beginTransaction();
try {
  $q=$db->prepare('SELECT status FROM pr_o01_revision_authority WHERE project_id=? AND revision_id=? FOR UPDATE');
  // Signal just before attempting the blocking lock, not after acquiring it.
  if(file_put_contents($marker,'LOCK_ATTEMPT')===false)throw new RuntimeException('marker_write_failed');
  $q->execute([$project,$revision]); $state=$q->fetchColumn();
  $u=$db->prepare("UPDATE pr_o01_revision_authority SET status='withdrawn' WHERE project_id=? AND revision_id=? AND status='awaiting_approval'");
  $u->execute([$project,$revision]);$affected=$u->rowCount();
  $db->commit(); echo json_encode(['prior_state'=>$state,'withdrawn_rows'=>$affected],JSON_THROW_ON_ERROR);exit(0);
} catch(Throwable $e) {if($db->inTransaction())$db->rollBack();fwrite(STDERR,'withdraw_worker_failed:'.get_class($e));exit(79);}
