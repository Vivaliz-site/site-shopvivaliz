# ShopVivaliz Zero-Sales Audit Design

**Date:** 2026-09-06
**Status:** Approved for autonomous execution
**Scope:** P0/P1 causes that can explain zero verified sales despite paid traffic.

## Problem statement

ShopVivaliz receives real paid traffic, but production cannot currently prove a complete paid-order lifecycle from ad click through payment, ERP reconciliation and conversion reporting. Structural quality gates pass while operational evidence shows gaps in marketing measurement, async payment processing, transaction reconciliation and deploy observability.

## Evidence baseline

- Apache receives Google paid-media markers (`gclid`, `gbraid`, `wbraid`, CPC UTMs).
- Google Ads emails reported hundreds of clicks, while conversion measurement remained not started.
- Production lacks `GOOGLE_ADS_ID` and `GOOGLE_ADS_CONVERSION_LABEL`.
- Current Olist/Tiny, Mercado Pago and Melhor Envio probes return HTTP 200.
- A Mercado Pago webhook received on 2026-08-31 remained queued until 2026-09-03 because the queue worker was unavailable.
- The local `orders` table contains 40 records, 32 still awaiting payment and 7 marked approved, but most are test-like.
- Two non-test-like locally approved orders checked in Olist are not completed: one is Open (`situacao=0`), one Cancelled (`situacao=2`).

## Causal model

The primary failure is not a single broken checkout endpoint. It is a compound system failure:

1. **Acquisition optimization blindness:** Ads delivered traffic without a usable purchase conversion action/goal for long periods, so bidding could not learn from verified revenue.
2. **Transactional reliability gap:** payment confirmation depends on a background worker that was offline for days, delaying post-payment state, analytics and ERP dispatch.
3. **Reconciliation gap:** local approved status is not automatically reconciled against ERP final state, and authoritative gateway evidence is not retained in `orders.raw_json`.
4. **Attribution gap:** click IDs and UTMs are captured in browser storage but are not persisted with first-party funnel/order records.
5. **False-positive observability:** legacy scripts can report integrations as operational without real provider calls, while deploy status files are stale.

## Architecture

Use an evidence-first, fail-closed pipeline. A payment is revenue only after an authoritative provider-approved state is recorded. ERP state is reconciliation evidence, not the authority for payment, and discrepancies must create an explicit alert/state rather than silently overwriting history.

Paid-media attribution is captured only with consent, persisted alongside the checkout/order context, and attached to verified purchase events. Google Ads readiness must require either a verified manual Ads conversion action or a verified GA4-import conversion source; mere environment presence is insufficient.

## Subsystems and boundaries

### Payment and order truth

- Preserve provider transaction identifiers/status snapshots without storing secrets.
- Keep `payment_status` and operational reconciliation state distinct.
- Record every integration transition with timestamp, provider and sanitized outcome.
- Make webhook queue health observable: worker heartbeat, oldest queued age, failures and retries.
- Never declare an order paid from redirect query parameters or ERP state.

### Marketing measurement and attribution

- Persist consented `gclid`/`gbraid`/`wbraid`/UTM attribution from landing through order creation.
- Emit purchase only after server-confirmed provider approval.
- Fail Google Ads readiness when no verified conversion source is active.
- Keep GA4 and Ads transaction IDs identical to the canonical order number for deduplication.

### Operational evidence

- Replace hardcoded integration validators with provider-backed fail-closed probes.
- Repair production env formatting without exposing values and preserve a backup.
- Ensure deploy/worker evidence is fresh and tied to the active release SHA.
- Preserve immutable-release rules and avoid editing `current` directly.

## Safety and data rules

- Do not change price or stock without official Olist/Tiny evidence.
- Do not create real charges to prove payment flow; use provider read-only probes and safe non-financial E2E paths.
- Never log tokens, credentials, raw customer PII or payment payload secrets.
- Attribution storage requires analytics/ad consent and must be bounded to the commerce use case.
- Existing operational data is migrated idempotently; no destructive cleanup is part of this audit.

## Validation gates

A P0 fix is complete only when all applicable gates pass:

1. New regression test failed before the fix and passes after it.
2. `php scripts/quality/run-all.php` passes.
3. Provider-backed integration probes succeed for Olist/Tiny, Mercado Pago and Melhor Envio.
4. Queue worker is active and queue health reports no stale critical jobs.
5. Production functional audit returns `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
6. Repo SHA, merged main SHA, release SHA and public version endpoint reconcile.
7. Post-deploy logs contain no new critical checkout/payment errors.

## Out of scope for automatic mutation

External paid-campaign budget/bid increases and real financial transactions are not required to repair the technical sales path. Account configuration that can be changed safely and reversibly is in scope when authenticated access is available; otherwise the code must expose a precise fail-closed blocker rather than claim readiness.
