# ShopVivaliz Sales-Critical Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make paid-order state, payment processing, attribution and production evidence fail-closed and reconcilable.

**Architecture:** Preserve payment-provider truth separately from ERP reconciliation, persist consented attribution into order context, and replace synthetic health with real provider-backed probes. Keep immutable releases and existing checkout APIs intact.

**Tech Stack:** PHP 8.3, MySQL 8, JavaScript, Bash, systemd, Google Ads/GA4, Mercado Pago, Olist/Tiny.

**Spec:** `docs/superpowers/specs/2026-09-06-shopvivaliz-zero-sales-audit-design.md`

## Global Constraints

- Never change price or stock without official source evidence.
- Never count redirect query parameters as payment approval.
- Never expose credentials or customer PII in logs/tests.
- No production code without a failing regression test first.
- Production changes require PR, merge, immutable deploy and functional post-deploy audit.

---

### Task 1: Transaction evidence and reconciliation

**Files:**
- Create: `includes/order-transaction-evidence.php`
- Modify: `includes/webhook-job-dispatcher.php`
- Modify: `includes/account-schema.php`
- Test: `tests/order-transaction-reconciliation-test.php`

**Interfaces:**
- Produces `svote_record_payment(PDO $pdo, string $orderNumber, array $snapshot): void`.
- Produces `svote_record_reconciliation(PDO $pdo, string $orderNumber, string $provider, string $state, array $details=[]): void`.

- [ ] **Step 1: Write the failing reconciliation test**

```php
$approved = ['provider' => 'mercado_pago', 'status' => 'approved', 'provider_id' => 'pay_123'];
svote_record_payment($pdo, 'SVTEST12345678901', $approved);
$row = $pdo->query("SELECT payment_provider_status, payment_provider_id FROM orders WHERE order_number='SVTEST12345678901'")->fetch();
assert($row['payment_provider_status'] === 'approved');
assert($row['payment_provider_id'] === 'pay_123');
svote_record_reconciliation($pdo, 'SVTEST12345678901', 'olist_tiny', 'cancelled');
$row = $pdo->query("SELECT reconciliation_status FROM orders WHERE order_number='SVTEST12345678901'")->fetch();
assert($row['reconciliation_status'] === 'discrepancy');
```

- [ ] **Step 2: Run RED**

Run: `php tests/order-transaction-reconciliation-test.php`
Expected: FAIL because the evidence columns/helpers do not exist.

- [ ] **Step 3: Implement minimal evidence storage**

Add idempotent schema columns for provider status/id, sanitized evidence JSON, reconciliation state and timestamps. In the webhook dispatcher, record the authoritative provider result immediately after successful provider lookup, then update business status.

- [ ] **Step 4: Run GREEN and regression tests**

Run: `php tests/order-transaction-reconciliation-test.php && php tests/mercadopago-payment-tests.php && php tests/infinitepay-approved-order-flow-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/ tests/ && git commit -m "fix(payments): persist transaction and reconciliation evidence"`

### Task 2: Queue-worker health and stale-job detection

**Files:**
- Create: `api/health/payment-queue.php`
- Modify: `core/queue/queue.php`
- Modify: `scripts/production-functional-audit.sh`
- Test: `tests/payment-queue-health-test.php`
- Test: `tests/test_production_functional_audit_contract.py`

**Interfaces:**
- Produces `sv_queue_health(int $staleSeconds=300): array` with `ok`, counts, `oldest_queued_age_seconds`, and `stale`.
- `/api/health/payment-queue.php` returns no payload/customer data.

- [ ] **Step 1: Write failing health test**

```php
$health = sv_queue_health(300);
assert(array_key_exists('oldest_queued_age_seconds', $health));
assert(array_key_exists('stale', $health));
assert($health['stale'] === 0);
```

- [ ] **Step 2: Run RED**
Run: `php tests/payment-queue-health-test.php`
Expected: FAIL because `sv_queue_health()` does not exist.

- [ ] **Step 3: Implement queue health and functional gate**
Implement metadata-only queue inspection for both SQLite and JSON fallback. Extend production functional audit to fail when a critical payment job is stale/failed.

- [ ] **Step 4: Run GREEN**
Run: `php tests/payment-queue-health-test.php && python3 tests/test_production_functional_audit_contract.py`
Expected: PASS.

- [ ] **Step 5: Commit**
`git add core/queue api/health scripts/production-functional-audit.sh tests/ && git commit -m "fix(payments): gate production on queue health"`

### Task 3: Consented paid-media attribution persistence

**Files:**
- Modify: `includes/order-request-context.php`
- Modify: `api/orders/process-validated.php`
- Modify: `api/orders/create-v2.php`
- Modify: `includes/account-schema.php`
- Test: `tests/google-ads-attribution-and-cro-test.php`

**Interfaces:**
- Order context accepts only allowlisted `gclid`, `gbraid`, `wbraid`, `dclid`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` when consent is accepted.
- MySQL mirror stores attribution as sanitized JSON in `attribution_json`.

- [ ] **Step 1: Extend existing attribution test so it fails**

```php
svorc_set(['gclid' => 'Click123', 'utm_source' => 'google', 'utm_medium' => 'cpc'], [['sku' => 'TEST-1']]);
$body = svorc_body();
assert(($body['attribution']['gclid'] ?? '') === 'Click123');
assert(($body['attribution']['utm_source'] ?? '') === 'google');
```

- [ ] **Step 2: Run RED**
Run: `php tests/google-ads-attribution-and-cro-test.php`
Expected: FAIL because the canonical `attribution` object is not produced.

- [ ] **Step 3: Implement minimal allowlisted persistence**
Normalize length/charset, drop attribution without consent, and write only the canonical attribution object to order JSON/MySQL. Do not log raw identifiers.

- [ ] **Step 4: Run GREEN**
Run: `php tests/google-ads-attribution-and-cro-test.php && php tests/approved-purchase-tracking-fallback-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**
`git add includes/order-request-context.php includes/account-schema.php api/orders tests/google-ads-attribution-and-cro-test.php && git commit -m "fix(analytics): persist consented paid attribution"`

### Task 4: Fail-closed Google Ads and integration readiness

**Files:**
- Modify: `includes/integration-health.php`
- Replace: `scripts/validate-integrations.py`
- Modify: `scripts/google_ads_real_readiness.py`
- Test: `tests/integration-health-credentials-test.php`
- Create: `tests/integration-validator-real-probe-test.py`

**Interfaces:**
- Google Ads health distinguishes `conversion_not_configured`, `api_client_unavailable`, `auth_failed`, and `connected`.
- Legacy validator must never emit an operational result without invoking a real probe.

- [ ] **Step 1: Write failing anti-fake-validator test**

```python
from pathlib import Path
src = Path('scripts/validate-integrations.py').read_text()
assert '99.8%' not in src
assert 'OPERACIONAL' not in src
assert 'includes/integration-health.php' in src or 'integrations-health.php' in src
```

- [ ] **Step 2: Run RED**
Run: `python3 tests/integration-validator-real-probe-test.py`
Expected: FAIL on the hardcoded legacy results.

- [ ] **Step 3: Replace synthetic validator and harden Ads readiness**
Make the legacy entrypoint delegate to real sanitized probes. Require a verified conversion source; missing Ads ID/label is a failure when direct Ads conversion mode is selected. Keep no-secret output.

- [ ] **Step 4: Run GREEN**
Run: `python3 tests/integration-validator-real-probe-test.py && php tests/integration-health-credentials-test.php && python3 scripts/google_ads_real_readiness.py`
Expected: tests PASS; production readiness may report a precise external/config blocker until the account conversion action is configured.

- [ ] **Step 5: Commit**
`git add includes/integration-health.php scripts tests/ && git commit -m "fix(observability): remove synthetic integration health"`

### Task 5: Runtime env/deploy evidence and final validation

**Files:**
- Modify: `docs/knowledge/deploy.md`
- Modify: `docs/knowledge/traffic-visibility-checklist.md`
- Modify only if tests prove required: production env materializer/deploy scripts.
- Test: `tests/runtime-audit-env-permissions-test.php`
- Test: `tests/production-runtime-reconciliation-regression.sh`

- [ ] **Step 1: Add/extend tests for canonical VM release flow and env parser safety**
Require non-assignment garbage in production env to be rejected/sanitized by the materializer and require deploy docs to reference immutable VM releases rather than FTP/HostGator.

- [ ] **Step 2: Run RED**
Run: `php tests/runtime-audit-env-permissions-test.php && bash tests/production-runtime-reconciliation-regression.sh`
Expected: at least the new documentation/env-safety assertion fails before correction.

- [ ] **Step 3: Implement minimal corrections**
Fix documentation drift and the env materialization path that created invalid lines. Back up and sanitize production `shared/.env` by removing only malformed non-assignment lines, never changing secret values.

- [ ] **Step 4: Full local verification**
Run: `php scripts/quality/run-all.php` plus every test changed/created in Tasks 1–5.
Expected: all PASS with no warnings/errors.

- [ ] **Step 5: PR, merge and immutable deploy**
Push `audit/sales-critical-20260906`, open PR, wait for required checks, fix any failure, merge, verify main Actions, then deploy the merged SHA through the canonical production pipeline.

- [ ] **Step 6: Production validation**
Verify repo/main/release/public SHA equality, provider-backed integration health, payment queue health, Apache error logs, and run `scripts/production-functional-audit.sh` until `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.

- [ ] **Step 7: Final causal reconciliation**
Re-query the production database and external providers. Report verified sales separately from test orders, pending payments, cancelled ERP records and unresolved external-account blockers.
