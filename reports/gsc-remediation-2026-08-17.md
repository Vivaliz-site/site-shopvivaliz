# Google Search Console remediation — 2026-08-17

Source: workflow `Google Search Console Audit`, run 32076813612.

## Sample audited

- 100 URLs inspected from Search Analytics fallback
- 52 clean
- 48 with one or more inspection flags
- 14 historical URLs currently reported as `NOT_FOUND`
- 6 canonical mismatches
- 3 URLs not yet recognized by Google

## Important findings

1. The production `robots.txt` allows crawling globally; the Search Console robots exclusions are therefore historical or route-specific, not a global robots rule.
2. The public sitemap is valid in a browser, but GitHub-hosted runners receive HTTP 403. The audit now correctly falls back to Search Analytics.
3. Search Console still has a legacy `https://www.shopvivaliz.com.br/sitemap.xml` submission with warnings/errors. The canonical sitemap is `https://shopvivaliz.com.br/sitemap.xml`.
4. Category URLs are split between `/catalogo?categoria=...` and `/catalogo/?categoria=...`, producing redirects/canonical mismatches.
5. Several product URLs resolve successfully but declare a different current product slug as canonical. These should redirect to the current canonical slug instead of returning 200 on the stale slug.
6. Many legacy `www` product/category URLs are valid historical exclusions and should not be converted to 200 pages.

## Remediation order

1. Normalize catalog category canonical URLs to `/catalogo/?categoria=...`.
2. Redirect resolved stale product slugs to their current canonical slug.
3. Keep genuine missing products as 404 + `noindex,follow`.
4. Do not weaken security 403 rules.
5. Remove the obsolete `www` sitemap submission in Search Console after confirming no legacy consumer depends on it.
6. Re-run URL Inspection audit and request validation only for issue classes changed by code.
