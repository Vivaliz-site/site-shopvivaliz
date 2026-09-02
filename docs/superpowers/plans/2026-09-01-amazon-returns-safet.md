# Amazon Returns & SAFE-T Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement an auditable Amazon Brazil returns and SAFE-T recovery subsystem that ingests official Amazon/Gmail events, tracks physical returns, attempts SAFE-T at the first eligible date, escalates repeated automated denials to Ajuda, and reconciles the final credit.

**Architecture:** Extend the existing LWA/SP-API transport and PHP/PDO/admin patterns with an append-only event ledger, deterministic projector/policy engine and transactional outbox. Official SP-API handles data sources; an isolated background Seller Central adapter handles only SAFE-T/Ajuda operations for which no verified public endpoint exists.

**Tech Stack:** PHP 8.3, PDO/MySQL, existing ShopVivaliz admin, SP-API LWA, Gmail connector/API, background browser automation, repository executable PHP tests, immutable production deploy.

**Spec:** `docs/superpowers/specs/2026-09-01-amazon-returns-safet-design.md`

## Global Constraints
- D+45 seller debit/refund date is the mandatory first eligibility attempt for applicable normal non-return cases.
- FBA Onsite / Delivery by Amazon effective 2026-04-21 is policy-versioned and may require D+60.
- Never invent a SAFE-T SP-API endpoint; browser adapter only when no verified official API exists.
- Gmail is redundant evidence/event detection, not financial truth.
- Physical warehouse intake is authoritative for receipt.
- No duplicate claim, appeal or support ticket; all external writes are idempotent and outbox-driven.
- Repeated materially identical automated denial that ignores facts escalates to one Ajuda case instead of an identical reply loop.
- MFA/CAPTCHA are never bypassed.
- All write feature flags default off.
- No direct main changes: branch -> tests -> commit -> push -> PR -> checks -> merge -> deploy -> real validation.

## File map
- Create `includes/amazon-returns/Schema.php` — idempotent DB schema.
- Create `includes/amazon-returns/Enums.php` — domain constants/state validation.
- Create `includes/amazon-returns/EventStore.php` — append-only events.
- Create `includes/amazon-returns/Projector.php` — current case projection.
- Create `includes/amazon-returns/PolicyEngine.php` — D+45/D+60 versioned eligibility.
- Create `includes/amazon-returns/GmailParser.php` — pure Gmail event parser.
- Create `includes/amazon-returns/SpApi.php` — Orders/Finances/Reports/notification facade using `SvAmazonClient`.
- Modify `includes/marketplace/AmazonPublisher.php` — expose/refine generic transport only if required by facade.
- Create `includes/amazon-returns/SafeTDecisionEngine.php` — pre-write gates.
- Create `includes/amazon-returns/DenialAnalyzer.php` — denial fingerprint/escalation decision.
- Create `includes/amazon-returns/FinancialReconciler.php` — credit/partial/reversal logic.
- Create `includes/amazon-returns/Outbox.php` — outbox/retry/DLQ.
- Create `scripts/amazon-returns/seller-central-adapter.mjs` — isolated browser contract.
- Create `workers/amazon-returns/*.php` — ingestion, scheduler, outbox and reconciliation runners.
- Create `admin/amazon-returns/index.php`, `intake.php`, `api/*.php` — dashboard/intake.
- Create `tests/amazon-returns-*.php` — executable regression tests.
- Modify `.github/workflows/shopvivaliz-qa.yml` — add dedicated regression gate.
- Modify `docs/AGENTS.md` — record non-obvious external API/policy behavior after validation.

### Task 1: Domain schema, enums and append-only event store

**Files:**
- Create: `includes/amazon-returns/Enums.php`
- Create: `includes/amazon-returns/Schema.php`
- Create: `includes/amazon-returns/EventStore.php`
- Test: `tests/amazon-returns-domain-test.php`

**Interfaces:**
- Produces `SvAmazonReturnsSchema::ensure(PDO): void`, `SvAmazonReturnEventStore::append(PDO,array): int`, `eventsForCase(PDO,int): array`, domain constants.

- [ ] **Step 1: Write failing domain test** covering state enum presence, terminal states, SQL/table definitions, and duplicate idempotency key behavior using the repository test database fixture convention or an injectable fake PDO-compatible seam.
- [ ] **Step 2: Verify failure** with `php tests/amazon-returns-domain-test.php`; expected non-zero because classes/files do not exist.
- [ ] **Step 3: Implement minimal schema/enums/event store** with all tables and indexes defined by the spec; events are insert-only and duplicate idempotency keys return the existing event ID.
- [ ] **Step 4: Verify pass** with `php -l includes/amazon-returns/Enums.php && php -l includes/amazon-returns/Schema.php && php -l includes/amazon-returns/EventStore.php && php tests/amazon-returns-domain-test.php`.
- [ ] **Step 5: Commit** `git add ... && git commit -m "feat: add Amazon returns event domain"`.

### Task 2: Projection and policy eligibility engine

**Files:**
- Create: `includes/amazon-returns/Projector.php`
- Create: `includes/amazon-returns/PolicyEngine.php`
- Test: `tests/amazon-returns-policy-test.php`

**Interfaces:**
- Consumes event store/domain constants.
- Produces `SvAmazonReturnProjector::project(PDO,int): array`, `SvAmazonReturnPolicyEngine::evaluate(array,DateTimeImmutable): array`.

- [ ] **Step 1: Write failing tests** for D+44 false, D+45 true, D+60 exception, effective-date selection, `UNKNOWN` policy-review behavior, physical receipt precedence and partial quantities.
- [ ] **Step 2: Run** `php tests/amazon-returns-policy-test.php`; expected non-zero before implementation.
- [ ] **Step 3: Implement projector and deterministic policy selection** using UTC internally and returning `eligible`, `eligibility_at`, `policy_version_id`, `reason`.
- [ ] **Step 4: Run lint + both domain/policy tests** and require exit 0.
- [ ] **Step 5: Commit** `feat: add Amazon returns policy engine`.

### Task 3: SP-API ingestion facade and financial truth

**Files:**
- Create: `includes/amazon-returns/SpApi.php`
- Modify: `includes/marketplace/AmazonPublisher.php` only to make the reusable transport safely accessible/testable.
- Test: `tests/amazon-returns-spapi-test.php`

**Interfaces:**
- Produces `syncOrder`, `listTransactions`, `requestReturnsReport`, `consumeTransactionUpdate`.

- [ ] **Step 1: Write failing transport-contract tests** asserting Orders path version `2026-01-01`, Finances path version `2024-06-19`, `ORDER_ID` filtering, request IDs retained, and no SAFE-T endpoint is exposed.
- [ ] **Step 2: Run failing test** `php tests/amazon-returns-spapi-test.php`.
- [ ] **Step 3: Implement facade** over existing `SvAmazonClient`; redact secrets and map raw Amazon responses into normalized events without treating Gmail as financial truth.
- [ ] **Step 4: Run lint/tests**, including existing `php tests/integration-health-credentials-test.php` to guard auth regressions.
- [ ] **Step 5: Commit** `feat: ingest Amazon returns financial events`.

### Task 4: Gmail parser and source cursor semantics

**Files:**
- Create: `includes/amazon-returns/GmailParser.php`
- Create: `workers/amazon-returns/gmail-ingest.php`
- Test: `tests/amazon-returns-gmail-test.php`

**Interfaces:**
- Produces pure `parse(array $message): array` and cursor-based ingestion independent of unread labels.

- [ ] **Step 1: Add sanitized golden fixtures** inside the test for `REFUND_ISSUED`, return authorization, SAFE-T registered and SAFE-T update subjects/bodies.
- [ ] **Step 2: Verify test fails** before parser exists.
- [ ] **Step 3: Implement parser** extracting order ID, SAFE-T ID, amount/date where present and message content hash; ignore unrelated Amazon messages.
- [ ] **Step 4: Verify replay of the same Gmail message yields the same idempotency key and no duplicate domain event**.
- [ ] **Step 5: Commit** `feat: parse Amazon return events from Gmail`.

### Task 5: SAFE-T decision, denial fingerprint and Ajuda escalation

**Files:**
- Create: `includes/amazon-returns/SafeTDecisionEngine.php`
- Create: `includes/amazon-returns/DenialAnalyzer.php`
- Test: `tests/amazon-returns-safet-decision-test.php`

**Interfaces:**
- Produces deterministic next actions: `WAIT`, `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`, `BLOCKED_REVIEW`.

- [ ] **Step 1: Write failing tests** for all pre-SAFE-T gates, duplicate claim suppression, repeated denial normalization/fingerprint, one active support escalation, and new-fact update behavior.
- [ ] **Step 2: Verify failure** with the dedicated test.
- [ ] **Step 3: Implement decision engine** so a repeated automated denial never emits an identical appeal action; it emits one support escalation keyed by SAFE-T + fingerprint.
- [ ] **Step 4: Run domain/policy/decision regression tests** exit 0.
- [ ] **Step 5: Commit** `feat: automate SAFE-T and support escalation decisions`.

### Task 6: Outbox, DLQ, retries and financial reconciliation

**Files:**
- Create: `includes/amazon-returns/Outbox.php`
- Create: `includes/amazon-returns/FinancialReconciler.php`
- Create: `workers/amazon-returns/scheduler.php`
- Create: `workers/amazon-returns/reconcile.php`
- Test: `tests/amazon-returns-reliability-test.php`

**Interfaces:**
- Produces idempotent enqueue/claim/success/failure methods and reconciliation actions.

- [ ] **Step 1: Write failing tests** for duplicate enqueue, retry sequence, deadline-aware retry, DLQ, restart recovery, approval-without-credit -> `CREDIT_PENDING`, partial credit and reversal reopening exposure.
- [ ] **Step 2: Verify failure**.
- [ ] **Step 3: Implement transactional outbox/reconciler** with case-level locks and bounded attempts.
- [ ] **Step 4: Run all Amazon return PHP tests twice** to prove replay idempotency.
- [ ] **Step 5: Commit** `feat: add reliable Amazon returns workers`.

### Task 7: Seller Central background adapter

**Files:**
- Create: `scripts/amazon-returns/seller-central-adapter.mjs`
- Create: `workers/amazon-returns/seller-central-worker.php`
- Test: `tests/amazon-returns-browser-contract-test.php`

**Interfaces:**
- Consumes outbox action JSON; produces structured result with `status`, `external_id`, `block_reason`, `next_allowed_at`, sanitized evidence metadata.

- [ ] **Step 1: Write failing contract tests** requiring dry-run default, write flags, existing-claim lookup before submit, MFA/CAPTCHA pause, UI-contract circuit breaker and support-case dedupe.
- [ ] **Step 2: Run failing PHP contract test and `node --check` after the file exists**.
- [ ] **Step 3: Implement adapter contract** with selectors/config isolated from business logic and no credential material in repo/logs.
- [ ] **Step 4: Validate in read-only/dry-run against an authorized Seller Central session**, proving one known order can be located and eligibility/status read without write. Capture sanitized evidence.
- [ ] **Step 5: Commit** `feat: add Seller Central SAFE-T adapter`.

### Task 8: Admin intake/dashboard and APIs

**Files:**
- Create: `admin/amazon-returns/index.php`
- Create: `admin/amazon-returns/intake.php`
- Create: `admin/amazon-returns/api/summary.php`
- Create: `admin/amazon-returns/api/intake.php`
- Create: `admin/amazon-returns/api/case.php`
- Test: `tests/amazon-returns-admin-test.php`

**Interfaces:**
- Admin summary returns money buckets + four health gates; intake appends physical events with operation idempotency key.

- [ ] **Step 1: Write failing source/behavior tests** for admin guard, CSRF/write semantics, no physical state inferred from carrier, health-gate query contract.
- [ ] **Step 2: Verify failure**.
- [ ] **Step 3: Implement mobile-first UI/APIs** using existing admin conventions.
- [ ] **Step 4: Run lint/admin regression test** then validate in a real authorized browser on desktop and mobile viewport, including one non-destructive test intake fixture or staging case and rollback/cleanup through an auditable event rather than deletion.
- [ ] **Step 5: Commit** `feat: add Amazon returns operations dashboard`.

### Task 9: Backfill, golden replay and policy monitor

**Files:**
- Create: `workers/amazon-returns/backfill.php`
- Create: `workers/amazon-returns/policy-monitor.php`
- Test: `tests/amazon-returns-backfill-test.php`

**Interfaces:**
- Backfill accepts bounded dates (default 180 days); policy monitor creates candidate/new versions, never rewrites historical policy IDs.

- [ ] **Step 1: Write failing replay tests** for 90/180-day windows and all golden scenarios.
- [ ] **Step 2: Verify failure**.
- [ ] **Step 3: Implement bounded resumable backfill and policy version candidate creation**.
- [ ] **Step 4: Run historical dry-run against real account data with all external write flags off** and record counts: ingested, deduped, unclassified, D+45 candidates, D+60 candidates, already reimbursed, active SAFE-T, denied, support escalation candidates.
- [ ] **Step 5: Commit** `feat: backfill Amazon return recovery cases`.

### Task 10: CI, documentation, end-to-end pilot, PR/merge/deploy

**Files:**
- Modify: `.github/workflows/shopvivaliz-qa.yml`
- Modify: `docs/AGENTS.md`
- Test: all `tests/amazon-returns-*.php`, PHP lint on changed PHP, `node --check scripts/amazon-returns/seller-central-adapter.mjs`.

**Interfaces:** final delivery gate.

- [ ] **Step 1: Add CI commands** for every Amazon returns test and JS syntax check.
- [ ] **Step 2: Run complete local regression**: all Amazon tests twice, changed-file PHP lint, existing Amazon/credential regression, `git diff --check`, secret scan on changed files.
- [ ] **Step 3: Run dry-run/shadow historical reconciliation until four controlled-data health gates are zero or every non-zero is explicitly classified with evidence.**
- [ ] **Step 4: Controlled live pilot**: enable only SAFE-T write for one verified eligible case, prove preexisting claim lookup, submit once if Seller Central accepts, independently observe SAFE-T ID/status; if Seller Central blocks, record exact block/next date and verify no duplicate attempt. Then test a repeated-denial case through one Ajuda escalation when eligible, never looping an identical reply.
- [ ] **Step 5: Commit docs/CI**, push branch, open PR, inspect all checks, fix failures, obtain repository-permitted approval, merge without bypass.
- [ ] **Step 6: Observe immutable deploy automatically**; prove active release SHA equals merged SHA, relevant workers/config are available, dashboard loads in real browser, and write flags remain at the intended rollout values.
- [ ] **Step 7: Post-deploy smoke/reconciliation**: no duplicate claim/support cases, no worker fatal errors, source cursors advance, and controlled pilot financial state matches SP-API/Seller Central evidence.
- [ ] **Step 8: Final git verification**: source worktree clean, branch merged, no relevant untracked files, and production verification recorded as COMPROVADO/FALHOU/INCONCLUSIVO with raw evidence references.
