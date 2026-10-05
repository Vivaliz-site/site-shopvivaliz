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

## Final review and validation ledger
- Full Python suite before review hardening: 944 passed, 17 skipped, 217 subtests passed (task 6edc8157-b72e-456b-be49-c863e4c1a1c7). PHP lint and both tracking regressions passed.
- Independent compact review completed in task 13e49d90-0ef8-4433-828d-8b98071ce566: no Critical defect, GTM change merge-acceptable; raised test-boundary and CI coverage findings.
- Review fix pass: five new regressions first failed; the positive Bastion fixture passed. After the fix, all 18 focused tests passed (task edb33fe9-9d83-4583-8a04-bf9dd92854f0).
- Fixes reject comment-only evidence, conditional cleanup, restoration from the wrong CIDR source, unapproved triggers and extra source paths. Both bootstrap variants have positive and negative cases. Shopee tests forbid restoration of obsolete env-writer APIs. CI now covers the runtime contract inputs and executes the full Python suite.
- Ruling: localhost was already in PRIVATE_TARGETS, and provision author/issue guards were retained. The review did not have that context; retain these controls and additionally verify the exact dispatcher route and full deploy SHA expressions.
- Deferred minor: retain require_once through analytics-tracking.php (already the mandatory include); avoid unnecessary changes to production rendering. Test-class naming and JSON-format assertions are not functional defects in this patch.
- A second full VM run became slow, reported one failure without a completed summary, and expired at 240 seconds (task 699805be-e44b-4d5e-bc6a-2d087bd5a8e5). It is INCONCLUSIVE, not a passing result. Complete the final validation on the isolated PR CI runner and resolve any reported failure before merge.
- The PR branch was advanced by the repository automation through a normal main merge (1b201b866). Local fast-forward preserved all review changes. No force push or protected-setting change.
