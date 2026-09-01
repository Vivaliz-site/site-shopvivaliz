# Auto Gate replay E2E proof

Controlled repository-governance proof for the PR Completion Enforcer.

This branch intentionally starts from a main commit that predates the missing-gate replay fix. The expected sequence is:

1. The Completion Enforcer detects that this PR head does not contain current `main`.
2. GitHub's native `update-branch` creates a new head using `GITHUB_TOKEN`.
3. Required pull-request workflows are absent on that bot-generated head because GitHub suppresses recursive workflow triggers from `GITHUB_TOKEN`.
4. The Completion Enforcer detects only the `missing` required gates and dispatches their approved workflows explicitly on the exact current PR head.
5. Pending workflows are left alone, failed workflows remain fail-closed, and the PR merges only after all eight required gates are green for the exact head SHA.

No empty validation commit should be needed for this proof.
