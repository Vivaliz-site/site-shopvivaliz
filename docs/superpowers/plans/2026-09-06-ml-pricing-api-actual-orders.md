# Mercado Livre Pricing API — Orders, Webhooks & ACTUAL Margin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add durable Mercado Livre order/webhook ingestion, order-discount attribution, actual shipment-cost synchronization, historical cost/tax resolution, and immutable `ACTUAL` margin snapshots for recent sales.

**Architecture:** Webhooks are accepted durably and processed asynchronously. Orders, discounts, shipment links, and shipment costs are normalized into canonical tables; the pure pricing engine receives only normalized actual facts. Shipment sender cost is counted exactly once per unique shipment and allocated to items only as a derived view.

**Tech Stack:** Existing PHP 8.3+/Symfony 7.4 service, Doctrine/MySQL, Symfony Messenger, PHPUnit/OpenAPI.

**Spec:** `docs/superpowers/specs/2026-09-06-ml-pricing-api-design.md`

## Global Constraints

- Order revenue uses the effective amount charged to the buyer; when ML `unit_price` already contains the sale discount, seller discount must not be subtracted again.
- Post-sale fee uses the actual order-item sale fee where supplied; do not recompute historical sales using today's listing-price quote.
- Actual shipping uses `/shipments/{shipment_id}/costs`, with seller sender cost from `senders[].cost`.
- A unique shipment cost is counted once at order/pack scope.
- Buyer contact/identity fields are not persisted by default because pricing does not need them.
- Webhook resource input must never become an arbitrary authenticated URL.
- Webhook processing is durable, idempotent, asynchronous, bounded-retry, and dead-letter capable.
- V1 remains read-only against Mercado Livre.

---

## File Structure Added by This Plan

```text
src/MercadoLivre/Order/
├── Order.php
├── OrderItem.php
├── OrderRepository.php
├── OrderSyncService.php
└── OrderDiscountService.php
src/MercadoLivre/Shipment/
├── Shipment.php
├── ShipmentCostSnapshot.php
├── ShipmentRepository.php
├── ShipmentCostService.php
└── ShipmentAllocationService.php
src/Webhook/
├── WebhookEvent.php
├── WebhookIngestionService.php
├── ProcessMlWebhook.php
└── ProcessMlWebhookHandler.php
src/Pricing/
└── ActualQuoteInput.php
src/Controller/
├── OrderController.php
└── WebhookController.php
src/Sync/
├── RepairMissedMlFeeds.php
└── RepairMissedMlFeedsHandler.php
tests/Contract/MercadoLivre/
├── OrderContractTest.php
├── OrderDiscountContractTest.php
├── ShipmentCostContractTest.php
└── WebhookContractTest.php
tests/Integration/
├── ActualMarginTest.php
├── ShipmentDeduplicationTest.php
├── WebhookIdempotencyTest.php
└── MissedFeedRepairTest.php
```

### Task 1: Create canonical order and order-item persistence

**Files:**
- Create: `src/MercadoLivre/Order/Order.php`
- Create: `src/MercadoLivre/Order/OrderItem.php`
- Create: `src/MercadoLivre/Order/OrderRepository.php`
- Create: migration
- Test: `tests/Integration/OrderPersistenceTest.php`

**Interfaces:**
- Produces tenant/account-scoped order lookup and immutable item economic facts

- [ ] **Step 1: Write RED persistence test**

Persist an order with two items and assert: ML order ID, optional pack ID, status, currency, effective total, timestamps, item IDs, variation/User Product/SKU identities, quantity, `unit_price`, optional reconstructed regular/gross amount, actual `sale_fee`, and payload hash.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/OrderPersistenceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement schema with privacy minimization**

Do not add buyer name, email, phone, address, or document columns to these pricing tables. Unique order identity is `(ml_account_id, ml_order_id)`.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit tests/Integration/OrderPersistenceTest.php
git add src tests migrations
git commit -m "feat: add canonical ml order model"
```

### Task 2: Normalize current order contract without discount double counting

**Files:**
- Create: `src/MercadoLivre/Order/OrderSyncService.php`
- Test: `tests/Contract/MercadoLivre/OrderContractTest.php`
- Fixture: `tests/Fixtures/ml/order.json`

**Interfaces:**
- Produces: `OrderSyncService::syncById(MlAccount $account, string $orderId): Order`

- [ ] **Step 1: Write RED order contract test**

Use a sanitized order fixture where `unit_price` is already discounted. Assert effective revenue for an item is exactly `unit_price * quantity`, while a regular/gross price is stored only as reference metadata.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/OrderContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement order normalizer**

Normalize only pricing-relevant fields. Preserve order-item `sale_fee` exactly as returned by the current contract. Do not call pre-sale `listing_prices` to replace actual sale fee.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/OrderContractTest.php
git add src tests
git commit -m "feat: normalize mercado livre orders"
```

### Task 3: Normalize order discounts as attribution data

**Files:**
- Create: `src/MercadoLivre/Order/OrderDiscountService.php`
- Modify: `src/MercadoLivre/Order/OrderItem.php`
- Test: `tests/Contract/MercadoLivre/OrderDiscountContractTest.php`
- Fixture: `tests/Fixtures/ml/order-discounts.json`

**Interfaces:**
- Produces seller-funded/ML-funded discount attribution fields without automatic second expense subtraction

- [ ] **Step 1: Write RED no-double-count regression**

Fixture:

```text
regular reference price: 100.00
unit_price charged: 90.00
seller-funded discount memo: 10.00
quantity: 1
```

Expected revenue is `90.00`, not `80.00`.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/OrderDiscountContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement discount metadata normalization**

Persist source campaign/offer identifiers and seller/ML shares as memo/attribution. Only an explicit official post-sale adjustment may change the accounting line later.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/OrderDiscountContractTest.php
git add src tests
git commit -m "feat: normalize ml order discount attribution"
```

### Task 4: Durable idempotent webhook acceptance

**Files:**
- Create: `src/Webhook/WebhookEvent.php`
- Create: `src/Webhook/WebhookIngestionService.php`
- Create: `src/Controller/WebhookController.php`
- Create: migration
- Test: `tests/Contract/MercadoLivre/WebhookContractTest.php`
- Test: `tests/Integration/WebhookIdempotencyTest.php`
- Test: `tests/Security/WebhookSsrfTest.php`

**Interfaces:**
- Produces: `POST /v1/webhooks/mercadolivre`; durable event key; async message dispatch

- [ ] **Step 1: Write RED tests for allowed envelope/resource syntax**

Accept only known topic/resource families. For orders, resource must match `/orders/{numeric_id}`. For shipments/items/prices, define equivalent strict resource regexes. Reject schemes, hosts, `..`, encoded host/path tricks, and malformed IDs.

- [ ] **Step 2: Write RED duplicate test**

Posting the exact same notification twice must return 200 both times but create only one durable event/work item.

- [ ] **Step 3: Run RED**

```bash
php bin/phpunit tests/Contract/MercadoLivre/WebhookContractTest.php tests/Integration/WebhookIdempotencyTest.php tests/Security/WebhookSsrfTest.php
```

Expected: FAIL.

- [ ] **Step 4: Implement durable acceptance**

Persist sanitized envelope and deterministic idempotency key in one transaction, enqueue `ProcessMlWebhook`, then return 200. Do not perform full order/shipment sync inside the HTTP request.

- [ ] **Step 5: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/WebhookContractTest.php tests/Integration/WebhookIdempotencyTest.php tests/Security/WebhookSsrfTest.php
git add src tests migrations
git commit -m "feat: add durable ml webhook ingestion"
```

### Task 5: Process order webhooks asynchronously

**Files:**
- Create: `src/Webhook/ProcessMlWebhook.php`
- Create: `src/Webhook/ProcessMlWebhookHandler.php`
- Modify: `config/packages/messenger.yaml`
- Test: `tests/Integration/WebhookOrderProcessingTest.php`

**Interfaces:**
- Consumes: persisted `WebhookEvent`
- Produces: synchronized order/discount records and dependent shipment work

- [ ] **Step 1: Write RED handler test**

Given one accepted `orders_v2` event, handler must fetch `/orders/{id}` via the fixed ML client, normalize order, fetch `/orders/{id}/discounts`, update event status, and be safe to replay.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/WebhookOrderProcessingTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement bounded retry/failure transport**

Retry only provider/network errors classified retryable by `MercadoLivreClient`; exhausted work goes to Messenger failure transport and marks the webhook event failed/dead-lettered with sanitized error class.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/WebhookOrderProcessingTest.php
git add src tests config
git commit -m "feat: process ml order webhooks asynchronously"
```

### Task 6: Synchronize actual shipment sender costs

**Files:**
- Create: `src/MercadoLivre/Shipment/Shipment.php`
- Create: `src/MercadoLivre/Shipment/ShipmentCostSnapshot.php`
- Create: `src/MercadoLivre/Shipment/ShipmentRepository.php`
- Create: `src/MercadoLivre/Shipment/ShipmentCostService.php`
- Create: migration
- Test: `tests/Contract/MercadoLivre/ShipmentCostContractTest.php`

**Interfaces:**
- Produces: `ShipmentCostService::syncActual(MlAccount $account, string $shipmentId): ShipmentCostSnapshot`

- [ ] **Step 1: Write RED contract test**

Fixture must assert seller actual shipping comes from `senders[].cost`, buyer cost is stored separately, discount metadata is retained, and observed timestamp/payload hash are persisted.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/ShipmentCostContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement shipment-cost fetch**

Call only `/shipments/{shipment_id}/costs` against fixed ML base. Persist a new snapshot only when the official payload/economic facts change.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/ShipmentCostContractTest.php
git add src tests migrations
git commit -m "feat: sync actual ml shipment costs"
```

### Task 7: Prevent shipment-cost double counting and allocate item views

**Files:**
- Create: `src/MercadoLivre/Shipment/ShipmentAllocationService.php`
- Create: `src/MercadoLivre/Shipment/OrderShipmentLink.php`
- Create: migration
- Test: `tests/Integration/ShipmentDeduplicationTest.php`

**Interfaces:**
- Produces: exact order/pack shipment total and derived per-item allocation with method/confidence

- [ ] **Step 1: Write RED multi-order/pack test**

Create two order records linked to the same shipment with sender cost `20.00`. Assert pack/order aggregation never totals `40.00` from that one unique shipment.

- [ ] **Step 2: Write RED per-item allocation test**

For items with effective revenues `75.00` and `25.00`, sender cost `20.00` allocates `15.00` and `5.00` using `PROPORTIONAL_REVENUE`. If both revenues are zero, fall back to quantity allocation and record `QUANTITY_FALLBACK`.

- [ ] **Step 3: Run RED**

Run: `php bin/phpunit tests/Integration/ShipmentDeduplicationTest.php`
Expected: FAIL.

- [ ] **Step 4: Implement deterministic unique-shipment accounting**

Use official per-item allocation when explicitly supplied; otherwise proportional revenue, then quantity fallback. Persist allocation method. Ensure allocated parts sum exactly to sender total after deterministic remainder handling.

- [ ] **Step 5: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/ShipmentDeduplicationTest.php
git add src tests migrations
git commit -m "feat: deduplicate and allocate shipment costs"
```

### Task 8: Historical cost/tax resolution for sale timestamp

**Files:**
- Modify: `src/Cost/CostCatalog.php`
- Modify: `src/Tax/TaxCatalog.php`
- Test: `tests/Integration/HistoricalOrderCostSelectionTest.php`

**Interfaces:**
- Produces exact cost/tax profile effective at `order.date_created`/economic timestamp

- [ ] **Step 1: Write RED historical test**

Cost profile A=`20.00` valid through June 30; profile B=`25.00` valid July 1 onward. A June 15 order must always resolve `20.00`, even after profile B exists.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/HistoricalOrderCostSelectionTest.php`
Expected: FAIL if current-value lookup is used.

- [ ] **Step 3: Implement timestamp-specific lookup and GREEN**

```bash
php bin/phpunit tests/Integration/HistoricalOrderCostSelectionTest.php
git add src tests
git commit -m "fix: resolve historical costs for ml orders"
```

### Task 9: Pure deterministic ACTUAL pricing engine

**Files:**
- Create: `src/Pricing/ActualQuoteInput.php`
- Modify: `src/Pricing/PricingEngine.php`
- Modify: `src/Pricing/MarginSnapshot.php`
- Test: `tests/Unit/Pricing/ActualPricingEngineTest.php`

**Interfaces:**
- Produces: `PricingEngine::actual(ActualQuoteInput $input): MarginCalculation`

- [ ] **Step 1: Write RED formula tests**

Normative formula:

```text
effective_order_revenue
- historical_product_cost
- historical_packaging_cost
- historical_other_variable_cost
- tax_amount
- actual_order_sale_fee
- actual_sender_shipping_cost
= actual_contribution_margin
```

Include the already-discounted `unit_price` regression and multi-quantity case.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/Pricing/ActualPricingEngineTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement pure ACTUAL calculation**

No network/DB calls. Seller/ML discount funding remains memo fields. `contribution_margin_pct` is null when effective revenue <=0.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Unit/Pricing/ActualPricingEngineTest.php
git add src tests
git commit -m "feat: calculate actual ml sale margins"
```

### Task 10: Materialize ACTUAL snapshots and order APIs

**Files:**
- Create: `src/Pricing/ActualMarginService.php`
- Create: `src/Controller/OrderController.php`
- Modify: `docs/openapi.yaml`
- Test: `tests/Integration/ActualMarginTest.php`

**Interfaces:**
- Produces: `GET /v1/ml/orders`, `GET /v1/ml/orders/{order_id}`, `GET /v1/ml/orders/{order_id}/margin`, `GET /v1/ml/orders/{order_id}/margin/history`

- [ ] **Step 1: Write RED API test**

Assert account/tenant scoping, decimal strings, source timestamps, effective revenue, cost/tax profiles, actual sale fee, exact unique shipment cost, discount memo fields, completeness, and immutable snapshot history.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/ActualMarginTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement materialization orchestration**

Create/update source facts as needed, resolve historical seller data, call pure engine, append `ACTUAL` snapshot. Never mutate `ESTIMATED` snapshot.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/ActualMarginTest.php tests/Contract/OpenApiContractTest.php
git add src tests docs
git commit -m "feat: expose actual ml order margins"
```

### Task 11: Missed-feed repair and persistent cursors

**Files:**
- Create: `src/Sync/RepairMissedMlFeeds.php`
- Create: `src/Sync/RepairMissedMlFeedsHandler.php`
- Modify: `src/Sync/SyncCursor.php`
- Test: `tests/Integration/MissedFeedRepairTest.php`

**Interfaces:**
- Produces deterministic repair path for missed notifications without offset/scroll misuse

- [ ] **Step 1: Write RED cursor/idempotency test**

Two repair runs over the same feed window must converge without duplicate orders/events; cursor advances only after durable processing checkpoint.

- [ ] **Step 2: Implement bounded repair job**

Consume current missed-feed contract, enqueue normalized event work, persist high-water mark, and stop at configured batch/time boundaries.

- [ ] **Step 3: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/MissedFeedRepairTest.php
git add src tests
git commit -m "feat: repair missed ml notifications"
```

### Task 12: Live ACTUAL-margin acceptance gate

**Files:**
- Create: `tests/Live/MercadoLivreActualMarginSmokeTest.php`
- Create: `docs/validation/actual-margin-live-smoke.md`
- Modify: `.github/workflows/ci.yml` only to keep live test manual/secrets-gated

**Interfaces:**
- Produces one verified recent order `ACTUAL` snapshot with zero marketplace writes

- [ ] **Step 1: Add live smoke**

Read a recent confirmed order available to the authorized seller, its discounts, and shipment cost where present; produce one internal `ACTUAL` snapshot.

- [ ] **Step 2: Run deterministic full suite**

```bash
php bin/phpunit
vendor/bin/phpstan analyse src tests --level=8
```

Expected: PASS.

- [ ] **Step 3: Run live read-only smoke**

```bash
RUN_ML_LIVE_TESTS=1 php bin/phpunit tests/Live/MercadoLivreActualMarginSmokeTest.php
```

Expected: PASS; audit shows only ML reads and internal writes.

- [ ] **Step 4: Verify no duplicate shipment charge and no discount double subtraction in evidence**

Record exact order ID masked where appropriate, input sources/timestamps, and formula component totals in `docs/validation/actual-margin-live-smoke.md` without buyer PII or tokens.

- [ ] **Step 5: Commit and review**

```bash
git add .
git commit -m "test: validate actual ml order margins"
git push -u origin HEAD
gh pr create --base main --title "feat: add actual ML order margins" --body "Adds durable order/webhook ingestion, actual shipment costs, discount attribution, and immutable ACTUAL margin snapshots."
```

Merge only after CI, review, secret/privacy scan, webhook security tests, and live-read evidence are green.
