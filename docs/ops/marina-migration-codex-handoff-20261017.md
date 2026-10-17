# Marina pre-cutover / Codex handoff (2026-10-17)

**Status: NO_CUT / NAO_APTO.** This is an evidence checkpoint, not an authorization to migrate live traffic, delete hosts, change DNS, or bypass an access/security refusal.

## Confirmed evidence

- Fred-Win scheduled task `ShopVivaliz-Codex-Marina-Prefinal-20261017`: state `Ready`, last task result 0. The Codex functional result was blocked; this scheduler result is **not** proof of migration success.
- Windows test-gate root cause: `System32/bash.exe` selected WSL without an installed distro. Linked-worktree fixture files also have a hidden attribute that prevents test-only overwrite. `tests/test_workspace_housekeeper_safety.py` was corrected to discover Git Bash from the installed Git root and clear the Windows hidden attribute **only on the disposable fixture**. RED: 20 failures/2 errors; subsequent GREEN: 18/18 tests with proper Git Bash and a targeted fixture fix.
- The complete Windows pre-commit hook **did not pass**: a separate POSIX-only functional-audit security test reported failures under Windows. Do not disable that test. Require Linux CI checks before any merge.
- Existing `storage/codex-bridge/state.json` is a separate, pre-existing local edit. It was not included here.
- ML Pricing import on Marina: 27 tables, 16 migration records and business counts matched the source in the last documented checkpoint; no duplicate production worker was started.
- Marina staging Playwright evidence: 19/21 read-only cases passed. Two mobile-only selectors were tested on desktop viewport in a different source/test revision. This **does not** certify full E2E, production routes, persistence, providers, checkout completion, or post-cutover operation.
- Existing backups/restoration evidence must be preserved. The final transactional delta and authorized Supabase transfer/restoration proof were not established. The platform expressly refused specified Supabase transfer/backup and IAM operations; **do not retry them through alternative tools or agents**.
- Latest user decision for Amazon Returns is to **reuse the existing Cloudflare Tunnel identity after prerequisites and authorization**, not to create a competing tunnel. Never run two connectors for that identity simultaneously.

## Remaining pre-cutover gates

1. Obtain official resolution of the specific platform refusals; validate restored live-data delta and referential consistency without unauthorized backup transport.
2. Validate all critical services with genuine providers, approved access routes, TLS/tunnel identity, renewals, queues, callbacks and SAFE-T workflows (FBA Onsite), using non-mutating or sandbox tests where necessary.
3. Complete published UI E2E on the destination for the exact release/SHA, including mobile and reload/persistence, with independent evidence. Temporary mTLS test configuration is not final proof.
4. Demonstrate zero duplicate writers/connections, ownership/lease/fencing, health under reboot, GitHub CI/deploy, rollback rehearsal, and exact origin/destination matching.
5. Reconcile source/destination with a final authorized synchronization, then perform the coordinated cutover only after gate approvals.

**Prohibited until fully proven:** source VM decommission or deletion, live DNS cutover, paid OCI resize, startup of duplicate marketplace workers, unapproved data moves, or security workarounds.

Canonical progress source: `/home/ubuntu/migration-marina-a1/STATUS.md` on the controller, and `docs/ops/oci-marina-migration-v5-20261017.md` in the repository.
