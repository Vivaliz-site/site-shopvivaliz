# Recovery Inventory — 2026-08-29

## Scope and rule
This inventory freezes the state observed immediately after adopting the two-A1 recovery architecture. It is evidence, not a cleanup list. No dirty checkout, backup, database, release or local agent state may be discarded until independently captured and classified.

## Surviving production topology
| Role | OCI instance | Public IP | Private IP | CPU | RAM visible | Root/boot |
|---|---|---:|---:|---:|---:|---:|
| Backend/data/services | `always-free-arm-1787907847-26` | `144.22.157.209` | `10.0.1.38` | 2 OCPU | ~11 GiB | 100 GB boot / ~96 GB root |
| Storefront/web/deploy | `shopvivaliz-free-a1` | `163.176.103.253` | `10.0.1.112` | 2 OCPU | ~11 GiB | ~47 GB boot / ~45 GB root |

The retired E2 instances `shopvivaliz-ai` and `shopvivaliz-micro-2` and their boot volumes are terminated and are not valid operational dependencies.

## Backend A1
Fresh inventory showed MEI API, worker, queue replenisher, monitor, Docker, Desktop Commander, SSH, cron and OCI agents running. `mei-mg-email-base-sync.timer`, MEI autorepair and the Desktop Commander guardian were scheduled/active.

The MEI PostgreSQL container is `mei-mg-email-db` (`postgres:16-alpine`) bound to `127.0.0.1:5433`. The local Solange Supabase stack is also present. Its exposed development/runtime ports require later security review.

### Backend source state
`/home/ubuntu/mei-mg-email` is at `64e7c71334f9badfe7567a6e8aeea27381633d0b` with many tracked modifications plus untracked backup/test files. Its origin is GitHub. This state was captured before reconciliation in `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/mei/` as a verified Git bundle, binary patch, status, index patch and untracked archive.

`/home/ubuntu/solange-rolla-consultorio` is at `e423e4660bd6faf202673117676f2237d3e6355d` with two tracked messaging/webhook modifications and one untracked integration test. Its origin still points to the local migration bundle, not GitHub. The state was captured in `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/solange/` and both repository bundles verified successfully.

Backend pre-reconciliation manifest: `57b8db062d5370249b3d5b45f576fef434a8a56cd034d508a90d7074558d2012`.

## Site A1
Fresh inventory showed Apache, MySQL, Shop Vivaliz queue worker, Desktop Commander, SSH, cron and OCI agents running. Listeners included `443`, SSH, private Apache `127.0.0.1:8080` and MySQL `127.0.0.1:3306`.

Active deployment: `/home/ubuntu/shopvivaliz-deploy/releases/20260829-190751-47144bc0`.

Operational checkout: `/home/ubuntu/email-cutover-work`, branch `ops/email-zero-cost-prep-20260829`, HEAD `6cc64212d5a3bb575ce13fa8bc0efc97ea986e4c`, with untracked `.codex-email-cutover-prompt.txt`. It was preserved before reconciliation as a verified Git bundle, binary patches and copied untracked state in `/home/ubuntu/recovery-two-a1-20260829/pre-reconcile/site/`.

Site pre-reconciliation manifest: `0912f77117923da54e09bc07294123eb9feb8dff7d249dddc0193bf2e27c0c54`.

## Migration recovery corpus
The backend A1 contains `/home/ubuntu/oci-a1-migration-20260828/BACKUPS` with 74 files. A fresh complete SHA256 manifest was generated at `/home/ubuntu/oci-a1-migration-20260828/REPORTS/recovery-checksums-20260829.sha256`; manifest SHA256 is `bd7ba45d50cd258801427d6feb5ea6e7ab441be31c22959cfc516a7475ed2d05`.

The corpus includes full/final PostgreSQL dumps, MySQL dumps, Git bundles and worktree patches, Solange source archive, `/opt/m365`, systemd/config archives, deployed Shop Vivaliz release and site system/application configuration archive. It is not a full E2 boot-volume image.

## Fred Win recovery source
`LAPTOP-NIG4IFUU` retains the strongest agent-history source: Codex reports 214 active and 47 archived rollout sessions (261 total), healthy state/log/goals/memories/thread-history databases and ChatGPT authentication. Large session JSONL files include the migration period.

The original SSH private key remains present at `C:\Users\FRED\Downloads\ssh-key-2026-07-04.key`; only presence/metadata was inventoried, never key contents. `C:\Users\FRED\.ssh\config` still contained obsolete E2 aliases at capture time and must be backed up before correction.

Fred Win also contains multiple Shop Vivaliz worktrees/clones, `C:\mei-mg-email`, `C:\solange-rolla` and `C:\Users\FRED\repo-backups`. The active dirty Shop Vivaliz clone and available MEI clone were copied into `C:\Users\FRED\repo-backups\two-a1-recovery-20260829\pre-reconcile-v2` before reconciliation. Manifest SHA256: `3dec42f18ea2f62dff90d8cf17b99189650106e705512046375f31253195c3f2`.

## Known discrepancy requiring audit
Earlier migration evidence recorded an M365 automation crontab on the backend A1, while the fresh user crontab inventory was empty. This must be treated as a possible lost/regressed capability and verified from system cron, timers, backups and `/opt/m365`, not assumed healthy.
