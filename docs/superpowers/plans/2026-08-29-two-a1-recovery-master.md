# Two-A1 Recovery Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recover the pre-incident operational capabilities of Shop Vivaliz, MEI MG Email, Solange and the agent/tooling environment on two surviving OCI A1 VMs, then prove every critical flow works end to end.

**Architecture:** Use `always-free-arm-1787907847-26` as backend/data/services and `shopvivaliz-free-a1` as storefront/web/deploy. Preserve all existing state first, reconcile Git/local divergence in isolated branches/worktrees, rebuild lost E2-only configuration from backups/Fred Win/GitHub, then validate through functional and disaster-recovery tests.

**Tech Stack:** OCI A1, Ubuntu/systemd, Docker/PostgreSQL, Apache/PHP/MySQL, Git/GitHub Actions, Microsoft Graph, Node/Python, Codex/agent tooling.

**Spec:** `docs/operations/TWO-A1-RECOVERY-SPEC-2026-08-29.md`

## Global Constraints
- Production topology is exactly two A1 VMs; do not recreate E2 as production dependencies.
- Never discard uncommitted work before independent capture.
- Never commit secrets or private keys.
- Exactly one MEI sender may be active.
- No destructive OCI operation is part of this recovery plan.
- Do not resize compute until free-tier/quota/cost state is freshly verified.
- Functional tests are mandatory; static review or service-active status is not sufficient.
- Do not change active production checkout until the corresponding recovery artifact and rollback path exist.
- Legacy E2 endpoints `137.131.156.17`, `136.248.69.116`, `shopvivaliz-ai`, `shopvivaliz-micro-2`, private IPs `10.0.1.13`/`10.0.1.203`, and any secret/alias that resolves to them must not remain in an active operational path. Historical evidence may retain them only when clearly marked historical/non-executable.
- Canonical A1 roles must be addressed by role/secret/alias rather than hard-coded transient public IP wherever technically possible.

---

### Task 1: Freeze and inventory all surviving state
**Files:**
- Create: `docs/operations/recovery/2026-08-29-inventory.md`
- Create: `docs/operations/recovery/2026-08-29-checksums.sha256`
- Create: `docs/operations/recovery/2026-08-29-legacy-endpoints.md`

- [ ] Record hostname, architecture, CPU/RAM/disk, active services, timers, cron, Docker containers and network listeners on both A1 VMs.
- [ ] Inventory `/home/ubuntu/oci-a1-migration-20260828`, migration staging, deploy releases, database dumps, Git bundles, patches and config archives.
- [ ] Inventory Fred Win recovery sources: `.codex`, SSH config/key presence, repository clones and migration archives without exposing secret content.
- [ ] Search both A1s, Fred Win and all three GitHub repos for legacy E2 public/private IPs, hostnames and aliases; classify each occurrence as ACTIVE, CONFIG, TEST, DOC-HISTORICAL or LOG-HISTORICAL.
- [ ] Produce SHA256 manifests for recovery archives and database dumps.
- [ ] Commit the inventory only after verifying no secret values are embedded.

### Task 2: Preserve every dirty/local Git state before reconciliation
**Files:**
- Create: `docs/operations/recovery/2026-08-29-git-divergence.md`

- [ ] Capture `git status`, HEAD, remotes and branch graph for Shop Vivaliz, MEI and Solange.
- [ ] Create Git bundles plus binary-safe working-tree archives/patches for every dirty checkout.
- [ ] Record hashes and storage locations of each capture.
- [ ] Verify each bundle with `git bundle verify` and each archive with an integrity check.
- [ ] Do not reset, clean, checkout-overwrite or delete any dirty state.

### Task 3: Recover and normalize agent memory/tooling
**Files:**
- Create: `.codex/RECOVERY-MEMORY.md`
- Update: `docs/AGENTS.md`
- Create: `docs/operations/recovery/2026-08-29-agent-state.md`

- [ ] Record the accidental E2 retirement, two-A1 binding architecture and preservation rules in agent memory.
- [ ] Record the legacy endpoint prohibition and require endpoint inventory before any workflow/deploy/remote-control change.
- [ ] Inventory Codex state on both A1s and Fred Win; preserve non-secret histories/configuration useful for recovery.
- [ ] Reconstruct required agent definitions from GitHub/current site checkout.
- [ ] Ensure future agents are instructed to read the recovery spec and master plan before infrastructure changes.

### Task 4: Reconcile Shop Vivaliz source and deployment state
**Files:**
- Modify only through recovery worktree/branch after Task 2 capture.

- [ ] Compare GitHub `main`, active deployed release, `email-cutover-work` and migration archives.
- [ ] Recover legitimate local-only changes into explicit commits on the recovery branch.
- [ ] Reject generated/runtime artifacts from source control while preserving them in backup archives.
- [ ] Run project test/lint gates available in the repo.
- [ ] Verify deployed release remains unchanged until a reviewed replacement is ready.

### Task 5: Reconcile MEI MG Email source and runtime
**Files:**
- Modify only in `fredmourao-ai/mei-mg-email` recovery branch/worktree.

- [ ] Compare GitHub `main` with the backend checkout and all uncommitted modifications.
- [ ] Classify every diff as source fix, runtime data, generated artifact or obsolete copy.
- [ ] Preserve and commit valid source fixes in coherent commits.
- [ ] Re-run MEI tests and safety/preflight checks.
- [ ] Verify one-and-only-one active sender after all changes.

### Task 6: Reconcile Solange source and runtime
**Files:**
- Modify only in `fredmourao-ai/solange-rolla-consultorio` recovery branch/worktree.

- [ ] Replace bundle-only provenance with canonical GitHub remote only after the bundle/local state is independently preserved.
- [ ] Reconcile local changes with GitHub `main` without discarding either side.
- [ ] Run application tests/build/integration validation.
- [ ] Document production runtime and rollback procedure.

### Task 7: Rebuild E2-only operating-system/service capabilities on the A1 roles
**Files:**
- Create: `docs/operations/recovery/2026-08-29-service-map.md`
- Create/update idempotent bootstrap scripts under `ops/recovery/` as needed.

- [ ] Diff backed-up E2 systemd units, crontabs, `/opt` content, service configs and scripts against the two A1s.
- [ ] Restore only capabilities still required under the two-A1 architecture.
- [ ] Remove architectural duplication by disabling redundant workers/timers, not by deleting recovery evidence.
- [ ] Test reboot persistence and service ordering.

### Task 8: Establish reproducible OCI and host access without secret leakage
**Files:**
- Create: `docs/operations/recovery/2026-08-29-access-bootstrap.md`

- [ ] Verify existing OCI API-signing authentication path and GitHub `OCI_CLI_*` secret-backed workflows.
- [ ] Configure host-local OCI CLI credentials only through secure secret transfer; never commit key contents.
- [ ] Validate read-only OCI inventory from each intended administrative path.
- [ ] Replace E2 SSH aliases/endpoints with A1 role aliases; retain E2 names only as clearly disabled/historical references.
- [ ] Document revocation/rotation and recovery steps.

### Task 9: Verify capacity and free-tier compliance before resizing
**Files:**
- Create: `docs/operations/recovery/2026-08-29-oci-capacity.md`

- [ ] Record current shapes, OCPU, RAM, boot-volume sizes, tenancy limits and cost/budget state.
- [ ] Calculate target backend/site allocation using only verified free capacity.
- [ ] If resize is needed, capture fresh backups and run health checks first.
- [ ] Resize one VM at a time and validate all dependent services before touching the second.
- [ ] Do not proceed with any resize that introduces unverified charge exposure.

### Task 10: End-to-end audit — Shop Vivaliz
**Files:**
- Create: `docs/audits/2026-08-29-shopvivaliz-e2e.md`

- [ ] Test home/category/search/product rendering with real data.
- [ ] Test cart, quantity, coupon, address/CEP and real freight calculation including explicit error paths.
- [ ] Test checkout through the last safe pre-charge step and order lifecycle in the approved test mode.
- [ ] Test admin, queue processing, email/notification handoff and product/stock sync.
- [ ] Validate desktop/mobile layout, performance-critical pages, logs and rollback.

### Task 11: End-to-end audit — MEI MG Email
**Files:**
- Create: `docs/audits/2026-08-29-mei-mg-e2e.md`

- [ ] Test source ingestion, dedupe, validation, suppression and queue replenishment.
- [ ] Verify queue target policy, worker throughput, rate limits and 24h caps from actual runtime data.
- [ ] Verify exactly one sender process and no competing legacy automation.
- [ ] Test Microsoft Graph send path and record Mail.Read/NDR as external-gated if admin permission remains unavailable.
- [ ] Test base-sync, monitor, restart/reboot behavior and database integrity.

### Task 12: End-to-end audit — Solange
**Files:**
- Create: `docs/audits/2026-08-29-solange-e2e.md`

- [ ] Run build/test/lint gates.
- [ ] Exercise authentication, persistence, integrations/webhooks and critical user flows using safe test data.
- [ ] Verify systemd/container/deploy behavior and rollback.
- [ ] Record any external dependency gate separately from code defects.

### Task 13: CI/CD, guards, endpoints and automation audit across all projects
**Files:**
- Create: `docs/audits/2026-08-29-automation-e2e.md`

- [ ] Enumerate workflows, scheduled jobs, systemd timers, cron, watchdogs, auto-repairs and agents.
- [ ] Search executable/config paths for `137.131.156.17`, `136.248.69.116`, `10.0.1.13`, `10.0.1.203`, `shopvivaliz-ai`, `shopvivaliz-micro-2` and stale VM secrets/aliases.
- [ ] Correct every active workflow/script/config that targets a retired E2; prefer role-based GitHub environment secrets or stable aliases over literal public IPs.
- [ ] Add a regression test/gate that fails when a retired E2 endpoint is introduced into executable workflows/scripts/configuration.
- [ ] Keep historical reports/logs unchanged or label them historical rather than rewriting evidence.
- [ ] Identify duplicate/conflicting automation and establish one owner per responsibility.
- [ ] Verify fail-closed behavior for protected/destructive operations.
- [ ] Verify workflows cannot silently reintroduce retired fields/services or overwrite production with a dirty checkout.
- [ ] Execute safe workflow validation/smoke tests and confirm the target host resolves to the intended A1 role before declaring each route repaired.

### Task 14: Disaster-recovery proof
**Files:**
- Create: `docs/operations/DR-RUNBOOK-TWO-A1.md`
- Create: `docs/audits/2026-08-29-dr-restore-drill.md`

- [ ] Produce machine-readable inventory of required repos, packages, services, configs and data backups.
- [ ] Perform restore tests in isolated directories/containers without replacing production.
- [ ] Verify database dumps, Git bundles and config archives are actually restorable.
- [ ] Define RPO/RTO assumptions and exact recovery sequence for loss of either A1.

### Task 15: Final cross-project functional gate
**Files:**
- Create: `docs/audits/2026-08-29-final-system-audit.md`

- [ ] Re-run critical tests after all recovery changes.
- [ ] Compare intended architecture against actual running processes, ports, timers and cron.
- [ ] Confirm no executable workflow/script/config references a retired E2 endpoint or alias.
- [ ] Confirm backups/hashes still validate.
- [ ] Confirm no secrets were committed and no paid OCI resource was created.
- [ ] List every unresolved item with owner, evidence and blocking reason; do not label the system healthy while a critical path remains untested.
