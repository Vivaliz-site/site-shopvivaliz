# Mercado Livre Pricing API — Foundation & Estimated Margin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the isolated `Vivaliz-site/ml-pricing-api` service and deliver secure MLB OAuth, listing/price synchronization, seller cost/tax registries, official fee/shipping quotes, and deterministic `ESTIMATED` contribution-margin quotes without any Mercado Livre mutation.

**Architecture:** New Symfony 7.4 modular monolith with a dedicated MySQL 8 database, database-backed Messenger queue, one Mercado Livre transport, immutable source snapshots, and a pure pricing engine. The service is tenant/account scoped from its first migration and shares no database tables with `site-shopvivaliz`.

**Tech Stack:** PHP 8.3+, Symfony 7.4 LTS, Doctrine ORM/DBAL/Migrations, MySQL 8.0+, Symfony HttpClient, Symfony Messenger Doctrine transport, PHPUnit, Symfony Clock, Monolog, OpenAPI 3.1.

**Spec:** `docs/superpowers/specs/2026-09-06-ml-pricing-api-design.md`

## Global Constraints

- Initial marketplace scope is Mercado Livre Brazil (`MLB`) only.
- V1 is read-only against Mercado Livre; no ML price, promotion, stock, campaign, listing, or pricing-automation mutation route may exist.
- PHP version floor is 8.3; Symfony version line is 7.4 LTS.
- MySQL 8.0+ is a separate logical database/schema with no foreign keys into ShopVivaLiz.
- All business rows carry `tenant_id`; all ML marketplace rows also carry `ml_account_id`.
- OAuth access/refresh tokens are encrypted at rest and never logged.
- Money is stored as decimal values; calculations use decimal arithmetic and half-up rounding at BRL presentation boundaries.
- `1 MLB = 1 SKU = 1 price` is never assumed.
- Missing cost/tax/fee/shipping inputs must create explicit incompleteness, never guessed zeros.
- Permanent jobs are deterministic; no paid AI is used by cron, daemon, retry loop, watcher, or runtime decision.
- Mercado Turbo is a black-box parity benchmark only; its code, selectors, tokens, private APIs, or cached responses are not runtime dependencies.

---

## File Structure Locked by This Plan

```text
ml-pricing-api/
├── .env
├── .env.test
├── .gitignore
├── composer.json
├── phpunit.xml.dist
├── config/
│   ├── packages/doctrine.yaml
│   ├── packages/framework.yaml
│   ├── packages/messenger.yaml
│   ├── packages/monolog.yaml
│   ├── routes.yaml
│   └── services.yaml
├── migrations/
├── public/index.php
├── src/
│   ├── Audit/
│   │   ├── AuditLogger.php
│   │   └── AuditRecord.php
│   ├── Cost/
│   │   ├── CostCatalog.php
│   │   ├── CostProfile.php
│   │   └── CostProfileRepository.php
│   ├── Http/
│   │   ├── ApiErrorResponder.php
│   │   ├── ApiTokenAuthenticator.php
│   │   └── CorrelationIdSubscriber.php
│   ├── Identity/
│   │   ├── ApiClient.php
│   │   ├── ApiClientRepository.php
│   │   ├── Tenant.php
│   │   └── TenantContext.php
│   ├── MercadoLivre/
│   │   ├── Account/MlAccount.php
│   │   ├── Auth/MercadoLivreOAuthService.php
│   │   ├── Auth/TokenCipher.php
│   │   ├── Http/MercadoLivreClient.php
│   │   ├── Http/MercadoLivreRequest.php
│   │   ├── Listing/Listing.php
│   │   ├── Listing/ListingVariant.php
│   │   ├── Listing/ListingSyncService.php
│   │   ├── Price/PriceSnapshot.php
│   │   ├── Price/PriceSyncService.php
│   │   ├── Price/PricingAutomationSnapshot.php
│   │   ├── Quote/FeeQuoteService.php
│   │   └── Quote/ShippingQuoteService.php
│   ├── Pricing/
│   │   ├── Completeness.php
│   │   ├── EstimatedQuoteInput.php
│   │   ├── MarginSnapshot.php
│   │   ├── Money.php
│   │   ├── PricingEngine.php
│   │   └── PricingFormulaVersion.php
│   ├── Tax/
│   │   ├── TaxCatalog.php
│   │   ├── TaxProfile.php
│   │   └── TaxProfileRepository.php
│   ├── Sync/
│   │   ├── RunMlAccountSync.php
│   │   ├── RunMlAccountSyncHandler.php
│   │   └── SyncCursor.php
│   └── Controller/
│       ├── AccountController.php
│       ├── CostController.php
│       ├── HealthController.php
│       ├── ListingController.php
│       ├── PricingController.php
│       ├── SyncController.php
│       └── TaxController.php
└── tests/
    ├── Contract/MercadoLivre/
    ├── Integration/
    ├── Security/
    └── Unit/
```

### Task 1: Bootstrap the isolated Symfony service and CI baseline

**Files:**
- Create: all repository bootstrap files shown above that Composer/Symfony generate
- Create: `.github/workflows/ci.yml`
- Create: `src/Controller/HealthController.php`
- Test: `tests/Integration/HealthEndpointTest.php`

**Interfaces:**
- Consumes: none
- Produces: bootable Symfony kernel; `GET /v1/health` returning JSON and no marketplace writes

- [ ] **Step 1: Create the repository and Symfony skeleton**

Run from an empty parent directory:

```bash
gh repo create Vivaliz-site/ml-pricing-api --private --description "Mercado Livre pricing and margin API" --clone
cd ml-pricing-api
composer create-project symfony/skeleton:"7.4.*" . --no-interaction
composer require doctrine/orm doctrine/doctrine-bundle doctrine/doctrine-migrations-bundle symfony/http-client symfony/messenger symfony/doctrine-messenger symfony/monolog-bundle symfony/uid symfony/validator symfony/serializer symfony/security-bundle symfony/clock
composer require --dev symfony/test-pack phpunit/phpunit phpstan/phpstan
```

- [ ] **Step 2: Write the failing health endpoint test**

Create `tests/Integration/HealthEndpointTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    public function testHealthIsJsonAndDoesNotExposeSecrets(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
    }
}
```

- [ ] **Step 3: Run the test and verify RED**

Run: `php bin/phpunit tests/Integration/HealthEndpointTest.php`
Expected: FAIL with 404 for `/v1/health`.

- [ ] **Step 4: Implement the minimal endpoint**

Create `src/Controller/HealthController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/v1/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 5: Add CI and run the baseline suite**

`.github/workflows/ci.yml` must run PHP 8.3, `composer validate --strict`, `composer install`, `php -l` over `src/` and `tests/`, PHPUnit, and PHPStan level 8 over `src` and `tests`.

Run:

```bash
composer validate --strict
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/phpunit
vendor/bin/phpstan analyse src tests --level=8
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "chore: bootstrap ml pricing api"
```

### Task 2: Tenant, API-client, and account-scoped persistence

**Files:**
- Create: `src/Identity/Tenant.php`
- Create: `src/Identity/ApiClient.php`
- Create: `src/Identity/TenantContext.php`
- Create: `src/MercadoLivre/Account/MlAccount.php`
- Create: first Doctrine migration
- Test: `tests/Integration/TenantIsolationTest.php`

**Interfaces:**
- Produces: `TenantContext::tenantId(): string`; `MlAccount::sellerId(): string`; mandatory tenant/account foreign keys for later modules

- [ ] **Step 1: Write the failing tenant-isolation test**

```php
public function testAccountLookupCannotCrossTenantBoundary(): void
{
    $tenantA = $this->fixtures->tenant('A');
    $tenantB = $this->fixtures->tenant('B');
    $accountB = $this->fixtures->mlAccount($tenantB, '123456');

    $repo = static::getContainer()->get(\App\MercadoLivre\Account\MlAccountRepository::class);
    self::assertNull($repo->findForTenant($tenantA->id(), $accountB->id()));
}
```

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/TenantIsolationTest.php`
Expected: FAIL because entities/repository do not exist.

- [ ] **Step 3: Implement UUID-backed entities and scoped repository methods**

Use Symfony Uid UUID values stored as `BINARY(16)`. `MlAccountRepository` must expose only:

```php
public function findForTenant(string $tenantId, string $accountId): ?MlAccount;
public function findBySellerForTenant(string $tenantId, string $sellerId, string $siteId = 'MLB'): ?MlAccount;
```

No unscoped business lookup may be injected into controllers.

- [ ] **Step 4: Generate/apply migration and rerun tests**

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit tests/Integration/TenantIsolationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src migrations tests
 git commit -m "feat: add tenant scoped account model"
```

### Task 3: Hashed API-client authentication and scopes

**Files:**
- Create: `src/Http/ApiTokenAuthenticator.php`
- Create: `src/Identity/ApiClientRepository.php`
- Create: `src/Http/ApiErrorResponder.php`
- Test: `tests/Security/ApiTokenAuthenticationTest.php`

**Interfaces:**
- Produces authenticated `TenantContext` and scope checks for `pricing:read`, `costs:read`, `costs:write`, `admin:sync`

- [ ] **Step 1: Write RED tests for missing, invalid, and insufficient-scope tokens**

Assert `401` for missing/invalid bearer token and `403` for a valid token without required scope. Assert DB stores `hash('sha256', $token)` only.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Security/ApiTokenAuthenticationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement opaque bearer authentication**

Token lookup must hash the received token with SHA-256 and compare against the stored hash. Never persist or log the raw token. Scope checks read `scopes_json` from the authenticated `ApiClient`.

- [ ] **Step 4: Run GREEN and full security subset**

```bash
php bin/phpunit tests/Security/ApiTokenAuthenticationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests migrations
git commit -m "feat: add scoped api client authentication"
```

### Task 4: Encrypt Mercado Livre tokens and implement OAuth 2.0 + PKCE

**Files:**
- Create: `src/MercadoLivre/Auth/TokenCipher.php`
- Create: `src/MercadoLivre/Auth/MercadoLivreOAuthService.php`
- Create: `src/Controller/MercadoLivreOAuthController.php`
- Modify: `src/MercadoLivre/Account/MlAccount.php`
- Test: `tests/Unit/MercadoLivre/TokenCipherTest.php`
- Test: `tests/Integration/MercadoLivreOAuthTest.php`
- Test: `tests/Security/TokenAtRestTest.php`

**Interfaces:**
- Produces: `TokenCipher::encrypt(string): string`, `TokenCipher::decrypt(string): string`, `MercadoLivreOAuthService::refresh(MlAccount): void`

- [ ] **Step 1: Write encryption round-trip and plaintext-at-rest RED tests**

```php
public function testTokenCipherRoundTripsWithoutPlaintextPersistence(): void
{
    $cipher = self::getContainer()->get(TokenCipher::class);
    $encoded = $cipher->encrypt('secret-token');
    self::assertNotSame('secret-token', $encoded);
    self::assertSame('secret-token', $cipher->decrypt($encoded));
}
```

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/MercadoLivre/TokenCipherTest.php`
Expected: FAIL because service does not exist.

- [ ] **Step 3: Implement libsodium authenticated encryption**

Use `sodium_crypto_secretbox()` with a 32-byte key loaded only from `ML_TOKEN_ENCRYPTION_KEY_B64`. Persist base64(nonce+ciphertext). Throw on missing/invalid key.

- [ ] **Step 4: Implement OAuth authorization-code + PKCE and refresh locking**

Persist only encrypted access/refresh tokens, expiry, account identity/status. Use a DB/advisory lock keyed by account ID so two workers cannot consume the same single-use refresh token concurrently.

- [ ] **Step 5: Verify no plaintext token in DB/log fixtures**

Run:

```bash
php bin/phpunit tests/Unit/MercadoLivre/TokenCipherTest.php tests/Integration/MercadoLivreOAuthTest.php tests/Security/TokenAtRestTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src tests migrations config
git commit -m "feat: add secure mercado livre oauth"
```

### Task 5: Mercado Livre HTTP client with allowlist, bounded retry, and redaction

**Files:**
- Create: `src/MercadoLivre/Http/MercadoLivreClient.php`
- Create: `src/MercadoLivre/Http/MercadoLivreRequest.php`
- Create: `src/MercadoLivre/Http/MercadoLivreException.php`
- Test: `tests/Unit/MercadoLivre/MercadoLivreClientTest.php`
- Test: `tests/Security/MercadoLivreSsrfTest.php`

**Interfaces:**
- Produces: `MercadoLivreClient::get(MlAccount $account, string $path, array $query = []): array`

- [ ] **Step 1: Write RED tests**

Tests must prove: relative allowlisted paths work; absolute URLs are rejected; `429` honors `Retry-After`; retry count is bounded; `401/403/404` are not blindly retried; Authorization is redacted from logged context.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/MercadoLivre/MercadoLivreClientTest.php tests/Security/MercadoLivreSsrfTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement transport policy**

Use fixed base `https://api.mercadolibre.com`. Reject any path that does not start with `/` or contains a scheme/host. Retry only 429 and selected safe network/5xx failures with exponential backoff+jitter, max 4 attempts. Honor `Retry-After` when present.

- [ ] **Step 4: Run GREEN**

Run same PHPUnit command; expected PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: add resilient mercado livre client"
```

### Task 6: Synchronize listings through current search + bulk APIs

**Files:**
- Create: `src/MercadoLivre/Listing/Listing.php`
- Create: `src/MercadoLivre/Listing/ListingVariant.php`
- Create: `src/MercadoLivre/Listing/ListingRepository.php`
- Create: `src/MercadoLivre/Listing/ListingSyncService.php`
- Create: migration for listing tables
- Test: `tests/Contract/MercadoLivre/ListingContractTest.php`
- Test: `tests/Integration/ListingSyncTest.php`
- Fixture: `tests/Fixtures/ml/items-bulk.json`

**Interfaces:**
- Produces: `ListingSyncService::syncAccount(MlAccount $account): ListingSyncResult`

- [ ] **Step 1: Add sanitized current `/items/bulk` fixture and RED contract test**

Contract test must assert item ID, User Product ID, family/catalog IDs, SKU, variation ID, listing type, condition, shipping mode, logistic type, free-shipping flag, and source timestamps normalize without assuming one SKU per item.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/ListingContractTest.php tests/Integration/ListingSyncTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement discovery and bulk normalization**

Use `/users/{seller_id}/items/search` to discover IDs and `/items/bulk?ids=...` in bounded batches for detail. Do not use deprecated `/items?ids=`.

- [ ] **Step 4: Persist idempotently and prove multi-identity support**

Upsert listing by `(ml_account_id,item_id)` and variants by deterministic sale-condition identity. Rerunning the same fixture must not create duplicates.

- [ ] **Step 5: Run GREEN**

Run same tests; expected PASS.

- [ ] **Step 6: Commit**

```bash
git add src tests migrations
git commit -m "feat: sync mercado livre listings"
```

### Task 7: Synchronize canonical prices and pricing-automation state

**Files:**
- Create: `src/MercadoLivre/Price/PriceSnapshot.php`
- Create: `src/MercadoLivre/Price/PricingAutomationSnapshot.php`
- Create: `src/MercadoLivre/Price/PriceSyncService.php`
- Create: migration
- Test: `tests/Contract/MercadoLivre/PriceContractTest.php`
- Fixtures: `tests/Fixtures/ml/item-prices.json`, `tests/Fixtures/ml/pricing-automation.json`

**Interfaces:**
- Produces: `PriceSyncService::syncListing(MlAccount $account, Listing $listing): void`

- [ ] **Step 1: Write RED contract tests**

Assert canonical price comes from current price resources, stores `amount`, `regular_amount`, context, source time, and automation status separately from listing metadata.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/PriceContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement price and automation synchronization**

Call `/items/{ITEM_ID}/prices` plus the current sale-price context resource where required. Persist append-only snapshots keyed by source/payload hash. Query pricing-automation read resources and persist state; never write automation.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/PriceContractTest.php
git add src tests migrations
git commit -m "feat: sync current ml prices and automation state"
```

### Task 8: Effective-dated cost and tax catalogs with audited internal writes

**Files:**
- Create: `src/Cost/CostProfile.php`
- Create: `src/Cost/CostCatalog.php`
- Create: `src/Tax/TaxProfile.php`
- Create: `src/Tax/TaxCatalog.php`
- Create: `src/Audit/AuditLogger.php`
- Create: `src/Controller/CostController.php`
- Create: `src/Controller/TaxController.php`
- Create: migrations
- Test: `tests/Integration/EffectiveDatedCostTest.php`
- Test: `tests/Security/CostTaxWriteScopeTest.php`

**Interfaces:**
- Produces: `CostCatalog::effectiveFor(string $tenantId, string $sku, DateTimeImmutable $at): ?CostProfile`; `TaxCatalog::effectiveForListing(string $tenantId, string $itemId, DateTimeImmutable $at): ?TaxProfile`

- [ ] **Step 1: Write RED tests for precedence, history, overlap rejection, scopes, and audit reason**

Manual approved override must beat ERP profile; a future cost change must not alter the profile returned for a past timestamp. `PUT /v1/costs/{sku}` must require `costs:write` plus non-empty `X-Audit-Reason`.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/EffectiveDatedCostTest.php tests/Security/CostTaxWriteScopeTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement catalogs and append-only effective periods**

On a new value, close the previous period and create a new row in one transaction; never update the numeric value of an historical row.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/EffectiveDatedCostTest.php tests/Security/CostTaxWriteScopeTest.php
git add src tests migrations
git commit -m "feat: add effective dated cost and tax catalogs"
```

### Task 9: Official fee and shipping quote services

**Files:**
- Create: `src/MercadoLivre/Quote/FeeQuote.php`
- Create: `src/MercadoLivre/Quote/FeeQuoteService.php`
- Create: `src/MercadoLivre/Quote/ShippingQuote.php`
- Create: `src/MercadoLivre/Quote/ShippingQuoteService.php`
- Create: migrations
- Test: `tests/Contract/MercadoLivre/FeeQuoteContractTest.php`
- Test: `tests/Contract/MercadoLivre/ShippingQuoteContractTest.php`

**Interfaces:**
- Produces: `FeeQuoteService::quote(MlAccount, Listing, Money): FeeQuote`; `ShippingQuoteService::quote(MlAccount, Listing, Money): ShippingQuote`

- [ ] **Step 1: Write RED fee tests**

Fixture must prove `sale_fee_amount` is treated as the total selling cost and returned `fixed_fee` is explanatory only, never added again. Assert request context includes category, price, currency, listing type, `shipping_mode`, and `logistic_type` when available.

- [ ] **Step 2: Write RED shipping tests**

Assert `/users/{seller_id}/shipping_options/free` is called with item/price/listing/logistics context and returned seller estimate is stored with an input fingerprint.

- [ ] **Step 3: Run RED**

```bash
php bin/phpunit tests/Contract/MercadoLivre/FeeQuoteContractTest.php tests/Contract/MercadoLivre/ShippingQuoteContractTest.php
```

Expected: FAIL.

- [ ] **Step 4: Implement short-lived quote caches keyed by complete input fingerprint**

The cache/snapshot lookup must never reuse a quote if any price/listing/logistics/dimensions input differs. Target TTL is 15 minutes.

- [ ] **Step 5: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/FeeQuoteContractTest.php tests/Contract/MercadoLivre/ShippingQuoteContractTest.php
git add src tests migrations
git commit -m "feat: add official fee and shipping quotes"
```

### Task 10: Pure deterministic ESTIMATED pricing engine

**Files:**
- Create: `src/Pricing/Money.php`
- Create: `src/Pricing/Completeness.php`
- Create: `src/Pricing/EstimatedQuoteInput.php`
- Create: `src/Pricing/PricingEngine.php`
- Create: `src/Pricing/PricingFormulaVersion.php`
- Test: `tests/Unit/Pricing/PricingEngineTest.php`

**Interfaces:**
- Produces: `PricingEngine::estimated(EstimatedQuoteInput $input): MarginCalculation`

- [ ] **Step 1: Write table-driven RED tests**

Use explicit decimal-string cases for positive/zero/negative margin, quantity >1, missing cost, missing tax, missing fee, missing freight, and rounding boundaries. Include the known economic identity:

```text
38.26 - 20.00 - 3.8260 - 7.35 - 4.40 = 2.6840
```

Expected calculation version: `mlb-margin-v1`.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/Pricing/PricingEngineTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement decimal Money and formula**

```text
effective_revenue
- product_cost
- packaging_cost
- other_variable_cost
- tax_amount
- estimated_net_sale_fee
- estimated_seller_shipping_cost
= estimated_contribution_margin
```

`contribution_margin_pct` is null when revenue <= 0. Missing authoritative inputs populate `Completeness` and prevent `COMPLETE` status.

- [ ] **Step 4: Run GREEN**

Run: `php bin/phpunit tests/Unit/Pricing/PricingEngineTest.php`
Expected: PASS with exact decimal-string assertions.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: add deterministic estimated margin engine"
```

### Task 11: Persist immutable margin snapshots and expose quote APIs

**Files:**
- Create: `src/Pricing/MarginSnapshot.php`
- Create: `src/Pricing/MarginSnapshotRepository.php`
- Create: `src/Controller/PricingController.php`
- Create: migration
- Test: `tests/Integration/PricingQuoteApiTest.php`

**Interfaces:**
- Produces: `POST /v1/pricing/quote`; `POST /v1/pricing/quotes/batch`

- [ ] **Step 1: Write RED API tests**

A quote request includes account ID, item ID and optional requested buyer-facing price. Response must serialize monetary values as decimal strings and include every component, completeness/missing inputs, source timestamps, and `calculation_version`.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/PricingQuoteApiTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement orchestration without contaminating PricingEngine**

Controller/application service loads listing, current price, effective cost/tax, official fee/shipping quotes, calls the pure engine, then appends a `margin_snapshots` row. Never update a previous snapshot.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/PricingQuoteApiTest.php
git add src tests migrations
git commit -m "feat: expose estimated pricing quote api"
```

### Task 12: Durable account sync orchestration and read APIs

**Files:**
- Create: `src/Sync/RunMlAccountSync.php`
- Create: `src/Sync/RunMlAccountSyncHandler.php`
- Create: `src/Sync/SyncCursor.php`
- Create: `src/Controller/SyncController.php`
- Create: `src/Controller/ListingController.php`
- Create: `src/Controller/AccountController.php`
- Test: `tests/Integration/MlAccountSyncTest.php`

**Interfaces:**
- Produces: `POST /v1/admin/sync/ml`, `GET /v1/admin/sync/status`, listing/account read endpoints

- [ ] **Step 1: Write RED sync idempotency test**

Dispatching the same account sync twice must converge to one current listing set and append only changed snapshots.

- [ ] **Step 2: Configure Doctrine Messenger transport**

Use a dedicated DB-backed transport, bounded retries, and a failure transport. The HTTP admin endpoint enqueues work and never performs a full account sync synchronously.

- [ ] **Step 3: Implement handler sequence**

`ListingSyncService -> PriceSyncService -> freshness/status persistence`. Fee/shipping quotes remain demand-driven rather than full-catalog fan-out.

- [ ] **Step 4: Run GREEN**

Run: `php bin/phpunit tests/Integration/MlAccountSyncTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests config migrations
git commit -m "feat: add durable mercado livre account sync"
```

### Task 13: Stable errors, audit, correlation IDs, OpenAPI, and security regression suite

**Files:**
- Create/modify: `src/Http/ApiErrorResponder.php`
- Create: `src/Http/CorrelationIdSubscriber.php`
- Create: `docs/openapi.yaml`
- Test: `tests/Security/NoMutationRoutesTest.php`
- Test: `tests/Security/RedactionTest.php`
- Test: `tests/Contract/OpenApiContractTest.php`

**Interfaces:**
- Produces stable error envelope and documented V1 foundation endpoints

- [ ] **Step 1: Write RED tests for error envelope and mutation-route absence**

Representative error:

```json
{"error":{"code":"MISSING_COST_PROFILE","message":"No effective cost profile exists for the requested SKU.","correlation_id":"test-correlation","details":{}}}
```

Explicitly assert no route permits `PUT/PATCH/POST` against ML listing/price/promotion/stock/automation resources.

- [ ] **Step 2: Add OpenAPI 3.1 contract for all foundation endpoints**

Document decimal strings, scopes, completeness states, and stable error codes.

- [ ] **Step 3: Run contract/security suite**

```bash
php bin/phpunit tests/Security tests/Contract/OpenApiContractTest.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src tests docs config
git commit -m "feat: harden and document ml pricing foundation"
```

### Task 14: Live read-only validation and foundation acceptance gate

**Files:**
- Create: `tests/Live/MercadoLivreFoundationSmokeTest.php`
- Create: `docs/validation/foundation-live-smoke.md`
- Modify: `.github/workflows/ci.yml` to keep live tests manual/secrets-gated

**Interfaces:**
- Produces evidence that the service can read the authorized seller account and calculate an `ESTIMATED` snapshot with zero marketplace writes

- [ ] **Step 1: Add live smoke test guarded by `RUN_ML_LIVE_TESTS=1`**

The test reads account identity, discovers one active listing, fetches bulk detail/current price, requests official fee + shipping quote, and creates one internal `ESTIMATED` margin snapshot. It must never call an ML mutation method.

- [ ] **Step 2: Run full deterministic suite locally/CI**

```bash
composer validate --strict
php bin/phpunit
vendor/bin/phpstan analyse src tests --level=8
```

Expected: all PASS.

- [ ] **Step 3: Run live read-only smoke against the authorized seller**

```bash
RUN_ML_LIVE_TESTS=1 php bin/phpunit tests/Live/MercadoLivreFoundationSmokeTest.php
```

Expected: PASS; audit evidence shows only GET/read API calls to ML and one internal margin snapshot.

- [ ] **Step 4: Record evidence and secret scan**

Run:

```bash
git grep -nE '(APP_USR-|refresh_token|access_token|sk-[A-Za-z0-9_-]{16,})' -- ':!tests/Fixtures/**' || true
git status --porcelain
```

Expected: no real secrets; clean tree after evidence commit.

- [ ] **Step 5: Commit and open review PR**

```bash
git add .
git commit -m "test: validate estimated margin foundation"
git push -u origin HEAD
gh pr create --base main --title "feat: add ML pricing foundation and estimated margins" --body "Implements the approved foundation plan with read-only Mercado Livre integration and deterministic ESTIMATED margin calculations."
```

Do not merge until CI, review, secret scan, and live read-only evidence are green.
