# O01 v7 — source-only staging admission (2026-10-30)

**Decision: NOT ADMITTED FOR DISPATCH.** No commit, dispatch, installation or host change.

## Artifact reconciliation

- Prior repository staging ZIP: `5ea4343ee04eddce6de0949664ae0be267f1bede792b20fbb12922d9df421bac` verified against current local bytes.
- Harness v7 ZIP: `1197fe1a53d3ddb021fef8f6cfad3f78b3d2f0bc3612fbdd7d553a5b2859d4e3` verified against embedded bytes.
- WordPress 7.1.4 tar.gz: `881e53a4681ff2cd911651b11cccdede5ae477ddf0765af5d6a1a8b219a1d835`, **owner-observed SHA256 of HTTPS stream**. Not independently re-downloaded here.
- Debian 13 amd64 OCI manifest: `sha256:e2914ec98e08b24b4cee3cefcb9f90dfb5519f8d215a2ccf0c9a49b10b6e43de`, **prior reported upstream metadata**; registry pull and platform check on runner unexecuted.
- GitHub checkout action: `11d5960a326750d5838078e36cf38b85af677262`, pinned in source; prior GitHub API resolution.

## Source inspection and exact admission gaps

The workflow contains a manually triggered `workflow_dispatch`, `contents: read`, an exact harness hash gate and `ubuntu-24.04` runner with a Debian image built by Docker. The generated runtime-pins.env has no secrets. The launch sequence initializes MariaDB on loopback, installs WordPress into a disposable database, and calls the archived `run-http-local.sh` which contains the three suite invocations. Docker runtime uses `--network none`; build step requires external package/archive retrieval. The source has not been exercised with a real Docker/MariaDB/WordPress runtime.

**Critical gaps:** (1) source-only artifacts are not staged in the GitHub repository; (2) no independent authority to commit/dispatch; (3) WordPress and Debian byte/platform identity need verification at runner build time; (4) shell/PHP/application startup, port and TLS conditions still need actual runtime proof; (5) no attempt-bound receipt, sanitized test result or accepted production behavior.

**Potential bootstrap issue for review before execution:** `wp_install` expects WordPress upgrade includes and valid environment; its return check and `wp-config.php` generation are untested against real WordPress. MariaDB socket/auth startup and PHP built-in server/router behavior also remain runtime risks. The Dockerfile uses Debian digest in ARG before FROM; validate with actual runner before approval. Do not relabel source checks as integration PASS.

## Next

Request a bounded, separate repository-write authorization to stage this exact reviewed packet (without dispatch). Before any workflow dispatch, require a reviewed repository diff, a runner-side immutable artifact hash check, and explicit separate dispatch approval; no production target.
