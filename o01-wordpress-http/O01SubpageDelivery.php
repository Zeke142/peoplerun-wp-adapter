<?php
declare(strict_types=1);
/** Source-only, uninstalled optional-subpage resolver. Caller supplies a server-authorized
 * parent delivery result, a trusted server-loaded child record, and a server-verified password.
 * Never accept either record or the authorization flag from an HTTP payload.
 */
final class O01SubpageDelivery {
    public static function resolve(array $parent, ?array $child, string $path, bool $passwordVerified=false): array {
        $deny=static fn(int $status,string $reason):array=>['status'=>$status,'body'=>'','reason'=>$reason,'published'=>false];
        if (($parent['status']??null)!==200||($parent['published']??null)!==true||($parent['reason']??null)!=='pinned_byte_delivery') return $deny(503,'parent_not_authorized');
        if (!in_array($path,['about','private'],true)) return $deny(404,'unknown_subpage');
        if ($child===null||($child['schema']??null)!=='peoplerun.o01.subpage.v1'||($child['path']??null)!==$path) return $deny(404,'subpage_not_found');
        $visibility=$child['visibility']??null;
        if (($path==='private'&&$visibility!=='password')||($path==='about'&&$visibility!=='public')) return $deny(503,'visibility_mismatch');
        if ($visibility==='password'&&!$passwordVerified) return $deny(401,'password_required');
        if (($child['revision_sha256']??null)!==($parent['revision_sha256']??null) || !is_string($child['revision_sha256']??null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$child['revision_sha256'])) return $deny(503,'revision_mismatch');
        $body=$child['html']??null;$digest=$child['html_sha256']??null;
        if (!is_string($body)||$body===''||strlen($body)>250000||!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)||!hash_equals($digest,hash('sha256',$body))) return $deny(503,'exact_byte_integrity_failed');
        return ['status'=>200,'body'=>$body,'reason'=>'subpage_pinned_byte_delivery','published'=>true,
            'headers'=>['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff']];
    }
}
