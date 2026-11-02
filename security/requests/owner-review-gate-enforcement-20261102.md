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


## Additional explicit P2 authorization: native GitHub merge queue

The owner is also asked to authorize **one** precise GitHub repository ruleset
change, **only after** the two required merge_group workflows from PR #3548
have been merged, tested and verified in main. This operation addresses a
production defect: branch protection currently requires up-to-date branch
checks and dismisses stale reviews on push. PR #3543 is already APPROVED
by shopvivaliz-human on the exact immutable SHA, but cannot merge when main
advances. Do not force sync and invalidate that approval.

Exact requested administrative action: add the single rule object from
config/github-main-merge-queue-p2.json to ruleset ID 24523478 for public
Vivaliz-site/site-shopvivaliz, retaining **all** existing rules,
required status checks (Audit repository automation and validation-gate),
strict policy, review settings, target branch and empty bypass list.
Merge method SQUASH; 1 build concurrent, 1 PR per merge group, ALLGREEN,
minimum 1, no wait, and 40-minute timeout. This protects queue evaluation
of the synthetic integration SHA without changing the formally approved PR
HEAD. No bypass, loosening, force-push, unreviewed actor or paid agent.

Operator preflight: confirm github.com repository owner/admin permission
and latest ruleset via official API, compare exact current rule/branch SHA,
verify owner-approved and merged PR for **this request** with SHA-bound review
and owner merge identity; verify the new owner-review validation-gate is
effectively installed in main and both required workflows handle merge_group.
If any divergence, fail closed, do not apply. Use standard GitHub REST ruleset
update to append only the approved rule, readback exact JSON and run an
authorized documentation-only canary through the actual GitHub merge queue.
Do not run this change just because the request exists or someone approves
in chat. Preserve the existing authorized user identity and MFA boundaries.

Rollback: using the same official admin channel, re-read the unchanged
ruleset and remove only this newly added merge_queue rule, retaining the
previously snapshotted ruleset untouched; confirm readback and then
revert the merge_group-only workflow hooks with another protected PR.
