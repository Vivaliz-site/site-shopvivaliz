# Mercado Livre Pricing & Margin API — Design

**Date:** 2026-09-06  
**Status:** Architecture approved; written specification pending user review  
**Initial marketplace scope:** Mercado Livre Brazil (`MLB`) only  
**Planned implementation repository:** `Vivaliz-site/ml-pricing-api` (created only after this specification is approved)

## 1. Goal

Build an isolated, API-first and auditable service that calculates contribution margin for Mercado Livre listings, promotions and sales using official Mercado Livre APIs plus seller-owned cost/tax data.

The first release reproduces the economically useful core observed in Mercado Turbo without depending on Mercado Turbo at runtime and without copying its proprietary code, UI, assets, selectors or private API implementation.

The service distinguishes three immutable financial states:

1. `ESTIMATED`: pre-sale quote using current item, price, promotion, fee, shipping and seller cost assumptions.
2. `ACTUAL`: post-sale result using confirmed order, actual sale fee, applied discount context and actual shipment charge when available.
3. `RECONCILED`: later result after official billing credits/debits/adjustments are reconciled.

A later state never mutates an older snapshot.

## 2. Non-negotiable rules

- Mercado Livre official APIs are the production source of truth for marketplace facts.
- Mercado Turbo is a black-box benchmark only; no production dependency on its endpoints, extension, tokens or cached data.
- V1 is read-only against Mercado Livre. It may write internal cost/tax/configuration data only.
- No ML price, promotion, stock, campaign or pricing-automation mutation route exists in V1.
- Existing repository policy protecting marketplace prices remains in force.
- Every monetary result is explainable from persisted source facts and a versioned deterministic formula.
- Missing fee, freight, tax, cost or promotion semantics never silently become zero/default unless zero is an explicit authoritative value.
- `1 MLB = 1 SKU = 1 price` is forbidden as an assumption. User Products, legacy variations and multiple sale conditions are supported.
- All seller/account-scoped rows carry `tenant_id`; all ML marketplace rows also carry `ml_account_id`.
- OAuth credentials/tokens are encrypted at rest, access-restricted and never logged.
- Timestamps are UTC. Money is decimal, never binary float.
- Permanent runtime jobs are deterministic; no recurring paid AI dependency.

## 3. Compliance boundary

This product is a value-added seller-management/profitability application, not a generic resale/proxy of the Mercado Livre API.

Current Mercado Livre Developer Terms require, among other things, that marketplace content embedded in applications be refreshed at least every 24 hours and item listings at least every 6 hours; they restrict scraping, redistribution/sublicensing, applications that operate substantially like the API, and some derived marketplace/performance statistics without express authorization.

Therefore V1 follows these rules:

- no scraping for facts available through the Developer Program;
- no bypass of API/rate-limit/security controls;
- no raw third-party token exposure;
- no generic pass-through API whose product is redistribution of ML responses;
- data minimization for buyer/personal information;
- public multi-customer commercialization and publication of derived marketplace statistics remain behind a Partner/Developer compliance review gate.

Reference: https://developers.mercadolivre.com.br/pt_br/termos-e-condicoes

## 4. Clean-room parity rule

Mercado Turbo may be used only to compare visible behavior for seller-owned cases.

Implementation sources are:

- official Mercado Livre documentation and responses;
- seller-owned Olist/Tiny/cost/tax data;
- formulas and domain rules defined in this specification;
- independently written adapters/tests.

Allowed benchmark:

`same seller case -> Mercado Turbo result vs our result vs official ML source facts`

If Mercado Turbo differs from official ML source facts or current documented semantics, the official source wins and the discrepancy becomes a classified regression fixture.

## 5. Source hierarchy

### Pre-sale estimate

`ML_PRICE_API + seller-promotions + listing_prices + shipping_options + seller cost/tax profiles`

### Post-sale actual

`ORDER + ORDER_ITEMS + ORDER_DISCOUNTS + actual order sale_fee + SHIPMENT_COSTS + historical seller cost/tax profiles`

### Financial reconciliation

`BILLING_INTEGRATION / official billing adjustments > operational estimates`

### Seller-owned costs

`approved manual override > validated Olist/Tiny profile > missing`

Cost/tax profiles are effective-dated. A cost changed tomorrow never rewrites yesterday's margin.

## 6. Official Mercado Livre contracts targeted by V1

- seller items: `GET /users/{USER_ID}/items/search`
- item bulk detail: `GET /items/bulk?ids=...`
- price contexts: `GET /items/{ITEM_ID}/prices` and current sale-price resources
- price notifications: topic `items_prices`
- selling-cost quote: `GET /sites/MLB/listing_prices`
- shipping quote: `GET /users/{USER_ID}/shipping_options/free`
- promotions: `/seller-promotions`
- pricing automation discovery: `/pricing-automation/...`
- orders: `/orders` and `/orders/{ORDER_ID}`
- order discounts: `GET /orders/{ORDER_ID}/discounts`
- actual shipment cost: `GET /shipments/{SHIPMENT_ID}/costs`
- notifications: `orders_v2`, `items`, `shipments`, `items_prices` where applicable
- missed notification recovery: `/missed_feeds`
- billing reconciliation: `/billing/integration/...`

The new service does not use old multi-get `/items?ids=` or `/users?ids=`. Current ML documentation requires migration to `/items/bulk?ids=` and `/users/bulk?ids=` by 2026-10-25.

## 7. Key 2026 ML semantics incorporated

### Selling fees

For MLB, current `listing_prices` needs the relevant logistics context. The fixed fee now depends on logistics; missing `logistic_type` / shipping mode can make the quote differ from the real charge.

`sale_fee_amount` is the total selling cost returned by ML. Any `fixed_fee` shown in details is already included and must never be added again.

Reference: https://developers.mercadolivre.com.br/pt_br/comissao-por-vender

### Promotion boosts

Applicable campaigns can return:

- `boosted_offer`
- `discount_meli_boosted_percentage`
- `discount_meli_boost_amount`
- `total_price_for_boosted_offer`

ML documents the boost as an equivalent reduction in selling costs. The engine models that benefit as fee reduction, not fabricated revenue.

Reference: https://developers.mercadolivre.com.br/pt_br/gerenciar-ofertas

### Price automation

Items with dynamic pricing automation require the pricing-automation contract for future price changes. A generic price PUT loop is not an acceptable V2 design.

### User Products / variations

The data model stores item, User Product and legacy variation identifiers independently. Profitability is resolved at the narrowest available sale condition.

## 8. Architecture decision

Use a new isolated modular monolith, not a microservice fleet and not an extension-first product.

```text
Mercado Livre OAuth
        |
        v
+-----------------------+
| MercadoLivre Adapter  |
| auth/http/retry/limit |
+----------+------------+
           |
   +-------+--------+----------------+
   |                |                |
   v                v                v
Listing/Promo    Order/Webhook     Cost/Tax
Sync             Ingestion         Providers
   |                |                |
   +----------------+----------------+
                    |
                    v
           +------------------+
           | Canonical Store  |
           | events/snapshots |
           +--------+---------+
                    |
                    v
           +------------------+
           | Pricing Engine   |
           | pure/deterministic|
           +--------+---------+
                    |
          +---------+---------+
          |         |         |
          v         v         v
        REST      Reports   Reconcile
```

Why:

- isolated enough for future SaaS;
- simple enough for existing Oracle infrastructure;
- one transactionally consistent store supports idempotency, audit and snapshots;
- browser DOM is not part of the financial truth;
- modules can later be extracted only if measured load justifies it.

## 9. Technology stack

Planned service baseline:

- PHP 8.3+
- Symfony 7.4 LTS
- MySQL 8.0+ dedicated logical database
- Doctrine migrations/DBAL/ORM
- Symfony HttpClient
- Symfony Messenger with database-backed durable transport in V1
- PHPUnit
- OpenAPI 3.1 contract validation
- Nginx + PHP-FPM
- dedicated systemd worker
- bounded systemd timer/cron jobs for repair sync/reconciliation

Redis is deliberately not required initially. Add it only after measured queue/cache demand warrants it.

The service is a separate repository and database. There are no cross-repository database joins or foreign keys into the legacy ShopVivaLiz schema.

## 10. Existing ShopVivaLiz reuse/retirement boundary

Useful concepts to reuse:

- current ML OAuth/PKCE experience;
- runtime secret/deploy mechanisms;
- current `orders_v2` webhook knowledge;
- existing Olist/Tiny integration knowledge.

Must not become the new core:

- duplicated ML clients/auth in `api/ml/client.php` and `api/ml/_bootstrap.php`;
- JSON-file token persistence as long-term multi-account storage;
- synchronous business work inside webhook requests;
- `storage/orders/*.json` as canonical profitability storage;
- fallback product JSON as source of ML listings.

The new service has one auth implementation, one ML transport, one canonical DB and one durable event path.

## 11. Module boundaries

### `Identity/Tenant`
Owns tenants, API clients and account scoping. Every repository query is tenant-scoped.

### `MercadoLivreAuth`
Owns OAuth authorization-code + PKCE, token refresh, encrypted persistence and authorization status.

### `MercadoLivreClient`
Owns provider transport only:

- fixed allowlisted API base;
- authorization header;
- timeouts;
- retry classification;
- exponential backoff with jitter;
- concurrency budgets;
- redacted telemetry.

Business modules cannot pass arbitrary absolute URLs.

### `ListingSync`
Discovers seller items and normalizes item, User Product/variation, logistics, price contexts and pricing-automation state.

### `PromotionSync`
Normalizes `/seller-promotions` and per-item offers, including seller/ML funding and boost fields.

### `OrderSync`
Normalizes orders, packs, order items, effective prices, sale fees, discounts and shipment links.

### `ShippingService`
Two contracts:

- `quote()` = pre-sale estimate from official shipping quote API;
- `actual()` = post-sale sender charge from `/shipments/{id}/costs`.

### `FeeService`
Calls `listing_prices` with complete price/category/listing/logistics context. It treats `sale_fee_amount` as the total official selling cost and prevents fixed-fee double counting.

### `CostCatalog`
Owns SKU cost/packaging/other-variable-cost profiles and effective dating. Olist/Tiny adapters feed this module.

### `TaxCatalog`
Owns versioned seller tax profiles and basis rules. It does not embed tax-law assumptions into the listing adapter.

### `PricingEngine`
Pure deterministic function. No network/database calls.

### `Reconciliation`
Adds verified billing adjustments to post-sale economics without rewriting previous snapshots.

### `WebhookIngestion`
Persists notification envelope first, returns after durable acceptance, processes asynchronously and idempotently.

### `Audit`
Append-only business/configuration audit. Never stores secrets/tokens.

## 12. Canonical data model

All business tables include `tenant_id`; marketplace-owned rows also include `ml_account_id`.

### `tenants`
`id`, `name`, `status`, timestamps.

### `api_clients`
`id`, `tenant_id`, `name`, `token_hash`, `scopes_json`, `last_used_at`, `revoked_at`, timestamps.

### `ml_accounts`
`id`, `tenant_id`, `seller_id`, `site_id`, `nickname`, encrypted access/refresh token fields, expiry/status, metadata, timestamps. Unique `(tenant_id,seller_id,site_id)`.

### `ml_listings`
`id`, tenant/account, `item_id`, `user_product_id`, `family_id`, `catalog_product_id`, `category_id`, `listing_type_id`, status/condition, `seller_sku`, currency, `shipping_mode`, `logistic_type`, `free_shipping`, dimensions, ML update timestamp, sync timestamp. Unique `(ml_account_id,item_id)`.

### `ml_listing_variants`
Listing reference, `variation_id`, `user_product_id`, `seller_sku`, attributes JSON.

### `price_snapshots`
Listing/sale-condition reference, context key, `amount`, `regular_amount`, currency, promotion reference, source, observed timestamp, payload hash, created timestamp.

### `pricing_automation_snapshots`
Listing reference, status, automation/rule ID, min/max price if supplied, status cause, observed timestamp.

### `sku_cost_profiles`
Tenant, SKU, unit/packaging/other variable cost, currency, source (`MANUAL|OLIST|TINY`), source reference, `valid_from`, `valid_to`, approval metadata, timestamps. Effective periods cannot overlap inside the same precedence layer.

### `tax_profiles`
Tenant, profile key, rate, basis (`GROSS_REVENUE` initially), effective dates, source/audit metadata.

### `listing_tax_assignments`
Listing/SKU -> tax-profile mapping.

### `promotion_snapshots`
Listing reference, promotion ID/type/status, seller offer fields, ML/seller participation fields, boost fields, validity window, observed timestamp, payload hash.

### `fee_quotes`
Listing/category/listing type, quoted buyer-facing price, shipping/logistics context, official `sale_fee_amount`, optional returned details, observed timestamp, payload hash.

### `shipping_quotes`
Listing/sale-condition reference, price/logistics/dimensions fingerprint, seller estimated cost, observed timestamp, payload hash.

### `orders`
Tenant/account, ML order ID, pack ID, status, currency, effective total, creation/close/update timestamps, source timestamps.

### `order_items`
Order reference, item/variation/User Product/SKU identifiers, quantity, `unit_price` (effective amount charged per unit when supplied by ML), optional reconstructed pre-discount/gross amount, actual order-item `sale_fee`, promotion/discount funding metadata, payload hash.

### `shipments`
Tenant/account, shipment ID, status/logistic type, observed timestamps. Unique `(ml_account_id,shipment_id)`.

### `order_shipment_links`
Prevents shipment-cost double counting across packs/multi-order shipments.

### `shipment_cost_snapshots`
Shipment reference, official gross shipment amount, sender final cost, receiver final cost, discount metadata, observed timestamp, payload hash.

### `billing_adjustments`
Tenant/account, order/order-item references when resolvable, official billing identity, adjustment type, amount/currency, occurred timestamp, idempotency key/payload hash.

### `margin_snapshots`
Append-only result containing:

- subject (`LISTING|PROMOTION|ORDER|ORDER_ITEM`);
- state (`ESTIMATED|ACTUAL|RECONCILED`);
- quantity;
- `reference_price_before_discount` when known;
- `effective_revenue`;
- product/packaging/other variable cost;
- tax amount;
- gross/official sale fee;
- ML documented fee reduction where applicable;
- net sale fee used by the estimate;
- seller shipping cost;
- seller-funded and ML-funded discount amounts as **memo/attribution fields**, not automatically additive/subtractive accounting lines;
- billing adjustment net;
- contribution margin and percentage;
- allocation method/confidence for allocated shipment cost;
- completeness status/missing inputs;
- source refs;
- calculation version/timestamp.

### `webhook_events`
Deterministic idempotency key, topic/resource/user/application, sanitized envelope, status, retry fields, timestamps.

### `sync_cursors`
Per account/source/topic high-water marks and repair-sync state.

### `audit_log`
Actor/action/target/reason/before-after hashes/timestamp.

## 13. Revenue and discount semantics

This section is normative because it prevents a major double-counting class of bugs.

### 13.1 Buyer-facing effective revenue

For `ACTUAL`, when the order returns `unit_price` already reflecting the applied sale discount, revenue is:

`effective_revenue = unit_price * quantity`

The seller-funded discount must **not** then be subtracted again from this already-discounted revenue.

Discount endpoint data is retained for attribution/explanation and later reconciliation, not blindly treated as another expense line.

### 13.2 Estimated promotion revenue

For `ESTIMATED`, use the buyer-facing final price for the selected promotion/context as `effective_revenue`.

If an ML boost reduces selling costs, record that benefit in the fee side of the calculation. Do not add it to revenue.

### 13.3 Reconstructed reference price

When a reliable regular/pre-discount price is available, store it only for explanation:

`reference_price_before_discount`

It is not the revenue basis for `ACTUAL` unless official transaction semantics explicitly require it.

### 13.4 Funding ambiguity

When ML exposes promotional funding metadata but the economic treatment cannot be proven from current official transaction/billing facts, set an explicit completeness flag such as `PROMOTION_FUNDING_RECONCILIATION_REQUIRED` rather than guessing.

## 14. Margin formulas

### `ESTIMATED`

`effective_revenue`
`- product_cost`
`- packaging_cost`
`- other_variable_cost`
`- tax_amount`
`- estimated_net_sale_fee`
`- estimated_seller_shipping_cost`
`= estimated_contribution_margin`

Where:

`estimated_net_sale_fee = max(0, official_sale_fee_amount - documented_meli_fee_reduction)` only when current official promotion semantics explicitly represent that benefit as a selling-cost reduction.

If the benefit appears to exceed the gross selling cost, clamp the estimate to zero selling fee and mark `REQUIRES_ACTUAL_RECONCILIATION`; never invent a negative fee/credit.

### `ACTUAL`

`effective_order_revenue`
`- historical_product_cost`
`- historical_packaging_cost`
`- historical_other_variable_cost`
`- tax_amount`
`- actual_order_sale_fee`
`- actual_sender_shipping_cost`
`= actual_contribution_margin`

Use actual order sale fee when supplied by the order/transaction contract; do not recompute an old sale with today's `listing_prices`.

### `RECONCILED`

`actual_contribution_margin + verified_billing_adjustment_net = reconciled_contribution_margin`

Credits are positive; debits are negative. Only official, matched adjustments enter this state.

### Percentage

`contribution_margin_pct = contribution_margin / effective_revenue * 100`

If effective revenue <= 0, percentage is `null`.

## 15. Multi-item shipment allocation

Shipment sender cost is authoritative at shipment level. It must be counted once.

For order-level margin, use the full unique shipment sender cost directly.

For order-item margin:

1. use an official per-item shipping allocation if ML explicitly supplies one;
2. otherwise allocate the shipment sender cost proportionally by each item's effective revenue within the unique shipment;
3. if total effective revenue is zero, fall back to quantity allocation;
4. store `allocation_method` and mark item-level shipping as derived.

Allocation never changes the exact order/shipment total.

## 16. Completeness model

Representative statuses:

- `COMPLETE`
- `MISSING_COST`
- `MISSING_TAX_PROFILE`
- `MISSING_SHIPPING_QUOTE`
- `MISSING_FEE_QUOTE`
- `STALE_MARKETPLACE_DATA`
- `PROMOTION_FUNDING_RECONCILIATION_REQUIRED`
- `REQUIRES_ACTUAL_RECONCILIATION`
- `UNSUPPORTED_CONTEXT`

Partial results may expose known components, but an incomplete quote cannot be presented as a reliable final margin.

## 17. API contract V1

JSON only under `/v1`. Monetary values serialize as decimal strings.

### Health/accounts

- `GET /v1/health`
- `GET /v1/accounts`
- `GET /v1/accounts/{account_id}`

### Listings

- `GET /v1/ml/listings?sku=&item_id=&status=&catalog=&page=`
- `GET /v1/ml/listings/{item_id}`
- `GET /v1/ml/listings/{item_id}/prices`
- `GET /v1/ml/listings/{item_id}/pricing-automation`

### Internal cost/tax registry

- `GET /v1/costs/{sku}`
- `PUT /v1/costs/{sku}`
- `GET /v1/tax-profiles`
- `PUT /v1/listings/{item_id}/tax-profile`

Internal writes require privileged scope and audit reason.

### Pricing

- `POST /v1/pricing/quote`
- `POST /v1/pricing/quotes/batch`
- `POST /v1/pricing/promotion-quote`

Responses include price context, every cost component, source/observed-at metadata, completeness, missing inputs and `calculation_version`.

### Promotions

- `GET /v1/ml/promotions`
- `GET /v1/ml/listings/{item_id}/promotions`

No promotion mutation endpoint.

### Orders/margins

- `GET /v1/ml/orders`
- `GET /v1/ml/orders/{order_id}`
- `GET /v1/ml/orders/{order_id}/margin`
- `GET /v1/ml/orders/{order_id}/margin/history`

### Reports

- `GET /v1/reports/margins`
- `GET /v1/reports/summary`

These expose our value-added results, not wholesale raw ML payloads.

### Webhooks/sync

- `POST /v1/webhooks/mercadolivre`
- `POST /v1/admin/sync/ml`
- `GET /v1/admin/sync/status`

## 18. API authentication and authorization

Two trust domains remain separate:

1. Mercado Livre OAuth for seller-account access.
2. Our API credentials for clients consuming this service.

V1 client tokens are high-entropy opaque secrets stored only as hashes. Example scopes:

- `pricing:read`
- `orders:read`
- `costs:read`
- `costs:write`
- `admin:sync`

Every object lookup is tenant-scoped. Object IDs never override tenant identity.

## 19. Mercado Livre token security

- OAuth authorization code + PKCE.
- Access/refresh tokens encrypted at rest.
- Encryption key from protected runtime secret storage, never DB/repo.
- Per-account lock around refresh to avoid refresh-token races.
- Authorization/token fields redacted from logs/errors/fixtures.
- Token-health endpoints expose status/expiry only.
- Fail closed on account/token mismatch.

Reference: https://developers.mercadolivre.com.br/pt_br/publicacao-de-produtos/gestao-de-identidades-e-acessos-oauth-e-tokens

## 20. Webhook/event processing

1. Validate topic/resource syntax.
2. Persist sanitized notification and idempotency key transactionally.
3. Return HTTP 200 after durable acceptance.
4. Async worker parses the resource into an allowlisted resource type/ID.
5. Rebuild the request against the fixed ML API base; never concatenate a user-controlled absolute URL.
6. Fetch authoritative resource.
7. Normalize/update snapshots.
8. Enqueue dependent calculation/reconciliation jobs.
9. Bounded retry for retryable failures; exhausted work goes to dead-letter state/alerts.

Missed-feed repair uses persistent cursors and deterministic scheduled execution.

## 21. Rate limiting and resilience

Implement endpoint/account budgets rather than uncontrolled global concurrency.

Retry only:

- 429;
- selected 5xx/network failures where semantics are safe.

Policy:

- exponential backoff with jitter;
- honor `Retry-After` when present;
- bounded attempts;
- ordinary business/auth 4xx are not blindly retried;
- provider failures can open a circuit breaker;
- use official bulk resources and request coalescing where appropriate.

Reference: https://developers.mercadolivre.com.br/pt_br/usuarios-e-aplicativos/rate-limit-erro-429

## 22. Freshness targets

Initial operational targets:

- listing core/status <=60 min, hard maximum <=6h;
- price event-driven + repair, target <=15 min;
- promotions target <=30 min while active;
- pricing automation <=60 min and always refreshed before any future mutation;
- orders/shipments event-driven + repair sync;
- fee/shipping quote cache keyed by complete input fingerprint, short target TTL ~15 min;
- billing scheduled for post-sale reconciliation, not real-time quoting.

Every returned marketplace-derived object exposes `observed_at`/`synced_at`.

## 23. Olist/Tiny cost synchronization

Olist/Tiny is a seller-owned cost/SKU provider, not another marketplace in V1.

Adapter rules:

- official authenticated ERP API only;
- normalize SKU identity;
- do not overwrite higher-precedence approved manual override;
- changed cost creates a new effective-dated profile;
- retain source reference/observed timestamp;
- duplicate/unmatched SKU mapping becomes explicit review data, never an automatic guess.

The Pricing Engine only sees `CostCatalog`, not ERP transport.

## 24. Observability

Structured logs:

- correlation ID;
- internal tenant/account ID;
- ML endpoint family;
- status/latency/retry count;
- event/sync ID;
- margin snapshot/version.

Metrics:

- provider request rate/status/latency;
- 429/backoff;
- token-refresh failures;
- webhook accepted/duplicate/failed/dead-letter;
- source freshness lag;
- queue depth/oldest age;
- quote completeness rate;
- estimated-vs-actual and actual-vs-reconciled deltas.

No recurring paid AI monitoring process.

## 25. Stable error contract

```json
{
  "error": {
    "code": "MISSING_COST_PROFILE",
    "message": "No effective cost profile exists for the requested SKU.",
    "correlation_id": "...",
    "details": {}
  }
}
```

Representative codes:

- `ML_AUTH_REQUIRED`
- `ML_RATE_LIMITED`
- `ML_PROVIDER_UNAVAILABLE`
- `ML_ITEM_NOT_FOUND`
- `ML_UNSUPPORTED_CONTEXT`
- `MISSING_COST_PROFILE`
- `MISSING_TAX_PROFILE`
- `STALE_SOURCE_DATA`
- `QUOTE_INCOMPLETE`
- `TENANT_SCOPE_VIOLATION`

Raw provider bodies are not blindly exposed.

## 26. Determinism, decimal arithmetic and versioning

`PricingEngine` is a pure function over normalized input.

Persist:

- all numeric inputs;
- semantic source references;
- calculation version, e.g. `mlb-margin-v1`;
- allocation method;
- rounding policy;
- all outputs/completeness state.

Arithmetic uses decimal precision >=4 fractional places internally. BRL presentation/final monetary boundaries use a documented half-up rounding policy. Formula changes create new versions; historical snapshots remain unchanged.

## 27. Testing strategy

### Unit tests

Fixtures include:

- positive/zero/negative margin;
- quantity >1;
- missing cost/tax;
- fee with fixed component without double counting;
- zero/non-zero sender freight;
- standard promotion;
- ML-funded/boosted promotion;
- boost >= sale fee clamp/reconciliation flag;
- historical cost selection;
- actual order where `unit_price` already includes discount, proving no second seller-discount subtraction;
- multi-item shipment allocation;
- decimal/rounding boundaries.

### Contract tests

Sanitized official ML fixtures for:

- `/items/bulk`;
- item price resources;
- `listing_prices`;
- `shipping_options/free`;
- `seller-promotions`;
- `pricing-automation`;
- orders/discounts;
- shipments/costs;
- webhook notifications;
- billing integration.

No tokens or unnecessary buyer personal data in fixtures.

### Integration tests

Real DB tests for:

- tenant isolation;
- webhook idempotency;
- queue/dead-letter behavior;
- token refresh locking;
- effective-dated costs;
- shipment-cost deduplication/allocation;
- append-only snapshots/cursors.

### Live read-only smoke tests

Against the authorized seller account:

- OAuth/account identity;
- list + bulk item detail;
- current price;
- fee quote with logistics context;
- shipping quote;
- promotion context;
- recent order/discount/shipment data where available;
- one `ESTIMATED` and one `ACTUAL` snapshot;
- zero marketplace writes.

### Mercado Turbo parity matrix

Representative cases:

- Classic/Premium;
- catalog/non-catalog;
- Full/Flex/drop-off/other observed logistics;
- free/non-free seller shipping;
- positive/near-zero/negative margin;
- normal/promotional/boosted price;
- multi-unit/multi-item pack;
- pricing automation;
- legacy variation/User Product when available.

Each mismatch is classified as:

- our bug;
- stale/different inputs;
- documented semantic difference;
- Mercado Turbo discrepancy;
- unsupported/unverifiable.

Parity means explained economics, not blind numerical imitation.

## 28. Security tests

Mandatory:

- tenant A cannot read/enumerate tenant B;
- webhook resource cannot cause SSRF/arbitrary authenticated request;
- token/log redaction;
- encrypted token-at-rest assertion;
- API client secrets stored hashed;
- duplicate webhook idempotency;
- bounded retries/no retry storm;
- invalid item/order/SKU/pagination rejected;
- cost/tax writes require privileged scope + audit reason;
- no ML mutation routes exist in V1.

## 29. Data retention/privacy minimization

Buyer contact/identity fields are not needed for pricing and are not persisted by default.

Raw provider payload retention is minimized. When payload evidence is useful, store sanitized content or hashes with explicit retention rather than unbounded logs.

## 30. Deployment topology

Initial deployment may share existing Oracle infrastructure but remains operationally isolated:

- separate repository;
- separate release/application directory;
- separate secret namespace/environment;
- separate database/schema;
- separate worker/service;
- independent health endpoint;
- dedicated reverse-proxy hostname only after implementation review.

Use immutable releases/rollback consistent with ShopVivaLiz governance. No production deployment occurs during the design phase.

## 31. Legacy migration/cutover

No big-bang replacement.

1. New service syncs read-only independently.
2. Shadow-compare account/listing/order identity with legacy data.
3. Generate margin snapshots without affecting storefront/order flows.
4. Validate source completeness and parity.
5. Allow ShopVivaLiz to consume selected read endpoints only after stability.
6. Retire duplicate legacy ML transport/token code only under a separate reviewed cutover.

Existing checkout/order behavior remains untouched by V1.

## 32. V1 acceptance criteria

V1 is complete only when evidence shows:

- secure OAuth + refresh for one authorized MLB seller;
- all active listings synchronize through current official endpoints;
- User Product/variation IDs persist when present;
- current price contexts do not rely on deprecated item-price fields as canonical truth;
- fee quote uses required logistics context and cannot double-count fixed fee;
- shipping estimates use official quote API;
- promotions including 2026 boost fields normalize correctly;
- cost/tax effective dating works;
- deterministic quote API returns explainable breakdown/completeness;
- recent confirmed orders produce `ACTUAL` snapshots from actual order/sale-fee/shipment facts;
- discount attribution cannot double-count revenue reduction;
- unique shipment cost cannot be counted twice in packs/multi-item orders;
- at least one testable billing case can create immutable `RECONCILED` snapshot when billing data exists;
- webhook ingestion is durable/idempotent/asynchronous;
- missed-feed repair works;
- rate-limit backoff is bounded/tested;
- tenant-isolation/security tests pass;
- no ML mutation route is present/enabled;
- parity matrix exists and every discrepancy is classified;
- OpenAPI/unit/integration/live read-only smoke tests pass;
- repository/logs/fixtures contain no secrets/tokens.

## 33. Explicit V1 non-goals

- changing ML prices;
- joining/leaving/editing promotions;
- creating/editing ads;
- stock mutation;
- creating pricing automations;
- browser extension;
- Shopee/Amazon/other marketplace support;
- AI-driven pricing decisions;
- public commercial multi-tenant SaaS launch;
- replacing ShopVivaLiz checkout/order storage;
- reproducing all Mercado Turbo features;
- full returns/claims subsystem.

Refund/reversal effects that appear as official billing adjustments can affect `RECONCILED`, but comprehensive returns management remains another domain.

## 34. Future phases

### V2 — Safe ML mutations
Separate design required. Must include simulation, dynamic-pricing/promotion constraints, min/max guardrails, review/approval, idempotent writes and read-after-write verification.

### V3 — Decision policies
Margin-floor/target rules propose actions first; execution remains separately governed.

### V4 — Commercial SaaS
Multi-customer onboarding, roles/billing, retention/privacy and Mercado Livre Partner/Developer compliance review.

### V5 — Optional browser companion
Thin UI layer consuming our own API; never the financial source of truth.

## 35. Official reference set

- Selling costs: https://developers.mercadolivre.com.br/pt_br/comissao-por-vender
- Product prices: https://developers.mercadolivre.com.br/pt_br/api-de-precos
- Shipping quote/cost semantics: https://developers.mercadolivre.com.br/pt_br/mercadolideres-lojas-oficiais/mercado-envios-custos-e-cotacoes
- Shipment actual costs: https://developers.mercadolivre.com.br/pt_br/gerenciamento-de-envios
- Promotions: https://developers.mercadolivre.com.br/pt_br/gerenciar-ofertas
- Price automation: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/automatizacoes-de-precos
- User Products/variation price: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/preco-variacao
- Items/bulk migration: https://developers.mercadolivre.com.br/pt_br/convivencia-me1-me2/itens-e-buscas
- Orders/discounts: https://developers.mercadolivre.com.br/pt_br/busca-de-produtos-por-vendedor/gerenciamento-de-vendas
- Notifications: https://developers.mercadolivre.com.br/produto-receba-notificacoes
- OAuth/token security: https://developers.mercadolivre.com.br/pt_br/publicacao-de-produtos/gestao-de-identidades-e-acessos-oauth-e-tokens
- Rate limits: https://developers.mercadolivre.com.br/pt_br/usuarios-e-aplicativos/rate-limit-erro-429
- Billing practices: https://developers.mercadolivre.com.br/pt_br/boas-praticas-para-o-consumo-das-apis-de-relatorios-de-faturamento
- Developer Terms: https://developers.mercadolivre.com.br/pt_br/termos-e-condicoes

## 36. Design self-review

- No `TBD`/`TODO` placeholders.
- V1 is Mercado Livre only and read-only against marketplace state.
- Runtime has no Mercado Turbo dependency.
- Current 2026 ML price/bulk/User Product/logistics fee/promotion boost/pricing-automation semantics are represented.
- `ESTIMATED`, `ACTUAL`, `RECONCILED` are distinct and immutable.
- Seller discounts cannot be subtracted twice from already-discounted order revenue.
- Shipment cost cannot be counted twice across packs/orders.
- Missing data cannot silently become a reliable margin.
- Tenant/account isolation is present from first schema.
- OAuth/token/webhook SSRF boundaries are explicit.
- Queue/retry behavior is bounded/deterministic.
- No recurring paid AI dependency.
- Legacy cutover is outside V1.
- Public commercialization remains behind compliance review.
