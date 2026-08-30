# Google Ads Optimization Intelligence — Design

**Date:** 2026-08-29
**Status:** Approved in chat for design; implementation pending explicit review of this written spec.
**Repository:** `Vivaliz-site/site-shopvivaliz`

## Goal

Build a permanent Google Ads optimization layer that explains optimization-score changes, audits recommendations and real performance, identifies waste and growth opportunities, and proposes or applies only evidence-backed changes with strict guardrails. The optimization target is profitable sales/ROAS, not an artificial 100% Google optimization score.

## Current State

The repository already contains Google Ads automation, including `.github/workflows/google-ads-rest-audit.yml`, `.github/workflows/google-ads-keyword-expansion-controlled.yml`, and `.github/workflows/google-ads-post-expansion-monitor.yml`.

The existing REST audit authenticates against Google Ads API v25, reads campaigns and 30-day metrics, and checks only the recommendation type `DYNAMIC_IMAGE_EXTENSION_OPT_IN`. It does not yet provide a complete optimization-score/recommendation audit, search-term waste analysis, keyword-level action classification, conversion-health checks, or a durable guardrail/risk model.

Production is VM2 (`shopvivaliz-micro-2`, `136.248.69.116`). Google Ads credentials used by runtime HTTP/API workflows live in `/home/ubuntu/shopvivaliz-deploy/shared/.env`. No secret value may be committed, logged, printed, or persisted in artifacts.

## Success Criteria

The system must:

1. Read the current Google Ads optimization score where supported by the API and capture the recommendation inventory with recommendation type, affected resource, recommendation impact/uplift metadata when available, and enough context to explain score changes.
2. Separate platform recommendations from business-value recommendations. A recommendation must never be applied only because it increases Google's score.
3. Audit campaigns, ad groups, keywords, search terms, ads, conversion actions, budgets, bidding strategy, cost, conversions, conversion value, CPA, ROAS, CTR, and other available diagnostic fields over 1-day, 3-day, 7-day, and 30-day windows where the API supports them.
4. Identify likely waste: search terms with material spend and no conversions, low-intent queries, duplicated/overlapping keywords, keywords with spend but insufficient business return, and campaigns/ad groups consuming budget without evidence of value.
5. Identify growth opportunities: high-intent search terms, converting keywords, profitable campaigns/ad groups, useful long-tail queries, and ad groups where creative/relevance improvements are justified.
6. Audit conversion measurement health, including purchase/conversion actions, conversion value, transaction identifiers or equivalent deduplication signals where available, and consistency between Google Ads and the site's analytics instrumentation. A missing or suspect conversion signal must block automated budget/bidding expansion.
7. Classify every proposed change into `APPLY`, `TEST`, `REVIEW`, or `REJECT`, with reasons and evidence.
8. Use guardrails so risky changes are not made automatically when data is sparse, tracking is suspect, recommendation confidence is low, or the projected spend increase is material.
9. Record each decision and resulting action in a machine-readable audit report without secrets.
10. Measure impact after changes and support rollback/reversion of repository-controlled configuration when post-change evidence degrades.

## Non-Goals

- Do not optimize for Google's score alone.
- Do not blindly accept all Google recommendations.
- Do not automatically increase budget solely because Google recommends it.
- Do not automatically switch to broad match, Performance Max, or a new bidding strategy without sufficient conversion evidence.
- Do not invent revenue, conversions, ROAS, CPA, product margins, or attribution values.
- Do not expose or rotate Google Ads/OAuth secrets as part of this feature.
- Do not bypass repository protections, GitHub checks, or production safeguards.

## Architecture

The feature is split into five small, testable units:

### 1. Read-only Google Ads collector

A Python module will own Google Ads REST authentication and GAQL execution. It will reuse the same environment contract already used by `google-ads-rest-audit.yml`, but the reusable logic must move out of inline workflow heredocs into a focused script under `scripts/google_ads/`.

Responsibilities:
- load required environment variables from the production `.env` only on the VM;
- refresh OAuth access token;
- execute GAQL safely against API v25;
- normalize API errors without logging secrets;
- fetch optimization/recommendation, campaign, ad-group, keyword, search-term, ad, budget/bidding, and conversion-action datasets;
- collect multiple time windows without duplicating query logic.

This layer is read-only and must have no mutation methods.

### 2. Evidence and scoring engine

A separate pure-Python module will receive normalized records and calculate evidence used for decisions.

It will derive, when mathematically valid:
- CTR;
- CPC;
- CPA;
- ROAS (`conversion_value / cost`);
- conversion rate;
- spend-without-conversion indicators;
- trend deltas between 1/3/7/30-day windows;
- data-sufficiency flags;
- tracking-health flags;
- change-risk level.

All divisions must handle zero denominators explicitly and report `null`/`insufficient_data` instead of fabricating metrics.

### 3. Recommendation classifier and guardrails

A policy module maps Google recommendations plus observed account performance to one of four actions:

- `APPLY`: low-risk, reversible, evidence-backed, and inside configured limits.
- `TEST`: promising but requires an experiment or bounded change.
- `REVIEW`: potentially useful but requires human/business input or more data.
- `REJECT`: conflicts with profitability/measurement/safety constraints or lacks evidence.

Default stance is fail-closed: unknown recommendation types and ambiguous conditions become `REVIEW`, not `APPLY`.

Initial hard guardrails:
- no budget increase if conversion tracking health is not `healthy`;
- no budget increase when there are too few recent conversions to justify scaling;
- no automatic budget increase greater than 10% in one action;
- no broad-match expansion unless the campaign has stable conversion evidence and negative-keyword protections;
- no bidding-strategy migration when recent conversion evidence is insufficient;
- no automated negative keyword when the term has a recorded conversion or meaningful conversion value;
- no destructive removal of keywords/ads/campaigns by the autonomous path; disabling/removal remains `REVIEW` unless a later approved spec explicitly permits it;
- recommendation-score uplift is advisory only and never sufficient evidence by itself.

Thresholds that depend on business economics (target CPA, minimum ROAS, gross margin) must come from explicit configuration. If not configured, the engine must not invent them; affected decisions stay `REVIEW`/`TEST`.

### 4. Report and decision ledger

The audit produces a sanitized JSON report under `ops/google-ads/` containing:
- timestamp and API version;
- account/customer identifier in non-secret form;
- optimization score and recommendation summary where available;
- metrics by configured windows;
- tracking-health summary;
- findings;
- proposed actions and their classification;
- guardrails that blocked actions;
- source evidence references such as campaign/ad-group/criterion IDs;
- before/after observation metadata for previously applied changes.

A human-readable Markdown summary may be generated from the same JSON, but JSON is the source of truth.

No access token, refresh token, client secret, developer token, raw authorization header, SSH key, or `.env` contents may appear in reports/logs.

### 5. GitHub Actions orchestration

Existing workflows will be reused rather than creating a large parallel automation family.

The current read-only audit workflow will be refactored to call the collector/report generator on VM2. A separate controlled optimizer workflow may consume a reviewed decision file for safe mutations. Read-only collection and mutation must remain separate jobs/workflows so audit cannot accidentally mutate the account.

The post-expansion monitor remains the pattern for measuring a bounded change after it happens.

## Data Flow

1. GitHub Actions authenticates to VM2 using the existing SSH secret.
2. VM2 loads Google Ads credentials from `/home/ubuntu/shopvivaliz-deploy/shared/.env`.
3. Collector refreshes an OAuth access token in memory and queries Google Ads API v25.
4. Raw API responses are normalized in memory.
5. Evidence engine computes metrics and data-sufficiency/tracking-health state.
6. Recommendation classifier combines Google recommendation metadata with business evidence and guardrails.
7. A sanitized audit report is produced.
8. Safe low-risk actions may only be executed by a separate approved mutation path; risky/ambiguous actions remain `TEST`, `REVIEW`, or `REJECT`.
9. Post-change monitoring compares later 1/3/7/30-day evidence with the baseline and records the result.

## Recommendation Handling

The system must not assume all recommendation types have the same value. Recommendation handlers are explicit and testable. Unknown types fall back to `REVIEW`.

Examples:
- responsive-search-ad/asset completeness: usually `APPLY` or `TEST` if content quality rules pass;
- dynamic/image assets: `TEST` or `APPLY` only when destination/product relevance is verified;
- new keyword suggestions: compare to catalog/product intent, existing query performance, and overlap before `TEST`/`APPLY`;
- broad match: default `REVIEW` unless conversion and negative-keyword safeguards are strong;
- budget increases: default `REVIEW`; bounded automatic changes only when configured profitability and tracking constraints pass;
- bidding migrations: `TEST`/`REVIEW`, never score-driven;
- Performance Max expansion: `REVIEW` unless an explicit later optimization plan defines feed, attribution, brand-exclusion, and budget constraints.

## Search-Term and Negative-Keyword Logic

Search terms are high-value evidence but potentially dangerous to automate.

A term can become a negative-keyword candidate only when:
- it has no attributed conversion/value in the evaluation window;
- spend or click volume exceeds a configured evidence threshold;
- it is not a close commercial-intent match to an active catalog product/category;
- it is not a protected brand/product term;
- the negative match type and scope will not block known converting queries.

Automatic insertion, if enabled in the implementation plan, must be conservative and limited to clearly irrelevant terms. Ambiguous terms are `REVIEW`.

## Conversion Measurement Gate

Before any scaling recommendation can be `APPLY`, the audit must determine whether the conversion signal is trustworthy enough.

The minimum health check must inspect:
- enabled conversion actions relevant to purchase/sale;
- presence of recent conversion activity;
- conversion value availability when ROAS is used;
- obvious duplicate/multiple counting risk where detectable;
- site-side implementation evidence already available in the repository for Google Ads/GA4 purchase events.

If API and site instrumentation cannot be reconciled automatically, the report must state `tracking_health=unknown` and block automated scaling.

## Mutation Strategy

Phase 1 is read-only and diagnostic. Mutation is added only after the read-only report is verified against live account data.

When mutation is enabled:
- every mutation must have a deterministic precondition;
- the command must record the Google Ads resource changed, old state when available, requested new state, reason, and correlation ID;
- bounded changes must be idempotent;
- mutation APIs must reject actions not explicitly classified `APPLY` by policy;
- budget changes must enforce the 10% per-action cap and any configured daily/account cap;
- repository-controlled configuration changes must be reversible by Git history;
- Google Ads account mutations that cannot be automatically reversed must be labeled before execution and remain `REVIEW` unless explicit permission exists.

## Error Handling

- OAuth refresh failures: fail the audit with a sanitized reason; never print credential values.
- Google Ads API HTTP/GAQL errors: record status/code/message and failed query label; do not dump full request headers.
- Partial dataset failure: report `partial=true` and block mutations.
- Missing business thresholds: continue read-only audit but classify economics-dependent actions as `REVIEW`.
- Missing/unknown optimization score field: continue audit; report score as unavailable rather than fail the whole run.
- Empty data: distinguish truly empty account data from API/query failure.
- Any tracking-health uncertainty: block automated spend/bidding expansion.

## Testing Strategy

Tests must be deterministic and not require live Google Ads credentials.

Unit tests will cover:
- safe metric math and zero denominators;
- normalization of representative Google Ads API payloads;
- recommendation classification for known and unknown recommendation types;
- guardrails for budget, broad match, bidding, and negative keywords;
- tracking-health gate behavior;
- secret redaction/sanitization;
- partial-data failure behavior;
- generation of stable JSON report schema.

Workflow-level tests will validate YAML syntax and script invocation paths. A live read-only smoke test on VM2 will verify API compatibility and real account shape before any mutation workflow is enabled.

No test may perform a chargeable Google Ads mutation.

## Rollout

### Phase 1 — Read-only intelligence

Refactor the existing audit into reusable modules, collect full recommendation/optimization evidence, produce the sanitized decision report, and verify it against the live account.

### Phase 2 — Safe recommendations

Enable `APPLY` only for low-risk reversible recommendations that pass tests and live-read validation. Keep budget/bidding/broad-match changes gated unless explicit configured thresholds pass.

### Phase 3 — Controlled optimization loops

Use post-change monitoring to compare results. If evidence worsens, stop further scaling and produce a rollback/review recommendation. No uncontrolled recursive optimization loop is allowed.

## Repository Files Expected to Change

Likely implementation surface:
- `.github/workflows/google-ads-rest-audit.yml` — call reusable read-only audit script instead of maintaining large inline logic;
- `.github/workflows/google-ads-post-expansion-monitor.yml` — consume/compare standardized report data where useful;
- `scripts/google_ads/client.py` — OAuth and read-only GAQL transport;
- `scripts/google_ads/collector.py` — account/recommendation/performance datasets;
- `scripts/google_ads/metrics.py` — safe derived metrics/trends;
- `scripts/google_ads/policy.py` — recommendation classification and guardrails;
- `scripts/google_ads/report.py` — sanitized report schema/output;
- `scripts/google_ads/audit.py` — CLI orchestration;
- `tests/google_ads/` — deterministic unit tests;
- `ops/google-ads/` — sanitized report/config/decision artifacts, never secrets;
- `docs/AGENTS.md` — only if implementation discovers a non-obvious operational fact that future agents need.

Exact file paths and task boundaries will be finalized in the implementation plan after this spec is reviewed.

## Security and Operational Constraints

- Production target is VM2 (`136.248.69.116`), not VM1.
- Google Ads secrets remain only in environment/GitHub Secrets and must never enter the repository.
- Audit is read-only by default.
- No force-push or branch-protection bypass.
- No fabricated metrics.
- Any incomplete/blocked live validation must be reported as `INCONCLUSIVE`, not success.
- Implementation follows repository-required commit/PR/merge/check/deploy/smoke flow.

## Acceptance Criteria

The implementation is accepted only when:
- tests pass;
- the read-only workflow runs successfully against VM2;
- a sanitized report demonstrates the real recommendation inventory and score availability/unavailability without exposing secrets;
- at least one recommendation is demonstrably classified from real evidence rather than score alone;
- tracking uncertainty correctly blocks risky scaling;
- unknown recommendation types fail closed to `REVIEW`;
- zero/empty metrics do not produce invented CPA/ROAS values;
- no mutation occurs during Phase 1;
- repository checks, PR/merge flow, production deploy/smoke validation, and documentation requirements are satisfied before declaring completion.
