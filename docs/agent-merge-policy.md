# Agent merge policy

This repository uses a merge-by-default operating rule for agent work.

## Required default

Agents must not leave completed work stranded in an unmerged branch or pull request when it is safe to merge.

For any repository change, the default completion path is:

1. Sync with `main` before changing files.
2. Make the smallest safe change.
3. Run the relevant validation available to the agent.
4. If the change is direct-to-main and safe, commit it to `main`.
5. If the change is on a branch or pull request, merge it after required checks pass.
6. Record evidence: commit SHA, PR number when applicable, checks/run IDs, and any generated artifacts.

## Meaning of "always merge"

"Always merge" means agents must complete the integration step whenever it is safe and authorized. It does not authorize bypassing protections or merging unsafe work.

Agents must prefer merge completion over leaving work pending.

## When not to merge

Agents must not merge when any of these are true:

- required checks are failing or absent for a risky change;
- merge conflicts require human/product judgment;
- the change touches secrets, credentials, payments, orders, pricing, stock, ERP, marketplace publishing, or customer data outside the approved scope;
- the user explicitly asks for a draft, review-only work, or no merge;
- branch protection, required review, or repository rules block the merge;
- the agent cannot verify what changed.

In those cases, the agent must stop with a clear status: `NOT_MERGED`, the blocker, and the exact next action.

## Required final report

Every agent report must include one of:

- `MERGED` with commit SHA / PR / run evidence;
- `DIRECT_TO_MAIN` with commit SHA / run evidence;
- `NOT_MERGED` with the blocker and next action.

## GitHub Actions observability

When a merge decision depends on GitHub Actions and the connector cannot list runs directly, use the canonical run index documented in `docs/agent-actions-observability.md`.

Do not declare Actions run discovery unavailable before trying:

1. `ops/actions-run-index-request.json`
2. `ops/actions-run-index.json`

## Fred-Win

For Fred-Win tasks, validate the private relay before relying on remote execution. Do not use the historical Cloudflare endpoint.
