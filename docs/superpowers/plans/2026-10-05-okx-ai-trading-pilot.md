# OKX AI Trading Pilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the approved staged OKX subsystem that scans dynamic opportunities, produces 20-layer structured AI decisions, enforces deterministic risk limits, reconciles exchange state, and can progress through SHADOW and PAPER before an explicitly enabled LIVE_PILOT.

**Architecture:** Add an isolated `services/okx-trading-pilot` Python service to the existing ShopVivaliz repository. Keep exchange I/O behind an OKX adapter, AI output behind a strict decision contract, and all permission to create exposure behind a pure deterministic Risk Gateway; persistence/audit and reconciliation surround the execution state machine so restart and ambiguous order outcomes fail closed.

**Tech Stack:** Python 3.12+, stdlib `decimal`/dataclasses/enums/zoneinfo, pytest, SQLite for durable pilot state/audit, official pinned `@okx_ai/okx-trade-mcp` upstream process, systemd/runtime configuration on `always-free-arm-1787907847-26`.

**Spec:** `docs/superpowers/specs/2026-10-05-okx-private-plugin-design.md`

## Global Constraints

- Canonical repository is `Vivaliz-site/site-shopvivaliz`; backend runtime is `always-free-arm-1787907847-26`.
- Operating modes are exactly `SHADOW -> PAPER -> LIVE_PILOT`; no autonomous promotion to `LIVE_PILOT`.
- Pilot reference capital: US$100.
- Maximum risk per operation: US$10.
- Maximum simultaneous total risk: US$30.
- Maximum correlated risk per direction/cluster: US$20.
- Daily loss stop: US$15; it blocks new entries only.
- Daily reset: 00:00 `America/Sao_Paulo`.
- Cumulative pilot kill switch: US$30; it blocks new entries and does not by itself liquidate existing positions.
- Maximum leverage: 20x.
- Minimum confidence: 70/100.
- Minimum expected R:R: 1.5:1.
- No fixed count limit on simultaneous positions; risk budgets are authoritative.
- Withdrawal/transfer permission is prohibited.
- OKX is authoritative for balances, orders, fills and positions.
- Missing/stale/ambiguous critical state fails closed for new exposure.
- The Decision Engine cannot raise, disable, or bypass deterministic risk limits.
- Existing positions remain defensively manageable while new entries are blocked.
- Secrets stay outside Git, prompts and normal logs.
- Pin a reviewed `@okx_ai/okx-trade-mcp` version; never depend on floating `latest`.
- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or an active immutable release directly.
- Use ShopVivaliz Remote Control as primary runtime path; versioned changes follow branch -> commit -> PR -> checks/review -> merge -> post-merge validation.

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
│   ├── scanner.py
│   ├── decision_contract.py
│   ├── risk.py
│   ├── correlation.py
│   ├── state_machine.py
│   ├── audit.py
│   ├── reconciliation.py
│   ├── execution.py
│   ├── paper.py
│   ├── orchestrator.py
│   ├── health.py
│   └── adapters/
│       └── okx_mcp.py
├── scripts/
│   ├── run_shadow.py
│   ├── run_paper.py
│   └── validate_runtime.py
└── tests/
    ├── test_config.py
    ├── test_scanner.py
    ├── test_decision_contract.py
    ├── test_risk.py
    ├── test_correlation.py
    ├── test_state_machine.py
    ├── test_audit.py
    ├── test_reconciliation.py
    ├── test_execution.py
    ├── test_paper.py
    ├── test_orchestrator.py
    └── test_health.py

scripts/install/
└── okx-trading-pilot.sh

systemd/
├── shopvivaliz-okx-mcp.service
└── shopvivaliz-okx-pilot.service
```

Each file has one responsibility: `domain.py` owns shared immutable types; `scanner.py` eligibility; `decision_contract.py` AI schema validation; `risk.py` numerical gates; `correlation.py` deterministic exposure clusters; `state_machine.py` lifecycle transitions; `audit.py` durable evidence; `reconciliation.py` exchange truth recovery; `execution.py` idempotent order/protection lifecycle; `paper.py` realistic simulation; `orchestrator.py` mode-aware composition; `okx_mcp.py` is the only OKX MCP boundary.

## Review Focus

- **Gap through stop price:** risk accounting must use a conservative executable-loss estimate and never assume a perfect stop fill; pin this in Task 5.
- **Clock/DST/day-boundary behavior:** daily stop reset must use `ZoneInfo("America/Sao_Paulo")`, not host-local time; pin this in Task 5.
- **Ambiguous submit timeout:** a retry must reconcile by `order_intent_id`/exchange identifiers before any resend; pin this in Task 8.
- **Partial fill followed by protection failure:** new entries must block while defensive close/reduction remains allowed; pin this in Task 8.
- **Restart with local state behind OKX:** exchange positions/orders win and risk budgets are reconstructed before new exposure; pin this in Task 7.
- **CI false-green:** the repository has pytest tests that historically were not guaranteed to run in GitHub Actions; pin `services/okx-trading-pilot/tests/**` to a path-scoped CI job before merge.

---

### Task 1: Bootstrap the isolated pilot service and immutable domain types

**Files:**
- Create: `services/okx-trading-pilot/pyproject.toml`
- Create: `services/okx-trading-pilot/okx_pilot/__init__.py`
- Create: `services/okx-trading-pilot/okx_pilot/domain.py`
- Test: `services/okx-trading-pilot/tests/test_config.py`

**Interfaces:**
- Consumes: none
- Produces: `Mode`, `DecisionKind`, `InstrumentType`, `Direction`, `Money`/Decimal conventions, timestamped `MarketSnapshot`, `PositionSnapshot`, and `PilotState` types used by every later task.

- [ ] **Step 1: Write failing domain tests**

Create tests asserting `Mode` contains exactly `SHADOW`, `PAPER`, `LIVE_PILOT`; money/risk fields reject binary floats and use `Decimal`; timestamps must be timezone-aware.

- [ ] **Step 2: Run RED**

Run: `cd services/okx-trading-pilot && python -m pytest tests/test_config.py -q`  
Expected: FAIL because `okx_pilot.domain` does not exist.

- [ ] **Step 3: Implement minimal immutable domain types**

Define frozen dataclasses/enums in `domain.py`; expose no exchange or AI behavior here.

- [ ] **Step 4: Run GREEN**

Run: `python -m pytest tests/test_config.py -q`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/okx-trading-pilot
git commit -m "feat(okx): add pilot domain foundation"
```

### Task 2: Configuration, secret isolation, mode gates, and package pin

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/config.py`
- Create: `services/okx-trading-pilot/config/pilot.example.toml`
- Create: `systemd/shopvivaliz-okx-mcp.service`
- Create: `systemd/shopvivaliz-okx-pilot.service`
- Modify/Test: `services/okx-trading-pilot/tests/test_config.py`

**Interfaces:**
- Consumes: `Mode` from Task 1.
- Produces: `PilotConfig.load(path: Path) -> PilotConfig`, `PilotLimits`, `OkxRuntimeConfig`, and `can_create_exchange_exposure(mode: Mode, explicit_live_enable: bool) -> bool`.

- [ ] **Step 1: Write failing configuration tests**

Assert exact defaults/required limits: `100, 10, 30, 20, 15, 30, 20, 70, 1.5`; timezone exactly `America/Sao_Paulo`; no withdrawal flag exists; `LIVE_PILOT` cannot create exposure unless an explicit protected runtime flag is true; example config contains secret *paths/names* only, never values.

- [ ] **Step 2: Add package-pin contract test**

Assert the systemd MCP command references an exact reviewed `@okx_ai/okx-trade-mcp@<version>`, not `@latest`, and SHADOW startup includes upstream read-only operation where supported.

- [ ] **Step 3: Run RED**

Run: `python -m pytest tests/test_config.py -q`  
Expected: FAIL on missing config implementation/service definitions.

- [ ] **Step 4: Implement configuration and unit templates**

Load limits as `Decimal`; make mode explicit; reference protected EnvironmentFile/config paths outside Git; sanitize health-visible config; do not embed credentials.

- [ ] **Step 5: Run GREEN and secret-pattern scan**

Run: `python -m pytest tests/test_config.py -q && git grep -nEi '(api[_-]?key|secret[_-]?key|passphrase)[[:space:]]*[:=][[:space:]]*[^$<{ ]{6,}' -- services/okx-trading-pilot systemd || true`  
Expected: tests PASS; no literal credential assignment.

- [ ] **Step 6: Commit**

```bash
git add services/okx-trading-pilot systemd
git commit -m "feat(okx): enforce pilot configuration and mode gates"
```

### Task 3: OKX MCP adapter and fresh exchange snapshots

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/adapters/okx_mcp.py`
- Create: `services/okx-trading-pilot/scripts/validate_runtime.py`
- Test: `services/okx-trading-pilot/tests/test_reconciliation.py`

**Interfaces:**
- Consumes: domain types and `OkxRuntimeConfig`.
- Produces: `OkxGateway.market_snapshot(instrument_id) -> MarketSnapshot`, `account_snapshot() -> AccountSnapshot`, `open_orders() -> tuple[OrderSnapshot, ...]`, `positions() -> tuple[PositionSnapshot, ...]`, `find_order(order_intent_id) -> OrderSnapshot | None`.

- [ ] **Step 1: Write contract tests against a fake MCP transport**

Assert adapter normalization of ticker/book/instrument metadata/account/positions/orders/fills; malformed or missing critical fields raise `ExchangeStateUnavailable` rather than guessing.

- [ ] **Step 2: Run RED**

Run: `python -m pytest tests/test_reconciliation.py -q`  
Expected: FAIL because adapter is absent.

- [ ] **Step 3: Implement transport-neutral adapter**

Keep the actual MCP invocation behind an injected transport so unit tests do not need credentials/network. Convert upstream numeric strings to `Decimal`; attach source timestamps.

- [ ] **Step 4: Add sanitized runtime validator**

`validate_runtime.py` reports package/version, authenticated boolean, module availability, freshness, and sanitized error class only.

- [ ] **Step 5: Run GREEN**

Run: `python -m pytest tests/test_reconciliation.py -q`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add services/okx-trading-pilot
git commit -m "feat(okx): add normalized MCP exchange adapter"
```

### Task 4: Market Scanner and deterministic instrument eligibility

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/scanner.py`
- Test: `services/okx-trading-pilot/tests/test_scanner.py`

**Interfaces:**
- Consumes: `MarketSnapshot`, instrument metadata from Task 3, scanner thresholds from `PilotConfig`.
- Produces: `Scanner.evaluate(snapshot) -> EligibilityResult` and `Scanner.rank(snapshots) -> tuple[Candidate, ...]`.

- [ ] **Step 1: Write failing eligibility tests**

Cover stale data, excessive spread, inadequate depth, excessive estimated slippage, inactive instrument, invalid lot/tick metadata, missing option maximum-loss inputs, and a valid liquid candidate.

- [ ] **Step 2: Run RED**

Run: `python -m pytest tests/test_scanner.py -q`  
Expected: FAIL.

- [ ] **Step 3: Implement scanner**

Reject before ranking whenever required risk data is unavailable. Keep thresholds explicit in config; ranking never overrides eligibility.

- [ ] **Step 4: Run GREEN**

Run: `python -m pytest tests/test_scanner.py -q`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/scanner.py services/okx-trading-pilot/tests/test_scanner.py
git commit -m "feat(okx): add deterministic market scanner"
```

### Task 5: Structured 20-layer Decision Contract and deterministic Risk Gateway

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/decision_contract.py`
- Create: `services/okx-trading-pilot/okx_pilot/risk.py`
- Create: `services/okx-trading-pilot/okx_pilot/correlation.py`
- Test: `services/okx-trading-pilot/tests/test_decision_contract.py`
- Test: `services/okx-trading-pilot/tests/test_risk.py`
- Test: `services/okx-trading-pilot/tests/test_correlation.py`

**Interfaces:**
- Consumes: eligible candidate, account/position snapshots, `PilotLimits`.
- Produces: `DecisionIntent.from_payload(payload) -> DecisionIntent`, `CorrelationClassifier.classify(...) -> ClusterId`, `RiskGateway.authorize(intent, market, account, pilot_state) -> RiskVerdict`.

- [ ] **Step 1: Write failing Decision Contract tests**

Require all 20 analysis layer results plus decision, instrument/type, direction, entry, stop, targets, risk, leverage, horizon, confidence, R:R, invalidation, supporting/contrary evidence, snapshot timestamp and expiry. Reject confidence outside 0..100, missing contradictory review, expired intent, and malformed Decimal values.

- [ ] **Step 2: Write boundary-first Risk Gateway tests**

For every hard limit test immediately below/at/above: US$10 per trade, US$30 open risk, US$20 correlated risk, US$15 daily loss, US$30 cumulative loss, 20x leverage, 70 confidence, 1.5 R:R. Assert daily stop blocks new entries but leaves `defensive_management_allowed=True`.

- [ ] **Step 3: Add Review Focus tests**

Test a stop-price gap where conservative executable loss exceeds US$10 and must reject; test daily reset immediately before/at/after midnight using `ZoneInfo("America/Sao_Paulo")`; test missing marked unrealized PnL blocks authorization rather than treating it as zero.

- [ ] **Step 4: Write deterministic correlation tests**

Assert same-underlying/same-direction and configured factor clusters aggregate risk; model-provided labels alone cannot evade the US$20 cluster cap.

- [ ] **Step 5: Run RED**

Run: `python -m pytest tests/test_decision_contract.py tests/test_risk.py tests/test_correlation.py -q`  
Expected: FAIL.

- [ ] **Step 6: Implement minimal contracts and pure gateway**

Use `Decimal` arithmetic only. Return explicit rejection codes for each gate. Do not call the exchange or model from `risk.py`.

- [ ] **Step 7: Run GREEN**

Run: `python -m pytest tests/test_decision_contract.py tests/test_risk.py tests/test_correlation.py -q`  
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/{decision_contract.py,risk.py,correlation.py} services/okx-trading-pilot/tests
git commit -m "feat(okx): enforce deterministic trading risk gateway"
```

### Task 6: Auditable lifecycle and durable pilot state

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/state_machine.py`
- Create: `services/okx-trading-pilot/okx_pilot/audit.py`
- Test: `services/okx-trading-pilot/tests/test_state_machine.py`
- Test: `services/okx-trading-pilot/tests/test_audit.py`

**Interfaces:**
- Consumes: `DecisionIntent`, `RiskVerdict`, order/position snapshots.
- Produces: `TradeStateMachine.transition(id, event) -> TradeRecord`, `AuditStore.append(event) -> None`, `AuditStore.load_pilot_state() -> PilotState`.

- [ ] **Step 1: Write failing state transition tests**

Pin legal lifecycle `DISCOVERED -> ANALYZING -> CANDIDATE -> RISK_CHECK -> APPROVED/REJECTED -> ORDER_SENT -> PARTIAL/FILLED -> PROTECTED -> EXITED -> RECONCILED`; reject illegal skips and duplicate terminal transitions.

- [ ] **Step 2: Write failing persistence/audit tests**

Assert restart preserves mode, pilot baseline, daily baseline/date, open-risk state, decision/order identifiers and immutable event history; sanitized serialization excludes configured secret values.

- [ ] **Step 3: Run RED**

Run: `python -m pytest tests/test_state_machine.py tests/test_audit.py -q`  
Expected: FAIL.

- [ ] **Step 4: Implement SQLite append-only audit and projections**

Use transactions; reconstruct mutable projections from durable rows. Never treat local projection as exchange truth.

- [ ] **Step 5: Run GREEN**

Run: `python -m pytest tests/test_state_machine.py tests/test_audit.py -q`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/{state_machine.py,audit.py} services/okx-trading-pilot/tests
git commit -m "feat(okx): persist auditable pilot lifecycle"
```

### Task 7: Exchange reconciliation and restart recovery

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/reconciliation.py`
- Modify: `services/okx-trading-pilot/tests/test_reconciliation.py`

**Interfaces:**
- Consumes: `OkxGateway`, `AuditStore`, current local projection.
- Produces: `Reconciler.reconcile() -> ReconciliationResult` with rebuilt balances/orders/positions/risk inputs and `new_exposure_allowed`.

- [ ] **Step 1: Write failing reconciliation tests**

Cover local missing position, locally-open/exchange-closed position, exchange-open/local-closed position, unknown order, changed fill quantity, and restart with stale local risk.

- [ ] **Step 2: Add Review Focus restart test**

Persist a local state that is behind OKX, restart the service, reconcile, assert exchange state wins and `new_exposure_allowed=False` until risk budgets are rebuilt from reconciled positions/orders.

- [ ] **Step 3: Run RED**

Run: `python -m pytest tests/test_reconciliation.py -q`  
Expected: FAIL.

- [ ] **Step 4: Implement reconciliation**

Treat OKX snapshots as authoritative; emit auditable correction events; only unlock new exposure after a complete consistent snapshot.

- [ ] **Step 5: Run GREEN**

Run: `python -m pytest tests/test_reconciliation.py -q`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/reconciliation.py services/okx-trading-pilot/tests/test_reconciliation.py
git commit -m "feat(okx): reconcile pilot state with exchange truth"
```

### Task 8: Idempotent execution, partial fills, and mandatory protection

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/execution.py`
- Test: `services/okx-trading-pilot/tests/test_execution.py`

**Interfaces:**
- Consumes: approved `RiskVerdict`, `DecisionIntent`, `OkxGateway`, state/audit services.
- Produces: `ExecutionManager.execute(approved) -> ExecutionResult`, `manage_existing_positions() -> ManagementResult`.

- [ ] **Step 1: Write failing happy-path execution test**

Assert a Risk Gateway rejection can never reach `place_order`; approved intent receives unique `order_intent_id`; fill quantity drives final risk/protection sizing.

- [ ] **Step 2: Add ambiguous-timeout idempotency test**

Simulate submit timeout after exchange acceptance. On retry, assert `find_order(order_intent_id)` is called and a second order is not sent.

- [ ] **Step 3: Add partial-fill/protection-failure test**

Simulate partial fill then stop/protection failure. Assert state becomes critical, all new entries block, and defensive reduction/close remains permitted.

- [ ] **Step 4: Add mode tests**

SHADOW never calls exchange writes; PAPER routes to simulator only; LIVE_PILOT writes only when explicit live-enable and a current Risk Gateway approval are both present.

- [ ] **Step 5: Run RED**

Run: `python -m pytest tests/test_execution.py -q`  
Expected: FAIL.

- [ ] **Step 6: Implement execution manager**

Reconcile uncertain outcomes before resend. Protection is part of successful entry lifecycle, not an optional follow-up.

- [ ] **Step 7: Run GREEN**

Run: `python -m pytest tests/test_execution.py -q`  
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/execution.py services/okx-trading-pilot/tests/test_execution.py
git commit -m "feat(okx): add idempotent protected execution lifecycle"
```

### Task 9: PAPER simulator with realistic costs and failure injection

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/paper.py`
- Test: `services/okx-trading-pilot/tests/test_paper.py`

**Interfaces:**
- Consumes: approved intents and timestamped market snapshots.
- Produces: `PaperBroker.submit(intent, market) -> SimulatedExecution`, deterministic seeded failure/slippage scenarios, reconciled paper PnL.

- [ ] **Step 1: Write failing simulation tests**

Assert spread, fee, funding, slippage, partial fill, rejection, timeout, and gap-through-stop alter PnL/risk rather than assuming ideal fills.

- [ ] **Step 2: Run RED**

Run: `python -m pytest tests/test_paper.py -q`  
Expected: FAIL.

- [ ] **Step 3: Implement deterministic simulator**

Make scenarios reproducible from explicit inputs/seed; use the same state/risk contracts as live execution.

- [ ] **Step 4: Run GREEN**

Run: `python -m pytest tests/test_paper.py -q`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/okx-trading-pilot/okx_pilot/paper.py services/okx-trading-pilot/tests/test_paper.py
git commit -m "feat(okx): add realistic paper execution simulator"
```

### Task 10: Compose scanner, 20-layer model call, risk, execution, and health

**Files:**
- Create: `services/okx-trading-pilot/okx_pilot/orchestrator.py`
- Create: `services/okx-trading-pilot/okx_pilot/health.py`
- Create: `services/okx-trading-pilot/scripts/run_shadow.py`
- Create: `services/okx-trading-pilot/scripts/run_paper.py`
- Test: `services/okx-trading-pilot/tests/test_orchestrator.py`
- Test: `services/okx-trading-pilot/tests/test_health.py`

**Interfaces:**
- Consumes: scanner, injected `DecisionProvider.analyze(candidate, context) -> Mapping`, Decision Contract, Risk Gateway, executor/paper broker, reconciler/audit.
- Produces: `PilotOrchestrator.run_cycle() -> CycleResult` and sanitized `HealthSnapshot`.

- [ ] **Step 1: Write failing end-to-end cycle tests with fake DecisionProvider**

Assert scanner rejection skips AI; malformed AI output rejects; valid 20-layer TRADE reaches Risk Gateway; rejected risk never executes; HOLD/REJECT never executes; stale decision is discarded; mode routing is exact.

- [ ] **Step 2: Write health tests**

Health exposes mode, package/version, authenticated boolean, data freshness, component states, reconciliation timestamp, open risk, daily/kill-switch state, sanitized error class; never raw account/secret material.

- [ ] **Step 3: Run RED**

Run: `python -m pytest tests/test_orchestrator.py tests/test_health.py -q`  
Expected: FAIL.

- [ ] **Step 4: Implement orchestrator and DecisionProvider boundary**

Keep model-provider specifics injected. Require the exact Decision Contract after the 20-layer prompt/analysis; the model never calls the exchange directly.

- [ ] **Step 5: Run GREEN and full suite**

Run: `python -m pytest -q`  
Expected: all pilot tests PASS.

- [ ] **Step 6: Commit**

```bash
git add services/okx-trading-pilot
git commit -m "feat(okx): compose staged decision and risk pipeline"
```

### Task 11: Runtime installer, SHADOW validation, and fault-injection gate

**Files:**
- Create: `scripts/install/okx-trading-pilot.sh`
- Modify: `services/okx-trading-pilot/README.md`
- Modify: `services/okx-trading-pilot/scripts/validate_runtime.py`

**Interfaces:**
- Consumes: completed service, systemd templates, protected runtime secret/config source.
- Produces: reproducible install/upgrade procedure, sanitized runtime validation report, SHADOW readiness evidence.

- [ ] **Step 1: Write installer validation tests/checks before installation**

Add a dry-run path that verifies immutable release paths, required package pin, protected config path, service user, file permissions, and refuses direct edits to `current/`.

- [ ] **Step 2: Run repository test suite**

Run: `cd services/okx-trading-pilot && python -m pytest -q`  
Expected: PASS.

- [ ] **Step 3: Install only through an immutable release on the backend VM**

Use ShopVivaliz Remote Control; first verify fresh `hostname`, identity, working directory and Git/release state. Do not print secret values.

- [ ] **Step 4: Validate SHADOW against real OKX reads**

Prove fresh market/account/position/order reads, scanner operation, Decision Contract validation, Risk Gateway verdicts, audit persistence, and **zero exchange writes**.

- [ ] **Step 5: Execute fault-injection matrix**

Exercise stale data, MCP outage, auth failure, ambiguous order fixture, position divergence fixture, missing protection, every risk-limit breach, restart recovery, daily stop, and cumulative kill switch. Expected: new exposure always fails closed while defensive management remains available where required.

- [ ] **Step 6: Commit runtime/documentation changes**

```bash
git add scripts/install/okx-trading-pilot.sh services/okx-trading-pilot/README.md services/okx-trading-pilot/scripts/validate_runtime.py
git commit -m "ops(okx): add safe pilot runtime installation and validation"
```

### Task 12: CI gate, PAPER evidence, independent review, merge, and LIVE_PILOT readiness gate

**Files:**
- Modify: `.github/workflows/shopvivaliz-qa.yml`
- Create: `reports/okx-pilot-shadow-validation.md`
- Create: `reports/okx-pilot-paper-validation.md`
- Create: `reports/okx-pilot-live-readiness.md`

**Interfaces:**
- Consumes: SHADOW evidence, PAPER runs, repository/runtime tests.
- Produces: reviewable readiness evidence; no implicit live enablement.

- [ ] **Step 1: Add and prove a path-scoped CI gate**

Make changes under `services/okx-trading-pilot/**`, `systemd/shopvivaliz-okx-*.service`, or `scripts/install/okx-trading-pilot.sh` run the pilot pytest suite in `.github/workflows/shopvivaliz-qa.yml`. Validate the workflow definition and prove the job actually executes; a test file existing without CI execution is not evidence.

- [ ] **Step 2: Run PAPER validation long enough to exercise all deterministic gates**

Capture expectancy, profit factor, drawdown, win rate, payoff, predicted/realized slippage, results by instrument/confidence/regime, gateway rejection reasons, fees and funding.

- [ ] **Step 3: Verify the full acceptance matrix**

Map each of the spec's 14 acceptance criteria and all approved financial limits to fresh test/runtime evidence. Any missing evidence is FAIL, not “assumed”.

- [ ] **Step 4: Run secret/governance checks**

Run relevant repository governance plus secret scans. Expected: PASS and no OKX secret values in Git/log evidence.

- [ ] **Step 5: Request independent code/reliability review**

Reviewer focuses on numerical boundary errors, fail-open paths, idempotency, restart reconciliation, protection failure, mode promotion, and secret leakage. Correct all actionable findings and rerun affected tests.

- [ ] **Step 6: Open PR and require checks/review**

The PR must contain code, tests, spec/plan references and SHADOW/PAPER evidence. Do not enable LIVE_PILOT as part of merge.

- [ ] **Step 7: Merge only after green checks and review, then post-merge validate SHADOW/PAPER runtime**

Expected: deployed merged commit matches repository; fresh runtime validation PASS.

- [ ] **Step 8: Produce LIVE_PILOT readiness report**

Report exact pinned MCP version, all hard limits, credential permission check (trade/read only, no withdrawal), current mode, acceptance evidence and remaining risks. `LIVE_PILOT` remains disabled until a separate explicit enable action after this readiness evidence is reviewed.

- [ ] **Step 9: Commit evidence**

```bash
git add reports/okx-pilot-*.md
git commit -m "docs(okx): record pilot validation and live readiness"
```
