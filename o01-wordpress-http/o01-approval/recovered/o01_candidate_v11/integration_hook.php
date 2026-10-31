<?php
declare(strict_types=1);
require_once __DIR__.'/caller_owned_approval.php';
/** Integration contract only. No live WordPress endpoint or nonce acceptance. */
function o01_caller_owned_boundary(PDO $pdo, array $claim, callable $authenticatedWordPressVerifier): array {
    if ($pdo->inTransaction()) return ['ok'=>false,'code'=>'NESTED_TRANSACTION_REFUSED'];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return ['ok'=>false,'code'=>'MYSQL_ONLY'];
    if (getenv('O01_DISPOSABLE_ACCEPTANCE')!=='YES') return ['ok'=>false,'code'=>'DISPOSABLE_ONLY'];
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='o01_ephemeral') return ['ok'=>false,'code'=>'WRONG_DATABASE'];
    $pdo->beginTransaction();
    try {
        $result=(new O01CallerOwnedApproval($pdo))->verifyAndClaim($claim,$authenticatedWordPressVerifier);
        if (($result['ok']??false)!==true) { $pdo->rollBack(); return $result; }
        // No automatic COMMIT: a higher-level caller must verify and commit or rollback.
        return ['ok'=>true,'code'=>'STAGED_TRANSACTION_OPEN_CALLER_MUST_COMMIT_OR_ROLLBACK'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'code'=>'TRANSACTION_ABORTED'];
    }
}
