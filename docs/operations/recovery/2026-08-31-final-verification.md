# Two-A1 recovery — verification checkpoint — 2026-08-31

## Architecture

The binding production architecture is two OCI A1 VMs only:

- Backend/data/services: `always-free-arm-1787907847-26`, 3 OCPU / 16 GB.
- Storefront/web/deploy: `shopvivaliz-free-a1`, 1 OCPU / 8 GB.
- Retired E2 hosts and boot volumes are not part of runtime or rollback. Historical references remain evidence only.
- No destructive OCI action or new paid resource was required by this recovery checkpoint.

## Shop Vivaliz

Fresh live-host verification:

- `apache2=active`
- `mysql=active`
- `shopvivaliz-queue-worker.service=active`
- `shopvivaliz-token-renewer.service=active`
- Active release observed after the governance merge: `b7c8f07244a757a3df5d195821c57894bf998694`.
- `retired-e2-endpoints-contract: ok`.
- Olist/Tiny shared `.env`: `ubuntu:www-data 0640`.
- Olist/Tiny renewer runs as `ubuntu:www-data` with `--interval 300 --retry-interval 300 --refresh-margin 1800`.
- `tests/token-renewer-runtime-permissions-contract.sh` and the production runtime reconciliation contract passed on the active release.
- Recent renewer logs showed normal proactive checks and no new `PermissionError` after ownership alignment.
- Mercado Livre OAuth was recovered through the repository's existing backup + refresh/readback path. The post-recovery production functional audit passed home, catalog, cart, checkout, orders health, real Melhor Envio freight and critical integrations.
- Isolated Python inventory on the recovered main line completed with `290 passed, 17 skipped, 44 subtests passed`; canonical quality gate and safe QA also passed.

## MEI MG Email

Fresh backend verification:

- API, worker, replenisher, monitor, NDR guard and Docker are active; zero failed systemd units were observed.
- Worker main PID is the only process in `mei-mg-email-worker.service` cgroup and executes `python -m worker.safe_entrypoint_v2`.
- Current sampled queue/status remained near the target with one sender path only.
- Recent worker batches recorded submitted messages with DB proof and zero send failures.
- `GRAPH_MAIL_READ_OK` passed using the app-only Graph audit script.
- NDR guard continued to record `NDR_HARD_BOUNCE_SUPPRESSED` events.
- Flyway recovery had already been reconciled to production-applied checksums; no migrations were executed during this final checkpoint.

## Solange

Recovery verification completed earlier in the same recovery cycle with 207 tests, typecheck, lint, dependency architecture, module contracts, migration check and production build passing.

Dependabot governance is now fail-closed for PR debt:

- npm `open-pull-requests-limit: 0`.
- GitHub Actions `open-pull-requests-limit: 0`.
- `tests/dependabot-no-pr-contract.test.mjs` enforces both limits and passed in a fresh isolated run.
- Dependabot PRs #4, #5, #6, #7 and #8 are closed without merge.

GitHub Actions for this private repository may still be prevented from starting by account billing/spending-limit state; this is external infrastructure, not a code-test failure.

## Pull-request state and governance

Fresh cross-repository search returned zero open pull requests for:

- `Vivaliz-site/site-shopvivaliz`
- `fredmourao-ai/mei-mg-email`
- `fredmourao-ai/solange-rolla-consultorio`

Shop Vivaliz PR #1290 was merged only after the exact current head `c3a7a43a69c5de02fbaf11f4132aaa83908864b4` completed 12/12 workflows successfully. Merge commit: `3c469fa257d9eb9f78c35c94d85411fdf823bae5`. The merged governance changes remove active direct-push/destructive-reset behavior, make automatic diagnostics read-only, preserve sanitized evidence as artifacts, and gate production-changing repair paths behind explicit manual authorization.

## Agent rules carried forward

1. Preserve before repair or cleanup.
2. Exactly two production A1 VMs unless a later approved architecture explicitly supersedes this checkpoint.
3. Never reintroduce retired E2 endpoints into active workflows, scripts, systemd, cron, SSH, MCP or deploy configuration.
4. MEI must retain exactly one production sender.
5. Do not print or commit secrets.
6. No destructive OCI action as part of ordinary recovery/maintenance.
7. Zero PR debt: merge only after green safety gates, otherwise close while preserving evidence.
8. A systemd `active` state is not sufficient evidence; functional readback is required.
9. Do not add a new periodic Mercado Livre OAuth writer without explicit design approval; current refresh ownership must remain fail-closed and non-concurrent.
