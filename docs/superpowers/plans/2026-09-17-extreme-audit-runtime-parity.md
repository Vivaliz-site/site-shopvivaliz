# Extreme Audit Runtime Parity Action Plan

**Date:** 2026-09-17
**Scope:** ShopVivaliz production, repository governance, storefront stateful flow, integrations and operational capacity.

## Execution plan

1. Rebuild the canonical topology from `docs/knowledge/host-access.md`, `README.md`, `agent-rules.md` and live hosts.
2. Reconcile `origin/main`, active release marker and public version endpoint before trusting production evidence.
3. Run the canonical production functional audit and require `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
4. Exercise the critical non-financial UI path on the served release: home -> product -> cart -> reload -> real shipping -> checkout -> reload.
5. Treat unexpected browser `console.error`, `requestfailed`, `pageerror` or HTTP 5xx as stop-the-line findings.
6. Correct only SAFE findings automatically; use PR/checks and immutable release deploy, never edit `current/`.
7. Re-run local regression, GitHub checks, deploy provenance, functional audit and runtime parity after publication.
8. Inspect both production hosts for failed services, resource saturation and log errors; record residual operational risks.
9. Update `docs/quality/AUDIT_STATUS.md` with the final code/release SHA and remaining evidence debt.

## Findings being closed in this execution

- Melhor Envio functional failure: recovered and canonical functional audit returned PASS.
- Stateful runtime parity blind spot: permanent browser gate added to the post-deploy public audit.
- Mercado Pago checkout frame blocked by CSP: add the exact required `www.mercadolibre.com` frame origin to enforced and report-only policies.
- E2E cart false negative from Playwright strict-mode `.or()`: use a deterministic heading assertion.
