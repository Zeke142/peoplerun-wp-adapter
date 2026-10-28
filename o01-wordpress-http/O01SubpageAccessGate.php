<?php
declare(strict_types=1);
/** Source-only WordPress/MySQL integration. Fail closed unless an atomic DB advisory lock works. */
final class O01SubpageAccessGate {
    public static function locked(string $purpose, int $postId, callable $operation): mixed {
        global $wpdb;
        if ($postId < 1 || !is_object($wpdb) || !method_exists($wpdb,'get_var') || !method_exists($wpdb,'prepare')) return false;
        $key='o01:'.substr(hash('sha256',$purpose.':'.$postId),0,40);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $key)) !== '1') return false;
        try { return $operation(); }
        finally { try { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); } catch (Throwable $ignored) {} }
    }
    public static function checkPassword(int $postId,string $path,string $passwordHash,?string $candidate): bool {
        if ($path!=='private' || !is_string($candidate) || $candidate==='' || strlen($candidate)>256) return false;
        return self::locked('password:'.$path,$postId,function() use($postId,$path,$passwordHash,$candidate):bool {
            $key='_o01_private_attempts_v1';
            $state=get_post_meta($postId,$key,true); $state=is_array($state)?$state:[];
            $now=time(); $start=(int)($state['window_start']??$now);
            $count=($start<=$now && $now-$start<900)?(int)($state['failed']??0):0;
            if($count>=5) return false;
            $correct=password_verify($candidate,$passwordHash);
            $next=$correct?['window_start'=>$now,'failed'=>0]:['window_start'=>($count===0?$now:$start),'failed'=>$count+1];
            if(update_post_meta($postId,$key,$next)===false) return false;
            return $correct;
        })===true;
    }
}
