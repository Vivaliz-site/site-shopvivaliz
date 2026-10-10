# Detached Continuation Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the ShopVivaliz continuity controller heartbeat fresh while long-running provider resume executions run durably and exactly once in a separate worker service.

**Architecture:** Convert `task_resume_dispatcher.py` from an inline executor into a coordinator/reconciler backed by durable execution records under the existing runtime directory. Add a separate `task_resume_worker.py` daemon/service that claims one durable execution record at a time and runs the existing provider execution path synchronously there; the controller only enqueues/reconciles and therefore never waits for provider completion.

**Tech Stack:** Python 3, JSON/JSONL durable state, `fcntl` locks, Linux process identity, systemd services, existing ShopVivaliz immutable release installer.

**Spec:** `docs/superpowers/specs/2026-10-05-detached-continuation-worker-design.md`

## Global Constraints

- Active task analysis remains exactly 10 days and is based on checkpoint `created_at`, not `updated_at`.
- Tasks older than 10 days must never become newly actionable after a later update.
- Historical checkpoints and execution history must be preserved.
- Checkpoint material progress remains the only success criterion; process exit 0 is not success.
- Existing ChatGPT-first recovery precedence and retry/cooldown behavior remain unchanged.
- The aggressive secondary ChatGPT stall monitor remains disabled.
- Do not reintroduce any guard that disables checkpoint-driven continuation.
- Provider timeout may remain 900 seconds, but controller cycles must never wait for provider completion.
- No active immutable release may be edited in place.
- No secrets, provider prompts, tokens, credentials, or private execution payloads may appear in sanitized controller health.

## Review Focus

- A worker dies after claiming a fingerprint but before writing a result: controller must recover the orphan explicitly without duplicating a still-live execution.
- Controller restarts while a worker is live: the same execution must remain in-flight and must not relaunch.
- Checkpoint advances while a prior fingerprint is still finishing: reconciliation must apply to the checkpoint identity captured at launch and not overwrite newer user/agent progress.
- Task crosses the 10-day boundary while already in-flight: allow reconciliation of that execution but never launch a new one.
- Worker service is unavailable or stopped: controller must stay fresh, expose explicit worker-launch/availability failure, and preserve queued work for later retry.

---

### Task 1: Durable execution records and non-blocking dispatcher coordinator

**Files:**
- Modify: `scripts/task_resume_dispatcher.py`
- Test: `tests/test_task_resume_dispatcher.py`

**Interfaces:**
- Produces: `enqueue_execution(runtime_dir: Path, project_dir: Path, request: dict[str, Any], state: dict[str, Any], timeout_seconds: int) -> dict[str, Any]`
- Produces: `reconcile_executions(runtime_dir: Path, project_dir: Path) -> dict[str, int]`
- Produces: durable execution records keyed by request/fingerprint under the existing runtime directory.
- Preserves: `run_once(...)` public signature for controller callers.

- [ ] **Step 1: Write RED regression: slow provider work cannot execute inline**

Add a test named `test_dispatcher_enqueues_without_calling_provider_inline` that replaces the current execution function with a sentinel that fails the test if called, invokes `run_once`, and asserts:
- `executed == 0`;
- `launched == 1`;
- `in_flight == 1`;
- a durable execution record exists for the request fingerprint.

- [ ] **Step 2: Run RED**

Run:
`python3 -m unittest tests.test_task_resume_dispatcher.TaskResumeDispatcherTests.test_dispatcher_enqueues_without_calling_provider_inline -v`

Expected: FAIL because current `run_once` calls `_execute` synchronously and has no `launched` / `in_flight` contract.

- [ ] **Step 3: Write RED duplicate-fingerprint and 10-day tests**

Add:
- `test_live_execution_record_prevents_duplicate_launch`;
- `test_task_older_than_ten_days_cannot_create_execution_record_after_recent_update`;
- `test_task_at_ten_day_cutoff_keeps_current_boundary_semantics`.

Assertions must prove one durable execution per fingerprint and preserve the existing `created_at` boundary behavior.

- [ ] **Step 4: Implement minimal coordinator state model**

In `scripts/task_resume_dispatcher.py`:
- add atomic execution-record create/read/update helpers;
- store request id, task id, fingerprint, repository, checkpoint-before timestamp/signature, timeout, status, worker identity fields, created/updated timestamps, and terminal result metadata;
- make `run_once` reconcile existing records first, then atomically enqueue at most `max_requests` eligible fingerprints;
- return counts including `launched`, `in_flight`, `reconciled`, `recovered`, `progressed`, `terminal`, `no_progress`, and `failed`;
- do not call provider execution from coordinator `run_once`.

Keep current request matching, ChatGPT defer logic, browser-only probe defer logic, cooldown rules, and queue certification semantics.

- [ ] **Step 5: Run focused GREEN**

Run:
`python3 -m unittest tests.test_task_resume_dispatcher -v`

Expected: all dispatcher tests PASS.

- [ ] **Step 6: Commit**

`git add scripts/task_resume_dispatcher.py tests/test_task_resume_dispatcher.py && git commit -m "refactor: make resume dispatcher non-blocking"`

### Task 2: Separate durable resume worker daemon

**Files:**
- Create: `scripts/task_resume_worker.py`
- Modify: `scripts/task_resume_dispatcher.py`
- Create: `tests/test_task_resume_worker.py`

**Interfaces:**
- Consumes: durable execution records from Task 1.
- Produces: `worker_run_once(runtime_dir: Path, project_dir: Path, executor: Sequence[str] | None = None) -> dict[str, Any]`.
- Produces: `worker_main(...)` daemon loop for systemd.
- Reuses: existing provider execution/material-progress logic without weakening its semantics.

- [ ] **Step 1: Write RED worker claim/execution test**

Create `tests/test_task_resume_worker.py` with `test_worker_claims_one_execution_and_records_real_progress`.

The fixture must:
- create one queued execution record;
- use a deterministic test executor that advances the task checkpoint;
- call `worker_run_once`;
- assert record status becomes terminal worker result `progress`;
- assert exactly one execution was attempted.

- [ ] **Step 2: Run RED**

Run:
`python3 -m unittest tests.test_task_resume_worker.ResumeWorkerTests.test_worker_claims_one_execution_and_records_real_progress -v`

Expected: FAIL because `task_resume_worker.py` does not exist.

- [ ] **Step 3: Write RED restart/orphan/timeout cases**

Add:
- `test_second_worker_cannot_claim_live_execution`;
- `test_worker_restart_recovers_dead_claim_without_marking_success`;
- `test_provider_timeout_is_terminal_worker_failure_not_controller_failure`;
- `test_newer_checkpoint_is_not_overwritten_by_old_worker_no_progress_restore`.

The last test must pin the Review Focus case where task state advanced independently after worker launch.

- [ ] **Step 4: Implement worker daemon**

Create `scripts/task_resume_worker.py`:
- claim one queued durable execution atomically using the existing execution lock/fingerprint identity;
- persist worker PID and Linux process-start identity before provider launch;
- run the existing provider path synchronously in the worker, preserving timeout and environment contract;
- persist terminal worker result and diagnostics atomically;
- never delete task checkpoints or queue history;
- daemon mode loops with a short idle interval but processes only one claimed execution at a time.

Refactor only the minimum provider execution helpers out of dispatcher if needed; do not duplicate material-progress semantics.

- [ ] **Step 5: Run worker + dispatcher GREEN**

Run:
`python3 -m unittest tests.test_task_resume_worker tests.test_task_resume_dispatcher -v`

Expected: PASS.

- [ ] **Step 6: Commit**

`git add scripts/task_resume_worker.py scripts/task_resume_dispatcher.py tests/test_task_resume_worker.py tests/test_task_resume_dispatcher.py && git commit -m "feat: add durable continuation worker"`

### Task 3: Controller health and reconciliation semantics

**Files:**
- Modify: `scripts/gemini_24x7_controller.py`
- Modify: `tests/test_gemini_24x7_controller.py`

**Interfaces:**
- Consumes: dispatcher summary fields `launched`, `in_flight`, `reconciled`, `recovered`, plus existing result counters.
- Produces: fresh controller health where legitimate in-flight work is observable but not degraded by itself.

- [ ] **Step 1: Write RED heartbeat test**

Add `test_in_flight_resume_does_not_block_or_degrade_controller_cycle`.

Patch dispatcher to report:
`{"scanned": 1, "eligible": 1, "launched": 1, "in_flight": 1, "reconciled": 0, "progressed": 0, "terminal": 0, "no_progress": 0, "failed": 0}`

Assert:
- `continuity_ready is True` when all other health gates are healthy;
- `degraded_reasons == []`;
- dispatcher summary exposes `launched == 1` and `in_flight == 1`.

- [ ] **Step 2: Run RED**

Run:
`python3 -m unittest tests.test_gemini_24x7_controller.Gemini24x7ControllerTests.test_in_flight_resume_does_not_block_or_degrade_controller_cycle -v`

Expected: FAIL because controller does not yet surface the new fields.

- [ ] **Step 3: Add RED explicit worker-failure test**

Add `test_worker_failure_degrades_without_staling_controller_health`.

Assert explicit dispatcher failure still degrades readiness, while `generated_at` is written normally.

- [ ] **Step 4: Implement controller summary update**

Update `gemini_24x7_controller.py` so:
- `in_flight` and `launched` are not degradation reasons;
- reconciled `no_progress` / explicit failure retain fail-closed readiness;
- dispatcher summary includes `launched`, `in_flight`, `reconciled`, and `recovered`;
- controller lease duration no longer needs to cover provider timeout; use a bounded controller-cycle lease independent of the 900-second worker timeout.

- [ ] **Step 5: Run controller GREEN**

Run:
`python3 -m unittest tests.test_gemini_24x7_controller -v`

Expected: PASS.

- [ ] **Step 6: Commit**

`git add scripts/gemini_24x7_controller.py tests/test_gemini_24x7_controller.py && git commit -m "fix: keep controller healthy during detached resumes"`

### Task 4: Install and supervise the worker independently

**Files:**
- Create: `deploy/systemd/shopvivaliz-task-resume-worker.service`
- Modify: `scripts/install-gemini-24x7-controller.sh`
- Modify: `tests/test_task_resume_dispatcher.py` or existing installer/runtime contract test file that owns controller deployment assertions.

**Interfaces:**
- Produces: systemd unit `shopvivaliz-task-resume-worker.service`.
- Produces environment entry `SHOPVIVALIZ_RESUME_WORKER_ENTRY=<immutable-release>/scripts/task_resume_worker.py`.

- [ ] **Step 1: Write RED deployment contract test**

Add a test asserting the installer:
- copies `task_resume_worker.py` into the immutable controller release;
- installs/enables/restarts `shopvivaliz-task-resume-worker.service`;
- writes the worker entry path to the controller environment;
- verifies the worker service with `systemd-analyze verify`.

- [ ] **Step 2: Run RED**

Run the owning deployment-contract test.

Expected: FAIL because the worker unit and installer wiring do not exist.

- [ ] **Step 3: Implement systemd worker service**

Create the service with:
- `User=ubuntu`, `Group=ubuntu`;
- same protected environment file;
- immutable worker entry path;
- independent restart policy;
- `KillMode=control-group` so provider children die with the worker on restart;
- no controller dependency that would restart the controller when the worker restarts.

Update installer to compile/copy the worker script, install/verify/enable/restart the worker service, and verify it is active.

- [ ] **Step 4: Run deployment contract GREEN**

Run the focused installer/systemd tests.

Expected: PASS.

- [ ] **Step 5: Commit**

`git add deploy/systemd/shopvivaliz-task-resume-worker.service scripts/install-gemini-24x7-controller.sh tests && git commit -m "ops: supervise detached resume worker"`

### Task 5: Full regression, independent review, merge, immutable promotion, production E2E

**Files:**
- Modify only if verification reveals a demonstrated defect.
- Documentation updates, if needed: `docs/knowledge/task-continuity.md`.

**Interfaces:**
- Consumes all prior tasks.
- Produces release evidence and final production verification.

- [ ] **Step 1: Run focused continuity suites**

Run:
- `python3 -m unittest tests.test_task_resume_dispatcher tests.test_task_resume_worker tests.test_gemini_24x7_controller -v`
- `python3 scripts/validate-task-continuity-enforcement.py`
- `bash scripts/agent-continuity-validate.sh`

Expected: all PASS.

- [ ] **Step 2: Run repository-required full validation**

Run the canonical repository governance/test commands from `AGENTS.md` / repository rules, including the project-wide test command required by the TDD skill.

Record every failure by name; do not omit unrelated pre-existing failures.

- [ ] **Step 3: Independent code review**

Use `superpowers:requesting-code-review` against the complete branch. Resolve all valid findings with RED/GREEN tests before merge.

Review must explicitly inspect:
- duplicate fingerprint prevention;
- PID/process-start identity reuse risk;
- controller restart while worker live;
- worker restart while provider child live;
- checkpoint-newer-than-worker reconciliation;
- 10-day boundary preservation;
- secret-free sanitized health.

- [ ] **Step 4: Merge through protected branch flow**

Push only via `python3 scripts/safe_git_push.py`, open PR, wait for mandatory checks, and merge only when required gates are green.

- [ ] **Step 5: Promote immutable release**

Promote the merged `main` SHA using the canonical controller promotion path. Verify:
- active SHA equals intended merged SHA;
- controller service active;
- worker service active;
- no active release files were modified in place.

- [ ] **Step 6: Production E2E with one authorized real continuation**

Choose one current eligible checkpoint inside the 10-day window and observe:
- controller `generated_at` advances across at least two 30-second cycles while worker is live;
- exactly one execution record/worker claim exists for its fingerprint;
- no duplicate provider process is launched;
- worker terminal result is reconciled;
- checkpoint shows real material progress, explicit `no_progress`, or explicit failure;
- controller health never becomes stale merely because worker remains busy.

- [ ] **Step 7: Re-verify 10-day queue contract in production**

Run read-only queue/watchdog certification and assert:
- `analysis_window_days == 10`;
- no outside-window actionable rows;
- no duplicate/malformed/orphan actionable rows;
- task with `created_at < now-10d` cannot be newly launched even if `updated_at` is recent.

- [ ] **Step 8: Completion verification**

Invoke `superpowers:verification-before-completion`. Report concrete commands/results, merged SHA, active controller/worker status, E2E fingerprint correlation, and any remaining genuine external blocker.
