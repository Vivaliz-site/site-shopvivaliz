# Mercado Livre Pricing API V1 — Plan Self-Review

## Spec coverage

- Architecture/runtime isolation: Foundation + Master.
- Tenant/account isolation and API auth: Foundation.
- Secure ML OAuth/refresh encryption/locking: Foundation + Master.
- Current listing bulk/User Product/variation model: Foundation.
- Current price/pricing-automation model: Foundation + Master.
- Official fee/logistics and shipping estimates: Foundation.
- Seller cost/tax effective dating: Foundation.
- Promotions, seller/Meli funding, boosted fee reduction: Promotions.
- Durable webhook processing and missed-feed repair: Orders + Master.
- Orders, discount attribution, actual sale fee, actual shipment cost: Orders.
- Unique-shipment deduplication and allocation: Orders.
- ESTIMATED/ACTUAL/RECONCILED formulas and immutability: all four plans.
- Billing reconciliation: Reconciliation.
- Olist/Tiny seller-cost synchronization: Reconciliation + Master.
- Reports/OpenAPI/observability/security/privacy: Foundation + Reconciliation.
- Rate limiting/backoff/freshness: Foundation + Master.
- Mercado Turbo clean-room parity: Promotions + Reconciliation.
- Immutable deployment and legacy shadow comparison: Reconciliation + Master.
- V1 acceptance/non-goals/no ML mutation: Master + Reconciliation.

## Placeholder scan

No implementation task intentionally contains `TODO`, `TBD`, `implement later`, or an unresolved marketplace-write step. Child-plan ambiguities discovered during review are explicitly superseded by `2026-09-06-ml-pricing-api-implementation-master.md`.

## Type/interface consistency

Shared types omitted from an early child-plan file tree were made normative in the master plan: `MarginCalculation`, `MlAccountRepository`, `ProviderCostRecord`, `AuditRecord`, and test fixture support. All pricing-engine variants return the same `MarginCalculation` type. `CostProvider` feeds `CostCatalog`; it never feeds `PricingEngine` directly.

## Corrections made during review

- Removed unused `PromotionOffer` abstraction from V1.
- Corrected repository bootstrap order.
- Added `brick/math` for decimal arithmetic.
- Added DB-backed locking for single-use refresh-token races.
- Added per-account/per-endpoint rate-limit budgets.
- Chose systemd timers as the single V1 scheduler.
- Added item/price/shipment webhook topic dispatch, not only orders.
- Added exact Olist/Tiny cost precedence and read-only V2 fallback boundary.
- Added read-only legacy shadow comparison without expanding V1 into cutover.
- Preserved seller-discount and shipment-cost double-count protections from the approved spec.

## Conclusion

The plan set covers the approved V1 specification and is ready for implementation handoff. Execution must still use TDD, milestone PR review, live read-only evidence, no-secret scans, and post-merge verification before any milestone is called complete.
