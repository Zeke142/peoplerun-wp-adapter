<?php
declare(strict_types=1);
/** Decorates the EXISTING publisher store. Source-only candidate.
 * Inner store MUST own this exact PDO, transaction and all publisher writes.
 * Closure is trusted server wiring to O01PersistedIdentity, not request data.
 * No production registration, installation or genuine proof follows.
 */
final class O01IdentityLockedStore {
 private array $locks=[]; private ?array $snapshot=null; private ?string $session=null;
 private bool $tainted=false;
 private bool $commitOutcomeUnknown=false;
 public function __construct(private PDO $db,private object $inner,private int $project,
  private string $slug,private Closure $reload,private Closure $clock) {
  if($project<1||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)<3||strlen($slug)>63)throw new InvalidArgumentException('invalid_identity');
 }
 private function scalar(string $sql,array $args=[]):mixed {
  $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchColumn();
 }
 private function assertLocks():void {
  if($this->session===null||(string)$this->scalar('SELECT CONNECTION_ID()')!==$this->session)throw new RuntimeException('lock_session_lost');
  foreach($this->locks as $lock)
   if((string)$this->scalar('SELECT IS_USED_LOCK(?)',[$lock])!==$this->session)throw new RuntimeException('lock_ownership_lost');
 }
 private function release():void {
  foreach(array_reverse($this->locks) as $lock){
   try{if((string)$this->scalar('SELECT RELEASE_LOCK(?)',[$lock])!=='1')$this->tainted=true;}
   catch(Throwable $e){$this->tainted=true;}
  }
  $this->locks=[];$this->session=null;$this->snapshot=null;
 }
 public function begin(string $slug,string $key):bool {
  if($this->tainted||$this->locks||$this->db->inTransaction()||$slug!==$this->slug)return false;
  try {
   $this->session=(string)$this->scalar('SELECT CONNECTION_ID()');
   foreach(['revision:'.$this->project,'g2:url:'.$slug] as $scope){
    $name='priw:'.substr(hash('sha256',$scope),0,48);
    // Reject existing ownership: GET_LOCK is recursive on supported MySQL/MariaDB.
    if($this->scalar('SELECT IS_USED_LOCK(?)',[$name])!==null)throw new RuntimeException('lock_busy');
    if((string)$this->scalar('SELECT GET_LOCK(?,0)',[$name])!=='1')throw new RuntimeException('lock_unavailable');
    $this->locks[]=$name;
   }
   if($this->inner->begin($slug,$key)!==true||!$this->db->inTransaction())throw new RuntimeException('publisher_transaction_missing');
   $this->assertLocks();$this->snapshot=($this->reload)(($this->clock)());
   return true;
  }catch(Throwable $e){try{$this->inner->rollback();}finally{$this->release();}throw $e;}
 }
 public function snapshot():array {
  $this->assertLocks();if($this->snapshot===null||!$this->db->inTransaction())throw new RuntimeException('snapshot_missing');
  return $this->snapshot;
 }
 public function findRequest($key){return $this->inner->findRequest($key);}
 public function findSlug($slug){return $this->inner->findSlug($slug);}
 public function put($slug,$record){$this->snapshot();return $this->inner->put($slug,$record);}
 public function receipt($key,$record){$this->snapshot();return $this->inner->receipt($key,$record);}
 public function commit():bool {
  try {
   $before=$this->snapshot();$fresh=($this->reload)(($this->clock)());
   if($fresh!==$before)throw new RuntimeException('identity_changed_before_commit');
   $this->assertLocks();
   // Once COMMIT is attempted, transport failure does not establish rollback.
   $this->commitOutcomeUnknown=true;
   try{$ok=$this->inner->commit();}catch(Throwable $e){$this->tainted=true;throw $e;}
   if(!$ok)$this->tainted=true;
   else $this->commitOutcomeUnknown=false;
   return $ok;
  }catch(Throwable $e){try{$this->inner->rollback();}catch(Throwable $ignored){$this->tainted=true;}throw $e;}
  finally{$this->release();}
 }
 public function commitOutcomeUnknown():bool {return $this->commitOutcomeUnknown;}
 public function rollback():void {
  try{$this->inner->rollback();}catch(Throwable $e){$this->tainted=true;throw $e;}finally{$this->release();}
 }
}
