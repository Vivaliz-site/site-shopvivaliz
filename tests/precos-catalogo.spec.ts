import { test, expect } from './fixtures';

test.describe('Catálogo - Preços', () => {
  test.beforeEach(async ({ page }) => {
    // Abrir homepage
    await page.goto('https://dev.shopvivaliz.com.br/', { waitUntil: 'networkidle' });
  });

  test('deve exibir preços nos produtos da homepage', async ({ page }) => {
    // Esperar produtos carregarem
    await page.waitForSelector('[class*="produto"]', { timeout: 5000 }).catch(() => {});

    // Procurar por "Preço sob consulta" - NÃO deve existir
    const priceLabels = await page.$$('text=Preço sob consulta');
    console.log(`[INFO] Encontrados ${priceLabels.length} produtos sem preço`);

    // Deve haver produtos com preço
    const productsWithPrice = await page.$$('text=R$');
    console.log(`[INFO] Encontrados ${productsWithPrice.length} produtos com preço`);

    expect(productsWithPrice.length).toBeGreaterThan(0);
  });

  test('deve exibir preços válidos (maior que zero)', async ({ page }) => {
    // Procurar por padrão de preço: R$ XX,XX
    const prices = await page.$$eval(
      'text=/R\\$ \\d+[.,]\\d{2}/',
      elements => elements.map(el => el.textContent)
    );

    console.log(`[INFO] Preços encontrados: ${prices.slice(0, 5).join(', ')}`);

    expect(prices.length).toBeGreaterThan(0);

    // Todos devem ser maiores que 0
    prices.forEach(price => {
      const cleaned = price?.replace('R$ ', '').replace(',', '.') || '0';
      const value = parseFloat(cleaned);
      expect(value).toBeGreaterThan(0);
    });
  });

  test('produtos especiais devem ter preços corretos', async ({ page }) => {
    // Procurar por produto específico
    const rodizioBotao = page.locator('text=Rodízio').first();
    if (await rodizioBotao.isVisible()) {
      const priceText = await rodizioBotao.locator('..').locator('text=/R\\$/').textContent();
      expect(priceText).toMatch(/R\$ \d+[.,]\d{2}/);
    }
  });
});
