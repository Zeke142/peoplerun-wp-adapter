# Dormant engineering runner wrapper v1

No workflow references this wrapper. Preparation does not execute it.

The wrapper is derived from the exact pinned private admission script and inserts the engineering phase in the same container after source-bound rollback and successful denials. Fresh private receive is now inside the wrapper, so an archive supplied by an earlier run cannot substitute for its own handshake. The receive token is removed before Docker setup. Only public metadata/ciphertext goes to the existing transfer branch during a future selected run.

Future caller requirements:
- Explicitly select the reviewed wrapper revision and runner.lock.json digest; download executable files by immutable commit and verify pins before calling any of them.
- Use existing public repository, standard ubuntu24.04 hosted runner, Linux/X64, contents-write token only for private_receive.py. The reviewed future workflow must independently establish standard zero-cost runner configuration; environment labels alone are not a billing audit.
- Populate actual GITHUB_RUN_ID, GITHUB_RUN_ATTEMPT, GITHUB_SHA, GITHUB_REPOSITORY, RUNNER_TEMP, RUNNER_ENVIRONMENT, RUNNER_OS and RUNNER_ARCH from the real job.
- Set O01_REPOSITORY to Zeke142/peoplerun-wp-adapter, O01_REPOSITORY_VISIBILITY=public, O01_BASE_HELPER_DIR to an absolute directory containing the exact base helper filenames in runner.lock.json.
- Put the unchanged seven engineering files at this directory beside runner-wrapper.sh, runner_support.py and runner.lock.json. Do not confuse the private dn29-no-payment.php helper with its older root counterpart.
- Set O01_ENGINEERING_OUTPUT_DIR to the unused exact RUNNER_TEMP/o01-engineering-result-RUNID-ATTEMPT path. Existing output paths are rejected.
- Only for a separately selected future execution, set O01_ENGINEERING_RUNNER_EXECUTE=1 and provide the ephemeral O01_HANDSHAKE_TOKEN. Otherwise wrapper/support reject before Docker or network work.
- Preserve outer always-cleanup/job15minute timeout. No dispatch, workflow or public port is installed here.

The wrapper forces original denials on and unrelated old SQL/reader phases off. It provisions the inherited pinned runtime and retains inherited memory/CPU/PID/tmpfs/network/filesystem admission checks unchanged. It transfers pinned engineering helpers through container stdin, generates private mechanics there, then invokes run.py under container and host timeouts. No original trust checks are changed.

Cleanup runs on exit and explicit INT/TERM handling. Each container has a unique invocation suffix and ownership label. Evidence is collected before teardown; deletion requires matching label, and removal is independently rechecked. Docker daemon/permission errors are not treated as absence. Only owned build/private-input paths under RUNNER_TEMP are deleted.

Aggregate private evidence is capped at2MiB. Source-bound rollback/denial proof, engineering receipt and separate cleanup result are retained when available. A successful phase is not final PASS: receipt must have all ten PASS cases in order, exact candidate lock, actual run binding, fresh receive record, matching rollback/denials, successful container removal and both private paths removed. No receipt or mismatched proof yields FAILED_OR_UNVERIFIED. Cleanup failure never becomes success.

Known preparation limits: Bash syntax, Python compilation and eight local guard/control-flow tests passed. Tests use no Docker, no network and no actual private receive. PHP lint, actual resource admission, EC01–EC10 and destructive cleanup behavior remain UNEXECUTED. The original six generator checks are separate prior evidence. No production or genuine payment acceptance follows from any future engineering PASS.

Allowed diff versus base is recorded in runner-diff.json. The structural inverse comparison returns byte-identical base when only the listed lifecycle/hook/label/footer changes are reversed. This is text/control-flow review, not independent runtime verification.

No workflow files changed. Source-only commits do not match the existing four workflow trigger paths. No job should be created by these additions; verify Actions readback after committing.
