<?php
declare(strict_types=1);
/** SOURCE-ONLY, NOT INSTALLED. Requires separately created tables and a transaction-capable InnoDB driver.
 * A DB name lock protects a missing slug row (SELECT FOR UPDATE alone cannot guard absent rows).
 * The PDO credentials MUST come from independently authorized server-side configuration.
 * The engine's independent-evidence callback MUST verify G1/G2 + consent/payment/publication receipts.
 */
final class O01WPMySQLPaidRouteStore {
    private PDO $db; private string $prefix; private ?string $lock=null; private bool $active=false; private bool $tainted=false;
    public function __construct(PDO $db,string $tablePrefix) {
        if (!preg_match('/\A[A-Za-z0-9_]{1,48}\z/D',$tablePrefix))throw new InvalidArgumentException('invalid_prefix');
        $this->db=$db;$this->prefix=$tablePrefix;
    }
    private function rows(): string{return '`'.$this->prefix.'o01_paid_routes`';}
    private function log(): string{return '`'.$this->prefix.'o01_paid_receipts`';}
    public function begin(string $slug,string $key): bool {
        if ($this->active||$this->tainted)return false;
        $lock='o01:'.substr(hash('sha256',$slug),0,48);
        $s=$this->db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);
        if ((int)$s->fetchColumn()!==1)return false;
        $this->lock=$lock;
        try {$this->db->beginTransaction();$this->active=true;return true;}
        catch(Throwable $e){$this->release();throw $e;}
    }
    private function release(): void {
        if ($this->lock!==null){$lock=$this->lock;$this->lock=null;try {$s=$this->db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}catch(Throwable $ignored){$this->tainted=true;/* Commit persists; prevent reusing this possibly locked connection. */}}
    }
    public function findSlug(string $slug): ?array {
        $s=$this->db->prepare('SELECT record_json FROM '.$this->rows().' WHERE slug=? FOR UPDATE');
        $s->execute([$slug]);$r=$s->fetchColumn();return $r===false?null:json_decode((string)$r,true,512,JSON_THROW_ON_ERROR);
    }
    public function findRequest(string $key): ?array {
        $s=$this->db->prepare('SELECT receipt_json FROM '.$this->log().' WHERE request_key=? FOR UPDATE');
        $s->execute([$key]);$r=$s->fetchColumn();return $r===false?null:json_decode((string)$r,true,512,JSON_THROW_ON_ERROR);
    }
    public function put(string $slug,array $record): bool {
        $json=json_encode($record,JSON_THROW_ON_ERROR);
        $s=$this->db->prepare('INSERT INTO '.$this->rows().' (slug,record_json) VALUES (?,?) ON DUPLICATE KEY UPDATE record_json=VALUES(record_json)');
        return $s->execute([$slug,$json]);
    }
    public function receipt(string $key,array $receipt): bool {
        $json=json_encode($receipt,JSON_THROW_ON_ERROR);
        $s=$this->db->prepare('INSERT INTO '.$this->log().' (request_key,receipt_json) VALUES (?,?)');
        return $s->execute([$key,$json]);
    }
    public function commit():bool {
        if (!$this->active)return false;
        try {
            $ok=$this->db->commit();
            if ($ok !== true) $this->tainted=true; // Outcome not durable/confirmed.
            return $ok === true;
        } catch (Throwable $e) {
            $this->tainted=true; // Commit outcome unknown. Never reuse this connection.
            throw $e;
        } finally {
            $this->active=false;
            $this->release(); // A release fault cannot reverse a successful commit.
        }
    }
    public function rollback():void {
        if (!$this->active) { $this->release(); return; }
        try {
            $ok=$this->db->rollBack();
            if ($ok !== true) throw new RuntimeException('rollback_unconfirmed');
            $this->active=false;
        } catch (Throwable $e) {
            // Transaction outcome unknown: this PDO connection MUST NOT be reused.
            $this->tainted=true;
            $this->active=false;
            throw $e;
        } finally {
            // Release the advisory lock even when PDO rollback fails or throws.
            $this->release();
        }
    }
}
