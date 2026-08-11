# Autonomous PR healing and completion

This repository enforces `REGRA-PR-FALHOU-CORRIGIR-NA-HORA.md` with three workflows:

- `PR Conflict Auto-Healer`: keeps same-repository PR branches current with `main` and uses a rotating Gemini credential pool to resolve textual Git conflicts.
- `PR Policy Enforcement`: tests the healer and verifies that the enforcement surfaces have not been removed or weakened.
- `PR Completion Enforcer`: merges only when the PR contains current `main` and every canonical gate is successful on the exact head SHA.

## Oracle-private credential boundary

GitHub Actions is an orchestration layer only. It uses the repository's verified Oracle SSH credentials to stage trusted scripts from `main` into a temporary directory on the Oracle VM.

Gemini API keys and repository write authentication never need to be copied into the Actions runner:

- Gemini keys are parsed directly from `/home/ubuntu/shopvivaliz-deploy/shared/.env` on Oracle. The parser reads only the approved Gemini/Google key names and ignores unrelated runtime secrets.
- GitHub branch pushes and final PR merges use the GitHub authentication already configured on Oracle.
- Actions keeps only read permissions for repository contents/PRs/actions and passes public PR metadata such as PR number, branch name and expected SHA.
- Temporary healer/finalizer scripts are removed from Oracle after each run.

This removes the dependency on a separate `GH_REPO_TOKEN` Actions secret and prevents copying the wider private Gemini pool into CI.

## Gemini credential rotation

The healer accepts singular aliases and multi-key bundles. Bundles may be comma-, semicolon-, or newline-separated. Values are deduplicated in memory and never printed.

Supported inputs include `GEMINI_API_KEY`, `GOOGLE_GEMINI_API_KEY`, `GOOGLE_IMAGEN_API_KEY`, `GOOGLE_API_KEY` and their plural `*_API_KEYS` forms.

A `401`, `403`, or `429` rotates immediately to the next unique credential. Model-level `400`/`404` errors try the configured fallback model before abandoning a credential. Transport and server errors also rotate instead of stalling the PR.

Every healer run performs a private credential preflight on Oracle and reports only the number of unique usable credential entries and configured models. No credential value is returned to Actions.

## Trust boundary

The healer never executes PR code while runtime secrets are available. It operates only on same-repository, non-draft PRs targeting `main`, uses trusted healer code staged from `main`, and sends only the three Git conflict stages for conflicted files to Gemini. Fork PRs are excluded from automated healing and merging.

Binary, symlink and oversized conflicts fail closed. A resolution is staged only after Git conflict markers are absent. Publication is allowed only to the original PR branch, never `main`/`master`, never with force, and only if the remote PR head has not moved.

## Merge freshness

Green checks are valid only for the exact SHA being merged. The completion enforcer requires `main` to be an ancestor of the PR head, verifies all canonical gates on that exact SHA and then sends only the PR number and expected SHA to Oracle. The Oracle finalizer repeats PR state, repository, base, head-SHA and current-main checks immediately before the merge.

If another PR advances `main`, the remaining PRs are not allowed to merge with stale checks. The next Auto-Healer event/sweep synchronizes `main` into each branch, which produces a new SHA and normal PR validation before merge.
