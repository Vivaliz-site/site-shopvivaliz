# Host Cleanup and Anti-Recreation Design

Date: 2026-08-24
Scope: shopvivaliz-ai VM and LAPTOP-NIG4IFUU (Fred-Win). DESKTOP-KOCEPSV is excluded while powered off.

## Goal

Remove redundant, obsolete, or orphaned persistence paths that can be re-enabled by another agent, while preserving the current canonical Desktop Commander 24h architecture and all unrelated production services.

## Invariants

- Canonical Desktop Commander stays pinned to `@wonderwhy-er/desktop-commander@0.2.47 remote --persist-session`.
- Fred-Win keeps exactly one logical canonical DC launcher, S4U/Highest watchdog, one local MCP relay, and one reverse SSH tunnel.
- VM keeps exactly one canonical `shopvivaliz-desktop-commander.service` systemd unit.
- Provider-enforced authorization is never bypassed and no device/session/token contents are read or published.
- Dirty/divergent repositories are preserved; no `reset --hard`, blind clean, rebase, or broad merge.
- Active production services unrelated to the cleanup are not removed without direct evidence that they are redundant.

## Fred-Win cleanup

Remove the redundant scheduled task `ShopVivaliz FredWin MCP Startup` and its launcher `C:\site-shopvivaliz\iniciar-fredwin-mcp.bat`, because it only invokes `fredwin-remote-bootstrap.ps1` at logon and duplicates the canonical `ShopVivaliz Fred-Win Relay 24h` watchdog.

Keep `ShopVivaliz Auto Sync`, `ShopVivaliz Desktop Commander 24h`, and `ShopVivaliz Fred-Win Relay 24h`. Do not install OpenSSH Server; only the SSH client is required for the reverse tunnel.

## VM cleanup

Remove legacy/obsolete runtime artifacts proven unused: `desktop-commander.service`, `shopvivaliz-mcp.service`, `shopvivaliz-monitor.service`, `shopvivaliz-sync.service`, `shopvivaliz-24x7.service`, `shopvivaliz-24x7.timer`, `shopvivaliz-auto-sync.service`, `shopvivaliz-auto-sync.timer`, `shopvivaliz-git-sync.service`, `shopvivaliz-git-sync.timer`, and stale Shopee unit backups.

Preserve `shopvivaliz-sync-safe.*`, `shopvivaliz-agent.service`, `shopvivaliz-queue-worker.service`, token renewers, `shopvivaliz-products-active-sync.service`, and other active production services. Preserve `shopvivaliz-agent-bridge.service`, `shopvivaliz-catalog-audit.service`, and `shopvivaliz-orchestrator.service` pending separate migration/retirement decisions.

## Anti-recreation controls

Canonical installers and workflows must not recreate retired units/tasks. `scripts/install-vm-desktop-commander-service.sh` must remove the legacy `desktop-commander.service` file, not merely disable it. `.github/workflows/fred-win-remote-action.yml` must not expose the legacy `install_mcp_startup` action. Retired VM sync/watchdog units must be treated as absent/unsupported by current installers.

## Verification

Fred-Win: online; app 0.2.47; canonical count 1; noncanonical 0; S4U/Highest; last result 0; `AUTH_REQUIRED=False`; relay health OK; no `ShopVivaliz FredWin MCP Startup`; no `iniciar-fredwin-mcp.bat`; no OpenSSH Server.

VM: online; `shopvivaliz-desktop-commander.service` enabled/active; `desktop-commander.service` not found; exactly one logical `0.2.47 --persist-session` tree; `AUTH_REQUIRED=False`; relay 5557 up; retired units not found; preserved services unchanged.

No removed artifact may reappear after one automatic watchdog/auto-sync/control-plane cycle.