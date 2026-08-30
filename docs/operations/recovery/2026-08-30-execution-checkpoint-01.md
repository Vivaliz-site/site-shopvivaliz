# Two-A1 Recovery — Execution Checkpoint 01

Date: 2026-08-30 UTC
Branch: `recovery/two-a1-20260829`

## Binding architecture

Production is intentionally consolidated onto exactly two OCI A1 instances:

- Backend role: `always-free-arm-1787907847-26` — 3 OCPU / 16 GB RAM.
- Site role: `shopvivaliz-free-a1` — 1 OCPU / 8 GB RAM.
- Retired E2 instances/addresses are historical only and must never be used by active workflows, scripts, configs, services, cron jobs or agent instructions.
- No destructive OCI action is permitted during recovery. Never terminate an instance/volume or overwrite recovery data as part of an audit.

## OCI evidence

Read-only OCI inventory confirmed exactly two active A1 instances. Active boot volumes total 147 GB (100 GB backend + 47 GB site). No boot-volume backups were found in OCI for the deleted E2 boot volumes.

Recent Usage API breakdown proved that A1 compute and A1 memory are currently charged at BRL 0.00. The small charges observed on 2026-08-28/29 were Block Storage Storage/Performance Units while the legacy boot-volume footprint still overlapped the two A1 volumes. Therefore CPU/RAM must not be reduced merely to chase those charges. Current boot-volume VPUs are being verified separately.

## Retired endpoint gate

The retired endpoint contract was expanded to recursively cover active executable/configuration paths. TDD RED found five active offenders and they were corrected:

- `DEPLOY_NOW.ps1`
- `EXECUTA-ISTO-NO-SERVIDOR.sh`
- `TEST-AGENT-ACCESS.sh`
- `bootstrap-claude-access.sh`
- `installer/make-admin.php`

The gate is GREEN together with the existing production/dated/legacy workflow retirement contracts. Historical references may remain only in non-executable documentation/history.

## Preservation

Before reconciliation, backend working state was frozen under:
`/home/ubuntu/recovery-two-a1/snapshots/20260830T023106Z`

MEI and Solange each have verified Git bundles, binary tracked-worktree patches, untracked-file manifests, HEAD records and SHA256 manifests.

The dirty working trees were also converted into local recovery commits without changing runtime file contents:

- MEI: `183200c` (`recovery/local-dirty-20260830`), 47 files preserved.
- Solange: `848c206` (`recovery/local-dirty-20260830`), 7 files preserved.

Post-commit recovery bundles:

- MEI bundle SHA256: `08e31b95a0caa74f934b585cff5c62154a01c7367ba62363245b1f4e319f6e91`
- Solange bundle SHA256: `f596389dd01cf93ab41f7e416e5c8b6a38dd08dbbda274a26ac4656d4cfe68fc`

Secret-looking `.env` backups and backup archives were intentionally not committed.

## Git reconciliation state

The preserved MEI base commit `64e7c71` is an ancestor of current GitHub `main`; GitHub is four commits ahead. The preserved Solange base `e423e46` is also an ancestor of current GitHub `main`; GitHub is three commits ahead. This means the main recovery problem is reconciling uncommitted/local changes onto newer upstream history, not repairing divergent published histories.

Direct `git fetch` from the backend is currently unavailable because the VM lost GitHub HTTPS/SSH credentials during migration. No token will be embedded in a remote URL. Reconciliation remains isolated until secure Git access is restored.

## Desktop Commander

Backend Desktop Commander remains connected and operational.

Site Desktop Commander had a stale/expired provider session. Current persistence supervisor + `TOKEN_REFRESHED` patcher were installed as a canary with backups and without altering `device.json`. Contract tests passed. The provider session itself remains unauthenticated (`PROVIDER_CONNECTED=false`), so the service correctly fails closed instead of cloning another VM's credentials or bypassing provider authentication.

## Codex

Codex CLI exists on the backend but the migrated host has no valid Codex login (`auth.json` absent / login unauthorized). Site also lacks Codex authentication. Fred-Win previously retained substantial Codex history but is currently offline from Desktop Commander. Do not copy authentication material blindly between hosts. Recovery execution continues through isolated GitHub branches, Actions and direct backend commands until an official Codex login is restored.

## Backend health at checkpoint

Active: Docker, MEI API, MEI worker, queue replenisher, monitor, NDR guard and Desktop Commander. No failed systemd units were present in the backend health snapshot. Exactly one main process was observed for each core MEI worker/replenisher/monitor/NDR role.

## Site health at checkpoint

Apache, MySQL and `shopvivaliz-queue-worker.service` are active. The only known failed unit is the site Desktop Commander because its provider authentication is expired; this does not currently interrupt storefront serving.

## Next execution gates

1. Verify active boot-volume VPUs and eliminate avoidable Block Storage charges.
2. Restore secure Git access without hard-coded credentials.
3. Reconcile MEI local recovery commit onto current GitHub main in an isolated branch and run its full tests.
4. Reconcile Solange local recovery commit onto current GitHub main in an isolated branch and run its full tests.
5. Reconcile Shop Vivaliz/deploy state and then run real browser E2E: catalog → product → cart → freight → coupon → checkout → order → confirmation/admin/email.
6. Run MEI E2E: ingest → eligibility → queue target → replenisher → worker → Graph → suppression/quota/NDR/monitor/reboot recovery.
7. Run Solange build/integration/webhook/database/deploy/rollback gates.
8. Prove disaster recovery by restoring from backups in isolation; backup existence alone is not a pass.
9. Remove temporary diagnostic workflows before final merge, preserving their evidence in this report.
