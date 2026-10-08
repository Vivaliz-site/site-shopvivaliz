# OKX Copy Trading MCP — ShopVivaliz

## Scope
Sidecar extension for the existing local bridge on `127.0.0.1:17671`. The original `@okx_ai/okx-trade-mcp@1.4.8` remains `--read-only`, and its tool policy is unchanged. New interfaces are available under `okx.copy.*` through the same `POST /v1/call`. `GET /v1/tools` reports schemas and `GET /v1/copy/health` reports the extension state.

Security target: LIVE sub-account UID `822315791411831486`, never the parent UID. Every supported Copy operation revalidates `GET /api/v5/account/config` and fails closed on UID mismatch.

## Interface and official HTTP mapping

| Interface | Provider HTTP endpoint | Status |
| --- | --- | --- |
| `account.verify` | GET /api/v5/account/config | Read, authenticated via existing upstream |
| `traders.list` | GET /api/v5/copytrading/current-lead-traders | Read, requires dedicated REST auth |
| `trader.details` | GET /api/v5/copytrading/copy-settings + public-stats | Read, requires REST auth |
| `positions.list`, `positions.details` | GET /api/v5/copytrading/current-subpositions | Read, requires REST auth |
| `balance.available` | GET /api/v5/account/balance | Read, authenticated via existing upstream |
| `balance.allocated` | GET /api/v5/copytrading/current-lead-traders, current-subpositions, copy-settings + account/balance | Estimate only |
| `history` | GET /api/v5/copytrading/subpositions-history | Read, limited by provider retention |
| `profit_loss` | GET /api/v5/copytrading/current-subpositions + subpositions-history | Estimate, not full settled PnL |
| `spot.status`, `futures.status` | GET /api/v5/copytrading/config + current-lead-traders + current-subpositions | Read |
| `stop` | POST /api/v5/copytrading/stop-copy-trading | Write gated, not live exercised |
| `positions.close` | POST /api/v5/copytrading/close-subposition | Write gated, not live exercised |
| `funds.release` | No distinct official API method | UNSUPPORTED_BY_OKX, assisted UI |
| `funds.internal_transfer` | POST /api/v5/asset/transfer, restricted funding/trading, same UID | Write gated, not live exercised |
| `trader.start` | POST /api/v5/copytrading/first-copy-settings | Write gated, not live exercised |
| `trader.settings.update` | POST /api/v5/copytrading/amend-copy-settings | Write gated, not live exercised |
| `transaction.verify` | Persistent local operation journal and provider follow-up reads for writes | Unknown operations never auto replay |

Reference: https://www.okx.com/docs-v5/en/ and https://www.okx.com/docs-v5/log_en/ (not all methods available in all jurisdictions/account modes).

## Deployment and access requirements

- Port 17671 binds to **loopback only**.
- The existing MCP process reads its separately provisioned LIVE profile. This extension uses that SDK only for authenticated account config and balance; it **does not retrieve secrets from the SDK**.
- The Copy-specific REST adapter accepts three values only through the service's **protected environment**: `OKX_COPY_API_KEY`, `OKX_COPY_API_SECRET`, `OKX_COPY_API_PASSPHRASE`. Provision these privately and without terminal output/logs, after confirming the profile belongs to the required sub-account. The production env file was absent at initial inspection. Without these values, REST-specific Copy calls fail closed with `LIVE_CREDENTIALS_MISSING`.
- `OKX_COPY_WRITE_ENABLED=1` is a **separate**, normally absent write gate. Do not enable without a new user-specific operational authorization.
- State root: `/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot/copy-state`, owned by service user, directory permissions 0700. Subdirectories `plans`, `operations` and `grants` must not be exposed publicly; JSON operation files use mode 0600.
- A financial execution requires: new preview, exact UID, correct modality, live lead-trader uniqueCode and name, accurate account snapshot, a short-lived `grants/<approval_id>.json` created by the authorized operator specifically for that preview and operation, a unique request ID, and the global write gate. The grant must contain `plan_id`, `tool`, `uid`, and `expires_at`. No API route issues an approval grant.
- Snapshot drift rejects mutation. All mutating operations begin as `UNKNOWN` in a synced local journal before POST; after transport timeouts, results remain UNKNOWN, requiring **read-only reconciliation or human investigation**. No automatic retry, even after service restart. A lock serializes financial execution. No external withdrawal API exists in the adapter.
- Only old traders `slime198888` and `Modern-dAPI-Manatee` may be selected for stopping/closing; new traders `Caesar cipher` and `Xiaoyao Lee` for start/configuration. `BestMax` and `NANO IA` are blocked from these operations.
- No financial operations should be run to test the integration. Demo writes are permitted only when OKX supports the tested Copy flow.

## Reproducible checks

```sh
node --test tests/*.test.mjs
curl -fsS http://127.0.0.1:17671/health
curl -fsS http://127.0.0.1:17671/v1/tools
systemctl is-active shopvivaliz-okx-mcp.service
systemctl is-enabled shopvivaliz-okx-mcp.service
```

Use `okx.copy.account.verify` and `okx.copy.balance.available` for live non-financial smoke tests. The GET /health check proves process + MCP handshake, not the Copy API permissions. Do not label Copy Trader E2E validated unless real copy GET endpoints also return valid account-specific data.

## Runtime hardening

- Every Copy Trading call verifies the exact authorized subaccount through both the upstream MCP profile and the REST credential used for Copy endpoints. A mismatch fails closed before Copy data or writes are accepted.
- REST credentials may come from the dedicated `OKX_COPY_API_*` environment variables only when all three are present; otherwise the protected local `~/.okx/config.toml` live profile is used. Partial environment configuration fails closed. Secrets are never returned by the module.
- Historical selected-trader names are pinned to their currently verified OKX identities. Protected traders remain immutable through this adapter.
- Stopping a Smart Sync copy requires explicit `confirmSmartSync=true` in addition to the normal preview/approval flow.
- An `UNKNOWN` stop is never replayed automatically. `okx.copy.transaction.verify` may mark it `VERIFIED` only from read-only evidence that the exact lead trader is no longer followed and no position for that unique code remains; the journal records `READ_ONLY_STOP_ABSENT`.
- Volatile equity display fields are excluded from the preflight hash, while releasable cash/balance and trader identity remain part of the safety snapshot.
- The generic upstream OKX MCP remains started with `--read-only`; Copy writes use only the guarded adapter and its approval/journal controls.
