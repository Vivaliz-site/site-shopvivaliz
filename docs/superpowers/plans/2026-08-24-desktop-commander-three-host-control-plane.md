# Desktop Commander Three-Host Control Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `LAPTOP-NIG4IFUU`, `shopvivaliz-ai`, and `DESKTOP-KOCEPSV` each converge to one persistent Desktop Commander 0.2.47 agent with unattended local recovery and a GitHub-based control plane that can observe and repair all three without relying on Desktop Commander itself.

**Architecture:** Each host owns its local watchdog. Windows uses elevated S4U Scheduled Tasks; the VM uses one canonical systemd service. GitHub Actions reaches the VM by verified SSH; the VM reaches each Windows host through a distinct loopback-only reverse SSH relay. A scheduled health workflow performs bounded repair and updates one sanitized GitHub Issue in place.

**Tech Stack:** PowerShell 5+/7, Bash, Python 3 `scripts/mcp-server.py`, systemd, OpenSSH reverse forwarding, GitHub Actions, PHP contract tests.

**Spec:** `docs/superpowers/specs/2026-08-23-desktop-commander-three-host-control-plane-design.md`

## Global Constraints

- Canonical Desktop Commander package is exactly `@wonderwhy-er/desktop-commander@0.2.47`.
- Canonical invocation is `remote --persist-session`.
- Never read, log, commit, or publish `device.json` contents, tokens, cookies, session blobs, private keys, raw provider auth output, or device codes.
- Provider-enforced reauthorization is not bypassed; unattended flows emit only `AUTH_REQUIRED=true` and enter cooldown.
- Each host must have exactly one canonical Desktop Commander remote launcher after convergence.
- Windows persistence must not depend on interactive logon.
- GitHub SSH must use configured `known_hosts` and `StrictHostKeyChecking=yes`.
- Windows private relays remain loopback-only on the VM; Fred-Win uses VM port `5557`, `DESKTOP-KOCEPSV` uses VM port `5558`.
- Automatic recovery is bounded to one repair attempt per scheduled run.
- The current-state surface is a single GitHub Issue; scheduled health must not create recurring source commits.

---

### Task 1: Strengthen Fred-Win singleton and migration behavior

**Files:**
- Modify: `scripts/fredwin-desktop-commander-supervisor.ps1`
- Modify: `scripts/fredwin-desktop-commander-status.ps1`
- Modify: `tests/fredwin-desktop-commander-supervisor-contract-test.php`
- Modify: `tests/desktop-commander-persist-session-contract-test.php`

**Interfaces:**
- Consumes: existing `ShopVivaliz Desktop Commander 24h` task and FRED persistent profile.
- Produces: sanitized status fields `CANONICAL_AGENT_COUNT`, `NONCANONICAL_AGENT_COUNT`, `TASK_LOGON_TYPE`, `TASK_RUN_LEVEL`, `AUTH_REQUIRED`.

- [ ] **Step 1: Write the failing singleton contract**

Add assertions requiring the supervisor to distinguish canonical launchers from generic `desktop-commander.*remote`, require package `0.2.47`, require `--persist-session`, and define legacy persistence names `DesktopCommanderHidden`, `DesktopCommanderUser24x7`, and Startup `desktop-commander.vbs`.

```php
foreach ([
    'Get-CanonicalRemoteLaunchers',
    '@wonderwhy-er/desktop-commander@0.2.47',
    '--persist-session',
    'DesktopCommanderHidden',
    'DesktopCommanderUser24x7',
    'desktop-commander.vbs',
    'CANONICAL_AGENT_COUNT',
    'NONCANONICAL_AGENT_COUNT'
] as $needle) {
    if (stripos($all, $needle) === false) exit(1);
}
```

- [ ] **Step 2: Run the Fred-Win contracts and verify RED**

Run:
```bash
php tests/fredwin-desktop-commander-supervisor-contract-test.php
php tests/desktop-commander-persist-session-contract-test.php
```
Expected: FAIL because the current supervisor treats any `desktop-commander.*remote` process as healthy and does not expose canonical/non-canonical counts.

- [ ] **Step 3: Implement narrow launcher classification and safe pruning**

Introduce helpers equivalent to:
```powershell
function Get-DesktopCommanderRemoteLaunchers {
    @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue |
      Where-Object { [string]$_.CommandLine -match '@wonderwhy-er/desktop-commander@.*remote' })
}
function Get-CanonicalRemoteLaunchers {
    @(Get-DesktopCommanderRemoteLaunchers |
      Where-Object { [string]$_.CommandLine -match '@wonderwhy-er/desktop-commander@0\.2\.47.*remote.*--persist-session' })
}
```
If exactly one canonical launcher exists and no non-canonical launcher exists, return without restart. Otherwise stop only Desktop Commander remote launcher trees, never unrelated Node/MCP/Playwright processes, then start one sanitized runner.

- [ ] **Step 4: Add staged legacy cleanup**

After the canonical agent is confirmed running, remove only:
```text
Scheduled Task: DesktopCommanderHidden
Scheduled Task: DesktopCommanderUser24x7
Startup: %APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\desktop-commander.vbs
```
Do not touch `ShopVivaliz FredWin MCP Startup` or unrelated MCP/tunnel tasks.

- [ ] **Step 5: Expand sanitized status**

Make `fredwin-desktop-commander-status.ps1` print only non-secret facts, including task principal properties and canonical counts. Do not print command lines or `device.json` contents.

- [ ] **Step 6: Run contracts and verify GREEN**

Run:
```bash
php tests/fredwin-desktop-commander-supervisor-contract-test.php
php tests/desktop-commander-persist-session-contract-test.php
```
Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/fredwin-desktop-commander-supervisor.ps1 scripts/fredwin-desktop-commander-status.ps1 tests/fredwin-desktop-commander-supervisor-contract-test.php tests/desktop-commander-persist-session-contract-test.php
git commit -m "fix: enforce singleton Fred-Win Desktop Commander"
```

---

### Task 2: Add persistent Desktop Commander and private control relay for DESKTOP-KOCEPSV

**Files:**
- Create: `scripts/desktopkocepsv-desktop-commander-runner.ps1`
- Create: `scripts/desktopkocepsv-desktop-commander-supervisor.ps1`
- Create: `scripts/desktopkocepsv-desktop-commander-status.ps1`
- Create: `scripts/desktopkocepsv-remote-bootstrap.ps1`
- Create: `scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1`
- Create: `tests/desktopkocepsv-desktop-commander-supervisor-contract-test.php`
- Create: `tests/desktopkocepsv-private-relay-contract-test.php`

**Interfaces:**
- Consumes: Windows `user` profile, `C:\Users\user\.desktop-commander-device\device.json`, `scripts/mcp-server.py`, Oracle SSH endpoint.
- Produces: task `ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h`, local maintenance MCP `127.0.0.1:5557`, VM loopback relay `127.0.0.1:5558`.

- [ ] **Step 1: Write failing DESKTOP-KOCEPSV contracts**

Require the dedicated supervisor to contain `AtStartup`, `LogonType S4U`, `RunLevel Highest`, one-minute watchdog, package pin `0.2.47`, `--persist-session`, cooldown, singleton classification, and Startup VBS migration. Require the relay script to use exactly:
```text
-R 5558:127.0.0.1:5557
StrictHostKeyChecking=yes
UserKnownHostsFile=<managed known_hosts path>
```
and forbid `accept-new` and `StrictHostKeyChecking=no`.

- [ ] **Step 2: Run new tests and verify RED**

Run:
```bash
php tests/desktopkocepsv-desktop-commander-supervisor-contract-test.php
php tests/desktopkocepsv-private-relay-contract-test.php
```
Expected: FAIL because the files do not exist.

- [ ] **Step 3: Implement sanitized runner and supervisor**

Mirror the proven Fred-Win lifecycle but use:
```powershell
$Repo = 'C:\site-shopvivaliz'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
```
The runner captures provider output only in a temporary/streamed context, persists no raw output, emits `AUTH_REQUIRED=true` on provider device flow, and uses `remote --persist-session`.

- [ ] **Step 4: Implement S4U installation and migration**

The supervisor `InstallTask` creates AtStartup plus one-minute repetition, S4U, Highest, IgnoreNew, StartWhenAvailable, restart count 999. Once a canonical launcher is healthy, rename or remove only:
```text
%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\desktop-commander-remote.vbs
```
The old `run-remote.cmd` may remain archived but must not be referenced by active persistence.

- [ ] **Step 5: Implement local maintenance MCP bootstrap**

Reuse `scripts/mcp-server.py` on `127.0.0.1:5557` with environment identity `desktop-kocepsv`. The bootstrap checks HTTP `/health`, repairs only that MCP process if unhealthy, then ensures the managed reverse tunnel exists.

- [ ] **Step 6: Implement verified reverse SSH tunnel**

The managed tunnel uses VM port 5558 and a fixed known-hosts file, for example:
```powershell
& ssh -i $KeyPath `
  -R 5558:127.0.0.1:5557 `
  -o 'BatchMode=yes' `
  -o 'ServerAliveInterval=30' `
  -o 'ServerAliveCountMax=3' `
  -o 'ExitOnForwardFailure=yes' `
  -o 'StrictHostKeyChecking=yes' `
  -o ("UserKnownHostsFile=" + $KnownHostsPath) `
  ${VMUser}@${VMHost} -N -T
```
The log records lifecycle only, not key contents or command output containing secrets.

- [ ] **Step 7: Run new tests and verify GREEN**

Run the two tests from Step 2. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add scripts/desktopkocepsv-* tests/desktopkocepsv-*
git commit -m "feat: add persistent DESKTOP-KOCEPSV control path"
```

---

### Task 3: Harden the VM to one canonical systemd service

**Files:**
- Modify: `scripts/vm-desktop-commander-supervisor.sh`
- Modify: `scripts/install-vm-desktop-commander-service.sh`
- Modify: `ops/systemd/shopvivaliz-desktop-commander.service`
- Modify: `tests/vm-desktop-commander-service-contract-test.php`
- Modify: `tests/vm-desktop-commander-action-contract-test.php`

**Interfaces:**
- Consumes: `/home/ubuntu/.desktop-commander-device/device.json` and existing `shopvivaliz-desktop-commander.service`.
- Produces: exactly one enabled canonical service; generic `desktop-commander.service` disabled and inactive after migration.

- [ ] **Step 1: Write failing VM singleton contract**

Require installer logic that explicitly checks and disables the legacy `desktop-commander.service`, verifies the canonical unit is enabled/active, and verifies only one `desktop-commander.*remote` tree remains. Require package pin and `RestartPreventExitStatus=20` to remain.

- [ ] **Step 2: Run VM contracts and verify RED**

Run:
```bash
php tests/vm-desktop-commander-service-contract-test.php
php tests/vm-desktop-commander-action-contract-test.php
```
Expected: FAIL until legacy-service convergence is represented in the installer/tests.

- [ ] **Step 3: Implement staged VM convergence**

Installer sequence:
```bash
install/update canonical supervisor and unit
systemctl daemon-reload
systemctl enable --now shopvivaliz-desktop-commander.service
verify canonical MainPID > 1 and device state exists
systemctl disable --now desktop-commander.service 2>/dev/null || true
kill only leftover non-canonical desktop-commander remote trees
verify exactly one canonical tree remains
```
Do not delete persistent device state.

- [ ] **Step 4: Keep auth flow fail-closed**

Maintain exit `20` when the device state is absent or provider device flow is detected. The service must not hammer reauthorization because `RestartPreventExitStatus=20` remains active.

- [ ] **Step 5: Run VM contracts and verify GREEN**

Run the two tests from Step 2. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/vm-desktop-commander-supervisor.sh scripts/install-vm-desktop-commander-service.sh ops/systemd/shopvivaliz-desktop-commander.service tests/vm-desktop-commander-service-contract-test.php tests/vm-desktop-commander-action-contract-test.php
git commit -m "fix: converge VM Desktop Commander to one service"
```

---

### Task 4: Make both Windows private relays boot-persistent and host-specific

**Files:**
- Modify: `scripts/ssh-tunnel-service-managed.ps1`
- Modify: `scripts/fredwin-remote-bootstrap.ps1`
- Modify: `tests/fredwin-desktop-commander-relay-contract-test.php`
- Modify: `scripts/desktopkocepsv-remote-bootstrap.ps1`
- Modify: `scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1`
- Modify: `tests/desktopkocepsv-private-relay-contract-test.php`

**Interfaces:**
- Produces: Fred-Win VM loopback `5557`; DESKTOP-KOCEPSV VM loopback `5558`; both have persistent local boot/watchdog mechanisms independent of Desktop Commander.

- [ ] **Step 1: Write failing strict-host and boot-persistence assertions**

For Fred-Win, forbid `StrictHostKeyChecking=accept-new` and require `StrictHostKeyChecking=yes` plus `UserKnownHostsFile`. Require the remote bootstrap persistence task to use S4U/AtStartup rather than relying only on an interactive logon task.

- [ ] **Step 2: Run relay contracts and verify RED**

Run:
```bash
php tests/fredwin-desktop-commander-relay-contract-test.php
php tests/desktopkocepsv-private-relay-contract-test.php
```
Expected: Fred-Win fails because its current tunnel uses `accept-new`; DESKTOP test fails until its full bootstrap exists.

- [ ] **Step 3: Harden Fred-Win SSH identity verification**

Use a managed known-hosts file populated during installation from the repository/secured local configuration; do not accept a new host key automatically during normal operation.

- [ ] **Step 4: Ensure each Windows maintenance path has one boot-level watchdog**

Create/repair one S4U AtStartup watchdog per host for its local MCP + tunnel bootstrap. Keep Desktop Commander watchdog and maintenance-relay watchdog as separate tasks because they supervise different components.

- [ ] **Step 5: Run relay contracts and verify GREEN**

Run tests from Step 2. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/ssh-tunnel-service-managed.ps1 scripts/fredwin-remote-bootstrap.ps1 scripts/desktopkocepsv-remote-bootstrap.ps1 scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1 tests/fredwin-desktop-commander-relay-contract-test.php tests/desktopkocepsv-private-relay-contract-test.php
git commit -m "fix: harden persistent Windows private relays"
```

---

### Task 5: Add three-host allowlisted GitHub control actions

**Files:**
- Modify: `.github/workflows/fred-win-desktop-commander-action.yml`
- Modify: `.github/workflows/vm-desktop-commander-action.yml`
- Create: `.github/workflows/desktopkocepsv-desktop-commander-action.yml`
- Create: `ops/desktopkocepsv-desktop-commander-request.json`
- Create: `tests/desktop-commander-three-host-action-contract-test.php`

**Interfaces:**
- Consumes: verified SSH to VM, relay ports 5557/5558, host-specific status/supervisor scripts.
- Produces: fixed actions `status`, `restart`, `install_or_repair`, `kill_for_recovery_test` for each host.

- [ ] **Step 1: Write failing action allowlist contract**

Require all three workflows to expose only fixed actions, use verified known_hosts, and forbid unattended `authorize`, `read_auth`, arbitrary `command`, `StrictHostKeyChecking=no`, provider logs, and device-code strings.

- [ ] **Step 2: Run action contract and verify RED**

Run:
```bash
php tests/desktop-commander-three-host-action-contract-test.php
```
Expected: FAIL because the DESKTOP workflow is absent and the VM workflow still contains automatic `authorize/read_auth` paths.

- [ ] **Step 3: Harden VM action workflow**

Remove `authorize` and `read_auth` from unattended actions. Replace `StrictHostKeyChecking=no` with the same known_hosts pattern already used by the observable Fred-Win workflow. Keep status/restart/install-or-repair/recovery-test only.

- [ ] **Step 4: Harden Fred-Win action workflow**

Use strict SSH verification and keep relay endpoint `http://127.0.0.1:5557`. Normalize action names to `status`, `restart`, `install_or_repair`, `kill_for_recovery_test` internally while preserving backward-compatible request parsing if needed.

- [ ] **Step 5: Add DESKTOP-KOCEPSV action workflow**

Use VM relay endpoint `http://127.0.0.1:5558/mcp/tool/execute_command`, and map only to the dedicated DESKTOP status/supervisor scripts.

- [ ] **Step 6: Run action contract and verify GREEN**

Run Step 2 command. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add .github/workflows/fred-win-desktop-commander-action.yml .github/workflows/vm-desktop-commander-action.yml .github/workflows/desktopkocepsv-desktop-commander-action.yml ops/desktopkocepsv-desktop-commander-request.json tests/desktop-commander-three-host-action-contract-test.php
git commit -m "feat: add allowlisted three-host Desktop Commander actions"
```

---

### Task 6: Build scheduled three-host health, bounded recovery, and sanitized Issue status

**Files:**
- Modify: `.github/workflows/desktop-commander-24h-health.yml`
- Create: `scripts/desktop-commander-control-plane-status.py`
- Create: `tests/desktop-commander-control-plane-contract-test.php`
- Create: `tests/desktop-commander-status-sanitization-contract-test.php`

**Interfaces:**
- Consumes: host status outputs and relay/SSH reachability.
- Produces: one sanitized JSON payload and one stable GitHub Issue titled `Desktop Commander 24h Control Plane Status`.

- [ ] **Step 1: Write failing schedule/recovery/status contracts**

Require:
```yaml
schedule:
  - cron: '*/5 * * * *'
permissions:
  contents: read
  issues: write
```
Require one bounded recovery attempt per unhealthy host, independent host results, and a stable issue title. Forbid source-file updates as the scheduled status transport.

- [ ] **Step 2: Write failing sanitization tests**

The status formatter accepts normalized host dictionaries and rejects/removes fields matching sensitive names. Example expected safe schema:
```json
{
  "host": "LAPTOP-NIG4IFUU",
  "state": "healthy",
  "watchdog": "ready",
  "canonical_agent_count": 1,
  "auth_required": false,
  "last_success": "2026-08-24T00:00:00Z",
  "last_recovery": "none",
  "run_id": "123"
}
```
Forbidden keys/substrings include `token`, `cookie`, `device_code`, `session`, `private_key`, `device.json`, raw command lines, IP addresses, and relay URLs.

- [ ] **Step 3: Run new contracts and verify RED**

Run:
```bash
php tests/desktop-commander-control-plane-contract-test.php
php tests/desktop-commander-status-sanitization-contract-test.php
```
Expected: FAIL because schedule, three-host orchestration, formatter, and issue update do not yet exist.

- [ ] **Step 4: Implement normalized status formatter**

`scripts/desktop-commander-control-plane-status.py` must parse only allowlisted status keys and render both JSON and Markdown table rows. It must never pass through arbitrary raw output.

- [ ] **Step 5: Implement periodic three-host orchestration**

Workflow sequence per host:
```text
collect sanitized status
if healthy -> record healthy
if unhealthy -> invoke one canonical restart/install_or_repair action
wait for convergence
collect one second sanitized status
record healthy/degraded/unreachable
```
A failure for one host must use `continue-on-error`/explicit result capture so the other hosts are still checked.

- [ ] **Step 6: Update one stable GitHub Issue in place**

Use `actions/github-script@v7` with `issues: write`. Search open issues for the exact title. Create it once if absent; otherwise update its body. Do not create one issue per run.

- [ ] **Step 7: Keep immutable sanitized evidence**

Upload a 30-day artifact containing only the normalized JSON status, not raw SSH/MCP/provider logs.

- [ ] **Step 8: Run new contracts and verify GREEN**

Run Step 3 commands. Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add .github/workflows/desktop-commander-24h-health.yml scripts/desktop-commander-control-plane-status.py tests/desktop-commander-control-plane-contract-test.php tests/desktop-commander-status-sanitization-contract-test.php
git commit -m "feat: add scheduled Desktop Commander control plane health"
```

---

### Task 7: Update documentation and remove obsolete automatic-auth guidance

**Files:**
- Modify: `docs/DESKTOP-COMMANDER-24H.md`
- Modify: `docs/FRED-WIN-PRIVATE-RELAY.md`
- Modify: `AGENTS-ACCESS-INDEX.md`
- Modify: `docs/ai-agents-map.md`
- Create: `docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md`
- Create: `tests/desktop-commander-docs-contract-test.php`

**Interfaces:**
- Produces: one documented source of truth for canonical ports, task/service names, recovery order, and provider-auth boundary.

- [ ] **Step 1: Write failing docs contract**

Require all three host names, canonical package/version, relay ports `5557` and `5558`, stable Issue title, no Cloudflare recovery dependency, and no unattended `authorize/read_auth` instructions.

- [ ] **Step 2: Run docs contract and verify RED**

Run:
```bash
php tests/desktop-commander-docs-contract-test.php
```
Expected: FAIL until the three-host documentation is complete.

- [ ] **Step 3: Update docs**

Document this recovery order:
```text
1. Read GitHub control-plane Issue.
2. Use scheduled/manual GitHub control action.
3. GitHub -> verified SSH -> VM.
4. VM handles local systemd or loopback relay 5557/5558.
5. Local host watchdog remains authoritative for process persistence.
6. AUTH_REQUIRED is reported, never bypassed.
```

- [ ] **Step 4: Run docs contract and verify GREEN**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add docs/DESKTOP-COMMANDER-24H.md docs/FRED-WIN-PRIVATE-RELAY.md docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md AGENTS-ACCESS-INDEX.md docs/ai-agents-map.md tests/desktop-commander-docs-contract-test.php
git commit -m "docs: document three-host Desktop Commander control plane"
```

---

### Task 8: Deploy host changes in a connectivity-preserving order

**Files:**
- Runtime only; no new source files beyond Tasks 1-7.

**Interfaces:**
- Consumes: merged/present source from Tasks 1-7.
- Produces: all three hosts migrated without intentionally dropping the last known-good management path.

- [ ] **Step 1: Run complete repository contract suite before deployment**

Run at minimum:
```bash
php tests/fredwin-desktop-commander-supervisor-contract-test.php
php tests/desktopkocepsv-desktop-commander-supervisor-contract-test.php
php tests/fredwin-desktop-commander-relay-contract-test.php
php tests/desktopkocepsv-private-relay-contract-test.php
php tests/vm-desktop-commander-service-contract-test.php
php tests/vm-desktop-commander-action-contract-test.php
php tests/desktop-commander-three-host-action-contract-test.php
php tests/desktop-commander-control-plane-contract-test.php
php tests/desktop-commander-status-sanitization-contract-test.php
php tests/desktop-commander-docs-contract-test.php
```
Expected: all PASS.

- [ ] **Step 2: Migrate VM first without depending on DC**

Use GitHub SSH action path. Install/reload canonical service, verify it is enabled/active and device state exists, then disable the generic legacy service. Confirm one canonical process tree.

- [ ] **Step 3: Migrate Fred-Win through the existing private relay**

Install/update canonical S4U DC task first. Confirm one canonical agent is online. Only then remove legacy DC tasks/Startup launcher. Separately harden the Fred-Win MCP/tunnel watchdog and strict known_hosts.

- [ ] **Step 4: Bootstrap DESKTOP-KOCEPSV while its current DC is still available**

Copy/sync repository, install its local maintenance MCP + 5558 reverse relay, prove VM can reach `http://127.0.0.1:5558/health`, then install the S4U DC task. Only after the canonical agent reconnects remove the Startup VBS persistence.

- [ ] **Step 5: Verify independent control paths before fault injection**

From GitHub, prove:
```text
VM SSH/systemd status works
Fred-Win health works through VM:5557
DESKTOP-KOCEPSV health works through VM:5558
```
No Desktop Commander tool may be used as the evidence source for this checkpoint.

- [ ] **Step 6: Controlled Fred-Win recovery test**

Kill only the canonical Desktop Commander remote launcher tree. Wait up to the one-minute watchdog interval plus startup allowance. Verify a new canonical launcher appears, count is exactly one, `AUTH_REQUIRED=false`, and provider reconnect occurs without a new device code.

- [ ] **Step 7: Controlled DESKTOP-KOCEPSV recovery test**

Perform the same test through the 5558 control path. Verify no interactive login is required.

- [ ] **Step 8: Controlled VM recovery test**

Kill only `shopvivaliz-desktop-commander.service` MainPID. Verify systemd starts a different MainPID, service stays active, and canonical remote process count is one.

- [ ] **Step 9: Run scheduled/manual final control-plane health**

Trigger `.github/workflows/desktop-commander-24h-health.yml`. Require all three host rows to be `healthy` and the stable Issue body to contain only sanitized fields.

- [ ] **Step 10: Verify final external state using GitHub only**

Read the stable Issue through the GitHub connector and verify its timestamps are fresh. This is the completion evidence when direct DC connectivity is unavailable.

- [ ] **Step 11: Commit any final documentation-only evidence update if project policy requires it**

Do not commit recurring health state. If a one-time migration record is needed, add a dated report with only sanitized results and commit once.

---

## Final Verification Gate

Completion may be claimed only after fresh evidence proves all of the following:

```text
LAPTOP-NIG4IFUU: exactly 1 canonical DC launcher; S4U boot task; AUTH_REQUIRED=false; 5557 relay reachable.
shopvivaliz-ai: exactly 1 canonical DC launcher; canonical systemd unit enabled+active; generic unit disabled.
DESKTOP-KOCEPSV: exactly 1 canonical DC launcher; S4U boot task; AUTH_REQUIRED=false; 5558 relay reachable.
GitHub: */5 schedule present; one bounded repair attempt; strict host verification; stable sanitized status Issue updated.
Security: no provider codes/tokens/device state/private keys/raw auth logs in workflows, artifacts, Issue, or repository.
Recovery: controlled process-kill test succeeds independently on all three hosts without interactive login while provider sessions remain valid.
```
