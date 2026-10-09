# Detached Continuation Worker Design

## Context

The ShopVivaliz 24x7 controller currently runs its continuity dispatcher inline inside the controller cycle. The dispatcher executes the provider failover path with a synchronous `subprocess.run(..., timeout=900)`. A long-running continuation provider therefore blocks the controller loop, freezes `generated_at`, delays browser/monitor health refresh, and can leave readiness showing stale `dispatcher_no_progress` / `chatgpt_resume_failed` state while the service process itself remains active.

The observed production case on 2026-10-05 involved task `chatgpt-freeze-root-cause-20261004-g1`: the controller service stayed active while the dispatcher waited on a provider subprocess. The controller state stopped advancing until that execution boundary returned.

The existing 10-day task-analysis rule is not part of this defect and must remain unchanged: eligibility is based on task `created_at`, tasks older than 10 days remain outside active analysis even after later updates, and historical checkpoints remain preserved.

## Goal

Decouple heavy continuation execution from the controller heartbeat so the controller can continue watchdog, nudge, browser-health, monitor-health, lease, and readiness cycles every configured interval while provider execution proceeds durably in a separate worker.

## Non-goals

- Do not re-enable the retired aggressive secondary ChatGPT stall monitor.
- Do not reintroduce any risk guard that disables checkpoint-driven continuation.
- Do not change the 10-day `created_at` eligibility semantics.
- Do not weaken the requirement for real checkpoint progress before success.
- Do not treat process exit 0, browser DOM changes, HTTP success, or a sent continuation as terminal completion.
- Do not add a new paid-provider fallback policy.
- Do not edit immutable active releases in place.

## Current Failure Mode

The controller currently performs the sequence:

1. watchdog scan;
2. ChatGPT nudge dispatcher;
3. task resume dispatcher;
4. health/readiness aggregation;
5. sleep before the next cycle.

The task resume dispatcher calls the continuation executor synchronously. A provider invocation can therefore occupy the controller's process for up to the configured resume timeout. During that period:

- controller `generated_at` does not advance;
- no new controller lease cycle is completed;
- browser/monitor readiness may be stale;
- operational health appears frozen although the main service PID is alive;
- unrelated continuation candidates wait behind the same provider call.

## Required Architecture

### 1. Controller remains a coordinator

The controller must stay bounded. Its responsibilities are:

- run watchdog discovery;
- run ChatGPT nudge logic;
- refresh browser and monitor health;
- reconcile continuation worker state;
- enqueue eligible detached work;
- compute readiness from fresh controller and worker evidence;
- return to its normal interval without waiting for provider completion.

The controller must never execute a long-running provider continuation inline.

### 2. Durable detached worker boundary

Provider-backed task continuation must run in a separate durable worker process or service.

The worker receives one immutable execution request containing at minimum:

- request id;
- task id;
- fingerprint;
- repository;
- checkpoint identity / `updated_at` observed before execution;
- provider timeout;
- path or identifier for the generated prompt/workspace where required.

The worker executes the existing provider failover contract and writes its terminal execution result to the existing continuation ledger or an equivalent durable execution record.

Allowed final execution results remain:

- `progress`;
- `terminal`;
- `no_progress`;
- `timeout`;
- `executor_error` or another explicit failure class already represented by policy.

### 3. Exactly-one active execution per fingerprint

The same continuation fingerprint must not execute concurrently.

Reuse existing durable identifiers and locking semantics rather than inventing a second task identity. Before launching work, the coordinator must atomically establish ownership for the fingerprint. A later controller cycle seeing that fingerprint must classify it as in-flight and avoid launching a duplicate.

The ownership record must be crash recoverable. It must include enough process/execution identity to distinguish:

- a live worker;
- a completed worker awaiting reconciliation;
- a dead/orphaned worker whose ownership may be recovered safely.

### 4. Reconciliation instead of waiting

Each controller cycle must reconcile in-flight worker records:

- live worker: report `in_flight`, do not launch another;
- completed worker with checkpoint progress: record `progress` or `terminal`;
- completed worker without material checkpoint progress: record `no_progress` and restore executor-owned transient state exactly as current dispatcher policy requires;
- timed-out/failed worker: record explicit failure and allow retry only under the existing cooldown/retry rules;
- dead worker with no terminal record: recover ownership, record diagnostic evidence, and make the request retryable under the same safety rules.

Readiness must distinguish an execution that is legitimately `in_flight` from a failure. An in-flight worker by itself must not make the controller stale or unhealthy.

### 5. Preserve material-progress semantics

Success remains checkpoint-driven.

Material progress is determined by the same semantic comparison used today between the pre-execution checkpoint and the post-execution checkpoint. No new weaker success signal is introduced.

A worker that exits successfully without material checkpoint advancement is still `no_progress`.

### 6. Preserve queue certification and 10-day eligibility

The active resume queue remains certified and compacted under the existing rules.

The detached architecture must preserve:

- `analysis_window_days = 10`;
- eligibility based on `created_at`;
- tasks outside the 10-day window cannot remain actionable;
- invalid/missing creation timestamps remain excluded;
- terminal history remains historical rather than actionable;
- queue deduplication by fingerprint/request identity.

### 7. Bounded controller latency

A controller cycle must not wait for provider completion.

The only worker-related operations permitted inline are bounded filesystem/process-state checks, atomic lock/record updates, and process/service launch requests. These should complete on the order of seconds, not minutes.

Provider timeout may remain long for the worker, but it must no longer be the controller cycle timeout.

## Process Model

Recommended process model:

1. watchdog creates/certifies actionable resume request;
2. controller/nudge logic applies existing precedence rules;
3. dispatcher selects one eligible request;
4. dispatcher atomically claims fingerprint;
5. dispatcher starts a durable detached worker and immediately returns `in_flight`;
6. controller writes fresh health/readiness and finishes the cycle;
7. worker runs provider failover independently;
8. worker persists exit/result/checkpoint evidence;
9. next controller cycle reconciles worker result;
10. ownership is released only after durable reconciliation.

A systemd transient unit or equivalent durable host-native execution unit is preferred over an unmanaged background process because it provides explicit lifecycle, exit status, kill/timeout control, and survives the parent controller cycle.

## Failure Handling

### Provider runs longer than controller interval

Expected behavior: controller continues producing fresh state every interval. The fingerprint remains `in_flight`. No duplicate launch occurs.

### Controller restarts while worker is live

Expected behavior: restarted controller discovers the durable worker ownership record and live process/unit, reports it as `in_flight`, and does not relaunch.

### Worker exits while controller is down

Expected behavior: on restart, controller reads the worker's terminal execution record, reconciles material checkpoint progress, then releases ownership.

### Worker dies without result

Expected behavior: controller detects dead ownership, records a recovery diagnostic, releases/reclaims the orphan safely, and applies normal retry/cooldown policy. It must not silently mark success.

### Fingerprint changes because checkpoint advances

Expected behavior: the old execution result is reconciled against the checkpoint it started from. A new fingerprint may become independently eligible only after the current state/queue certification says so.

### Task ages beyond 10 days while worker is running

The already-started worker may finish and its result may be reconciled, but no new continuation execution may be launched for a task whose `created_at` is outside the active 10-day window.

## State and Observability

Controller sanitized state should expose enough information to distinguish health from work-in-progress without secrets. At minimum:

- number of in-flight detached executions;
- number reconciled this cycle;
- number recovered as orphaned/dead;
- dispatcher counts for `launched`, `in_flight`, `progressed`, `terminal`, `no_progress`, and `failed`;
- existing browser/monitor health;
- controller `generated_at` that continues updating during worker execution.

Do not expose provider prompts, credentials, tokens, secret environment values, or private execution payloads in sanitized health.

## Testing Strategy

Implementation must use TDD.

Required regressions:

1. **Slow executor does not block controller heartbeat**
   - launch a fake worker that remains active longer than one controller interval;
   - verify controller `run_once` returns promptly;
   - verify a second controller cycle can run while the worker is still active.

2. **No duplicate execution for same fingerprint**
   - first cycle launches worker;
   - second cycle sees same live ownership;
   - verify only one provider execution exists.

3. **Progress reconciliation**
   - worker advances checkpoint;
   - next cycle records `progress` and releases ownership.

4. **No-progress reconciliation**
   - worker exits without material checkpoint advancement;
   - next cycle records `no_progress` and applies existing restore semantics.

5. **Controller restart during live worker**
   - simulate a new controller process against the same runtime;
   - verify live worker is adopted/observed and not duplicated.

6. **Dead worker recovery**
   - ownership exists but process/unit is gone and no terminal result exists;
   - verify orphan recovery is explicit and retry policy remains bounded.

7. **Worker timeout**
   - worker reaches provider timeout;
   - controller remains healthy throughout;
   - terminal timeout is reconciled without duplicate execution.

8. **10-day rule remains enforced**
   - task created 11 days ago with a recent `updated_at` cannot launch;
   - exactly-at-cutoff behavior remains consistent with current implementation.

9. **Queue certification remains green**
   - no malformed, orphan, duplicate, or outside-window actionable rows after reconciliation.

10. **Production E2E**
    - run one authorized real continuation candidate;
    - observe controller `generated_at` advance across multiple cycles while worker is live;
    - observe exactly one worker for the fingerprint;
    - observe real checkpoint progress or explicit `no_progress`/failure;
    - verify controller remains fresh and does not report stale health merely because the worker is still running.

## Rollout

1. Implement on an isolated branch/worktree.
2. Run focused dispatcher/controller tests.
3. Run the full continuity enforcement suite and repository-required gates.
4. Obtain independent code review.
5. Merge through normal protected-branch flow.
6. Promote the immutable controller release.
7. Validate systemd service points to the merged SHA.
8. Run production E2E with sanitized evidence.
9. Confirm the 10-day task window and queue certification remain unchanged.

Rollback is the previous immutable controller release. Rollback must not delete durable checkpoints, queue history, or worker result records.

## Acceptance Criteria

The change is complete only when all of the following are true:

- controller health timestamps advance normally while a continuation provider runs longer than one controller interval;
- no duplicate provider execution occurs for the same fingerprint;
- restart during an in-flight execution does not duplicate work;
- worker completion is reconciled using existing material-progress semantics;
- `no_progress`, timeout, and executor failures remain explicit and retry-bounded;
- browser/monitor readiness remains fresh independently of provider execution duration;
- active queue certification passes;
- the 10-day `created_at` analysis window remains intact;
- production E2E proves the real detached path, not only unit tests or service health;
- no active immutable release was edited in place;
- no secrets appear in logs or health output.
