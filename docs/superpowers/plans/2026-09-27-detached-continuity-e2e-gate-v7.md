# Detached Continuity E2E Gate V7 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Make detached task recovery auditable and impossible to certify from ACK/static gates alone.

**Architecture:** Preserve only structured, sanitized executor diagnostics before ephemeral cleanup; label operations-worker continuation events as queued acknowledgements rather than execution; add a production E2E probe that creates one synthetic RUNNING checkpoint and only polls durable state/ledgers while the existing daemon performs watchdog -> dispatcher -> Gemini. The probe never invokes watchdog or dispatcher directly.

**Tech Stack:** Python 3, unittest, GitHub Actions, existing ShopVivaliz durable task-state JSONL runtime.

**Spec:** `docs/knowledge/task-continuity.md`

## Global Constraints
- Do not expose prompts, provider output, credentials, tokens, cookies, or secrets.
- Background detached recovery remains Gemini-only; paid Claude/Codex must not be daemon fallbacks.
- Production releases are immutable; no direct edits to `current/` or active releases.
- E2E PASS requires automatic stale detection, queued request, detached executor attempt, durable checkpoint change, `CONCLUIDO`, `verification=continuity_e2e_pass`, and ledger result `terminal` or `progress`, never `no_progress`.
- “ChatGPT session re-entry” and “detached task recovery” are distinct concepts.

## Review Focus
- Provider output containing secret-like strings must never appear in durable diagnostics.
- A zero provider exit without state mutation must still fail.
- Worker ACK/timeline must not be usable as execution evidence.
- The E2E probe must not import/call watchdog or dispatcher.
- Superseded/other task ledger rows must not produce false PASS.

---

### Task 1: Sanitized executor diagnostics

**Files:**
- Modify: `scripts/task_resume_dispatcher.py`
- Test: `tests/test_task_resume_dispatcher.py`

- [ ] Write failing tests for bounded structured diagnostics with no raw provider output/prompt.
- [ ] Run focused tests and observe RED.
- [ ] Implement artifact summarization before workspace cleanup.
- [ ] Persist diagnostics in `_resume-executions.jsonl`.
- [ ] Run focused suite GREEN.

### Task 2: Worker acknowledgement semantics

**Files:**
- Modify: `scripts/agent-operations-worker.py`
- Test: `tests/test_task_continuation_watchdog.py`

- [ ] Write failing test requiring `auto-resume-queued`/acknowledgement wording.
- [ ] Observe RED.
- [ ] Change timeline event/message without changing routing semantics.
- [ ] Run focused suite GREEN.

### Task 3: Production detached-continuity E2E gate

**Files:**
- Create: `scripts/task_continuity_e2e.py`
- Create: `tests/test_task_continuity_e2e.py`
- Create: `.github/workflows/task-continuity-production-e2e.yml`
- Modify: `.github/workflows/task-continuity-fast-gate.yml`
- Modify: `scripts/validate-task-continuity-enforcement.py`
- Modify: `docs/knowledge/task-continuity.md`
- Modify: `AUDIT_POLICY.md`

- [ ] Write failing unit/static contracts proving the probe cannot call watchdog/dispatcher and requires terminal durable evidence.
- [ ] Observe RED in PR CI.
- [ ] Implement the polling-only E2E probe and workflow.
- [ ] Make continuity validator require the probe/workflow/policy markers.
- [ ] Document that continuity cannot be APTO without fresh production E2E evidence.
- [ ] Run focused suite GREEN.

### Task 4: Production proof

- [ ] Merge after CI/review.
- [ ] Confirm exact release SHA is active.
- [ ] Dispatch only the E2E workflow; do not invoke watchdog/dispatcher manually.
- [ ] Require watchdog-created request, dispatcher ledger, `CONCLUIDO`, `verification=continuity_e2e_pass`, and non-`no_progress` result.
- [ ] If failure occurs, use sanitized diagnostics for the next TDD cycle.
