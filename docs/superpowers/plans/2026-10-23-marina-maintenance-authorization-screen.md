# Marina Maintenance Authorization Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fail-closed ShopVivaliz admin screen that prepares and submits an externally signed, fixed-scope Marina maintenance ticket without issuing leases or running host commands from the web application.

**Architecture:** The admin UI and PHP endpoints call one narrow broker client. The client is capability-gated and returns `NAO_APTO` when the official private broker route is not provisioned; it never falls back to SSH, shell, `runtime_lock`, OCI, or arbitrary MCP commands. A separate external device/CLI signs the canonical broker ticket; only the detached signature returns to the authenticated admin session.

**Tech Stack:** PHP 8.x with strict types, existing ShopVivaliz session/CSRF conventions, vanilla HTML/CSS/JavaScript, PHPUnit-style repository PHP tests, and real-browser E2E evidence required by project governance.

**Spec:** `docs/superpowers/specs/2026-10-23-marina-maintenance-authorization-screen-design.md`

## Global Constraints

- The fixed operation is exactly `retire_origin_abandoned_cart_scheduler`.
- The browser must never generate, import, export, persist, or transmit the private signing key.
- The server must never accept a private-key field.
- The web application must not invoke SSH, shell, `runtime_lock.py`, cron, systemd, OCI, or arbitrary MCP commands.
- Missing broker/private route, operator registry, lease/fencing, or fresh preconditions must remain `NAO_APTO`.
- `INDETERMINATE_INSPECTION_REQUIRED` disables retry and cannot become success through HTTP 200, ACK, or refresh.
- Production readiness requires the absolute audit and `AUDIT_VERDICT=APTO` for the same release/environment/scope.
- Preserve all unrelated dirty-worktree changes.

## Review Focus

- **Unprovisioned broker:** every endpoint must return a truthful `NAO_APTO`/`broker_not_provisioned` state without attempting a public-route fallback. Test in Task 1 and Task 2.
- **Signature payload tampering:** changing operation, host, nonce, expiry, or canonical ticket bytes must be rejected before broker submission. Test in Task 1 and Task 3.
- **Replay and stale approvals:** an expired, already-used, or state-invalidated ticket must not be submitted twice. Test in Task 1 and Task 2.
- **Secret leakage:** private keys, bearer tokens, raw host output, and unredacted signatures must not enter response bodies or audit logs. Test in Task 2 and Task 4.
- **Ambiguous execution:** timeout/unknown broker outcomes must freeze retry and show `INDETERMINATE_INSPECTION_REQUIRED`. Test in Task 2 and Task 4.

## File Map

### New files

- `includes/marina-maintenance/contract.php` — fixed operation, schema, canonicalization, redaction, and state mapping.
- `includes/marina-maintenance/broker-client.php` — fail-closed broker adapter; no shell or host mutation.
- `api/admin/marina-maintenance-status.php` — authenticated read-only preflight endpoint.
- `api/admin/marina-maintenance-preview.php` — authenticated fixed-scope preview endpoint.
- `api/admin/marina-maintenance-approve.php` — authenticated signed-envelope submission endpoint.
- `api/admin/marina-maintenance-operation.php` — authenticated bounded operation lookup endpoint.
- `admin/marina-maintenance.php` — protected admin page.
- `admin/assets/marina-maintenance.js` — UI state machine, ticket display/download, signature submission, and bounded polling.
- `tests/php/marina_maintenance_contract_test.php` — pure contract/security tests.
- `tests/php/marina_maintenance_broker_test.php` — broker adapter and failure-state tests.
- `tests/marina-maintenance-admin-test.php` — static/admin route contract tests.
- `tests/marina-maintenance-browser-contract.sh` — browser journey contract and evidence preflight.
- `docs/ops/marina-maintenance-authorization-screen.md` — operator enrollment/signing/recovery runbook.

### Modified files

- `admin/index.php` — add a navigation card/link to the protected screen.
- Existing test/index manifests only if the repository requires explicit registration.

## Implementation Tasks

### Task 1: Define the fixed contract and fail-closed broker adapter

**Files:**
- Create: `includes/marina-maintenance/contract.php`
- Create: `includes/marina-maintenance/broker-client.php`
- Test: `tests/php/marina_maintenance_contract_test.php`
- Test: `tests/php/marina_maintenance_broker_test.php`

**Interfaces:**
- Produces `sv_marina_maintenance_operation(): string` returning the fixed operation constant.
- Produces `sv_marina_canonical_ticket(array $ticket): string` using deterministic JSON with recursively sorted object keys and no secret fields.
- Produces `sv_marina_validate_ticket(array $ticket, int $now): array` with `ok`, `code`, and normalized metadata.
- Produces `SvMarinaMaintenanceBrokerClient::status(): array`, `preview(): array`, `approve(array $ticket, string $signature): array`, and `operation(string $operationId): array`.

- [ ] **Step 1: Write failing contract tests** for the fixed operation, canonical key ordering, required fields, maximum ticket lifetime of 180 seconds, rejection of private-key/host/command extras, and redacted audit projection.
- [ ] **Step 2: Run the focused tests** and confirm they fail because the contract functions/classes do not exist.
- [ ] **Step 3: Implement the pure contract** with strict allow-lists. Reject unknown fields where the broker contract requires a closed envelope; never canonicalize a private key or bearer token.
- [ ] **Step 4: Write failing broker tests** for missing configuration, public-route configuration, malformed broker responses, timeout, refused operation, and successful reconciled response using an injected fake transport only in tests.
- [ ] **Step 5: Implement the broker adapter**. Read only the documented broker configuration; if URL, private-route identity, audience, or transport capability is absent, return `NAO_APTO`/`broker_not_provisioned`. The production adapter must use an injectable transport boundary and must not call `exec`, `shell_exec`, SSH, runtime-lock modules, or arbitrary command APIs.
- [ ] **Step 6: Run both focused test files** and require PASS, including tests proving no public fallback and no secret leakage.
- [ ] **Step 7: Commit** with `feat: add fail-closed Marina maintenance contract`.

### Task 2: Add authenticated admin API endpoints

**Files:**
- Create: `api/admin/marina-maintenance-status.php`
- Create: `api/admin/marina-maintenance-preview.php`
- Create: `api/admin/marina-maintenance-approve.php`
- Create: `api/admin/marina-maintenance-operation.php`
- Test: `tests/marina-maintenance-admin-test.php`

**Interfaces:**
- `GET /api/admin/marina-maintenance-status.php` returns `{ok, state, release, preflight, freshness, audit_id}`.
- `POST /api/admin/marina-maintenance-preview.php` consumes the existing admin session plus CSRF and returns a fixed-scope preview or a refusal code.
- `POST /api/admin/marina-maintenance-approve.php` consumes `{csrf_token, ticket, signature_b64}` and returns only broker operation metadata/state; private-key fields are rejected.
- `GET /api/admin/marina-maintenance-operation.php?operation_id=...` returns bounded broker status and reconciliation state.

- [ ] **Step 1: Write failing route tests** requiring `includes/admin-guard.php`, JSON/no-store headers, CSRF validation for mutating routes, fixed operation scope, strict body limits, and explicit `401/403/409/422/503` refusal behavior.
- [ ] **Step 2: Run the route tests** and confirm failure for missing endpoints/guards.
- [ ] **Step 3: Implement the status endpoint** as read-only. It must expose only redacted preflight data and preserve `UNKNOWN`, `NAO_APTO`, `REFUSED`, and `SUCCEEDED_RECONCILED` as distinct states.
- [ ] **Step 4: Implement preview** with `admin-guard`, `sv_csrf_valid('admin-marina-maintenance')`, recent-auth/MFA policy hook if the repository exposes one, and no user-controlled host/operation parameters.
- [ ] **Step 5: Implement approve** with bounded JSON parsing, exact ticket validation, detached-signature shape validation, replay/idempotency protection, and the broker adapter call. Reject any `private_key`, `secret`, `command`, `hostname`, `lease_id`, or `fencing_token` input supplied by the browser.
- [ ] **Step 6: Implement operation lookup** with strict operation ID format, bounded polling semantics, and no retry/mutation behavior.
- [ ] **Step 7: Run route tests and PHP syntax checks**; require all refusal and redaction assertions to pass.
- [ ] **Step 8: Commit** with `feat: expose guarded Marina maintenance endpoints`.

### Task 3: Build the protected admin screen and navigation entry

**Files:**
- Create: `admin/marina-maintenance.php`
- Create: `admin/assets/marina-maintenance.js`
- Modify: `admin/index.php`
- Test: `tests/marina-maintenance-admin-test.php`

**Interfaces:**
- Page exposes `window.SV_MARINA_MAINTENANCE` with CSRF token and endpoint URLs only; it contains no secret or broker credential.
- JavaScript functions `loadStatus()`, `requestPreview()`, `renderTicket()`, `submitApproval()`, and `pollOperation()` implement the state machine and bounded polling.

- [ ] **Step 1: Extend the static/admin tests** for admin guard inclusion, CSRF scope, disabled approval before `APPROVAL_REQUIRED`, absence of private-key inputs, absence of free-form host/command fields, and navigation link.
- [ ] **Step 2: Run the tests** and confirm the screen/link are absent.
- [ ] **Step 3: Implement the page** using existing admin visual conventions, with state cards for origin, Marina, broker/private route, operator registry, freshness, preview/audit IDs, and an explicit blocked reason.
- [ ] **Step 4: Implement the JavaScript state machine**. Render all unknown/stale/refused states fail-closed; display a human-readable fixed scope; provide machine-readable ticket download; accept only the detached signature; never use `localStorage`, `sessionStorage`, IndexedDB, clipboard automation, or hidden private-key fields for signing material.
- [ ] **Step 5: Add bounded polling** with cancellation on reload/session expiry, no automatic retry after refused/indeterminate results, and final status `SUCCEEDED_RECONCILED` only when returned by the broker.
- [ ] **Step 6: Add the admin navigation entry** without changing unrelated admin routes.
- [ ] **Step 7: Run static tests and PHP lint** and require PASS.
- [ ] **Step 8: Commit** with `feat: add Marina maintenance authorization screen`.

### Task 4: Document operator enrollment, external signing, and recovery

**Files:**
- Create: `docs/ops/marina-maintenance-authorization-screen.md`
- Modify: `docs/superpowers/specs/2026-10-23-marina-maintenance-authorization-screen-design.md` only if implementation contracts require a reviewed correction.
- Test: `tests/marina-maintenance-browser-contract.sh`

**Interfaces:**
- Runbook defines operator enrollment, external signing command/input format, ticket expiry, one-use behavior, broker refusal codes, indeterminate recovery, and proof required for reconciliation.

- [ ] **Step 1: Write the browser-contract test** for required UI journeys: read-only `NAO_APTO`, missing broker, approval-required, refusal, indeterminate, reload/session expiry, and reconciled state.
- [ ] **Step 2: Run it** and record the expected unavailable/deferred state before deployment.
- [ ] **Step 3: Write the runbook** with no secret values, no private-key transport, no direct runtime-lock instruction, no public SSH fallback, and explicit dependency status.
- [ ] **Step 4: Re-run documentation and contract checks** and verify commands are copy-safe and do not expose secrets.
- [ ] **Step 5: Commit** with `docs: document Marina maintenance authorization workflow`.

### Task 5: Validate the integrated feature in a real browser

**Files:**
- Modify: `tests/marina-maintenance-browser-contract.sh` if coverage gaps are found.
- Create/update: governed evidence under the project’s audit evidence path only.

**Interfaces:**
- Evidence must identify the deployed SHA, environment, URL, timestamp, actor session without secrets, before/after state, reload behavior, console/network errors, and broker audit correlation.

- [ ] **Step 1: Deploy only the disabled/read-only screen** unless the official broker capability, private route, registry, and approved signing procedure are proven in the same release.
- [ ] **Step 2: Open `/admin/marina-maintenance.php` through the real graphical browser** under the authorized admin profile.
- [ ] **Step 3: Exercise the read-only and blocked states**, reload, revisit, and verify persistence of the truthful `NAO_APTO` state.
- [ ] **Step 4: If and only if all external dependencies are proven**, execute one owner-approved preview/sign/submit flow using the external signer; never paste a private key into the browser or chat.
- [ ] **Step 5: Reconcile the broker audit record and source/Marina states** independently. Any refusal, timeout, or mismatch remains open and `NAO_APTO`.
- [ ] **Step 6: Run the applicable absolute audit protocols and certifier** for the exact release. Do not claim completion unless `AUDIT_VERDICT=APTO`.
- [ ] **Step 7: Commit only evidence/index changes that are part of the governed workflow** and preserve unrelated worktree changes.

## Execution Order and Stop Conditions

Execute Tasks 1–4 in order, then Task 5. The approval control must remain
disabled until the broker adapter reports a fresh private-route capability and
the operator registry/lease prerequisites are proven. If those external
dependencies are unavailable, finish the screen in read-only/disabled mode and
record `NAO_APTO`; do not simulate a lease or claim migration completion.

## Plan Self-Review

- Spec coverage: goals, non-goals, UI states, security boundaries, proposed
  interfaces, failure/recovery rules, audit requirements, and dependencies are
  covered by Tasks 1–5.
- Step scan: each task has failing-test, implementation, verification, and
  commit checkpoints with defined files/interfaces.
- Type consistency: all endpoint and broker method names are defined before
  use; the UI consumes only the endpoint contracts from Task 2.
- Review focus: all five high-risk inputs are pinned to tests in Tasks 1–4.
- Proportion: the plan defines boundaries and checks without embedding
  implementation bodies or inventing the missing broker authority.



