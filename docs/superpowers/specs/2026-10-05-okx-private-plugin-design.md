# OKX Private Plugin / MCP Integration Design

**Date:** 2026-10-05  
**Repository:** `Vivaliz-site/site-shopvivaliz`  
**Target runtime:** `always-free-arm-1787907847-26`  
**Status:** Design approved in chat; written spec pending user review before implementation.

## Goal

Create a private ShopVivaliz OKX integration that lets ChatGPT inspect the user's real OKX account through the official OKX Agent Trade Kit / MCP server, with live account access starting in strict read-only mode and without exposing API credentials.

The initial production capability must support reliable investigation of:
- account balances;
- open positions;
- position history;
- orders and fills;
- account bills / ledger;
- realized and unrealized PnL;
- fees and funding;
- contract quantities and historical trade evidence needed to reconcile discrepancies.

## Chosen architecture

Use the official OKX Agent Trade Kit MCP server, package `@okx_ai/okx-trade-mcp`, as the upstream integration layer.

Run the service on the canonical backend VM:
- host: `always-free-arm-1787907847-26`;
- role: backend/controller/browser/integrations;
- access path: ShopVivaliz Remote Control MCP first.

The first live profile must run with:
- `--profile live`;
- `--read-only`;
- no write/trade actions exposed to the ChatGPT-facing integration.

A separate write-enabled trading mode is explicitly out of scope for this first implementation. If added later, it must be separately gated, separately permissioned, and must never silently replace the read-only profile.

## Credential handling

The user previously supplied OKX credentials. The implementation must reuse the already provisioned credential source if it can be located and validated.

Rules:
1. Never print, log, return, commit, or document API key, secret key, passphrase, session token, cookie, or derived authentication material.
2. Do not place secrets in Git.
3. Do not place secret values in systemd unit files committed to the repository.
4. Prefer an existing protected runtime secret source on the backend VM.
5. If an OKX config file must be created, it must live outside the repository with restrictive ownership and permissions.
6. Logs and health probes must expose only sanitized state such as `configured=true`, profile name, module availability, and validation result.
7. Credential discovery must inspect only filenames, environment variable names, service references, and protected paths until the specific source is identified.

## Runtime shape

The integration is composed of four responsibilities:

### 1. OKX upstream MCP

Official `@okx_ai/okx-trade-mcp` package.

Responsibilities:
- authenticate to OKX;
- expose OKX market/account/position/order/history tools;
- enforce upstream read-only mode.

### 2. ShopVivaliz runtime wrapper

A small ShopVivaliz-owned runtime definition around the official server.

Responsibilities:
- start the exact expected command;
- bind to the intended credential profile;
- make read-only mode non-optional;
- provide deterministic environment/config paths;
- sanitize health output;
- fail closed when credentials or profile are invalid;
- avoid leaking command-line secrets.

### 3. Private ChatGPT plugin connection

Expose the OKX MCP to ChatGPT as a private plugin / MCP integration.

Responsibilities:
- surface account and market tools in chat;
- keep the default usable surface read-only;
- make source attribution explicit so account facts come from OKX data rather than screenshots or inferred values.

### 4. Validation layer

Provide reproducible checks that prove:
- official package is installed and version-resolved;
- MCP process starts;
- live authentication succeeds;
- read-only account queries succeed;
- balances/positions/orders/fills/bills tools return structured data;
- write/trade actions are unavailable or rejected in the read-only profile;
- no secret values appear in checked logs/output.

## Data flow

`ChatGPT private plugin -> ShopVivaliz OKX MCP runtime -> official OKX Agent Trade Kit -> OKX API`

No browser dependency is required for normal account reads once API authentication is valid.

## Initial tool scope

Required read capabilities:
- market ticker / contract metadata;
- account balance;
- positions;
- position/history data when exposed by the upstream MCP;
- pending/open orders;
- historical orders;
- fills;
- account bills / ledger;
- fee/funding-related data available to the account.

The implementation may expose additional read-only official OKX tools when they are part of the same supported module set, but it must not expose write operations in the initial live profile.

## Safety and fail-closed behavior

The runtime must refuse to claim readiness when any of these are true:
- OKX authentication fails;
- the expected live profile is absent;
- read-only mode is not active;
- required account tools are missing;
- the service starts but account probes fail;
- credentials are only partially configured.

A process being `active` or an MCP server starting successfully is structural evidence only. Final validation requires successful authenticated account reads and a negative test proving trade/write behavior is blocked.

## Test strategy

Implementation must follow TDD where ShopVivaliz-owned code is added.

Required tests include:
- command construction always includes `--read-only`;
- live profile selection is explicit;
- sanitizer never emits configured secret values;
- health is false when authenticated probes fail;
- health is true only after required read probes succeed;
- a write/trade tool cannot be invoked through the initial ChatGPT-facing profile;
- invalid/expired credentials produce a controlled failure without secret disclosure.

Runtime validation must include:
- fresh market-data probe;
- fresh authenticated account-balance probe;
- fresh positions probe;
- fresh history/fills/bills probe where available;
- explicit negative write test in read-only mode.

## Deployment constraints

- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or an active immutable release directly.
- Use an isolated branch/worktree for repository changes.
- Runtime state and secrets live under protected persistent paths, not an immutable release.
- Any production service must be installed through the repository's established deployment/install mechanism.
- Merge and post-merge validation are separate gates.

## Observability

Permitted health/status fields include:
- upstream package/version;
- service state;
- profile label;
- read_only=true;
- authenticated=true/false;
- required tool availability;
- timestamp of last successful authenticated probe;
- sanitized error class/code.

Forbidden observability:
- API key contents;
- secret key contents;
- passphrase;
- signed request material;
- raw Authorization-like headers;
- complete account response dumps in persistent logs unless separately protected and intentionally required.

## Out of scope for phase 1

- automated trading;
- unattended order placement;
- position closing;
- leverage changes;
- transfers/withdrawals;
- trading bots;
- recurring trading strategies;
- any fallback that disables read-only restrictions.

These can only be designed as a later phase with separate authorization and controls.

## Acceptance criteria

Phase 1 is complete only when all are true:
1. The official OKX MCP package/version is verified from current official sources.
2. The backend host identity is verified live.
3. Existing credential storage is located or a protected replacement source is provisioned without exposing values.
4. Live authentication succeeds.
5. Required account reads succeed against the real OKX account.
6. Read-only enforcement is positively configured and negatively tested against a write action.
7. A private ChatGPT plugin/MCP connection can invoke the approved read tools.
8. No secrets appear in repository content, logs reviewed during validation, or user-visible output.
9. Repository tests and relevant runtime validation pass.
10. Any versioned changes follow branch -> commit -> PR -> review/checks -> merge -> post-merge validation.

## References

Current official sources used for this design:
- OKX Agent Trade Kit API guide: https://www.okx.com/docs-v5/agent_en/
- OKX Agent Trade Kit product page: https://www.okx.com/en-br/agent-tradekit
- npm package: https://www.npmjs.com/package/@okx_ai/okx-trade-mcp

At the time of design review, official documentation explicitly documents `okx-trade-mcp --profile live --read-only` for live read-only monitoring.
