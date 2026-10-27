# O01 — Disposable GitHub Actions InnoDB acceptance package
2026-10-27; SOURCE-ONLY; no GitHub writes or workflow dispatch.

Verified existing PDO driver archive SHA256 cd5995ee4c084fc7550ff4af4f790057051b93a52e85058a4af90d80b148dc8a [see note: authoritative full value from prior artifact is cd5995ee4c084fc7550ff4af4f790057051b93a52e85058a4af90d80b148dc8a].
The package includes driver + previously built writer, reader and term sources. The new workflow creates disposable MariaDB with localhost port binding, installs php-mysql, uses per-job synthetic DB records, and calls the real PDO driver.

No run executed: real InnoDB acceptance is unverified. Standard public repository runners are generally free, but account-level Actions permission / current billing and run admission remain unverified. Repository write and workflow_dispatch explicitly NOT authorized by this package. No access to real customers, production, or O01 secrets.

Next single gated action: owner authorize a repo-scoped commit of this dedicated workflow and acceptance source, then one manual workflow_dispatch after confirming $0 account admission. A connected tool capable of the exact repo write/dispatch is additionally required; no write occurred.
