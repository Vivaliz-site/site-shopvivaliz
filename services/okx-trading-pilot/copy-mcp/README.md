# OKX Copy Trading MCP — ShopVivaliz

## Scope
Sidecar extension for the existing local bridge on `127.0.0.1:17671`. New interfaces are available under `okx.copy.*` through the same `POST /v1/call`. The upstream `@okx_ai/okx-trade-mcp@1.4.8` remains read-only by default; Spot/Futures write tools are exposed only when both `OKX_MCP_READ_ONLY=0` and `OKX_SPOT_FUTURES_WRITE_ENABLED=1` are present and the upstream tool metadata marks the selected tool writable. `GET /v1/tools` reports schemas and `GET /v1/copy/health` reports the Copy extension state.

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
| `stop` | POST /api/v5/copytrading/stop-copy-trading | Write gated; journal-first, read-only reconciliation supported |
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
- The Copy-specific REST adapter accepts the complete protected environment triplet `OKX_COPY_API_KEY`, `OKX_COPY_API_SECRET`, `OKX_COPY_API_PASSPHRASE` when all three are present. Otherwise it uses the protected local `~/.okx/config.toml` `profiles.live` credential. A partial environment triplet fails closed; secrets are never returned or logged.
- `OKX_COPY_WRITE_ENABLED=1` is a **separate** gate for Copy Trading writes. Generic Spot/Futures writes use the independent pair `OKX_MCP_READ_ONLY=0` + `OKX_SPOT_FUTURES_WRITE_ENABLED=1`. These gates do not bypass per-operation Copy preview/grant/journal controls.
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
- The generic upstream OKX MCP is started with `--read-only` unless `OKX_MCP_READ_ONLY=0`. Even then, only upstream Spot/Futures tools explicitly identified as writable by provider metadata are admitted, and only while `OKX_SPOT_FUTURES_WRITE_ENABLED=1`; Copy writes remain isolated behind their own approval/journal gate.
