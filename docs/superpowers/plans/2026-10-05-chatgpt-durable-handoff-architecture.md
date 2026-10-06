# ChatGPT Durable Handoff Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple user-facing ChatGPT turns from long operational execution by introducing bounded foreground handoff, durable background execution, per-conversation leases, a single-writer runtime lock, and real-response-only E2E completion.

**Architecture:** Add durable ownership primitives beside the existing task-state/controller state, route all browser/runtime mutations through one gate, and make the foreground path persist state plus enqueue exactly one durable execution before returning. Recovery remains automatic, but only after foreground ownership is released/expired, and `PROGRESS_CONFIRMED` remains tied to a new assistant response on the exact bound conversation.

**Tech Stack:** Python 3, Node.js ESM, systemd user/system services, SQLite/JSON task state, ShopVivaliz Remote Control MCP, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-05-chatgpt-durable-handoff-architecture-design.md`

## Global Constraints

- Do not disable checkpoint-driven automatic continuation globally.
- Do not use Windows browsers; canonical browser remains the backend VM session.
- New ShopVivaliz Remote Control MCP remains the canonical operational route.
- Foreground must not call `task_wait`, sleep for durable completion, poll CI/deploys, or run multi-minute remote work.
- `PROGRESS_CONFIRMED` requires a new assistant response on the exact bound `conversation_id`.
- Bridge health, HTTP success, tool activity, UI chrome changes, controller promotion, or a successful click are supporting evidence only.
- Conversation mutation and shared runtime mutation must be fenced by ownership and monotonic fencing tokens.
- No edits to active immutable release/current deployment trees.
- No prompts, credentials, tokens, cookies, or message bodies in operational logs.
- Production acceptance requires one correlated E2E run satisfying every acceptance criterion in the spec.

## Spec Coverage Map

- Foreground Coordinator -> Task 3.
- Durable Executor -> Task 6.
- Conversation Lease -> Task 1.
- Single-Writer Runtime Lock -> Task 2.
- Mutation Gate -> Task 4.
- Recovery State Machine -> Task 5.
- Foreground Budget and Queue/Backpressure -> Tasks 3 and 10.
- Controller Ownership and Observability -> Task 7.
- Feature-flag rollout and rollback -> Task 8.
- Real E2E Definition and production acceptance -> Task 10.

## Review Focus

- **Lease expiry races:** foreground expires while still responding; background must not mutate until the lease/fencing check proves ownership transfer.
- **Stale writer after promotion/restart:** an old controller/process must fail closed when its fencing token is no longer current.
- **Queue saturation:** a full durable queue must delay background completion without extending the interactive foreground lifecycle.
- **Conversation rebound/rebinding:** a task rebound to a different `conversation_id` must invalidate stale mutation rights and stale recovery attempts.
- **False progress:** tool activity, Thinking UI, sidebar changes, HTTP 200, or stream-status changes must never produce `PROGRESS_CONFIRMED` without new assistant content.

---

### Task 1: Durable Conversation Lease Primitive

**Files:**
- Create: `scripts/continuity/conversation_lease.py`
- Modify: `scripts/agent_task_state.py`
- Test: `tests/test_conversation_lease.py`
- Test: `tests/test_task_continuity_enforcement.py`

**Interfaces:**
- Produces: `acquire_conversation_lease(conversation_id: str, owner_kind: str, owner_id: str, checkpoint_version: int, ttl_seconds: int, allowed_actions: list[str]) -> dict`
- Produces: `renew_conversation_lease(conversation_id: str, lease_id: str, fencing_token: int, ttl_seconds: int) -> dict`
- Produces: `release_conversation_lease(conversation_id: str, lease_id: str, fencing_token: int, reason: str) -> dict`
- Produces: `get_conversation_lease(conversation_id: str) -> dict | None`
- Produces: `assert_conversation_lease(conversation_id: str, lease_id: str, fencing_token: int, required_action: str) -> dict`
- Persists lease state in the existing durable task-state directory using atomic compare/update semantics.

- [ ] **Step 1: Write failing lease acquisition tests**

Add tests asserting: first acquire succeeds with fencing token `1`; second acquire while live fails; acquire after expiry succeeds with fencing token `2`.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run: `python3 -m unittest tests.test_conversation_lease -v`
Expected: FAIL because lease primitives do not exist.

- [ ] **Step 3: Implement the minimal lease store and atomic fencing rules**

Implement the five interfaces above in `scripts/continuity/conversation_lease.py`. Use filesystem locking plus write-to-temp-and-rename, following existing durable-state patterns.

- [ ] **Step 4: Add stale-renew/release tests**

Assert that an old fencing token cannot renew or release a newer lease and that the current lease remains unchanged.

- [ ] **Step 5: Run focused tests and confirm GREEN**

Run: `python3 -m unittest tests.test_conversation_lease tests.test_task_continuity_enforcement -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/continuity/conversation_lease.py scripts/agent_task_state.py tests/test_conversation_lease.py tests/test_task_continuity_enforcement.py
git commit -m "feat(continuity): add fenced conversation leases"
```

---

### Task 2: Shared Runtime Mutation Lock

**Files:**
- Create: `scripts/continuity/runtime_lock.py`
- Modify: `remote-control-mcp/server.py`
- Test: `tests/test_runtime_mutation_lock.py`
- Test: `tests/remote-control-mcp-test.py`

**Interfaces:**
- Consumes: fencing semantics from Task 1.
- Produces: `acquire_runtime_lock(owner_kind: str, owner_id: str, ttl_seconds: int, allowed_actions: list[str]) -> dict`
- Produces: `assert_runtime_lock(lease_id: str, fencing_token: int, required_action: str) -> dict`
- Produces: `release_runtime_lock(lease_id: str, fencing_token: int, reason: str) -> dict`
- Remote-control mutating operations receive ownership metadata and fail closed without a valid current token.

- [ ] **Step 1: Write failing tests for competing runtime writers**

Cover continuity restart, controller promotion, browser mutation, and runtime installation while another owner holds the lock.

- [ ] **Step 2: Verify RED**

Run: `python3 -m unittest tests.test_runtime_mutation_lock tests.remote-control-mcp-test -v`
Expected: FAIL because mutations are currently unfenced.

- [ ] **Step 3: Implement runtime lock storage and assertion helpers**

Keep storage independent from conversation leases but use the same monotonic fencing model.

- [ ] **Step 4: Gate mutating MCP operations**

Apply runtime-lock assertions to controller promotion, continuity/browser service mutation, and browser click/type/open paths that can alter canonical browser state. Read-only operations remain ungated.

- [ ] **Step 5: Add stale-writer regression tests**

Simulate owner A acquiring token 1, owner B taking token 2 after expiry, then owner A attempting a mutation. Assert hard rejection and no side effect.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.test_runtime_mutation_lock tests.remote-control-mcp-test -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/continuity/runtime_lock.py remote-control-mcp/server.py tests/test_runtime_mutation_lock.py tests/remote-control-mcp-test.py
git commit -m "feat(remote-control): fence shared runtime mutations"
```

---

### Task 3: Foreground Handoff Contract

**Files:**
- Create: `scripts/continuity/foreground_handoff.py`
- Modify: `scripts/agent_task_state.py`
- Modify: `remote-control-mcp/server.py`
- Test: `tests/test_foreground_handoff.py`
- Test: `tests/remote-control-mcp-test.py`

**Interfaces:**
- Consumes: conversation lease from Task 1 and durable task submission from existing Remote Control MCP.
- Produces: `handoff_foreground(task_id: str, conversation_id: str, checkpoint_version: int, durable_command: list[str], lease_ttl_seconds: int = 90) -> dict`
- Return object includes `task_id`, `conversation_id`, `durable_execution_id`, `lease_id`, `fencing_token`, `checkpoint_version`, `queue_position`, and `foreground_duration_ms`.

- [ ] **Step 1: Write failing tests proving foreground is bounded**

Assert the foreground handoff submits exactly one durable execution and never calls wait/sleep/poll helpers.

- [ ] **Step 2: Verify RED**

Run: `python3 -m unittest tests.test_foreground_handoff -v`
Expected: FAIL because handoff coordinator does not exist.

- [ ] **Step 3: Implement the handoff coordinator**

Persist/update checkpoint, acquire foreground lease, submit one durable task, and return immediately with receipt metadata.

- [ ] **Step 4: Add queue-saturation test**

Stub queue position `>=9`; assert handoff still returns without waiting for task completion.

- [ ] **Step 5: Add submission-failure rollback test**

If durable submission fails before acceptance, release the newly acquired foreground lease and leave checkpoint status nonterminal.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.test_foreground_handoff tests.remote-control-mcp-test -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/continuity/foreground_handoff.py scripts/agent_task_state.py remote-control-mcp/server.py tests/test_foreground_handoff.py tests/remote-control-mcp-test.py
git commit -m "feat(continuity): add bounded foreground durable handoff"
```

---

### Task 4: Browser Mutation Gate

**Files:**
- Create: `scripts/continuity/mutation_gate.py`
- Modify: `scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs`
- Modify: `remote-control-mcp/server.py`
- Test: `tests/test_mutation_gate.py`
- Test: `tests/chatgpt-continuity-bridge-worker-test.mjs`
- Test: `tests/remote-control-mcp-test.py`

**Interfaces:**
- Consumes: conversation lease, runtime lock, checkpoint version.
- Produces: `authorize_mutation(conversation_id: str, checkpoint_version: int, conversation_lease: dict | None, runtime_lock: dict, action: str, session_identity: str) -> dict`
- Node worker consumes a serialized authorization result before any click, type, reload, navigation, or continuation send.

- [ ] **Step 1: Write failing tests for live foreground lease rejection**

Assert background recovery cannot click/type/navigate/send while foreground owns the conversation.

- [ ] **Step 2: Verify RED**

Run: `python3 -m unittest tests.test_mutation_gate -v`
Expected: FAIL because no central gate exists.

- [ ] **Step 3: Implement mutation gate in Python**

Check conversation binding, checkpoint version, foreground lease ownership, runtime fencing token, session identity, allowed action, and cooldown/idempotency state.

- [ ] **Step 4: Wire the Node continuity worker through the gate**

Before browser mutation, request/verify authorization. On rejection, record a nonterminal diagnostic state and perform no fallback mutation.

- [ ] **Step 5: Add exact-session and stale-checkpoint tests**

Wrong profile/session and stale checkpoint version must both fail closed without mutation.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.test_mutation_gate tests.remote-control-mcp-test -v`
Run: `node --test tests/chatgpt-continuity-bridge-worker-test.mjs`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/continuity/mutation_gate.py scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs remote-control-mcp/server.py tests/test_mutation_gate.py tests/chatgpt-continuity-bridge-worker-test.mjs tests/remote-control-mcp-test.py
git commit -m "feat(continuity): gate browser mutations by ownership"
```

---

### Task 5: Recovery State Machine and Lease Transfer

**Files:**
- Modify: `scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs`
- Modify: `scripts/chatgpt_continuity_nudge_dispatcher.py`
- Modify: `scripts/agent_task_state.py`
- Test: `tests/chatgpt-continuity-bridge-worker-test.mjs`
- Test: `tests/test_chatgpt_continuity_nudge_dispatcher.py`
- Test: `tests/test_task_continuity_enforcement.py`

**Interfaces:**
- Consumes: lease and mutation gate APIs from Tasks 1 and 4.
- Produces explicit recovery states: `FOREGROUND_ACTIVE`, `FOREGROUND_RELEASED`, `LEASE_EXPIRED`, `RECOVERY_CLAIMED`, `RECOVERY_ACTIONED`, `WAITING_FOR_REAL_RESPONSE`, `PROGRESS_CONFIRMED`, `RECOVERY_EXHAUSTED`.

- [ ] **Step 1: Add failing state-transition tests**

Cover foreground-active suppression, lease-expired claim, one recovery action, wait-for-response, and exhaustion without duplicate sends.

- [ ] **Step 2: Verify RED**

Run the focused Node/Python recovery suites and confirm expected transition failures.

- [ ] **Step 3: Implement explicit state transitions**

Replace implicit success inference where necessary with named states persisted in checkpoint/ledger fields.

- [ ] **Step 4: Add rebinding invalidation test**

Bind task to conversation A, rebind to conversation B with a newer checkpoint version, then assert a recovery owner for A cannot mutate or confirm progress.

- [ ] **Step 5: Add real-response-only completion regression**

Thinking UI, tool activity, sidebar text, HTTP 200, stream flag change, and successful Retry click must all leave the state below `PROGRESS_CONFIRMED`.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `node --test tests/chatgpt-continuity-bridge-worker-test.mjs`
Run: `python3 -m unittest tests.test_chatgpt_continuity_nudge_dispatcher tests.test_task_continuity_enforcement -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs scripts/chatgpt_continuity_nudge_dispatcher.py scripts/agent_task_state.py tests/chatgpt-continuity-bridge-worker-test.mjs tests/test_chatgpt_continuity_nudge_dispatcher.py tests/test_task_continuity_enforcement.py
git commit -m "refactor(continuity): make recovery ownership explicit"
```

---

### Task 6: Durable Executor Ownership and Resume

**Files:**
- Modify: `scripts/task_resume_dispatcher.py`
- Modify: `scripts/autonomous-provider-failover.sh`
- Modify: `scripts/agent_task_state.py`
- Test: `tests/test_task_resume_dispatcher.py`
- Test: `tests/test_task_continuity_enforcement.py`
- Test: `tests/test_task_continuity_e2e.py`

**Interfaces:**
- Consumes: durable checkpoint state and conversation/runtime lease APIs.
- Durable executors receive `task_id`, `conversation_id`, `checkpoint_version`, and owner identity.
- A resumed executor must acquire a fresh lease/fencing token before mutation.

- [ ] **Step 1: Write failing disconnect-survival test**

Simulate foreground handoff followed by client disconnect. Assert durable execution continues and checkpoint advances without requiring the original client stream.

- [ ] **Step 2: Verify RED**

Run: `python3 -m unittest tests.test_task_continuity_e2e -v`
Expected: FAIL on ownership/handoff expectations not yet implemented.

- [ ] **Step 3: Propagate ownership metadata into durable resume execution**

Update dispatcher payload/env construction without persisting prompt bodies or secrets.

- [ ] **Step 4: Require fresh mutation ownership after resume**

A resumed executor can read state freely but must acquire a current lease/runtime token before mutation.

- [ ] **Step 5: Add stale-resume test**

Create two resume attempts from different checkpoint versions; assert the older attempt cannot mutate or certify terminal state.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.test_task_resume_dispatcher tests.test_task_continuity_enforcement tests.test_task_continuity_e2e -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/task_resume_dispatcher.py scripts/autonomous-provider-failover.sh scripts/agent_task_state.py tests/test_task_resume_dispatcher.py tests/test_task_continuity_enforcement.py tests/test_task_continuity_e2e.py
git commit -m "feat(continuity): resume durable work with fenced ownership"
```

---

### Task 7: Operational Guardrails and Audit Events

**Files:**
- Modify: `remote-control-mcp/server.py`
- Modify: `scripts/gemini_24x7_controller.py`
- Modify: `docs/knowledge/task-continuity.md`
- Modify: `docs/knowledge/agent-rules.md`
- Test: `tests/remote-control-mcp-test.py`
- Test: `tests/test_gemini_24x7_controller.py`

**Interfaces:**
- Produces audit fields for lease id, owner kind, fencing token, checkpoint version, durable execution id, queue position, foreground duration, mutation rejection reason, and E2E evidence type.
- Controller may observe all tasks but mutate only through the runtime/conversation ownership contracts.

- [ ] **Step 1: Add failing audit-schema tests**

Assert mutating actions emit ownership metadata without prompt/body/secret content.

- [ ] **Step 2: Verify RED**

Run the focused remote-control/controller tests and confirm missing metadata failures.

- [ ] **Step 3: Add ownership audit fields and redaction**

Record only identifiers/metadata required by the spec.

- [ ] **Step 4: Gate controller promotion and continuity restart**

Require runtime lock ownership; read-only controller health/status remains unaffected.

- [ ] **Step 5: Update operating docs**

Document foreground budget, durable handoff, lease ownership, no-wait foreground rule, and real-response-only E2E definition.

- [ ] **Step 6: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.remote-control-mcp-test tests.test_gemini_24x7_controller -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add remote-control-mcp/server.py scripts/gemini_24x7_controller.py docs/knowledge/task-continuity.md docs/knowledge/agent-rules.md tests/remote-control-mcp-test.py tests/test_gemini_24x7_controller.py
git commit -m "feat(continuity): audit and enforce single-writer operations"
```

---

### Task 8: Feature Flag and Safe Rollout Wiring

**Files:**
- Modify: `scripts/install-chatgpt-continuity-backend-bridge.sh`
- Modify: `scripts/install-gemini-24x7-controller.sh`
- Modify: `ops/systemd/shopvivaliz-chatgpt-continuity.service`
- Test: `tests/test_chatgpt_continuity_backend_runtime.py`
- Test: `tests/test_gemini_24x7_controller.py`

**Interfaces:**
- Feature flag: `SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=0|1`.
- Enabled mode activates leases, foreground handoff, mutation gate, and single-writer enforcement as one coherent rollout unit.

- [ ] **Step 1: Write failing installer/runtime tests**

Assert flag defaults to off until rollout, installed services consume the same value, and rollback preserves checkpoint/lease data.

- [ ] **Step 2: Verify RED**

Run focused installer/runtime tests.

- [ ] **Step 3: Wire the feature flag into both runtimes**

Do not add separate flags for each ownership primitive; the architecture ships as one coherent behavior.

- [ ] **Step 4: Add rollback regression test**

Disable the feature flag and assert stored checkpoint/lease files remain intact and no concurrent legacy mutation path is re-enabled.

- [ ] **Step 5: Run tests and confirm GREEN**

Run: `python3 -m unittest tests.test_chatgpt_continuity_backend_runtime tests.test_gemini_24x7_controller -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add scripts/install-chatgpt-continuity-backend-bridge.sh scripts/install-gemini-24x7-controller.sh ops/systemd/shopvivaliz-chatgpt-continuity.service tests/test_chatgpt_continuity_backend_runtime.py tests/test_gemini_24x7_controller.py
git commit -m "feat(continuity): add durable handoff rollout flag"
```

---

### Task 9: Full Verification and Adversarial Review

**Files:**
- Modify only if verification exposes defects.
- Test: all continuity, controller, remote-control, and runtime suites touched above.

**Interfaces:**
- Consumes all prior tasks.
- Produces a branch that is eligible for PR only if all deterministic verification passes.

- [ ] **Step 1: Run full targeted test matrix**

Run all touched Python and Node suites plus repository validators governing task continuity, Remote Control MCP, controller, and browser runtime.
Expected: all PASS.

- [ ] **Step 2: Run `git diff --check` and secret scan on changed files**

Expected: no whitespace errors; no credentials/tokens/message bodies added.

- [ ] **Step 3: Perform adversarial review against the spec**

Check every success criterion, failure mode, rollback requirement, and Review Focus item. Any gap returns to the owning task for TDD repair.

- [ ] **Step 4: Rebase on current `origin/main` and rerun the full targeted matrix**

Expected: PASS on the rebased commit.

- [ ] **Step 5: Commit any review-only repairs**

Use focused commit messages; do not squash evidence-producing test commits locally.

---

### Task 10: PR, CI, Immutable Deployment, and Production E2E

**Files:**
- No source changes unless CI/E2E exposes a defect; any defect returns to TDD in the owning task.

**Interfaces:**
- Uses the repository PR workflow, immutable deployment installers, Remote Control MCP, and canonical backend browser.

- [ ] **Step 1: Push branch and open PR**

PR body must include architecture summary, TDD evidence, rollout flag behavior, rollback behavior, and the exact production acceptance criteria.

- [ ] **Step 2: Wait for mandatory CI outside the foreground ChatGPT lifecycle**

CI observation must run as durable/background work; do not keep an interactive response open waiting for CI.

- [ ] **Step 3: Merge only after required gates pass**

Capture merge SHA.

- [ ] **Step 4: Deploy exact merge SHA through immutable installers**

Install controller, continuity worker, Remote Control MCP, and systemd units from the exact merged commit as applicable. Verify installed hashes and active release pointers.

- [ ] **Step 5: Enable `SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=1` in the controlled rollout environment**

Verify one controller/runtime writer and no legacy concurrent mutation path.

- [ ] **Step 6: Run synthetic disconnect E2E**

Start a dedicated checkpoint-bound conversation, acquire foreground lease, hand off durably within the foreground budget, interrupt the client stream, and prove durable execution continues while background mutation remains blocked until lease release/expiry.

- [ ] **Step 7: Run queue-saturation E2E**

Create controlled background load; prove foreground handoff still returns within budget and does not wait on queue drain.

- [ ] **Step 8: Run real user-turn recovery E2E**

Bind the exact conversation, capture assistant baseline, trigger one bounded interruption/recovery scenario, and require a new assistant turn/content on that same conversation before `PROGRESS_CONFIRMED`.

- [ ] **Step 9: Verify no conflicting mutations occurred**

Correlate audit records by conversation/task/lease/fencing token. Assert zero unauthorized reload/click/type/restart/promotion events during the foreground lease.

- [ ] **Step 10: Complete the durable task only after all acceptance criteria pass**

Checkpoint `verification` must reference the fresh real-response observation and correlated ownership evidence. If any criterion fails, leave the task RUNNING and return to the owning TDD task.
