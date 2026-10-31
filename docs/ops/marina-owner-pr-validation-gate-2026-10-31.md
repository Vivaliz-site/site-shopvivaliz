# Marina independent MCP: owner-only PR validation gate

**Status:** REVIEW ONLY / NO MERGE BY AGENT / NO AUTO-MERGE / NO DEPLOY / NAO_APTO.
**Host:** marina-a1-4c24g (OCI ESPOSA). **Repository:** Vivaliz-site/site-shopvivaliz.
**Related:** merged #3446; issues #3152 and #3156; docs/knowledge/security-approval-via-pr.md.

## Decision requested from the owner in this exact PR

Validate the introduction of marina-direct-mcp/owner_pr_gate.py and its
negative-first tests as an internal **fail-closed authorization adapter** for
future Marina MCP administrative operations. This PR does **not** authorize
unrestricted root, sudo, database changes, host permissions, tunnel keys,
OpenAI Platform roles, or deployment. No service is enabled and no runtime
grant is issued by merging this PR.

Only a distinct owner-only approval PR containing an exact grant under
security/owner-approvals/<PR_NUMBER>.json may authorize a later, individually
scoped operation when the runtime enforcement is completed and certified.
The owner must personally validate/merge such PR; agents must never merge or
enable auto-merge for a security authorization PR.

## Actual changes in this proposal

- Introduces a typed fail-closed adapter that calls the existing
  scripts/validate-owner-pr-grant.py with exact repository, principal,
  resource, PR, HEAD/MERGE SHAs and capability; it accepts only the exact
  official verifier success response.
- Requires an **independent human-only attestation** callback and a separate
  **effective runtime permission** callback before the GitHub check.
  Neither callback is implemented, configured or exposed to a client here:
  without them, **all authorization requests are denied**.
- Tests deny missing human attestation, missing runtime permission, incorrect
  scope, malformed SHA, wrong verifier output, missing protected configuration
  and exceptions.
- Does not modify server.py, expose administrative tools, change systemd,
  modify GitHub rulesets, invoke OCI, alter secrets, change permissions or
  touch production. Existing two Marina MCP tools remain read-only.

## Boundary of owner approval and the identity limitation

The GitHub connector used by agents may authenticate as fredmourao-ai.
Therefore merged_by.login == fredmourao-ai **alone cannot prove the action
was human-only**. The runtime authorizer must obtain an independent
human-exclusive identity/attestation inaccessible to agents, bots, GitHub
connector credentials or runners. Until that is real and tested, fail closed.
Do not set an unverified environment boolean as a substitute for attestation.

Owner approval via this PR is valid as code review for the exact diff, but it
is not automatic proof that a particular privileged operation was approved
and is not a substitute for external OCI/OpenAI Platform RBAC, host privileges
or MFA. No duplicate owner authorization should be demanded for the same
exact scope after a genuinely verified owner-only approval.

## Required follow-up BEFORE any mutating MCP tool may be enabled

1. Officially authenticate the human-exclusive owner decision within the PR
   workflow and prove no agent-accessible credential can impersonate it.
2. Package the exact reviewed verifier with the immutable Marina MCP release,
   pin SHA and enforce its call **before every grant and operation**.
3. Implement restricted tool-by-tool allowlists (resource, host, principal,
   capability, operation, path/command and TTL), effective host RBAC, lease,
   fencing, CAS/idempotency, complete redacted audit log, rollback and
   explicit rejection on revoked/modified/expired grants.
4. Obtain host privilege and OpenAI Platform tunnel/workspace registration
   through their own official mechanisms when required. A GitHub PR cannot
   create those permissions on its own; no transport is a bypass of a denial.
5. Run only the affected CI gates, negative authorization tests, live token
   denial/positive tests in a dedicated isolated release, real ChatGPT MCP
   tool calls, restart/rollback checks and independent official certification.

## Validation checklist for this PR

- [ ] Owner personally reviews the exact changed files and HEAD SHA.
- [ ] Relevant CI security checks are green; no required checks bypassed.
- [ ] Independent human-only identity limitation is acknowledged; no claim of
      privilege availability or APTO.
- [ ] Owner alone marks Draft ready and performs merge (never an agent/bot).
- [ ] Subsequent privilege PRs specify exact operations/grants and complete
      official provider provisioning, E2E and rollback evidence.

**Current disposition:** code proposal; MCP Marina remains inactive and
unprivileged; OWNER_PR_GRANT=DENIED unless independent human proof, protected
configuration, GitHub verifier and effective runtime RBAC all pass.
