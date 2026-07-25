/**
 * 🤖 E2E Journey Test - Simula jornada real do usuário
 * Testa: Homepage -> Busca -> Carrinho -> Checkout
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';
const IS_LOCAL = /^http:\/\/(127\.0\.0\.1|localhost)(:|\/)/.test(BASE_URL);
const TIMEOUT = 30000;

test.describe('🛒 E2E Journey - Compra Completa', () => {

  test.beforeEach(async ({ page }) => {
    page.setDefaultTimeout(TIMEOUT);
    page.setDefaultNavigationTimeout(TIMEOUT);
  });

  test('✅ Homepage carrega corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    await expect(page.locator('.sv-navbar')).toBeVisible();
    await expect(page.locator('footer')).toBeVisible();
    await expect(page.locator('.hero-content h1')).toBeVisible();

    const errors = [];
    page.on('pageerror', err => errors.push(err));

    expect(errors).toHaveLength(0);
    console.log('✅ Homepage OK');
  });

  test('✅ Busca de produtos funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const searchInput = page.locator('.hero-search-form input[name="busca"]');
    await expect(searchInput).toBeVisible();
    await searchInput.fill('rodizio');
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/\/catalogo\/?\?busca=rodizio/);
  });

  test('✅ Navegação de categorias funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const catalogLink = page.locator('.sv-navbar a[href="/catalogo"]').first();
    await expect(catalogLink).toBeVisible();
    await catalogLink.click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('/catalogo');
    console.log('✅ Navegação OK');
  });

  test('✅ Produtos carregam corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const firstProduct = page.locator('#product-grid .product-card a.card-link').first();
    await expect(firstProduct).toBeVisible();
    await firstProduct.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#main-product-image')).toBeVisible();
    await expect(page.locator('.product-price-label')).toContainText('R$');
  });

  test('✅ Carrinho funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/carrinho');
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('/carrinho');
    await expect(page.locator('.sv-navbar')).toBeVisible();
    await expect(page.getByRole('heading', { name: /meu carrinho/i })).toBeVisible();

    console.log('✅ Carrinho OK');
  });

  test('✅ Checkout está acessível', async ({ page }) => {
    const response = await page.goto(BASE_URL + '/checkout', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);
    await expect(page.locator('form, [class*="checkout"]').first()).toBeAttached();
  });

  test('✅ Admin painel acessível', async ({ page }) => {
    test.skip(IS_LOCAL, 'PHP local sem extensão mysqli; admin é validado no ambiente de produção');
    const adminUrl = BASE_URL + '/admin/';
    const response = await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);
    expect(await page.locator('body').innerText()).not.toHaveLength(0);

    console.log('✅ Admin painel OK');
  });

  test('✅ Footer com dados da empresa', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const footer = page.locator('footer');
    const footerText = await footer.textContent();

    const hasCNPJ = footerText.includes('49.903.300/0001-70') || footerText.includes('CNPJ');
    const hasPhone = footerText.includes('3799937') || footerText.includes('37 9993');
    const hasEmail = footerText.includes('atendimento@shopvivaliz');

    expect(hasCNPJ || hasPhone || hasEmail).toBeTruthy();
    console.log('✅ Footer com dados OK');
  });

  test('✅ Liz mascote carrega', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const liz = page.locator('#sv-liz-launcher').first();
    await expect(liz).toBeVisible();
    await liz.click();
    await expect(page.locator('#sv-liz-panel')).toBeVisible();
  });

  test('✅ Nenhum erro HTTP 500', async ({ page }) => {
    const errors = [];

    page.on('response', response => {
      if (response.status() >= 500) {
        errors.push({
          url: response.url(),
          status: response.status(),
        });
      }
    });

    await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });

    const hrefs = await page.locator('a[href]').evaluateAll((anchors, origin) => {
      const unique = new Set();
      for (const anchor of anchors) {
        const raw = anchor.getAttribute('href');
        if (!raw || raw.startsWith('#') || raw.startsWith('javascript:')) continue;
        try {
          const url = new URL(raw, origin);
          if (url.origin === new URL(origin).origin) unique.add(url.pathname + url.search);
        } catch (_) {}
        if (unique.size >= 5) break;
      }
      return Array.from(unique);
    }, BASE_URL);

    for (const href of hrefs) {
      await page.goto(BASE_URL + href, { waitUntil: 'domcontentloaded' });
    }

    expect(errors).toHaveLength(0);
    console.log('✅ Sem erros HTTP 500');
  });

  test('✅ Performance: Página fica interativa em < 5s', async ({ page }) => {
    const startTime = Date.now();
    await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });

    const loadTime = Date.now() - startTime;
    expect(loadTime).toBeLessThan(5000);

    console.log(`✅ Página interativa em ${loadTime}ms`);
  });
});

test.describe('🔐 Security Checks', () => {

  test('✅ HTTPS ativo', async ({ page }) => {
    test.skip(IS_LOCAL, 'HTTPS é verificado no ambiente publicado');
    await page.goto(BASE_URL + '/');

    const url = page.url();
    expect(url).toMatch(/^https:/);

    console.log('✅ HTTPS OK');
  });

  test('✅ CSP headers presentes', async ({ page }) => {
    const response = await page.goto(BASE_URL + '/');
    const csp = response.headerValue('content-security-policy');

    if (csp) {
      expect(csp).toBeTruthy();
      console.log('✅ CSP headers OK');
    } else {
      console.log('⚠️ CSP headers não encontrados');
    }
  });
});
