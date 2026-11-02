# Native GitHub merge queue: required checks preflight

Problem: main uses strict up-to-date checks, and GitHub dismisses stale
reviews when an approved PR branch changes. The owner-approved PR #3543
has native auto-merge enabled but is behind. Syncing it would invalidate
the exact-SHA owner grant and demand another approval.

This P1 preparation adds merge_group.checks_requested to both existing
required workflows. Queued integration SHA runs full mandatory validation
and repository governance. No ruleset, privilege, token, reviewer requirement,
owner approval or production service is altered in this PR.

Enabling the GitHub native merge queue is a separate P2 ruleset change,
requiring formal exact-SHA owner approval before activation. An owner-only
queue enqueuer and authorization verifier changes likewise require
explicit P2 review. Both must preserve the human-approved HEAD and
verify all required official GitHub merge_group statuses, never simulate
success, bypass rules, or downgrade checks to loose.

Rollback: revert these trigger additions if merge queue is not approved.
