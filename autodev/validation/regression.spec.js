// @ts-check
/**
 * AutoDev – Regression Test Suite
 *
 * Validates that critical site pages and features continue to work
 * correctly after each AutoDev evolution cycle.
 *
 * Run with: npx playwright test regression.spec.js
 */

const { test, expect } = require('@playwright/test');

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const CRITICAL_JS_ERROR_TYPES = ['TypeError', 'ReferenceError'];

// ---------------------------------------------------------------------------
// Selectors
// ---------------------------------------------------------------------------
const SEL = {
  navLinks:         'nav a, header a, .menu a, .navbar a',
  productCards:     '.product, .produto, [data-testid="product-card"], .card-produto, article.product',
  searchInput:      'input[type="search"], input[name="busca"], input[name="search"], input[placeholder*="Buscar"], input[placeholder*="Pesquisar"]',
  searchResults:    '.product, .produto, .search-result, [data-testid="search-result"], .resultado',
  productPrice:     '.price, .preco, [data-testid="price"], .valor, .produto-preco, span.price',
  addToCart:        '.add-to-cart, [data-action="add-to-cart"], button:has-text("Comprar"), button:has-text("Adicionar ao carrinho"), [data-testid="add-to-cart"]',
  firstProductLink: '.product a, .produto a, [data-testid="product-card"] a, .card-produto a, article.product a',
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Attach console and pageerror listeners; returns the mutable errors array. */
function collectErrors(page) {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });
  page.on('pageerror', (err) => {
    errors.push(err.message);
  });
  return errors;
}

/** Return the first path that serves product cards, or '/' as fallback. */
async function findProductListingUrl(page) {
  for (const path of ['/produtos', '/loja', '/catalogo', '/shop', '/']) {
    const res = await page.goto(path, { waitUntil: 'domcontentloaded' }).catch(() => null);
    if (res && res.status() < 400 && (await page.locator(SEL.productCards).count()) > 0) {
      return path;
    }
  }
  return '/';
}

// ---------------------------------------------------------------------------
// Tests – each is independent (fresh page provided by Playwright)
// ---------------------------------------------------------------------------

test.describe('Regression Suite', () => {

  test('homepage loads with products and navigation', async ({ page }) => {
    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });

    expect(response, 'Homepage should respond').not.toBeNull();
    expect(response.status(), `Homepage returned HTTP ${response.status()}`).toBeLessThan(400);

    // Navigation links
    const navCount = await page.locator(SEL.navLinks).count();
    expect(navCount, 'Expected at least one navigation link').toBeGreaterThan(0);

    // Products on page or link to a listing
    const productCount = await page.locator(SEL.productCards).count();
    if (productCount === 0) {
      const listingLinks = await page.locator('a[href*="produto"], a[href*="loja"], a[href*="catalogo"]').count();
      expect(listingLinks, 'Homepage should have products or links to product listings').toBeGreaterThan(0);
    }
  });

  test('search works and returns results', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const searchInput = page.locator(SEL.searchInput).first();

    if ((await searchInput.count()) > 0) {
      await expect(searchInput).toBeVisible({ timeout: 5_000 });
      await searchInput.fill('produto');
      await searchInput.press('Enter');
      await page.waitForLoadState('domcontentloaded');
    } else {
      // Try direct search URL variants
      for (const path of ['/busca?q=produto', '/search?q=produto', '/pesquisa?q=produto']) {
        const res = await page.goto(path, { waitUntil: 'domcontentloaded' }).catch(() => null);
        if (res && res.status() < 400) break;
      }
    }

    const resultCount = await page.locator(SEL.searchResults).count();
    const noResultsCount = await page.locator('text=Nenhum resultado, text=não encontrado, text=sem resultados').count();

    expect(
      resultCount > 0 || noResultsCount > 0,
      'Search page should display results or a no-results message'
    ).toBe(true);
  });

  test('product page loads with price and add-to-cart visible', async ({ page }) => {
    const listingUrl = await findProductListingUrl(page);
    const productLink = page.locator(SEL.firstProductLink).first();

    if ((await productLink.count()) === 0) {
      test.skip(true, `No product cards found on ${listingUrl}`);
      return;
    }

    await productLink.click();
    await page.waitForLoadState('domcontentloaded');

    // Price element must be visible
    await expect(page.locator(SEL.productPrice).first()).toBeVisible({ timeout: 10_000 });

    // Add-to-cart button must be visible
    await expect(page.locator(SEL.addToCart).first()).toBeVisible({ timeout: 10_000 });
  });

  test('cart page loads without server error', async ({ page }) => {
    const response = await page.goto('/carrinho', { waitUntil: 'domcontentloaded' });

    expect(response, 'Cart page should respond').not.toBeNull();
    expect(
      response.status(),
      `Cart page returned HTTP ${response.status()} (server error)`
    ).toBeLessThan(500);

    // Page must render visible text content
    const bodyText = await page.evaluate(() => (document.body?.innerText ?? '').trim());
    expect(bodyText.length, 'Cart page rendered no visible text (blank page)').toBeGreaterThan(0);
  });

  test('no JS errors on critical pages (TypeError / ReferenceError)', async ({ page }) => {
    const criticalPaths = ['/', '/produtos'];
    const allErrors = [];

    for (const path of criticalPaths) {
      const errors = collectErrors(page);

      // networkidle so deferred scripts finish; ignore timeout
      await page.goto(path, { waitUntil: 'networkidle' }).catch(() =>
        page.goto(path, { waitUntil: 'domcontentloaded' })
      );
      await page.waitForTimeout(1_500);

      for (const text of errors) {
        const isCritical = CRITICAL_JS_ERROR_TYPES.some((t) => text.includes(t));
        if (isCritical) allErrors.push({ page: path, text });
      }

      // Navigate away to reset listener scope for next path
      await page.goto('about:blank').catch(() => {});
    }

    if (allErrors.length > 0) {
      const summary = allErrors.map((e) => `[${e.page}] ${e.text}`).join('\n');
      expect.soft(allErrors.length, `Critical JS errors found:\n${summary}`).toBe(0);
    }
  });

});
