# Mercado Livre Pricing & Margin API — Design

**Date:** 2026-09-06  
**Status:** Architecture approved; written specification pending user review  
**Initial marketplace scope:** Mercado Livre Brazil (`MLB`) only  
**Planned implementation repository:** `Vivaliz-site/ml-pricing-api` (created only after this specification is approved)

## 1. Goal

Build an isolated, API-first, auditable service that calculates and explains contribution margin for Mercado Livre listings, promotions and sales using official Mercado Livre APIs plus seller-owned cost/tax data.

The first version must reproduce the economically useful core observed in Mercado Turbo without depending on Mercado Turbo at runtime and without copying its proprietary code, UI, assets or private API implementation.

The service must distinguish three financial states:

1. `ESTIMATED`: pre-sale quote using current item, price, promotion, fee, shipping and seller cost assumptions.
2. `ACTUAL`: post-sale calculation using the confirmed order, applied discounts and actual shipment charge when available.
3. `RECONCILED`: post-billing result after later Mercado Livre credits/debits/adjustments have been reconciled.

## 2. Non-negotiable rules

- Mercado Livre official APIs are the production source of truth for marketplace facts.
- Mercado Turbo is a benchmark/oracle for parity testing only; no production dependency on its endpoints, extension, tokens or cached data.
- Do not copy Mercado Turbo source code, selectors, visual assets or proprietary implementation. Reimplement behavior independently from official API contracts and seller-owned data.
- V1 is read-only against Mercado Livre. It may write internal cost/tax/configuration data, but it must not change an ML item price, promotion, stock, automation or campaign.
- Existing repository policy that protects marketplace prices remains in force.
- Every monetary result must be explainable from persisted source facts and a versioned deterministic formula.
- Never silently substitute a guessed fee, freight, tax, promotion contribution or historical cost when the authoritative input is missing.
- Missing inputs produce an explicit completeness state, not a fabricated margin.
- `1 MLB = 1 SKU = 1 price` must never be assumed. The model must support User Products, conditions of sale and legacy variations.
- All seller/account-scoped rows carry `tenant_id` and `ml_account_id` from day one, even though the first deployment enables one tenant/account.
- OAuth credentials/tokens are encrypted at rest, access-restricted and never logged.
- All timestamps are stored in UTC; API responses include ISO-8601 UTC timestamps.
- All currency values are stored as decimal amounts, never binary floats.

## 3. Compliance boundary

The application is a value-added seller-management and profitability product, not a generic resale/proxy of Mercado Livre's API.

Before any public/commercial multi-customer launch, a dedicated compliance gate is mandatory because current Mercado Livre Developer Terms include restrictions on scraping, redistribution/sublicensing of the API, applications that operate substantially like the API, and publication/use of certain derived performance statistics without express authorization.

V1 development rules:

- no scraping of Mercado Livre for facts already available through the Developer Program;
- no bypass of API rate limits or technical restrictions;
- refresh Mercado Livre content at least within the freshness required by current Developer Terms (general content <=24h, item listings <=6h), while operational data should normally be substantially fresher;
- no third-party exposure of raw ML credentials/tokens;
- no raw pass-through endpoint whose primary product is simply redistributing Mercado Livre API responses;
- public/commercial reporting of derived marketplace statistics is blocked until Partner/Developer compliance is reviewed and, where required, explicit authorization is obtained.

Reference: https://developers.mercadolivre.com.br/pt_br/termos-e-condicoes

## 4. Clean-room parity strategy

Observed Mercado Turbo behavior may be used to define black-box test expectations for seller-owned cases. The implementation itself must be derived from:

- official Mercado Livre documentation and responses;
- seller-owned Olist/Tiny/cost/tax data;
- deterministic formulas defined in this specification;
- independently written adapters and tests.

Allowed benchmark comparison:

`same seller case -> Mercado Turbo visible result vs our result vs official Mercado Livre source facts`

If Mercado Turbo differs from official source facts, official Mercado Livre data and documented semantics win, and the discrepancy becomes a regression fixture with an explanation.

## 5. Source hierarchy

### 5.1 Marketplace facts

For a pre-sale estimate:

`ML_PRICE_API / seller-promotions / listing_prices / shipping_options > item fallback metadata`

For a completed sale:

`ORDER + ORDER_DISCOUNTS + SHIPMENT_COSTS > pre-sale quote`

For financial reconciliation:

`BILLING_INTEGRATION > operational estimate`

### 5.2 Seller-owned cost facts

`explicit approved internal override > validated Olist/Tiny synchronization > missing`

Historical calculations select the cost profile effective at the economic event timestamp; later cost changes never rewrite old margin history.

### 5.3 Tax facts

Tax is seller-owned configuration and is not presented as legal/tax advice. A versioned tax profile defines its rate and calculation basis. If the profile is absent, tax completeness is `MISSING`; the engine does not invent a default rate.

## 6. Relevant official Mercado Livre APIs

V1 adapters are designed around current 2026 contracts:

- Seller items: `GET /users/{USER_ID}/items/search`
- Bulk item detail: `GET /items/bulk?ids=...`
- Price contexts: `GET /items/{ITEM_ID}/prices` and current sale-price resources
- Price notifications: topic `items_prices`
- Selling fee quote: `GET /sites/MLB/listing_prices` with category, price, currency, listing type, `logistic_type` and shipping mode
- Shipping quote: `GET /users/{USER_ID}/shipping_options/free`
- Promotions: `/seller-promotions`
- Pricing automation discovery: `/pricing-automation/users/{USER_ID}/items` and item automation resources
- Orders: `/orders` and `/orders/{ORDER_ID}`
- Order discounts: `GET /orders/{ORDER_ID}/discounts`
- Shipment actual cost: `GET /shipments/{SHIPMENT_ID}/costs`
- Notifications: `orders_v2`, `items`, `shipments`, `items_prices` as applicable
- Missed notifications recovery: `/missed_feeds`
- Billing reconciliation: `/billing/integration/...`

The old multi-get `/items?ids=` and `/users?ids=` are not used. Current ML documentation requires migration to `/items/bulk?ids=` and `/users/bulk?ids=` by 2026-10-25.

## 7. Architecture decision

Use an isolated modular monolith, not a microservice fleet and not an extension-first product.

```text
                    +---------------------------+
                    | Mercado Livre OAuth 2.0   |
                    +-------------+-------------+
                                  |
                    +-------------v-------------+
                    | MercadoLivre Adapter      |
                    | HTTP / retry / rate limit |
                    +-------------+-------------+
                                  |
             +--------------------+--------------------+
             |                    |                    |
      +------v------+      +------v------+      +------v------+
      | Sync        |      | Webhook     |      | Cost/Tax    |
      | Orchestrator|      | Ingestion   |      | Providers   |
      +------+------+      +------+------+      +------+------+ 
             |                    |                    |
             +--------------------+--------------------+
                                  |
                        +---------v---------+
                        | Canonical Store   |
                        | snapshots/events  |
                        +---------+---------+
                                  |
                        +---------v---------+
                        | Pricing Engine    |
                        | deterministic     |
                        +---------+---------+
                                  |
              +-------------------+-------------------+
              |                   |                   |
       +------v------+      +-----v------+      +-----v------+
       | REST API    |      | Reports    |      | Reconcile  |
       | seller ops  |      | summaries  |      | billing    |
       +-------------+      +------------+      +------------+
```

### Why this shape

- isolated enough to become a SaaS later;
- simple enough to deploy and debug on the existing Oracle infrastructure;
- one transactional database makes snapshots, event ingestion, idempotency and margin calculation auditable;
- bounded modules can later be extracted only if traffic/ownership justifies it;
- no browser DOM dependency for the core product.

## 8. Technology stack

Planned new service baseline:

- PHP 8.3+
- Symfony 7.4 LTS components/application structure
- MySQL 8.0+ dedicated logical database
- Doctrine DBAL/ORM migrations
- Symfony HttpClient for Mercado Livre/Olist/Tiny adapters
- Symfony Messenger with a database-backed durable transport in V1
- PHPUnit for unit/integration/contract tests
- Nginx + PHP-FPM
- systemd worker service for Messenger consumers
- systemd timer or bounded cron entry for reconciliation/scheduled sync
- OpenAPI 3.1 generated/validated from the HTTP contract

Redis is intentionally not mandatory in V1. Add it only if measured queue/cache load makes the database transport insufficient.

The service repository is separate from `site-shopvivaliz`; the existing site may later consume it through its REST API. There are no cross-repository database joins and no foreign keys into the legacy ShopVivaLiz schema.

## 9. Existing ShopVivaLiz code: reuse and retirement boundary

Current useful assets:

- existing Mercado Livre OAuth/PKCE concepts;
- known runtime secret/deploy mechanisms;
- current `orders_v2` webhook knowledge;
- existing Olist/Tiny integration knowledge and credentials pipeline.

Current code that must not become the new core:

- duplicated ML auth/client implementations in `api/ml/client.php` and `api/ml/_bootstrap.php`;
- JSON-file token persistence as the long-term multi-account token store;
- synchronous business processing inside the webhook request;
- `storage/orders/*.json` as the canonical profitability database;
- local fallback product catalogs as an ML listing source.

The new service gets one authentication implementation, one ML transport, one canonical database and a durable event-processing path.

## 10. Module boundaries

### `Identity/Tenant`
Owns tenants, API clients and account scoping. No business query may execute without an explicit `tenant_id` boundary.

### `MercadoLivreAuth`
Owns OAuth authorization code + PKCE, token refresh, encrypted token persistence and per-account authorization status.

### `MercadoLivreClient`
Owns HTTP transport only:

- base URL allowlist;
- Authorization header;
- timeouts;
- retry classification;
- exponential backoff with jitter for retryable failures/429;
- concurrency budget;
- request/response telemetry with secrets and sensitive fields redacted.

Business modules never call arbitrary ML URLs directly.

### `ListingSync`
Discovers seller items, fetches bulk details, User Product identifiers, listing/shipping metadata, current price contexts and pricing-automation status.

### `PromotionSync`
Normalizes `/seller-promotions` and per-item offers, including seller contribution and ML-funded/boost fields.

### `OrderSync`
Normalizes orders, packs where applicable, order items, applied discounts and shipment IDs.

### `ShippingService`
Provides two explicit contracts:

- `quote()` -> pre-sale shipping estimate;
- `actual()` -> seller charge from `/shipments/{id}/costs`, using `senders[].cost` as the actual seller shipping charge.

### `FeeService`
Calls current `listing_prices` with the full relevant logistics context. `sale_fee_amount` is treated as a total official selling-cost result; fixed and percentage components may be stored when explicitly returned but are never double-counted.

### `CostCatalog`
Owns SKU cost profiles, packaging/other seller costs and effective dating. Olist/Tiny is an adapter feeding this module, not a direct dependency of the Pricing Engine.

### `TaxCatalog`
Owns versioned seller tax profiles and taxable-basis policy.

### `PricingEngine`
Pure deterministic domain engine. It receives normalized inputs and emits a complete calculation result; it performs no network or database calls.

### `Reconciliation`
Matches post-sale billing adjustments to order/order-item economics without rewriting original `ESTIMATED` or `ACTUAL` snapshots.

### `WebhookIngestion`
Persists notification envelope first, returns HTTP 200 after durable acceptance, and processes the resource asynchronously. Duplicate deliveries are idempotent.

### `Audit`
Records configuration changes, internal writes, source versions and calculation versions. Raw tokens/secrets are never audit payloads.

## 11. Canonical data model

All business tables include `tenant_id`, and marketplace-owned rows also include `ml_account_id`.

### `tenants`
- `id BINARY(16) PRIMARY KEY`
- `name VARCHAR(160)`
- `status VARCHAR(24)`
- `created_at`, `updated_at`

### `api_clients`
- `id`, `tenant_id`, `name`
- `token_hash` or public-key reference
- `scopes_json`
- `last_used_at`, `revoked_at`, timestamps

### `ml_accounts`
- `id`, `tenant_id`
- `seller_id BIGINT`
- `site_id VARCHAR(8) DEFAULT 'MLB'`
- `nickname`
- `token_ciphertext`, `refresh_token_ciphertext`
- `token_expires_at`
- `oauth_status`
- `metadata_json`
- timestamps
Unique `(tenant_id, seller_id, site_id)`.

### `ml_listings`
- `id`, `tenant_id`, `ml_account_id`
- `item_id VARCHAR(32)`
- `user_product_id VARCHAR(64) NULL`
- `family_id VARCHAR(64) NULL`
- `catalog_product_id VARCHAR(64) NULL`
- `catalog_listing BOOLEAN`
- `category_id VARCHAR(32)`
- `listing_type_id VARCHAR(32)`
- `status VARCHAR(32)`
- `condition_code VARCHAR(24)`
- `seller_sku VARCHAR(191) NULL`
- `currency_id VARCHAR(8)`
- `shipping_mode VARCHAR(32) NULL`
- `logistic_type VARCHAR(64) NULL`
- `free_shipping BOOLEAN`
- `dimensions_raw VARCHAR(128) NULL`
- `source_updated_at`, `synced_at`, timestamps
Unique `(ml_account_id, item_id)`.

### `ml_listing_variants`
Supports legacy variation IDs and future condition-specific association without assuming one SKU per item.

- `id`, `listing_id`
- `variation_id VARCHAR(64) NULL`
- `user_product_id VARCHAR(64) NULL`
- `seller_sku VARCHAR(191) NULL`
- `attributes_json`
- timestamps

### `price_snapshots`
- `id`, `listing_id`
- `context_key VARCHAR(191)`
- `amount DECIMAL(14,2)`
- `regular_amount DECIMAL(14,2) NULL`
- `currency_id`
- `promotion_id NULL`
- `source VARCHAR(32)`
- `source_observed_at`
- `payload_hash CHAR(64)`
- `created_at`
Unique on a source/payload idempotency key rather than timestamp alone.

### `pricing_automation_snapshots`
- listing reference
- `status`
- `rule_id`
- `min_price`, `max_price`
- `status_cause`
- `observed_at`

### `sku_cost_profiles`
- `id`, `tenant_id`
- `sku VARCHAR(191)`
- `unit_cost DECIMAL(14,4)`
- `packaging_cost DECIMAL(14,4) DEFAULT 0`
- `other_variable_cost DECIMAL(14,4) DEFAULT 0`
- `currency_id`
- `source VARCHAR(32)` (`MANUAL`, `OLIST`, `TINY`)
- `source_reference NULL`
- `valid_from DATETIME`
- `valid_to DATETIME NULL`
- `approved_at`, `approved_by NULL`
- timestamps
No overlapping effective periods for the same tenant/SKU/source precedence layer.

### `tax_profiles`
- `id`, `tenant_id`
- `profile_key`
- `rate DECIMAL(9,6)`
- `basis VARCHAR(32)` (`GROSS_REVENUE` initially)
- `valid_from`, `valid_to`
- `source`, audit metadata

### `listing_tax_assignments`
Maps listing/SKU to the effective tax profile without embedding tax into listing metadata.

### `promotion_snapshots`
- `id`, `listing_id`
- `promotion_id`, `promotion_type`, `status`
- seller offer price fields
- `meli_percentage NULL`
- `seller_percentage NULL`
- `boosted_offer BOOLEAN`
- `discount_meli_boosted_percentage NULL`
- `discount_meli_boost_amount DECIMAL(14,2) NULL`
- `total_price_for_boosted_offer DECIMAL(14,2) NULL`
- start/end timestamps
- source payload hash, observed timestamp

### `fee_quotes`
Stores the official input context and returned selling cost:

- listing/category/listing type
- quoted price
- shipping mode/logistic type
- `sale_fee_amount DECIMAL(14,2)`
- optional returned breakdown JSON
- observed timestamp
- source payload hash

### `shipping_quotes`
- listing reference
- price/listing/logistics/dimensions input fingerprint
- seller estimated cost
- source response hash
- observed timestamp

### `orders`
- `order_id BIGINT`
- tenant/account
- status
- pack id where applicable
- total amount/currency
- date created/closed/last updated
- shipment id where applicable
- source timestamps
Unique `(ml_account_id, order_id)`.

### `order_items`
- order reference
- `item_id`, `variation_id`, `user_product_id`, `seller_sku`
- quantity
- unit price
- official sale fee when present
- seller-funded discount amount
- ML-funded discount amount when determinable
- source payload hash

### `shipment_cost_snapshots`
- shipment id/order reference
- seller cost from `senders[].cost`
- receiver cost
- gross amount
- discounts JSON
- observed timestamp

### `billing_adjustments`
- tenant/account/order/order-item references when resolvable
- external document/charge/credit identity
- adjustment type
- amount/currency
- occurred_at
- source payload hash/idempotency key

### `margin_snapshots`
Immutable calculation result:

- `id`, tenant/account
- `subject_type` (`LISTING`, `PROMOTION`, `ORDER`, `ORDER_ITEM`)
- `subject_id`
- `state` (`ESTIMATED`, `ACTUAL`, `RECONCILED`)
- `quantity`
- `gross_revenue`
- `product_cost`
- `packaging_cost`
- `other_variable_cost`
- `tax_amount`
- `sale_fee_gross`
- `meli_fee_reduction`
- `sale_fee_net`
- `seller_shipping_cost`
- `seller_discount_cost`
- `billing_adjustment_net`
- `contribution_margin`
- `contribution_margin_pct`
- `completeness_status`
- `missing_inputs_json`
- `source_refs_json`
- `calculation_version`
- `calculated_at`

Snapshots are append-only. Recalculation creates a new snapshot.

### `webhook_events`
- ML notification identity or deterministic hash
- topic/resource/user/application/attempt timestamps
- raw sanitized envelope JSON
- status (`ACCEPTED`, `PROCESSING`, `SUCCEEDED`, `FAILED`, `DEAD_LETTER`)
- attempts/next_attempt_at/last_error
- created/updated timestamps

### `sync_cursors`
Per tenant/account/source high-water marks, including periodic reconciliation cursors and missed-feed recovery.

### `audit_log`
Append-only administrative/business audit entries with actor, action, target, reason, before/after hashes and timestamp.

## 12. Margin semantics

### 12.1 Canonical percentage

`contribution_margin_pct = contribution_margin / gross_revenue * 100`

If `gross_revenue <= 0`, percentage is `null`, not zero.

### 12.2 Estimated margin

Conceptually:

`gross_revenue`
`- product_cost`
`- packaging_cost`
`- other_variable_cost`
`- tax_amount`
`- sale_fee_net`
`- seller_shipping_cost`
`- seller_discount_cost`
`= contribution_margin`

Where:

`sale_fee_net = max(0, sale_fee_gross - documented_meli_fee_reduction)` for pre-sale boost cases unless an official source explicitly defines a different net treatment.

The engine must prevent double counting when an ML endpoint already returns a net cost.

### 12.3 Promotion boost rule

Current ML promotion APIs may return `boosted_offer`, `discount_meli_boosted_percentage`, `discount_meli_boost_amount` and `total_price_for_boosted_offer` for applicable campaign types.

The ML-funded boost is represented as a selling-cost reduction, not additional sales revenue. The estimate therefore preserves buyer-facing revenue and records the ML benefit in `meli_fee_reduction`.

If the documented benefit exceeds the gross sale fee, the pre-sale engine clamps net fee to zero and marks the quote `REQUIRES_ACTUAL_RECONCILIATION`; it does not invent a negative fee/credit. A later confirmed credit can exist only through `ACTUAL`/`RECONCILED` official facts.

### 12.4 Tax basis

V1 supports `GROSS_REVENUE` as the initial basis, but the basis is a profile attribute so the formula is explicit and versioned. The engine does not encode Brazilian tax law implicitly.

### 12.5 Completeness

Possible statuses:

- `COMPLETE`
- `MISSING_COST`
- `MISSING_TAX_PROFILE`
- `MISSING_SHIPPING_QUOTE`
- `MISSING_FEE_QUOTE`
- `STALE_MARKETPLACE_DATA`
- `REQUIRES_ACTUAL_RECONCILIATION`
- `UNSUPPORTED_CONTEXT`

A quote may contain a partial breakdown but must never label an incomplete result as a reliable final margin.

## 13. API contract V1

All endpoints are versioned under `/v1`. JSON only. Monetary values are serialized as decimal strings to preserve precision.

### Health and account

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

These mutate only our internal configuration and require an audit reason and privileged scope.

### Quotes

- `POST /v1/pricing/quote`
- `POST /v1/pricing/quotes/batch`

Single quote request supports item/UP/variation context, quantity, optional candidate price and optional promotion identity.

Response includes:

- requested/effective price;
- every cost component;
- source and `observed_at` per marketplace-derived component;
- completeness and missing inputs;
- `calculation_version`;
- `ESTIMATED` state.

### Promotions

- `GET /v1/ml/promotions`
- `GET /v1/ml/listings/{item_id}/promotions`
- `POST /v1/pricing/promotion-quote`

No promotion write endpoint in V1.

### Orders and actual margin

- `GET /v1/ml/orders`
- `GET /v1/ml/orders/{order_id}`
- `GET /v1/ml/orders/{order_id}/margin`
- `GET /v1/ml/orders/{order_id}/margin/history`

### Reports

- `GET /v1/reports/margins`
- `GET /v1/reports/summary`

Reports are tenant/account scoped and return our derived business results, not raw wholesale redistribution of ML API payloads.

### Sync and webhooks

- `POST /v1/webhooks/mercadolivre`
- `POST /v1/admin/sync/ml` (bounded manual sync trigger)
- `GET /v1/admin/sync/status`

## 14. Authentication and authorization

Two separate trust domains:

1. Mercado Livre OAuth for access to a seller account.
2. Our API authentication for users/services consuming the Pricing API.

V1 API uses opaque high-entropy client tokens stored only as hashes, with scopes such as:

- `pricing:read`
- `orders:read`
- `costs:read`
- `costs:write`
- `admin:sync`

All authorization checks include tenant scope. A valid token from tenant A can never select tenant B through an object identifier.

## 15. Token security

Mercado Livre credentials follow current official security guidance:

- encrypt Client Secret/access/refresh tokens at rest;
- encryption key comes from protected runtime secret storage, not the database/repository;
- never log `Authorization`, refresh tokens or raw credential payloads;
- refresh under a per-account lock to avoid refresh-token races;
- fail closed on token-account mismatch;
- token health endpoint exposes status/expiry only, never secret material.

Reference: https://developers.mercadolivre.com.br/pt_br/publicacao-de-produtos/gestao-de-identidades-e-acessos-oauth-e-tokens

## 16. Webhook/event processing

Webhook request path:

1. validate envelope shape and allowlisted topic/resource syntax;
2. persist sanitized event/idempotency key transactionally;
3. return HTTP 200 after durable acceptance;
4. Messenger consumer fetches the authoritative resource through `MercadoLivreClient`;
5. normalize/upsert snapshots;
6. enqueue dependent calculation/reconciliation work;
7. mark event success or schedule bounded retry;
8. exhausted events become dead letters and alertable.

No access token is ever attached to a user-controlled absolute URL. `resource` is parsed into known resource types/IDs and rebuilt against the allowlisted ML API base.

Missed-feed recovery runs as a bounded scheduled job and uses per-topic cursors. It is deterministic and uses no paid AI.

## 17. Rate limiting and resilience

Implement per-endpoint and per-account concurrency budgets, not one unbounded global retry loop.

Retryable:

- 429;
- selected 5xx/network timeouts where request semantics are safe.

Policy:

- exponential backoff with jitter;
- honor `Retry-After` when supplied;
- bounded attempts;
- no retry of ordinary 4xx business/authorization errors;
- circuit-break noisy endpoints after repeated provider failure;
- request coalescing/cache where semantics permit;
- use official bulk endpoints when available.

Reference: https://developers.mercadolivre.com.br/pt_br/usuarios-e-aplicativos/rate-limit-erro-429

## 18. Cache and freshness

The database stores source snapshots; it is not a license to serve indefinitely stale marketplace facts.

Initial freshness targets:

- listing core/status: <= 60 minutes during active operation, hard maximum <= 6 hours;
- current price: event-driven via `items_prices` plus periodic repair, target <= 15 minutes;
- promotions: target <= 30 minutes while active;
- pricing automation status: <= 60 minutes and refreshed before any future price mutation;
- order/shipment changes: event-driven plus repair sync;
- fee/shipping quote: cached only by complete input fingerprint and short TTL (target 15 minutes) because price/logistics changes alter the answer;
- billing: scheduled post-sale reconciliation, not a real-time operational source.

Every API object includes `observed_at`/`synced_at` sufficient to assess staleness.

## 19. Pricing automation guard

Even though V1 is read-only, automation state is collected now because it changes the meaning and future mutability of price.

Current ML documentation states that since 2026-03-18 price updates via `/items/{id}` are rejected for items with dynamic pricing automation active.

Future mutation code must therefore have a hard pre-write gate:

`discover automation -> discover promotion constraints -> simulate -> review/approval -> write through the correct ML contract -> verify read-after-write`

A generic `PUT /items price` loop is explicitly forbidden.

References:
- https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/automatizacoes-de-precos
- https://developers.mercadolivre.com.br/pt_br/automatizacoes-de-precos

## 20. User Products / variation compatibility

Current ML migration enables different sale conditions for variants through User Products. The service therefore resolves profitability at the narrowest available sale condition:

`tenant -> ML account -> listing/item -> User Product / variation -> SKU -> price/promotion context`

The canonical model stores nullable identifiers rather than assuming legacy or new model exclusively.

Reference: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/preco-variacao

## 21. Olist/Tiny cost synchronization

Olist/Tiny is not a second marketplace in V1; it is an optional seller-owned source for cost/SKU metadata.

Adapter responsibilities:

- fetch product/SKU cost data through official authenticated ERP API;
- normalize SKU identifiers;
- never overwrite a higher-precedence approved manual override;
- open a new effective-dated cost profile when a value changes;
- retain source reference and observed timestamp;
- report unmatched/duplicate SKU mappings instead of guessing.

Pricing Engine sees only `CostCatalog`, never an Olist/Tiny HTTP client.

## 22. Observability

Structured logs:

- request/correlation ID;
- tenant/account ID (non-secret internal ID);
- ML endpoint family;
- HTTP status, latency, retry count;
- sync/event IDs;
- calculation snapshot ID/version.

Metrics:

- ML request rate/status/latency by endpoint;
- 429 count and backoff time;
- OAuth refresh success/failure;
- webhook accepted/duplicate/failed/dead-letter count;
- source freshness lag;
- queue depth/oldest age;
- quote completeness rate;
- estimated-vs-actual and actual-vs-reconciled deltas.

Alerts are deterministic. No recurring paid AI process is part of runtime monitoring.

## 23. Error contract

REST errors use a stable envelope:

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

Provider errors are translated into our domain codes; raw provider bodies are not blindly exposed to API clients.

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

## 24. Determinism and calculation versioning

`PricingEngine` is a pure function over a normalized input object.

The result stores:

- all numeric inputs;
- semantic source references;
- `calculation_version` such as `mlb-margin-v1`;
- rounding policy;
- output components.

Rounding:

- internal arithmetic uses decimal precision >=4 fractional places;
- official monetary source amounts are preserved at their returned precision;
- public BRL totals round to 2 decimals using a single documented half-up policy only at presentation/final monetary boundaries;
- no intermediate binary float arithmetic.

A formula change creates a new version. Historical snapshots are not mutated.

## 25. Testing strategy

### Unit tests

Pure engine fixtures for:

- positive/zero/negative margins;
- quantity >1;
- missing cost/tax;
- fixed/percentage selling cost returned by ML;
- seller shipping zero/non-zero;
- promotion with no ML funding;
- co-funded promotion;
- boosted offer where benefit < fee;
- boosted benefit >= fee clamp/reconciliation flag;
- historical cost selection;
- rounding boundaries.

### Contract tests

Saved sanitized official ML fixtures for:

- `/items/bulk`;
- `/items/{id}/prices`;
- `listing_prices`;
- `shipping_options/free`;
- `seller-promotions`;
- `pricing-automation`;
- orders/discounts;
- shipments/costs;
- notification envelopes;
- billing integration.

Fixtures contain no access tokens, buyer private data or unnecessary personal information.

### Integration tests

Real database transactions verify tenant isolation, idempotency, queue retries, token refresh locking, cursor behavior and append-only snapshots.

### Live read-only smoke tests

Against the authorized seller account:

- authenticate `/users/me` equivalent through the new client;
- list items and bulk detail;
- fetch at least one price, fee, shipping quote and promotion context;
- fetch recent order/discount/shipment data where available;
- generate an `ESTIMATED` and an `ACTUAL` snapshot without marketplace writes.

### Mercado Turbo parity matrix

Use seller-owned examples across:

- Classic/Premium;
- catalog/non-catalog;
- Full/Flex/drop-off/other observed logistics;
- free and seller-paid shipping;
- positive/near-zero/negative margin;
- regular price and promotion;
- ML-funded/boosted offer;
- multi-unit/order pack;
- dynamic pricing automation;
- legacy item and User Product when available.

Acceptance is not "equal to Mercado Turbo at any cost". Each mismatch must be classified as:

- our bug;
- stale/different inputs;
- different documented semantics;
- Mercado Turbo discrepancy;
- unsupported/unverifiable.

## 26. Security tests

Mandatory tests include:

- tenant A cannot enumerate/read tenant B objects;
- webhook `resource` cannot cause SSRF or arbitrary-path authenticated requests;
- log redaction for Authorization/access/refresh tokens;
- encrypted token-at-rest assertion;
- API client tokens only hashed;
- idempotent duplicate webhook delivery;
- bounded retries and no retry storm;
- invalid `item_id`, order ID, SKU and pagination inputs rejected;
- cost/tax writes require privileged scope and audit reason;
- no Mercado Livre mutation routes exist in V1.

## 27. Data retention and privacy minimization

Store only marketplace/seller fields needed for pricing, reconciliation and audit. Buyer personal/contact/billing identity is not required for the pricing engine and should not be persisted by default.

Raw source payload retention is minimized. Where full payload retention is useful for audit, store sanitized payloads or content hashes with explicit retention rather than indefinite unbounded logs.

## 28. Deployment topology

Initial deployment may share the existing Oracle VM/control plane, but it is operationally isolated:

- separate repository;
- separate application directory/release artifact;
- separate environment file/secret namespace;
- separate database/schema;
- separate PHP-FPM pool/service where practical;
- dedicated worker systemd unit;
- independent health endpoint;
- reverse-proxy hostname such as `pricing-api.shopvivaliz.com.br` only after implementation review.

The service must support immutable releases and rollback without modifying the currently active release directory, consistent with ShopVivaLiz repository governance.

No production deployment occurs as part of this design phase.

## 29. Migration/cutover from legacy ML code

No big-bang replacement.

1. New service starts read-only and independently syncs ML.
2. Shadow compare OAuth/account identity, listings and recent orders with legacy data.
3. Generate margin snapshots without affecting storefront/order flows.
4. Validate parity and data completeness.
5. Let existing ShopVivaLiz consume selected read endpoints only after stability.
6. Retire duplicate legacy ML transport/token paths only in a separately reviewed migration.

The existing checkout/order integration remains untouched by V1 until an explicit cutover spec is approved.

## 30. Acceptance criteria for V1

V1 is complete only when all of the following are evidenced:

- one authorized MLB seller account connects through secure OAuth and refreshes tokens correctly;
- all active seller listings can be synchronized through current official endpoints;
- User Product/variation identifiers are retained when present;
- current price contexts are synchronized without relying on deprecated `/items.price` as the canonical source;
- fee quotes include the required logistics context;
- shipping estimates are sourced from official ML quote API;
- promotion offers including 2026 boost fields are normalized;
- cost/tax profile effective dating works;
- deterministic quote API returns a complete explainable breakdown;
- recent confirmed orders can produce `ACTUAL` snapshots from order/discount/shipment facts;
- billing reconciliation can create a later immutable `RECONCILED` snapshot for at least one testable case when billing data exists;
- webhook delivery is durably persisted/idempotent and processed asynchronously;
- missed-feed repair path is tested;
- rate-limit backoff is bounded and tested;
- tenant-isolation tests pass;
- no ML write endpoint is present/enabled;
- Mercado Turbo parity matrix has representative cases and every discrepancy is classified;
- OpenAPI contract, unit tests, integration tests and live read-only smoke tests pass;
- secrets/tokens do not appear in repository, test fixtures or logs.

## 31. Explicit non-goals for V1

- changing ML prices;
- joining/leaving/editing promotions;
- creating/editing ML ads;
- stock mutation;
- creating pricing automations;
- browser extension;
- Shopee/Amazon/other marketplace support;
- AI-driven pricing decisions;
- public multi-tenant commercial SaaS launch;
- replacing ShopVivaLiz checkout/order storage;
- reproducing all Mercado Turbo features.

## 32. Future phases after V1

### V2 — Safe price/promotion actions
Only after parity and read-model reliability are proven. Requires a separate design with simulation, min/max guardrails, dynamic-pricing detection, human approval/review, idempotent ML writes and read-after-write verification.

### V3 — Decision policies
Rules such as margin floors/targets can propose actions. Initially proposals only; action execution remains approval-gated.

### V4 — SaaS commercialization
Multi-customer onboarding, tenant billing/roles, Partner/Developer compliance review, retention/privacy controls and commercial observability.

### V5 — Optional browser companion
A thin extension may display our own API results inside Seller Central, but it remains a presentation layer and never becomes the financial source of truth.

## 33. Official reference set used for the design

- Selling costs / `listing_prices`: https://developers.mercadolivre.com.br/pt_br/comissao-por-vender
- Product prices: https://developers.mercadolivre.com.br/pt_br/api-de-precos
- Shipping costs: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/custos-de-envio
- Shipping FAQs/cost semantics: https://developers.mercadolivre.com.br/pt_br/mercadolideres-lojas-oficiais/mercado-envios-custos-e-cotacoes
- Promotions: https://developers.mercadolivre.com.br/pt_br/gerenciar-ofertas
- Price automation: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/automatizacoes-de-precos
- Seller automation listing: https://developers.mercadolivre.com.br/pt_br/atributos/automatizacoes-de-precos
- User Products / price by variation: https://developers.mercadolivre.com.br/pt_br/guia-para-produtos/preco-variacao
- Items and bulk migration: https://developers.mercadolivre.com.br/pt_br/convivencia-me1-me2/itens-e-buscas
- Orders/discounts: https://developers.mercadolivre.com.br/pt_br/busca-de-produtos-por-vendedor/gerenciamento-de-vendas
- Shipments actual cost: https://developers.mercadolivre.com.br/pt_br/gerenciamento-de-envios
- Notifications: https://developers.mercadolivre.com.br/produto-receba-notificacoes
- OAuth/token security: https://developers.mercadolivre.com.br/pt_br/publicacao-de-produtos/gestao-de-identidades-e-acessos-oauth-e-tokens
- Rate limit: https://developers.mercadolivre.com.br/pt_br/usuarios-e-aplicativos/rate-limit-erro-429
- Billing report consumption: https://developers.mercadolivre.com.br/pt_br/boas-praticas-para-o-consumo-das-apis-de-relatorios-de-faturamento
- Developer Terms: https://developers.mercadolivre.com.br/pt_br/termos-e-condicoes

## 34. Design self-review checklist

- No `TBD`/`TODO` placeholders.
- V1 scope is Mercado Livre only and read-only against marketplace state.
- Architecture does not depend on Mercado Turbo runtime services.
- Official 2026 ML migrations (price API, bulk endpoints, User Products, logistics-aware fees, price automation) are represented.
- Estimated/Actual/Reconciled semantics are distinct and immutable.
- Missing data cannot silently become zero/default margin.
- Tenant/account isolation exists from the first schema.
- OAuth/token and webhook SSRF boundaries are explicit.
- Queue/retry behavior is bounded and deterministic.
- No paid AI is required by permanent runtime jobs.
- Legacy ShopVivaLiz cutover is explicitly outside V1.
- Public commercialization remains behind a compliance review gate.
