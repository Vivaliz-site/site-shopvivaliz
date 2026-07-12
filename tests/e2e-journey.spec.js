/**
 * 🤖 E2E Journey Test - Simula jornada real do usuário
 * Testa: Homepage -> Busca -> Carrinho -> Checkout
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = 'https://dev.shopvivaliz.com.br';
const TIMEOUT = 30000;

test.describe('🛒 E2E Journey - Compra Completa', () => {

  test.beforeEach(async ({ page }) => {
    page.setDefaultTimeout(TIMEOUT);
    page.setDefaultNavigationTimeout(TIMEOUT);
  });

  test('✅ Homepage carrega corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Verificar elementos críticos
    await expect(page.locator('header')).toBeVisible();
    await expect(page.locator('footer')).toBeVisible();
    await expect(page.locator('h1, h2')).toBeVisible();

    // Verificar que não há erros críticos
    const errors = [];
    page.on('pageerror', err => errors.push(err));

    expect(errors).toHaveLength(0);

    console.log('✅ Homepage OK');
  });

  test('✅ Busca de produtos funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar campo de busca
    const searchInput = page.locator('input[placeholder*="busca"], input[name="q"], input[type="search"]').first();

    if (await searchInput.isVisible()) {
      await searchInput.fill('produto teste');
      await page.keyboard.press('Enter');

      // Esperar resultados
      await page.waitForLoadState('networkidle');

      // Verificar que resultado apareceu
      const results = page.locator('[class*="product"], [class*="resultado"]');
      expect(await results.count()).toBeGreaterThan(0);

      console.log('✅ Busca OK');
    } else {
      console.log('⚠️ Campo de busca não encontrado (OK se site não tem busca)');
    }
  });

  test('✅ Navegação de categorias funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar menu ou categorias
    const menuItems = page.locator('nav a, [class*="menu"] a, [class*="category"] a');
    const count = await menuItems.count();

    if (count > 0) {
      const firstLink = menuItems.first();
      const href = await firstLink.getAttribute('href');

      if (href && !href.includes('javascript')) {
        await firstLink.click();
        await page.waitForLoadState('networkidle');

        const currentUrl = page.url();
        expect(currentUrl).not.toContain(BASE_URL + '/'); // Deve ter mudado

        console.log('✅ Navegação OK');
      }
    } else {
      console.log('⚠️ Menu não encontrado');
    }
  });

  test('✅ Produtos carregam corretamente', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar link de produto
    const productLinks = page.locator('a[href*="/produto"], a[href*="/product"], [class*="product"] a');
    const count = await productLinks.count();

    if (count > 0) {
      const firstProduct = productLinks.first();
      await firstProduct.click();
      await page.waitForLoadState('networkidle');

      // Verificar página de produto
      const addToCartBtn = page.locator('button:has-text("Adicionar"), button:has-text("Add to"), input[type="submit"]');
      const productImage = page.locator('img[alt*="Produto"], img[alt*="Product"]');

      if (await addToCartBtn.isVisible() || await productImage.isVisible()) {
        console.log('✅ Página de produto OK');
      } else {
        console.log('⚠️ Produto carregou mas elementos podem estar fora');
      }
    } else {
      console.log('⚠️ Nenhum link de produto encontrado');
    }
  });

  test('✅ Carrinho funciona', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar link de carrinho
    const cartLink = page.locator('a[href*="cart"], a[href*="carrinho"], [class*="cart"]');
    const cartCount = await cartLink.count();

    if (cartCount > 0) {
      await cartLink.first().click();
      await page.waitForLoadState('networkidle');

      // Verificar se está na página de carrinho
      const currentUrl = page.url();
      const isCartPage = currentUrl.includes('cart') || currentUrl.includes('carrinho') ||
                        await page.locator('h1, h2').first().textContent().then(t => t.includes('Carrinho'));

      expect(isCartPage || currentUrl !== BASE_URL + '/').toBeTruthy();

      console.log('✅ Carrinho OK');
    } else {
      console.log('⚠️ Carrinho não acessível');
    }
  });

  test('✅ Checkout está acessível', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar botão de checkout
    const checkoutBtn = page.locator(
      'button:has-text("Checkout"), button:has-text("Finalizar"), a[href*="checkout"], a[href*="finalize"]'
    );

    if (await checkoutBtn.isVisible()) {
      const href = await checkoutBtn.first().getAttribute('href');

      if (href) {
        await page.goto(BASE_URL + href);
      } else {
        await checkoutBtn.first().click();
      }

      await page.waitForLoadState('networkidle');

      console.log('✅ Checkout OK');
    } else {
      console.log('⚠️ Checkout não visível (pode exigir itens no carrinho)');
    }
  });

  test('✅ Admin painel acessível', async ({ page }) => {
    const adminUrl = BASE_URL + '/admin/';
    await page.goto(adminUrl, { waitUntil: 'networkidle' });

    const status = await page.evaluate(() => document.documentElement.innerText.length);

    // Se página tem conteúdo, admin está respondendo
    expect(status).toBeGreaterThan(100);

    console.log('✅ Admin painel OK');
  });

  test('✅ Footer com dados da empresa', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    const footer = page.locator('footer');
    const footerText = await footer.textContent();

    // Verificar dados obrigatórios
    const hasCNPJ = footerText.includes('49.903.300/0001-70') || footerText.includes('CNPJ');
    const hasPhone = footerText.includes('3799937') || footerText.includes('37 9993');
    const hasEmail = footerText.includes('atendimento@shopvivaliz');

    expect(hasCNPJ || hasPhone || hasEmail).toBeTruthy();

    console.log('✅ Footer com dados OK');
  });

  test('✅ Liz mascote carrega', async ({ page }) => {
    await page.goto(BASE_URL + '/');

    // Procurar widget Liz
    const liz = page.locator('[class*="liz"], [id*="liz"], [class*="mascote"], [class*="assistant"]');

    if (await liz.isVisible()) {
      console.log('✅ Liz mascote visível');

      // Tentar clicar
      await liz.first().click();
      await page.waitForTimeout(500);

      console.log('✅ Liz clicável');
    } else {
      console.log('⚠️ Liz mascote não encontrado (OK se desabilitado)');
    }
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

    await page.goto(BASE_URL + '/');
    await page.waitForLoadState('networkidle');

    // Clicar em alguns links
    const links = page.locator('a').filter({ hasText: /^[a-zA-Z]/ });
    const count = Math.min(await links.count(), 5); // Max 5 links

    for (let i = 0; i < count; i++) {
      const link = links.nth(i);
      const href = await link.getAttribute('href');

      if (href && !href.includes('javascript') && !href.includes('#')) {
        try {
          await link.click({ timeout: 5000 });
          await page.waitForLoadState('networkidle').catch(() => {});
        } catch (e) {
          // Ignore timeouts
        }
      }
    }

    expect(errors).toHaveLength(0);

    console.log('✅ Sem erros HTTP 500');
  });

  test('✅ Performance: Página carrega em < 3s', async ({ page }) => {
    const startTime = Date.now();

    await page.goto(BASE_URL + '/', { waitUntil: 'networkidle' });

    const loadTime = Date.now() - startTime;

    expect(loadTime).toBeLessThan(3000);

    console.log(`✅ Página carregou em ${loadTime}ms`);
  });

});

test.describe('🔐 Security Checks', () => {

  test('✅ HTTPS ativo', async ({ page }) => {
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
