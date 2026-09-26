# CI Performance Monitor 1000-Result Cap Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve a complete 24-hour CI performance sample even when the repository exceeds GitHub's 1,000-result cap for filtered workflow-run searches.

**Architecture:** Replace the single 24h filtered query with a small fetch helper that partitions the requested window into non-overlapping one-hour slices. Each slice still uses one paginated workflow-runs search, refuses to accept a saturated (>=1000) slice, and writes a page-array payload consumed by the existing analyzer. The analyzer deduplicates run IDs defensively.

**Tech Stack:** Python 3 stdlib, GitHub CLI `gh api`, GitHub Actions.

**Spec:** GitHub REST Actions workflow-runs documentation: filtered searches using `created` return at most 1,000 results; first production monitor run returned exactly 1,000.

## Global Constraints

- Preserve a 24h reporting window.
- Default slice size: 60 minutes (24 bounded searches/day).
- No per-run API calls, no job-level API fan-out, no polling, no sleep.
- Any slice reporting `total_count >= 1000` fails closed instead of silently truncating.
- Slice boundaries must not overlap; analyzer still deduplicates by run ID as a defensive invariant.
- Monitor remains `ubuntu-latest` only.
- Existing thresholds and issue lifecycle remain unchanged.

## Review Focus

- Exact 1,000-result slice: fail closed because completeness cannot be proven.
- Boundary timestamps: no duplicated/lost second between adjacent slices.
- Duplicate run IDs from malformed/replayed payloads: count once.
- `gh` failure or invalid JSON: fetch helper exits nonzero and does not publish a misleading complete report.
- Manual monitor rerun should update issue #1858 rather than create a duplicate.

---

### Task 1: Bounded workflow-run fetcher

**Files:**
- Create: `scripts/ci_performance_fetch.py`
- Create: `tests/test_ci_performance_fetch.py`
- Modify: `scripts/repository-governance-validate.sh`

**Interfaces:**
- Consumes: `--repository owner/repo`, `--output path`, `--window-hours 24`, `--slice-minutes 60`, optional `--end-time`.
- Produces: JSON array of GitHub page objects compatible with `ci_performance_monitor._flatten_runs`.

- [ ] **Step 1: Write failing tests for 24 non-overlapping slices, invalid repository, gh failure, and saturated slice.**
- [ ] **Step 2: Confirm RED because fetcher is absent.**
- [ ] **Step 3: Implement minimal fetcher with subprocess argv (no shell) and fail-closed saturation check.**
- [ ] **Step 4: Confirm GREEN.**

### Task 2: Defensive analyzer dedupe and workflow migration

**Files:**
- Modify: `scripts/ci_performance_monitor.py`
- Modify: `tests/test_ci_performance_monitor.py`
- Modify: `.github/workflows/ci-performance-monitor.yml`
- Modify: `tests/test_ci_performance_monitor_workflow.py`

**Interfaces:**
- Consumes: page-array payload from Task 1.
- Produces: same report schema and issue lifecycle as before.

- [ ] **Step 1: Write failing duplicate-ID analyzer test and workflow contract for fetch helper.**
- [ ] **Step 2: Confirm RED.**
- [ ] **Step 3: Deduplicate by numeric/string run ID and switch workflow fetch step to helper.**
- [ ] **Step 4: Confirm all monitor/fetch/workflow tests pass.**

### Task 3: Rollout proof

**Files:**
- Modify only if verification finds a defect.

- [ ] **Step 1: Pass Mandatory Validation Gate, Repository Governance, and Absolute Audit Governance.**
- [ ] **Step 2: Merge.**
- [ ] **Step 3: Dispatch manual CI Performance Monitor run on main.**
- [ ] **Step 4: Verify run_count is no longer silently pinned at 1000, artifact exists, runner is ubuntu-latest, and issue #1858 is updated rather than duplicated.**
