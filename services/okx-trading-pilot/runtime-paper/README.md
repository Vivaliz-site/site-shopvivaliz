# OKX PAPER continuous runtime

Public market data and simulated accounting only. No live execution or credential access.

## Scope

Discover active spot instruments quoted in USD/USDT/USDC and linear SWAP/FUTURES settled in those currencies. No asset whitelist. Retain at most 12 liquidity-ranked candidates, fairly interleaved between instrument classes, and consider every retained candidate rather than only two. Positions are subject to margin/risk budgets, not a fixed position count. Metadata supplies contract face value, size increments and minimum size. Inverse contracts, options and ambiguous multipliers are rejected. Futures within 24 hours plus five minutes of expiry are not eligible for new positions; maximum holding time is 24 hours per position, not a limit on service uptime.

## Simulation boundary and limitations

The operational decision provider is a strict priority chain: Gemini Free with recent billing proof, then Ollama local, then normal ChatGPT through an authorized adapter, and finally Codex behind its existing approval gate. Every model result must satisfy an exact 20-layer schema, from market_regime through adversarial_review, contain supporting and contrary evidence, and expire within 120 seconds. At most two model analyses may be in flight at once; the market/position loop never waits for them. Invalid JSON, unavailable or revoked authentication, wrong model identity, stale decisions, or missing required evidence fail closed for new entries. There is no heuristic fallback in the production runner. The heuristic provider remains only as a deterministic unit-test fixture.

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

The candidate defaults to Luna (gpt-6-luna) + medium and permits only a loopback Codex bridge. OpenAI API-key access and silent model/transport fallback are not supported. Authenticate a corporate Codex profile with the native executable, not a legacy profile-selection wrapper; never copy browser cookies or paste credentials in chat. This candidate is not operational until the real login/inference gate in reports/ai-engine-validation.md passes.

## Decision-provider watchdog and incident alerts

The process can be `active (running)` while no valid 20-layer decisions can be obtained. In that condition `ai_20_layers_active=false` and there can be **zero PAPER trades** despite healthy market polling. The watchdog `scripts/provider_watchdog.py` checks the PAPER-only boundary, recent status timestamps, and actual provider readiness, rather than service liveness. It runs on `shopvivaliz-okx-paper-watchdog.timer` every ten minutes, posting the initial failure, a reminder after six hours, and recovery to the existing VM incident issue #1586 (deduplicated by a restricted local state file). It does not place orders or reset the account ledger. The `--dry-run` flag is read-only and returns status code 2 if degraded; never force a positive status for a failed AI bridge. The Codex bridge `paid_provider_disabled` manual approval policy remains untouched: resolving the approval or onboarding an independently verified free-tier model is required before AI-driven trades can resume.

Run a direct status check on the backend using `python3 scripts/provider_watchdog.py --dry-run`. Never mark the simulated strategy APTO solely because market scans advance or systemd reports the service running.


## Ordered fallback and billing safety (2026-11-05)

Priority is exactly: Gemini > Ollama > ChatGPT normal > Codex. A provider is attempted only while its channel is eligible and within its respective access/cost policy. Gemini Free requires an operator-verified Free billing tier for the exact API key and Google project, recorded in the existing protected 0600 attestation for at most 30 days. Without this proof, Gemini is skipped without generating a paid request. Ollama uses a local structured-output model, and every one of its 20 analytical layers must contain model-generated assessment and evidence; invalid results fall through and cannot trigger a paper order. Normal ChatGPT is interactive and has no sanctioned headless inference adapter in this runtime. Until an authorized interactive adapter is available, its slot is skipped rather than manipulating browser sessions or extracting cookies. Codex remains last and retains its provider-side manual approval/cost gate. The provider chain must never bypass a 403.

HOLD and REJECT are valid outcomes from the earliest qualified provider and do not trigger fallback just because no trade was created. The immutable Risk Gateway and PAPER-only broker remain authoritative. Actual source, fallback order, errors and last valid decision timestamp are emitted in status for diagnostics. A running service alone does not prove strategy availability. Ollama calls are bounded to 75 seconds and timeouts cool down for 15 minutes to avoid starving the VM.


## Gemini credentials in the isolated PAPER worker

The worker retains systemd DynamicUser=yes and ProtectHome=yes. Install
the systemd/shopvivaliz-okx-paper-gemini-free-credentials.conf fragment at
/etc/systemd/system/shopvivaliz-okx-paper.service.d/10-gemini-free-credentials.conf
and run systemctl daemon-reload before restarting. systemd LoadCredential
copies two protected sources to private CREDENTIALS_DIRECTORY, which the
provider reads without exposing the originals or putting secrets in argv/env.

The Gemini Free proof recorded in Google AI Studio on 2026-10-12 was
recovered from Marina and fully SHA-256 matched against the existing backend
key on 2026-11-05 (project gen-lang-client-0341449504). No new key or paid
billing was enabled. Recheck the exact project's Free billing tier before
the 30-day attestation expires; never auto-renew it without evidence.

Observe a real 20-layer PAPER-only Gemini decision, the running source
commit, persisted portfolio parity and the active watchdog after deployment.

Systemd 255 exposes private LoadCredential files as root-owned mode 0440 under a root-owned mode 0550 directory beneath /run/credentials/. The permission verifier recognizes only this exact secure systemd layout as the exception to the original protected 0600 source-file requirement. Arbitrary group-readable proof files remain refused.

Gemini generateContent must send JSON Schema through generationConfig.responseJsonSchema (not responseSchema), since the legacy OpenAPI responseSchema rejects additionalProperties and otherwise returns HTTP 400 before inference. The strict 20-layer parser remains the final validation gate.

Continuous market polls remain five-second, but the PAPER decision engine now admits at most one unattended model-analysis attempt each five minutes (288 per 24 hours), regardless of Free-tier provider or fallback. Idle wait is not a provider error and does not make the prior validated AI decision inactive. The provider status exposes seconds until the next analysis slot. This is a conservative ceiling; the project's actual Gemini rate limits are provider-controlled and may be lower.
