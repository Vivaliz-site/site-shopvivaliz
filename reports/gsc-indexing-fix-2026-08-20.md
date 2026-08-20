# GSC indexing remediation - 2026-08-20

Context: Google Search Console showed large groups of non-indexed pages: not found, noindex, redirects and canonical alternates.

This remediation focuses on code paths that can keep generating new instances of those classes:

- Product slug routing now redirects proven historical aliases to the current canonical product slug.
- Unknown product slugs no longer fall through to `produto.php` where legacy lookup could serve a 200 page with a different canonical URL.
- The product sitemap now includes only indexable product detail URLs with current slug, name, positive price, positive stock and safe HTTPS image.
- HTTP or protocol-relative product images are excluded from sitemap image entries.

Operational follow-up:

1. Deploy the merged code.
2. Confirm `https://shopvivaliz.com.br/sitemap.xml` returns only final indexable URLs.
3. In Search Console, keep only the canonical non-www sitemap submission.
4. Start validation for the changed issue classes after Google recrawls the sitemap.
