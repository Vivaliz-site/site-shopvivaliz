# Production Runtime Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate persistent production runtime defects in shared-path permissions and ViaCEP cache, investigate Apache saturation before tuning, and verify catalog service drift.

**Architecture:** Keep runtime-writable state under the existing shared directory and make deployment enforce the www-data group contract without weakening secret permissions. Treat SQLite as a host dependency for the existing optional ViaCEP cache. Diagnose Apache saturation from production evidence before changing worker limits.

**Tech Stack:** Bash deploy script, Python pytest structural tests, PHP 8.3/Apache, systemd, GitHub Actions.

**Spec:** Approved design in the 2026-09-14 production audit conversation.

## Global Constraints
- Do not expose or weaken secrets; `.env` remains `0640`.
- Use an isolated worktree and TDD for code changes.
- Do not change `MaxRequestWorkers` without evidence identifying the saturation cause.
- Preserve existing concurrent worktrees and edits.
- Production verification must be fresh and include public and local runtime checks.

---

### Task 1: Persist shared runtime permissions
- [ ] Add a failing deploy contract test for www-data group-writable runtime paths.
- [ ] Run the focused test and confirm RED for missing behavior.
- [ ] Add the minimal deploy helper/call that enforces group ownership, group write, and setgid directories while excluding `.env`.
- [ ] Run focused deploy tests and bash syntax; confirm GREEN.
- [ ] Commit the tested change.

### Task 2: Restore ViaCEP SQLite cache dependency
- [ ] Verify PHP/Apache version and current absence of pdo_sqlite.
- [ ] Verify the matching Ubuntu package exists before installation.
- [ ] Install/enable the matching SQLite PHP module and reload Apache safely.
- [ ] Smoke-test ViaCEP and confirm SQLite cache creation/read without new driver errors.

### Task 3: Diagnose Apache saturation and catalog drift
- [ ] Analyze vhost access/error logs around each MaxRequestWorkers event and current memory/process state.
- [ ] Correct only evidence-backed operational drift; do not raise worker limits speculatively.
- [ ] Verify reconcile timer/service and legacy sync service state.

### Task 4: Integrate and production-verify
- [ ] Run relevant/full repository verification and review the diff.
- [ ] Push branch, create PR, enable auto-merge, and wait for required checks/merge.
- [ ] Verify production release reaches merged main SHA.
- [ ] Verify ML log is writable by www-data, ViaCEP is clean, Olist negative auth is 401, public site is 200, reconcile is healthy, and no fresh failed units/runtime regressions exist.
