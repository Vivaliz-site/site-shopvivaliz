# Codex Recovery Memory — 2026-08-30

Read before infrastructure, deploy, cleanup, migration or recovery work.

## Binding state
- Production architecture is TWO surviving OCI A1 VMs only.
- Backend/data/services: `always-free-arm-1787907847-26`, currently 3 OCPU / 16 GB RAM.
- Storefront/web/deploy: `shopvivaliz-free-a1`, currently 1 OCPU / 8 GB RAM.
- A1 compute and memory are currently BRL 0.00 in OCI Usage API. Do not reduce CPU/RAM merely to address the recent small bill: the observed charges were Block Storage Storage/Performance Units while the legacy boot-volume footprint overlapped the A1 volumes.
- `shopvivaliz-ai` and `shopvivaliz-micro-2` were accidentally terminated and their boot volumes deleted; do not assume their local files still exist.
- Retired E2 endpoints include public IPs `137.131.156.17` and `136.248.69.116`, and historical private IPs `10.0.1.13` and `10.0.1.203`.
- Do not recreate E2 as a production dependency unless a future explicit architecture change supersedes this memory.

## Recovery sources
- GitHub canonical repos and history.
- `/home/ubuntu/oci-a1-migration-20260828` on backend A1.
- `/home/ubuntu/recovery-two-a1/snapshots/20260830T023106Z` with verified MEI/Solange bundles, patches, manifests and checksums.
- MEI local recovery commit `183200c` on `recovery/local-dirty-20260830`.
- Solange local recovery commit `848c206` on `recovery/local-dirty-20260830`.
- Migration staging, release archives, MySQL dump and local Codex state on site A1.
- Fred Win `.codex` history/state plus SSH config/key presence when that host is reachable.
- Existing database dumps, Git bundles, config archives and deployment releases.

## Mandatory safety rules
1. Preserve first: never `git reset --hard`, `git clean`, delete a backup, delete a release or overwrite a dirty checkout before independent capture and checksum.
2. No destructive OCI operation belongs to the current recovery plan.
3. Never commit or print secrets, private keys, PFX/P12 contents, passwords or access tokens.
4. MEI must have exactly one production sender.
5. A service is not healthy merely because systemd says active or an endpoint returns HTTP 200; run the real functional flow.
6. Freight/checkout validation must exercise a real CEP/address calculation and prove error reporting works.
7. Before changing capacity, record OCI quota/free-tier/cost state and validate backups. Current decision: retain 3/16 backend + 1/8 site unless new cost evidence contradicts it.
8. Work on `recovery/two-a1-20260829` or isolated recovery branches/worktrees; production deploy stays unchanged until reviewed artifacts pass gates.
9. Before editing any workflow, deploy script, remote-control route, cron, systemd unit or SSH config, search for all retired E2 IPs/hostnames and classify every occurrence. Active operational references to retired E2 endpoints are defects.
10. Prefer stable role aliases or environment/GitHub secrets for A1 targets; do not introduce literal transient public IPs into new operational code when an indirection is available.
11. Historical logs/reports may retain old endpoints as evidence; do not rewrite history merely to make searches clean. Regression gates must distinguish executable/config paths from historical evidence.
12. The recursive retired-endpoint gate is GREEN after fixing five active offenders (`DEPLOY_NOW.ps1`, `EXECUTA-ISTO-NO-SERVIDOR.sh`, `TEST-AGENT-ACCESS.sh`, `bootstrap-claude-access.sh`, `installer/make-admin.php`). Keep it green.
13. Site Desktop Commander has the new `TOKEN_REFRESHED` persistence patch installed, but its provider session is currently expired (`PROVIDER_CONNECTED=false`). Do not clone another host's session or bypass provider authentication.
14. Backend/site Codex authentication is currently unavailable; do not copy auth material blindly between hosts. Continue safe recovery through GitHub Actions/direct backend operations until official login is restored.

## Git reconciliation facts
- MEI preserved base `64e7c71` is an ancestor of GitHub main; GitHub main is four commits ahead. Local dirty work is now committed as `183200c`.
- Solange preserved base `e423e46` is an ancestor of GitHub main; GitHub main is three commits ahead. Local dirty work is now committed as `848c206`.
- Backend GitHub shell credentials are missing. Never embed a PAT in a remote URL; restore secure Git access before fetch/push reconciliation.

## Required reading
- `docs/operations/TWO-A1-RECOVERY-SPEC-2026-08-29.md`
- `docs/superpowers/plans/2026-08-29-two-a1-recovery-master.md`
- `docs/operations/recovery/2026-08-30-execution-checkpoint-01.md`
- `docs/AGENTS.md`
- `REGRAS-AGENTES-CENTRALIZADAS.md`

## Current known external gate
Microsoft Graph Application `Mail.Read` remains blocked by administrative permission/consent (previous app-only attempts returned HTTP 403). Do not loop retries or broaden privileges automatically; record it as an external gate while recovering all independent functionality.

## Completion rule
Do not declare recovery complete until Shop Vivaliz, MEI and Solange pass their end-to-end audits, no executable/config route targets a retired E2 endpoint, and the two-A1 disaster-recovery restore drill has been demonstrated.
