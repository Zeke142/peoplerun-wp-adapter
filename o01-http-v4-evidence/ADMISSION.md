# O01 HTTP v4 source admission

Source archive: O01_Authenticated_HTTP_Approval_Delivery_v4.zip
Library: libfile_ecf0e80c2dcc8191892c809f509b9d0a
SHA-256: 1ae7e73b14b05e741b425d9867339db2f5b98c8efa500d00c19b20d748ccb7aa
Base: 450ce0ba110e34c1d6260919e23993e870526649

Nine additive PHP candidate/test files are at o01-wordpress-http/. Four exact harness files are at repository root, preserving their relative paths. All 45 pre-existing source files match the package byte-for-byte and remain unchanged. Evidence/history text is preserved here; its SHA256SUMS paths describe the original archive layout, not this repository layout.

To reproduce, restore vendor/wordpress-6.8.3.tar.gz from the pinned Library archive to repository root (SHA-256 92da34c9960e64d1258652c1ef73c517f7e46ac6dfd2dfc75436d3855af46b0c), provide the separate PHP/MariaDB runtime documented in history/v2/RESUME.md, set O01_RUNTIME_ROOT, then run python3 run-local.py http. Vendor/runtime binaries and prior-candidate-v1.zip remain in the immutable Library package; they are not committed here. Do not use against an existing database or host.

82 HTTP assertions and prior 64/76/19 milestones are historical disposable test evidence; no tests were rerun during source admission. Payment callbacks are synthetic. No production acceptance, external payment proof, workflow change or dispatch is established.
