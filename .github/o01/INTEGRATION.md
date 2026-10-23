# DN29 no-payment integration — prepared v1

Scope: the existing ephemeral GitHub runner branch, real WordPress and MariaDB. This prepares engineering execution; it does not deploy DN29 to SiteGround, admit trust, process payment or close O01.

## Dependency lock

`runtime.lock.json` pins the Alpine base by SHA-256, 67 APK filenames and SHA-256s, all 75 installed-package versions, architecture, WordPress 7.1.3 URL/hash and the exact DN29 archive hash. Fetches accept only these bytes. An unavailable or altered package stops the build; no latest-version fallback exists. Runtime image builds install local verified APKs with network disabled. The standard GitHub VM/Docker build toolchain remains provider-managed and its observed version must be recorded; no bit-for-bit VM reproducibility is claimed.

## Execution order

1. Provision the pinned runtime and verify network, filesystem and cgroup boundaries. No production source or credentials enter the job.
2. Validate the exact owner-supplied DN29 archive with `stage_dn29.py`. Preparation's default validation mode writes no plugin files. The original archive is not committed to this public repository.
3. In a separately selected staging execution, quiesce the owned HTTP process. The explicit `--stage-and-verify-rollback` mode installs only the eight pinned runtime files, activates DN29's schema on the dedicated database and runs `rollback.sh` with the DN29 source hash. It never loads original/regression publishers or the fictional signed test suite.
4. NP08: checkpoint matching WordPress files/config and database dump; verify checkpoint hashes; introduce a labelled file marker and synthetic database table; restore only the fixed staging root/database; independently regenerate file and SQL readbacks and compare bytes. Only then write `/run/o01-rollback-proof.json` bound to run ID/attempt, source hash and checkpoint hashes. HTTP must remain stopped during this sequence.
5. NP01–NP04: invoke the `denials` phase on real WordPress, proving exclusive route/function ownership, real InnoDB tables and rejection of anonymous, unsigned, quarantined and invalid-proof requests without release/receipt row changes. Write a same-run, same-source, same-checkpoint denial-phase result only after success.
6. NP05–NP06: the optional `faults` phase additionally requires explicit caller selection and the matching denial-phase result. It exercises two owned SQL sessions, lock contention/release, transaction visibility, uniqueness, rollback and disconnection on a separate synthetic probe table. It cannot prove successful DN29 publisher commit.
7. NP07: the separately selected `reader` phase uses directly inserted, clearly labelled synthetic release rows, never fictional receipt rows. Start only the owned loopback HTTP server after rollback. Verify exact bytes/no-store, expiry and corrupt-row denial. Delete only the known synthetic row on exit. Protected loopback delivery is not public customer delivery.
8. Recheck boundaries, source hashes and empty receipt ledger; collect actual evidence; destroy the container and disposable VM. Retain no customer data or public endpoint.

The activation phase and the `prepare-dn29.sh` script are not invoked by the push admission workflow. That workflow verifies the pinned baseline, performs the actual baseline rollback drill and lints the prepared integration assets only. A BASELINE_NO_DN29 proof cannot pass the DN29 phase gate. No DN29 positive publish, issuer configuration, verifier keys, signed payment events, live/test processor requests or G3 activation are permitted.

## Gate behavior and limits

Missing, stale, misbound or false rollback proof stops all DN29 test phases. The faults phase also stops without its matching denial-phase result. These are local engineering sequencing gates, not an issuer, approval record or PeopleRun host authorization. Environment flags choose a phase; they cannot grant authority.

All baseline container controls remain in force: no network except private loopback, no host binds/socket/ports, read-only image, unprivileged UID, all capabilities dropped, bounded writable tmpfs/inodes, 768 MiB aggregate memory, 1 CPU, 64 PIDs and a 15-minute job limit. PHP memory is configured at 128M, execution time at 15 seconds; these are application settings rather than a universal wall-clock deadline or immutable per-process kernel cap. MariaDB may round connection settings upward; two-session tests must not claim a hard two-connection server limit.

## Evidence classification

Actual baseline pin/rollback run: `39877590688`, commit `322d2f2bbe4b7be4edb69e35e538b31850d92e4a`, succeeded. Its checkpoint is labelled BASELINE_NO_DN29. Final syntax/preparation validation is recorded separately in the delivery checkpoint. DN29 staging activation, NP01–NP07 behavior and DN29-bound NP08 remain UNEXECUTED until the explicit prepared flow runs with the exact private archive.

Original DN29 pin: `b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871`.
