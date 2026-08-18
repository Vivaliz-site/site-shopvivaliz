# Agent merge policy

This repository uses a merge-by-default operating rule for agent work.

## Required default

Agents must not leave completed work stranded in an unmerged branch or pull request when it is safe to merge.

For any repository change, the default completion path is:

1. Sync with `main` before changing files.
2. Make the smallest safe change.
3. Run the relevant validation available to the agent.
4. If validation fails, diagnose and fix the failure immediately in the same work session.
5. Re-run the failed validation and any directly affected checks.
6. If the change is direct-to-main and safe, commit it to `main`.
7. If the change is on a branch or pull request, merge it after required checks pass.
8. Publish machine-readable merge feedback for autonomous agents.
9. Record evidence: commit SHA, PR number when applicable, checks/run IDs, and any generated artifacts.

## Error handling before merge

A failing check, test, lint, build, review comment, merge conflict, or runtime error is not a reason to leave a PR pending by default.

When an error appears, the agent must immediately:

1. Capture the exact failing check, log, command, file, line, and run ID when available.
2. Identify the smallest safe fix.
3. Apply the fix in the same branch or PR whenever permitted.
4. Re-run the failed check or an equivalent validation.
5. Continue this repair loop until the branch is mergeable or a real blocker is proven.

Agents must not stop at `PR_OPEN`, `CHECK_FAILED`, `AWAITING_AGENT`, or similar passive status when the error is actionable by the agent.

## Machine-readable feedback for auto agents

Every merge, direct-to-main commit, attempted merge, failed merge, or pending repair state must update the feedback bus documented in `docs/agent-merge-result-feedback.md`.

Required files:

- latest state: `ops/agent-merge-result.json`
- optional history: `ops/agent-merge-results/<timestamp-or-run-id>.json`

The latest state must tell the next autonomous agent whether work is pending and what to do next.

If work is pending because of an actionable failure, agents must publish:

- `status=PENDING_AGENT_FIX`
- `pending=true`
- `auto_fix_required=true`
- the failing check/run/job/log evidence
- `auto_fix_action`
- `next_agent_instruction`

If work is complete, agents must publish `pending=false` and include merge/direct-to-main evidence.

A natural-language chat response is not sufficient; autonomous agents must be able to read the JSON state without conversation history.

## Meaning of "always merge"

"Always merge" means agents must complete the integration step whenever it is safe and authorized. It also means agents must resolve actionable errors immediately so the work can be merged instead of staying pending.

It does not authorize bypassing protections, hiding failures, force-pushing unsafe history, or merging unsafe work.

Agents must prefer merge completion over leaving work pending.

## When not to merge

Agents must not merge when any of these are true:

- required checks are failing after the agent has attempted and documented the repair loop;
- merge conflicts require human/product judgment;
- the change touches secrets, credentials, payments, orders, pricing, stock, ERP, marketplace publishing, or customer data outside the approved scope;
- the user explicitly asks for a draft, review-only work, or no merge;
- branch protection, required review, or repository rules block the merge;
- the agent cannot verify what changed;
- external credentials, production access, or human approval are required and unavailable.

In those cases, the agent must stop with a clear status: `NOT_MERGED`, the blocker, what was tried, evidence links/run IDs/log excerpts, and the exact next action. The same information must also be written to `ops/agent-merge-result.json`.

## Required final report

Every agent report must include one of:

- `MERGED` with commit SHA / PR / run evidence;
- `DIRECT_TO_MAIN` with commit SHA / run evidence;
- `FIXED_THEN_MERGED` with the original failure, fix commit, final passing evidence, and merge evidence;
- `PENDING_AGENT_FIX` with failing evidence and next autonomous repair action;
- `NOT_MERGED` with the blocker, attempted fixes, evidence, and next action.

Reports must not use vague pending statuses without naming the blocker and why the agent could not resolve it immediately.

## GitHub Actions observability

When a merge decision depends on GitHub Actions and the connector cannot list runs directly, use the canonical run index documented in `docs/agent-actions-observability.md`.

Do not declare Actions run discovery unavailable before trying:

1. `ops/actions-run-index-request.json`
2. `ops/actions-run-index.json`

After a failing run is identified, the agent must use available job/log/artifact actions to inspect the failure and attempt a fix before leaving work unmerged.

## Fred-Win

For Fred-Win tasks, validate the private relay before relying on remote execution. Do not use the historical Cloudflare endpoint.
