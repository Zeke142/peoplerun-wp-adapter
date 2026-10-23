# Dormant engineering publisher-commit candidate v1

This directory contains a generator, CLI harness and bounded orchestrator. No workflow references it. Do not add it to production or claim DN29/payment acceptance.

Original archive pin: b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871.

`generate.py` reads the exact private archive, checks both source-file pins and writes a private `mechanics.php` plus `allowed-diff.json`. The generated module is never committed: regenerate privately, check `sources.lock.json` and retain its original archive provenance. It assumes a post-trust engineering fixture and reuses the retained transaction/lock bodies. Original DN29 functions and trust remain unchanged.

`harness.php` loads unchanged DN29 only in CLI UID10001, checks exact isolated database/table binding, source hashes, exclusive routes, no keys/authority, same-run rollback and checkpoint-matched denial marker. Synthetic fixture identifiers use eng_ and provider eng_no_payment. It registers no REST route. Real wpdb operations are delegated through a fault-observation wrapper; errors and commit acknowledgement loss are simulated only after their specified real operation. Observer processes use separate database connections.

`run.py` is dormant unless explicitly selected with both an execution argument and environment flag. It must run INSIDE the already admitted network-none disposable container, after fresh source-bound rollback and denials, with owned HTTP stopped. It checks file pins and performs PHP lint before DB cases. The caller must independently verify the established container resource/network boundaries; the CLI flag is not host authority.

Future integration must copy these files plus lock under a private container helper directory; generate the private module in `/run/o01-eng-` followed by the first12hex SHA256 of the exact run:attempt binding. Use the same value for O01_ENGINEERING_DIR. No job or runtime is created by these scripts. Do not set authority admitted or supply verifier keys.

EC01–EC10 are implemented as candidate cases: paired commit, simultaneous publisher lock contention, exact and individual uniqueness conflicts, synchronized interruption after receipt/both inserts, owned process termination, lost commit acknowledgement plus retry readback, post-case lock reuse, publisher-derived HTTP fixture delivery/expiry/corruption and paired checkpoint restoration. Manifest uniqueness uses an explicitly labelled atomic-layer subcase. Process termination closes only the worker-owned connection, not the database server. This intentionally differs from the proposal's SQL KILL approach and needs no session-kill privilege.

Observer compares full row identities/bytes; an orphan row fails. Per-case records contain actual readback data and reported result. Case subprocess deadlines30s; normal phase150s with30s reserved for restore, total180s. Existing outer container/job cleanup remains required even if script-level restore fails. The orchestrator stops only its own child processes and attempts restore on failure. Final EC10 restores the pre-existing clean `/backup/checkpoint` rather than replacing it with a post-test checkpoint. Hashes must match the same-run proof before restore.

Local checks are generator tests and Python compilation ONLY. PHP executable, WordPress and database are unavailable in the preparation environment. PHP lint and all real harness behavior remain UNEXECUTED. No success receipt exists.

`test_generate.py` expects the private original archive in the existing workspace DN29 source path. It is a local preparation test and is not wired to Actions. It checks deterministic generation, inverse body equivalence, archive/source drift rejection, anchor rejection and original-byte preservation.

No paid resource, deployment, workflow change, signed event, payment, issuer admission, G3 activation or HOLD release belongs to this candidate.
