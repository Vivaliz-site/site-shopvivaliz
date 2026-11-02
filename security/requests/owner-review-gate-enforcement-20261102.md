# P2: Required owner-only review gate for protected pull requests

Scope: repository Vivaliz-site/site-shopvivaliz, mandatory-validation-gate.yml
and the static gate contract. No external access, secrets, root or RBAC grant.

Root cause confirmed: required validation-gate completed SUCCESS on PR #3543
before any native APPROVED review from shopvivaliz-human. The verification code
was introduced by PR #3546 without being wired into that required workflow.

Proposal: for pull_request and pull_request_review, checkout the exact trusted
base SHA separately, execute only the protected base verification script, and
check the current pull request number and exact HEAD against native GitHub
reviews. Fail closed on missing trusted code, absent owner approval, stale SHA
or unavailable API. Ordinary technical PRs remain autonomous and require
no human review. Commercial price/stock changes remain governed by the
independent existing enforce-autonomy-policy.py gate; this new security-owner
validator does not replace or downgrade commercial approval. Preserve all
existing required checks.

Evidence: tests.test_owner_required_review_gate (positive/negative decision),
tests.test_owner_review_required_workflow (positive/negative workflow wiring),
scripts/check_pr_completion_policy.py, Python/Bash syntax, YAML parse, Git diff.
Test review and code-check workflows on the exact HEAD; do not fake green.

This change is P2: GitHub protected workflow review by shopvivaliz-human
on exact HEAD, with owner-enabled GitHub-native auto-merge or owner manual
merge only after required checks. A chat authorization does not replace review.
Rollback: protected PR reverting just the workflow wiring after dedicated
owner approval and regression verification.
