# ChatGPT Durable Handoff Architecture

## Status
Design approved in chat on 2026-10-05 after repeated production freezes and a live `Transmissão interrompida` capture. The ChatGPT-facing Remote Control stdio adapter now enforces the five-second foreground boundary: over-budget admin commands are promoted to durable submission, stale `task_wait` calls are detached to one non-blocking `task_status`, and adapter tool discovery hides `task_wait`. The raw backend retains `task_wait` for internal/non-ChatGPT consumers.

## Problem
The current continuity architecture keeps an interactive ChatGPT turn coupled to long operational work: remote commands, durable task waits, CI, deploys, controller promotion, browser probes, and recovery actions may overlap while the user-facing response remains open.

A live incident around 2026-10-05 00:54:30-00:56:50 UTC showed 99 control-plane operations in roughly two minutes, including 16 synchronous admin commands, 13 durable task submissions, 24 task waits, controller promotion, browser actions, and a continuity-service restart. The Remote Control host reached both concurrency slots and a queue position of 9. The iPhone client simultaneously displayed `Transmissão interrompida. Aguardando a mensagem completa...`.

The continuity ledger did not show a duplicate `Continue` sent to that conversation during the incident, so the design must address execution coupling and concurrent ownership rather than treating duplicate nudges as the sole cause.

## Goal
Make a user-facing ChatGPT response independent of long-running operational execution. The interactive turn should finish quickly after checkpointing and handing off work. Long work must continue durably without keeping the response stream open, and only one controller may mutate a conversation or continuity runtime at a time.

## Non-goals
- Do not weaken the requirement for automatic continuation.
- Do not disable checkpoint-driven recovery globally.
- Do not treat bridge health, HTTP 200, tool execution, or provider ACK as E2E success.
- Do not introduce another browser session or reuse Windows browsers.
- Do not replace the new ShopVivaliz Remote Control MCP with RDC.
- Do not relax external CI, deploy, or production validation gates.

## Success Criteria
1. A foreground ChatGPT turn performs only bounded work: validate request, persist checkpoint, acquire/verify ownership metadata, submit one durable execution, and return.
2. Foreground never waits on CI, deploy, `task_wait`, long polling, arbitrary sleeps, or multi-minute remote commands.
3. Long-running work survives client disconnects and continues through durable task execution.
4. Exactly one writer may mutate the same conversation/task continuity state at a time.
5. While a conversation is actively responding in foreground, background automation cannot navigate, reload, click, type, send `Continue`, or restart its browser/session.
6. Recovery can resume only after the foreground lease expires or is explicitly released.
7. `PROGRESS_CONFIRMED` requires a new assistant response on the exact bound `conversation_id`.
8. Controller promotion, continuity-service restart, browser mutation, and recovery send are serialized behind explicit ownership.
9. Runtime evidence must show bounded foreground latency, durable continuation, zero conflicting browser mutations during the lease, and one real assistant response before E2E success.

## Architecture

### 1. Foreground Coordinator
The foreground coordinator is the only component invoked synchronously by a user turn. It has a hard responsibility boundary:

- resolve or bind `conversation_id`;
- persist/update the durable task checkpoint;
- create a foreground lease for that conversation;
- submit exactly one durable execution request;
- return control to the user-facing turn.

It must not perform CI waits, deploy waits, repeated task polling, browser recovery, controller promotion, or sleeps.

The foreground coordinator returns an execution receipt containing at least `task_id`, `conversation_id`, durable execution id, lease id, and checkpoint version. That receipt is diagnostic state, not completion evidence.

### 2. Durable Executor
The durable executor owns all work expected to exceed the foreground budget, including investigation, tests, PR operations, CI observation, deploy, controller promotion, runtime validation, and E2E checks.

Durable execution must be resumable and idempotent. Every step records progress back to the checkpoint. A disconnect of the ChatGPT client must not cancel the durable task.

The executor may request browser recovery only after proving it holds the mutation lease described below.

### 3. Conversation Lease
Each bound `conversation_id` has one lease record with:

- `conversation_id`;
- `lease_id`;
- `owner_kind` (`foreground`, `durable-recovery`, or `maintenance`);
- `owner_id`;
- `issued_at`;
- `expires_at`;
- `checkpoint_version`;
- `allowed_actions`.

Foreground acquires a lease when a response begins. While that lease is live, background components are read-only for that conversation. They may inspect checkpoint metadata and health, but must not navigate, reload, click, type, send a continuation, or alter browser ownership.

Foreground releases the lease when the turn completes normally. If the stream disconnects, the lease expires after a bounded TTL rather than remaining forever. Recovery may acquire a new lease only after expiry/release and after checking the latest checkpoint version.

Lease renewal must be bounded and monotonic. A stale owner cannot overwrite a newer lease.

### 4. Single-Writer Runtime Lock
Conversation lease protects one conversation. A separate single-writer runtime lock protects shared continuity infrastructure.

Mutations requiring this lock include:

- restart/reload of `shopvivaliz-chatgpt-continuity.service`;
- browser restart or navigation affecting the canonical profile;
- controller promotion;
- installation of continuity/browser/controller runtime files;
- creation/removal of diagnostic drop-ins;
- browser mutation for recovery.

Read-only health checks do not require the lock.

The lock must have owner identity, fencing token/version, TTL, and auditable acquisition/release. The fencing token prevents an old process from continuing mutations after ownership has changed.

### 5. Mutation Gate
Every mutating browser or continuity action passes through one gate. The gate checks, in order:

1. exact conversation binding;
2. current checkpoint version;
3. no live foreground lease owned by another execution;
4. current mutation lease/fencing token;
5. session/profile identity;
6. action is allowed for the lease owner;
7. cooldown/idempotency rules.

If any check fails, the action is rejected without fallback mutation.

### 6. Recovery State Machine
Recovery uses explicit states rather than inferred success:

`FOREGROUND_ACTIVE` -> no mutation allowed.

`FOREGROUND_RELEASED` or `LEASE_EXPIRED` -> durable executor may evaluate recovery.

`RECOVERY_CLAIMED` -> one recovery owner holds the lease.

`RECOVERY_ACTIONED` -> one bounded recovery action was executed.

`WAITING_FOR_REAL_RESPONSE` -> no additional send; observe only.

`PROGRESS_CONFIRMED` -> only when a new assistant turn/content is observed on the exact bound conversation.

`RECOVERY_EXHAUSTED` -> no repeated blind mutations; fall back to detached/background continuation according to checkpoint policy.

A bridge response, HTTP success, stream flag, UI text change, tool activity, `Thinking`, or successful click is never terminal proof.

### 7. Foreground Budget
The foreground path must have a strict budget. Initial target: 5 seconds for control-plane work after the assistant has enough information to hand off.

Anything that cannot complete within the budget is submitted durably. The synchronous path must never call `task_wait` or sleep to wait for a durable operation.

The exact budget may be tuned by measurement, but the architectural rule is fixed: long work never extends the user-facing stream.

### 8. Queue and Backpressure
The durable task queue already serializes host execution by capacity. This design adds priority and admission rules:

- user-turn handoff metadata is high priority but very short;
- recovery mutations are serialized by conversation lease and runtime lock;
- audits/tests/deploys remain durable and must not consume the interactive response lifecycle;
- repeated equivalent recovery requests deduplicate by task id, conversation id, checkpoint version, and action fingerprint.

Queue depth is observable. A busy queue delays background completion, not the user's foreground response.

### 9. Controller Ownership
The controller may observe many tasks, but it cannot mutate a conversation owned by foreground. It also cannot promote itself, restart continuity, or rewrite runtime state while another mutation owner holds the runtime lock.

Controller promotion is a maintenance mutation and therefore serialized. Promotion does not imply task completion or continuity progress.

### 10. Real E2E Definition
A continuity E2E passes only if all of the following are true:

1. the task is bound to the expected `conversation_id`;
2. foreground releases or loses its lease in a controlled way;
3. recovery acquires ownership without conflict;
4. no other writer mutates the conversation during recovery;
5. a bounded recovery action occurs or detached continuation is selected;
6. the exact conversation produces a new assistant turn/content after the recovery baseline;
7. that new content is observed by the verifier;
8. the checkpoint records fresh evidence and verification after the observation.

Health endpoints and service status are supporting evidence only.

## Data Model

### Conversation lease file/table
Recommended durable fields:

- `conversation_id` primary key;
- `lease_id` UUID;
- `owner_kind`;
- `owner_id`;
- `fencing_token` monotonic integer;
- `issued_at`;
- `expires_at`;
- `checkpoint_version`;
- `allowed_actions` array;
- `released_at`;
- `release_reason`.

Storage should live with the existing durable controller state rather than in browser-local state.

### Runtime mutation lock
Recommended fields mirror the lease but identify the shared runtime resource. The lock update must be atomic and compare the previous fencing token/version.

## Failure Handling

### Client stream disconnects
The durable task continues. Foreground lease expires after TTL. Recovery may proceed only after expiry and checkpoint freshness check.

### Durable executor crashes
A replacement executor may resume from the checkpoint only after acquiring a new lease/fencing token. Previous owner becomes invalid.

### Queue saturation
No synchronous waiting. Foreground returns after durable submission. Queue depth and position remain diagnostic only.

### Browser authentication loss
Recovery enters auth-quiescent state. It does not loop or mutate another profile.

### Conversation cannot render
Canonical conversation state is checked before `CONVERSATION_NOT_FOUND`. If the conversation exists but UI hydration failed, classify it as recoverable hydration failure and do not send duplicate user content.

### Platform additional checks / 429
Enter bounded cooldown/backoff. Do not repeatedly read full conversation state or resend continuation.

## Observability
Every foreground handoff and recovery attempt records:

- conversation id (non-secret identifier);
- task id;
- lease id and owner kind;
- fencing token;
- checkpoint version;
- durable execution id;
- queue position at submission;
- foreground duration;
- mutation attempts/rejections;
- final E2E evidence type.

No prompts, credentials, tokens, cookies, or message bodies are persisted in these operational logs.

## Rollout
1. Add lease/runtime-lock primitives behind a feature flag.
2. Make browser/continuity mutations honor the gate while legacy orchestration still submits work.
3. Move long foreground operations to durable handoff.
4. Enable single-writer enforcement.
5. Run synthetic disconnect E2E with a dedicated task/conversation.
6. Run a real user-turn recovery E2E and require a new assistant response.
7. Remove the legacy path that keeps long work in the interactive turn only after parity is proven.

Rollback disables the new handoff feature flag and leaves checkpoint data intact. Rollback must not re-enable concurrent mutation paths that have already been proven unsafe.

## Testing Strategy

### Unit
- foreground handoff never calls wait/sleep/long execution;
- live foreground lease rejects background browser mutation;
- expired lease permits exactly one recovery owner;
- stale fencing token is rejected;
- deduplication prevents duplicate recovery action;
- generic UI/tool/bridge activity cannot produce `PROGRESS_CONFIRMED`;
- new assistant turn on exact conversation can produce `PROGRESS_CONFIRMED`.

### Integration
- durable task survives simulated client disconnect;
- queue saturation does not extend foreground lifecycle;
- controller promotion is rejected while runtime mutation lock is held;
- continuity restart is rejected while another mutation owner exists;
- browser reload/click/type is rejected during foreground lease;
- detached fallback works after recovery exhaustion.

### Production E2E
- start a dedicated checkpoint-bound conversation;
- begin a real foreground response;
- simulate/observe stream interruption without killing durable work;
- verify no conflicting mutations while foreground lease is live;
- permit recovery after release/expiry;
- require one real new assistant response in the bound conversation;
- verify fresh checkpoint evidence only after that response.

## Acceptance Criteria
The architecture is accepted only when production evidence demonstrates all of the following in one correlated run:

- foreground handoff completes within budget;
- durable work continues after the client stream is interrupted;
- no second writer mutates the conversation during the foreground lease;
- no duplicate continuation is emitted;
- queue saturation does not keep the foreground turn open;
- recovery is single-owner;
- a real new assistant response is observed on the bound conversation;
- checkpoint verification is written only after that response;
- no service restart, controller promotion, or browser mutation occurs outside the runtime-lock contract.
