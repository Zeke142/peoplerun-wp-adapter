# O01 — database-session loss and commit recovery, v3

October 31, 2026 · America/Phoenix. Production acceptance remains OPEN.

## Verified result

**64 new recovery assertions PASS, 2 explicit SKIP groups, exit 0.** Actual WordPress 6.8.3, PHP 8.3.6 and MariaDB 10.11.14. The existing **76-check integrated suite also PASS**, rerun because its coordinator and adapter changed. The prior **19-check milestone is preserved separately**, not added to either suite's counts. Counts include identity setup and cleanup assertions.

Recovery output SHA-256: 31b006285c1524bc20cf1f09eb3ac90c003ba319a906edd40e388f522b7ff98c.
Integration regression output SHA-256: e8d38e736172b454ac4e8a93b7efd34b6890331fada5c9429f125cf91dec66d9.

## Change and reason

The inherited atomic publisher reports a general exception as DENIED. That alone cannot distinguish a rolled-back transaction from one that committed before its acknowledgement was lost. The local coordinator now tracks when COMMIT has been attempted but not confirmed. The integrated adapter projects this as COMMIT_OUTCOME_UNKNOWN_LOCAL_CANDIDATE, publication_status UNVERIFIED, blind_retry_allowed=false. No production success receipt is fabricated.

New O01PublicationReconciler performs read-only recovery on a fresh server-owned PDO, using a single repeatable-read, read-only transaction. It checks original request/intent, publisher receipt hash, exact route identities/digests/bytes/term and the unique consent/event/payment ledger bindings against trusted pinned expected context. It returns committed/no-retry, absent/retry-eligible, or unresolved/no-retry. Reconciliation denies retry while any matching revision, URL or publisher lock is still owned. No automatic retry, receipt issuance, payment or database write follows from reconciliation. An absent snapshot permits only a deliberate retry through the locked publisher using the original request and identifiers; it does not reserve that absence against another worker. Retry eligibility is a local state classification, not host authorization.

## Fault tests observed

| Fault | Observed result |
|---|---|
| Kill lock-owning session before receipt verification/writes | DENIED, verifier not reached, route/receipt/ledger absent; fresh reconciliation proves absence before deliberate same-ID retry |
| Kill session at COMMIT boundary after receipt reservation | Outcome UNKNOWN, blind retry blocked; fresh connection proves rollback/absence; original IDs safely reused once |
| Real COMMIT succeeds, then application acknowledgement is discarded and session killed | Outcome UNKNOWN; fresh connection verifies committed tuple; same request REPLAY returns original receipt without re-entering authority or verifier |
| Repeat recovery retry | Ledger count unchanged; no duplicate consumption of synthetic consent/event/payment identifiers |
| Committed term later expires | Still reconciles as consumed/committed; EXPIRED delivery state and retry denied |
| Wrong owner, original request key or provider event | UNRESOLVED; retry denied |
| Tampered persisted parent bytes | UNRESOLVED; retry denied; after fixture restoration read-only reconciliation succeeds |

Session termination is real MariaDB KILL CONNECTION against the exact test-owned PDO connection ID, from an independent disposable connection. Server processlist confirms the killed session is absent. The lost-ack fault is injected **at the application's return boundary after a real successful COMMIT**, not by dropping TCP packets or intercepting wire-level acknowledgements. The pre-COMMIT kill and post-COMMIT lost-ack tests cover distinct outcomes; neither is represented as packet-level transport proof.

All nine G1/G2 fixture posts from the recovery suite are removed, WordPress post count returns to its measured baseline, candidate mapping/ledger/publisher/approval tables are checked absent, and shared locks are checked released. The local runner terminates its MariaDB child in finally. The terminated PDO is discarded before recovery. No database server shutdown or production session is targeted.

## Scope and preserved evidence

Input v2 ZIP independently matched SHA-256 076fafc9779ba4195a093e2fa22c9b3ef7d10889eb788c9d0faa45683b6bafbb, Library libfile_fb4f72cb23288191b4134c16fd7bf1d2. Original v2 coordinator/adapter, resume, receipt and checksums are retained in history/v2; original 76-check log remains integrated-test-result.txt and is byte-identical after regression. Original v1 archive remains prior-candidate-v1.zip, SHA-256 e6a559aa045e70fc1bc6e6b25e1c605b089357c86f4cf4af931ff32bd62eb2d3, preserving its 19-check milestone.

All 45 original repository sources still match their Git blob pins at 450ce0ba110e34c1d6260919e23993e870526649. Changes are limited to local O01IdentityLockedStore, O01PersistedPublisher, the new O01PublicationReconciler and recovery-check harness, plus runner suite selection and evidence. No repository ref, workflow, hosted plugin, production route or protected system was changed.

The first recovery attempt passed both pre-commit kill cases, then its test incorrectly expected the raw inner store to return false after an acknowledged internal commit/session kill. That raw PDO correctly raised MySQL error 2006. The assertion now accepts that exact connection-loss error or a tainted-store false return; unrelated errors still fail. The final log is the complete successful corrected suite.

## Remaining blocker

Positive approval/payment/publication callbacks remain explicitly SYNTHETIC. No genuine final-preview consent, provider payment, real customer or production admission is proven. HTTP customer authentication and public serving are not exercised by the recovery harness. Packet-level transport fault injection remains untested. Production use still requires the admitted private mapping writer/configuration and independently trusted genuine consent/provider/publication proof sources. Existing standalone tests do not substitute for a combined commercial acceptance path.

## One deterministic Next

Connect the existing authenticated WordPress HTTP approval and exact HTTP delivery checks to this recovery-tested integration, then verify preview-to-approval invalidation and byte-identical delivery in the same isolated $0 run. Keep all positive payment callbacks explicitly synthetic until genuine proof is independently admitted.

## Reproduce locally

Provide the unpacked local PHP/MariaDB runtime described in history/v2/RESUME.md via O01_RUNTIME_ROOT. Run `python3 o01-recovery-v3/run-local.py recovery` for the new fault suite, or `python3 o01-recovery-v3/run-local.py integrated` for the prior integration regression. Runner uses the pinned vendor archive, local-only WordPress config, fixed loopback port, owned datadir verification and disposable database; no external production destination is configurable. Runtime binaries and initialized databases are excluded from this package. Genuine credentials are neither required nor included.
