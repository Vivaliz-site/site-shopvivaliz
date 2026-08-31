# Repository governance

Mandatory flow for every code, configuration, workflow, infrastructure, or documentation change:

`feature branch -> real validation -> commit -> clean working tree -> push -> pull request -> independent CI validation -> merge -> post-merge verification`

Direct commits or pushes to `main`/`master` are forbidden. Agents must not use `--no-verify` or any equivalent bypass.

A failed validation, check, review, conflict, runner problem, permission issue, or external dependency blocks task completion but does not authorize abandoning the PR. Diagnose, repair, revalidate, and repeat until merge. If an external blocker temporarily prevents progress, the task remains active/incomplete and must resume when the blocker can be addressed.

An open PR is never a final successful state. Completion requires merge plus post-merge verification, except when the user explicitly requests review-only/no-merge work for that specific task.

Before finishing any task, run `git status --porcelain=v1`; it must be empty for the task branch/worktree. Existing unrelated dirty worktrees must be preserved and must not be silently cleaned, reset, stashed, or overwritten.

Every clone/worktree must enable the versioned hooks once with:

`git config core.hooksPath .githooks`

The GitHub governance workflow repeats validation independently. Native branch protection/rulesets must additionally require pull requests and the governance status check wherever the GitHub plan supports those controls.
