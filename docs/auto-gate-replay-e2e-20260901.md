# Auto Gate replay E2E proof

Controlled repository-governance proof for the `PR Completion Enforcer`.

## Reproducible identity

- Pre-fix `main`: `96482a787ed4f5e45ee93b38bcca75d22c451777`.
- Initial proof commit: `d661fbb8be714217c1dd880d6af63e69777f7f2f`, whose sole parent is the pre-fix SHA above.
- Missing-gate replay fix: PR #1311, merged as `a8e02a5ec626551e11e8fd377edc075dacd0b38b`.
- Bot `action_required` replay hardening: PR #1314, merged as `be1ebc5c9622b5b172d2ef2c55c5af2f8df49eec`.
- Successful bot-generated proof head: `cd4af2eafc85c2d811bebc982228ddff1215e261` on branch `test/auto-gate-replay-e2e-20260901`.

## Required gates

The eight required gates for this proof are:

1. `Quality Gate`
2. `ShopVivaliz QA`
3. `Repository Governance`
4. `Policy Engine`
5. `Autonomy Boundary`
6. `History Integrity`
7. `Ecommerce Excellence Audit`
8. `PR Policy Enforcement`

## Observed E2E sequence

1. The proof branch was created from pre-fix `main` commit `96482a787ed4f5e45ee93b38bcca75d22c451777`.
2. The `PR Completion Enforcer` detected that the PR head did not contain current `main` and requested GitHub's native `update-branch`.
3. GitHub created bot-authored synchronization commits. On proof head `cd4af2e...`, the normal `pull_request` runs were suppressed/blocked as `action_required` by the `GITHUB_TOKEN` recursion boundary.
4. The `PR Completion Enforcer` replayed only the approved required gates with `workflow_dispatch` on that exact head SHA.
5. All eight replayed gates completed successfully on `cd4af2e...`:
   - `Quality Gate` — run `33471567242`
   - `ShopVivaliz QA` — run `33471568696`
   - `Repository Governance` — run `33471570097`
   - `Policy Engine` — run `33471571368`
   - `Autonomy Boundary` — run `33471572734`
   - `History Integrity` — run `33471574142`
   - `Ecommerce Excellence Audit` — run `33471575688`
   - `PR Policy Enforcement` — run `33471576988`
6. No empty/manual validation commit was required to obtain the replayed green gates.

This proves the stale-head -> native bot sync -> missing/action-required gate replay path end to end while keeping failed gates fail-closed.
