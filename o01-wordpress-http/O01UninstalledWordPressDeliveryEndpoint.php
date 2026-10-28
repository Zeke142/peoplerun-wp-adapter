<?php
declare(strict_types=1);
require_once __DIR__.'/O01WordPressPublicationAdapter.php';
require_once __DIR__.'/WPMySQLPaidRouteStore.php';
/** Uninstalled adapter: no add_action(), rewrite rules, DB migrations or direct output. */
final class O01UninstalledWordPressDeliveryEndpoint {
    private PDO $pdo; private string $prefix; private $authority;
    /** Receipt verifier MUST be independently issued server-side and return verifiable SHA pins.
     * This constructor never reads credentials from HTTP or environment variables. */
    public function __construct(PDO $pdo,string $tablePrefix,callable $authority) {
        if (!in_array('mysql',$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?['mysql']:[],true))
            throw new InvalidArgumentException('mysql_pdo_required');
        if (!preg_match('/\A[A-Za-z0-9_]{1,48}\z/D',$tablePrefix))throw new InvalidArgumentException('invalid_table_prefix');
        $this->pdo=$pdo;$this->prefix=$tablePrefix;$this->authority=$authority;
    }
    private function load(string $slug):?array {
        // One consistent route read per HTTP request. Never query G1 for anonymous delivery.
        $sql='SELECT record_json FROM `'.$this->prefix.'o01_paid_routes` WHERE slug=? LIMIT 1';
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$slug]);$raw=$stmt->fetchColumn();
        if($raw===false)return null;
        $decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($decoded))throw new RuntimeException('route_invalid');
        return $decoded;
    }
    /** No user-supplied approval or receipts. Authentication callbacks operate on server DB data only. */
    public function resolve(string $slug,?string $path,int $serverNow,?string $password=null):array {
        if($serverNow<1)return ['status'=>503,'body'=>'','reason'=>'invalid_server_clock','published'=>false];
        if(!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)<3||strlen($slug)>63)
            return ['status'=>404,'body'=>'','reason'=>'invalid_slug','published'=>false];
        if($path!==null&&!in_array($path,['about','private'],true))
            return ['status'=>404,'body'=>'','reason'=>'invalid_path','published'=>false];
        try {
            $load=fn(string $s)=>$this->load($s);
            $receiptAuthority=$this->authority;
            // The persisted reader ignores this argument and uses committed parent bytes.
            $noOwnerG1=static fn()=>null;
            if($path===null)return O01PersistedRouteReader::resolve($slug,$serverNow,$load,$noOwnerG1,$receiptAuthority);
            return O01WordPressPublicationAdapter::deliver($slug,$path,$serverNow,$load,$noOwnerG1,$receiptAuthority,$password);
        }catch(Throwable $e){return ['status'=>503,'body'=>'','reason'=>'database_unavailable','published'=>false];}
    }
    /** Owner-side only: real G1 functions and independent receipts are required. No public route can call this. */
    public static function ownerTransition(PDO $pdo,string $prefix,string $operation,array $command,callable $authority,int $now):array {
        if(!function_exists('get_current_user_id')||get_current_user_id()<1)
            return ['result'=>'DENIED','reason'=>'authenticated_owner_required','published'=>false];
        try {return O01WordPressPublicationAdapter::transition(new O01WPMySQLPaidRouteStore($pdo,$prefix),$operation,$command,$authority,$now);}
        catch(Throwable $e){return ['result'=>'DENIED','reason'=>'storage_unavailable','published'=>false];}
    }
}
