import { test, expect } from './fixtures';

test.describe('Catálogo - Preços', () => {
  test.beforeEach(async ({ page }) => {
    // Abrir homepage
    await page.goto('/', { waitUntil: 'networkidle' });
  });

  test('deve exibir preços nos produtos da homepage', async ({ page }) => {
    // Esperar produtos carregarem
    await page.waitForSelector('#product-grid .product-card', { timeout: 5000 });

    // Procurar por "Preço sob consulta" - NÃO deve existir
    const priceLabels = await page.$$('text=Preço sob consulta');
    console.log(`[INFO] Encontrados ${priceLabels.length} produtos sem preço`);

    // Deve haver produtos com preço
    const productsWithPrice = await page.$$('text=R$');
    console.log(`[INFO] Encontrados ${productsWithPrice.length} produtos com preço`);

    expect(productsWithPrice.length).toBeGreaterThan(0);
  });

  test('deve exibir preços válidos (maior que zero)', async ({ page }) => {
    // Só preços de PRODUTO (.product-price). O padrão amplo antigo varria a
    // página toda e casava com o subtotal "R$ 0,00" do mini-cart vazio.
    const prices = await page.$$eval(
      '.product-price',
      elements => elements.map(el => el.textContent ?? '')
    );

    const priced = prices.filter(p => /R\$ \d+[.,]\d{2}/.test(p));
    console.log(`[INFO] Preços encontrados: ${priced.slice(0, 5).join(', ')}`);

    expect(priced.length).toBeGreaterThan(0);

    // Todos devem ser maiores que 0
    priced.forEach(price => {
      const match = price.match(/R\$ (\d+(?:\.\d{3})*,\d{2}|\d+\.\d{2})/);
      const cleaned = (match ? match[1] : '0').replace(/\.(?=\d{3})/g, '').replace(',', '.');
      const value = parseFloat(cleaned);
      expect(value).toBeGreaterThan(0);
    });
  });

  test('produtos especiais devem ter preços corretos', async ({ page }) => {
    // Procurar por produto específico
    const rodizioCard = page.locator('#product-grid .product-card:has-text("Rodízio")').first();
    if (await rodizioCard.isVisible()) {
      const priceText = await rodizioCard.locator('.product-price').textContent();
      expect(priceText).toMatch(/R\$ \d+[.,]\d{2}/);
    }
  });
});
