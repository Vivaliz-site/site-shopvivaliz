# OKX Pilot - PAPER Validation

**Date:** 2026-10-05
**Mode:** PAPER / deterministic simulation

## Evidence

The automated suite covers the approved pilot controls and execution-safety invariants, including:

- US$10 maximum risk per operation;
- US$30 simultaneous total risk;
- US$20 correlated risk;
- US$15 daily stop;
- US$30 cumulative kill switch;
- maximum leverage 20x;
- minimum confidence 70/100;
- minimum expected R:R 1.5:1;
- stale-data rejection;
- dynamic instrument eligibility;
- 20-layer decision contract;
- order-intent idempotency;
- ambiguous-submit reconciliation;
- protection failure blocking new entries;
- fees, slippage and funding in paper PnL;
- exchange/local reconciliation;
- no write path in SHADOW.

Target-host verification: **41 Python tests PASS + 3 Node bridge tests PASS**.

## Result

**PAPER_FOUNDATION_PASS** for the deterministic controls exercised by the test suite.

This is engineering validation, not evidence of a profitable trading edge. No claim of expected returns, sustained paper performance, or live profitability is made.
