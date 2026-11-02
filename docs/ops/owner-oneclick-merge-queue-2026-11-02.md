# Owner approval only: safe GitHub native merge queue

## Proven failure (02 Nov 2026)

Main GitHub repository ruleset 24523478 requires strict up-to-date
checks and dismisses stale approvals on any push. P2 PR #3543 already
has an exact-SHA APPROVED by shopvivaliz-human plus human-enabled native
auto-merge, yet main advances independently and its head is behind.
Rewriting that branch would change the SHA and revoke the formal grant;
simply rerunning CI cannot make a stale branch mergeable.

The current technical PR finalizer on the Marina runner also cannot
authenticate the independent technical GitHub operator, so conflict
repair fails. Its separate, exact owner-approved backend runner fix is
PR #3543. Reusing the existing backend runner and local Ollama avoids
duplicate paid executors and transferring secrets between VMs.

## P2 authorization and one-time bootstrap

This PR requests formal owner approval to:
1. Configure GitHub repository-level native merge_queue for main under
   ruleset 24523478, keeping mandatory validation-gate and
   Audit repository automation required and all bypass actors empty.
   Replace strict branch-HEAD-up-to-date checks with merge queue ALLGREEN
   synthetic-integrated-SHA checks, max one PR per integration group.
   The CI merge_group triggers were separately prepared by technical
   PR #3548, merged before this activation.
2. Allow only fredmourao-ai on the existing backend runner to enqueue
   SHA-bound PRs. For protected P2 paths the official GitHub API must
   prove APPROVED by shopvivaliz-human on the current exact HEAD with
   no later dismissal/change request; ordinary P1 PRs require no human
   review and are auto-enqueued by the same verified technical actor.
   Always preserve required original-head checks GREEN, impacted
   specialized CI workflows GREEN and explicit operational holds.
3. Let the GitHub merge queue bot integrate only when it has validated
   BOTH required contexts against the synthetic merge commit.
4. Recognize an internal security owner grant only with GraphQL
   AddedToMergeQueueEvent official evidence after the exact
   human approval, immutable PR HEAD, approved enqueuer and bot merge;
   technical direct merging remains invalid.
5. Protect owner-sensitive PRs with trusted-main validation against
   the GitHub API during pull_request and pull_request_review runs.
   Technical common PRs require no human review.

This bootstrap PR itself is a privileged P2 change and is not
integrated or activated by its own code while pending. The one-time
owner must approve its final SHA and integrate it through the currently
authorized manual or personally enabled native auto-merge flow.
After that one-time bootstrap, an official push-to-main workflow checks
the owner grant, activates the ruleset via technical authenticated API
and enqueues older exact owner-approved PRs, including #3543. Merely
creating this PR, a workflow run or another actor's comment is NOT
authorization to modify the GitHub ruleset.

## Evidence, idempotence, rollback and failure modes

Every enqueue is submitted via GitHub GraphQL with expectedHeadOid.
Never edit a protected PR HEAD after APPROVED; if it changed, request
a new official review only for changed scope. No direct merges, branch
protection bypass, PR approvals by agents, GH_TOKEN writes or fake green
statuses. All actions use existing backend runtime and credentials.
Production site, DB, marketplace and Cloudflare are unchanged.

Scripts fail closed on mismatched ruleset ID, name, baseline protections,
required statuses, host, actor, missing grant, revoked approval or
ambiguous GitHub API pages. The one-time ruleset activation reads back
effective settings and records an audit signal. In a failed native
merge-group test, halt enqueues, fix real CI defects and optionally
rollback ruleset through the same formal owner-granted administrative
path; do not silently downgrade protection.

## Provider E2E post-merge (02 Nov 2026): technical merge attribution

PR #3543 merged through the official queue with enqueuer and REST
merged_by=shopvivaliz-human. PR #3554 merged through the same queue
with enqueuer=fredmourao-ai; REST attributed merged_by to the technical
enqueuer rather than the generic queue bot. Both approved HEADs
remained immutable, both synthetic merge_group checks were GREEN,
and main ruleset preserved strict=true and no bypass.

The first grant verifier for #3554 incorrectly accepted only the
hypothetical github-merge-queue bot identity, making the postmerge
activation action #41519710001 fail despite an active queue.
A security-maintaining fix requires real event, synthetic SHA and
official required checks before accepting technical attribution.
See security/requests/native-queue-merge-technical-attribution-20261102.md.
