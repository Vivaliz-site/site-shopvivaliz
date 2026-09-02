# Amazon Returns & SAFE-T Recovery — Design

**Date:** 2026-09-01
**Status:** Approved for implementation
**Marketplace baseline:** Amazon Brazil (`amazon.com.br`), policy engine marketplace-aware

## Goal
Build a permanent, auditable subsystem inside ShopVivaliz that tracks Amazon returns from refund through physical receipt or recovery, attempts SAFE-T on the first eligible day, handles denials and appeals, escalates repeated non-substantive automated denials through Seller Support/Ajuda, and reconciles the final Amazon credit.

## Non-negotiable rules
- Official Amazon SP-API is preferred wherever it exposes the required data.
- No verified public SP-API SAFE-T filing/appeal endpoint is assumed. SAFE-T and Seller Support writes are isolated behind an authenticated Seller Central browser adapter.
- Gmail is redundant evidence/event detection, never financial source of truth.
- Physical warehouse intake is authoritative for actual physical receipt; carrier `delivered` is provisional.
- For applicable normal return-not-received cases, D+45 from seller refund/debit date is the mandatory first SAFE-T eligibility attempt. If Seller Central blocks, persist the exact reason and retry on the first allowed date.
- Versioned exceptions apply, including FBA Onsite / Delivery by Amazon orders on/after 2026-04-21 that may require D+60.
- No case disappears without a justified terminal state.
- Repeated materially identical automated SAFE-T denial that ignores submitted facts must not cause an identical reply loop; open/update one Seller Support/Ajuda escalation per SAFE-T/reason.
- SAFE-T approval is not terminal until the Amazon financial credit is observed and reconciled.
- No fabricated evidence, policy, tracking, dates, amounts, or claim facts.

## Source hierarchy
For financial state: `SP_API_FINANCES > SELLER_CENTRAL > OFFICIAL_RETURN_REPORT > GMAIL > EXTERNAL_CARRIER`.
For physical receipt: internal warehouse intake is authoritative, with carrier delivery retained as an independent observed fact.

## Architecture

```text
SP-API Orders ─┐
SP-API Finances├──> Event Ingestion ──> Append-only Ledger ──> Case Projector
SP-API Reports ┤                                            │
Notifications ─┤                                            ├─> Policy/Eligibility Engine
Gmail ─────────┤                                            ├─> Denial/Appeal Engine
Warehouse UI ──┘                                            ├─> Financial Reconciler
                                                           └─> Outbox
                                                                 │
                                         ┌───────────────────────┴──────────────────────┐
                                         ▼                                              ▼
                                Seller Central SAFE-T                           Seller Support/Ajuda
                                  Browser Adapter                               Browser Adapter
```

## Module boundaries

### `SvAmazonClient`
Existing LWA/SP-API transport remains the single Amazon authentication layer. Extend only generic transport capabilities when needed; business-specific methods live in dedicated services.

### `SvAmazonReturnsSchema`
`includes/amazon-returns/Schema.php`
- `ensure(PDO $db): void`
- idempotent MySQL DDL only.

### `SvAmazonReturnEventStore`
`includes/amazon-returns/EventStore.php`
- `append(PDO $db, array $event): int`
- `eventsForCase(PDO $db, int $caseId): array`
- deterministic unique event key prevents duplicate ingestion.

### `SvAmazonReturnProjector`
`includes/amazon-returns/Projector.php`
- `project(PDO $db, int $caseId): array`
- calculates current state from immutable events and writes only the projection table.

### `SvAmazonReturnsSpApi`
`includes/amazon-returns/SpApi.php`
- `syncOrder(string $amazonOrderId): array`
- `listTransactions(string $amazonOrderId): array`
- `requestReturnsReport(DateTimeImmutable $from, DateTimeImmutable $to): array`
- `consumeTransactionUpdate(array $notification): array`
Uses Orders `v2026-01-01`, Finances `v2024-06-19`, Reports and Notifications.

### `SvAmazonGmailParser`
`includes/amazon-returns/GmailParser.php`
Pure parser accepting normalized Gmail message payload, returning zero or more domain events. No dependency on unread state.
Recognized families: refund issued, return authorization, SAFE-T registered, SAFE-T update, information request, denial/approval where content permits classification.

### `SvAmazonReturnPolicyEngine`
`includes/amazon-returns/PolicyEngine.php`
- `evaluate(array $case, DateTimeImmutable $now): array`
- selects a versioned policy by marketplace/program/order/refund dates.
- normal applicable path: D+45.
- FBA Onsite/DBA effective 2026-04-21: D+60 candidate rule.
- uncertain cases become `POLICY_REVIEW_REQUIRED`, never guessed.

### `SvAmazonSafeTDecisionEngine`
`includes/amazon-returns/SafeTDecisionEngine.php`
- `nextAction(array $case, array $timeline, array $policy): array`
- pre-write gates: refund confirmed, seller debit/financial exposure confirmed, physical non-receipt, initiator/context classified, no duplicate claim, policy eligibility reached.

### `SvAmazonDenialAnalyzer`
`includes/amazon-returns/DenialAnalyzer.php`
- `normalize(string $text): string`
- `fingerprint(string $text): string`
- `analyze(array $current, string $newText): array`
Repeated denial means normalized/fingerprint-equivalent response plus lack of substantive treatment of submitted facts. It increments `repeated_denial_count` and emits `SUPPORT_ESCALATION_REQUIRED` instead of another identical appeal response.

### `SvAmazonFinancialReconciler`
`includes/amazon-returns/FinancialReconciler.php`
- compares expected reimbursement against observed Amazon transactions.
- supports partial credits and later reversals.

### `SvAmazonReturnsOutbox`
`includes/amazon-returns/Outbox.php`
- `enqueue(PDO $db, string $kind, int $caseId, array $payload, string $idempotencyKey): int`
- `claimBatch(PDO $db, int $limit): array`
- `markSucceeded/markFailed`.
Write kinds: `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`, `ALERT`.

### Seller Central browser adapter
`workers/amazon-returns/seller-central-worker.php` plus isolated automation script under `scripts/amazon-returns/`.
Contract:
- receives one outbox action with immutable case/evidence IDs;
- searches existing claim/case before writing;
- captures eligibility/block reason, protocol ID, visible status and a sanitized evidence snapshot;
- never bypasses MFA/CAPTCHA;
- circuit-breaks when expected UI contract changes;
- writes success/failure as new domain events.

### Admin
`admin/amazon-returns/index.php`: dashboard.
`admin/amazon-returns/intake.php`: mobile-first physical intake.
`admin/amazon-returns/api/*.php`: guarded JSON endpoints.

## Status enums
Current projection states:
`REFUND_DETECTED`, `AWAITING_RETURN`, `IN_TRANSIT`, `CARRIER_DELIVERED_PENDING_PHYSICAL`, `RECEIVED_OK`, `RECEIVED_DISCREPANT`, `SAFE_T_ELIGIBLE`, `SAFE_T_READY`, `SAFE_T_SUBMITTED`, `SAFE_T_APPROVED`, `SAFE_T_DENIED`, `SAFE_T_INFO_REQUESTED`, `APPEAL_REQUIRED`, `APPEAL_SUBMITTED`, `APPEAL_APPROVED`, `APPEAL_DENIED_FINAL`, `CREDIT_PENDING`, `RECOVERED`, `SUPPORT_ESCALATION`, `CLOSED_LOSS`, `POLICY_REVIEW_REQUIRED`.

Terminal states require terminal reason metadata: `RECEIVED_OK`, `RECOVERED`, `CLOSED_LOSS`.

## Refund initiator/context
`AMAZON_AUTOMATIC`, `AMAZON_CUSTOMER_SERVICE`, `SELLER`, `A_TO_Z`, `UNKNOWN`.
`UNKNOWN` cannot pass automated SAFE-T write gate.

## Database schema
All tables use InnoDB/utf8mb4 and timestamps in UTC.

### `amazon_return_cases`
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `amazon_order_id VARCHAR(32) NOT NULL`
- `amazon_order_item_id VARCHAR(64) NOT NULL`
- `marketplace_id VARCHAR(32) NOT NULL`
- `sku VARCHAR(128) NULL`, `asin VARCHAR(32) NULL`
- `quantity_ordered INT NOT NULL DEFAULT 1`
- `quantity_refunded INT NOT NULL DEFAULT 0`
- `quantity_received INT NOT NULL DEFAULT 0`
- `program VARCHAR(64) NOT NULL DEFAULT UNKNOWN`
- `refund_initiator VARCHAR(40) NOT NULL DEFAULT UNKNOWN`
- `refund_at DATETIME NULL`, `seller_debit_at DATETIME NULL`
- `refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0`
- `expected_reimbursement_amount DECIMAL(12,2) NOT NULL DEFAULT 0`
- `reconciled_credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0`
- `physical_status VARCHAR(48) NOT NULL DEFAULT NOT_RECEIVED`
- `state VARCHAR(64) NOT NULL`
- `policy_version_id BIGINT UNSIGNED NULL`
- `eligibility_at DATETIME NULL`, `next_action_at DATETIME NULL`
- `safe_t_id VARCHAR(64) NULL`, `support_case_id VARCHAR(64) NULL`
- `repeated_denial_count INT NOT NULL DEFAULT 0`
- `last_denial_fingerprint CHAR(64) NULL`
- `appeal_deadline_at DATETIME NULL`
- `terminal_reason VARCHAR(128) NULL`, `closed_at DATETIME NULL`
- `created_at`, `updated_at`
Unique: `(amazon_order_id, amazon_order_item_id)`.
Indexes: `(state,next_action_at)`, `(safe_t_id)`, `(support_case_id)`, `(eligibility_at)`, `(seller_debit_at)`.

### `amazon_return_events`
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `case_id BIGINT UNSIGNED NOT NULL`
- `event_type VARCHAR(64) NOT NULL`
- `source VARCHAR(32) NOT NULL`
- `source_event_id VARCHAR(191) NULL`
- `idempotency_key CHAR(64) NOT NULL UNIQUE`
- `occurred_at DATETIME NOT NULL`
- `payload_json JSON NOT NULL`
- `evidence_sha256 CHAR(64) NULL`
- `created_at DATETIME NOT NULL`
Index `(case_id, occurred_at, id)`.
Events are never updated/deleted by normal application flow.

### `amazon_return_policies`
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `policy_key VARCHAR(96) NOT NULL`
- `marketplace_id VARCHAR(32) NOT NULL`
- `program VARCHAR(64) NOT NULL`
- `effective_from DATE NOT NULL`
- `effective_to DATE NULL`
- `eligibility_days INT NOT NULL`
- `basis VARCHAR(32) NOT NULL DEFAULT SELLER_DEBIT_AT`
- `source_url TEXT NOT NULL`
- `source_hash CHAR(64) NOT NULL`
- `status VARCHAR(24) NOT NULL DEFAULT ACTIVE`
- `created_at DATETIME NOT NULL`
Unique `(policy_key, marketplace_id, program, effective_from)`.
Initial normal policy uses 45 days; FBA_ONSITE/DELIVERY_BY_AMAZON effective 2026-04-21 uses 60 where applicable.

### `amazon_return_evidence`
Stores metadata and safe relative storage reference only; binary evidence remains in protected shared storage.
- `id`, `case_id`, `kind`, `source`, `external_id`, `content_sha256`, `storage_ref`, `metadata_json`, `captured_at`, `created_at`.
Unique `(case_id, kind, content_sha256)`.

### `amazon_return_outbox`
- `id`, `case_id`, `kind`, `idempotency_key UNIQUE`, `payload_json`, `status`, `attempt_count`, `available_at`, `locked_at`, `last_error`, `created_at`, `updated_at`.
Indexes `(status,available_at)` and `(case_id,kind)`.

### `amazon_return_dead_letters`
Immutable failure records copied from exhausted outbox attempts, with action kind, payload hash, error class/message, attempt count and timestamps.

### `amazon_return_source_cursors`
Per-source high-water marks for SP-API reports/notifications and Gmail message history; independent of unread state.

### `amazon_return_overrides`
Append-only manual decisions with actor ID, reason, before/after JSON and timestamp.

## Event/idempotency keys
- Gmail: `sha256("gmail|" + gmail_message_id + "|" + event_type + "|" + order_item_id)`.
- Finances: `sha256("finances|" + transaction_id + "|" + order_item_id)`.
- Reports: `sha256("report|" + report_document_id + "|" + row_identity)`.
- Physical intake: generated operation UUID persisted before submit.
- SAFE-T submit: `sha256("safe-t-submit|" + case_id + "|" + policy_version_id + "|" + eligibility_at)`.
- Appeal: `sha256("safe-t-appeal|" + safe_t_id + "|" + denial_fingerprint)`.
- Help escalation: `sha256("support-open|" + safe_t_id + "|" + denial_fingerprint)`.

Before any Seller Central write the adapter searches for an existing SAFE-T/support case and records an `ALREADY_EXISTS` event instead of duplicating.

## Gmail ingestion contract
A Gmail connector/worker normalizes each message to `{message_id, thread_id, from, subject, received_at, body_text, body_hash}`. Parser returns domain events. Store message ID/hash and only the minimum sanitized evidence necessary. Do not use `UNREAD` as a cursor. Backfill uses time windows and source cursor.

## SAFE-T gate
`SAFE_T_SUBMIT` may be enqueued only when all are true:
1. seller financial exposure/refund is confirmed;
2. initiator/context is not `UNKNOWN` and policy permits the case;
3. physical item/quantity remains unreceived for the claim reason;
4. eligibility time is reached;
5. no existing SAFE-T claim is known for this same event;
6. write feature flag is enabled;
7. evidence bundle contains timeline, refund/debit proof and return/tracking facts available.

At D+45 for normal applicable cases the adapter must attempt eligibility even if historical guidance also mentions later proactive-reimbursement windows. A Seller Central block is persisted verbatim and becomes the authority for the next retry calculation, subject to policy validation.

## Repeated denial -> Ajuda rule
Normalize whitespace/case/punctuation and volatile IDs/dates before SHA-256 fingerprinting. If a new denial fingerprint matches the previous substantive template, the prior response already supplied the disputed facts, and the new denial does not materially address those facts, emit `REPEATED_AUTOMATED_DENIAL`. Do not enqueue an identical SAFE-T response. If no active support escalation exists for `(safe_t_id, denial_fingerprint)`, enqueue `SELLER_SUPPORT_OPEN`; otherwise enqueue `SELLER_SUPPORT_UPDATE` only when there is a new fact or status change.

## Feature flags
Environment values, default safe:
- `AMAZON_RETURNS_ENABLED=0`
- `AMAZON_RETURNS_MODE=dry-run`
- `AMAZON_RETURNS_GMAIL_INGEST=0`
- `AMAZON_RETURNS_SAFE_T_WRITE=0`
- `AMAZON_RETURNS_APPEAL_WRITE=0`
- `AMAZON_RETURNS_SUPPORT_WRITE=0`
- `AMAZON_RETURNS_POLICY_MONITOR=0`

`production` mode alone never enables write flags.

## Workers and cadence
- transaction notification consumer: event-driven, reconciliation every 15 min;
- Orders/Finances reconciliation: every 30 min for active cases;
- returns report sync: every 2 h plus daily 180-day reconciliation slice;
- Gmail ingestion: every 5 min if push is unavailable, plus daily reconciliation;
- policy/eligibility scheduler: every 10 min;
- Seller Central outbox worker: every 5 min, bounded rate, one action per case lock;
- financial reconciliation: every 30 min for approved/credit-pending cases;
- health gates/alerts: every 15 min;
- policy monitor: daily, creates review candidate/version rather than silently mutating active history.

## Backoff and DLQ
Attempts use 1m, 5m, 15m, 1h, 6h with jitter; deadline-critical actions cap backoff so another attempt occurs before the stored deadline. Authentication/MFA/CAPTCHA and UI-contract drift open the circuit breaker immediately rather than repeated retries. Exhausted recoverable actions go to DLQ and create a critical alert.

## Dashboard
Metrics: money at risk, eligible now, SAFE-T submitted, denied, appeal due, support escalation, approved awaiting credit, recovered, documented loss. Health gates shown prominently: unclassified, eligible without action, expired without treatment, credit without reconciliation; production target is zero for all four.

## Security
- secrets only from protected environment/secret stores;
- never log LWA tokens, Gmail tokens, Seller Central cookies or MFA values;
- evidence snapshots sanitize PII and authorization material;
- admin endpoints require existing admin guard and CSRF protections for writes;
- browser session runs on background authorized VM; no CAPTCHA/MFA bypass;
- evidence files live outside public document root in protected shared storage.

## Rollout
1. schema + deterministic unit tests;
2. dry-run SP-API/Gmail ingestion;
3. 90–180 day historical backfill and golden fixtures;
4. shadow policy decisions compared with Seller Central manually/read-only;
5. controlled live SAFE-T pilot with one eligible case and duplicate prevention verified;
6. controlled appeal/support escalation pilot;
7. enable production writes after zero unexplained health-gate discrepancies;
8. ongoing reconciliation and policy monitoring.

Rollback disables all three write flags first. Read/ingest/reconciliation can remain active. Immutable application release rollback is independent of database event history; append-only events are not deleted on rollback.

## Testing matrix
- schema idempotency: ensure twice without drift;
- event append duplicate returns same logical result/no duplicate row;
- D+44 not eligible, D+45 eligible normal path;
- FBA Onsite/DBA post-2026-04-21 evaluates D+60;
- `UNKNOWN` refund initiator blocks write;
- carrier delivered without physical intake stays unresolved;
- physical receipt stops non-return SAFE-T path;
- partial quantities compute remaining exposure correctly;
- duplicate Gmail/SP-API event deduplicates;
- SAFE-T already exists prevents duplicate write;
- repeated denial fingerprints trigger one support escalation, not appeal loop;
- approval without financial credit remains `CREDIT_PENDING`;
- partial credit stays open for difference;
- reversal reopens financial exposure;
- MFA/CAPTCHA opens circuit breaker and preserves action/deadline;
- worker restart resumes outbox without duplicate external write;
- 180-day backfill is idempotent;
- dashboard health gates reconcile against database queries;
- browser-real admin intake and dashboard validation desktop/mobile before merge.

## Acceptance criteria
- All golden fixtures pass deterministically.
- D+45 normal eligible case produces exactly one eligibility/write intent; D+60 exception is respected.
- No SAFE-T/appeal/support write occurs with write flag off.
- Replaying the same source data produces zero duplicate events, claims, appeals or support cases.
- A repeated automatic denial creates/updates one Ajuda escalation and does not generate an identical SAFE-T reply loop.
- SAFE-T approval remains open until matching credit is observed.
- All four health gates are zero after reconciliation for controlled pilot data.
- CI/lint/tests pass; PR merges through repository workflow; immutable production release matches merged SHA.
- Real browser validates dashboard/intake functionality; controlled Seller Central pilot independently confirms protocol/status without duplicate write.
