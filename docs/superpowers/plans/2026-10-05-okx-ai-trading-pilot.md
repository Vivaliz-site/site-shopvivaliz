# OKX AI Trading Pilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the approved staged OKX subsystem that dynamically discovers opportunities, produces strict 20-layer ChatGPT decisions, enforces all financial limits outside the model, reconciles against OKX, and progresses through SHADOW and PAPER before any separately enabled LIVE_PILOT.

**Architecture:** Use a Python pilot service for scanning, decision validation, risk, state, paper/live execution, and reconciliation. Put the official OKX Agent Trade Kit behind two small local Node MCP bridges: an always-read-only bridge and a separately gated write bridge. Reuse the existing local AI Squad Codex bridge at `127.0.0.1:17656` as the initial DecisionProvider so raw OKX credentials never enter the model context; the model receives only normalized market/account/risk snapshots and returns strict JSON.

**Tech Stack:** Python >=3.10 stdlib (`decimal`, `dataclasses`, `enum`, `sqlite3`, `zoneinfo`, `urllib`), pytest; Node.js >=18; `@okx_ai/okx-trade-mcp@1.4.8`; dev-only `@okx_ai/okx-trade-cli@1.4.8` for credential-free tool-registry export; `@modelcontextprotocol/sdk@1.32.0`; `zod@4.6.4`; SQLite; systemd; existing ShopVivaliz Codex bridge.

**Spec:** `docs/superpowers/specs/2026-10-05-okx-private-plugin-design.md`

## Global Constraints

- Canonical repository: `Vivaliz-site/site-shopvivaliz`.
- Canonical backend runtime: `always-free-arm-1787907847-26`.
- Modes are exactly `SHADOW -> PAPER -> LIVE_PILOT`; the runtime may never promote itself to `LIVE_PILOT`.
- Pilot reference capital: **US$100**.
- Maximum risk per operation: **US$10**.
- Maximum simultaneous total open risk: **US$30**.
- Maximum correlated risk per direction/cluster: **US$20**.
- Daily loss stop: **US$15**; it blocks new entries only.
- Daily reset: **00:00 America/Sao_Paulo**.
- Cumulative pilot kill switch: **US$30**; it blocks new entries and does not blanket-liquidate existing positions.
- Maximum leverage: **20x**; it is a ceiling, never a default.
- Minimum confidence: **70/100**.
- Minimum expected R:R: **1.5:1**.
- No fixed position-count limit; risk budgets are authoritative.
- Withdrawals/transfers are never exposed through this subsystem.
- Existing positions remain defensively manageable when new entries are blocked.
- Missing, stale, inconsistent, or ambiguous critical state fails closed for new exposure.
- OKX is authoritative for balances, orders, fills, and positions.
- The Decision Engine cannot change financial limits, execution mode, tool policy, or secret configuration.
- Secrets stay outside Git, prompts, audit payloads, and normal logs.
- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or an active immutable release.
- ShopVivaliz Remote Control is the primary runtime path.
- Versioned work follows branch -> tests -> commit -> PR -> checks/review -> merge -> post-merge validation.
- The installer must verify Node >=18 and Python >=3.10 before installing; unsupported runtime versions fail before service changes.
- LIVE_PILOT activation is a separate explicit action after readiness evidence; implementation/merge does not enable it.

## File Structure Locked by This Plan

```text
services/okx-trading-pilot/
├── pyproject.toml
├── README.md
├── config/
│   └── pilot.example.toml
├── okx_pilot/
│   ├── __init__.py
│   ├── domain.py
│   ├── config.py
│   ├── store.py
│   ├── scanner.py
│   ├── decision.py
│   ├── instrument_risk.py
│   ├── correlation.py
│   ├── risk.py
│   ├── state_machine.py
│   ├── reconciliation.py
│   ├── execution.py
│   ├── paper.py
│   ├── orchestrator.py
│   ├── health.py
│   └── adapters/
│       └── okx_bridge.py
├── scripts/
│   ├── run.py
│   └── validate_runtime.py
└── tests/
    ├── test_domain_config.py
    ├── test_okx_bridge.py
    ├── test_store_state.py
    ├── test_scanner.py
    ├── test_decision.py
    ├── test_instrument_risk.py
    ├── test_correlation_risk.py
    ├── test_paper.py
    ├── test_execution.py
    ├── test_reconciliation.py
    └── test_orchestrator_health.py

ops/okx-trading-pilot/
├── package.json
├── package-lock.json
├── mcp-bridge.mjs
├── tool-policy.mjs
└── tool-catalog-1.4.8.json

tests/
└── okx-trading-mcp-bridge-test.mjs

deploy/systemd/
├── shopvivaliz-okx-mcp-read.service
├── shopvivaliz-okx-mcp-write.service
└── shopvivaliz-okx-trading.service

scripts/
├── install-okx-trading-pilot.sh
└── enable-okx-live-pilot.sh

.github/workflows/
└── okx-trading-pilot.yml
```

Persistent runtime paths:

- database/audit: `/home/ubuntu/shopvivaliz-deploy/shared/okx-trading/okx-trading.sqlite3`;
- sanitized readiness: `/home/ubuntu/shopvivaliz-deploy/shared/okx-trading/live-readiness.json`;
- write-bridge token: `/home/ubuntu/shopvivaliz-deploy/shared/okx-trading/write-bridge.token`, mode 0600;
- official OKX profile: protected `~ubuntu/.okx/config.toml`, mode 0600, or an already-provisioned equivalent discovered without printing values;
- live activation marker: `/etc/shopvivaliz-okx-trading/live-pilot.enabled`, root-owned and absent by default.

## Fixed Runtime Defaults

These are implementation defaults, not permission to raise the approved financial limits:

- scanner interval: 5 seconds;
- maximum scanner candidates retained per cycle: 12;
- maximum concurrent deep decisions: 2;
- DecisionProvider model: `gpt-5.6-sol`;
- DecisionProvider effort: `medium`;
- DecisionProvider web search: disabled;
- DecisionProvider timeout: 45 seconds;
- execution ticker/order-book maximum age: 5 seconds;
- DecisionIntent maximum age: 120 seconds and only while current price remains inside its explicit entry band;
- non-option maximum spread at authorization: 50 bps;
- long-option maximum spread at authorization: 150 bps;
- non-option maximum estimated slippage: 25 bps;
- long-option maximum estimated slippage: 75 bps;
- minimum executable book depth: 10x planned order notional;
- derivatives in LIVE_PILOT use isolated margin only.

SHADOW/PAPER evidence may justify tightening these defaults before LIVE_PILOT. No tuning may loosen the user-approved capital/risk/loss/leverage/confidence/R:R limits without a new explicit design change.

## Review Focus

- **MCP/tool drift:** pinned 1.4.8 must expose exactly the logical read/write capabilities mapped in `tool-catalog-1.4.8.json`; missing or unexpected write tools fail closed.
- **Stop/gap/liquidation risk:** sizing must account for fees, executable slippage/gap buffer, contract multipliers, and leverage; a nominal stop may not be treated as guaranteed loss.
- **Ambiguous submit/partial fill:** timeout after acceptance or partial fill must reconcile before resend and must establish protection for actual filled quantity before new exposure.
- **Restart/exchange divergence:** OKX wins over local state; open-risk/daily/cumulative budgets are rebuilt before entries resume.
- **Clock/model freshness:** daily reset uses `ZoneInfo("America/Sao_Paulo")`; malformed, incomplete, timed-out, expired, or out-of-entry-band AI decisions never execute.

---

### Task 1: Domain model, configuration, and immutable limits

**Files:**
- Create: `services/okx-trading-pilot/pyproject.toml`
- Create: `services/okx-trading-pilot/okx_pilot/__init__.py`
- Create: `services/okx-trading-pilot/okx_pilot/domain.py`
- Create: `services/okx-trading-pilot/okx_pilot/config.py`
- Create: `services/okx-trading-pilot/config/pilot.example.toml`
- Test: `services/okx-trading-pilot/tests/test_domain_config.py`

**Interfaces:**
- Produces `Mode`, `DecisionKind`, `InstrumentType`, `Direction`, `MarketSnapshot`, `AccountSnapshot`, `PositionSnapshot`, `DecisionIntent`, `OrderIntent`, `PilotState`.
- Produces `PilotLimits` and `RuntimeConfig.load(path: Path) -> RuntimeConfig`.
- All money/price/risk/size values crossing the Python domain boundary are `Decimal`; timestamps are timezone-aware.

- [ ] **Step 1: Write RED tests for exact enums, Decimal-only financial values, aware timestamps, and every approved hard limit.**
- [ ] **Step 2: Run:** `cd services/okx-trading-pilot && python -m pytest tests/test_domain_config.py -q`  
  **Expected:** FAIL because package/types do not exist.
- [ ] **Step 3: Implement frozen domain dataclasses and configuration loader.** Reject float financial inputs; default mode is `SHADOW`; no config field exists for withdrawal permission.
- [ ] **Step 4: Run the same test command.**  
  **Expected:** PASS.
- [ ] **Step 5: Commit:** `git commit -am "feat(okx): add pilot domain and limits"` after staging new files.

### Task 2: Pinned official OKX MCP bridges and tool policy

**Files:**
- Create: `ops/okx-trading-pilot/package.json`
- Create: `ops/okx-trading-pilot/package-lock.json`
- Create: `ops/okx-trading-pilot/mcp-bridge.mjs`
- Create: `ops/okx-trading-pilot/tool-policy.mjs`
- Create: `ops/okx-trading-pilot/tool-catalog-1.4.8.json`
- Test: `tests/okx-trading-mcp-bridge-test.mjs`

**Interfaces:**
- `createOkxBridge({profile, modules, readOnly, port, tokenFile, transportFactory}) -> http.Server`.
- GET `/health` returns only sanitized package/version, `read_only`, profile label, tool counts, required capability status, and upstream connectivity.
- POST `/v1/call` accepts `{request_id, tool, arguments}`; write bridge additionally requires the protected bearer token.
- Bridge child command is the local lockfile-installed `okx-trade-mcp`, never `npx -y ...@latest`.

- [ ] **Step 1: Create package metadata with exact runtime dependencies `@okx_ai/okx-trade-mcp=1.4.8`, `@modelcontextprotocol/sdk=1.32.0`, `zod=4.6.4` and dev dependency `@okx_ai/okx-trade-cli=1.4.8`; generate and commit lockfile.**
- [ ] **Step 2: Write RED Node tests with an injected fake MCP transport.** Assert read bridge launches upstream with `--profile live --modules market,account,spot,swap,futures,option --read-only`; write bridge omits `--read-only` but rejects calls without its token and rejects transfer/earn/bot/event/news/smartmoney write surfaces.
- [ ] **Step 3: Generate the credential-free registry with the pinned CLI `okx list-tools --json`, normalize it to only tool names/input schemas in `tool-catalog-1.4.8.json`, and test that every logical operation used later maps to exactly one tool.** During Task 11, compare this committed registry with sanitized live MCP `tools/list`; any drift blocks runtime readiness.
- [ ] **Step 4: Implement the minimal loopback-only bridge with the official SDK `Client` + `StdioClientTransport`.** Any tool absent from the committed catalog is rejected; write calls must also be in `tool-policy.mjs`'s exact execution allowlist.
- [ ] **Step 5: Run:** `npm ci --prefix ops/okx-trading-pilot && node --test tests/okx-trading-mcp-bridge-test.mjs`  
  **Expected:** PASS.
- [ ] **Step 6: Commit:** `feat(okx): add pinned MCP bridge and tool policy`.

### Task 3: Python OKX bridge adapter and sanitized capability validation

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/adapters/okx_bridge.py`
- Create: `services/okx-trading-pilot/scripts/validate_runtime.py`
- Test: `services/okx-trading-pilot/tests/test_okx_bridge.py`

**Interfaces:**
- `OkxReadClient.call(tool: str, args: Mapping) -> Mapping`.
- Logical methods: `list_instruments()`, `ticker(inst_id)`, `orderbook(inst_id, depth)`, `candles(inst_id, bar, limit)`, `funding(inst_id)`, `open_interest(inst_id)`, `account()`, `positions()`, `orders()`, `fills()`, `bills()`.
- `OkxWriteClient.call_execution(operation: ExecutionOperation, args: Mapping, request_id: str) -> Mapping`.
- `ExchangeStateUnavailable` is raised for malformed/missing critical data; never substitute zero/empty values.

- [ ] **Step 1: Write RED tests against a fake HTTP bridge for normalization, Decimal conversion, timeouts, non-2xx, malformed payloads, and secret-redacted errors.**
- [ ] **Step 2: Run:** `python -m pytest tests/test_okx_bridge.py -q`  
  **Expected:** FAIL.
- [ ] **Step 3: Implement adapter using stdlib HTTP only; tool names come from the committed 1.4.8 catalog mapping, not scattered string literals.**
- [ ] **Step 4: Implement `validate_runtime.py` to prove exact package version, required tools, authenticated account reads, read-only rejection of one write tool, and sanitized output.**
- [ ] **Step 5: Run tests.**  
  **Expected:** PASS.
- [ ] **Step 6: Commit:** `feat(okx): add normalized OKX bridge adapter`.

### Task 4: Durable SQLite audit/state and lifecycle

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/store.py`
- Create: `services/okx-trading-pilot/okx_pilot/state_machine.py`
- Test: `services/okx-trading-pilot/tests/test_store_state.py`

**Interfaces:**
- `PilotStore.open(path: Path) -> PilotStore`.
- `append_event(trade_id, event_type, payload) -> EventRecord`.
- `reserve_order_intent(order_intent_id, decision_id) -> bool` is transactionally unique.
- `load_pilot_state() -> PilotState`, `save_daily_baseline(...)`, `save_live_baseline(...)`.
- `TradeStateMachine.transition(record, event) -> TradeRecord`.

- [ ] **Step 1: Write RED tests for schema versioning, append-only event hashes, unique `order_intent_id`, restart persistence, and legal/illegal lifecycle transitions.**
- [ ] **Step 2: Run:** `python -m pytest tests/test_store_state.py -q`  
  **Expected:** FAIL.
- [ ] **Step 3: Implement SQLite WAL storage with transactions.** Persist only normalized decision/risk/order evidence; no credentials or raw auth material.
- [ ] **Step 4: Test restart reconstruction of mode, daily baseline/date, cumulative live baseline, open-risk projection, and unresolved order intents.**
- [ ] **Step 5: Run GREEN and commit:** `feat(okx): persist pilot audit and lifecycle state`.

### Task 5: Dynamic Market Scanner and deterministic eligibility

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/scanner.py`
- Test: `services/okx-trading-pilot/tests/test_scanner.py`

**Interfaces:**
- `MarketScanner.discover() -> tuple[Candidate, ...]`.
- `evaluate(snapshot: MarketSnapshot) -> EligibilityResult`.
- `rank(eligible: Sequence[Candidate]) -> tuple[Candidate, ...]`.

**Algorithm decisions:**
- No asset whitelist.
- Build the candidate pool from current active OKX instruments plus market-filter/OI-change capabilities when present in the pinned catalog.
- Retain at most 12 candidates per 5-second cycle.
- For options, only inspect chains for underlyings already surfaced by spot/swap/futures discovery; do not deep-analyze every strike.
- Pre-filter inactive instruments, missing metadata, stale ticker/book, zero/non-numeric volume, and clearly unexecutable spread/depth.
- Final spread/slippage/depth gates are repeated with fresh data by RiskGateway.

- [ ] **Step 1: Write RED tests for dynamic discovery, stale/inactive/illiquid rejection, option-chain narrowing, and deterministic ranking with no hardcoded BTC/ETH/SOL preference.**
- [ ] **Step 2: Run:** `python -m pytest tests/test_scanner.py -q`  
  **Expected:** FAIL.
- [ ] **Step 3: Implement scanner with injected `OkxReadClient`; never call the model for ineligible candidates.**
- [ ] **Step 4: Run GREEN and commit:** `feat(okx): add dynamic opportunity scanner`.

### Task 6: ChatGPT DecisionProvider and strict 20-layer contract

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/decision.py`
- Test: `services/okx-trading-pilot/tests/test_decision.py`

**Interfaces:**
- `DecisionProvider.analyze(candidate: Candidate, context: DecisionContext) -> Mapping`.
- Initial concrete provider: `CodexBridgeDecisionProvider(url="http://127.0.0.1:17656/v1/respond", model="gpt-5.6-sol", effort="medium", timeout_seconds=45)`.
- `DecisionParser.parse(payload: Mapping, now: datetime) -> DecisionIntent`.

**Contract decisions:**
- Model gets normalized market/account/portfolio context only; no OKX secret values.
- `web_search=false`; current exchange snapshot is the authoritative decision input.
- Prompt requires exactly 20 layer objects with stable IDs `1..20`, each containing `assessment`, `evidence`, and `risk_flags`.
- Top-level result is JSON only with `TRADE|HOLD|REJECT`, instrument/type/direction, entry band, stop/protection, targets, suggested risk, suggested leverage, horizon, confidence, expected R:R, invalidation, evidence for/against, snapshot timestamp, and expiry.
- Missing layer, malformed JSON, timeout, bridge busy/unavailable, confidence outside 0..100, or expiry >120 seconds is a rejection.

- [ ] **Step 1: Write RED parser/provider tests for complete output and every malformed/incomplete/expired variant.**
- [ ] **Step 2: Test that the prompt contains all 20 stable layer IDs and no credential fields; verify HOLD/REJECT require no order fields beyond their audit explanation.**
- [ ] **Step 3: Run:** `python -m pytest tests/test_decision.py -q`  
  **Expected:** FAIL.
- [ ] **Step 4: Implement loopback Codex-bridge call and strict parser.** Limit concurrent requests to 2; discard queued candidates once stale.
- [ ] **Step 5: Run GREEN and commit:** `feat(okx): add strict 20-layer decision provider`.

### Task 7: Instrument risk adapters, correlation, and deterministic RiskGateway

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/instrument_risk.py`
- Create: `services/okx-trading-pilot/okx_pilot/correlation.py`
- Create: `services/okx-trading-pilot/okx_pilot/risk.py`
- Test: `services/okx-trading-pilot/tests/test_instrument_risk.py`
- Test: `services/okx-trading-pilot/tests/test_correlation_risk.py`

**Interfaces:**
- `InstrumentRiskAdapter.size_for_risk(intent, instrument, market, risk_usd) -> SizedExposure`.
- Concrete adapters: `SpotRiskAdapter`, `LinearDerivativeRiskAdapter`, `InverseDerivativeRiskAdapter`, `LongOptionRiskAdapter`.
- `CorrelationClassifier.cluster(candidate, open_positions, returns) -> CorrelationResult`.
- `RiskGateway.authorize(intent, market, account, pilot_state, open_positions) -> RiskVerdict`.

**Deterministic rules:**
- Same normalized underlying is always one cluster.
- Otherwise compute Pearson correlation on the most recent 48 hourly log returns with at least 36 overlapping returns.
- Positions share correlated risk when `rho * effective_direction_a * effective_direction_b >= 0.70`.
- Option effective direction is signed delta; missing delta rejects option eligibility.
- Option v1 permits only net-long single-leg premium exposure; naked short and multi-leg option entries reject as `unsupported_option_risk_shape`.
- Derivatives use isolated margin; proposed leverage is capped at 20 and additionally at `floor(1 / (2.5 * stop_distance_fraction))`.
- Risk includes stop-distance loss, fees, and a conservative gap/slippage buffer; long-option risk is at least full premium plus fees.
- Normalize risk/account equity to USDT; inability to obtain a reliable conversion rejects.
- Authorization re-fetches ticker/book and enforces 5-second freshness, current price inside entry band, spread/depth/slippage defaults above, confidence >=70, R:R >=1.5, US$10 per-trade, US$30 total open, US$20 correlated, US$15 daily stop, US$30 cumulative kill switch.

- [ ] **Step 1: Write RED sizing tests for spot, linear/inverse derivatives, long options, lot/tick rounding, fee/gap buffers, isolated leverage cap, and unsupported option shorts.**
- [ ] **Step 2: Write RED correlation tests for same underlying, rho threshold 0.70, opposing hedge direction, insufficient history, and signed option delta.**
- [ ] **Step 3: Write boundary tests immediately below/at/above every approved financial gate.** At US$15 daily loss and US$30 cumulative loss, assert new entry blocked but defensive management remains allowed.
- [ ] **Step 4: Add midnight tests immediately before/at/after `00:00 America/Sao_Paulo` and missing unrealized-PnL tests that fail closed.**
- [ ] **Step 5: Run RED:** `python -m pytest tests/test_instrument_risk.py tests/test_correlation_risk.py -q`.
- [ ] **Step 6: Implement pure deterministic adapters/gateway; no model/exchange writes inside these modules.**
- [ ] **Step 7: Run GREEN and commit:** `feat(okx): enforce instrument and portfolio risk gates`.

### Task 8: Realistic PAPER broker

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/paper.py`
- Test: `services/okx-trading-pilot/tests/test_paper.py`

**Interfaces:**
- `PaperBroker.submit(order_intent, market, scenario) -> SimulatedExecution`.
- `PaperBroker.manage_positions(market_updates) -> tuple[SimulatedEvent, ...]`.

- [ ] **Step 1: Write RED tests proving spread, maker/taker fee, funding, slippage, partial fills, rejection, timeout, gap-through-stop, and target fills change realized results.**
- [ ] **Step 2: Require deterministic seeded scenarios so failures are reproducible.**
- [ ] **Step 3: Run RED, implement against the same OrderIntent/state contracts used by live execution, then run GREEN.**
- [ ] **Step 4: Commit:** `feat(okx): add realistic paper broker`.

### Task 9: Idempotent live executor and mandatory protection

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/execution.py`
- Test: `services/okx-trading-pilot/tests/test_execution.py`

**Interfaces:**
- `ExecutionManager.execute(verdict: RiskVerdict) -> ExecutionResult`.
- `ExecutionManager.manage_existing_positions() -> ManagementResult`.
- `ExecutionOperation` maps logical place/cancel/amend/protect/close/leverage actions to the pinned tool catalog.

- [ ] **Step 1: Write RED test proving no RiskGateway rejection can reach `OkxWriteClient`.**
- [ ] **Step 2: Write ambiguous-submit test:** exchange accepts then caller times out; retry must reconcile by client/order identifiers and must not submit a duplicate.
- [ ] **Step 3: Write partial-fill test:** risk/protection resize to actual filled quantity.
- [ ] **Step 4: Write protection-failure test:** state becomes critical, new entries block, defensive reduce/close remains permitted.
- [ ] **Step 5: Write mode/live-marker tests:** SHADOW never writes; PAPER uses only PaperBroker; LIVE_PILOT requires mode + root-owned marker + write bridge health + current risk approval.
- [ ] **Step 6: Implement exact operation mapping from `tool-catalog-1.4.8.json`; transfers/withdrawals/bots remain impossible through the executor.**
- [ ] **Step 7: Run GREEN and commit:** `feat(okx): add idempotent protected live executor`.

### Task 10: Reconciliation, orchestration, daily accounting, and health

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/reconciliation.py`
- Create: `services/okx-trading-pilot/okx_pilot/orchestrator.py`
- Create: `services/okx-trading-pilot/okx_pilot/health.py`
- Create: `services/okx-trading-pilot/scripts/run.py`
- Test: `services/okx-trading-pilot/tests/test_reconciliation.py`
- Test: `services/okx-trading-pilot/tests/test_orchestrator_health.py`

**Interfaces:**
- `Reconciler.reconcile() -> ReconciliationResult`.
- `PilotOrchestrator.run_cycle() -> CycleResult`.
- `HealthService.snapshot() -> Mapping`.

- [ ] **Step 1: Write restart/divergence RED tests for exchange-only position, local-only position, changed fill quantity, ambiguous order, and stale local risk.** OKX must win and entries remain blocked until complete risk reconstruction.
- [ ] **Step 2: Write cycle tests:** reconcile -> scan -> at most 2 fresh decisions -> parse -> risk -> paper/live route; stale or malformed model result never executes.
- [ ] **Step 3: Write daily/cumulative accounting tests including realized PnL, marked unrealized PnL, fees and funding; reset daily baseline at São Paulo midnight only.**
- [ ] **Step 4: Write sanitized health tests for mode, bridge/model health, package version, last reconciliation, freshness, open risk, daily stop, kill switch, and critical protection state.**
- [ ] **Step 5: Implement and run full Python suite:** `cd services/okx-trading-pilot && python -m pytest -q`  
  **Expected:** PASS.
- [ ] **Step 6: Commit:** `feat(okx): compose reconciled trading pilot runtime`.

### Task 11: Immutable runtime installation and SHADOW proof

**Files:**
- Create: `deploy/systemd/shopvivaliz-okx-mcp-read.service`
- Create: `deploy/systemd/shopvivaliz-okx-mcp-write.service`
- Create: `deploy/systemd/shopvivaliz-okx-trading.service`
- Create: `scripts/install-okx-trading-pilot.sh`
- Modify: `services/okx-trading-pilot/README.md`
- Modify: `services/okx-trading-pilot/scripts/validate_runtime.py`

**Interfaces/operational contract:**
- Read bridge binds `127.0.0.1:17660`, uses live profile + approved modules + `--read-only`, and is enabled for SHADOW/PAPER.
- Write bridge binds `127.0.0.1:17661`, requires the protected bearer token and `ConditionPathExists=/etc/shopvivaliz-okx-trading/live-pilot.enabled`; installer leaves it disabled/stopped.
- Pilot service starts in SHADOW unless protected config explicitly says PAPER; it cannot start LIVE_PILOT without the marker and readiness file.
- Installer copies code into an immutable `/opt/shopvivaliz-okx-trading/releases/<release_sha>` tree; mutable DB/config/token remain outside the release.

- [ ] **Step 1: Add static service/installer tests before runtime changes.** Assert immutable paths, service user, loopback binding, read-only flag, write-marker condition, protected token/config permissions, exact package pin, and no automatic live enable.
- [ ] **Step 2: Run all Python + Node tests.**
- [ ] **Step 3: Via ShopVivaliz Remote Control, verify backend identity plus Python/Node/npm versions and metadata-only credential location.** Never print API key/secret/passphrase.
- [ ] **Step 4: Install from an immutable candidate release; provision runtime dir/token/config permissions; do not start write bridge.**
- [ ] **Step 5: Run live SHADOW validation:** exact MCP version, market/account/position/order/fill/bill reads, dynamic scanner, real Codex DecisionProvider, 20-layer parser, deterministic RiskGateway, audit/restart reconciliation.
- [ ] **Step 6: Prove zero writes in SHADOW using read-bridge tool catalog/audit plus a negative write call that upstream/bridge rejects.**
- [ ] **Step 7: Commit ops/docs changes:** `ops(okx): install shadow-safe trading pilot runtime`.

### Task 12: CI, PAPER/fault evidence, independent review, and LIVE readiness

**Files:**
- Create: `.github/workflows/okx-trading-pilot.yml`
- Create: `scripts/enable-okx-live-pilot.sh`
- Create: `reports/okx-pilot-shadow-validation.md`
- Create: `reports/okx-pilot-paper-validation.md`
- Create: `reports/okx-pilot-live-readiness.md`

**Interfaces:**
- CI runs only for relevant paths and executes both Python and Node pilot suites.
- `enable-okx-live-pilot.sh <release_sha>` validates a matching `live-readiness.json` with `ready=true`, then creates the root-owned marker and enables/starts the write bridge. The script is created/tested but **not executed as part of implementation or merge**.
- Readiness JSON includes the merged release SHA, pinned MCP version, hard-limit hash, no-withdrawal permission check, SHADOW/PAPER result, and fault-injection result.

- [ ] **Step 1: Write a path-scoped CI workflow for `services/okx-trading-pilot/**`, `ops/okx-trading-pilot/**`, `deploy/systemd/shopvivaliz-okx-*`, `scripts/*okx-trading-pilot*`, and the bridge test.** Add concurrency cancellation per ref.
- [ ] **Step 2: Run PAPER fault matrix:** stale market/book, AI timeout/malformed/missing layer, MCP outage, auth failure, exact/over risk limits, leverage >20, rho cluster breach, order timeout after acceptance, partial fill, protection failure, service restart, position divergence, São Paulo daily reset, daily stop, cumulative kill switch.
- [ ] **Step 3: Capture PAPER metrics:** expectancy, profit factor, drawdown, win rate, payoff, modeled vs realized-simulation slippage, instrument/confidence/regime breakdown, RiskGateway rejections, fees/funding.
- [ ] **Step 4: Generate readiness evidence only if every acceptance criterion has fresh evidence.** Missing evidence => `ready=false`.
- [ ] **Step 5: Run secret/governance scans and request independent review focused on numerical boundaries, fail-open paths, tool policy, idempotency, reconciliation, mode promotion, and secret leakage; fix all actionable findings and rerun impacted tests.**
- [ ] **Step 6: Open PR, require green checks/review, merge, then post-merge revalidate SHADOW/PAPER on the merged SHA.**
- [ ] **Step 7: Verify `shopvivaliz-okx-mcp-write.service` remains disabled/stopped and the live marker remains absent.**
- [ ] **Step 8: Commit final evidence:** `docs(okx): record trading pilot validation and readiness`.

## Execution Gate

Completing this plan does **not** mean enabling LIVE_PILOT. The implementation terminal state for this plan is: merged code + green CI + SHADOW/PAPER runtime evidence + `reports/okx-pilot-live-readiness.md` + write bridge still disabled.

A later explicit LIVE_PILOT enable action must verify the readiness evidence against the exact merged release SHA and then run `scripts/enable-okx-live-pilot.sh <release_sha>`. That activation is not implied by approval of this plan.
