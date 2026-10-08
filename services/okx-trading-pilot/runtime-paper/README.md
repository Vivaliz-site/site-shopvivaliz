# OKX PAPER continuous runtime

Public market data and simulated accounting only. No live execution or credential access.

## Scope

Discover active spot instruments quoted in USD/USDT/USDC and linear SWAP/FUTURES settled in those currencies. No asset whitelist. Retain at most 12 liquidity-ranked candidates, fairly interleaved between instrument classes, and consider every retained candidate rather than only two. Positions are subject to margin/risk budgets, not a fixed position count. Metadata supplies contract face value, size increments and minimum size. Inverse contracts, options and ambiguous multipliers are rejected. Futures within 24 hours plus five minutes of expiry are not eligible for new positions; maximum holding time is 24 hours per position, not a limit on service uptime.

## Simulation boundary and limitations

The primary decision provider is the local authenticated ChatGPT/Codex bridge using gpt-5.6-terra at medium reasoning effort. If that login-based Codex path is unavailable for quota/auth/transport reasons, the only automatic model fallback is normal ChatGPT through the canonical Dev browser MCP (127.0.0.1:5583 -> CDP 9559, account dev@shopvivaliz.com.br) using GPT-5.6 Sol at Extra High reasoning (xhigh). The fallback opens a fresh Temporary Chat while holding the shared browser maintenance lock and closes that tab after the response; it does not create or reuse a third browser profile. Every model result must satisfy an exact 20-layer schema, from market_regime through adversarial_review, contain supporting and contrary evidence, and expire within 120 seconds. At most two model analyses may be in flight at once; the market/position loop never waits for them. Invalid JSON, unavailable or revoked authentication, wrong model identity, stale decisions, or missing required evidence fail closed for new entries. There is no heuristic fallback or OpenAI Platform API-key fallback in the production runner. The heuristic provider remains only as a deterministic unit-test fixture.

The 20-layer engine does not place orders and cannot modify risk limits. It receives normalized public OKX context (5m/1h/4h candles, order book, derivatives open interest/funding where applicable) plus the local PAPER portfolio/risk state. No exchange credentials are passed to the model. The deterministic Risk Gateway remains authoritative after every model decision.

Fees are modeled at 6 bps per side, entry/exit slippage at 5 bps, and size uses an additional 25-bps risk buffer. Defaults propose US$1.50 risk and 2x leverage for derivatives (1x for spot), below the immutable ceilings. Rates are assumptions, not authenticated account fee quotes. USD, USDT and USDC are treated as 1:1 for this baseline. Open PnL uses observed bid/ask and excludes hypothetical future exit fees. Funding uses actual published rates but current observed valuation, not a historical replay of settlement mark prices. Both SWAP and X-Perps FUTURES funding are included; recovered historical events are applied once. Exchange-exact liquidation, full order-book depth and inverse/options accounting are not implemented. This is not evidence of LIVE readiness or AI-strategy profitability.

Limits: US$100 starting reference, US$10 risk per entry, US$30 total open risk, US$20 conservative correlated risk, US$15 daily loss stop, US$30 cumulative loss stop, maximum leverage 20x. Daily baseline rolls in America/Sao_Paulo. The correlated-risk approximation groups all open exposure conservatively. Loss checks include marked open PnL. Missing held-position quotes and provider errors block new entries, while fresh-price protective management remains independent.

## Persistence and operation

Systemd runs with `--cycles 0` and restarts automatically. One writer per state file is enforced using flock. The local JSON ledger atomically preserves run identity, baseline, fees, funding, open positions and closed results. Invalid persisted state fails rather than resetting. Status is regenerated each cycle with timestamps and data readiness. The scan waits five seconds between cycles; request latency is additional. Production state lives outside immutable releases:

- `/var/lib/shopvivaliz-okx-paper/paper-state-v3.json`
- `/var/lib/shopvivaliz-okx-paper/paper-status-v3.json`
- `/var/lib/shopvivaliz-okx-paper/paper-events-v3.jsonl`

The pre-persistence legacy run cannot be reconstructed exactly from its partial audit. Preserve it separately; never present a new baseline as uninterrupted old performance. Test/smoke portfolios are not imported into the operational ledger.

Run tests with `python -m pytest -q`. Run the operational read-only check with `python scripts/verify_runtime.py` on the backend after a verified immutable deployment.

## References verified on 2026-10-05

- OKX REST documentation: https://app.okx.com/docs-v5/en/
- Tickers: volCcy24h is quote volume for spot and base volume for derivatives.
- Public instruments: linear notional is contracts times ctVal times price.
- Funding history accepts SWAP and X-Perps FUTURES instruments.

## Login-only deployment requirement

The primary path defaults to Terra + medium over the loopback Codex bridge. Its only model fallback is Sol + Extra High through the loopback normal-ChatGPT bridge, which delegates exclusively to the canonical Dev browser MCP. Both paths use corporate ChatGPT login; OpenAI Platform API-key access, cross-account browser fallback, and heuristic execution are forbidden. After a primary availability failure, Codex is backed off before being probed again; browser-provider failures also enter cooldown. This candidate is not operational until the real primary/fallback inference gates in reports/ai-engine-validation.md pass.
