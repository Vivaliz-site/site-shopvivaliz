# Git Divergence and Preservation — 2026-08-29

## Rule
No reconciliation begins from an unprotected dirty checkout. Bundles capture Git object history; binary patches capture tracked working-tree/index changes; untracked files are copied/archived separately because they may include runtime backups or secrets and must not be committed automatically.

## Backend MEI
- Runtime checkout: `/home/ubuntu/mei-mg-email`
- Captured HEAD: `64e7c71334f9badfe7567a6e8aeea27381633d0b`
- Origin: GitHub `fredmourao-ai/mei-mg-email`
- State: many tracked modifications plus untracked backup/test files.
- Preservation root: `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/mei/`
- `repository.bundle`: verified with `git bundle verify`.
- `worktree.patch`, `index.patch`, `status.txt`, `untracked.zlist`, `untracked.tgz`: preserved mode 0600.

## Backend Solange
- Runtime checkout: `/home/ubuntu/solange-rolla-consultorio`
- Captured HEAD: `e423e4660bd6faf202673117676f2237d3e6355d`
- Origin at capture: local migration bundle, not GitHub.
- Dirty state: two tracked messaging/webhook files and one untracked integration test.
- Preservation root: `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/solange/`
- `repository.bundle`: verified with `git bundle verify`; tracked and untracked state captured separately.

Backend preservation manifest SHA256: `57b8db062d5370249b3d5b45f576fef434a8a56cd034d508a90d7074558d2012`.

## Site A1
- Operational checkout: `/home/ubuntu/email-cutover-work`
- Captured branch/HEAD: `ops/email-zero-cost-prep-20260829` / `6cc64212d5a3bb575ce13fa8bc0efc97ea986e4c`
- Active deploy: `/home/ubuntu/shopvivaliz-deploy/releases/20260829-190751-47144bc0`
- Untracked state included `.codex-email-cutover-prompt.txt`.
- Preservation root: `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/site/`
- Repository bundle verified; tracked/index/untracked state and active-release pointer captured.

Site preservation manifest SHA256: `0912f77117923da54e09bc07294123eb9feb8dff7d249dddc0193bf2e27c0c54`.

## Fred Win
The primary `C:\site-shopvivaliz` checkout is intentionally left dirty and untouched. It contains many local workflow/script changes and multiple linked worktrees. A separate recovery worktree is used at `C:\site-shopvivaliz-worktrees\two-a1-recovery-20260829`.

Before reconciliation, a second independent preservation set was written under `C:\Users\FRED\repo-backups\two-a1-recovery-20260829\pre-reconcile-v2`. The Shop Vivaliz bundle verified successfully and its tracked/untracked state was copied; the available `C:\mei-mg-email` clone was also bundled. The SSH config was copied as `ssh-config.before-two-a1` before any alias correction.

Fred Win preservation manifest SHA256: `3dec42f18ea2f62dff90d8cf17b99189650106e705512046375f31253195c3f2`.

`C:\solange-rolla` was not a Git repository at this path during the preservation pass; the authoritative dirty Solange runtime checkout on backend A1 is already independently captured.

## Existing cutover work worth recovering
`C:\site-shopvivaliz-a1-cutover` / branch `fix/a1-vm-cutover-20260829` is clean and published. Commit `f9e4136112` changes 11 operational files from the retired E2 targets to the two A1 targets. This commit predates the current recovery branch and should be reviewed/cherry-picked as recovery evidence rather than manually reconstructed.
