# Mercado Livre Pricing API V1 Implementation Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sequence and constrain the four approved implementation plans so the new `Vivaliz-site/ml-pricing-api` service reaches V1 acceptance without cross-plan type drift, ambiguous infrastructure choices, or accidental marketplace writes.

**Architecture:** The four detailed plans remain the task-level execution guides. This master plan is authoritative where a detailed plan is ambiguous or omits a shared contract. Execution order is Foundation/ESTIMATED -> Promotions -> Orders/ACTUAL -> Reconciliation/Hardening; each milestone must merge cleanly before the next begins.

**Tech Stack:** PHP 8.3+, Symfony 7.4 LTS, MySQL 8.0+, Doctrine, Symfony HttpClient/Messenger/Lock/RateLimiter, Brick Math, PHPUnit, PHPStan, OpenAPI 3.1, Nginx/PHP-FPM/systemd.

**Spec:** `docs/superpowers/specs/2026-09-06-ml-pricing-api-design.md`

## Global Constraints

- Initial marketplace scope is Mercado Livre Brazil (`MLB`) only.
- V1 performs no Mercado Livre listing, price, promotion, stock, campaign, or pricing-automation mutation.
- OAuth token exchange/refresh is the only provider POST allowed in the ML authentication module.
- All marketplace business reads are tenant/account scoped.
- `ESTIMATED`, `ACTUAL`, and `RECONCILED` are immutable snapshot states.
- Decimal arithmetic uses `brick/math` `BigDecimal`; PHP float is forbidden for persisted/economic calculations.
- Mercado Turbo is black-box parity input only and is never a runtime dependency.
- Scheduling is systemd-based in V1; do not add Symfony Scheduler, Redis, or a second queue unless measured evidence justifies a later design.
- Permanent jobs are deterministic and bounded; no recurring paid AI.
- Public commercial multi-customer launch remains outside V1 and behind Mercado Livre compliance review.

---

## Detailed Plans and Mandatory Order

1. `docs/superpowers/plans/2026-09-06-ml-pricing-api-foundation-estimated.md`
2. `docs/superpowers/plans/2026-09-06-ml-pricing-api-promotions-estimated.md`
3. `docs/superpowers/plans/2026-09-06-ml-pricing-api-actual-orders.md`
4. `docs/superpowers/plans/2026-09-06-ml-pricing-api-reconciliation-hardening.md`

A milestone is not complete at a local commit. Required terminal state is branch -> tests -> commit -> push -> PR -> CI/review -> merge -> post-merge verification -> clean tree -> no open/draft PR from that milestone.

## Shared File/Type Contracts

The following files are mandatory even where a child plan omitted them from its file tree:

```text
src/MercadoLivre/Account/MlAccountRepository.php
src/Pricing/MarginCalculation.php
src/Audit/AuditRecord.php
src/Cost/Provider/ProviderCostRecord.php
tests/Support/FixtureFactory.php
```

Do not create `src/MercadoLivre/Promotion/PromotionOffer.php` in V1; the immutable `PromotionSnapshot` plus API DTO is sufficient. This removes an unused abstraction from the promotion child plan.

### `MarginCalculation`

```php
<?php
declare(strict_types=1);

namespace App\Pricing;

final readonly class MarginCalculation
{
    /** @param list<string> $missingInputs @param array<string,string|null> $sourceRefs */
    public function __construct(
        public string $state,
        public string $effectiveRevenue,
        public string $productCost,
        public string $packagingCost,
        public string $otherVariableCost,
        public string $taxAmount,
        public string $saleFeeGross,
        public string $meliFeeReduction,
        public string $saleFeeNet,
        public string $sellerShippingCost,
        public string $billingAdjustmentNet,
        public string $contributionMargin,
        public ?string $contributionMarginPct,
        public string $completenessStatus,
        public array $missingInputs,
        public array $sourceRefs,
        public string $calculationVersion,
    ) {}
}
```

All three pricing-engine methods return this type:

```php
public function estimated(EstimatedQuoteInput $input): MarginCalculation;
public function estimatedPromotion(PromotionQuoteInput $input): MarginCalculation;
public function actual(ActualQuoteInput $input): MarginCalculation;
public function reconciled(ReconciledQuoteInput $input): MarginCalculation;
```

### `MlAccountRepository`

Only tenant-scoped business reads are exposed:

```php
public function findForTenant(string $tenantId, string $accountId): ?MlAccount;
public function findBySellerForTenant(string $tenantId, string $sellerId, string $siteId = 'MLB'): ?MlAccount;
public function listForTenant(string $tenantId): array;
```

### `ProviderCostRecord`

```php
<?php
declare(strict_types=1);

namespace App\Cost\Provider;

final readonly class ProviderCostRecord
{
    public function __construct(
        public string $sku,
        public string $unitCost,
        public string $currencyId,
        public string $source,
        public string $sourceReference,
        public \DateTimeImmutable $observedAt,
        public ?string $averageCost = null,
    ) {}
}
```

The pricing engine consumes only the effective `CostProfile`; it never consumes `ProviderCostRecord` directly.

## Bootstrap Corrections

Use this exact repository bootstrap instead of creating a GitHub clone before Composer:

```bash
mkdir ml-pricing-api
cd ml-pricing-api
composer create-project symfony/skeleton:"7.4.*" . --no-interaction
composer require doctrine/orm doctrine/doctrine-bundle doctrine/doctrine-migrations-bundle symfony/http-client symfony/messenger symfony/doctrine-messenger symfony/monolog-bundle symfony/uid symfony/validator symfony/serializer symfony/security-bundle symfony/clock symfony/lock symfony/rate-limiter brick/math
composer require --dev symfony/test-pack phpunit/phpunit phpstan/phpstan
git init
git add .
git commit -m "chore: bootstrap ml pricing api"
gh repo create Vivaliz-site/ml-pricing-api --private --source=. --remote=origin --push --description "Mercado Livre pricing and margin API"
```

The first migration must include the Messenger Doctrine transport table and lock storage table/config needed by the service; production must not depend on automatic schema creation.

## CI Baseline Contract

The foundation PR must create `.github/workflows/ci.yml` with a MySQL 8 service because locking, decimal columns, unique constraints, and Doctrine migrations must be exercised against the production database family.

Minimum CI jobs execute:

```bash
composer validate --strict
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/phpunit
vendor/bin/phpstan analyse src tests --level=8
git diff --check
```

## Decimal Contract

`src/Pricing/Money.php` wraps `Brick\Math\BigDecimal`. Numeric database/JSON inputs enter as decimal strings. Required helpers:

```php
public static function of(string $amount): self;
public function plus(self $other): self;
public function minus(self $other): self;
public function multipliedBy(string $quantity): self;
public function max(self $other): self;
public function toScale(int $scale, \Brick\Math\RoundingMode $mode): self;
public function __toString(): string;
```

Use scale >=4 internally and `RoundingMode::HALF_UP` at BRL presentation boundaries. Do not cast economic values to float in controllers, entities, tests, or reports.

## API Client Provisioning Contract

Foundation Task 3 also creates `src/Command/CreateApiClientCommand.php`:

```text
php bin/console app:api-client:create <tenant-id> <name> pricing:read,costs:read
```

The command prints the raw token exactly once to the authorized operator, persists only SHA-256 hash + scopes, and never logs the raw token.

## OAuth Route Contract

Foundation Task 4 exposes only these account-linking routes:

```text
GET /v1/oauth/mercadolivre/start?tenant_id=<uuid>
GET /v1/oauth/mercadolivre/callback?code=...&state=...
```

The callback validates state/PKCE, exchanges the code, immediately reads the authenticated ML user identity, creates/updates the tenant-scoped `MlAccount`, encrypts both access and refresh tokens, and redirects/returns status without exposing tokens.

Refresh concurrency uses `symfony/lock` with a lock key `ml-refresh:{account-id}` and a DB-backed store. A refresh result replaces both access and refresh ciphertext atomically.

## Rate-Limit Budget Contract

Foundation Task 5 must add `src/MercadoLivre/Http/ProviderRateLimiter.php`. The key is `{ml_account_id}:{endpoint_family}`. Start with conservative configurable token buckets; do not hardcode an assumed Mercado Livre global quota.

```php
public function reserve(string $accountId, string $endpointFamily): void;
```

`MercadoLivreClient` calls `reserve()` before every provider request, then applies bounded provider retry. HTTP 429 honors `Retry-After` when present and otherwise uses exponential backoff + jitter, maximum 4 attempts.

## Foundation Read-Endpoint Contract

Foundation Task 12 must expose and test all of:

```text
GET /v1/accounts
GET /v1/accounts/{account_id}
GET /v1/ml/listings?sku=&item_id=&status=&catalog=&page=
GET /v1/ml/listings/{item_id}
GET /v1/ml/listings/{item_id}/prices
GET /v1/ml/listings/{item_id}/pricing-automation
GET /v1/costs/{sku}
PUT /v1/costs/{sku}
GET /v1/tax-profiles
PUT /v1/listings/{item_id}/tax-profile
POST /v1/pricing/quote
POST /v1/pricing/quotes/batch
POST /v1/admin/sync/ml
GET /v1/admin/sync/status
```

All monetary JSON fields are decimal strings. All object lookups derive tenant identity from our API credential, never from a caller-supplied tenant selector.

## Webhook Topic Contract

The Orders/ACTUAL plan must process these topics through one allowlisted dispatcher:

```text
orders_v2     -> OrderSyncService + OrderDiscountService
items         -> ListingSyncService for the notified item
items_prices  -> PriceSyncService for the notified item
shipments     -> ShipmentCostService after resolving authorized shipment/account context
```

Webhook events persist first; provider fetch occurs asynchronously. Resource parsing extracts only a validated type/id and rebuilds the path against `https://api.mercadolibre.com`.

## Scheduling Contract

V1 uses systemd timers only. Required targets:

```text
listing repair: every 60 minutes (hard freshness guard <= 6 hours)
price repair: every 15 minutes
active promotion refresh: every 30 minutes
missed-feed repair: every 15 minutes
billing reconciliation: every 6 hours
Olist/Tiny cost sync: every 60 minutes
```

Each command has `--max-items` and `--max-runtime-seconds` bounds. Timers use `Persistent=true` and randomized delay where safe to avoid synchronized bursts. Messenger consumers have bounded retry and a failure transport.

## Olist/Tiny Cost Policy

Primary ERP base is the already-used current V3 endpoint:

```text
https://api.tiny.com.br/public-api/v3
```

OAuth token endpoint:

```text
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token
```

The adapter reads product identity/SKU and seller cost. Cost precedence is:

```text
approved MANUAL unit_cost > V3 preco_custo > read-only V2 preco_custo > missing
```

`preco_custo_medio`/average cost, when available, is stored as `ProviderCostRecord::averageCost` for audit/analysis but is not silently substituted for `unitCost`.

If the authorized V3 product response does not expose `preco_custo`, the adapter may use the documented read-only Olist/Tiny V2 product search/get endpoint only for cost retrieval. V2 writes are prohibited. Olist documents V2 as still functional but no longer receiving new features; this fallback is therefore isolated behind `CostProvider` and can be removed without touching PricingEngine.

## Legacy Shadow Contract

Before V1 acceptance, add a read-only shadow comparison command:

```text
php bin/console app:ml:shadow-compare --account=<id> --max-items=500
```

It compares new-service account/listing/order identities and available margin-source completeness against legacy ShopVivaLiz records without writing legacy data. Output is a sanitized JSON/Markdown evidence artifact. No legacy transport/token code is retired in V1.

## Self-Review Corrections Applied

- `MarginCalculation`, `MlAccountRepository`, `ProviderCostRecord`, `AuditRecord`, and test fixture support are now explicit shared files.
- The unused `PromotionOffer` abstraction is removed from V1.
- Repository bootstrap no longer attempts Composer create-project inside a cloned non-empty directory.
- `brick/math`, `symfony/lock`, and `symfony/rate-limiter` are explicit dependencies.
- MySQL 8 is required in CI instead of relying on SQLite for behavior that differs in production.
- Scheduling is resolved to systemd timers; the child-plan phrase suggesting Symfony Scheduler is superseded.
- ML endpoint/account budgets are explicit rather than relying only on retry-after-429 behavior.
- Foundation read endpoints are enumerated so price/pricing-automation/account endpoints cannot be accidentally omitted.
- Webhook handling explicitly includes `items`, `items_prices`, and `shipments`, not only `orders_v2`.
- Olist/Tiny cost precedence and V3/V2 read-only fallback behavior are explicit.
- Legacy shadow comparison is included without expanding V1 into a cutover project.
- No `TODO`/`TBD` implementation placeholders are permitted in any execution PR.

## Execution Gate

- [ ] Execute Foundation/ESTIMATED plan and merge it.
- [ ] Execute Promotions plan and merge it.
- [ ] Execute Orders/ACTUAL plan and merge it.
- [ ] Execute Reconciliation/Hardening plan and merge it.
- [ ] Run complete V1 acceptance, parity, security, privacy, OpenAPI, live read-only smoke, and no-secret checks.
- [ ] Confirm no open/draft PRs or abandoned local changes remain.
