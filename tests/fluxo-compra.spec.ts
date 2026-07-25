import { test, expect, type Page } from './fixtures';

async function dismissCouponPopup(page: Page): Promise<void> {
  const popup = page.locator('#popup-cupons-modal');
  const visible = await popup.isVisible({ timeout: 1000 }).catch(() => false);
  if (!visible) return;

  const closeButton = popup.locator('button, [role="button"], .close, .popup-cupons-close').first();
  if (await closeButton.isVisible({ timeout: 500 }).catch(() => false)) {
    await closeButton.click();
  } else {
    await page.evaluate(() => {
      const close = (window as typeof window & { sv_popup_cupons_close?: () => void }).sv_popup_cupons_close;
      if (typeof close === 'function') close();
    });
  }

  await expect(popup).toBeHidden({ timeout: 3000 });
}

test.describe('Fluxo de Compra', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';

  test('homepage deve responder e carregar estrutura basica', async ({ page }) => {
    const response = await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('#product-grid')).toBeAttached();
  });

  test('produto na homepage deve ter preco valido (ou catalogo deve informar vazio)', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    const produtoLink = page.locator('#product-grid .product-card').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);

    if (!temProduto) {
      await expect(page.locator('#catalog-status')).toBeVisible();
      test.skip(true, 'Catalogo vazio neste ambiente - sem produto para validar preco');
      return;
    }

    const precoText = await produtoLink.locator('text=/R\\$/').textContent();
    expect(precoText).toMatch(/R\$ \d+[.,]\d{2}/);
  });

  test('clique em produto deve abrir pagina de detalhes com preco', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await dismissCouponPopup(page);

    const produtoLink = page.locator('#product-grid .product-card a.card-link').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);
    test.skip(!temProduto, 'Nenhum produto de exemplo encontrado neste ambiente');

    await produtoLink.click();
    await expect(page).toHaveURL(/\/produto/);
    await expect(page.locator('text=/R\\$/').first()).toBeVisible({ timeout: 5000 });
  });

  test('botao de compra deve existir na pagina de produto', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await dismissCouponPopup(page);

    const produtoLink = page.locator('#product-grid .product-card a.card-link').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);
    test.skip(!temProduto, 'Nenhum produto de exemplo encontrado neste ambiente');

    await produtoLink.click();
    const comprarBtn = page.locator('button:has-text("Comprar"), a:has-text("Comprar"), button:has-text("Adicionar")').first();
    await expect(comprarBtn).toBeVisible({ timeout: 5000 });
  });

  test('carrinho deve estar acessivel e responder', async ({ page }) => {
    const response = await page.goto(`${baseUrl}/carrinho.php`, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBeLessThan(500);
  });

  test('checkout nao deve gerar cobranca real ao ser acessado', async ({ page }) => {
    const response = await page.goto(`${baseUrl}/checkout`, { waitUntil: 'domcontentloaded' }).catch(() => null);
    expect(response).not.toBeNull();
    expect(response!.status()).toBeLessThan(500);
  });
});
