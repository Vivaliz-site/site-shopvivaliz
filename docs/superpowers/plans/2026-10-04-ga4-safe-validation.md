# GA4 safe configuration validation

> Execute inline with Superpowers TDD and independent review.

**Goal:** Make the existing configuration diagnostic read-only and incapable of manufacturing purchase events.
**Architecture:** Keep the CLI entrypoint and environment bootstrap; inspect configuration and required files only. Do not include the transaction sender. Distinguish structural configuration from credential validity and actual delivery.
**Tech Stack:** PHP 8.2+, Python unittest/pytest, existing tracking CI.
**Spec:** The observed validate-tracking-config.php invokes sendPurchaseEventGA4 with a fictitious order. This contradicts the no-synthetic-production-purchase policy and the Google validation contract.

## Global constraints
No secret provisioning or transport change in this patch. Preserve the platform refusal on GA4_SECRET installation. Do not create credentials, payments, orders, campaigns or background jobs. Use immutable deployment and merge only after validation.

## Review focus
Configured credentials must not trigger network activity; missing/placeholder values must fail; no credential value in output; existing aliases remain supported; PASS must mean structural configuration only.

## Task 1
- [ ] Add isolated subprocess tests and observe failure in the transaction-sender guard.
- [ ] Remove the unsafe event sender and make configuration errors fail closed, without invented secret-length requirements.
- [ ] Add regression to the existing full-suite CI paths and document the exact remaining configuration operation.
- [ ] Run focused tests, full Python suite, PHP lint, independent review, then PR/checks/merge and validate deployed diagnostic output.

## Evidence and boundaries
Google OAuth and metadata access already passed. On this turn, production still reports ga4_server_secret_configured=false. The available Remote Control surface has generic commands but no write-only secret-reference provisioning action. The blocked secret installation is not being rerouted through SSH, another agent or a new endpoint. GEPETO_UNAVAILABLE as previously established.

## Official references
https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events
https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties.dataStreams.measurementProtocolSecrets/list

## Execution ledger
- Existing tracking baselines passed before edits.
- RED: the isolated CLI test observed TRANSACTION_SENDER_CALLED (audit 2d92285f-ef6d-4eee-bda9-c5c2eeed9c3b). No request was sent; the sender was a fixture trap.
- GREEN: all 6 subprocess tests passed, including missing/placeholder values, invalid IDs, alias support, missing files, non-disclosure and unchanged fixture files (audit 15320442-bdb8-49d9-a010-4c4af84ff533).
- Official Google validation: captured the current purchase payload from the real PHP builder with cURL replaced by a local capture. Sent only the fixture body to /debug/mp/collect with a nonsecret dummy value. HTTP 200, validationMessages=[], no real credentials, no recorded events (audit d422d42b-5e37-424b-8271-5269e9ad9642). This does NOT prove authentication, ingestion or sale attribution.
- Full Python suite: task fe11e7e2-492c-47e7-9c6a-ddb2dc14e1f4, result must be checked before merge.
- Independent read-only review: task b2ccaee8-6a28-495d-9564-7e1627aae19d, result must be checked before merge.
- Ruling: this patch removes unsafe validation only. It cannot and does not override the earlier platform refusal to configure GA4_SECRET. That operation remains separate and unexecuted.

## Independent review and fix pass
- Full suite before review: 985 passed, 17 skipped, 237 subtests passed in 107.15s.
- Independent review completed with no Critical defect. It requested verification of alias order, bootstrap fixtures and a precise network-proof claim.
- A new test reproduced a real alias mismatch: whitespace in GA4_ID was incorrectly bypassed by the validator. Fixed selection to match AnalyticsTracking before trimming; this is a RED-to-GREEN correctness fix.
- Added placeholder-precedence, final legacy alias, dotenv and runtime-array fixture coverage; disabled socket/cURL entrypoints in the isolated tests.
- Replaced the hardcoded network-counter label with an explicit design policy and documented trust in the canonical configuration bootstrap.
- Ruling: generic placeholder dictionaries, output-label rename, missing optional CI path and extra invalid-value logging cases are minor follow-ups, not delivery blockers. No secret provisioning or transaction behavior changes were introduced.
