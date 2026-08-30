# Two-A1 Recovery Architecture — 2026-08-29

## Binding objective
Restore all Shop Vivaliz, MEI MG Email, Solange and agent/automation capabilities that existed before the accidental retirement of `shopvivaliz-ai` and `shopvivaliz-micro-2`, using only the two surviving A1 VMs as production infrastructure.

## Authoritative topology
- `always-free-arm-1787907847-26`: backend/services/data plane.
- `shopvivaliz-free-a1`: storefront/web/deploy plane.
- The retired E2 instances are not to be recreated as production dependencies.
- Disaster recovery must be reproducible from Git, dumps, configuration archives and documented bootstrap procedures.

## Non-negotiable preservation rules
1. Never discard an uncommitted change until it is captured in a bundle, patch, archive or recovery branch.
2. Never delete a VM, boot volume, database, production release, backup or secret material as part of cleanup.
3. OCI destructive operations are fail-closed and require explicit resource identity plus a separately recorded destructive authorization.
4. `preserveBootVolume=true` is the default for any future instance retirement workflow.
5. No secret, private key, access token, PFX/P12 content or password may be committed to Git.
6. Exactly one MEI sender may be active in production at any time.
7. Audit success requires real functional execution; `systemctl active`, HTTP 200 or static code review alone are insufficient.
8. Recovery work happens on `recovery/two-a1-20260829` or an isolated worktree, never by resetting the active production checkout.

## Recovery evidence already confirmed
- Both E2 instances and their boot volumes are terminated in OCI.
- Surviving A1 backend contains the migration directory, database dumps/config archives and active MEI services.
- Surviving A1 site contains migration staging archives, MySQL dump/release archives and local Codex state.
- Fred Win preserves Codex history/state and the original SSH key/config aliases.
- GitHub preserves canonical repositories and historical workflows/commits.

## Capacity policy
Do not resize the A1 VMs until a fresh OCI quota/cost check is recorded and all critical backups are independently verified. Target distribution is backend-heavy and site-light, but actual values must remain within the tenancy's verified free allowance.

## Project ownership
### Backend A1
- PostgreSQL / MEI MG Email API, worker, replenisher, monitor, base-sync and Microsoft 365 automation.
- Solange services that require backend/database access.
- Central backup staging and disaster-recovery manifests.

### Site A1
- Apache/PHP/MySQL Shop Vivaliz storefront and admin.
- Site queue worker and deployment releases/shared state.
- Browser-facing validation and release smoke tests.

## Definition of done
Recovery is complete only when: all recoverable E2 state is classified; Git/local divergences are reconciled without data loss; both A1s have reproducible bootstrap/backup documentation; Shop Vivaliz passes storefront-to-order tests including freight; MEI passes ingest-to-send/queue/suppression/base-sync tests with sender uniqueness; Solange passes its application/integration tests; CI/CD and guards pass; restore drills succeed; and unresolved external gates are explicitly documented.
