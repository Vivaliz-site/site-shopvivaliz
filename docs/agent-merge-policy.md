# Agent merge policy

This repository uses a mandatory merge-completion rule for agent work.

## Required default

An open branch or pull request is never a completed task. Direct-to-main work is forbidden.

For any repository change, the mandatory completion path is:

1. Sync with `main` before changing files.
2. Create or use a task-specific branch/worktree; never edit `main` directly.
3. Make the smallest safe change.
4. Run the real project validation required by `scripts/repository-governance-validate.sh` before commit.
5. If validation fails, diagnose and fix the failure in the same work cycle, then re-run validation.
6. Commit only after validation passes.
7. Confirm `git status --porcelain=v1` is empty before push.
8. Push only the task branch; direct push to `main`/`master` is forbidden.
9. Open or update the pull request and require independent GitHub Actions validation.
10. Repair checks, conflicts, review findings, workflow failures, and merge blockers until the current PR SHA is green.
11. Merge immediately after all required gates pass.
12. Perform post-merge verification and record merge SHA, PR number, checks/run IDs, and generated artifacts.

Agents must never use `--no-verify`, force push, direct-to-main commits, disabled checks, or any equivalent bypass.

## Error handling before merge

A failing check, test, lint, build, review comment, merge conflict, runtime error, permission problem, runner failure, or provider outage is not permission to abandon a PR. Capture the exact evidence, identify the root cause, apply the smallest safe repair, and re-run the affected validation.

Agents must not stop at `PR_OPEN`, `CHECK_FAILED`, `AWAITING_AGENT`, `PENDING_AGENT_FIX`, `NOT_MERGED`, or similar passive states when there is an actionable repair path.

If the blocker is external and cannot be removed in the current execution, the PR and task remain active work. Record the blocker and next repair action, but do not call the task complete. Resume the repair loop when the dependency is available.

## Machine-readable feedback for auto agents

Every attempted merge, failed merge, pending repair state, or completed merge must update the feedback bus documented in `docs/agent-merge-result-feedback.md`.

Required files:

- latest state: `ops/agent-merge-result.json`
- optional history: `ops/agent-merge-results/<timestamp-or-run-id>.json`

`PENDING_AGENT_FIX` and `NOT_MERGED` may be used only as transient operational states. They never mean task completion and must include `pending=true`, concrete evidence, `auto_fix_action`, and `next_agent_instruction`.

A completed state requires `pending=false` plus PR and merge evidence.

## Meaning of "always merge"

"Always merge" means the integration step is mandatory whenever a repository change is being delivered. Failures trigger repair, not abandonment. External blockers may pause execution but do not convert an open PR into a finished task.

This rule does not authorize bypassing protections, hiding failures, force-pushing unsafe history, disabling checks, or merging unsafe work. The agent must fix the condition that prevents a safe merge.

The only exception is an explicit user instruction for that specific task to stop before merge or perform review-only work.

## Required final report

A completed task report may use only:

- `MERGED` with merge SHA / PR / run evidence; or
- `FIXED_THEN_MERGED` with the original failure, repair commit, final passing evidence, and merge evidence.

Transient statuses such as `PENDING_AGENT_FIX` or `NOT_MERGED` are progress reports, never completion reports. `DIRECT_TO_MAIN` is not a valid status.

## GitHub Actions observability

When a merge decision depends on GitHub Actions, use the canonical run index documented in `docs/agent-actions-observability.md` when needed. Inspect failing jobs/logs/artifacts, repair the cause, and continue until the PR is merged.

## Fred-Win

For Fred-Win tasks, validate the private relay before relying on remote execution. Do not use the historical Cloudflare endpoint.
