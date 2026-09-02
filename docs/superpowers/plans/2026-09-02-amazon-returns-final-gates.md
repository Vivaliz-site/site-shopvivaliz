# Amazon Returns Final Gates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Amazon Returns production gate truthful and green by restoring the Fred-Win bridge heartbeat, classifying AFN/FBA orders from Orders v2026, and completing the Reports lifecycle instead of stopping at `reportId` creation.

**Architecture:** Keep external writes fail-closed. Normalize only facts explicitly returned by Amazon, store report cursors/documents without secrets, and define health gates around actionable SAFE-T cases rather than known out-of-scope FBA cases. The daemon will request bounded report windows, poll/download completed documents, persist normalized events, and expose precise readiness.

**Tech Stack:** PHP 8.3, PDO/MySQL, Amazon SP-API Orders v2026-01-01, Finances v2024-06-19, Reports v2021-06-30, Node.js Fred-Win bridge, executable PHP tests.

**Spec:** `docs/superpowers/specs/2026-09-01-amazon-returns-safet-design.md`

## Global Constraints

- Gmail remains redundant evidence, never financial truth.
- Do not invent a SAFE-T API or infer refund initiator without evidence.
- Never log LWA, Gmail, bridge tokens, cookies, or authorization headers.
- SAFE-T, appeal, and support writes remain disabled until an individual case is eligible.
- Every production change follows red-green TDD and ends with a real smoke test.

---

### Task 1: Restore authenticated Fred-Win bridge heartbeat

**Files:**
- Modify: `api/amazon-returns/bridge.php`
- Test: `tests/amazon-returns-remote-bridge-test.php`

**Interfaces:**
- Consumes: Apache request headers and `SELLER_CENTRAL_BRIDGE_TOKEN`.
- Produces: `sv_amz_bridge_auth_header(): string`, accepting `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, or case-insensitive `getallheaders()` fallback.

- [ ] **Step 1: Write the failing test** asserting the endpoint source checks all three safe header sources and never emits the token.
- [ ] **Step 2: Run** `php tests/amazon-returns-remote-bridge-test.php`; expect failure because only `HTTP_AUTHORIZATION` is read.
- [ ] **Step 3: Implement the minimal header resolver**:

```php
foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
    $value = trim((string)($_SERVER[$key] ?? ''));
    if ($value !== '') return $value;
}
foreach (function_exists('getallheaders') ? getallheaders() : [] as $name => $value) {
    if (strcasecmp((string)$name, 'Authorization') === 0) return trim((string)$value);
}
return '';
```

- [ ] **Step 4: Re-run the bridge test** and syntax checks.
- [ ] **Step 5: Commit** `fix: accept forwarded bridge authorization header`.

### Task 2: Classify Amazon-fulfilled orders without inventing SAFE-T facts

**Files:**
- Modify: `includes/amazon-returns/Enums.php`
- Modify: `includes/amazon-returns/SpApiEventSink.php`
- Modify: `admin/amazon-returns/api/summary.php`
- Test: `tests/amazon-returns-spapi-test.php`
- Test: `tests/amazon-returns-admin-test.php`

**Interfaces:**
- Consumes: Orders v2026 `fulfillment.fulfilledBy` and explicit `programs`.
- Produces: `SvAmazonReturnPrograms::FBA` and deterministic `programFromOrder()` precedence: explicit DBA/FBA Onsite first, then `fulfilledBy=AMAZON -> FBA`, `fulfilledBy=MERCHANT -> STANDARD`.
- Produces: health gate SQL that counts missing initiator/debit only for SAFE-T-managed programs (`STANDARD`, `FBA_ONSITE`, `DELIVERY_BY_AMAZON`).

- [ ] **Step 1: Write failing SP-API tests** for `fulfilledBy=AMAZON`, `fulfilledBy=MERCHANT`, and explicit DBA precedence.
- [ ] **Step 2: Write failing admin test** asserting the unclassified gate excludes known FBA while still counting unknown program and incomplete SAFE-T-managed cases.
- [ ] **Step 3: Run both tests** and verify the expected failures.
- [ ] **Step 4: Add `FBA` to the enum and implement the minimal mapping.**
- [ ] **Step 5: Replace the broad gate expression** with:

```sql
CASE
  WHEN program='UNKNOWN' THEN 1
  WHEN program IN ('STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON')
       AND (refund_initiator='UNKNOWN' OR seller_debit_at IS NULL) THEN 1
  ELSE 0
END
```

- [ ] **Step 6: Run SP-API, admin, policy, scheduler, and domain tests.**
- [ ] **Step 7: Commit** `fix: classify Amazon fulfilled returns separately`.

### Task 3: Complete Reports request, poll, download, parse, and persist

**Files:**
- Create: `includes/amazon-returns/ReturnsReport.php`
- Modify: `includes/amazon-returns/SpApi.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/amazon-returns-spapi-test.php`
- Test: `tests/amazon-returns-runtime-test.php`

**Interfaces:**
- `SvAmazonReturnsSpApi::getReport(string $reportId): array`
- `SvAmazonReturnsSpApi::getReportDocument(string $documentId): array`
- `SvAmazonReturnsReport::parse(string $document): list<array<string,mixed>>`
- `SvAmazonReturnsReport::persist(PDO $db, array $row): ?int`
- Rows retain order/item IDs, request dates/status, A-to-Z flag, resolution, return delivery date, SAFE-T state/amount, and refunded amount; raw documents and signed download URLs are never logged.

- [ ] **Step 1: Write failing transport tests** for get-report and get-document paths plus `DONE`, `CANCELLED`, and `FATAL` handling.
- [ ] **Step 2: Write failing parser tests** using a tab-separated fixture with reordered/extra columns, UTF-8 BOM, empty fields, A-to-Z `Y`, and SAFE-T values.
- [ ] **Step 3: Run tests** and confirm failures for missing lifecycle/parser.
- [ ] **Step 4: Implement report status/document retrieval and bounded polling** with no busy loop and no secret-bearing output.
- [ ] **Step 5: Implement the TSV parser** using header names, not column positions, and normalize dates/decimals conservatively.
- [ ] **Step 6: Persist only evidence-backed facts**: A-to-Z `Y -> A_TO_Z`; report SAFE-T identifiers/status; return quantity/date; refund amount as non-financial corroboration. Leave ambiguous initiator as `UNKNOWN`.
- [ ] **Step 7: Change daemon report windows to bounded slices** and save a high-water mark only after a completed document is consumed.
- [ ] **Step 8: Run SP-API/runtime/reliability tests** and verify no raw URL/token is serialized.
- [ ] **Step 9: Commit** `feat: consume official Amazon returns reports`.

### Task 4: Deploy and prove the production gate

**Files:**
- Modify only through the immutable deployment pipeline; keep shared secrets outside Git.

- [ ] **Step 1: Run all Amazon Returns tests, PHP lint, Node syntax, and secret scan.**
- [ ] **Step 2: Merge/deploy the tested SHA and verify the production release symlink.**
- [ ] **Step 3: Restart `shopvivaliz-amazon-returns.service` and run one real daemon cycle.**
- [ ] **Step 4: Run Fred-Win `--heartbeat`; require HTTP 200 / `status=OK`, then verify scheduled polling returns `NO_JOB` while writes are off.**
- [ ] **Step 5: Query readiness and the four DB health gates; require zero unexplained critical gates.**
- [ ] **Step 6: Confirm Gmail, Orders, Finances, Reports, bridge, logs-without-secrets, write flags false, and exact deployed SHA.**
- [ ] **Step 7: Remove all temporary diagnostic/report files from Linux and Fred-Win.**
