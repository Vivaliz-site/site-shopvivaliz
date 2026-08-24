# Desktop Commander Three-Host 24h Control Plane Design

## Goal
Keep the official Desktop Commander channel available 24h on `LAPTOP-NIG4IFUU`, `shopvivaliz-ai`, and `DESKTOP-KOCEPSV`, with automatic local recovery, no dependency on interactive user logon, no duplicate launchers, and an independent GitHub-based control plane that remains usable when every Desktop Commander channel is offline.

## Hosts and canonical runtime

### LAPTOP-NIG4IFUU (Fred-Win)
- Official package: `@wonderwhy-er/desktop-commander@0.2.47`.
- Official mode: `remote --persist-session`.
- Persistent profile: the existing FRED Windows profile containing `%USERPROFILE%\.desktop-commander-device\device.json`.
- Canonical supervisor: `scripts/fredwin-desktop-commander-supervisor.ps1`.
- Canonical runner: `scripts/fredwin-desktop-commander-runner.ps1`.
- Canonical persistence: one elevated Scheduled Task, `ShopVivaliz Desktop Commander 24h`, with `AtStartup`, `LogonType S4U`, `RunLevel Highest`, `StartWhenAvailable`, `MultipleInstances IgnoreNew`, and one-minute watchdog/restart behavior.
- Legacy `DesktopCommanderHidden`, `DesktopCommanderUser24x7`, user Startup VBS launchers, and any `@latest` remote launcher are non-canonical and must be disabled or removed after the canonical task is proven healthy.

### shopvivaliz-ai (Oracle VM)
- Official package: `@wonderwhy-er/desktop-commander@0.2.47`.
- Official mode: `remote --persist-session`.
- Persistent profile: `/home/ubuntu/.desktop-commander-device/device.json` under user `ubuntu`.
- Canonical supervisor: `scripts/vm-desktop-commander-supervisor.sh` installed under `/usr/local/lib/shopvivaliz/`.
- Canonical persistence: `shopvivaliz-desktop-commander.service`, enabled at boot with `Restart=always` and a fixed `HOME`, XDG paths, and npm cache.
- The generic `desktop-commander.service`, ad-hoc `npx @latest` processes, and any second remote launcher are non-canonical and must be disabled or removed after the canonical service is proven healthy.

### DESKTOP-KOCEPSV
- Official package: `@wonderwhy-er/desktop-commander@0.2.47`.
- Official mode: `remote --persist-session`.
- Persistent profile: the existing Windows `user` profile containing `C:\Users\user\.desktop-commander-device\device.json`.
- Canonical persistence: an elevated S4U Scheduled Task equivalent to the Fred-Win model, starting at Windows boot without interactive logon and using a sanitized runner/supervisor pair dedicated to this host.
- Existing user Startup VBS and `run-remote.cmd` loop may be used only as migration inputs; they must not remain as a second persistence mechanism after the S4U task is proven healthy.

## Architecture
There are three independent layers. The local watchdog is the first recovery layer and must work without GitHub or ChatGPT. GitHub Actions is the external control plane and reaches the Oracle VM by SSH; the VM manages its own systemd service and acts as the bastion for loopback-only private relays to both Windows hosts. GitHub also maintains one sanitized status surface that the GitHub connector can read even when no Desktop Commander session is available.

The control plane does not replace Desktop Commander. Desktop Commander remains the preferred interactive channel whenever available. The control plane exists to observe, repair, and validate the hosts independently of the chat/plugin session.

## Private relay topology
- GitHub Actions -> verified SSH -> `shopvivaliz-ai`.
- VM -> existing loopback-only private relay -> `LAPTOP-NIG4IFUU`.
- VM -> a distinct loopback-only private relay -> `DESKTOP-KOCEPSV`.
- Each relay must use a distinct local port and a fixed host identity so a command can never be routed to the wrong Windows machine.
- No Windows MCP or Desktop Commander endpoint may be exposed directly to the public Internet.
- Cloudflare or other public tunnels are not part of the canonical recovery path.

## Local watchdog behavior
Each host must converge to exactly one canonical Desktop Commander remote launcher. A watchdog execution first validates the persisted device-state file exists without reading it, then counts matching launchers, verifies package version and mode, and checks for an auth-required cooldown marker. If exactly one canonical launcher is healthy, it performs no restart. If zero launchers are present, it starts one. If duplicate or non-canonical launchers are present, it removes the competing launchers and starts exactly one canonical launcher.

A watchdog must not kill unrelated Node/MCP/Playwright processes. Process matching must be scoped to the Desktop Commander package and `remote` command.

## Authentication behavior
`--persist-session` and the host's existing provider-supported device state are always reused. No automation may invent, copy, bypass, or weaken provider authentication. If the provider accepts the persisted session, restarts must occur without user intervention.

If the provider explicitly starts a new device authorization flow, the runner must record only `AUTH_REQUIRED=true`, stop the retry loop, and enter a cooldown. It must never persist or publish the device code, raw auth response, token, cookie, session blob, or contents of `device.json`. Provider-enforced reauthorization is an external policy boundary and cannot be bypassed by the control plane.

## GitHub control plane
The existing allowlisted VM and Fred-Win actions are retained and consolidated into a three-host control plane. The control plane must support, per host, only fixed actions such as `status`, `restart`, `install_or_repair`, and a controlled `kill_for_recovery_test`. Arbitrary command execution is not exposed through the status interface.

The health workflow runs on `workflow_dispatch`, relevant configuration changes, and a periodic schedule. The target schedule is every five minutes. It checks all three hosts independently so one host failure does not hide the state of the other two.

For an unhealthy host, the workflow performs one bounded recovery sequence: collect sanitized status, invoke the host's canonical local repair/restart action, wait for convergence, then run a second status check. It must not repeatedly start authorization flows. A failed recovery is reported as degraded and left for the next scheduled control-plane run or manual diagnosis.

## Observable status surface
The canonical externally readable state is one dedicated GitHub Issue, updated in place rather than by recurring commits to `main`. The issue title is stable and unique, for example `Desktop Commander 24h Control Plane Status`.

The issue body contains one row per host with only:
- host name;
- overall `healthy`/`degraded` state;
- local watchdog/task/service state;
- canonical Desktop Commander process count;
- `auth_required` boolean;
- last successful health timestamp;
- last recovery timestamp and outcome;
- source workflow run reference.

The issue must never contain credentials, private keys, IP addresses, relay URLs, full device identifiers, process command lines containing secrets, raw provider output, device codes, tokens, cookies, session data, or `device.json` contents.

Per-run immutable artifacts may retain the same sanitized status for diagnostics with limited retention, but the GitHub Issue is the primary connector-readable current state.

## GitHub security
- SSH host verification uses a configured `known_hosts` secret and `StrictHostKeyChecking=yes`; `StrictHostKeyChecking=no` is removed from the canonical workflows.
- SSH private keys remain only in GitHub Actions secrets and ephemeral runner files with mode `0600`, deleted in an `always()` cleanup step.
- Workflows use minimum required permissions. The scheduled health workflow needs `contents: read` and `issues: write`; it does not need repository write access to mutate source files.
- Operational `request.json` files are no longer used as a recurring status transport. Existing files may remain only for backward compatibility until their callers are migrated.
- Automatic flows never expose an `authorize` or `read_auth` action. Any provider reauthorization path is manual and explicitly separated from unattended recovery.

## Migration and duplicate removal
Duplicate removal is staged so connectivity is not intentionally destroyed. For each host:
1. Confirm persistent device-state file exists without reading secret contents.
2. Install or validate the canonical watchdog/persistence mechanism.
3. Start the canonical `0.2.47 remote --persist-session` launcher.
4. Verify the official device reconnects and status is healthy.
5. Only then disable/remove legacy tasks, Startup launchers, generic systemd units, and `@latest` launchers.
6. Recheck that exactly one canonical launcher remains.
7. Run a controlled process-kill recovery test and verify automatic recovery without interactive login.

The three hosts are migrated independently. A failure on one host does not block preserving a known-good channel on the others.

## Health state model
A host is `healthy` only when all applicable conditions are true:
- persistent device-state file exists;
- canonical watchdog task/service exists and is enabled;
- exactly one canonical Desktop Commander remote launcher is running;
- launcher is version `0.2.47` and uses `--persist-session`;
- `AUTH_REQUIRED=false`;
- the independent host control path is reachable.

A host is `degraded` when the watchdog exists but the canonical launcher is absent, duplicated, non-canonical, or requires provider authorization. A host is `unreachable` when the independent control path itself cannot be reached.

## Testing strategy
Contract tests cover package/version pinning, `--persist-session`, singleton process matching, no broad Node termination, sanitized diagnostics, no automatic authorization output, S4U/systemd persistence, strict SSH host verification, five-minute schedule, bounded recovery, and status issue schema.

Runtime verification is performed independently on all three hosts. For Windows, verify the scheduled task principal/logon type and boot trigger, then kill only the canonical Desktop Commander launcher tree and observe restart within the watchdog interval. For the VM, kill only the canonical systemd MainPID and confirm systemd provides a new MainPID. For every host, verify the device returns online without a new device code while the provider session remains valid.

A final control-plane run must prove all three hosts healthy from GitHub without using Desktop Commander as the observation channel.

## Failure handling
- Duplicate launcher: canonical watchdog prunes only Desktop Commander remote launcher trees and recreates one pinned launcher.
- Missing watchdog: control plane invokes the host-specific `install_or_repair` action, then rechecks.
- Relay down: report the Windows host as `unreachable`; VM recovery remains independent.
- VM SSH down: all VM-mediated external control is unavailable, but each host's local watchdog continues operating independently.
- `AUTH_REQUIRED=true`: do not restart repeatedly and do not generate new device codes; report degraded authentication state.
- GitHub Actions unavailable: local watchdogs continue operating; status becomes stale but does not affect the agents.

## Success criteria
- `LAPTOP-NIG4IFUU`, `shopvivaliz-ai`, and `DESKTOP-KOCEPSV` each have exactly one canonical Desktop Commander `0.2.47 remote --persist-session` launcher.
- Both Windows hosts start the agent after boot without interactive logon through elevated S4U tasks.
- The VM starts the agent after boot through one enabled canonical systemd service.
- Legacy/duplicate launchers and persistence mechanisms no longer compete with the canonical launcher.
- Killing the canonical launcher on each host is followed by automatic local recovery without user intervention while provider auth remains valid.
- Scheduled GitHub health checks run every five minutes and can independently inspect and recover all three hosts.
- One sanitized GitHub Issue exposes current health for all three hosts and remains readable through the GitHub connector when every Desktop Commander device is offline.
- No automatic workflow logs or status surfaces expose device codes, tokens, cookies, session blobs, private keys, raw provider auth output, or `device.json` contents.
- A fresh final GitHub control-plane run reports all three hosts healthy without relying on Desktop Commander for verification.
