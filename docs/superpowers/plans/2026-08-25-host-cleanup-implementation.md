# Host Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove proven legacy runtime artifacts from Fred-Win and shopvivaliz-ai and prevent workflows/installers from recreating them.

**Architecture:** Keep the existing canonical Desktop Commander control plane unchanged. Retire only artifacts proven redundant or broken, add fail-closed anti-recreation contracts in the repository, perform bounded host cleanup, and verify the canonical runtime remains healthy across an automatic cycle.

**Tech Stack:** PowerShell/Task Scheduler, Bash/systemd, GitHub Actions, PHP contract tests.

**Spec:** `docs/superpowers/specs/2026-08-24-host-cleanup-design.md`

## Global Constraints

- Desktop Commander remains exactly `@wonderwhy-er/desktop-commander@0.2.47 remote --persist-session`.
- No broad reset, clean, rebase, or merge over dirty/divergent host repositories.
- Do not read or publish device/session/token contents.
- Preserve production services not explicitly proven redundant.
- KOCEPSV remains out of scope while powered off.

---

### Task 1: Anti-recreation contracts

**Files:**
- Create: `tests/host-runtime-retirement-contract-test.php`
- Modify: `scripts/install-vm-desktop-commander-service.sh`
- Modify: `.github/workflows/fred-win-remote-action.yml`

**Interfaces:**
- Consumes: legacy names from the approved spec.
- Produces: repository contract that rejects legacy VM DC unit persistence and Fred legacy startup task creation.

- [ ] **Step 1:** Add a failing PHP contract asserting the VM installer removes `/etc/systemd/system/desktop-commander.service`, reloads systemd, and the Fred workflow no longer contains `install_mcp_startup` or `ShopVivaliz FredWin MCP Startup` creation logic.
- [ ] **Step 2:** Run `php tests/host-runtime-retirement-contract-test.php`; expect failure against current code.
- [ ] **Step 3:** Update the VM installer to `disable --now` when present, remove the legacy unit file, run `systemctl daemon-reload`, and assert `systemctl cat desktop-commander.service` fails. Remove the legacy Fred workflow action that creates the interactive startup task/BAT.
- [ ] **Step 4:** Re-run the contract and existing Desktop Commander contract tests; expect pass.

### Task 2: Fred-Win bounded cleanup

**Files:**
- Runtime only: Task Scheduler and `C:\site-shopvivaliz\iniciar-fredwin-mcp.bat`

**Interfaces:**
- Consumes: canonical `ShopVivaliz Fred-Win Relay 24h` task.
- Produces: exactly three ShopVivaliz runtime tasks relevant to the DC path, with no legacy startup launcher.

- [ ] **Step 1:** Record fresh DC/relay/task status and verify canonical relay/DC healthy.
- [ ] **Step 2:** Unregister only `ShopVivaliz FredWin MCP Startup`; delete only `C:\site-shopvivaliz\iniciar-fredwin-mcp.bat`.
- [ ] **Step 3:** Verify task and BAT are absent, OpenSSH.Server remains `NotPresent`, DC count is 1/0, S4U/Highest, result 0, auth false, relay healthy.

### Task 3: VM legacy runtime cleanup

**Files:**
- Runtime only: `/etc/systemd/system/*` and two stale Shopee backup unit files.

**Interfaces:**
- Consumes: admin SSH/sudo path via existing GitHub recovery channel.
- Produces: retired units absent while preserved production units remain unchanged.

- [ ] **Step 1:** Capture pre-cleanup state for every removal target and every preserved critical service.
- [ ] **Step 2:** Stop/disable and remove only: `desktop-commander.service`, `shopvivaliz-mcp.service`, `shopvivaliz-monitor.service`, `shopvivaliz-sync.service`, `shopvivaliz-24x7.service`, `shopvivaliz-24x7.timer`, `shopvivaliz-auto-sync.service`, `shopvivaliz-auto-sync.timer`, `shopvivaliz-git-sync.service`, `shopvivaliz-git-sync.timer`; remove stale Shopee backup unit files; daemon-reload and reset-failed.
- [ ] **Step 3:** Verify every removed unit is `not-found`, canonical DC is enabled/active with one 0.2.47 tree, and preserved queue/token/agent/sync-safe/agent-bridge/catalog-audit/orchestrator/products-active units still exist with their previous enabled/active state.

### Task 4: Integration and post-cycle audit

**Files:**
- Repository branch and PR only.

**Interfaces:**
- Consumes: Tasks 1-3 results.
- Produces: auditable cleanup and no legacy recreation after automatic cycles.

- [ ] **Step 1:** Open PR with the anti-recreation changes and review its changed files/checks.
- [ ] **Step 2:** Integrate only after relevant contract checks pass or unrelated failures are explicitly classified.
- [ ] **Step 3:** Wait through at least one Fred Auto Sync/DC watchdog cycle and one central control-plane cycle.
- [ ] **Step 4:** Re-run full Fred and VM audit; verify no removed artifact returned and canonical runtime remains healthy.
