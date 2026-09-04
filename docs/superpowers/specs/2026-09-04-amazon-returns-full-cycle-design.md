# Amazon Returns Full-Cycle Recovery — Design

**Date:** 2026-09-04
**Status:** Approved by operator in project conversation
**Supersedes:** policy timing and post-denial escalation details in `2026-09-01-amazon-returns-safet-design.md`

## Goal
Make the Amazon Returns subsystem execute the complete recovery lifecycle automatically and audibly: identify eligible cases, open SAFE-T, read the real Seller Central decision, appeal a denial, escalate a denied appeal by individual email to `Safe-T-Review@amazon.com`, track the response, reconcile the actual Amazon credit, and close only after financial recovery or a documented terminal loss.

## Non-negotiable business rules
- Historical spreadsheet is backfill-only through 2026-09-03; new cases enter through APIs.
- `Data Devolucao` / authoritative physical receipt hard-blocks any new SAFE-T write.
- `Data Reembolso` is financial history, not proof of physical return.
- Eligibility policy for the current operating rule is D+75 from confirmed seller debit/refund exposure unless Seller Central provides a later authoritative retry date.
- Existing SAFE-T claims continue through their lifecycle even when historical initiator/program classification is incomplete.
- A Seller Central denial must be parsed from the actual decision conversation, not from the original claim reason.
- SAFE-T denial -> appeal in Seller Central while the displayed appeal deadline is valid.
- Appeal denied -> send one individual review email per SAFE-T/order to `Safe-T-Review@amazon.com` with evidence and the real denial/review history.
- Do not send duplicate appeals or duplicate review emails. Idempotency is keyed by SAFE-T and substantive denial/review fingerprint.
- SAFE-T approval is not terminal. The case remains `CREDIT_PENDING` until Finances observes and reconciles the real credit.
- Finances must deduplicate lifecycle representations of the same economic refund/credit (for example DEFERRED_RELEASED followed by RELEASED) before computing exposure or expected reimbursement.
- Unknown/ambiguous parsing is fail-closed and must not trigger a write.
- No secrets, cookies, OAuth tokens, MFA values, or authorization headers in logs or evidence.

## Lifecycle
`ELIGIBLE -> SAFE_T_SUBMIT -> SAFE_T_SUBMITTED -> SAFE_T_READ`

`SAFE_T_DENIED -> SAFE_T_APPEAL -> APPEAL_SUBMITTED -> SAFE_T_READ`

`APPEAL_DENIED_FINAL -> SAFE_T_EMAIL_REVIEW -> EMAIL_REVIEW_SENT -> EMAIL_REVIEW_RESPONSE`

`SAFE_T_APPROVED | APPEAL_APPROVED | EMAIL_REVIEW_APPROVED -> CREDIT_PENDING -> RECOVERED`

A rejected email review may escalate to one Seller Support case when there is a new support-worthy fact or the email channel directs the seller there. No identical-loop escalation is allowed.

## Components
### Seller Central status parser
`scripts/amazon-returns/safe-t-status-parser.mjs` extracts status, appeal deadline, latest Amazon decision text, latest seller message, whether an appeal has already been submitted, and whether Amazon has denied that appeal. Decision extraction must prioritize the latest Amazon-authored decision/review message and exclude the original `Motivo` field.

### Status projection
`includes/amazon-returns/SafeTStatus.php` and `SafeTStatusService.php` persist normalized observations and derive the claim lifecycle. A new substantive Amazon denial after a seller appeal maps to `APPEAL_DENIED_FINAL`; a first denial maps to `SAFE_T_DENIED`.

### Decision engine
`includes/amazon-returns/SafeTDecisionEngine.php` chooses exactly one next action. First denial chooses `SAFE_T_APPEAL`. Denied appeal chooses `SAFE_T_EMAIL_REVIEW`. Approved states wait for Finance reconciliation. Physical receipt and recovered credit are hard stops.

### Email review transport
Create a focused `SafeTEmailReview.php` service and outbox kind `SAFE_T_EMAIL_REVIEW`. It composes one evidence-grounded message per SAFE-T/order and sends through the existing authenticated Gmail API/transport. Recipient is fixed to `Safe-T-Review@amazon.com`. The message contains no invented facts and includes the related Seller Support case only when known.

### Gmail response ingestion
Extend Gmail parsing to recognize replies/threads involving `Safe-T-Review@amazon.com`, map them back to SAFE-T/order, and emit review response events without depending on unread state.

### Policy
`PolicyEngine.php` and seeded/migrated policy data must use D+75 for the operating rule. Bootstrap/migration must not silently restore old D+45/D+60 values.

### Financial deduplication
Finances normalization/reconciliation must identify multiple transaction lifecycle rows that represent the same economic movement and count it once. Transaction IDs remain stored as evidence; economic projection uses a deterministic economic key.

## Feature flags
External writes remain separately gated. Add/retain explicit flags for SAFE-T submit, appeal, email review, and Seller Support. A production mode string alone never enables a write.

## Validation gates
Before external writes are enabled:
1. parser fixtures prove real denial/review extraction;
2. decision tests prove `denied -> appeal -> denied appeal -> email review`;
3. D+75 tests prove no earlier submit;
4. financial fixtures prove duplicate lifecycle transactions count once;
5. Gmail tests prove review-response correlation;
6. full Amazon Returns test suite is green;
7. dry-run production audit has zero eligible-without-action caused by engine defects and zero duplicate-write candidates;
8. browser read worker is authenticated and stable;
9. write flags are enabled one channel at a time and first real action is verified before continuing.
