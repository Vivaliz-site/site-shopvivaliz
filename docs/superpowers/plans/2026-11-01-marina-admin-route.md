# Marina owner-approved admin route implementation plan

> **For agentic workers:** Use Superpowers test-driven development and verification-before-completion. Changes are staged in an isolated worktree and require human-only security PR.

**Goal:** Introduce a fail-closed local maintenance adapter for the Marina Olist browser, without generic root access.
**Architecture:** Separate local, host-bound module; immutable operation catalogue; exact PR/lease/runtime proofs; protected-file metadata preflight; fixed privileged systemctl call only after gates.
**Tech Stack:** Python 3 stdlib; existing owner PR verifier; unittest; GitHub PR.
**Spec:** docs/superpowers/specs/2026-11-01-marina-admin-route-design.md.

## Constraints
- The `shopvivaliz-human` account must be used only by the person, never an agent.
- The Marina host remains distinct from the backend and deleted source VM.
- Platform denied operations remain denied. No secret contents or credential transfer.
- No deployment before human merge and runtime permission certification.

## Review focus
- A forged boolean approval must never pass.
- A replayed operation ID/fencing token must never cause a second restart.
- An existing running process with absent persistent environment must not be restarted.
- Failure or timeout of a systemctl command must never be reported as success.
- A foreign host and arbitrary service must be rejected.

### Task 1: Pure operation decision
- [ ] Write negative and positive tests in tests/test_marina_admin_route.py.
- [ ] Observe RED before implementing.
- [ ] Implement marina-direct-mcp/operator_admin_route.py (actual path without spaces) with a fixed operation catalogue and explicit error codes.
- [ ] Run unit tests GREEN.

### Task 2: Trusted integration contract and preflight
- [ ] Reject missing human proof, effective runtime right, active fence/lease, wrong host/uid, missing file metadata or replay.
- [ ] Verify tests for missing privileges and idempotency fail closed.
- [ ] Do not install any privileged or network service in this PR.

### Task 3: Integration and delivery
- [ ] Run affected Marina MCP/owner-gate tests, syntax checks, and governance checks.
- [ ] Compare diff with current main and check no credentials or arbitrary shell.
- [ ] Publish security PR as Draft from `fredmourao-ai`; request personal review and merge by `shopvivaliz-human`.
- [ ] After owner merge, official host operator provisions isolated privileges, reviewer verifies actual safe preflight and end-to-end behavior before enabling any mutation.
