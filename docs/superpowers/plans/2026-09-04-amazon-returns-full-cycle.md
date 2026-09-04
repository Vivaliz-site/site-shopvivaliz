# Amazon Returns Full-Cycle Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Amazon Returns subsystem automatically execute the approved lifecycle from D+75 eligibility through SAFE-T, appeal, email review, and final financial reconciliation.

**Architecture:** Keep the existing append-only event/outbox model. Improve Seller Central read normalization so the state machine knows whether a denial is the first decision or a denied appeal; add a dedicated email-review outbox action using authenticated Gmail; make policy timing D+75; and deduplicate Finances by economic movement before projection. All external writes remain individually feature-gated and idempotent.

**Tech Stack:** PHP 8.x, MySQL/PDO, Node.js Seller Central CDP worker, Gmail API, Amazon SP-API, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-04-amazon-returns-full-cycle-design.md`

## Global Constraints
- `Data Devolucao` / authoritative physical receipt hard-blocks new SAFE-T.
- D+75 is the current operating eligibility rule.
- Existing claims continue lifecycle even with incomplete historical initiator/program data.
- Unknown browser/Gmail classifications are fail-closed.
- One first appeal per substantive first denial; one email review per substantive denied appeal.
- `Safe-T-Review@amazon.com` is the fixed detailed-review recipient confirmed by Seller Support.
- Approval is not terminal until Finances credit is reconciled.
- External write flags remain off until dry-run/CI gates pass; enable one channel at a time.
- Never log secrets, cookies, OAuth tokens, MFA, authorization headers, or raw PII-heavy browser bodies.

---

### Task 1: Parse the real Amazon decision and appeal outcome

**Files:**
- Modify: `scripts/amazon-returns/safe-t-status-parser.mjs`
- Test: `tests/amazon-returns-browser-contract-test.php`

**Interfaces:**
- Produces normalized read fields: `claim_status`, `decision_text`, `decision_fingerprint`, `appeal_deadline_at`, `appeal_submitted`, `appeal_denied`, `latest_amazon_decision_at`.

- [ ] Add a fixture reproducing a page where original `Motivo` is `Não recebi a devolução` but Amazon's latest message says the claim was outside the eligible period; assert `decision_text` is the Amazon message, not `Motivo`.
- [ ] Add a fixture with a seller appeal followed by an Amazon denial; assert `appeal_submitted=true` and `appeal_denied=true`.
- [ ] Run Amazon Returns browser-contract CI and confirm the new assertions fail for the intended reason.
- [ ] Implement minimal parser changes that select the latest Amazon-authored decision block and detect an appeal/appeal denial without treating the original claim reason as a decision.
- [ ] Re-run the browser-contract test and confirm green.

### Task 2: Project first denial vs denied appeal correctly

**Files:**
- Modify: `includes/amazon-returns/SafeTStatus.php`
- Modify: `includes/amazon-returns/SafeTStatusService.php`
- Test: `tests/amazon-returns-safe-t-status-test.php`
- Test: `tests/amazon-returns-safet-decision-test.php`

**Interfaces:**
- `SAFE_T_STATUS_OBSERVED` carries the parser's appeal metadata.
- First substantive denial projects `SAFE_T_DENIED`; a denial with `appeal_denied=true` projects `APPEAL_DENIED_FINAL`.

- [ ] Add failing tests for first denial and denied-appeal projection.
- [ ] Confirm RED in CI.
- [ ] Preserve the normalized appeal metadata in status normalization/events.
- [ ] Update state mapping so a denied appeal cannot be mistaken for another first denial.
- [ ] Confirm status and decision tests green.

### Task 3: Add the email-review action after a denied appeal

**Files:**
- Create: `includes/amazon-returns/SafeTEmailReview.php`
- Modify: `includes/amazon-returns/RemoteBridge.php` or shared action enums only if needed for validation; email review is not a Seller Central browser write.
- Modify: `includes/amazon-returns/SafeTDecisionEngine.php`
- Modify: `workers/amazon-returns/scheduler.php`
- Modify: `includes/amazon-returns/Outbox.php` only if kind validation requires it.
- Test: `tests/amazon-returns-safet-decision-test.php`
- Create/Test: `tests/amazon-returns-safe-t-email-review-test.php`

**Interfaces:**
- Decision action: `SAFE_T_EMAIL_REVIEW`.
- Idempotency key: `sha256("safe-t-email-review|" + safe_t_id + "|" + denial_fingerprint)`.
- Composer returns `{to,subject,body}` with fixed recipient `Safe-T-Review@amazon.com` and evidence-grounded content.

- [ ] Add failing decision test: `APPEAL_DENIED_FINAL` chooses `SAFE_T_EMAIL_REVIEW`, not another `SAFE_T_APPEAL`/support loop.
- [ ] Add failing composer tests for fixed recipient, SAFE-T/order IDs, real denial reason, optional support case, and no invented fields.
- [ ] Confirm RED in CI.
- [ ] Implement the focused composer and decision transition.
- [ ] Make scheduler enqueue exactly one email-review outbox job per fingerprint.
- [ ] Confirm tests green.

### Task 4: Send review emails through authenticated Gmail and ingest responses

**Files:**
- Modify: `includes/amazon-returns/GmailApiClient.php` (or the repository's actual Gmail transport class discovered during implementation)
- Modify: `includes/amazon-returns/GmailParser.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/amazon-returns-gmail-test.php`
- Test: `tests/amazon-returns-safe-t-email-review-test.php`

**Interfaces:**
- Outbox execution result records Gmail message/thread IDs without storing OAuth material.
- Gmail parser emits `SAFE_T_EMAIL_REVIEW_RESPONSE` with SAFE-T/order correlation and sanitized response classification.

- [ ] Add failing transport test proving an email-review job calls Gmail send with the fixed recipient and deterministic headers/subject.
- [ ] Add failing parser fixtures for a response from/to the review address that contains SAFE-T/order IDs.
- [ ] Confirm RED in CI.
- [ ] Implement send + event persistence with `AMAZON_RETURNS_EMAIL_REVIEW_WRITE` default-off feature gate.
- [ ] Implement response correlation independent of unread state.
- [ ] Confirm Gmail/email-review tests green.

### Task 5: Enforce D+75 atomically

**Files:**
- Modify: `includes/amazon-returns/PolicyEngine.php`
- Modify: `includes/amazon-returns/Schema.php` and/or policy seeding/bootstrap migration used by production
- Test: `tests/amazon-returns-policy-test.php`

**Interfaces:**
- Active production policy evaluates eligible only at seller-exposure D+75 unless a later authoritative Seller Central retry time exists.

- [ ] Add failing tests that D+74 is not eligible and D+75 is eligible for current program paths.
- [ ] Add a migration/bootstrap test proving restart/bootstrap cannot restore 45/60-day values.
- [ ] Confirm RED in CI.
- [ ] Update versioned policy seed/migration and evaluation to D+75.
- [ ] Confirm policy tests green.

### Task 6: Deduplicate Finances economic movements

**Files:**
- Modify: `includes/amazon-returns/SpApi.php` and/or the current Finances normalization component
- Modify: `includes/amazon-returns/FinancialReconciler.php`
- Test: existing Finances/reconciliation Amazon Returns test file(s)

**Interfaces:**
- Preserve every source transaction as evidence.
- Projection receives a deterministic economic key so `DEFERRED_RELEASED` and subsequent `RELEASED` representations of one movement count once.

- [ ] Add failing fixture matching production examples where same amount/economic movement appears twice with different transaction IDs/lifecycle statuses.
- [ ] Assert exposure/expected reimbursement is counted once while both raw transaction events remain representable.
- [ ] Confirm RED in CI.
- [ ] Implement economic-key normalization/deduplication at projection/reconciliation boundary.
- [ ] Confirm finance tests green.

### Task 7: Prevent browser-worker silent PROCESSING leases

**Files:**
- Modify: `scripts/amazon-returns/seller-central-safe-t-read-worker.mjs`
- Test: `tests/amazon-returns-remote-bridge-test.php` or browser worker contract test

**Interfaces:**
- Pending CDP commands reject on WebSocket `close`/`error`; worker submits a failed/retryable job result rather than silently exiting.

- [ ] Add failing contract test for close/error rejection behavior.
- [ ] Confirm RED in CI.
- [ ] Implement pending waiter rejection and cleanup on `close`/`error`.
- [ ] Confirm green.

### Task 8: Full verification, dry-run production audit, staged activation

**Files:**
- No new behavior unless a validation defect is found; any defect starts a new RED→GREEN micro-cycle.

- [ ] Run full `tests/amazon-returns-*.php` CI suite and require zero failures.
- [ ] Deploy merged code with all external write flags still off.
- [ ] Verify production schema/policy reports D+75 and finance projections no longer double-count known duplicate examples.
- [ ] Verify authenticated read worker classifies all known SAFE-T claims and persists real Amazon decision text.
- [ ] Dry-run scheduler: require no engine-caused eligible-without-action gaps, no duplicate appeal/email candidates, and physical-return cases blocked.
- [ ] Enable SAFE-T submit/appeal/email-review channels one at a time only after their respective gates are clean; verify the first real action/result before allowing the next action.
- [ ] Verify denied appeal produces exactly one `SAFE_T_EMAIL_REVIEW` and one Gmail send; verify Gmail response correlation when a response exists.
- [ ] Verify approved/recovered cases remain `CREDIT_PENDING` until Finances credit and only then become `RECOVERED`.
- [ ] Re-run full production health audit and document remaining external waits (Amazon response/credit) separately from software defects.
