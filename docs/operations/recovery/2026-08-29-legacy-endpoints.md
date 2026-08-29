# Legacy Endpoint Inventory — 2026-08-29

## Retired endpoints
- Backend E2 name: `shopvivaliz-ai`
- Backend E2 public/private: `137.131.156.17` / `10.0.1.13`
- Site E2 name: `shopvivaliz-micro-2`
- Site E2 public/private: `136.248.69.116` / `10.0.1.203`

## Canonical surviving roles
- Backend A1: `always-free-arm-1787907847-26` — `144.22.157.209` / `10.0.1.38`
- Site A1: `shopvivaliz-free-a1` — `163.176.103.253` / `10.0.1.112`

## Repository scan baseline
A tracked-file scan of the recovery branch found **808 references across 437 files**. Classification by unique file:
- 286 `.github/workflows/*.yml` files that are technically executable by GitHub Actions.
- 22 `.disabled` workflow files.
- 14 script/config/ops files.
- 3 test files.
- 97 documentation/report files.

Historical docs and logs are intentionally not rewritten. The 286 executable workflow files require lifecycle classification before remediation because many are dated one-off diagnostics/hotfixes that should be archived/disabled instead of retargeted forever.

## Confirmed active defects
Examples include `abandoned-cart-cron-install.yml`, catalog/stock workflows, runtime guards, Shop Vivaliz production automation, MEI audits/probes, M365 tooling, Desktop Commander/Fred Win relays, emergency recovery workflows, Shopee workflows and local SSH/MCP/tunnel configuration.

The prior cutover branch `fix/a1-vm-cutover-20260829` already contains commit `f9e4136112` (`fix: retarget workflows to A1 VMs after cutover`). It correctly retargets 11 high-value files, including deploy/runtime, MEI probe/base-sync, Shopee health, Desktop Commander and Fred Win routes. That reviewed historical work should be reused rather than reimplemented blindly.

## Remediation policy
1. Preserve historical evidence and disabled workflows unchanged unless they create an active risk.
2. Identify permanent scheduled/push/production workflows and retarget them by backend/site role.
3. Dated one-off diagnostics, temporary fixes and superseded emergency workflows should be disabled/archived after confirming no unique current responsibility.
4. Prefer `SHOPVIVALIZ_VM_HOST`/role-specific indirection or a centralized host-role contract where available; do not introduce new retired-IP literals.
5. Back up Fred Win SSH config before removing the four active E2 aliases.
6. Add a repository gate that rejects retired E2 endpoints in executable workflows/scripts/config while allowing explicitly historical docs/reports.
7. For every repaired permanent workflow, validate YAML/shell syntax and prove the target resolves to the intended A1 role before marking it healthy.

## Completion condition
The endpoint gate is complete only when executable/config paths have zero retired E2 targets, all permanent automation has a known owner/role, and historical occurrences are isolated as non-executable evidence.
