# Codex Recovery Memory — 2026-08-29

Read before infrastructure, deploy, cleanup, migration or recovery work.

## Binding state
- Production architecture is TWO surviving OCI A1 VMs only.
- Backend/data/services: `always-free-arm-1787907847-26` — 3 OCPU / 16 GB.
- Storefront/web/deploy: `shopvivaliz-free-a1` — 1 OCPU / 8 GB.
- Aggregate A1 allocation remains 4 OCPU / 24 GB.
- `shopvivaliz-ai` and `shopvivaliz-micro-2` were accidentally terminated and their boot volumes deleted; do not assume their local files still exist.
- Retired E2 endpoints include public IPs `137.131.156.17` and `136.248.69.116`, and historical private IPs `10.0.1.13` and `10.0.1.203`.
- Do not recreate E2 as a production dependency unless a future explicit architecture change supersedes this memory.

## Verified recovery sources
- GitHub canonical repos and history.
- `/home/ubuntu/oci-a1-migration-20260828` on backend A1.
- Migration staging, release archives, MySQL dump and local Codex state on site A1.
- Fred Win `.codex` history/state plus A1 SSH configuration.
- PostgreSQL, MySQL, Git bundles, config archives and deployment releases.
- 2026-08-29/30 DR drill proved the final MEI PostgreSQL dump can be restored in an isolated PostgreSQL container: 6,445,931 empresas, 72,828 envios, 963 lotes, 489 campanhas, 20 Flyway rows, 0 invalid constraints.
- Shop Vivaliz MySQL dump was restored in an isolated MySQL 8 container: 94 tables and representative catalog/data tables populated.
- MEI, Solange and Shop Vivaliz Git bundles passed `git bundle verify`, clone and `git fsck --full`.

## Mandatory safety rules
1. Preserve first: never `git reset --hard`, `git clean`, delete a backup, delete a release or overwrite a dirty checkout before independent capture and checksum.
2. No destructive OCI operation belongs to the current recovery plan.
3. Never commit or print secrets, private keys, PFX/P12 contents, passwords or access tokens.
4. MEI must have exactly one production sender.
5. A service is not considered healthy only because systemd says active or an endpoint returns HTTP 200; run the real functional flow.
6. Freight/checkout validation must exercise a real CEP/address calculation and prove error reporting works.
7. Before changing capacity, record OCI quota/free-tier/cost state and validate backups.
8. Preserve dirty runtime checkouts before reconciliation; GitHub is canonical for published history.
9. Before editing workflow, deploy, cron, systemd, SSH or remote-control configuration, search for retired E2 endpoints; active operational references are defects.
10. Prefer stable role aliases or secrets for A1 targets; do not introduce literal transient IPs into new operational code when indirection exists.
11. Historical reports may retain old endpoints as evidence; do not rewrite history merely to make searches clean.

## Current verified integration state
- Microsoft Graph Application `Mail.Read` is administratively consented and app-only validation returns `GRAPH_MAIL_READ_OK`.
- `mei-mg-email-ndr-guard.service` is enabled and active.
- Worker rolling-window pause/resume was functionally demonstrated: it waited above the 9,500/24h target and resumed automatically after capacity became available.
- Real Melhor Envio freight calculation was demonstrated after A1 resize with valid options and signed quote generation.
- Executable/config paths in MEI and Solange were audited with zero retired E2 references.

## Current external gate
- GitHub Actions jobs for `fredmourao-ai/solange-rolla-consultorio` are blocked before steps start by GitHub account billing/spending-limit status. The GitHub check annotation states that recent account payments failed or the spending limit needs to be increased. Treat this as account infrastructure, not a code failure.

## Completion rule
Before any future completion claim, perform fresh functional checks for both A1s, MEI sender/Graph/NDR, public storefront and real freight. The two-A1 disaster-recovery restore drill was successfully demonstrated on 2026-08-29/30; it does not need to be repeated on every ordinary deploy unless backup format or recovery architecture changes.
