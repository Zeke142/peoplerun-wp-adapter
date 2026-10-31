<?php
declare(strict_types=1);
/** O01 DN17 NEW candidate core. Not the missing v0.3 original. */
const O01_DN17_CSS = 'body{font-family:system-ui,sans-serif;margin:0;padding:1rem;color:#122338}main{max-width:64rem;margin:auto}';
function o01_assert(bool $ok, string $why): void { if (!$ok) throw new DomainException($why); }
function o01_normalize(array $p): array {
    $required=['customer_id','offer_id','price_cents','currency','page_count','term','privacy','slug','revision','expires_at_utc','sanitized_html','css','asset_manifest'];
    foreach ($required as $key) o01_assert(array_key_exists($key,$p),'missing_'.$key);
    o01_assert(is_string($p['customer_id'])&&$p['customer_id']!=='','invalid_customer');
    o01_assert(is_string($p['offer_id'])&&$p['offer_id']!=='','invalid_offer');
    o01_assert(is_int($p['price_cents'])&&$p['price_cents']>0,'invalid_price');
    o01_assert($p['currency']==='USD','invalid_currency');
    o01_assert(is_int($p['page_count'])&&$p['page_count']>=1&&$p['page_count']<=4,'invalid_page_count');
    o01_assert(in_array($p['term'],['1_week','1_month','1_year'],true),'invalid_term');
    o01_assert(in_array($p['privacy'],['public','password_protected'],true),'invalid_privacy');
    o01_assert(is_string($p['slug'])&&preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$p['slug'])===1,'invalid_slug');
    o01_assert(is_int($p['revision'])&&$p['revision']>0,'invalid_revision');
    o01_assert(is_string($p['expires_at_utc'])&&preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$p['expires_at_utc'])===1&&strtotime($p['expires_at_utc'])!==false,'invalid_expiry');
    o01_assert(is_string($p['sanitized_html'])&&strlen($p['sanitized_html'])<=250000,'invalid_html');
    o01_assert(is_string($p['css'])&&hash_equals(O01_DN17_CSS,$p['css']),'untrusted_css_denied');
    o01_assert(is_array($p['asset_manifest'])&&count($p['asset_manifest'])<=100,'invalid_assets');
    return array_intersect_key($p,array_flip($required));
}
function o01_canon(array $a): string { ksort($a,SORT_STRING); return json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
function o01_escape(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function o01_render(array $p): string {
    $p=o01_normalize($p);
    // The caller MUST have sanitized HTML and CSS via a reviewed trusted sanitizer.
    // Same immutable bytes serve preview and publication; never use a different WordPress theme wrapper.
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'.$p['css'].'</style></head><body><main id="o01-document">'.$p['sanitized_html'].'</main></body></html>';
}
function o01_manifest(array $p): array {
    $p=o01_normalize($p);
    $render=o01_render($p);
    $core=['schema'=>'o01-dn17-render-v1','render_sha256'=>hash('sha256',$render),'template_version'=>'o01-dn17-exact-v1','asset_sha256'=>hash('sha256',o01_canon($p['asset_manifest']))];
    foreach (['customer_id','offer_id','price_cents','currency','page_count','term','privacy','slug','revision','expires_at_utc'] as $f) $core[$f]=$p[$f];
    $core['manifest_sha256']=hash('sha256',o01_canon($core));
    return $core;
}
function o01_release_decision(array $candidate, array $approval, array $payment, int $now, bool $trustedApproval, bool $trustedPayment): array {
    try {
        $m=o01_manifest($candidate);
        o01_assert($trustedApproval,'approval_not_independently_verified');
        o01_assert($trustedPayment,'payment_not_independently_verified');
        o01_assert(($approval['status']??null)==='approved','approval_status');
        o01_assert(($payment['status']??null)==='completed','payment_status');
        o01_assert(($approval['manifest_sha256']??null)===$m['manifest_sha256'],'approval_manifest_mismatch');
        foreach (['customer_id','offer_id','price_cents','currency','term','revision'] as $f){
            o01_assert(($payment[$f]??null)===$m[$f],'payment_'.$f.'_mismatch');
        }
        o01_assert(($payment['manifest_sha256']??null)===$m['manifest_sha256'],'payment_manifest_mismatch');
        o01_assert(($approval['customer_id']??null)===$m['customer_id'],'approval_customer_mismatch');
        o01_assert($now < strtotime($m['expires_at_utc']),'expired');
        return ['ok'=>true,'reason'=>'admissible_candidate','manifest'=>$m];
    }catch(Throwable $e){ return ['ok'=>false,'reason'=>$e->getMessage()]; }
}
function o01_visible(array $m,int $now): bool { return isset($m['expires_at_utc'])&&$now<strtotime((string)$m['expires_at_utc']); }
