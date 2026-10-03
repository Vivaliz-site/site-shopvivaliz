# ChatGPT Continuity Structural Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ChatGPT continuity stack quiet and truthful when idle while preserving bounded recovery for real tasks.

**Architecture:** Keep the existing checkpoint-driven reinforcement loop and canonical guardian. Tighten current-health semantics so idle is a recovered state, retain the already-merged idle/auth/additional-check gates, then validate installer/runtime ownership and an idle observation window.

**Tech Stack:** Node.js ESM, Bash/systemd, Python task-state helper.

**Spec:** `docs/superpowers/specs/2026-10-03-chatgpt-structure-optimization.md`

## Global Constraints
- Never edit `current/` or an active release.
- New MCP is the canonical control path.
- No secrets in logs or artifacts.
- Completion requires fresh runtime evidence, not commit/merge alone.

## Review Focus
- Stale degraded monitor after all tasks terminate must become healthy idle.
- Idle loop must not touch browser/account APIs.
- Additional-check cooldown must renew heartbeat without checking another candidate.
- Authentication terminal state must not trigger recovery sends.
- Legacy and canonical browser supervisors must never run concurrently.

---

### Task 1: Truthful idle monitor state
**Files:** Modify `scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs`; Test `tests/chatgpt-continuity-bridge-worker-test.mjs`.
**Interfaces:** `reinforcementHealthPayload(outcome, updatedAt, previous)` produces current monitor state.
- [ ] Add a failing regression test: stale `error` + `idle_no_checkpoint` becomes non-degraded idle with cleared detail/failure.
- [ ] Run the focused worker test and confirm RED.
- [ ] Treat `idle_no_checkpoint` as an explicit recovered/healthy action.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit.

### Task 2: Structural/runtime validation
**Files:** Existing installer, worker and systemd units only unless a failing validation exposes another defect.
**Interfaces:** installer owns worker deployment and canonical guardian lifecycle.
- [ ] Run continuity worker, installer contract, guardian and governance tests.
- [ ] Review diff and commit documentation.
- [ ] Push branch and integrate through repository workflow.
- [ ] Deploy through immutable release/install flow; never edit active release.
- [ ] Verify installed worker hash matches main, legacy timer absent, canonical guardian active.
- [ ] Finish the durable task only after a fresh idle window shows heartbeat renewal and zero discovery/send events.
