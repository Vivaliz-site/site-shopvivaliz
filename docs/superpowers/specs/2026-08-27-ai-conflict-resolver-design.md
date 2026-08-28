# AI Conflict Resolver — Design

Date: 2026-08-27
Status: proposed
Repository: Vivaliz-site/site-shopvivaliz

## Goal

Add a conservative AI-assisted merge-conflict resolver with no per-token API cost while preserving the repository's existing Actions, deployment controls, commerce safeguards, and agent policies.

## Current constraint

GitHub Models was retired on 2026-07-30. The implementation therefore must not depend on GitHub Models. AI inference will run locally on a trusted self-hosted runner using Ollama or llama.cpp.

## Architecture

1. GitHub Actions detects conflicted pull requests and supports manual execution.
2. A disposable workspace attempts the merge without committing.
3. Only conflicted files plus bounded context are sent to a local model on the trusted runner.
4. Deterministic safety guards validate candidate resolutions.
5. Existing repository tests/smoke checks run according to the changed paths.
6. If all checks succeed, a normal commit may be pushed to an eligible same-repository PR branch. The resolver never merges the PR itself.
7. High-risk or ambiguous conflicts stop for human review.

## Security boundaries

- Least-privilege GITHUB_TOKEN permissions.
- Never provide secrets, .env files, credentials, certificates, tokens, database dumps, customer/order data, production data, or deployment credentials to the model.
- Never automatically resolve changes to workflow permissions, secrets handling, production deployment controls, payment/checkout logic, authentication/authorization, destructive migrations, infrastructure credentials, or mass deletion operations.
- Never force-push.
- Never perform privileged write-back to fork PRs.
- No blanket ours/theirs resolution.

## Repository-specific protection

For site-shopvivaliz, high-risk areas include checkout/payment, pricing and discount logic, catalog/ERP/Olist/Tiny synchronization, order/customer data, authentication, production deploy workflows, DNS/domain automation, database migrations, email/WhatsApp sending, Google/ads credentials, agent control/watchdog infrastructure, and any workflow capable of mutating production. Conflicts in these areas require deterministic resolution plus relevant tests or human review.

The existing large Actions estate must remain intact. The resolver is additive and path-scoped; it must not replace or broadly rewrite current workflows.

## Proposed files

- `.github/workflows/ai-conflict-resolver.yml`
- `.github/scripts/ai_conflict_resolver.py`
- `.github/scripts/validate_conflict_resolution.sh`
- `AI_CONFLICT_RULES.md`
- tests for conflict parsing, path protection, marker detection, output schema, and unsafe-diff rejection

## Model runtime

Use Ollama on a trusted self-hosted Linux runner over localhost/private networking. The model is configurable and defaults to a code-capable local model appropriate to available hardware. If inference is unavailable, the resolver fails closed.

## Resolution protocol

For each conflicted file, send BASE/OURS/THEIRS plus repository rules. The model returns only the proposed full content for that file in a machine-validated envelope. Reject output containing conflict markers, unexpected paths, unjustified substantial deletion, unrelated refactors, or protected-file modifications.

## Validation

Before any commit:

1. no conflict markers remain;
2. only originally conflicted or explicitly allowed generated files changed;
3. protected paths/rules are honored;
4. relevant lint, tests, build, smoke, and policy checks run based on changed paths;
5. no secrets are introduced;
6. conservative diff-size/deletion thresholds are respected;
7. production-deploy and mutation workflows are never invoked merely by the resolver.

## Commit and PR behavior

Successful low-risk resolutions create a normal commit on an eligible same-repository PR branch. The resolver does not merge the PR, weaken branch protection, disable tests, or alter existing auto-merge policy. Existing merge automation may act only after all normal required checks pass.

## Failure behavior

Fork PRs, protected-path conflicts, unavailable local model, unsafe/ambiguous output, failed tests, or oversized diffs leave the branch untouched and produce a concise diagnostic for human review.

## Cost model

No paid AI API is required. Self-hosted runners avoid GitHub-hosted Actions minute consumption; inference uses infrastructure already controlled by the project.

## Rollout

Phase 1 is report-only/dry-run against real conflicted PRs. Phase 2 enables guarded write-back only for low-risk paths after validation. Commerce, production, credentials, migrations, and destructive changes remain human-reviewed.
