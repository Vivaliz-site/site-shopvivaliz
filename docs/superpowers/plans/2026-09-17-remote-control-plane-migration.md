# Remote Control Plane Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make GitHub Actions, self-hosted runners, private OCI SSH and the existing Windows reverse relays the primary ShopVivaliz control plane, with Desktop Commander retained only as fallback.

**Architecture:** Add a DC-independent health workflow and normalize allowlisted remote actions around the existing backend A1 relay at `10.0.1.38`, with Fred-Win on loopback `5557` and DESKTOP-KOCEPSV on `5558`. Preserve all existing Desktop Commander tasks/services and production release immutability; update monitoring and documentation so DC quota/provider state no longer determines whether hosts are operationally reachable.

**Tech Stack:** GitHub Actions, self-hosted ARM64 runner `shopvivaliz-a1-deploy`, Bash, Python 3, PowerShell, PHP contract tests, OpenSSH, OCI private VCN.

**Spec:** `docs/superpowers/specs/2026-09-17-remote-control-plane-migration-design.md`

## Global Constraints

- Public direct SSH remains disabled.
- Do not expose MCP or relay endpoints publicly.
- Reverse relays remain loopback-only on the OCI backend host.
- Do not remove or disable existing Desktop Commander services/tasks during this migration.
- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or any active release directory.
- Do not print, persist or version secret values, private keys, tokens, device state or session blobs.
- Remote Windows actions remain allowlisted; no arbitrary shell input is accepted from workflow inputs.
- A failed or unexecuted relay probe is `INCONCLUSIVE` until an objective lower-layer failure is confirmed.

---

### Task 1: Add a DC-independent four-host health contract

**Files:**
- Create: `.github/workflows/remote-control-plane-health.yml`
- Create: `tests/remote-control-plane-primary-contract-test.php`

**Interfaces:**
- Consumes: self-hosted runner `shopvivaliz-a1-deploy`, `SHOPVIVALIZ_VM_SSH_KEY`/`ORACLE_VM_SSH_KEY`, `SHOPVIVALIZ_VM_KNOWN_HOSTS`/`ORACLE_VM_KNOWN_HOSTS`, backend target `ubuntu@10.0.1.38`, relay ports `5557` and `5558`.
- Produces: a read-only workflow whose success depends on VM/relay reachability and MCP identity, not Desktop Commander provider state.

- [ ] **Step 1: Write the failing contract test**

Create `tests/remote-control-plane-primary-contract-test.php` with assertions that `.github/workflows/remote-control-plane-health.yml` exists and contains all of these exact contracts:

```php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/remote-control-plane-health.yml';
if (!is_file($path)) { fwrite(STDERR, "missing remote control plane workflow\n"); exit(1); }
$yml = (string) file_get_contents($path);
$required = [
    'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]',
    'ubuntu@10.0.1.38',
    'http://127.0.0.1:5557/health',
    'http://127.0.0.1:5558/health',
    'environment=fred-win',
    'environment=desktop-kocepsv',
    'mcp_version',
    'REMOTE_CONTROL_PLANE_STATUS=',
    'StrictHostKeyChecking=yes',
    'UserKnownHostsFile=',
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "missing {$needle}\n"); exit(1); }
}
$forbidden = ['remote_calls_left_pct','PROVIDER_CONNECTED','AUTH_REQUIRED','trycloudflare.com','0.0.0.0:5557','0.0.0.0:5558'];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "forbidden dependency {$needle}\n"); exit(1); }
}
echo "remote-control-plane-primary-contract: ok\n";
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php tests/remote-control-plane-primary-contract-test.php
```

Expected: non-zero exit with `missing remote control plane workflow`.

- [ ] **Step 3: Implement the health workflow**

Create `.github/workflows/remote-control-plane-health.yml` with `workflow_dispatch` plus a conservative schedule, `permissions: contents: read`, and one self-hosted ARM64 job. Configure SSH material in temporary files with mode `600`, then:

```bash
set -Eeuo pipefail
SSH=(ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=10 -i "$HOME/.ssh/vmkey" ubuntu@10.0.1.38)
"${SSH[@]}" 'set -Eeuo pipefail; printf "BACKEND_HOST=%s\nBACKEND_USER=%s\nBACKEND_PWD=%s\n" "$(hostname)" "$(whoami)" "$PWD"'
"${SSH[@]}" 'curl -fsS --connect-timeout 5 --max-time 10 http://127.0.0.1:5557/health'
"${SSH[@]}" 'curl -fsS --connect-timeout 5 --max-time 10 http://127.0.0.1:5558/health'
```

Parse each JSON response with Python and require `status == "ok"`, expected `environment`, and a non-empty `mcp_version`. Also verify the site runner locally with `hostname`, `whoami`, `pwd`, and a non-destructive repository check. Emit only sanitized markers including:

```text
SITE_RUNNER_STATUS=ok
BACKEND_A1_STATUS=ok
FRED_WIN_RELAY_STATUS=ok
DESKTOP_KOCEPSV_RELAY_STATUS=ok
REMOTE_CONTROL_PLANE_STATUS=ok
```

On inability to test a relay, emit `REMOTE_CONTROL_PLANE_STATUS=inconclusive` before failing the job; do not label the Windows host inactive.

- [ ] **Step 4: Run the contract test and YAML syntax validation**

Run:

```bash
php tests/remote-control-plane-primary-contract-test.php
python3 - <<'PY'
import yaml
with open('.github/workflows/remote-control-plane-health.yml', encoding='utf-8') as f:
    yaml.safe_load(f)
print('yaml: ok')
PY
```

Expected: both pass.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/remote-control-plane-health.yml tests/remote-control-plane-primary-contract-test.php
git commit -m "feat: add primary remote control plane health"
```

---

### Task 2: Normalize DESKTOP-KOCEPSV into the generic private-relay action model

**Files:**
- Create: `.github/workflows/desktopkocepsv-remote-action.yml`
- Create: `ops/desktopkocepsv-remote-request.json`
- Create: `tests/desktopkocepsv-remote-action-contract-test.php`
- Keep unchanged as fallback: `.github/workflows/desktopkocepsv-desktop-commander-action.yml`

**Interfaces:**
- Consumes: backend A1 `10.0.1.38`, loopback relay `5558`, MCP endpoint `/mcp/tool/execute_command`.
- Produces: request action `health` plus benign allowlisted action `runtime_identity`, both independent of Desktop Commander quota/provider state.

- [ ] **Step 1: Write the failing contract test**

Create a PHP contract that requires the new workflow/request and verifies the workflow contains:

```text
ops/desktopkocepsv-remote-request.json
health)
runtime_identity)
http://127.0.0.1:5558/health
http://127.0.0.1:5558/mcp/tool/execute_command
COMPUTERNAME
whoami
Action not allowlisted
StrictHostKeyChecking=yes
```

and forbids `access_token`, `refresh_token`, `auth_token`, `device code`, `verification_uri`, `trycloudflare.com`, and a generic workflow input named `command`.

- [ ] **Step 2: Run it and verify it fails**

```bash
php tests/desktopkocepsv-remote-action-contract-test.php
```

Expected: fail because the generic KOCEPSV action workflow does not yet exist.

- [ ] **Step 3: Add the request file**

Create `ops/desktopkocepsv-remote-request.json`:

```json
{
  "action": "health",
  "reason": "Default private-relay health check for DESKTOP-KOCEPSV"
}
```

- [ ] **Step 4: Implement the generic KOCEPSV workflow**

Use the same verified SSH setup pattern as Fred-Win. `health)` must perform only the relay health request and validate:

```text
status=ok
environment=desktop-kocepsv
mcp_version=<non-empty>
```

`runtime_identity)` must send a fixed PowerShell command through `/mcp/tool/execute_command` that prints only:

```powershell
Write-Output ('COMPUTER=' + $env:COMPUTERNAME)
Write-Output ('USER=' + [System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
Write-Output ('PWD=' + (Get-Location).Path)
```

Do not accept arbitrary command text from dispatch inputs or the JSON request.

- [ ] **Step 5: Run the contract and existing relay contract**

```bash
php tests/desktopkocepsv-remote-action-contract-test.php
php tests/desktopkocepsv-private-relay-contract-test.php
```

Expected: both pass.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/desktopkocepsv-remote-action.yml ops/desktopkocepsv-remote-request.json tests/desktopkocepsv-remote-action-contract-test.php
git commit -m "feat: add kocepsv private relay remote action"
```

---

### Task 3: Make Fred-Win routine control explicitly independent of Desktop Commander

**Files:**
- Modify: `.github/workflows/fred-win-remote-action.yml`
- Modify: `ops/fredwin-request.json`
- Create: `tests/fredwin-remote-primary-contract-test.php`
- Keep unchanged as fallback: `.github/workflows/fred-win-desktop-commander-action.yml`

**Interfaces:**
- Consumes: backend A1 loopback relay `5557` and existing `ops/fredwin-request.json`.
- Produces: stable `health` and `runtime_identity` actions that do not start, stop, configure, or depend on Desktop Commander.

- [ ] **Step 1: Write the failing test**

Require the generic Fred-Win workflow to include `health)` and `runtime_identity)`, validate `http://127.0.0.1:5557/health`, require `environment=fred-win`, and forbid the strings `configure_desktop_commander_allow_all`, `desktop-commander remote`, `device.json`, `access_token`, `refresh_token`, and arbitrary `command)` dispatch behavior in the primary action path.

- [ ] **Step 2: Run it and verify the current workflow fails**

```bash
php tests/fredwin-remote-primary-contract-test.php
```

Expected: fail because the current generic workflow still contains Desktop Commander-specific configuration actions.

- [ ] **Step 3: Reduce the primary Fred-Win workflow to control-plane duties**

Keep `health` and add `runtime_identity`; retain only other actions that are genuinely non-DC operational actions already in routine use. Move no code into the DC fallback workflow: it already exists and remains untouched. The primary workflow must not alter DC configuration, restart DC, or read DC state.

`runtime_identity` prints only:

```text
COMPUTER=<computer name>
USER=<Windows identity>
PWD=<current path>
```

- [ ] **Step 4: Restore the default request to `health`**

Ensure `ops/fredwin-request.json` ends with:

```json
{
  "action": "health",
  "requested_at": "2026-09-17T00:00:00Z",
  "reason": "Primary private-relay health check"
}
```

The timestamp is metadata only; execution logic must not depend on it.

- [ ] **Step 5: Run primary and fallback contracts**

```bash
php tests/fredwin-remote-primary-contract-test.php
php tests/fredwin-desktop-commander-relay-contract-test.php
```

Expected: primary contract passes and the separate DC fallback contract continues to pass.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/fred-win-remote-action.yml ops/fredwin-request.json tests/fredwin-remote-primary-contract-test.php
git commit -m "refactor: separate fred win primary relay from dc fallback"
```

---

### Task 4: Add a bounded non-DC administration path for both OCI hosts

**Files:**
- Create: `.github/workflows/remote-host-action.yml`
- Create: `ops/remote-host-request.json`
- Create: `tests/remote-host-action-contract-test.php`

**Interfaces:**
- Consumes: self-hosted site runner, backend private SSH target `10.0.1.38`.
- Produces: allowlisted `identity` and `repo_status` checks for `shopvivaliz-free-a1` and `always-free-arm-1787907847-26` without using Desktop Commander.

- [ ] **Step 1: Write the failing contract**

Require exactly two target names and exactly two actions:

```text
shopvivaliz-free-a1
always-free-arm-1787907847-26
identity)
repo_status)
Action not allowlisted
Target not allowlisted
```

Require site-host execution to run locally on `shopvivaliz-a1-deploy`, backend execution to use `ubuntu@10.0.1.38`, and forbid public production IP SSH, `137.131.149.55`, arbitrary `command` inputs, `git reset --hard`, writes to `/home/ubuntu/shopvivaliz-deploy/current`, and any secret echo.

- [ ] **Step 2: Run it and verify failure**

```bash
php tests/remote-host-action-contract-test.php
```

Expected: fail because files are absent.

- [ ] **Step 3: Add the request file**

```json
{
  "target": "shopvivaliz-free-a1",
  "action": "identity"
}
```

- [ ] **Step 4: Implement the allowlisted workflow**

For `shopvivaliz-free-a1`:

```bash
identity: hostname; whoami; pwd
repo_status: cd /home/ubuntu/shopvivaliz-deploy/repo && git branch --show-current && git status --porcelain && git rev-parse HEAD
```

For `always-free-arm-1787907847-26`, execute the same fixed commands through verified SSH to `ubuntu@10.0.1.38`. Do not mutate repositories in either action.

- [ ] **Step 5: Run the contract**

```bash
php tests/remote-host-action-contract-test.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/remote-host-action.yml ops/remote-host-request.json tests/remote-host-action-contract-test.php
git commit -m "feat: add allowlisted non-dc host administration"
```

---

### Task 5: Decouple host health from Desktop Commander monitoring semantics

**Files:**
- Modify: `.github/workflows/desktop-commander-24h-health.yml`
- Modify: `tests/desktop-commander-four-host-monitor-contract-test.php`
- Create: `tests/remote-control-plane-monitor-separation-contract-test.php`

**Interfaces:**
- Consumes: new `remote-control-plane-health.yml` as the authoritative reachability/control-path monitor.
- Produces: Desktop Commander health workflow that reports DC-specific provider health only, without being the canonical host-reachability gate.

- [ ] **Step 1: Add a separation contract**

The new test must assert:

```text
remote-control-plane-health.yml exists
Desktop Commander health remains present
DC health docs/status text identifies itself as fallback/provider health
remote control plane does not inspect PROVIDER_CONNECTED or AUTH_REQUIRED
```

Also assert there is no logic that converts exhausted DC quota or provider disconnect into `REMOTE_CONTROL_PLANE_STATUS=failed`.

- [ ] **Step 2: Run it and verify failure**

```bash
php tests/remote-control-plane-monitor-separation-contract-test.php
```

Expected: fail until monitor language/contracts are separated.

- [ ] **Step 3: Update DC monitor semantics**

Keep the existing DC monitor and its repair behavior for DC itself, but change summaries/comments/naming in outputs so it is explicitly `Desktop Commander fallback/provider health`. It must not claim overall host unavailability solely from DC provider state. Keep all existing security checks and existing per-host logon contracts.

- [ ] **Step 4: Adjust the legacy four-host contract without weakening DC checks**

Update `tests/desktop-commander-four-host-monitor-contract-test.php` so it still verifies the singular scheduled DC monitor and all four DC owners, while no longer describing it as the primary host control plane.

- [ ] **Step 5: Run the relevant tests**

```bash
php tests/remote-control-plane-monitor-separation-contract-test.php
php tests/desktop-commander-four-host-monitor-contract-test.php
php tests/desktop-commander-critical-health-contract-test.php
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/desktop-commander-24h-health.yml tests/desktop-commander-four-host-monitor-contract-test.php tests/remote-control-plane-monitor-separation-contract-test.php
git commit -m "refactor: decouple host reachability from dc provider health"
```

---

### Task 6: Update the canonical access documentation and protect the new priority order

**Files:**
- Modify: `docs/knowledge/host-access.md`
- Modify: `docs/knowledge/README.md`
- Modify: `docs/FRED-WIN-PRIVATE-RELAY.md`
- Create: `docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md`
- Modify: `docs/DESKTOP-COMMANDER-24H.md`
- Create: `tests/remote-control-plane-docs-contract-test.php`

**Interfaces:**
- Consumes: workflows from Tasks 1-4.
- Produces: canonical access order `GitHub Actions/private relay -> OCI Bastion -> Desktop Commander fallback`.

- [ ] **Step 1: Write the docs contract first**

Require `host-access.md` to state the new priority order and require exact canonical values:

```text
shopvivaliz-free-a1
10.0.1.112
always-free-arm-1787907847-26
10.0.1.38
127.0.0.1:5557
127.0.0.1:5558
GitHub Actions/private relay
OCI Bastion
Desktop Commander fallback
```

Forbid language saying agents should prefer Desktop Commander whenever connected.

- [ ] **Step 2: Run and verify failure**

```bash
php tests/remote-control-plane-docs-contract-test.php
```

Expected: fail on the current `host-access.md` preference line.

- [ ] **Step 3: Update the documentation**

In `host-access.md`, replace the access priority with:

```text
1. GitHub Actions / self-hosted runner / private relay.
2. OCI Bastion when direct operator shell access to an OCI host is needed.
3. Desktop Commander only as fallback when quota/provider availability permits.
```

Document both Windows relay ports and explicitly preserve the immutable deploy rule. Add `docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md` mirroring Fred-Win's evidence model with `environment=desktop-kocepsv` and port `5558`. Update `DESKTOP-COMMANDER-24H.md` to identify DC as fallback/provider transport, not the primary ShopVivaliz control plane.

- [ ] **Step 4: Run docs and relay contracts**

```bash
php tests/remote-control-plane-docs-contract-test.php
php tests/desktopkocepsv-private-relay-contract-test.php
```

Expected: both pass.

- [ ] **Step 5: Commit**

```bash
git add docs/knowledge/host-access.md docs/knowledge/README.md docs/FRED-WIN-PRIVATE-RELAY.md docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md docs/DESKTOP-COMMANDER-24H.md tests/remote-control-plane-docs-contract-test.php
git commit -m "docs: make private control plane canonical"
```

---

### Task 7: Run structural regression gates before touching live hosts

**Files:**
- No new files unless a test reveals a missing contract.

**Interfaces:**
- Consumes: all implementation tasks above.
- Produces: a branch that is structurally safe to exercise against live infrastructure.

- [ ] **Step 1: Run the new contracts**

```bash
php tests/remote-control-plane-primary-contract-test.php
php tests/desktopkocepsv-remote-action-contract-test.php
php tests/fredwin-remote-primary-contract-test.php
php tests/remote-host-action-contract-test.php
php tests/remote-control-plane-monitor-separation-contract-test.php
php tests/remote-control-plane-docs-contract-test.php
```

Expected: all pass.

- [ ] **Step 2: Run existing relay/DC regression contracts**

```bash
php tests/desktopkocepsv-private-relay-contract-test.php
php tests/fredwin-desktop-commander-relay-contract-test.php
php tests/desktop-commander-four-host-monitor-contract-test.php
php tests/desktop-commander-critical-health-contract-test.php
php tests/local-auto-sync-dc-separation-contract-test.php
```

Expected: all pass.

- [ ] **Step 3: Search for unsafe public-control regressions**

```bash
if grep -RInE 'trycloudflare\.com|0\.0\.0\.0:5557|0\.0\.0\.0:5558|ssh .*137\.131\.149\.55' .github/workflows ops scripts docs/knowledge docs/FRED-WIN-PRIVATE-RELAY.md docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md; then
  echo 'unsafe public control path found' >&2
  exit 1
fi
```

Expected: no active canonical-path matches. Historical documents outside the scoped paths are not part of this gate.

- [ ] **Step 4: Commit any test-only corrections if needed**

```bash
git status --porcelain
git add tests .github/workflows docs ops
git commit -m "test: validate remote control plane migration"
```

Only commit if the prior steps required an actual correction.

---

### Task 8: Validate the live primary path with Desktop Commander quota still exhausted

**Files:**
- Runtime evidence only; do not edit production release files.

**Interfaces:**
- Consumes: merged/available workflow definitions and existing GitHub secrets.
- Produces: fresh evidence for every acceptance criterion.

- [ ] **Step 1: Confirm DC quota remains exhausted without using it as a control dependency**

Record the current authenticated DC usage state as evidence only. Expected condition for this migration test: `remote_calls_left_pct=0`.

- [ ] **Step 2: Dispatch `remote-control-plane-health.yml`**

Expected evidence:

```text
SITE_RUNNER_STATUS=ok
BACKEND_A1_STATUS=ok
FRED_WIN_RELAY_STATUS=ok
DESKTOP_KOCEPSV_RELAY_STATUS=ok
REMOTE_CONTROL_PLANE_STATUS=ok
```

If any path cannot be exercised, stop the acceptance claim and classify that path as `INCONCLUSIVE`; diagnose runner -> SSH -> backend loopback -> reverse tunnel in that order.

- [ ] **Step 3: Validate both OCI hosts through `remote-host-action.yml`**

Run `identity` for `shopvivaliz-free-a1` and `always-free-arm-1787907847-26`. Require correct hostname/user evidence and no DC invocation in the workflow logs.

- [ ] **Step 4: Validate Fred-Win through `fred-win-remote-action.yml`**

Run `health`, then `runtime_identity`. Require `environment=fred-win`, non-empty `mcp_version`, and Windows identity output.

- [ ] **Step 5: Validate DESKTOP-KOCEPSV through `desktopkocepsv-remote-action.yml`**

Run `health`, then `runtime_identity`. Require `environment=desktop-kocepsv`, non-empty `mcp_version`, and Windows identity output.

- [ ] **Step 6: Verify relay owners remain intact**

Use the private relay itself to query scheduled-task state without stopping or restarting anything. Require `ShopVivaliz Fred-Win Relay 24h`/canonical Fred-Win relay task and `ShopVivaliz DESKTOP-KOCEPSV Relay 24h` to remain enabled/running according to their existing contracts.

- [ ] **Step 7: Verify production immutability**

On `shopvivaliz-free-a1`, run read-only checks:

```bash
readlink -f /home/ubuntu/shopvivaliz-deploy/current
cd /home/ubuntu/shopvivaliz-deploy/repo
git status --porcelain
git rev-parse HEAD
```

No step in this migration may write into the resolved active release path.

- [ ] **Step 8: Record acceptance evidence**

The migration is complete only with fresh evidence for all four hosts while DC quota remains exhausted. Record workflow run IDs, commit SHA, absolute timestamp, and sanitized status fields; do not record secret values or device/session content.

---

## Self-review results

- Spec coverage: all goals, non-goals, health contracts, access priority, monitoring separation, security constraints, rollback posture, and acceptance criteria map to Tasks 1-8.
- Placeholder scan: no TBD/TODO/"implement later" steps remain.
- Interface consistency: Fred-Win uses `5557`, DESKTOP-KOCEPSV uses `5558`, backend target is `10.0.1.38`, and site administration stays on `shopvivaliz-a1-deploy`/local execution.
