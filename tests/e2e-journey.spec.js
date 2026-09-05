/**
 * E2E Journey Test - jornada publica e regressões críticas da ShopVivaliz.
 * Testa: Homepage -> Busca -> Produto -> Carrinho -> Checkout -> Blog.
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';
const IS_LOCAL = /^http:\/\/(127\.0\.0\.1|localhost)(:|\/)/.test(BASE_URL);
const TIMEOUT = 30000;
const CART_PATH = IS_LOCAL ? '/carrinho.php' : '/carrinho';

test.describe('E2E Journey - Compra Completa', () => {

  test.beforeEach(async ({ page }) => {
    page.setDefaultTimeout(TIMEOUT);
    page.setDefaultNavigationTimeout(TIMEOUT);
  });

  test('Homepage carrega corretamente', async ({ page }) => {
    const errors = [];
    page.on('pageerror', err => errors.push(err));

    const response = await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);

    await expect(page.locator('.sv-navbar')).toBeVisible();
    await expect(page.locator('footer')).toBeVisible();
    await expect(page.locator('.hero-content h1')).toBeVisible();
    expect(errors).toHaveLength(0);
  });

  test('Homepage não exibe placeholders comerciais inválidos', async ({ page }) => {
    await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });
    const bodyText = await page.locator('body').innerText();

    expect(bodyText).not.toContain('Preço de pré-venda');
    expect(bodyText).not.toMatch(/\b0%\s*OFF\b/i);
    expect(bodyText).not.toMatch(/\bR\$\s*0,00\b/);
  });

  test('Busca de produtos funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const searchInput = page.locator('.hero-search-form input[name="q"], .hero-search-form input[name="busca"]');
    await expect(searchInput).toBeVisible();
    await searchInput.fill('rodizio');
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/\/catalogo\/?\?(?:q|busca)=rodizio/);
  });

  test('Navegação de categorias funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const catalogLink = page.locator('.sv-navbar a[href="/catalogo/"], .sv-navbar a[href="/catalogo"]').first();
    await expect(catalogLink).toBeVisible();
    await catalogLink.click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('/catalogo');
  });

  test('Produtos carregam corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const firstProduct = page.locator('#product-grid .product-card a.card-link').first();
    await expect(firstProduct).toBeVisible();
    await firstProduct.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#main-product-image')).toBeVisible();
    await expect(page.locator('.product-price-label')).toContainText('R$');
  });

  test('Carrinho funciona', async ({ page }) => {
    await page.goto(BASE_URL + CART_PATH);
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('/carrinho');
    await expect(page.locator('.sv-navbar')).toBeVisible();
    await expect(page.getByRole('heading', { name: /meu carrinho/i }).or(page.getByText(/seu carrinho .* vazio/i))).toBeVisible();
  });

  test('Checkout está acessível', async ({ page }) => {
    const response = await page.goto(BASE_URL + '/checkout', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);
    await expect(page.locator('form, [class*="checkout"]').first()).toBeAttached();
  });

  test('Admin painel acessível', async ({ page }) => {
    test.skip(IS_LOCAL, 'PHP local sem extensão mysqli; admin é validado no ambiente de produção');
    const response = await page.goto(BASE_URL + '/admin/', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);
    expect(await page.locator('body').innerText()).not.toHaveLength(0);
  });

  test('Footer contém dados da empresa', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const footerText = await page.locator('footer').textContent();
    const hasCNPJ = footerText.includes('49.903.300/0001-70') || footerText.includes('CNPJ');
    const hasPhone = footerText.includes('3799937') || footerText.includes('37 9993');
    const hasEmail = footerText.includes('atendimento@shopvivaliz');

    expect(hasCNPJ || hasPhone || hasEmail).toBeTruthy();
  });

  test('Liz abre e fecha corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const liz = page.locator('#sv-liz-launcher').first();
    await expect(liz).toBeVisible();
    await liz.click();
    await expect(page.locator('#sv-liz-panel')).toBeVisible();
    await expect(liz).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('#sv-liz-panel')).toBeHidden();
    await expect(liz).toHaveAttribute('aria-expanded', 'false');
  });

  test('Mobile: WhatsApp fica à esquerda e Liz à direita sem sobreposição', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(BASE_URL + '/', { waitUntil: 'networkidle' });

    const essentialOnly = page.getByRole('button', { name: /somente essenciais/i });
    const consentVisible = await essentialOnly.waitFor({ state: 'visible', timeout: 1500 }).then(() => true).catch(() => false);
    if (consentVisible) {
      await essentialOnly.click();
      await essentialOnly.waitFor({ state: 'detached', timeout: 3000 }).catch(async () => {
        await essentialOnly.waitFor({ state: 'hidden', timeout: 3000 });
      });
    }

    const liz = page.locator('#sv-liz-launcher').first();
    const whatsapp = page.locator('.sv-support-whatsapp').first();
    await expect(liz).toBeVisible();
    await expect(whatsapp).toBeVisible();
    await page.waitForFunction(() => {
      const lizEl = document.querySelector('#sv-liz-launcher');
      const whatsappEl = document.querySelector('.sv-support-whatsapp');
      if (!lizEl || !whatsappEl) return false;
      const key = [lizEl, whatsappEl].map(el => {
        const r = el.getBoundingClientRect();
        return [r.x, r.y, r.width, r.height].map(v => Math.round(v * 10) / 10).join(',');
      }).join('|');
      if (window.__svStableSupportGeometry === key) return true;
      window.__svStableSupportGeometry = key;
      return false;
    }, { polling: 150, timeout: 3000 });

    const lizBox = await liz.boundingBox();
    const whatsappBox = await whatsapp.boundingBox();
    expect(lizBox).not.toBeNull();
    expect(whatsappBox).not.toBeNull();

    const viewportWidth = page.viewportSize().width;
    const horizontalGap = lizBox.x - (whatsappBox.x + whatsappBox.width);
    const lizBottom = lizBox.y + lizBox.height;
    const whatsappBottom = whatsappBox.y + whatsappBox.height;

    expect(whatsappBox.x).toBeLessThan(viewportWidth / 2);
    expect(lizBox.x + lizBox.width).toBeGreaterThan(viewportWidth / 2);
    expect(horizontalGap).toBeGreaterThanOrEqual(12);
    expect(Math.abs(lizBottom - whatsappBottom)).toBeLessThanOrEqual(12);

    await liz.click();
    await expect(page.locator('#sv-liz-panel')).toBeVisible();
    await expect(whatsapp).toBeHidden();
  });

  test('Blog possui conteúdo, canonical e UTF-8 válido', async ({ page }) => {
    const response = await page.goto(BASE_URL + '/blog/', { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(500);

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/blog\/?$/);
    await expect(page.locator('script[type="application/ld+json"]').first()).toBeAttached();

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(/InÃ|NavegaÃ|PolÃ|CondiÃ|Â©|â€“|â€”|â€™|�/);
    await expect(page.locator('.knowledge-card, .knowledge-panel').first()).toBeVisible();
  });

  test('Nenhum erro HTTP 500 nas principais rotas', async ({ page }) => {
    const errors = [];

    page.on('response', response => {
      if (response.status() >= 500) {
        errors.push({ url: response.url(), status: response.status() });
      }
    });

    const routes = ['/', '/catalogo', CART_PATH, '/checkout', '/blog/', '/contato', '/faq'];
    for (const route of routes) {
      await page.goto(BASE_URL + route, { waitUntil: 'domcontentloaded' });
    }

    expect(errors).toHaveLength(0);
  });

  test('Performance: página fica interativa em menos de 5 segundos', async ({ page }) => {
    const startTime = Date.now();
    await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });
    expect(Date.now() - startTime).toBeLessThan(5000);
  });
});

test.describe('Security Checks', () => {

  test('HTTPS ativo', async ({ page }) => {
    test.skip(IS_LOCAL, 'HTTPS é verificado no ambiente publicado');
    await page.goto(BASE_URL + '/');
    expect(page.url()).toMatch(/^https:/);
  });

  test('Headers essenciais presentes', async ({ page }) => {
    const response = await page.goto(BASE_URL + '/');
    expect(await response.headerValue('x-content-type-options')).toBe('nosniff');
    expect(await response.headerValue('x-frame-options')).toBeTruthy();
    expect(await response.headerValue('referrer-policy')).toBeTruthy();
  });

  test('Arquivos de backup não são expostos', async ({ request }) => {
    const response = await request.get(BASE_URL + '/checkout.php.bak', { failOnStatusCode: false });
    expect([403, 404, 410]).toContain(response.status());
  });
});
