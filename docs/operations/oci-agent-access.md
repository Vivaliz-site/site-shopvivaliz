# OCI access for agents — active layout 2026-10-29

Read [the current topology and MCP matrix](../knowledge/current-topology-and-access-2026-10-29.md), [the host access runbook](../knowledge/host-access.md), [direct VM access](../../AGENTS-VM-ACCESS.md), and [dual tenancy identities](../ops/oci-dual-account-access.md) before executing commands.

## Current two-tenancy layout

- **ESPOSA**: `marina-a1-4c24g` is web/deploy, MySQL, workers and runner; private IP `10.80.1.116` from earlier OCI documentation, Tailscale observed `100.118.170.46`. Marina was **ONLINE** through both RDC connectors on 2026-10-29; `hostname -s` and `id -un` verified `marina-a1-4c24g` and `ubuntu`.
- **PRINCIPAL**: `always-free-arm-1787907847-26` is backend/control/browser/MCP; private `10.0.1.38`, Tailscale observed `100.66.174.74`; `host_health` through central MCP succeeded.
- **DECOMMISSIONED**: `shopvivaliz-free-a1`, old `vm1`, was reported deleted by the owner on 2026-10-29 and SSH to `10.0.1.112:22` returned `No route to host`. An up-to-date OCI lifecycle query was not done. **Never execute old `vm1`, `shopvivaliz-a1-site-raw`, or source-host Run Commands**, and do not recreate the source as a recovery shortcut.

## Access selection

1. Central ShopVivaliz Remote Control MCP is operational for the backend; its `hosts_list` still contains the deleted origin but does **not** include Marina.
2. For Marina, use `Remote Desktop Commander` or `Remote Desktop Commander 2` with freshly discovered `list_devices` device ID and validate exact host, user, privileges and requested capability. The independent `marina-direct-mcp` and OpenAI tunnel-client were **inactive/not connected** on 2026-10-29. The Cloudflare admin service being active does not prove MCP authentication.
3. SSH/Tailscale/VCN is conditional on approved identity and verified host key; public root SSH is not an acceptable fallback. OCI Bastion/Instance Agent/serial console are audited bootstrap/recovery channels only, subject to official provider permission and prior denials.
4. GitHub Actions in `Vivaliz-site/site-shopvivaliz` can authenticate separately to PRINCIPAL and ESPOSA according to the **2026-10-16** run cited in the dual-account runbook. This is not proof that the older local Fred-Win `AGENTS` signing profile or `sv-oci-vm-run vm1/vm2` helpers can administer both tenancies today. Never default to a personal OCI profile.
5. Never log keys, tokens, OTPs or protected file contents; never bypass role restrictions, NoNewPrivileges, leases, CI or branch protection. A previously observed NoNewPrivileges value is not proof of current sudo entitlement.

## Verification

A reachable port, `ACCEPTED` Run Command, registered runner or `active` service does not prove an operation succeeded. Require a current signed OCI identity / target lifecycle check for the **specific tenancy**, a terminal command status, verified stdout/exit code, and an independent host check. A production assertion requires relevant runtime/UI E2E evidence.

**Historical evidence (2026-09-06)** for `vm1/vm2` and former SSH aliases is preserved in Git history, not in this active runbook; treat it as superseded, not an operational command.
