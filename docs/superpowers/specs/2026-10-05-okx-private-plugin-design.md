# OKX AI Trading Pilot — Architecture Design

**Date:** 2026-10-05  
**Repository:** `Vivaliz-site/site-shopvivaliz`  
**Target runtime:** `always-free-arm-1787907847-26`  
**Status:** Conversational design approved; written spec awaiting user review before implementation planning.

## 1. Goal

Build a private ShopVivaliz OKX trading subsystem that continuously discovers market opportunities, performs a structured 20-layer AI analysis, applies an independent deterministic risk gateway, and manages the resulting order/position lifecycle through the OKX integration.

The design prioritizes fast decisions without allowing the language model to bypass capital, exposure, loss, leverage, liquidity, or safety constraints.

The system has three explicit operating modes:

`SHADOW -> PAPER -> LIVE_PILOT`

Promotion between modes is explicit. The system must never promote itself from PAPER to LIVE_PILOT.

## 2. Selected architecture

The approved architecture is:

`OKX -> Market Scanner -> AI Decision Engine -> Deterministic Risk Gateway -> Execution & Position Manager -> OKX`

with reconciliation and outcome data feeding back into monitoring and evaluation.

This architecture was selected over:
1. direct LLM-to-exchange execution, because prompt behavior must not be the enforcement mechanism for financial limits; and
2. a rules-only strategy engine, because the intended system should adapt its market judgment dynamically rather than use the LLM only for explanation.

The Risk Gateway, not the Decision Engine, is authoritative for whether a candidate may create new exposure.

## 3. Components

### 3.1 OKX integration layer

Use the current official OKX Agent Trade Kit / MCP capabilities as the upstream exchange integration where they support the required market, account, order, position, and history operations.

Responsibilities:
- authenticated OKX market/account access;
- instrument metadata;
- ticker and market data required by the scanner;
- balances, margin, positions, orders, fills, bills, fees and funding where supported;
- order lifecycle operations required by an approved operating mode;
- reconciliation against the exchange.

Credentials remain outside Git and outside prompts. Withdrawal permission is prohibited.

### 3.2 Market Scanner

The scanner reduces the exchange universe to candidates worth deep analysis.

It evaluates, as applicable:
- instrument status and metadata;
- liquidity;
- bid/ask spread;
- order-book depth;
- recent volume;
- volatility;
- expected slippage;
- market-data freshness;
- availability of the data required to manage the instrument safely.

The universe is dynamic rather than a fixed BTC/ETH/SOL whitelist. Instrument eligibility is nevertheless deterministic: an instrument with insufficient liquidity, excessive spread/slippage, stale data, or inadequate risk information is rejected before deep analysis.

The design may consider spot, perpetual swaps, futures, and options when the integration exposes adequate data and the Risk Gateway has an instrument-appropriate risk model.

### 3.3 AI Decision Engine

The Decision Engine receives only candidates that pass the scanner and performs the approved 20-layer analysis:

1. market regime;
2. multi-timeframe alignment;
3. price structure;
4. momentum;
5. volume;
6. order-book structure;
7. trades/microstructure;
8. spread and expected slippage;
9. volatility;
10. derivatives context/open interest where available;
11. funding and basis;
12. liquidation/leverage context where reliable data is available;
13. correlation with existing exposure;
14. current account context;
15. payoff asymmetry;
16. probability times payoff / expected value;
17. instrument selection;
18. timing and data staleness;
19. adverse scenario and thesis invalidation;
20. adversarial final review that actively tries to refute the trade.

The output is a structured decision, not a raw exchange command.

Required semantic fields include:
- decision: `TRADE | HOLD | REJECT`;
- instrument and instrument class;
- direction;
- proposed entry or entry logic;
- stop;
- target(s);
- proposed size/risk;
- proposed leverage when relevant;
- horizon;
- confidence score;
- expected risk/reward;
- thesis;
- evidence for;
- evidence against;
- invalidation condition;
- source-data timestamps.

Confidence is not a vote count across correlated indicators. Multiple indicators derived from the same underlying information must not be treated as independent confirmations.

### 3.4 Deterministic Risk Gateway

The gateway independently recomputes all enforceable constraints from current account and market state immediately before authorization.

The Decision Engine cannot override, disable, or raise gateway limits.

For the approved pilot:

| Control | Limit |
|---|---:|
| Pilot reference capital | US$100 |
| Maximum risk per operation | US$10 |
| Maximum simultaneous total risk | US$30 |
| Maximum correlated risk per direction/cluster | US$20 |
| Daily loss stop | US$15 |
| Cumulative pilot kill switch | US$30 |
| Maximum leverage | 20x |
| Minimum confidence | 70/100 |
| Minimum expected R:R | 1.5:1 |
| Fixed maximum number of positions | None; constrained by risk budgets |
| Daily reset | 00:00 America/Sao_Paulo |

US$10 is a maximum risk per operation, not a target. Likewise, 20x is a ceiling rather than a default. Actual position size and leverage are derived from stop distance, volatility, liquidity, slippage, margin, existing exposure, and remaining risk budget.

When the US$15 daily loss stop is reached:
- new entries are blocked for the remainder of that daily cycle;
- existing positions are not closed solely because the daily stop was reached;
- existing positions continue under their own protective stop/target/risk rules.

The cumulative US$30 pilot kill switch blocks new exposure and requires review before the pilot may resume.

Daily-loss and pilot-loss accounting must include the economic effects relevant to the position lifecycle, including realized PnL and applicable fees/funding. Unrealized exposure remains part of live risk accounting and cannot be ignored when deciding whether new exposure fits the simultaneous-risk budgets.

### 3.5 Execution & Position Manager

Only an authorization produced by the Risk Gateway can reach the executor.

Responsibilities:
- translate approved intents into supported OKX order operations;
- attach or establish protective risk controls required by the approved strategy;
- track acknowledgement, partial fills, fills, cancellation and rejection;
- recalculate exposure after actual fills;
- monitor stops, targets, margin, fees, funding and position state;
- reconcile local state against OKX;
- record why the position was opened, changed and exited.

Every decision and execution intent has unique identifiers such as `decision_id` and `order_intent_id`. Retry behavior must be idempotent: an uncertain order result is reconciled against OKX before another order can be sent.

For balances, positions, fills and orders, OKX is the source of truth.

## 4. State machine and lifecycle

The auditable lifecycle is:

`DISCOVERED -> ANALYZING -> CANDIDATE -> RISK_CHECK -> APPROVED/REJECTED -> ORDER_SENT -> PARTIAL/FILLED -> PROTECTED -> EXITED -> RECONCILED`

Not every candidate reaches every state. Rejected candidates retain their rejection reason.

If local and exchange state disagree, new exposure is blocked until reconciliation succeeds. Defensive management of already-open positions remains separate from permission to create new exposure.

## 5. Fail-closed behavior

New entries are blocked when critical state cannot be trusted, including:
- stale or missing required market data;
- authentication failure;
- unknown order outcome;
- unresolved local/OKX position divergence;
- Risk Gateway unavailable;
- insufficient margin information;
- missing protection for an open position;
- inability to calculate aggregate or correlated risk;
- daily stop or cumulative kill switch reached.

Specific handling:
- stale market data: discard and re-evaluate the signal;
- uncertain order submission: query OKX before retrying;
- partial fill: recalculate actual exposure and protection from filled quantity;
- unconfirmed protective stop: mark the position critical and block new entries;
- Decision Engine unavailable: no new AI decisions; deterministic protection/reconciliation of existing positions continues;
- OKX/API/MCP unavailable: no new entries until state can be reconciled.

A service process being alive is not evidence that the trading subsystem is safe to operate.

## 6. Operating modes and validation gates

### 6.1 SHADOW

Use real market/account context for analysis without submitting orders.

Validate:
- candidate discovery;
- 20-layer analysis completeness;
- latency and staleness behavior;
- risk calculations;
- correlation classification;
- decision auditability.

### 6.2 PAPER

Execute the same decision/risk pipeline against simulated positions and fills.

Simulation must account for realistic:
- fees;
- funding where applicable;
- spread;
- slippage;
- partial fills where relevant;
- order rejection/timeout scenarios.

### 6.3 LIVE_PILOT

Only after the preceding gates pass may the system be configured for the approved US$100 pilot envelope.

Transition to LIVE_PILOT is an explicit configuration/authorization event; it cannot be initiated autonomously by the Decision Engine.

## 7. Metrics and evaluation

Record enough evidence to calculate at least:
- expectancy;
- profit factor;
- drawdown;
- win rate;
- average payoff;
- predicted versus realized slippage;
- results by asset/instrument;
- results by confidence band, including 70-79 versus 80+;
- results by market regime;
- Risk Gateway rejection counts and reasons.

These metrics are used to determine whether thresholds should later be tightened or changed. The system must not autonomously raise its own risk limits based on performance.

## 8. Security and governance

1. API key, secret and passphrase stay outside Git, prompts and normal logs.
2. Withdrawal permission is prohibited.
3. The Decision Engine does not receive raw exchange credentials.
4. Risk limits are configuration controlled and cannot be changed by model output.
5. Limit changes must be explicit, versioned/auditable and revalidated.
6. Logs must sanitize credentials, signatures, authorization material and other secrets.
7. Restart must restore/reconcile open positions, active orders, risk usage, daily-loss state and pilot-loss state before new exposure is permitted.
8. No component may infer that a position is closed solely from local state; reconciliation must confirm exchange state.
9. Operating mode must be visible and unambiguous in health/status output.

## 9. Testing strategy

ShopVivaliz-owned implementation follows TDD.

### Unit and property tests

Cover at minimum:
- each numerical risk boundary;
- confidence threshold;
- R:R threshold;
- leverage ceiling;
- daily reset semantics;
- aggregate-risk accounting;
- correlated-risk accounting;
- position sizing from stop distance;
- stale-data rejection;
- idempotency keys;
- secret sanitization;
- state-machine transition rules.

Boundary tests must include values immediately below, at, and above every financial limit.

### Integration tests

Cover:
- market/account reads;
- scanner eligibility;
- structured Decision Engine schema validation;
- gateway authorization/rejection;
- exchange order-state reconciliation;
- partial fills;
- rejected orders;
- timeouts and unknown outcomes;
- restart recovery.

### Failure injection

Explicitly test:
- API/MCP outage;
- stale data;
- partial fill;
- duplicate/retried intent;
- position mismatch;
- missing stop/protection;
- attempted risk-limit breach;
- attempted leverage breach;
- daily-stop behavior;
- cumulative kill-switch behavior.

The required invariant is fail-closed for new exposure.

## 10. Deployment constraints

- Canonical repository: `Vivaliz-site/site-shopvivaliz`.
- Canonical backend runtime: `always-free-arm-1787907847-26`.
- Use ShopVivaliz Remote Control as the primary operational path.
- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or an active immutable release directly.
- Repository work uses an isolated branch/worktree.
- Secrets and mutable runtime state live in protected persistent storage, not immutable release directories.
- Merge and post-merge runtime validation are separate gates.
- No secret may be printed merely to prove configuration.

## 11. Observability

Permitted status includes:
- operating mode;
- integration/service health;
- authenticated true/false;
- market-data freshness;
- scanner status;
- Decision Engine status;
- Risk Gateway status;
- reconciliation status;
- open-risk totals;
- daily-loss budget status;
- cumulative pilot-loss status;
- last successful reconciliation timestamp;
- sanitized error codes/classes.

Persistent logs must avoid raw secret material and unnecessary complete account dumps.

## 12. Scope boundaries

This design does not authorize:
- withdrawals or transfers out of the trading account;
- autonomous changes to risk limits;
- autonomous promotion to LIVE_PILOT;
- bypassing the Risk Gateway;
- treating an LLM prompt as a substitute for deterministic financial controls.

The first implementation plan must preserve the mode progression and validation gates rather than jumping directly to live execution.

## 13. Acceptance criteria

The subsystem is implementation-complete only when:
1. current official OKX integration capabilities used by the implementation are verified;
2. protected credential storage is provisioned or reused without secret disclosure;
3. authenticated market/account probes succeed;
4. Scanner, Decision Engine, Risk Gateway and Executor interfaces are independently testable;
5. all approved financial limits are enforced by deterministic tests;
6. stale/missing/ambiguous state blocks new exposure;
7. retry/restart behavior is idempotent and reconciles against OKX;
8. SHADOW validation passes;
9. PAPER validation passes with realistic trading costs and failure scenarios;
10. LIVE_PILOT remains an explicit separately enabled mode;
11. no withdrawal permission is present;
12. no secrets appear in repository content or reviewed logs;
13. relevant repository and runtime tests pass;
14. versioned changes follow branch -> commit -> PR -> checks/review -> merge -> post-merge validation.

## 14. Current design decisions

The following decisions are explicitly approved:
- architecture option 2: AI Decision Engine plus deterministic Risk Gateway;
- dynamic instrument selection rather than a fixed asset whitelist;
- 20-layer analytical core;
- US$100 pilot reference capital;
- US$10 maximum risk per operation;
- US$30 maximum simultaneous total risk;
- US$20 maximum correlated risk per direction/cluster;
- US$15 daily loss stop;
- daily stop blocks new entries only;
- daily reset at 00:00 America/Sao_Paulo;
- US$30 cumulative pilot kill switch;
- 20x maximum leverage;
- minimum confidence 70/100;
- minimum expected R:R 1.5:1;
- no fixed count limit on simultaneous positions, subject to risk budgets;
- explicit SHADOW -> PAPER -> LIVE_PILOT progression;
- no withdrawal permission.

## 15. References

Implementation must revalidate current official documentation before pinning package versions or relying on specific tool names.

Design references:
- OKX Agent Trade Kit API guide: https://www.okx.com/docs-v5/agent_en/
- OKX Agent Trade Kit product page: https://www.okx.com/en-br/agent-tradekit
- official package distribution referenced by the Agent Trade Kit documentation where applicable.

This spec supersedes the earlier read-only-only phase-1 design in this file. Read-only access remains useful for SHADOW validation, but the approved architecture now covers the full staged trading pilot and its deterministic controls.
