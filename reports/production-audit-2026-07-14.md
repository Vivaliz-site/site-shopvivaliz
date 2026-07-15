# Production audit — 2026-07-14

## Scope

End-to-end catalog, storefront, checkout, security, deployment and developer-tooling audit for cumulative release 9.2.103. No prices, campaigns, budgets or financial actions were changed. Checkout verification stopped before any charge, boleto or order creation.

## Completed work

- Replaced the incomplete summary-only product synchronization with paginated detail synchronization, retry/backoff, atomic writes and partial-failure preservation.
- Stopped duplicate local legacy synchronizers that were overwriting the detailed cache.
- Centralized catalog reads for the storefront, cart, checkout, shipping, health endpoints, feed and sitemap.
- Added CSRF protection to authentication, checkout and sensitive administration forms.
- Removed deterministic signing-key fallbacks from quote and order validation.
- Repaired the repository security scanner and sensitive-data validator false positives.
- Hardened Python and Playwright collection/assertions so external scripts and HTTP failures cannot appear as successful tests.
- Installed resident systemd services for detailed catalog synchronization and atomic Olist OAuth renewal. The first production renewal completed successfully without logging token values.
- Fixed the Windows MCP bridge encoding boundary. Before the fix Codex timed out after 30 seconds while waiting for `tools/list`; after forcing UTF-8, the same required bridge initialized and answered in about 6 seconds.
- Merged cumulative pull requests #285 through #289 with required checks green and deployed verified `main` commits to the Oracle VM.
- Disabled the obsolete, permanently restarting MCP unit on the Linux web server; it is unrelated to the working VS Code stdio bridge.

## Critical production perimeter findings remediated

- Direct HTTP reads of `.git`, environment files, order storage, catalog caches, task queues, local agent settings, scripts, tests and deployment metadata returned 200 before remediation. They now return 403.
- Legacy web diagnostics exposed partial OAuth values and database/runtime details; unauthenticated sync/setup scripts could mutate files or data. Their HTTP routes, including the legacy `/olist` utilities, now return 403 while command-line automation remains available.
- Apache directory indexing exposed 34 repository directories. `Options -Indexes` now prevents those listings while public pages and static assets remain available.
- Added HSTS, nosniff, referrer, permissions, frame and minimal CSP protection. PHP session cookies observed on the admin redirect carry `Secure`, `HttpOnly` and `SameSite=Lax`.
- Removed internal cache flags and per-request debug logging from the public catalog response.
- Replaced two syntactically invalid scheduled workflows. The production E2E workflow is now explicitly non-financial; the VM configuration workflow sends only whitelisted static values over stdin and updates `.env` atomically without overwriting rotating OAuth tokens.
- Replaced an obsolete Olist web-scraping image job (login endpoint returned 404) with a canonical API coverage audit, and replaced a Shopee job that called nonexistent/simulated upload files with a non-mutating prerequisite preflight.
- Updated all workflow checkout/setup actions to their current Node 24-compatible major releases after GitHub began warning that Node 20 actions were being force-upgraded at runtime.

## Local verification

- PHP lint: PASS.
- Quality suite: PASS (185 valid prices, 155 products in stock, 183 real images across 185 products).
- Security scan: PASS (0 critical, 0 high, 0 secrets, 0 dependency findings).
- Python: PASS (expanded hardening/unit suite; integration-only cases remain explicitly skipped when their prerequisites are absent).
- Production Playwright: PASS, 38 passed and 3 intentionally skipped, 0 failed across 41 tests.
- Master production pipeline run 29313072306: PASS, including validation, deploy SHA verification and all live health monitors.
- Production runtime: Apache, agent, repository sync, product sync and token renewer services active; Apache configuration syntax valid; no failed systemd units after cleanup.
- Catalog health: 185 storefront products, 185 valid prices, 155 available, 183 real images (98.92%). Installer self-test reported `100% OK`.
- Public/private route audit: required storefront/API/sitemap/robots/service-worker routes return 200 or expected canonical redirects; sampled sensitive and maintenance routes return 403.

## Risks and follow-up

- Two active ERP products have no real product image and use the storefront fallback; therefore image coverage is 98.92%, not 100%.
- The Windows PHP installation lacks curl and mysqli. Production runtime verification is required and recorded separately after deployment.
- The separate commercial VNDA/Olist storefront at `www.shopvivaliz.com.br` is not served by this repository/VM. Its homepage crawl found 58 category-navigation links returning 404 (9 links returned 200). Correcting that menu requires authenticated VNDA/Olist storefront administration, which was not available in this workspace and was not guessed or mutated.
- Shopee Media Space bulk repair remains intentionally gated because `mapeamento_olist_ambientadas.xlsx` and `imagens_ambientadas/` are absent. The scheduled preflight reports this as an expected warning and performs no simulated or real upload.
- VS Code must reload its window or start a new Codex task to consume the merged MCP bridge file; the underlying required server was independently proven healthy after the fix.
- Database migrations: none; release 9.2.103 is file, service, workflow and web-server configuration only and is idempotent.
