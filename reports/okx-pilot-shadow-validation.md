# OKX Pilot - SHADOW Validation

**Date:** 2026-10-05
**Backend:** `always-free-arm-1787907847-26`
**Mode:** SHADOW / live account reads only

## Evidence

- Python pilot suite on the clean feature worktree: **41 passed**.
- Read-bridge Node suite: **3 passed, 0 failed**.
- Installer shell syntax: PASS.
- Official Agent Trade Kit pinned: `@okx_ai/okx-trade-mcp@1.4.8`.
- Runtime profile: `live`.
- Runtime read-only flag: `true`.
- Upstream MCP connected: `true`.
- Upstream tool count observed: **105**.
- Authenticated account read through the bridge: HTTP **200**.
- Negative write proof: `spot_place_order` returned HTTP **403 READ_ONLY**.
- No credential or account-value data is recorded in this report.

## Result

**SHADOW_READ_ONLY_PASS**.

This proves authenticated read-only connectivity and hard bridge denial of exchange write tools. It does not authorize or enable live order execution.
