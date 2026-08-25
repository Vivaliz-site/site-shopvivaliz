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

Review one-shot tasks `ShopVivalizGoogleAdsOAuth` and `ShopVivalizGraphBrowserAuth`. Remove them only if they have no future trigger, no current dependency, and no workflow/script that intentionally recreates them. Preserve `ShopVivaliz Auto Sync`, `ShopVivaliz Desktop Commander 24h`, `ShopVivaliz Fred-Win Relay 24h`, and `ShopVivalizExchangeRestrictedSender`.

## VM cleanup

Remove the legacy `/etc/systemd/system/desktop-commander.service` unit file, reload systemd, and verify it becomes `not-found`. Harden `scripts/install-vm-desktop-commander-service.sh` so future installs remove the legacy unit file rather than merely disabling it.

Investigate disabled/inactive units and timers before deletion: `shopvivaliz-mcp.service`, `shopvivaliz-monitor.service`, `shopvivaliz-sync.service`, `shopvivaliz-sync-products.service`, `shopvivaliz-24x7.timer`, `shopvivaliz-auto-sync.timer`, `shopvivaliz-git-sync.timer`, and `shopvivaliz-sync-safe.timer`. Delete only units proven to have no reverse dependencies, no active process owner, and no intended current deployment path.

Remove stale backup unit files only after confirming they are not referenced by any service, script, workflow, or recovery path.

## Anti-recreation controls

Search the repository for creation/registration of every removed task/unit. Remove or update obsolete installers/workflows so another agent cannot recreate the legacy path. Canonical installers should actively remove known legacy persistence artifacts during bounded repair.

The three-host recovery workflow must use targeted file restore and `Ensure` for already-correct Windows tasks; `InstallTask` is allowed only when the canonical task is missing or has invalid S4U/Highest configuration. VM recovery must use targeted restore rather than broad `git merge --ff-only` on a possibly dirty checkout.

## Verification

After cleanup, run a fresh audit.

Fred-Win must show: online; app version 0.2.47; canonical count 1; noncanonical count 0; S4U/Highest; last task result 0; `AUTH_REQUIRED=False`; relay health OK; exactly one managed SSH tunnel; no redundant MCP startup task or startup launcher.

VM must show: online; canonical service enabled/active; legacy `desktop-commander.service` not found; exactly one logical `0.2.47 --persist-session` process tree; `AUTH_REQUIRED=False`; relay 5557 up while Fred-Win is online; no redundant DC systemd unit.

No removed artifact may reappear after at least one automatic watchdog/auto-sync/control-plane cycle. The KOCEPSV host will receive the same audit when it is powered on.