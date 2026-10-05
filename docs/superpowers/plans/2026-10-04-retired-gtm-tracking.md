# Retired GTM recovery implementation plan

> Execute inline with Superpowers TDD, review, and verification-before-completion.

**Goal:** Reject retired GTM from explicit stale configuration while preserving official GA4.
**Architecture:** Shared PHP resolver used by the analytics renderer and head deduplication.
**Spec:** docs/audits/2026-10-03-google-ads-zero-sales-v5.md plus production reproduction 2026-10-04.

## Constraints
Immutable releases; no credential changes; preserve consent, valid replacement GTM, prices, and advertising budgets. No synthetic purchases.

## Task 1
- [ ] Add behavioral regressions to tests/google-ads-v5-regression-test.php and observe RED.
- [ ] Implement includes/analytics-gtm-policy.php and use it in analytics-tracking.php and head-analytics.php.
- [ ] Add dedicated CI, run lint and regression tests, then review, commit, PR and permitted merge.
- [ ] Validate the immutable production release and public tracking output.

## Review focus
All three aliases; malformed IDs; primary-key precedence; preserved direct GA4; valid explicit replacement support.

## Evidence and limits
The current production environment still enables the retired GTM. Analytics Admin API was disabled and was enabled successfully; existing canonical-stream secret confirmed by metadata only. GA4_SECRET installation was blocked by platform security and was not executed; do not circumvent that block. GEPETO_UNAVAILABLE after exact plugin search. Tracking repair does not prove recovered sales.

Ruling: the full-suite scope test asserted an obsolete push-time workflow removed by the deployed event-driven architecture. Align only the test with the existing verified production-deploy-event-gate; retain scheduled/manual gating and deployed-SHA assertions. No deployment gate or workflow is weakened.

## Resume validation decisions
The complete Python suite executed on 2026-10-05 UTC: 931 passed, 10 failed, 17 skipped. Failures were investigated rather than skipped.
- Native-profile tests still modeled a paid exec probe; align fixtures with the existing login/status probe, without changing runtime.
- The collaboration executor is retired; test the active read-only Ads environment parser and explicitly keep the old entrypoint blocked.
- Shopee persists only a canonical JSON cache; test that behavior and that shared environment bytes remain unchanged, instead of restoring an obsolete environment writer.
- Google rejects group-writable files; use a 0640 positive fixture and retain an explicit 0664 rejection test.
- Provisioning uses the single issue-comment dispatcher; assert its current exact route and bounded readiness loop, not an obsolete periodic job.
- Distinguish narrowly source-triggered bootstrap and verified private Bastion transport from normal runtime transport. Keep negative tests for unknown, broad and periodic bootstrap triggers, plain hosted SSH, and missing Bastion controls. No infrastructure workflow is modified or executed.
These are isolated validation repairs, not credential changes. The earlier GA4_SECRET installation refusal remains in force. Full validation and independent review must still pass before integration.
