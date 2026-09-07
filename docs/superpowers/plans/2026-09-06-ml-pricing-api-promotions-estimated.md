# Mercado Livre Pricing API — Promotions & Promotion-Adjusted Estimates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add official Mercado Livre promotion synchronization, seller/ML funding attribution, 2026 boosted-offer fee-reduction semantics, promotion-aware `ESTIMATED` margin quotes, and promotion read APIs without any marketplace mutation.

**Architecture:** Promotion facts are normalized into immutable snapshots and joined to the already-delivered listing/price/cost/tax/fee/shipping foundation. The pure pricing engine receives explicit promotion economics; when official semantics are incomplete, it returns an explicit reconciliation-required completeness flag instead of guessing.

**Tech Stack:** Existing PHP 8.3+/Symfony 7.4 service, Doctrine/MySQL, Symfony HttpClient/Messenger, PHPUnit/OpenAPI.

**Spec:** `docs/superpowers/specs/2026-09-06-ml-pricing-api-design.md`

## Global Constraints

- Mercado Livre official APIs remain the source of truth.
- No endpoint may join, leave, modify, create, or delete a Mercado Livre promotion.
- Buyer-facing promotion price is revenue for `ESTIMATED`; ML-funded boost is modeled on the fee side, not added to revenue.
- If ML funding economics cannot be proven from official facts, return `PROMOTION_FUNDING_RECONCILIATION_REQUIRED`.
- If documented fee reduction exceeds the quoted gross sale fee, clamp estimated net sale fee to zero and mark `REQUIRES_ACTUAL_RECONCILIATION`; never create a negative fee credit.
- Historical/observed promotion snapshots are append-only.
- Runtime remains independent from Mercado Turbo.

---

## File Structure Added by This Plan

```text
src/MercadoLivre/Promotion/
├── PromotionOffer.php
├── PromotionRepository.php
├── PromotionSnapshot.php
├── PromotionSyncService.php
└── PromotionType.php
src/Pricing/
├── PromotionEconomics.php
└── PromotionQuoteInput.php
src/Controller/
└── PromotionController.php
tests/Contract/MercadoLivre/
├── PromotionContractTest.php
└── PromotionBoostContractTest.php
tests/Integration/
├── PromotionQuoteApiTest.php
└── PromotionSyncTest.php
tests/Unit/Pricing/
└── PromotionPricingEngineTest.php
```

### Task 1: Persist immutable promotion snapshots

**Files:**
- Create: `src/MercadoLivre/Promotion/PromotionType.php`
- Create: `src/MercadoLivre/Promotion/PromotionSnapshot.php`
- Create: `src/MercadoLivre/Promotion/PromotionRepository.php`
- Create: Doctrine migration
- Test: `tests/Integration/PromotionSyncTest.php`

**Interfaces:**
- Produces: repository lookup by tenant/account/listing/promotion; immutable source-payload snapshots

- [ ] **Step 1: Write RED persistence test**

The same normalized promotion payload written twice must create one snapshot for the same deterministic payload hash; a changed official payload must append a new snapshot rather than overwrite the old one.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/PromotionSyncTest.php`
Expected: FAIL because promotion entities do not exist.

- [ ] **Step 3: Implement schema**

Persist at minimum: listing reference, promotion ID, type, status, buyer-facing offer price fields, seller/Meli participation fields, `boosted_offer`, `discount_meli_boosted_percentage`, `discount_meli_boost_amount`, `total_price_for_boosted_offer`, validity window, source-observed timestamp, payload hash.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit tests/Integration/PromotionSyncTest.php
git add src tests migrations
git commit -m "feat: add immutable ml promotion snapshots"
```

### Task 2: Normalize current `/seller-promotions` contracts

**Files:**
- Create: `src/MercadoLivre/Promotion/PromotionSyncService.php`
- Test: `tests/Contract/MercadoLivre/PromotionContractTest.php`
- Fixtures: `tests/Fixtures/ml/promotions/deal.json`, `marketplace-campaign.json`, `price-discount.json`, `smart.json`, `lightning.json`, `seller-coupon-campaign.json`

**Interfaces:**
- Produces: `PromotionSyncService::syncListing(MlAccount $account, Listing $listing): PromotionSyncResult`

- [ ] **Step 1: Write RED contract tests for supported 2026 promotion types**

Assert normalized `promotion_type` accepts at least `DEAL`, `MARKETPLACE_CAMPAIGN`, `PRICE_DISCOUNT`, `LIGHTNING`, `DOD`, `VOLUME`, `PRE_NEGOTIATED`, `SELLER_CAMPAIGN`, `SMART`, `PRICE_MATCHING`, `UNHEALTHY_STOCK`, and `SELLER_COUPON_CAMPAIGN` when returned by official APIs.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/PromotionContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement explicit normalizers**

Unknown future promotion types must be persisted as `UNKNOWN` plus raw sanitized type metadata and must not be silently mapped to a known economic treatment.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/PromotionContractTest.php
git add src tests
git commit -m "feat: normalize mercado livre promotions"
```

### Task 3: Model seller funding, ML funding, and boosted fee reduction

**Files:**
- Create: `src/Pricing/PromotionEconomics.php`
- Test: `tests/Contract/MercadoLivre/PromotionBoostContractTest.php`
- Test: `tests/Unit/Pricing/PromotionPricingEngineTest.php`

**Interfaces:**
- Produces: `PromotionEconomics::fromSnapshot(PromotionSnapshot $snapshot): PromotionEconomics`

- [ ] **Step 1: Write RED tests for co-funding and boost semantics**

Use fixtures with `seller_percentage`, `meli_percentage`, `discount_meli_boosted_percentage`, `discount_meli_boost_amount`, and `total_price_for_boosted_offer`. Assert buyer-facing revenue is not increased by ML funding.

- [ ] **Step 2: Add the known edge-case regression**

When `official_sale_fee_amount = 9.00` and documented ML fee reduction is `10.00`, expected estimated net fee is `0.00` and completeness contains `REQUIRES_ACTUAL_RECONCILIATION`; it must never be `-1.00`.

- [ ] **Step 3: Run RED**

```bash
php bin/phpunit tests/Contract/MercadoLivre/PromotionBoostContractTest.php tests/Unit/Pricing/PromotionPricingEngineTest.php
```

Expected: FAIL.

- [ ] **Step 4: Implement economics mapping**

Rules:

```text
effective_revenue = buyer_facing_final_price
estimated_net_sale_fee = max(0, official_sale_fee_amount - documented_meli_fee_reduction)
```

Seller-funded and ML-funded discounts are retained as memo/attribution fields. If funding meaning is not explicit enough to determine the correct fee-side treatment, do not modify fee/revenue and flag `PROMOTION_FUNDING_RECONCILIATION_REQUIRED`.

- [ ] **Step 5: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/PromotionBoostContractTest.php tests/Unit/Pricing/PromotionPricingEngineTest.php
git add src tests
git commit -m "feat: model ml promotion funding economics"
```

### Task 4: Extend PricingEngine with promotion-aware `ESTIMATED` input

**Files:**
- Create: `src/Pricing/PromotionQuoteInput.php`
- Modify: `src/Pricing/PricingEngine.php`
- Modify: `src/Pricing/MarginSnapshot.php`
- Test: `tests/Unit/Pricing/PromotionPricingEngineTest.php`

**Interfaces:**
- Produces: `PricingEngine::estimatedPromotion(PromotionQuoteInput $input): MarginCalculation`

- [ ] **Step 1: Write RED table cases**

Cover: normal discount with no ML funding; co-funded campaign; boosted offer; boost equal to fee; boost greater than fee; missing funding semantics; positive/zero/negative resulting margin.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/Pricing/PromotionPricingEngineTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement pure engine branch**

The method accepts already-normalized `PromotionEconomics`; it performs no ML/API/DB call and persists the same `mlb-margin-v1` calculation version until the formula itself changes.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Unit/Pricing/PromotionPricingEngineTest.php
git add src tests
git commit -m "feat: calculate promotion adjusted estimated margins"
```

### Task 5: Expose promotion read and quote endpoints

**Files:**
- Create: `src/Controller/PromotionController.php`
- Modify: `src/Controller/PricingController.php`
- Modify: `docs/openapi.yaml`
- Test: `tests/Integration/PromotionQuoteApiTest.php`

**Interfaces:**
- Produces: `GET /v1/ml/promotions`; `GET /v1/ml/listings/{item_id}/promotions`; `POST /v1/pricing/promotion-quote`

- [ ] **Step 1: Write RED API tests**

Assert tenant isolation, pagination/filtering, decimal-string output, source timestamps, seller/ML funding memo fields, completeness flags, and absence of any promotion mutation route.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/PromotionQuoteApiTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement endpoints and OpenAPI contract**

`POST /v1/pricing/promotion-quote` must resolve the requested official promotion snapshot, current cost/tax, fee quote, and shipping quote before invoking the pure engine.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/PromotionQuoteApiTest.php tests/Contract/OpenApiContractTest.php
git add src tests docs
git commit -m "feat: expose ml promotion pricing api"
```

### Task 6: Add promotion freshness scheduling without polling storms

**Files:**
- Create: `src/Sync/RunPromotionRefresh.php`
- Create: `src/Sync/RunPromotionRefreshHandler.php`
- Modify: `config/packages/messenger.yaml`
- Create: `config/packages/scheduler.yaml` or deterministic systemd timer command
- Test: `tests/Integration/PromotionRefreshSchedulingTest.php`

**Interfaces:**
- Produces bounded refresh target <=30 minutes while active; no paid AI runtime

- [ ] **Step 1: Write RED scheduling/idempotency test**

A second refresh request for the same account/listing window must coalesce or no-op rather than create unbounded duplicate work.

- [ ] **Step 2: Implement deterministic scheduling**

Use one bounded refresh job per active account window. No sub-minute loop; retries remain bounded by Messenger failure policy.

- [ ] **Step 3: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/PromotionRefreshSchedulingTest.php
git add src tests config
git commit -m "feat: schedule bounded promotion refresh"
```

### Task 7: Promotion parity matrix and live read-only validation

**Files:**
- Create: `tests/Live/MercadoLivrePromotionSmokeTest.php`
- Create: `tests/Parity/PromotionParityTest.php`
- Create: `tests/Fixtures/parity/promotion-cases.json`
- Create: `docs/validation/promotion-parity.md`

**Interfaces:**
- Produces classified parity evidence; Mercado Turbo remains a human-observed benchmark input only

- [ ] **Step 1: Build seller-owned parity fixture schema**

Each case stores only normalized inputs/expected economic components, not Mercado Turbo private API payloads. Required classes: standard promotion, ML-funded, boosted, near-zero margin, negative margin, and fee-reduction clamp edge.

- [ ] **Step 2: Write parity assertions**

Every mismatch must be classified as `OUR_BUG`, `STALE_OR_DIFFERENT_INPUTS`, `DOCUMENTED_SEMANTIC_DIFFERENCE`, `MERCADO_TURBO_DISCREPANCY`, or `UNSUPPORTED_OR_UNVERIFIABLE`.

- [ ] **Step 3: Run full deterministic suite**

```bash
php bin/phpunit tests/Contract/MercadoLivre tests/Unit/Pricing tests/Integration/PromotionQuoteApiTest.php tests/Parity/PromotionParityTest.php
```

Expected: PASS.

- [ ] **Step 4: Run live read-only promotion smoke**

```bash
RUN_ML_LIVE_TESTS=1 php bin/phpunit tests/Live/MercadoLivrePromotionSmokeTest.php
```

Expected: reads active promotion context and generates internal estimate; zero ML writes.

- [ ] **Step 5: Commit and review**

```bash
git add .
git commit -m "test: validate ml promotion economics"
git push -u origin HEAD
gh pr create --base main --title "feat: add promotion adjusted ML margins" --body "Adds read-only promotion synchronization, funding attribution, boosted fee-reduction semantics, and promotion-aware ESTIMATED margins."
```

Merge only after CI, review, live-read evidence, and no-secret checks pass.
