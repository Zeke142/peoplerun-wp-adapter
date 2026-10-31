# O01 — persisted identity connected to complete publisher, v2

October 31, 2026 · America/Phoenix. Full product acceptance remains OPEN.

## Outcome

O01PersistedPublisher connects the persisted identity loader and lock-coordinated store to O01AtomicPaidRoute, O01PublisherReceiptBinding, the canonical quote comparison, receipt uniqueness reservation and original PDO route/receipt writer. Parent bytes come from the locked G1 snapshot, not a rebuilt page. All underlying 45 pinned repository files match their Git blob identities at 450ce0ba110e34c1d6260919e23993e870526649; source-pins.json records the exact paths and hashes. Four additive PHP candidate files are included. No repository mutation or workflow dispatch occurred.

## Verified local result

PHP 8.3.6, MariaDB 10.11.14, actual WordPress 6.8.3: **76 PASS, 2 explicit SKIP groups, exit 0**. Syntax checks passed for the four additive PHP files. Complete output is integrated-test-result.txt, SHA-256 e8d38e736172b454ac4e8a93b7efd34b6890331fada5c9429f125cf91dec66d9.

Actual WordPress APIs create the owner-scoped private project, immutable exact-byte revision and URL reservation. Server-generated IDs are persisted in the mapping and preserved by the committed route. The frozen canonical quote is persisted in the mapping JSON and compared by the original comparator inside the publisher transaction. CLI wp_set_current_user selects a disposable owner; this is not HTTP customer authentication.

Two independent PHP workers call the actual G1 add-revision and G2 reservation APIs while publication holds the original named locks. Both return lock_unavailable, with no conflicting write. A successful transaction commits route, receipt reservation and publisher receipt together; fresh-connection readback proves the stored parent bytes match G1 exactly.

An injected false result at the publisher receipt-write boundary yields atomic_commit_failed. Fresh readback proves no partial route, receipt or ledger reservation. Retrying with the same rolled-back consent/event/payment/request IDs succeeds and commits one receipt reservation. Missing genuine verifier configuration denies. Revoked mapping, changed canonical quote, superseded G1 revision and reservation expiry at commit deny without partial writes. Every shared lock is checked released. All 22 created G1/G2 posts are removed, pre-fixture WordPress post count restored, candidate mapping/ledger/publisher/approval tables dropped and checked absent. Runner terminates its MariaDB child in finally.

## Evidence limits

Approval claim callback, positive payment identifier callback and publication verifier are explicitly SYNTHETIC. No signed genuine final-preview consent, payment, Stripe webhook, real customer or commercial publication is established. The default recovered verifier is tested to deny without configured trust. No keys or live-looking success receipts were manufactured. Test records are marked synthetic.

Public HTTP serving, HTTP login/cookie approval, lock-session loss and unknown commit-acknowledgement cases remain untested by this run. The parent-only integration does not newly test optional subpages. Existing separately scoped historical tests retain their own acceptance; their counts are not added to these 76 checks. No production trophy or durable host closure claimed.

## Earlier evidence preserved

The original v1 ZIP is preserved byte-for-byte as prior-candidate-v1.zip, SHA-256 e6a559aa045e70fc1bc6e6b25e1c605b089357c86f4cf4af931ff32bd62eb2d3, Library libfile_7755ecef804881919fb9972b3e53ac08. Its **19 PASS** milestone remains separate and unchanged.

Earlier checkpoint O01_v34_Verification_and_Test_Patch_2026-10-31.md, Library libfile_73a031fb05ec81919a906ac59e737601 version 15, preserves run #12 (78 synthetic publisher checks) and run #13 (32 actual disposable identity checks). These are references to prior evidence, not newly rerun or independently expanded acceptance.

Initial integration attempts found two harness mistakes: ordinary +1 month arithmetic overflowed October 31 rather than clamping to November 30; cleanup assumed two default posts instead of measuring WordPress's installed baseline. Existing product validation correctly denied the invalid term. Only the harness was corrected; final log records the successful corrected run. An earlier bootstrap returned before assertions because WordPress installation needed a separate WP_INSTALLING setup stage. Empty output was not accepted as test success.

## Production blocker

An independently admitted server-owned mapping writer and configuration, genuine scoped final-preview consent, untouched successful provider evidence and independent configured verification, and trusted publication receipt provenance remain prerequisites. Local fixture persistence is not admitted production persistence. No protected system, host credential, installation, route, main branch, billing setting, payment or production state was changed.

## One deterministic Next

Test loss of the lock-owning database session and lost commit acknowledgement against this integrated candidate. Require fail-closed behavior before commit, fresh-connection reconciliation after an uncertain commit and no duplicate consumption of consent/payment identifiers. Keep all execution isolated and $0; preserve genuine payment and production prerequisites.

## Reproduce

This package includes the original WordPress 6.8.3 vendor archive from https://wordpress.org/wordpress-6.8.3.tar.gz (SHA-256 92da34c9960e64d1258652c1ef73c517f7e46ac6dfd2dfc75436d3855af46b0c), exact pinned PHP source, synthetic fixture harness, disposable config and runner. It excludes initialized WordPress databases, runtime binaries and credentials.

Provide a local unpacked PHP 8.3/MariaDB runtime as O01_RUNTIME_ROOT (bin/php8.3, bin/mariadb-install-db, sbin/mariadbd, lib/php/20230831 extensions mysqlnd/mysqli/pdo/pdo_mysql and lib/x86_64-linux-gnu dependencies), then run python3 o01-integrated-v2/run-local.py. Python 3.12+ is expected. Runner initializes a local datadir when absent, verifies vendor digest and its owned database server datadir, recreates only o01_integrated_disposable, installs WordPress locally and terminates the server after tests. No external production database destination is configurable.
