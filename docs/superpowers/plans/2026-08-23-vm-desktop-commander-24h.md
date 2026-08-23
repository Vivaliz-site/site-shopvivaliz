# VM Desktop Commander 24h Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the official Desktop Commander on the Ubuntu VM available unattended while preserving provider authentication and SSH recovery.

**Architecture:** Diagnose the current official Desktop Commander process/profile/session boundary through the existing GitHub Actions -> SSH route, then add an idempotent `systemd` installer and unit that always run under one persistent non-root user with fixed profile directories. SSH remains the independent recovery route and no public MCP exposure is added.

**Tech Stack:** Ubuntu 22.04+, systemd, Node/npm/npx, Desktop Commander Remote MCP, GitHub Actions, SSH.

**Spec:** `docs/superpowers/specs/2026-08-23-vm-desktop-commander-24h-design.md`

## Global Constraints
- Never commit or print provider tokens, cookies, device codes, session blobs, private keys, or full device identifiers.
- Never bypass provider-enforced authentication or expose MCP publicly.
- Run the official service under one persistent non-root Linux user with fixed profile paths.
- Production/repository changes go through branch/PR; live installation happens only after reviewed code is merged or through an existing allowlisted operational action whose payload contains no secrets.

---

### Task 1: Diagnose current VM Desktop Commander runtime

**Files:**
- Create: `.github/workflows/vm-desktop-commander-action.yml`
- Create: `ops/vm-desktop-commander-request.json`
- Create: `tests/vm-desktop-commander-action-contract-test.php`

**Interfaces:**
- Consumes: `SHOPVIVALIZ_VM_SSH_KEY` and the existing VM SSH endpoint.
- Produces: sanitized diagnostic output for process, user, unit, package/version, HOME/XDG/npm paths and auth-path existence/mtime only.

- [ ] **Step 1: Write the failing contract test**
Assert the workflow allowlists `status`, refuses arbitrary commands, never prints auth/session file contents, and uses the existing SSH secret only through an ephemeral key file.
- [ ] **Step 2: Run test to verify RED**
Run: `php tests/vm-desktop-commander-action-contract-test.php`
Expected: FAIL because workflow/request files do not exist.
- [ ] **Step 3: Add the minimal workflow and status action**
The `status` command must print only `whoami`, `HOME`, `XDG_CONFIG_HOME`, `XDG_CACHE_HOME`, Node/npm/npx versions, matching process metadata, matching unit names/states, candidate config/cache directory paths with existence and modification times, and whether an interactive device-flow marker is present in the last sanitized service log lines.
- [ ] **Step 4: Run GREEN and syntax checks**
Run the contract test and `git diff --check`.
- [ ] **Step 5: Commit**
Commit workflow, request and contract test.

### Task 2: Add idempotent systemd installer

**Files:**
- Create: `scripts/install-vm-desktop-commander-service.sh`
- Create: `ops/systemd/shopvivaliz-desktop-commander.service`
- Create: `tests/vm-desktop-commander-service-contract-test.php`

**Interfaces:**
- Consumes: diagnosed persistent Linux user, resolved `npx`/Desktop Commander command and fixed profile directories.
- Produces: `/etc/systemd/system/shopvivaliz-desktop-commander.service` enabled at boot.

- [ ] **Step 1: Write failing service contract test**
Assert the unit contains `After=network-online.target`, `Wants=network-online.target`, a non-root `User=`, fixed `Environment=HOME=`, `Restart=always`, `RestartSec=10`, `NoNewPrivileges=true`, `PrivateTmp=true`, and no public listen address or embedded secret.
- [ ] **Step 2: Run test and confirm RED**
Run: `php tests/vm-desktop-commander-service-contract-test.php`.
- [ ] **Step 3: Implement the unit template**
Use a noninteractive Desktop Commander `remote` command resolved from the installed npm toolchain, fixed HOME/XDG/npm-cache paths, journald output, conservative memory/CPU protections, and no shell that waits for an interactive device flow.
- [ ] **Step 4: Implement idempotent installer**
The installer validates the target user and command, writes the unit from the repository template, runs `systemctl daemon-reload`, `enable`, and `restart`, then prints sanitized `is-enabled`/`is-active` state only.
- [ ] **Step 5: Run GREEN and shell syntax checks**
Run both VM contract tests, `bash -n scripts/install-vm-desktop-commander-service.sh`, and `git diff --check`.
- [ ] **Step 6: Commit**
Commit service template, installer and tests.

### Task 3: Add allowlisted install/restart/recovery verification

**Files:**
- Modify: `.github/workflows/vm-desktop-commander-action.yml`
- Modify: `tests/vm-desktop-commander-action-contract-test.php`

**Interfaces:**
- Consumes: installer and systemd unit from Task 2.
- Produces: allowlisted actions `install`, `restart`, `kill_for_recovery_test`, and `status`.

- [ ] **Step 1: Extend the failing workflow contract test**
Assert exactly the supported actions are accepted and each maps to a fixed command; reject request values outside the allowlist.
- [ ] **Step 2: Run test to confirm RED**
Run the workflow contract test.
- [ ] **Step 3: Implement fixed allowlisted actions**
`install` invokes the repository installer; `restart` restarts only `shopvivaliz-desktop-commander.service`; `kill_for_recovery_test` kills only the service main process and waits; `status` reports sanitized state.
- [ ] **Step 4: Run GREEN**
Run VM contract tests and `git diff --check`.
- [ ] **Step 5: Commit**
Commit workflow action extensions.

### Task 4: Execute live VM persistence and recovery validation

**Files:**
- Modify operationally: `ops/vm-desktop-commander-request.json`
- No secret-bearing artifacts committed.

**Interfaces:**
- Consumes: reviewed allowlisted workflow actions.
- Produces: fresh evidence of enabled/active state and unattended recovery.

- [ ] **Step 1: Run sanitized `status`**
Record persistent user, HOME/XDG paths, current process/service state, and provider-auth-required flag.
- [ ] **Step 2: Run `install`**
Require installer exit 0, `systemctl is-enabled=enabled`, and `systemctl is-active=active`.
- [ ] **Step 3: Run `status` again**
Confirm the service uses the intended user/profile and no new interactive device flow appeared.
- [ ] **Step 4: Run `kill_for_recovery_test`**
Kill only the official service main process; do not touch SSH, web server, database, ERP, email worker, or deploy services.
- [ ] **Step 5: Verify automatic recovery**
After at least `RestartSec`, require a different MainPID and `is-active=active` without operator action.
- [ ] **Step 6: Verify boot persistence declaration**
Require `systemctl is-enabled=enabled` and the unit symlink under the appropriate `multi-user.target.wants` directory.
- [ ] **Step 7: Classify provider-auth boundary**
If provider reauthorization is required, record it explicitly and verify SSH recovery remains available; do not bypass authentication.

### Task 5: Documentation, PR, CI and final cross-host verification

**Files:**
- Modify: `docs/AGENT-MCP-REMOTE.md`
- Modify: `docs/FRED-WIN-PRIVATE-RELAY.md`
- Create: `docs/DESKTOP-COMMANDER-24H.md`

**Interfaces:**
- Consumes: Fred-Win plan results and VM plan results.
- Produces: one runbook describing the official channel, recovery channels, health checks and provider-auth boundary for both hosts.

- [ ] **Step 1: Document host-specific supervisors**
Document Windows Task Scheduler/watchdog for Fred-Win and systemd for VM, including sanitized logs and exact health/status commands.
- [ ] **Step 2: Run all relevant contract and governance tests**
Run Fred-Win + VM Desktop Commander contract tests, repository governance tests, PowerShell/shell syntax checks, and `git diff --check`.
- [ ] **Step 3: Open one PR for the shared 24h reliability work**
Include RED/GREEN evidence and live validation state for both hosts.
- [ ] **Step 4: Wait for required checks and fix only evidenced failures**
Do not merge while required gates are failing.
- [ ] **Step 5: Merge and re-run fresh live validation on both hosts**
Confirm Fred-Win private relay remains healthy, Fred-Win official supervisor/task recovers automatically, VM systemd unit remains enabled/active and recovers automatically, and neither host asks for a new device code unless provider policy has invalidated the session.
