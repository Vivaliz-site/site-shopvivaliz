import { test, expect } from './fixtures';

test.describe('Fluxo de Compra Completa', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';
  const isLocal = /^http:\/\/(127\.0\.0\.1|localhost)(:|\/)/.test(baseUrl);
  const testUser = {
    email: 'test@example.com',
    password: 'password123',
  };

  test('realizar uma compra completa com login, endereco e verificacao de pedido', async ({ page }) => {
    test.skip(isLocal, 'Fluxo completo depende de banco, sessão e dados persistentes não provisionados no runner local');

    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await expect(page.locator('#product-grid')).toBeAttached();

    const produtoLink = page.locator('#product-grid .product-card a.card-link').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);
    test.skip(!temProduto, 'Nenhum produto disponível neste ambiente para adicionar ao carrinho');

    await produtoLink.click();
    await expect(page).toHaveURL(/\/produto/);

    const comprar = page
      .locator('button:has-text("Comprar"), a:has-text("Comprar"), button:has-text("Adicionar")')
      .first();
    await expect(comprar).toBeVisible();
    await comprar.click();

    if (!/\/carrinho/.test(page.url())) {
      const carrinhoLink = page.locator('a[href*="/carrinho"]').first();
      await expect(carrinhoLink).toBeVisible();
      await carrinhoLink.click();
    }

    await expect(page).toHaveURL(/\/carrinho/);
    await expect(page.locator('.cart-item').first()).toBeVisible();

    const finalizarCompra = page
      .locator('button:has-text("Finalizar Compra"), a:has-text("Finalizar Compra"), a:has-text("Finalizar pedido")')
      .first();
    await expect(finalizarCompra).toBeVisible();
    await finalizarCompra.click();
    await expect(page).toHaveURL(/\/checkout|\/auth\/login\.php/);

    if (/\/auth\/login\.php/.test(page.url())) {
      await page.locator('input[name="email"]').fill(testUser.email);
      await page.locator('input[name="password"]').fill(testUser.password);
      await page.locator('button:has-text("Entrar"), button:has-text("Login")').first().click();
      await expect(page).toHaveURL(/\/checkout/);
    }

    await page.locator('input[name="cep"]').fill('01001000');
    await page.locator('input[name="numero"]').fill('123');
    const complemento = page.locator('input[name="complemento"]');
    if (await complemento.isVisible().catch(() => false)) {
      await complemento.fill('Apto 1');
    }

    await page.locator('button:has-text("Pagar"), button:has-text("Confirmar Pedido")').first().click();

    await expect(page).toHaveURL(/\/pedido-confirmado|\/sucesso/);
    await expect(page.locator('h1:has-text("Pedido Confirmado"), h1:has-text("Sucesso")')).toBeVisible();
    await expect(page.getByText(/pedido foi realizado com sucesso/i)).toBeVisible();

    // Verificar que tracking foi capturado
    const hasClientId = await page.evaluate(() => {
      try {
        const clientId = localStorage.getItem('sv_funnel_client_v1');
        return clientId && clientId.length > 16;
      } catch (e) {
        return false;
      }
    });
    expect(hasClientId).toBe(true);

    // Verificar que gtag foi carregado
    const hasGtag = await page.evaluate(() => {
      return typeof window.gtag === 'function' || (window.dataLayer && Array.isArray(window.dataLayer));
    });
    expect(hasGtag).toBe(true);
  });

  test('persistir dados de tracking (client_id, gclid, utm) no pedido', async ({ page }) => {
    test.skip(isLocal, 'Requer ambiente de teste com persistência de pedidos');

    // Simular URL com parâmetros de rastreamento
    const trackingUrl = `${baseUrl}/?utm_source=google&utm_medium=cpc&utm_campaign=test&gclid=test123`;
    await page.goto(trackingUrl, { waitUntil: 'networkidle' });

    // Validar que UTM params foram capturados
    const hasUtmData = await page.evaluate(() => {
      try {
        return (
          localStorage.getItem('sv_utm_source') === 'google' &&
          localStorage.getItem('sv_utm_medium') === 'cpc' &&
          localStorage.getItem('sv_utm_campaign') === 'test' &&
          localStorage.getItem('sv_gclid') === 'test123'
        );
      } catch (e) {
        return false;
      }
    });
    expect(hasUtmData).toBe(true);
  });
});
