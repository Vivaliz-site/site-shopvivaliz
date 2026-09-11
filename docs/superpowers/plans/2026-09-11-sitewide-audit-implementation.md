# ShopVivaliz Sitewide Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the live ecommerce audit from representative-page checks to a bounded same-origin sitemap crawl, then fix every actionable blocker and high-impact warning found in production.

**Architecture:** Reuse `scripts/ecommerce-excellence-audit.py` as the canonical engine. Split page inventory, per-page SEO parsing and cross-page duplicate analysis into focused functions that are unit-testable with fixtures; preserve current critical endpoint, robots, sitemap and Merchant feed checks. After merge/deploy, run the live audit against production and fix findings from the source rather than suppressing them.

**Tech Stack:** Python 3 standard library, PHP 8.3 site runtime, existing GitHub Actions ecommerce audit and Quality Gate, real-browser relay for UI validation.

**Spec:** `docs/superpowers/specs/2026-09-11-blog-sitewide-quality-design.md`

## Global Constraints

- Crawl only same-origin URLs discovered from the canonical sitemap plus the existing critical endpoints.
- Bound requests with timeout and de-duplicate normalized URLs; never recursively crawl external sites.
- Do not weaken existing blocker rules to make the audit pass.
- Do not execute checkout payment, order creation, email sending or destructive actions during audit.
- Fix root causes in source code; classify genuinely external conditions as INCONCLUSIVO with evidence.
- UI findings require real-browser desktop/mobile validation after deploy.

---

### Task 1: Sitemap crawl regression tests

**Files:**
- Create: `tests/test_ecommerce_excellence_sitewide.py`
- Modify: `.github/workflows/quality-gate.yml`

**Interfaces:**
- Consumes planned functions `sitemap_inventory(base_url: str, sitemap_body: bytes) -> list[str]`, `audit_sitewide_pages(base_url: str, urls: list[str]) -> tuple[list[dict[str, Any]], list[Finding]]`, `cross_page_findings(pages: list[dict[str, Any]]) -> list[Finding]`.

- [ ] **Step 1: Create fixtures in the test itself using local HTTP-independent byte strings**

Use a sitemap fixture containing duplicate, external, fragment/query and same-origin canonical URLs. Assert that inventory keeps only normalized same-origin HTTP(S) URLs, removes fragments/duplicates, and retains intentional query URLs for reporting rather than silently dropping them.

- [ ] **Step 2: Add duplicate metadata fixtures**

Create parsed page dictionaries for two distinct indexable paths with the same title and description and assert `duplicate_title` and `duplicate_meta_description` warnings identify both paths.

- [ ] **Step 3: Add canonical mismatch and visible quality fixtures**

Assert a sitemap page whose canonical points at a different same-origin URL produces `canonical_mismatch`; body text containing replacement characters/mojibake markers produces `visible_encoding_issue`; a blog article below the editorial text threshold produces `thin_editorial_content` warning.

- [ ] **Step 4: Run test and capture RED**

Run `python3 tests/test_ecommerce_excellence_sitewide.py`; expected failure is missing new helper functions.

- [ ] **Step 5: Register the test in Quality Gate**

Add it beside the existing ecommerce excellence Python tests.

- [ ] **Step 6: Commit**

Commit with `test(audit): define sitewide sitemap crawl contract`.

---

### Task 2: Refactor page parsing into reusable structured results

**Files:**
- Modify: `scripts/ecommerce-excellence-audit.py`
- Test: `tests/test_ecommerce_excellence_sitewide.py`

**Interfaces:**
- Produces `PageAudit`-equivalent dictionaries with keys `url`, `path`, `status`, `final_url`, `title`, `description`, `canonical`, `robots`, `h1`, `body_text_length`, `is_indexable`.
- Keeps `validate_page(url, body, headers, findings)` compatible for existing callers or replaces it only with a wrapper preserving behavior.

- [ ] **Step 1: Add body-text collection to `HeadParser` or a dedicated parser**

Ignore script/style data for visible-text metrics. Normalize whitespace before calculating length and encoding markers.

- [ ] **Step 2: Extract canonical parsing into one helper**

Resolve relative canonical links against the final URL. Compare canonical after removing fragments and normalizing default ports/trailing root form; do not collapse meaningful path slashes indiscriminately.

- [ ] **Step 3: Return structured page metadata while retaining current findings**

Existing missing title/description/canonical/H1/OG/JSON-LD/security checks must continue to fire exactly as before.

- [ ] **Step 4: Add visible encoding check**

Flag replacement character `�`, common UTF-8/Latin-1 mojibake sequences (`Ã`, `Â`) only when present in visible normalized text, not scripts or source comments.

- [ ] **Step 5: Add thin editorial heuristic**

For paths under `/blog/` excluding `/blog/` root, warn when visible main-page text is below 900 normalized characters. Do not classify product/cart/checkout pages with this heuristic.

- [ ] **Step 6: Run existing and new tests GREEN**

Run `python3 tests/test_ecommerce_excellence_credential_literals.py`, `python3 tests/test_ecommerce_excellence_reference_resolution.py`, and `python3 tests/test_ecommerce_excellence_sitewide.py`.

- [ ] **Step 7: Commit**

Commit with `refactor(audit): expose structured live page results`.

---

### Task 3: Same-origin sitemap inventory and all-page execution

**Files:**
- Modify: `scripts/ecommerce-excellence-audit.py`
- Test: `tests/test_ecommerce_excellence_sitewide.py`

**Interfaces:**
- Produces: `sitemap_inventory(base_url, sitemap_body)` and `audit_sitewide_pages(base_url, urls)`.
- `validate_live()` output adds `sitewide_pages`, `sitewide_checked`, and cross-page findings while preserving `checked`, `sitemap_urls`, and `merchant_items`.

- [ ] **Step 1: Implement normalized sitemap inventory**

Parse `<loc>` values, resolve only URLs whose lowercase hostname equals the base hostname, accept `http`/`https`, strip fragments, preserve query strings, and stable de-duplicate in sitemap order.

- [ ] **Step 2: Reuse already-checked critical endpoint results**

Build a cache keyed by normalized final/request URL so `/`, `/catalogo`, `/blog`, etc. are not fetched twice if present in the sitemap.

- [ ] **Step 3: Audit every sitemap URL with bounded request behavior**

Use existing `fetch()` timeout. Record status/final URL/bytes for every URL. A non-200 sitemap URL is blocker `sitemap_page_http_status`; an external final origin is blocker `unexpected_external_redirect`.

- [ ] **Step 4: Validate canonical equality for sitemap URLs**

For indexable 200 pages, canonical must resolve to the normalized final URL. Emit `canonical_mismatch` blocker with path and target.

- [ ] **Step 5: Aggregate title/description duplicates**

Across indexable 200 pages, titles/descriptions repeated on more than one distinct canonical URL produce warnings with a bounded sample of affected paths. Ignore empty values because existing missing-field checks already cover them.

- [ ] **Step 6: Keep sitemap structural checks and Merchant/robots checks intact**

Do not replace or weaken `sitemap_duplicates`, `sitemap_query_urls`, feed format or security-header checks.

- [ ] **Step 7: Make reports useful at site scale**

Add counts for sitewide pages and include each finding once. JSON remains machine-readable; Markdown keeps stable `severity/code/path/message` format.

- [ ] **Step 8: Run tests GREEN and compile**

Run `python3 -m py_compile scripts/ecommerce-excellence-audit.py` and all three ecommerce audit tests.

- [ ] **Step 9: Commit**

Commit with `feat(audit): validate every sitemap page`.

---

### Task 4: Optional bounded internal-link integrity from each page

**Files:**
- Modify: `scripts/ecommerce-excellence-audit.py`
- Modify: `tests/test_ecommerce_excellence_sitewide.py`

**Interfaces:**
- Produces internal-link extraction limited to same-origin navigational anchors and a global de-duplicated target set.

- [ ] **Step 1: Add anchor extraction to parser**

Collect only `href` values from `<a>` tags. Ignore `mailto:`, `tel:`, `javascript:`, fragments-only links, admin/auth transactional actions, and external origins.

- [ ] **Step 2: Limit link verification globally**

De-duplicate targets and verify each unique internal URL at most once. Do not submit forms. GET only. Reuse fetch cache. This makes cost proportional to unique public navigation targets, not `pages × links`.

- [ ] **Step 3: Classify broken internal links**

4xx/5xx target referenced by an indexable sitemap page is `broken_internal_link` warning unless the target itself is in sitemap, where the existing sitemap HTTP blocker already applies.

- [ ] **Step 4: Test bounded de-duplication**

Provide two page fixtures linking to the same target and assert the target is represented once in the extracted verification set.

- [ ] **Step 5: Run tests and commit**

Commit with `feat(audit): check bounded public internal links`.

---

### Task 5: Execute pre-deploy branch quality gates

**Files:**
- No new files unless a test exposes a defect.

- [ ] **Step 1:** Run Python compile and all ecommerce audit unit tests.
- [ ] **Step 2:** Run `php scripts/quality/run-all.php`.
- [ ] **Step 3:** Run repository governance validation.
- [ ] **Step 4:** Run static ecommerce audit with `--fail-on blocker`.
- [ ] **Step 5:** Review diff for test weakening, ignored errors or scope creep.
- [ ] **Step 6:** Correct any failure at root cause and repeat until green.

---

### Task 6: PR, merge, deploy and production crawl

**Files:**
- Runtime corrections may touch only files directly supported by audit findings.
- Create/update a dated audit report under `reports/` only after live production evidence exists.

- [ ] **Step 1: Open one PR for the complete controlled branch**

PR body must summarize blog root cause, editorial repair safety, UI redesign, sitewide audit coverage, tests and rollout/rollback.

- [ ] **Step 2: Wait for required checks and fix failures**

No bypasses. Repeat until required checks are successful.

- [ ] **Step 3: Merge and verify resulting main SHA**

Confirm no task PR remains open.

- [ ] **Step 4: Follow the canonical immutable-release deployment**

Observe Master Production Pipeline until the merged SHA is the active production release. Do not edit the active release.

- [ ] **Step 5: Run editorial DB repair safely**

First run dry-run. Create backup in approved non-public shared storage. Apply once, read back article counts/content signatures, then run apply a second time and require zero changed rows to prove idempotency.

- [ ] **Step 6: Execute the new live audit against `https://shopvivaliz.com.br`**

Require all sitemap URLs to be visited and capture counts/findings. Do not accept representative-only output.

- [ ] **Step 7: Fix every actionable blocker and high-impact warning**

For each source-code fix, repeat TDD/checks/PR/merge/deploy as needed. Never suppress a finding just to reach green.

- [ ] **Step 8: Re-run production audit**

Require zero actionable blockers; warnings may remain only when documented with evidence and rationale.

- [ ] **Step 9: Real-browser UI validation**

Use the authorized browser path/relay for desktop and mobile screenshots of `/blog/`, one repaired article, home, catalog, representative product, cart and checkout non-destructive states. Confirm no horizontal overflow, broken images, duplicate navigation or UI collisions.

- [ ] **Step 10: Final evidence record**

Record base SHA, merged SHA, active release, number of sitemap URLs audited, blockers/warnings after fixes, blog repair changed/unchanged counts, Quality Gate result, production audit result and browser-smoke evidence.
