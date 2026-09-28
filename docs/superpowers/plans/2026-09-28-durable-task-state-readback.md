# Durable Task State Readback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a strictly read-only, allowlisted control-plane action that returns one durable task checkpoint from `scripts/agent_task_state.py` without enabling arbitrary shell or exposing secrets.

**Architecture:** Extend the existing `ShopVivaliz Remote Access` workflow instead of creating a parallel control path. The request keeps the current `/remote target=<host> action=<action> reason=<reason>` syntax; for `task_state_show`, `reason` must contain exactly `task=<safe-id>`. The workflow invokes only `agent_task_state.py show --task <safe-id>` against the production/shared state location and emits the JSON unchanged because the task-state schema must not contain secrets.

**Tech Stack:** GitHub Actions YAML, Python 3, existing `scripts/agent_task_state.py`.

**Spec:** `docs/knowledge/task-continuity.md`

## Global Constraints

- Read-only only; no arbitrary shell input.
- Preserve `CHATGPT_WEB_AUTOMATION_RISK_GUARD_V2`; no ChatGPT Web turns, login flows, retries, token/service/CDP activation.
- Never print secrets.
- The task identifier must match the same safe-id character class used by `agent_task_state.py`.
- Use the canonical persistent state as source of truth.

## Review Focus

- Reject malformed or missing task ids instead of passing them to a shell.
- Do not let `reason` become arbitrary shell text.
- Ensure the action works only on the production site host where `/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state` is canonical.
- Preserve all existing remote actions.
- Readback must fail non-zero when the task state is missing/unreadable.

### Task 1: Regression test

**Files:**
- Create: `tests/test_remote_task_state_readback.py`
- Modify later: `.github/workflows/shopvivaliz-remote-access.yml`

- [ ] Write a failing test asserting the workflow exposes `task_state_show`, validates `task=<safe-id>`, and calls `agent_task_state.py show` using the shared state directory.
- [ ] Run the focused test and confirm RED because the action does not yet exist.

### Task 2: Minimal read-only action

**Files:**
- Modify: `.github/workflows/shopvivaliz-remote-access.yml`

- [ ] Parse and validate the task id in the existing request resolution step.
- [ ] Restrict `task_state_show` to `shopvivaliz-free-a1`.
- [ ] Execute `agent_task_state.py show --task "$TASK_ID"` with `SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state`.
- [ ] Run focused test, workflow syntax validation, and applicable governance tests.

### Task 3: Integration and live readback

- [ ] Open PR, wait for required checks, review diff, merge only when green.
- [ ] Post `/remote target=shopvivaliz-free-a1 action=task_state_show reason=task=chatgpt-freeze-root-cause-20260927` to issue #1586.
- [ ] Read the resulting workflow log and follow the checkpoint's `next_action` without creating a ChatGPT Web turn.
- [ ] Persist progress or terminal state through the canonical task-state mechanism.
