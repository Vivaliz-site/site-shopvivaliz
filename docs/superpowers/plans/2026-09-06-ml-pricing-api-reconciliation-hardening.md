# Mercado Livre Pricing API — Reconciliation, ERP Cost Sync & V1 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete V1 with official billing reconciliation, immutable `RECONCILED` margins, seller-owned Olist/Tiny cost synchronization, value-added reports, parity classification, production observability/security hardening, and end-to-end read-only acceptance evidence.

**Architecture:** Billing data is a post-sale reconciliation source and never contaminates pre-sale quoting. ERP integration feeds only the seller-owned `CostCatalog`; the pricing engine remains provider-agnostic. Production runs as an isolated immutable-release service with deterministic workers/timers and no recurring paid AI.

**Tech Stack:** Existing PHP 8.3+/Symfony 7.4 service, Doctrine/MySQL, Symfony HttpClient/Messenger, PHPUnit/OpenAPI, Nginx/PHP-FPM/systemd, existing ShopVivaLiz immutable-release governance.

**Spec:** `docs/superpowers/specs/2026-09-06-ml-pricing-api-design.md`

## Global Constraints

- `RECONCILED = ACTUAL + verified matched billing adjustment net`; `ESTIMATED` and `ACTUAL` snapshots are never rewritten.
- Billing endpoints are for post-sale reconciliation, not real-time quotes.
- Only official matched credits/debits enter `RECONCILED`.
- Olist/Tiny is a seller-owned cost provider, not another marketplace in V1.
- Approved manual cost overrides outrank ERP-synchronized cost profiles.
- Duplicate/unmatched ERP SKU mappings become explicit review data; they are never guessed.
- Reports expose value-added margin results, not wholesale raw Mercado Livre API payloads.
- Buyer PII is minimized; tokens/secrets never enter logs, fixtures, audit payloads, or repository history.
- Production jobs are deterministic and bounded; no recurring paid AI.
- Public commercial multi-customer launch remains outside V1 and behind Mercado Livre compliance review.

---

## File Structure Added by This Plan

```text
src/MercadoLivre/Billing/
├── BillingAdjustment.php
├── BillingClient.php
├── BillingMatcher.php
└── BillingReconciliationService.php
src/Cost/Provider/
├── CostProvider.php
├── OlistTinyClient.php
├── OlistTinyCostProvider.php
└── SkuMatchResult.php
src/Pricing/
├── ReconciledQuoteInput.php
└── ReconciliationService.php
src/Report/
├── MarginReportService.php
└── SummaryReportService.php
src/Controller/
└── ReportController.php
src/Observability/
├── MetricsRegistry.php
└── ProviderTelemetry.php
tests/Contract/MercadoLivre/
└── BillingContractTest.php
tests/Contract/OlistTiny/
└── CostProviderContractTest.php
tests/Integration/
├── BillingReconciliationTest.php
├── OlistTinyCostSyncTest.php
├── ReportApiTest.php
└── ReconciledSnapshotImmutabilityTest.php
tests/Parity/
└── V1ParityMatrixTest.php
```

### Task 1: Persist official billing adjustments idempotently

**Files:**
- Create: `src/MercadoLivre/Billing/BillingAdjustment.php`
- Create: migration
- Test: `tests/Integration/BillingAdjustmentPersistenceTest.php`

**Interfaces:**
- Produces immutable adjustment rows keyed by official billing identity/idempotency key

- [ ] **Step 1: Write RED persistence test**

Persist the same official adjustment twice and assert one row. Persist a later reversal with a distinct official identity and assert a second row. Fields must include tenant/account, resolvable order/order-item IDs, adjustment type, signed decimal amount, currency, occurred timestamp, source identity, and payload hash.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/BillingAdjustmentPersistenceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement append-only schema**

Do not add a mutable `current_adjustment` amount to order rows. Credits are positive; debits are negative.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit tests/Integration/BillingAdjustmentPersistenceTest.php
git add src tests migrations
git commit -m "feat: add immutable ml billing adjustments"
```

### Task 2: Implement billing read adapter and current contract tests

**Files:**
- Create: `src/MercadoLivre/Billing/BillingClient.php`
- Test: `tests/Contract/MercadoLivre/BillingContractTest.php`
- Fixtures: `tests/Fixtures/ml/billing-periods.json`, `billing-documents.json`, `billing-details.json`

**Interfaces:**
- Produces sanitized normalized billing adjustments from `/billing/integration/...`

- [ ] **Step 1: Write RED contract tests**

Assert period/document/detail identifiers, signed economic amount, currency, date, type, and order references normalize from current official responses. Unrelated raw invoice/report fields are discarded.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/MercadoLivre/BillingContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement read-only billing client**

Use `MercadoLivreClient`; page/period consumption must be bounded and checkpointable. Do not call billing endpoints from the synchronous pricing quote path.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/MercadoLivre/BillingContractTest.php
git add src tests
git commit -m "feat: read ml billing reconciliation data"
```

### Task 3: Match billing adjustments conservatively

**Files:**
- Create: `src/MercadoLivre/Billing/BillingMatcher.php`
- Test: `tests/Unit/MercadoLivre/BillingMatcherTest.php`

**Interfaces:**
- Produces `MATCHED`, `AMBIGUOUS`, or `UNMATCHED` result with reason/evidence

- [ ] **Step 1: Write RED matching cases**

Cases: exact order-item reference; exact order-only reference; multiple candidate items; missing order; currency mismatch. Only unambiguous official identity may be `MATCHED` automatically.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Unit/MercadoLivre/BillingMatcherTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement deterministic matcher**

Never infer a match solely from equal amount/date when multiple candidates exist. Ambiguous/unmatched adjustments stay reviewable and cannot alter margin.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Unit/MercadoLivre/BillingMatcherTest.php
git add src tests
git commit -m "feat: match ml billing adjustments conservatively"
```

### Task 4: Create immutable RECONCILED margin snapshots

**Files:**
- Create: `src/Pricing/ReconciledQuoteInput.php`
- Create: `src/Pricing/ReconciliationService.php`
- Modify: `src/Pricing/PricingEngine.php`
- Test: `tests/Integration/BillingReconciliationTest.php`
- Test: `tests/Integration/ReconciledSnapshotImmutabilityTest.php`

**Interfaces:**
- Produces: `PricingEngine::reconciled(ReconciledQuoteInput $input): MarginCalculation`

- [ ] **Step 1: Write RED formula test**

```text
actual_contribution_margin = 30.00
verified_billing_adjustment_net = +5.00
reconciled_contribution_margin = 35.00
```

A later `-2.00` official debit must produce a new reconciled snapshot reflecting cumulative verified adjustments, while the earlier reconciled and original actual snapshots remain unchanged.

- [ ] **Step 2: Run RED**

```bash
php bin/phpunit tests/Integration/BillingReconciliationTest.php tests/Integration/ReconciledSnapshotImmutabilityTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implement pure reconciled formula and orchestration**

Only `MATCHED` billing adjustments are summed. Persist source adjustment IDs and calculation version in every reconciled snapshot.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/BillingReconciliationTest.php tests/Integration/ReconciledSnapshotImmutabilityTest.php
git add src tests
git commit -m "feat: create reconciled ml margin snapshots"
```

### Task 5: Add Olist/Tiny provider boundary

**Files:**
- Create: `src/Cost/Provider/CostProvider.php`
- Create: `src/Cost/Provider/SkuMatchResult.php`
- Create: `src/Cost/Provider/OlistTinyClient.php`
- Create: `src/Cost/Provider/OlistTinyCostProvider.php`
- Test: `tests/Contract/OlistTiny/CostProviderContractTest.php`

**Interfaces:**
- Produces: `CostProvider::fetchChangedSince(DateTimeImmutable $since): iterable<ProviderCostRecord>`

- [ ] **Step 1: Write RED provider contract**

Fixture record must normalize SKU, unit cost, currency, source reference, observed timestamp, and source system. Do not expose ERP transport details to `PricingEngine`.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Contract/OlistTiny/CostProviderContractTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement authenticated ERP HTTP boundary**

Use existing authorized Olist/Tiny API credentials through runtime secret storage. Do not commit tokens or copy them into ML account storage. Add timeouts, bounded retries, and provider redaction equivalent to the ML client.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Contract/OlistTiny/CostProviderContractTest.php
git add src tests
git commit -m "feat: add olist tiny cost provider boundary"
```

### Task 6: Synchronize effective-dated ERP costs without overriding manual approvals

**Files:**
- Create: `src/Cost/Provider/SyncProviderCosts.php`
- Create: `src/Cost/Provider/SyncProviderCostsHandler.php`
- Modify: `src/Cost/CostCatalog.php`
- Test: `tests/Integration/OlistTinyCostSyncTest.php`

**Interfaces:**
- Produces effective-dated `OLIST`/`TINY` cost profiles and explicit unmatched-review records

- [ ] **Step 1: Write RED precedence and SKU-match tests**

Assert: exact normalized SKU creates/updates ERP profile; same cost is idempotent; changed cost closes old ERP period and opens new one; approved `MANUAL` profile remains effective over ERP; duplicate/unmatched mapping is not guessed.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/OlistTinyCostSyncTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement sync transaction**

Persist provider source reference and observed time. Store unmatched/duplicate SKU state in a review table with candidate identifiers and reason.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/OlistTinyCostSyncTest.php
git add src tests migrations
git commit -m "feat: sync effective dated olist tiny costs"
```

### Task 7: Build value-added margin reports

**Files:**
- Create: `src/Report/MarginReportService.php`
- Create: `src/Report/SummaryReportService.php`
- Create: `src/Controller/ReportController.php`
- Modify: `docs/openapi.yaml`
- Test: `tests/Integration/ReportApiTest.php`

**Interfaces:**
- Produces: `GET /v1/reports/margins`; `GET /v1/reports/summary`

- [ ] **Step 1: Write RED report tests**

Filters must include date range, state (`ESTIMATED|ACTUAL|RECONCILED`), SKU/item/account, and completeness. Summary must aggregate effective revenue, product costs, tax, sale fee, shipping, billing adjustment net, contribution margin, and margin percentage using decimal-safe SQL/application math.

- [ ] **Step 2: Run RED**

Run: `php bin/phpunit tests/Integration/ReportApiTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement report services over our canonical results only**

Do not expose wholesale raw ML payloads. Preserve tenant/account filters in every query.

- [ ] **Step 4: Run GREEN and commit**

```bash
php bin/phpunit tests/Integration/ReportApiTest.php tests/Contract/OpenApiContractTest.php
git add src tests docs
git commit -m "feat: add margin reporting api"
```

### Task 8: Observability and deterministic operational jobs

**Files:**
- Create: `src/Observability/MetricsRegistry.php`
- Create: `src/Observability/ProviderTelemetry.php`
- Create: `src/Command/SyncListingsCommand.php`
- Create: `src/Command/RepairFeedsCommand.php`
- Create: `src/Command/ReconcileBillingCommand.php`
- Create: `ops/systemd/ml-pricing-worker.service`
- Create: `ops/systemd/ml-pricing-listing-sync.timer`
- Create: `ops/systemd/ml-pricing-feed-repair.timer`
- Create: `ops/systemd/ml-pricing-billing-reconcile.timer`
- Test: `tests/Integration/OperationalCommandBoundsTest.php`

**Interfaces:**
- Produces bounded workers/timers and metrics for provider requests, 429/backoff, token refresh, webhooks, freshness, queue depth, quote completeness, estimate-vs-actual delta, actual-vs-reconciled delta

- [ ] **Step 1: Write RED command-bound tests**

Every command must accept explicit `--max-items`/`--max-runtime-seconds` or equivalent bounded batch controls and exit successfully when no work exists.

- [ ] **Step 2: Implement deterministic commands and systemd units**

Workers/timers invoke only deterministic PHP commands. No Claude/GPT/Codex call, shell watcher with paid AI, or unbounded retry loop is allowed.

- [ ] **Step 3: Run GREEN and inspect units**

```bash
php bin/phpunit tests/Integration/OperationalCommandBoundsTest.php
systemd-analyze verify ops/systemd/*.service ops/systemd/*.timer
```

Expected: PASS/valid units.

- [ ] **Step 4: Commit**

```bash
git add src tests ops
git commit -m "feat: add bounded ml pricing operations"
```

### Task 9: Complete V1 parity matrix

**Files:**
- Create: `tests/Parity/V1ParityMatrixTest.php`
- Create: `tests/Fixtures/parity/v1-cases.json`
- Create: `docs/validation/v1-parity-matrix.md`

**Interfaces:**
- Produces classified evidence across standard, promotion, actual, and reconciled economics

- [ ] **Step 1: Define seller-owned cases**

Include Classic/Premium; catalog/non-catalog; Full/Flex/drop-off/other observed logistics; free/non-free seller shipping; positive/near-zero/negative margin; normal/promotional/boosted price; multi-unit/multi-item pack; pricing automation; legacy variation/User Product when available.

- [ ] **Step 2: Assert classification completeness**

Every mismatch must have exactly one classification: `OUR_BUG`, `STALE_OR_DIFFERENT_INPUTS`, `DOCUMENTED_SEMANTIC_DIFFERENCE`, `MERCADO_TURBO_DISCREPANCY`, or `UNSUPPORTED_OR_UNVERIFIABLE`, plus a human-readable reason and official source reference where applicable.

- [ ] **Step 3: Run parity test**

Run: `php bin/phpunit tests/Parity/V1ParityMatrixTest.php`
Expected: PASS; no unclassified mismatch.

- [ ] **Step 4: Commit**

```bash
git add tests docs
git commit -m "test: add complete ml pricing parity matrix"
```

### Task 10: Security, privacy, and no-mutation final gate

**Files:**
- Create: `tests/Security/TenantEnumerationTest.php`
- Create: `tests/Security/RetryStormTest.php`
- Create: `tests/Security/NoMarketplaceMutationTest.php`
- Create: `tests/Security/FixturePrivacyTest.php`
- Modify: `tests/Security/RedactionTest.php`

**Interfaces:**
- Produces proof of tenant isolation, bounded retries, token redaction, PII minimization, and zero ML mutation routes/calls

- [ ] **Step 1: Add final security regressions**

Tests must prove tenant A cannot enumerate tenant B; webhook/HTTP client cannot perform SSRF; all retry loops have a hard attempt bound; fixtures contain no access/refresh tokens or buyer contact fields; API client secrets are hashed; ML tokens are encrypted at rest.

- [ ] **Step 2: Enforce no marketplace writes mechanically**

Inspect route collection and `MercadoLivreClient` usage so V1 business code has no method that sends `POST`, `PUT`, `PATCH`, or `DELETE` to item/price/promotion/stock/pricing-automation endpoints. OAuth token exchange is the only provider POST allowed by the auth module.

- [ ] **Step 3: Run security suite**

```bash
php bin/phpunit tests/Security
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests src
git commit -m "test: enforce ml pricing v1 security boundary"
```

### Task 11: Package immutable deployment artifacts

**Files:**
- Create: `ops/deploy/build-release.sh`
- Create: `ops/deploy/install-release.sh`
- Create: `ops/nginx/ml-pricing-api.conf`
- Create: `docs/runbooks/deploy.md`
- Test: `tests/Deployment/ReleaseArtifactTest.php`

**Interfaces:**
- Produces immutable application releases, separate environment/DB/service namespace, independent health endpoint

- [ ] **Step 1: Write RED artifact test**

Assert release artifact excludes `.git`, tests requiring secrets, local `.env`, caches, logs, and runtime token files; it includes locked Composer dependencies/config/migrations/public entrypoint/ops manifest.

- [ ] **Step 2: Implement release scripts**

Shell scripts start with `#!/bin/bash` and `set -Eeuo pipefail`. They create a versioned release directory, install production dependencies from `composer.lock`, run preflight/migration checks, and switch a `current` symlink atomically only after health checks.

- [ ] **Step 3: Run artifact/deploy dry-run tests**

```bash
php bin/phpunit tests/Deployment/ReleaseArtifactTest.php
bash -n ops/deploy/build-release.sh ops/deploy/install-release.sh
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add ops docs tests
git commit -m "chore: package immutable ml pricing releases"
```

### Task 12: End-to-end V1 acceptance and read-only production smoke

**Files:**
- Create: `tests/Live/MercadoLivreV1EndToEndSmokeTest.php`
- Create: `docs/validation/v1-acceptance.md`
- Modify: `.github/workflows/ci.yml` to expose manual live acceptance only

**Interfaces:**
- Produces evidence for all V1 acceptance criteria from the approved spec

- [ ] **Step 1: Run complete deterministic verification**

```bash
composer validate --strict
php bin/phpunit
vendor/bin/phpstan analyse src tests --level=8
git diff --check
```

Expected: all PASS.

- [ ] **Step 2: Run live read-only end-to-end smoke**

The smoke must verify: authorized account identity; active listing sync via current endpoints; User Product/variation persistence when present; current price resource; fee quote with logistics; shipping quote; promotion normalization; one complete/incomplete explainable `ESTIMATED`; recent order `ACTUAL`; one `RECONCILED` when matched billing data exists; webhook idempotency using internal test event; zero ML mutations.

Run:

```bash
RUN_ML_LIVE_TESTS=1 php bin/phpunit tests/Live/MercadoLivreV1EndToEndSmokeTest.php
```

Expected: PASS or explicit SKIP only for the optional live billing case when no matched billing record exists; all non-billing acceptance checks must PASS.

- [ ] **Step 3: Verify repository and fixture hygiene**

```bash
git grep -nE '(APP_USR-|refresh_token|access_token|sk-[A-Za-z0-9_-]{16,})' -- . || true
git status --porcelain
```

Expected: no real credentials/tokens; clean tree after evidence commit.

- [ ] **Step 4: Record V1 acceptance evidence**

`docs/validation/v1-acceptance.md` must map every acceptance criterion from spec section 32 to test name, execution result, source timestamp, and evidence artifact. No criterion is marked complete without reproducible evidence.

- [ ] **Step 5: Commit, PR, CI/review, merge, and post-merge validation**

```bash
git add .
git commit -m "test: complete ml pricing api v1 acceptance"
git push -u origin HEAD
gh pr create --base main --title "feat: complete Mercado Livre pricing API v1" --body "Completes billing reconciliation, ERP cost sync, reports, parity, security, immutable operations, and V1 acceptance evidence."
```

After checks/review are green, merge through repository policy, verify `main`, verify clean working tree, and confirm there is no open/draft PR remaining for this implementation round.
