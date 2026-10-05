# Active worktree cleanup safety implementation plan

Goal: preserve newly created and explicitly locked worktrees while queued tasks wait, without disabling ordinary disk cleanup.
Spec: AGENTS.md workspace ownership and continuity rules; git worktree lock contract.
Incident: housekeeper deleted audit-v5-functional-privacy-20261005 at2026-10-05T00:53:08Z age_hours=0. The pending test then failed with missing directory. Source and installed housekeeper hashes match. Disk guard can set FULL_TTL_HOURS=0; delivered() treats HEAD already in main as delivered even for a new branch.

Architecture: add a shared conservative protection check before worktree cache/removal in both workspace loops. Preserve Git-locked worktrees regardless of age and a fixed1800second grace even with emergency TTL0. Keep existing dirty/active/undelivered checks. Fail closed on unreadable metadata. No cleanup disabling or real-data deletion in tests.

Files: ops/host/shopvivaliz-workspace-housekeeper; tests/test_workspace_housekeeper_safety.py; scripts/repository-governance-validate.sh.
Execution: Superpowers TDD, review and verification. One repo only, no certifier changes. Prior functional privacy branch remains preserved as a Git ref; its tests never executed and no patch is claimed.

- [x] Reproduce live removal from task results and housekeeping log; verify source/runtime parity.
- [x] RED: execute actual cleanup loop only inside disposable Git fixtures with TTL0; prove recent/locked protection missing, old-delivered cleanup still works.
- [x] GREEN: minimum-age and Git-lock protection in both loops; negative boundaries and dirty fixtures.
- [ ] Canonical test gate, independent review, protected merge.
- [ ] Install only the merged canonical hygiene scripts, then dry-run evidence on backend; no disablement or active-release editing.

Review focus: TTL0, old locked worktree, fresh clean main-based branch, dirty state, grace boundary, old unlocked delivered work, metadata errors, both workspace roots.

## Independent review and rulings
Review f1f9fd5f found late-lock TOCTOU and inherited Git environment risks. Both were reproduced in disposable fixtures, then corrected with immediate pre-deletion rechecks and isolated fixture environments. Fresh chat wrapper gap was regraded material and covered. Preservation reasons are logged. Added old/dirty chat and empty-lock controls; source extraction now fails if a required function is missing; test fallback cannot invoke real sudo/GitHub.
Grace is not indefinite reservation: lock worktrees at creation for long/queued jobs. Rechecks are not an atomic cross-process protocol; lock must precede eligibility. Broken metadata is preserved, not destructively guessed away; reason=metadata makes this observable.
Full suite exposed an unrelated stale token-file fixture after main2701 requires conversation binding. Only the synthetic fixture now binds an explicit fake conversation and asserts it; production routing/auth gates are unchanged. Before this fix the isolated baseline test failed0!=1; after fix all26 backend-runtime tests pass.
