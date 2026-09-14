# OCI Two-VM Rebalance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebalance and harden the two OCI A1 VMs while reducing waste and preserving production availability.

**Architecture:** Clean stale workloads instead of copying them, keep persistent services split by responsibility, add automated preview TTL and verified backups, then apply OCI cost/security/monitoring controls. Shape resize is gated by a real tenancy cost/limit audit.

**Tech Stack:** OCI, Ampere A1, systemd, Docker/Compose, PostgreSQL, GitHub Actions, OCI Object Storage/Monitoring/Budgets/Logging/Bastion/Cloud Guard.

**Spec:** `docs/superpowers/specs/2026-09-13-oci-two-vm-rebalance-design.md`

## Global Constraints
- Preserve production availability and data integrity.
- No destructive cleanup without verified backup for persistent data.
- No resize unless cost/limit audit proves it safe.
- Never expose credentials or pre-authenticated URLs.
- Validate each task before moving to the next.

---

### Task 1: Restore OCI administrative audit path
- [ ] Repair Windows time service/clock skew on the OCI controller.
- [ ] Validate `AGENTS` signed OCI identity without printing secrets.
- [ ] Capture read-only inventory: instances, shapes, VNICs, volumes, backups, budgets, alarms, logging, security services, limits and current cost/usage when permitted.
- [ ] Record a redacted audit artifact.

### Task 2: Back up critical state before cleanup
- [ ] Inventory persistent databases/volumes on both hosts.
- [ ] Create and validate dumps for any database belonging to a stack that may be removed or migrated.
- [ ] Verify remote Object Storage object size/checksum or retained local rollback copy.

### Task 3: Remove stale workloads and reclaim disk
- [ ] Prove old preview/homologation stacks have no active routing/dependency.
- [ ] Stop/remove only proven orphan stacks and their disposable volumes.
- [ ] Prune unused images/cache after container removal.
- [ ] Validate production Solange, MEI, ShopVivaLiz and SAFE-T services.

### Task 4: Persist workload distribution and preview TTL
- [ ] Keep production Solange/MEI on `always-free-arm-1787907847-26` and integrations/SAFE-T on `shopvivaliz-free-a1`.
- [ ] Add a preview inventory/TTL cleaner with dry-run mode, PR-age guard and explicit allowlist/prefix rules.
- [ ] Add systemd timer and regression tests/documentation.

### Task 5: OCI hardening, monitoring and cost guardrails
- [ ] Tighten unnecessary host listeners/firewall exposure without breaking private service paths.
- [ ] Configure/verify budgets and low-cost alerts if API permissions allow.
- [ ] Configure/verify monitoring alarms, logging/flow visibility, Bastion/Run Command, Cloud Guard and Vulnerability Scanning where supported and cost-safe.
- [ ] Verify backup retention/lifecycle rules against Archive minimum-retention economics.

### Task 6: Capacity rebalance and final validation
- [ ] Compare post-cleanup CPU/RAM/disk/load on both VMs.
- [ ] If tenancy limits/cost prove safe, resize to 2 OCPU/12 GiB each; otherwise keep 3+1 and document the cost gate.
- [ ] Reboot only if required, then verify all critical services, runners, backups, agents and private connectivity.
- [ ] Commit/push operational automation, merge through normal GitHub governance, and leave no pending failed workflow/PR created by this work.
