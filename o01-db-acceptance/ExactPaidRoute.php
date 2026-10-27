<?php
declare(strict_types=1);
/** Source-only, disabled-by-default O01 route candidate. NOT installed, authenticated, or production-approved.
 * Repository implementation would have to retrieve $record from trusted server-only storage.
 * Neither an HTTP request nor a customer draft may supply this record.
 */
require_once __DIR__.'/O01PaidCalendarTerms.php';
final class O01ExactPaidRoute {
    public static function resolve(?array $record, string $requestedSlug, int $trustedNow): array {
        $deny = static fn(int $status, string $reason): array => ['status'=>$status,'body'=>'','reason'=>$reason,'published'=>false];
        if ($record === null) return $deny(404,'route_unknown');
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $requestedSlug) || strlen($requestedSlug)<3 || strlen($requestedSlug)>63) return $deny(404,'invalid_slug');
        if (($record['schema']??null)!=='peoplerun.o01.paid_route.v1'||($record['slug']??null)!==$requestedSlug) return $deny(404,'route_identity_mismatch');
        // Deny without confirmed server-issued receipt, authenticated consent, and approved publication.
        foreach (['trusted_store_confirmed','owner_authorized','customer_consent_independently_verified','payment_independently_verified','publish_approved','revision_immutable'] as $k)
            if (($record[$k]??null)!==true) return $deny(503,'authority_not_proven');
        if (($record['route_state']??null)==='withdrawn') return $deny(410,'withdrawn');
        if (($record['route_state']??null)!=='published') return $deny(404,'not_published');
        if (!is_int($record['started_at']??null)||!is_int($record['expires_at']??null)||!is_int($record['term_seconds']??null)||
            !is_string($record['term']??null)||
            !O01PaidCalendarTerms::valid($record['term'],$record['started_at'],$record['expires_at'])||
            $record['started_at']<1||$record['expires_at']!==$record['started_at']+$record['term_seconds']) return $deny(503,'bad_paid_term');
        if ($trustedNow < $record['started_at']) return $deny(404,'not_started');
        if ($trustedNow >= $record['expires_at']) return $deny(410,'paid_term_expired');
        if (!is_string($record['html']??null)||($record['html']==='')||strlen($record['html'])>250000||
            !is_string($record['html_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$record['html_sha256'])||
            !hash_equals($record['html_sha256'],hash('sha256',$record['html']))) return $deny(503,'exact_byte_integrity_failed');
        if (!is_string($record['revision_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$record['revision_sha256'])||
            !hash_equals($record['html_sha256'],$record['revision_sha256'])) return $deny(503,'revision_not_bound');
        return ['status'=>200,'body'=>$record['html'],'reason'=>'pinned_byte_delivery','published'=>true,
            'headers'=>['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff']];
    }
}
