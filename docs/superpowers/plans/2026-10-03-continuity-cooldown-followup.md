# Continuity cooldown follow-up Implementation Plan

> For agentic workers: execute inline with superpowers:executing-plans and TDD.

**Goal:** Complete the approved structural audit by reusing PR #2603 and fixing its review findings.
**Architecture:** Keep checkpoint-driven recovery enabled, a single canonical guardian, and local heartbeats while platform checks defer browser actions.
**Tech Stack:** Node.js, Python, systemd, durable MCP tasks.
**Spec:** User-approved architecture in the 2026-10-03 ChatGPT freeze investigation; PR #2603 is the baseline.

## Global Constraints
- Never edit current/ or an active release.
- Never bypass platform checks, alter credentials, or claim E2E from a unit test.
- Preserve the confirmed legacy supervisor files before retirement.
- Keep the canonical guardian and checkpoint recovery enabled.

## Review Focus
- Five-minute cooldown versus 180-second heartbeat freshness.
- Historical healthy state masking current platform deferral.
- Additional checks appearing after reattach or before sending.
- Expired authentication aborting installation before worker restart.
- Idle and unauthenticated runtime must not discover account conversations.

## Task 1: Regression and minimal patch
- [x] Reproduce all five defects against dbfe45a2 (5 failures).
- [x] Correct heartbeat, degradation, send boundaries, and installer ordering.
- [x] Rerun the five focused tests (5 pass).
- [x] Run full continuity Fast Gate and MCP regression suites: 180 + 22 + 150 + 16 Python tests; worker JS; five cooldown tests; PHP queue; policy validator. Durable task f4870f7b-986d-4575-9dd9-0fb5177adf8b passed at 2026-10-03T14:10:38Z.

## Task 2: Publish and validate runtime
- [ ] Publish one reviewed coherent change with green required gates.
- [ ] Back up legacy supervisor files and install from immutable reviewed source.
- [ ] Verify installed hashes, PID, one supervisor, and two real fresh heartbeat cycles.
- [ ] Keep conversation E2E unverified unless authenticated real progress is observed.

## Remaining scope
Explicit conversation binding, durable per-conversation cooldown across restart,
and published MCP capability refresh are not certified by this bounded patch.
