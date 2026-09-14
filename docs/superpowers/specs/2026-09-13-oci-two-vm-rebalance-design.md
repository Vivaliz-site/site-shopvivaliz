# OCI Two-VM Rebalance Design

## Goal
Rebalance the two OCI Ampere A1 VMs for reliability, zero/near-zero cost, lower disk pressure, safer networking, verified backups, and automated cleanup of ephemeral environments.

## Current topology
- `always-free-arm-1787907847-26`: 3 OCPU, 15 GiB RAM, 100 GB boot, production Solange + MEI + many stale preview stacks, OCI Object Storage backup role.
- `shopvivaliz-free-a1`: 1 OCPU, 7.7 GiB RAM, 100 GB boot, ShopVivaLiz integrations/SAFE-T plus duplicate Solange stack.
- Both are private-only VNICs in 10.0.1.0/24, same AD, different fault domains.
- Object Storage bucket `shopvivaliz-free-archive` holds Standard + Archive data; PAR used for migration is expired.

## Design
1. Repair OCI controller clock/auth and perform read-only tenancy audit before any shape change.
2. Verify backups for persistent databases before cleanup or migration.
3. Remove only preview/homologation stacks proven orphaned and prune unused Docker assets.
4. Keep Solange production on the larger host; keep ShopVivaLiz/SAFE-T integrations on the other host. Do not move stale previews.
5. Add TTL-based preview cleanup tied to PR lifecycle and age.
6. Harden host/network exposure and prefer private paths/Bastion/Run Command.
7. Add OCI budgets, alarms, logging/flow visibility and security services when available and cost-safe.
8. Resize from 3+1 to 2+2 OCPUs only if tenancy limits/cost audit proves the aggregate 4 OCPU/~24 GiB remains cost-safe.

## Safety gates
- No destructive cleanup before verified database dumps/checksums for affected persistent data.
- No shape resize before OCI cost/limit confirmation.
- No port closure until dependency/listener checks prove the service is not externally required.
- Every change has a rollback path and post-change health check.
- Do not expose OCI API keys, PARs, tokens, database secrets, or auth headers in logs or commits.

## Success criteria
- Both VMs healthy and reachable through approved management paths.
- Production services pass health checks after cleanup/rebalance.
- Stale preview environments no longer consume persistent resources and future previews expire automatically.
- Root disk pressure reduced; Docker reclaimable assets removed safely.
- Verified backups exist for critical databases with documented retention.
- OCI monitoring/cost guardrails and security posture improved without unintended charge.
