# Owner authorization request — continuity rollout of technical PR #3498

Status: REQUEST ONLY — does not grant runtime privileges or clear a lock.
Target repository: Vivaliz-site/site-shopvivaliz
Technical PR: #3498
Technical merge commit: ae7857ee1187686a0b75bb0bd2e370a297910f60
Observed main HEAD when preparing this request (2026-11-02): bc7b92fe5af2e060605a826ee76410fcf846af39
Target host: always-free-arm-1787907847-26 (backend/controller)
Principal requested: authorized backend maintenance operator, verified from protected runtime identity
Required capability: controller_promote maintenance; separately scoped bridge installation

## Intent

Authorize the legitimate backend maintenance operator to deploy the currently verified **remote main HEAD**, including technical PR #3498, with immutable releases and the canonical installer. The old merge SHA ae7857ee1187686a0b75bb0bd2e370a297910f60 must NOT be passed to controller_promote if it differs from refreshed origin/main. No promotion may be attempted until the intended exact HEAD SHA has been reverified, and the owner-reviewed grant supports precisely that value.

## Required preconditions (deny otherwise)

1. Verify controller_status, live owner/dispatcher status and _runtime-lock/lock.json. Any still-live durable-recovery owner or recovery lease means STOP: do not force-release, overwrite, expire manually, or bypass fencing; do not run a duplicate executor.
2. Verify main HEAD through GitHub official API AND controller origin/main refresh; HEAD equality is required by controller_promote.
3. Enforce this owner's APPROVED review on the exact authorization PR HEAD, issued from the separate shopvivaliz-human account. Follow canonical security/owner-approvals/<PR>.json grant verification if supported for the exact resource, operation, actor, expiry and target repository. A request document is not itself an active grant.
4. Acquire **new** official maintenance lease scoped to controller_promote; assert lease_id/fencing_token before mutation. Never release another owner's lease.
5. Call controller_promote only with the exact remotely verified HEAD, verify active_sha == that SHA; release only the acquired lease through the official mechanism.
6. In a separate authorized lease scope use scripts/install-chatgpt-continuity-backend-bridge.sh from that immutable release, verify exact SHA256 of scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs, systemd active state and heartbeat.
7. Confirm continuity_ready, dispatcher and real bound conversation E2E PROGRESS_CONFIRMED / certifier ok=true before declaring APTO. Protect browser sessions, secrets, cookies and external OpenAI MFA.
8. Record the audit trail with exact PR, commit, host, live lease/fence, hashes, service status and E2E; no cross-PR changes.

## Risk / rollback

Risk of overlapping mutable runtime operations, interruption of detached recovery, or false-green continuity. Safeguard: fail-closed lock checks, distinct leases, immutable releases and explicit SHA verification. Roll back using the canonical immutable release procedure under a separate valid lease; never edit live current in place.

## Notes for reviewers

This PR contains the grant at security/owner-approvals/3499.json, bound to resource mcp:controller, principal shopvivaliz-central-mcp, operation continuity_release_install, and the technical merge commit. The authorization verifier can check the grant, but an end-to-end runtime adapter for these exact capabilities must be confirmed before exercising them. The grant does not itself install that adapter or confer OS privileges. Approval on the final PR HEAD is required. Approval does not substitute external RBAC/MFA and cannot clear a live lock.
