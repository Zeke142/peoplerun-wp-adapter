# O01 — authenticated HTTP approval to exact HTTP delivery, v4

October 31, 2026 · America/Phoenix. Isolated HTTP milestone verified; full production acceptance remains OPEN.

## Verified output

**82 HTTP assertions PASS, 2 explicit SKIP groups, exit 0.** Three new PHP fixture files pass syntax validation. Actual WordPress 6.8.3, PHP 8.3.6, MariaDB 10.11.14 and real loopback HTTP requests run in one isolated session. The complete log is http-test-result.txt, SHA-256 51c85927303acefcca80a9340d53155db0d0fe552df65e963f2519d3e8fd0828. Counts include setup, negative authorization and cleanup assertions; they are not 82 separate commercial acceptance conditions.

The test owner logs in through actual WordPress wp_signon over HTTP and receives a real disposable session cookie. Nonces come from WordPress and are checked by the original o01_wp_verifier_v31. No CLI identity substitution is used in HTTP approval or publication requests. A separate authenticated test account and an anonymous client are denied owner nonces. Invalid approval/publication nonces and approval replay deny.

The owner retrieves the actual G1 exact-byte preview over HTTP. Approval loads the persisted owner/project/revision/reservation/quote mapping under the existing revision/URL locks, validates the canonical quote and invokes the original caller-owned approval claim and WordPress session/nonce verifier within the same PDO transaction. The fixture controller uses the original verifier directly because the old disposable integration hook hardcodes a different database name; that historical hook is preserved unchanged.

An authenticated HTTP publication request runs O01PersistedPublisher with the unchanged recovery-tested coordinator, canonical comparator, approval binding, receipt reservation and atomic route/receipt writer. Publication IDs and parent bytes come from actual G1/G2 fixture records and the persisted mapping. Anonymous HTTP delivery uses the existing uninstalled delivery endpoint and persisted route reader, after the new recovery reconciler checks receipt/route/ledger bindings. It does not fetch owner-only G1 data for anonymous requests.

## Observed acceptance

| Scenario | Result |
|---|---|
| Real HTTP WordPress signon, cookie and nonces | PASS |
| Anonymous and wrong-owner nonce access | Denied |
| Invalid nonce approval and publication | Denied |
| Owner HTTP preview → owner approval → atomic publish → anonymous HTTP body | Identical bytes, length and SHA-256 |
| Repeated publication request | Original receipt REPLAY; no extra route/receipt/ledger consumption |
| Stored parent-byte tampering | HTTP 503, empty response body |
| Restored fixture bytes | Original exact HTTP page returns |
| G1 content/revision changes after approval, before publication | Publication denied; no page content |
| Price, term, canonical quote or withdrawal state changed after approval | Publication denied; no page content |
| G1 preview superseded before approval | Approval denied, no extra claim |
| End-of-run readback | Exactly one successful route, publisher receipt and synthetic receipt-ledger entry; six authenticated claims, no seventh stale claim |
| Cleanup | All 23 created G1/G2 fixture posts and both test users removed, WordPress post baseline restored, candidate tables absent, HTTP and database child processes terminated |

Content invalidation is exercised through the actual G1 add-revision API. Price, term, canonical quote and approved→withdrawn changes are explicit isolated SQL fault injections, not a newly admitted product edit/withdraw API. The test proves that stale approval cannot publish after these mutations; it does not claim conversational editing or existing-page revision UX acceptance.

## Evidence limits and protected scope

The WordPress cookie/nonce approval is authentic for disposable test accounts. It is not independent external scoped customer consent. Positive payment identifiers and publication-verifier callbacks remain clearly marked SYNTHETIC, despite the original fixture ledger's stripe_live enum requirement. No genuine provider event, live payment, signing key, real customer or public hosted page is created or claimed. The test router is guarded by O01_DISPOSABLE_ACCEPTANCE=YES and bound only to 127.0.0.1; it must never be installed as a production endpoint.

TLS, physical iPhone UX, optional subpages and new session/transport fault injection are skipped by this HTTP suite. The earlier recovery and calendar tests retain their independent evidence. Production still requires the separately admitted mapping writer/configuration, genuine final-preview consent/provider evidence and independent publication authority. No source branch, main branch, workflow, production host, credential configuration, billing or protected system was changed. Spending: $0.

The first HTTP attempt passed login, approval, publication, exact delivery, replay and tamper denial, then stopped because its test assumed all unavailable-page denials must be HTTP 503. The existing reader correctly returns 404 for an absent route; the corrected assertion accepts 404 or 503 only with an empty body. Fixture receipt timestamps are now taken from the publisher's verification time argument to avoid a seconds-boundary mismatch. The final log is the complete successful corrected run.

## Prior milestones preserved

Input O01_Session_Loss_Commit_Recovery_v3.zip independently matched SHA-256 e0d2f8dac7b4c80bede0b58dc5912e8e170bfa0e7c56d51aade4cc960a0c33bf, Library libfile_34e73d7f6724819185cb75800b690cca. The four persisted identity/publisher/coordinator/reconciler PHP classes are byte-identical to that archive. All 45 original repository source pins still match commit 450ce0ba110e34c1d6260919e23993e870526649.

The **64 recovery** and **76 integration** logs remain intact in recovery-test-result.txt and integrated-test-result.txt. Their receipts/resume histories remain under history/v3 and history/v2. The **19-check v1** archive remains byte-for-byte in prior-candidate-v1.zip. These counts are separate and are not added together as product proof. None was unnecessarily rerun for these additive HTTP fixture files.

## One deterministic Next

Commit the exact verified candidate and HTTP harness to the existing isolated o01-approval-binding-fixtures-20261031 branch after checking its current head and file scope. Preserve main, protected systems and existing workflows. Return the exact commit/diff receipt; no merge, workflow dispatch or production deployment.

## Reproduce

Use the local runtime layout and vendor archive described in history/v2/RESUME.md. Set O01_RUNTIME_ROOT to the unpacked PHP/MariaDB runtime and run `python3 o01-http-v4/run-local.py http`. The runner verifies the pinned WordPress archive and its own database datadir, installs disposable WordPress, creates fixtures, runs the HTTP suite and terminates its child server processes. Cookie jars live only in Python memory; none are saved. The fixture-only config contains no real credentials. Initialized WordPress files/databases and runtime binaries are excluded from this deliverable.
