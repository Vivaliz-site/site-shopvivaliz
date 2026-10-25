# Marina Maintenance Authorization Screen — Design Specification

**Date:** 2026-10-23  
**Status:** Draft for owner review  
**Scope:** ShopVivaliz admin panel only; no production mutation in this design stage

## Problem

The origin host still owns an active abandoned-cart scheduler while the Marina
runtime is the migration target. The source scheduler cannot be retired by a
generic admin command, SSH shortcut, `foreground_handoff`, or direct
`runtime_lock` call. The operation requires a trusted operator, a private
route to the official broker, a short-lived one-use signed ticket, an internal
maintenance lease, fencing/CAS checks, and post-action reconciliation.

The existing repository has no safe user-facing workflow for preparing and
authorizing that operation. The new screen must make the authorized path
available without turning the web application into a root shell or lease
issuer.

## Goals

1. Give the owner a single place in the existing admin panel to inspect the
   migration preflight and the exact operation scope.
2. Allow an authorized operator to approve a broker-issued maintenance ticket
   using an external signing device or CLI.
3. Keep private signing keys outside the website, server, repository, CI,
   browser storage, logs, and MCP payloads.
4. Fail closed when the official broker, operator registry, private route,
   lease/fencing, source state, or Marina readiness is missing or stale.
5. Produce auditable, redacted evidence for every preview, approval, refusal,
   timeout, and reconciliation result.

## Non-goals

- Issuing a maintenance lease from PHP or JavaScript.
- Accepting arbitrary hostnames, shell commands, timer names, cron text,
  lease IDs, fencing tokens, or externally supplied success flags.
- Writing the operator private key to any server-side or browser-managed store.
- Retrying a refused or indeterminate source mutation automatically.
- Replacing the official broker, its trusted operator registry, or its private
  Marina route.
- Declaring the migration complete. Completion remains subject to the absolute
  audit and production evidence gates.

## User experience

### Entry point

Add `admin/marina-maintenance.php` to the existing admin navigation. The page
must use the established admin session guard, CSRF protection, normal layout,
and existing authorization conventions. A high-impact action additionally
requires recent re-authentication or the project's existing MFA/re-auth
mechanism; the implementation must not invent a weaker substitute.

### State cards

The initial screen is read-only and displays:

- current release SHA and environment;
- origin scheduler state and last observed timestamp;
- legacy cron evidence as a redacted count/hash, never raw secrets or unrelated
  command output;
- Marina sender/scheduler readiness;
- official broker availability and private-route identity;
- trusted operator registry status for the current operator;
- preview ID, audit ID, lease state, and a fail-closed reason when blocked.

Each value must include freshness and provenance. A stale or incomplete value
is shown as `UNKNOWN`/`NAO_APTO`, not as success.

### Preview

The user selects no free-form operational parameters. The server requests a
fresh official preview with a fixed, allow-listed operation:

`retire_origin_abandoned_cart_scheduler`

The preview returns a canonical operation summary, required preconditions,
ticket expiry, and a nonce/operation ID. The UI renders the exact scope and
requires an explicit confirmation immediately before signing.

### External approval

The UI provides a download/copy action for the canonical ticket envelope,
excluding any private material. The operator signs that envelope on the
authorized external device/CLI. The UI then accepts only the detached
signature and the broker-defined ticket envelope, validates shape locally,
and submits both to the server over the authenticated admin session.

The browser must never generate, import, export, persist, or transmit the
private signing key. The server must never accept a private key field.

### Execution and reconciliation

The server forwards the signed envelope only to the official broker adapter.
It does not run a command itself. The broker validates operator registry,
signature, scope, TTL, one-use state, private route, maintenance lease,
fencing/CAS, and source/target preconditions before performing the fixed
operation.

The UI polls a broker operation ID using bounded polling and displays one of:

- `READY_FOR_APPROVAL`;
- `APPROVAL_REQUIRED`;
- `RUNNING`;
- `SUCCEEDED_RECONCILED`;
- `REFUSED`;
- `INDETERMINATE_INSPECTION_REQUIRED`;
- `NAO_APTO`.

`INDETERMINATE_INSPECTION_REQUIRED` disables retry and directs the operator to
the broker audit record. It must never be converted into success by an HTTP
200, an ACK, or a UI refresh.

## Security boundaries

### Browser boundary

The browser handles presentation, confirmation, and transport of a detached
signature only. It cannot select a host or operation and cannot issue a lease.
The canonical ticket is displayed in a redacted, human-readable form and is
also available as a machine-readable download for the external signer.

### Application boundary

PHP endpoints enforce the admin session, CSRF, recent re-auth/MFA policy,
strict JSON schema, maximum body size, nonce freshness, and replay protection.
They call a narrow broker adapter with fixed operation constants. They do not
invoke SSH, shell, `runtime_lock.py`, cron, systemd, OCI, or arbitrary MCP
commands.

### Broker boundary

The official broker remains the only component authorized to issue/use the
maintenance lease and mutate the source scheduler. It must verify the trusted
operator registry and private route server-side. A signed request from an
unknown operator, wrong scope, stale ticket, wrong route, or changed source
state is refused.

### Audit boundary

Audit records contain operation IDs, actor ID, release SHA, timestamps,
decision codes, state transitions, and hashes of canonical envelopes. They do
not contain private keys, bearer tokens, client secrets, raw signatures unless
the broker's approved audit contract explicitly requires a safely redacted
representation, or arbitrary host output.

## Proposed interfaces

These are contracts to be implemented only after the broker capability and
deployment route are confirmed:

- `GET /api/admin/marina-maintenance/status` — read-only preflight and
  freshness metadata.
- `POST /api/admin/marina-maintenance/preview` — requests a fresh fixed-scope
  preview; CSRF and recent re-auth required.
- `POST /api/admin/marina-maintenance/approve` — submits a broker ticket and
  detached external signature; strict schema, one-use replay protection, no
  private-key field.
- `GET /api/admin/marina-maintenance/operations/{id}` — bounded read-only
  status/reconciliation lookup.

The endpoints must return explicit machine-readable refusal codes and preserve
the distinction between `REFUSED`, `UNKNOWN`, and `SUCCEEDED_RECONCILED`.

## Implementation surfaces

Likely files, subject to repository conventions after the implementation plan:

- `admin/marina-maintenance.php`;
- `admin/assets/marina-maintenance.js` or the existing admin asset location;
- the four narrowly scoped admin API endpoints above;
- a server-side broker adapter under the existing integration boundary;
- unit/contract tests for schema, canonicalization, replay, TTL, refusal, and
  redaction;
- browser E2E coverage for read-only, blocked, approval-required, refusal, and
  reconciled states;
- an operations runbook documenting enrollment, external signing, recovery,
  and the no-bypass rules.

No implementation is allowed to claim operational readiness while the official
broker route, trusted operator registry, and deployment mechanism remain
unprovisioned. In that condition the screen may be deployed in read-only or
disabled approval mode only.

## Failure and recovery rules

- Missing broker/private route: remain `NAO_APTO`; do not fall back to public
  SSH or direct host commands.
- Missing operator registry entry: show enrollment-required; do not self-enroll
  by writing a root-owned registry from the web app.
- Expired or already-used ticket: obtain a new preview; never reuse or mutate
  the old ticket.
- Source state changed after preview: invalidate the ticket and require a new
  preview.
- Timeout or ambiguous broker result: freeze retries and inspect the official
  audit record.
- Partial reconciliation: preserve evidence and keep migration status
  `NAO_APTO` until an authorized operator resolves it.

## Verification plan

Before implementation is considered complete:

1. Run backend tests for auth, CSRF, fixed operation scope, canonical JSON,
   signature envelope validation, TTL, replay, redaction, and refusal codes.
2. Run negative tests proving the app rejects private keys, arbitrary commands,
   arbitrary hosts, stale tickets, wrong operators, wrong scopes, duplicate
   approvals, and public-route fallback.
3. Exercise the page through a real graphical browser, including reload,
   session expiry, blocked state, approval-required state, refusal, and
   reconciled state. API/CLI output alone is not UI evidence.
4. Validate the deployed release SHA and broker operation ID against the
   official audit record.
5. Run the required audit protocols and certify the same release only if the
   absolute certifier returns `AUDIT_VERDICT=APTO`.

## Open dependencies

The design does not invent the following missing authorities. They must exist
before enabling the approval button:

1. official private route to Marina;
2. root-owned trusted operator registry;
3. installed and reviewed broker/helper implementation;
4. approved external signing device/CLI and operator enrollment procedure;
5. deployment of the broker adapter and its audit contract;
6. owner-approved production change window and rollback/reconciliation plan.

Until these dependencies are proven, the correct status is `NAO_APTO`, not
“migration complete”.


