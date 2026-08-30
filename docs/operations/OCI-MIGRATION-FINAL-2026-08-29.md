# OCI migration final state — 2026-08-29

## Control-plane state
Final OCI inventory remains:
- `always-free-arm-1787907847-26` — `VM.Standard.A1.Flex` — RUNNING — 3 OCPU / 16 GB.
- `shopvivaliz-free-a1` — `VM.Standard.A1.Flex` — RUNNING — 1 OCPU / 8 GB.
- `shopvivaliz-ai` — `VM.Standard.E2.1.Micro` — TERMINATED.
- `shopvivaliz-micro-2` — `VM.Standard.E2.1.Micro` — TERMINATED.

The two E2 boot volumes are TERMINATED. The two A1 boot volumes remain AVAILABLE. Aggregate A1 allocation remains 4 OCPU / 24 GB.

## Backend A1 verified state
Post-resize functional verification showed:
- MEI API, worker, queue replenisher, monitor, NDR guard, Docker and Desktop Commander active.
- failed systemd units: 0.
- exactly one production sender.
- Microsoft Graph app-only validation: `GRAPH_MAIL_READ_OK`.
- NDR guard enabled and active.
- rolling 24h target behavior demonstrated: worker waited while above 9,500 sends/24h and automatically resumed once capacity became available.
- monitor reported `health=ok`.

The brief PostgreSQL connection failures immediately after resize occurred while the database was still starting; systemd retried and recovered without operator bypass.

## Storefront A1 verified state
Post-resize verification showed Apache, MySQL, queue worker and Desktop Commander active with 0 failed systemd units.

Public functional checks returned HTTP 200 for home, cart and checkout. A real Melhor Envio quote was executed after resize and returned two valid Jadlog options; the lowest observed quote was R$ 16.25. No mock fallback and no order creation were used for this test.

## Disaster-recovery restore drill
A real isolated restore drill was executed without touching production databases.

### MEI PostgreSQL
- source: `BACKUPS/20260829-cutover/mei_mg_email.final.pgcustom.dump`.
- `pg_restore` completed with exit code 0.
- restored database checks: 6,445,931 empresas; 72,828 envios; 963 lotes; 489 campanhas; 20 Flyway rows.
- invalid PostgreSQL constraints: 0.
- temporary DR container and volume were removed after validation.

### Shop Vivaliz MySQL
- source dump SHA256: `cdd1ed275ff6fc7aa7fc823a4bb521494711269e344a4b4fc3f96420c4a1d145`.
- restored successfully in isolated MySQL 8 container.
- 94 tables restored; representative data included `products=257` and `olist_product_images=1276`.
- temporary DR container and volume were removed after validation.

### Git recovery bundles
The MEI, Solange and Shop Vivaliz migration bundles each passed:
- `git bundle verify`;
- clone into an isolated temporary repository;
- `git fsck --full`.

Temporary clone/verification directories were removed after validation.

## Operational cleanup
- retired E2 SSH aliases were removed from Fred Win after preserving a backup of the prior SSH config.
- executable/config searches in MEI and Solange found zero retired E2 references.
- unregistered orphan OCI keypairs created on the A1s were removed after fail-closed hostname validation; direct verification confirmed absence on both hosts.
- Fred Win OCI private-key ACL is restricted to SYSTEM, Administrators and the FRED account.
- temporary OCI cleanup workflow was removed after successful execution.

## Solange recovery
- tooling was hardened so ESLint/Vitest do not traverse operational `.worktrees` or `backups`.
- local canonical gates passed: lint, typecheck, 79 test files / 207 tests, dependency architecture, module contracts, migrations and Next.js build.
- recovery PR #43 was merged; resulting main merge commit: `e300676bc7aac0965676f1bc92181266f6ae2abc`.
- GitHub Actions jobs currently fail before executing any step because GitHub reports account billing/spending-limit blockage. This is an external account gate, not a code-test failure.

## Residual cautions
- The original E2 instances and their boot volumes are permanently reflected as terminated in the current OCI control plane; application-level backups are not equivalent to full boot-volume images.
- MEI runtime still contains preserved local operational diffs; do not hard-reset or bulk-clean that checkout without reconciling the saved patch/backups first.
- Historical reports may retain retired E2 endpoints as evidence; active executable/config references must not.

## Completion evidence
The recovery architecture now has demonstrated functional production checks plus isolated restores for both primary databases and all three critical Git bundles. Future completion claims still require fresh checks rather than relying solely on this report.
