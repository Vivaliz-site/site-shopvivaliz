# Agent merge policy

This repository uses a merge-by-default operating rule for agent work, with mandatory branch, validation, pull request, and merge gates.

## Required default

Agents must not leave completed work stranded in an unmerged branch or pull request when it is safe to merge. Direct-to-main work is forbidden.

For any repository change, the mandatory completion path is:

1. Sync with `main` before changing files.
2. Create or use a task-specific branch/worktree; never edit `main` directly.
3. Make the smallest safe change.
4. Run the real project validation required by `scripts/repository-governance-validate.sh` before commit.
5. If validation fails, diagnose and fix the failure in the same work session, then re-run validation.
6. Commit only after validation passes.
7. Confirm `git status --porcelain=v1` is empty before push.
8. Push only the task branch; direct push to `main`/`master` is forbidden.
9. Open a pull request and require independent GitHub Actions validation.
10. Merge only after all required checks pass.
11. Perform post-merge verification and record commit SHA, PR number, checks/run IDs, and generated artifacts.

Agents must never use `--no-verify`, force push, direct-to-main commits, or any equivalent bypass of these gates.

## Error handling before merge

A failing check, test, lint, build, review comment, merge conflict, or runtime error is not a reason to leave a PR pending by default. Capture the exact failure evidence, apply the smallest safe fix in the same branch, and re-run the affected validation until the branch is mergeable or a real blocker is proven.

Agents must not stop at `PR_OPEN`, `CHECK_FAILED`, `AWAITING_AGENT`, or similar passive status when the error is actionable by the agent.

## Machine-readable feedback for auto agents

Every attempted merge, failed merge, pending repair state, or completed merge must update the feedback bus documented in `docs/agent-merge-result-feedback.md`.

Required files:

- latest state: `ops/agent-merge-result.json`
- optional history: `ops/agent-merge-results/<timestamp-or-run-id>.json`

If work is pending because of an actionable failure, agents must publish `status=PENDING_AGENT_FIX`, `pending=true`, `auto_fix_required=true`, the failing check/run/job/log evidence, `auto_fix_action`, and `next_agent_instruction`.

If work is complete, agents must publish `pending=false` and include PR/merge evidence. A natural-language chat response alone is not sufficient.

## Meaning of "always merge"

"Always merge" means agents must complete the PR integration step whenever it is safe and authorized. It does not authorize bypassing protections, hiding failures, force-pushing unsafe history, or merging unsafe work.

## When not to merge

Agents must not merge when required checks are failing, conflicts require human/product judgment, the requested scope does not authorize sensitive changes, repository rules block the merge, the change cannot be verified, or required external access/approval is unavailable.

In those cases, publish `NOT_MERGED` with the blocker, attempted fixes, evidence, and exact next action in both the final report and `ops/agent-merge-result.json`.

## Required final report

Every agent report must include one of:

- `MERGED` with commit SHA / PR / run evidence;
- `FIXED_THEN_MERGED` with original failure, fix commit, final passing evidence, and merge evidence;
- `PENDING_AGENT_FIX` with failing evidence and next autonomous repair action;
- `NOT_MERGED` with blocker, attempted fixes, evidence, and next action.

`DIRECT_TO_MAIN` is no longer a valid completion status.

## GitHub Actions observability

When a merge decision depends on GitHub Actions, use the canonical run index documented in `docs/agent-actions-observability.md` when needed. Inspect failing jobs/logs/artifacts and attempt a fix before leaving work unmerged.

## Fred-Win

For Fred-Win tasks, validate the private relay before relying on remote execution. Do not use the historical Cloudflare endpoint.
