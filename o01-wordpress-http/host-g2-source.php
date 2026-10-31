<?php
/**
 * Plugin Name: PeopleRun Instant Webpages — G2 URL Reservation (Candidate)
 * Description: Owner-bound, private URL reservations and fail-closed paid-publication authorization. No actual publishing or billing.
 * Version: 0.1.0-candidate
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

add_action('init', static function (): void {
    register_post_type('pr_iw_url_reserve', [
        'public'=>false, 'publicly_queryable'=>false, 'exclude_from_search'=>true,
        'show_ui'=>false, 'show_in_rest'=>false, 'rewrite'=>false, 'query_var'=>false,
        'supports'=>['title','author'],
    ]);
});

/** Strict, predictable top-level /customer-slug/ URLs. No raw paths or router bypass. */
function pr_iw_g2_v1_normalize_slug(string $input) {
    $slug = strtolower(trim($input));
    if (strlen($slug) < 3 || strlen($slug) > 63 ||
        preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
        return new WP_Error('invalid_slug','Use a 3–63 character lowercase URL slug');
    }
    $reserved = ['wp-admin','wp-content','wp-includes','wp-json','feed','login','admin','api',
        'robots','sitemap','sitemap-index','xmlrpc','index','preview','checkout','payment','cart'];
    if (in_array($slug, $reserved, true)) { return new WP_Error('reserved_slug','Protected site route'); }
    return $slug;
}

function pr_iw_g2_v1_owner(int $project_id, int $owner_id): bool {
    return function_exists('pr_iw_g1_v2_owner') && pr_iw_g1_v2_owner($project_id, $owner_id);
}
function pr_iw_g2_v1_revision(int $project_id, int $revision_id, int $owner_id) {
    if (!pr_iw_g2_v1_owner($project_id, $owner_id)) { return new WP_Error('forbidden','Owner required'); }
    $read = pr_iw_g1_v2_read_revision($project_id, $revision_id, $owner_id);
    if (is_wp_error($read)) { return $read; }
    $number = (int)get_post_meta($revision_id, '_pr_iw_revision_number', true);
    $current = (int)get_post_meta($project_id, '_pr_iw_version', true);
    if ($number < 1 || $number !== $current) { return new WP_Error('stale_revision','Only current approved revision may be reserved'); }
    return $read;
}
function pr_iw_g2_v1_reservation(int $reservation_id, int $project_id, int $owner_id) {
    if (!pr_iw_g2_v1_owner($project_id, $owner_id)) { return new WP_Error('forbidden','Owner required'); }
    $post = get_post($reservation_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'pr_iw_url_reserve' ||
        $post->post_status !== 'private' || (int)$post->post_parent !== $project_id ||
        (int)$post->post_author !== $owner_id) { return new WP_Error('not_found','Reservation not found'); }
    $data = [];
    foreach (['slug','revision_id','revision_sha256','expires_at','request_key','state'] as $key) {
        $data[$key] = (string)get_post_meta($reservation_id, '_pr_iw_g2_'.$key, true);
    }
    if (!$data['slug'] || !$data['revision_sha256'] || !$data['state']) { return new WP_Error('integrity_failure','Reservation incomplete'); }
    return $data + ['reservation_id'=>$reservation_id,'project_id'=>$project_id,'owner_id'=>$owner_id];
}
/** Reserve a URL for a current G1 revision. Lock is held across route lookup and write. */
function pr_iw_g2_v1_reserve(int $project_id, int $revision_id, int $owner_id, string $requested_slug, string $request_key, int $ttl_seconds = 3600) {
    $slug = pr_iw_g2_v1_normalize_slug($requested_slug);
    if (is_wp_error($slug)) { return $slug; }
    if (!pr_iw_g2_v1_owner($project_id, $owner_id)) { return new WP_Error('forbidden','Owner required'); }
    if ($request_key === '' || strlen($request_key) > 120 || $ttl_seconds < 300 || $ttl_seconds > 604800) {
        return new WP_Error('invalid_input','Invalid reservation key or TTL');
    }
    $revision = pr_iw_g2_v1_revision($project_id, $revision_id, $owner_id);
    if (is_wp_error($revision)) { return $revision; }
    if (!function_exists('pr_iw_g1_v2_lock')) { return new WP_Error('g1_unavailable','Accepted G1 required'); }
    $keyLock = pr_iw_g1_v2_lock('g2:request:'.$owner_id.':'.$project_id.':'.$request_key);
    if ($keyLock === null) { return new WP_Error('lock_unavailable','Cannot serialize idempotency key'); }
    $lock = pr_iw_g1_v2_lock('g2:url:'.$slug);
    if ($lock === null) { pr_iw_g1_v2_unlock($keyLock); return new WP_Error('lock_unavailable','Cannot serialize URL reservation'); }
    try {
        if (!pr_iw_g2_v1_owner($project_id, $owner_id)) { return new WP_Error('forbidden','Owner changed'); }
        $revision = pr_iw_g2_v1_revision($project_id, $revision_id, $owner_id);
        if (is_wp_error($revision)) { return $revision; }
        $key = hash('sha256', $owner_id.':'.$project_id.':'.$request_key);
        // The same key cannot silently mint a different URL or bind a different revision.
        // Request-key and slug advisory locks jointly prevent cross-slug races.
        $sameKey = get_posts(['post_type'=>'pr_iw_url_reserve','post_status'=>'private','author'=>$owner_id,
            'post_parent'=>$project_id,'numberposts'=>-1,'meta_key'=>'_pr_iw_g2_request_key',
            'meta_value'=>$key,'suppress_filters'=>true]);
        foreach ($sameKey as $old) {
            if ((string)get_post_meta((int)$old->ID,'_pr_iw_g2_slug',true) !== $slug ||
                (int)get_post_meta((int)$old->ID,'_pr_iw_g2_revision_id',true) !== $revision_id) {
                return new WP_Error('idempotency_conflict','Request key already binds to another URL or revision');
            }
        }
        $posts = get_posts(['post_type'=>'pr_iw_url_reserve','post_status'=>'private','numberposts'=>-1,
            'meta_key'=>'_pr_iw_g2_slug','meta_value'=>$slug,'suppress_filters'=>true]);
        if (get_page_by_path($slug, OBJECT, 'page')) { return new WP_Error('url_taken','Existing WordPress route'); }
        $now = time();
        foreach ($posts as $p) {
            $id = (int)$p->ID;
            // Collision checking inspects metadata server-side; no other customer's data is returned.
            $state = (string)get_post_meta($id, '_pr_iw_g2_state', true);
            $expiry = (int)get_post_meta($id, '_pr_iw_g2_expires_at', true);
            $oldKey = (string)get_post_meta($id, '_pr_iw_g2_request_key', true);
            if ($state === 'reserved' && $expiry > $now) {
                if ((int)$p->post_author === $owner_id && (int)$p->post_parent === $project_id &&
                    $oldKey === $key && (int)get_post_meta($id,'_pr_iw_g2_revision_id',true) === $revision_id &&
                    hash_equals((string)get_post_meta($id,'_pr_iw_g2_revision_sha256',true), $revision['content_sha256'])) {
                    return ['reservation_id'=>$id,'slug'=>$slug,'url'=>home_url('/'.$slug.'/'), 'replayed'=>true,'expires_at'=>$expiry];
                }
                return new WP_Error('url_reserved','Slug currently reserved');
            }
        }
        $id = wp_insert_post(['post_type'=>'pr_iw_url_reserve','post_status'=>'private','post_title'=>'Reserved: '.$slug,
            'post_parent'=>$project_id,'post_author'=>$owner_id,'post_content'=>''], true);
        if (is_wp_error($id)) { return $id; }
        $expiry = $now+$ttl_seconds;
        $values = ['slug'=>$slug,'revision_id'=>(string)$revision_id,'revision_sha256'=>$revision['content_sha256'],
            'expires_at'=>(string)$expiry,'request_key'=>$key,'state'=>'reserved'];
        foreach ($values as $field=>$value) {
            if (!add_post_meta((int)$id,'_pr_iw_g2_'.$field,$value,true)) {
                wp_delete_post((int)$id,true);
                return new WP_Error('metadata_failure','Reservation write failed');
            }
        }
        return ['reservation_id'=>(int)$id,'slug'=>$slug,'url'=>home_url('/'.$slug.'/'), 'replayed'=>false,'expires_at'=>$expiry];
    } finally { pr_iw_g1_v2_unlock($lock); pr_iw_g1_v2_unlock($keyLock); }
}
/** Verifier is intentionally NOT supplied by the caller: server-owned and registered separately. */
function pr_iw_g2_v1_authorize_publication(int $project_id, int $reservation_id, int $owner_id, string $payment_reference) {
    if (!pr_iw_g2_v1_owner($project_id, $owner_id)) { return new WP_Error('forbidden','Owner required'); }
    $r = pr_iw_g2_v1_reservation($reservation_id, $project_id, $owner_id);
    if (is_wp_error($r)) { return $r; }
    if ($r['state'] !== 'reserved' || (int)$r['expires_at'] <= time()) { return new WP_Error('reservation_inactive','Reservation expired or unavailable'); }
    $revision = pr_iw_g2_v1_revision($project_id, (int)$r['revision_id'], $owner_id);
    if (is_wp_error($revision)) { return $revision; }
    if (!hash_equals($r['revision_sha256'], $revision['content_sha256'])) { return new WP_Error('revision_changed','Approved revision changed'); }
    if ($payment_reference === '' || strlen($payment_reference) > 200) { return new WP_Error('invalid_payment_reference','Payment reference required'); }
    // G2 deliberately has no payment provider. This trusted function is absent until installed and audited.
    if (!function_exists('pr_iw_payment_verify_receipt_v1')) { return new WP_Error('payment_verifier_unavailable','No trusted payment verifier installed'); }
    $evidence = pr_iw_payment_verify_receipt_v1($payment_reference);
    if (!is_array($evidence) || ($evidence['verified'] ?? null) !== true || ($evidence['status'] ?? null) !== 'paid' ||
        ($evidence['project_id'] ?? null) !== $project_id || ($evidence['reservation_id'] ?? null) !== $reservation_id ||
        ($evidence['owner_id'] ?? null) !== $owner_id || !is_string($evidence['receipt_sha256'] ?? null) ||
        preg_match('/\A[a-f0-9]{64}\z/D', $evidence['receipt_sha256']) !== 1 ||
        !is_string($evidence['provider_transaction_id'] ?? null) || $evidence['provider_transaction_id'] === '' ||
        !in_array($evidence['currency'] ?? null, ['USD'], true) ||
        !is_int($evidence['amount_cents'] ?? null) || $evidence['amount_cents'] < 1 ||
        !is_int($evidence['paid_at'] ?? null) || $evidence['paid_at'] > time()+300 || $evidence['paid_at'] < time()-604800) {
        return new WP_Error('payment_unverified','Payment evidence invalid or does not bind to reservation');
    }
    // This is an authorization PROPOSAL, not a capability, token, side effect, or publishing action.
    return ['result'=>'eligible_for_governed_publication','project_id'=>$project_id,'reservation_id'=>$reservation_id,
        'revision_id'=>(int)$r['revision_id'],'revision_sha256'=>$r['revision_sha256'],
        'slug'=>$r['slug'],'url'=>home_url('/'.$r['slug'].'/'),
        'payment_receipt_sha256'=>$evidence['receipt_sha256'],'payment_reference'=>$payment_reference,
        'publishing_performed'=>false];
}
