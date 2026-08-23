# Fred-Win Desktop Commander 24h Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the official Desktop Commander on Fred-Win available unattended while preserving provider authentication and the existing private relay fallback.

**Architecture:** Diagnose the official Desktop Commander process/profile/session boundary through the canonical private relay, then add an idempotent Windows supervisor and Scheduled Task that always run under the persistent FRED profile. The private relay remains an independent recovery route and is used to validate the official channel without exposing secrets.

**Tech Stack:** Windows PowerShell 5.1+, Task Scheduler, Node/npm/npx, Desktop Commander Remote MCP, GitHub Actions, Oracle VM reverse SSH.

**Spec:** `docs/superpowers/specs/2026-08-23-fredwin-desktop-commander-24h-design.md`

## Global Constraints
- Never commit or print provider tokens, cookies, device codes, session blobs, private keys, or full device identifiers.
- Never bypass provider-enforced authentication or expose MCP publicly.
- Preserve the canonical Fred-Win private relay on `127.0.0.1:5557` as independent fallback.
- Production/repository changes go through branch/PR; operational relay requests may use the existing allowlisted workflow.

---

### Task 1: Diagnose current official Desktop Commander runtime

**Files:**
- Modify: `.github/workflows/fred-win-remote-action.yml`
- Modify: `ops/fredwin-request.json`

**Interfaces:**
- Consumes: canonical private relay health endpoint.
- Produces: sanitized diagnostic output for process/task/profile/session-path state.

- [ ] **Step 1: Add a failing policy test**
Create `tests/fredwin-desktop-commander-relay-contract-test.php` asserting an allowlisted `desktop_commander_status` action exists and its command redacts auth/session values.
- [ ] **Step 2: Run test to verify it fails**
Run: `php tests/fredwin-desktop-commander-relay-contract-test.php`
Expected: FAIL because the action is absent.
- [ ] **Step 3: Add minimal allowlisted diagnostic action**
Inspect only process command lines, scheduled-task names/actions, user/profile environment paths, package/version locations and existence/mtime of auth-related directories; print paths/booleans only, never file contents or secrets.
- [ ] **Step 4: Run test to verify it passes**
Run the new contract test plus `git diff --check`.
- [ ] **Step 5: Commit**
Commit the diagnostic action and test.

### Task 2: Add idempotent official Desktop Commander supervisor

**Files:**
- Create: `scripts/fredwin-desktop-commander-supervisor.ps1`
- Create: `tests/fredwin-desktop-commander-supervisor-contract-test.php`
- Modify: `scripts/fredwin-remote-bootstrap.ps1`

**Interfaces:**
- Consumes: persistent FRED `USERPROFILE`, `APPDATA`, `LOCALAPPDATA`, `npm/npx` availability.
- Produces: one healthy official Desktop Commander process and a sanitized status log.

- [ ] **Step 1: Write failing supervisor contract test**
Assert the script uses the current user profile, never embeds auth values, uses hidden/noninteractive startup, detects existing healthy process, and supports restart without launching duplicate instances.
- [ ] **Step 2: Run test and confirm RED**
Run: `php tests/fredwin-desktop-commander-supervisor-contract-test.php`.
- [ ] **Step 3: Implement minimal supervisor**
Resolve the official executable/command from the diagnosed installation, preserve the current Windows profile environment, start hidden, write only sanitized lifecycle logs, and exit nonzero when provider authentication is required instead of triggering repeated interactive device-flow loops.
- [ ] **Step 4: Integrate with Fred-Win bootstrap**
Call the supervisor after private MCP/tunnel health so the fallback remains available even if the official channel cannot authenticate.
- [ ] **Step 5: Run GREEN and lint checks**
Run both new contract tests, relevant Fred-Win tests, PowerShell parser checks, and `git diff --check`.
- [ ] **Step 6: Commit**
Commit supervisor + bootstrap integration.

### Task 3: Register persistent Scheduled Task and watchdog

**Files:**
- Modify: `.github/workflows/fred-win-remote-action.yml`
- Modify: `scripts/fredwin-desktop-commander-supervisor.ps1`
- Test: `tests/fredwin-desktop-commander-supervisor-contract-test.php`

**Interfaces:**
- Consumes: supervisor script from Task 2.
- Produces: scheduled startup/recovery configuration under the intended Windows user.

- [ ] **Step 1: Extend failing test**
Assert a task-registration action uses `Interactive` logon for the intended current user, `RunLevel Highest`, `StartWhenAvailable`, `MultipleInstances IgnoreNew`, and restart-on-failure settings without secrets.
- [ ] **Step 2: Run test to confirm RED**
Run supervisor contract test.
- [ ] **Step 3: Implement task registration**
Create/update a `ShopVivaliz Desktop Commander 24h` Scheduled Task with logon trigger plus periodic watchdog trigger; action invokes the supervisor script hidden.
- [ ] **Step 4: Run GREEN**
Run contract tests and PowerShell parser validation.
- [ ] **Step 5: Commit**
Commit task-registration support.

### Task 4: Execute live Fred-Win recovery validation

**Files:**
- Modify operationally: `ops/fredwin-request.json`
- No secret-bearing artifacts committed.

**Interfaces:**
- Consumes: allowlisted `desktop_commander_status`, install/start/restart actions.
- Produces: fresh objective evidence of unattended recovery.

- [ ] **Step 1: Run canonical private relay health**
Require HTTP 200 with `status=ok`, `environment=fred-win`, `mcp_version=1.0.0`.
- [ ] **Step 2: Run sanitized official-channel status**
Record process/task/profile consistency and whether provider session is valid.
- [ ] **Step 3: Install/update task and start supervisor**
Use only the allowlisted action.
- [ ] **Step 4: Verify official channel health**
Confirm the official Desktop Commander is reachable without a new device code.
- [ ] **Step 5: Kill only the official Desktop Commander process through an allowlisted test action**
Do not touch the private relay.
- [ ] **Step 6: Verify automatic recovery**
Wait for watchdog/restart and confirm a new process instance is healthy with no user interaction.
- [ ] **Step 7: Classify provider-auth boundary**
If provider reauthorization is required, report it explicitly and verify private relay remains healthy; do not bypass authentication.

### Task 5: PR, CI, and final verification

**Files:**
- Modify: `docs/AGENT-MCP-REMOTE.md`
- Modify: `docs/FRED-WIN-PRIVATE-RELAY.md` only if operational rules change.

**Interfaces:**
- Consumes: implementation and live evidence.
- Produces: documented runbook and merge-ready PR.

- [ ] **Step 1: Update docs with official-vs-private channel behavior**
Document startup task, sanitized logs, health checks, and the provider-auth boundary.
- [ ] **Step 2: Run complete relevant test set**
Run all Fred-Win contract tests, repository governance tests, PHP/PowerShell syntax checks, and `git diff --check`.
- [ ] **Step 3: Open PR**
Include RED/GREEN evidence and live Fred-Win validation.
- [ ] **Step 4: Wait for required checks and fix only evidenced failures**
No merge while required gates are failing.
- [ ] **Step 5: Merge and re-run fresh live validation**
After merge, verify canonical relay + official Desktop Commander startup/recovery again before claiming completion.
