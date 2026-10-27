<?php
declare(strict_types=1);
/** Source-only bridge for the actual wp_o01_paid_routes table; no hooks, writes or activation. */
require_once __DIR__.'/ExactPaidRoute.php';
final class O01PersistedRouteReader {
    private static function deny(int $status,string $reason):array {return ['status'=>$status,'body'=>'','reason'=>$reason,'published'=>false];}
    /** $load is a server-owned read from InnoDB paid_routes, $revision is a server-owned G1 reader,
     * $authority independently verifies all consent/payment/publication receipts and G1/G2 owner/slug bindings.
     * Missing storage binding metadata must NEVER be inferred from the browser or fabricated.
     */
    public static function resolve(string $slug,int $now, callable $load,callable $revision,callable $authority):array {
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)<3||strlen($slug)>63)
            return self::deny(404,'invalid_slug');
        try {
            $record=$load($slug);
            if ($record===null) return self::deny(404,'route_unknown');
            if (!is_array($record)||($record['slug']??null)!==$slug) return self::deny(503,'stored_route_invalid');
            foreach (['owner_id','project_id','revision_id','reservation_id'] as $field)
                if (!is_int($record[$field]??null)||$record[$field]<1) return self::deny(503,'binding_not_persisted');
            $html=$revision($record['project_id'],$record['revision_id'],$record['owner_id']);
            if (!is_array($html)||!is_string($html['html']??null)||!is_string($html['content_sha256']??null)
               || !hash_equals($html['content_sha256'],hash('sha256',$html['html']))) return self::deny(503,'g1_revision_invalid');
            if (!hash_equals((string)($record['html_sha256']??''),$html['content_sha256'])||
                !hash_equals((string)($record['revision_sha256']??''),$html['content_sha256']))
                return self::deny(503,'immutable_revision_mismatch');
            $verified=$authority($record,$html,$now);
            if (!is_array($verified)||($verified['owner']??null)!==true||($verified['reservation']??null)!==true||
                ($verified['consent']??null)!==true||($verified['payment']??null)!==true||
                ($verified['publication']??null)!==true||($verified['receipts']??null)!==true)
                return self::deny(503,'independent_authority_unverified');
            // Existing reader previously accepted independent-authority booleans without
            // binding their receipt hashes to the actual committed writer record.
            foreach (['consent','payment','publication'] as $type) {
                $field=$type.'_sha256';
                $stored=$record[$field]??null;
                $admitted=$verified[$field]??null;
                if (!is_string($stored)||!preg_match('/\A[a-f0-9]{64}\z/D',$stored)||
                    !is_string($admitted)||!hash_equals($stored,$admitted))
                    return self::deny(503,'receipt_pin_mismatch');
            }
            return O01ExactPaidRoute::resolve([
                'schema'=>'peoplerun.o01.paid_route.v1','slug'=>$slug,'html'=>$html['html'],
                'html_sha256'=>$html['content_sha256'],'revision_sha256'=>$html['content_sha256'],
                'route_state'=>$record['state']??null,'started_at'=>$record['started_at']??null,
                'expires_at'=>$record['expires_at']??null,'term_seconds'=>$record['term_seconds']??null,'term'=>$record['term']??null,
                'trusted_store_confirmed'=>true,'owner_authorized'=>true,'customer_consent_independently_verified'=>true,
                'payment_independently_verified'=>true,'publish_approved'=>true,'revision_immutable'=>true,
            ],$slug,$now);
        }catch(Throwable $e){return self::deny(503,'trusted_reader_unavailable');}
    }
}
