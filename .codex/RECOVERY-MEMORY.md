# Codex Recovery Memory — 2026-08-29

Read before infrastructure, deploy, cleanup, migration or recovery work.

## Binding state
- Production architecture is TWO surviving OCI A1 VMs only.
- Backend/data/services: `always-free-arm-1787907847-26`.
- Storefront/web/deploy: `shopvivaliz-free-a1`.
- `shopvivaliz-ai` and `shopvivaliz-micro-2` were accidentally terminated and their boot volumes deleted; do not assume their local files still exist.
- Do not recreate E2 as a production dependency unless a future explicit architecture change supersedes this memory.

## Recovery sources
- GitHub canonical repos and history.
- `/home/ubuntu/oci-a1-migration-20260828` on backend A1.
- Migration staging, release archives, MySQL dump and local Codex state on site A1.
- Fred Win `.codex` history/state plus SSH config/key presence.
- Existing database dumps, Git bundles, config archives and deployment releases.

## Mandatory safety rules
1. Preserve first: never `git reset --hard`, `git clean`, delete a backup, delete a release or overwrite a dirty checkout before independent capture and checksum.
2. No destructive OCI operation belongs to the current recovery plan.
3. Never commit or print secrets, private keys, PFX/P12 contents, passwords or access tokens.
4. MEI must have exactly one production sender.
5. A service is not considered healthy only because systemd says active or an endpoint returns HTTP 200; run the real functional flow.
6. Freight/checkout validation must exercise a real CEP/address calculation and prove error reporting works.
7. Before changing capacity, record OCI quota/free-tier/cost state and validate backups.
8. Work on `recovery/two-a1-20260829` or isolated worktrees; production deploy stays unchanged until reviewed artifacts pass gates.

## Required reading
- `docs/operations/TWO-A1-RECOVERY-SPEC-2026-08-29.md`
- `docs/superpowers/plans/2026-08-29-two-a1-recovery-master.md`
- `docs/AGENTS.md`
- `REGRAS-AGENTES-CENTRALIZADAS.md`

## Current known external gate
Microsoft Graph Application `Mail.Read` remains blocked by administrative permission/consent (previous app-only attempts returned HTTP 403). Do not loop retries or broaden privileges automatically; record it as an external gate while recovering all independent functionality.

## Completion rule
Do not declare recovery complete until Shop Vivaliz, MEI and Solange pass their end-to-end audits and the two-A1 disaster-recovery restore drill has been demonstrated.
