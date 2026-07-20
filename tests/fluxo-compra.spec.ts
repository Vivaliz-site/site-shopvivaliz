import { test, expect } from './fixtures';

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
      // Sem produtos sincronizados neste ambiente - isso e um estado valido,
      // mas o site precisa dizer isso claramente ao usuario, nao mostrar
      // pagina quebrada/vazia sem explicacao.
      await expect(page.locator('#catalog-status')).toBeVisible();
      test.skip(true, 'Catalogo vazio neste ambiente - sem produto para validar preco');
      return;
    }

    const precoText = await produtoLink.locator('text=/R\\$/').textContent();
    expect(precoText).toMatch(/R\$ \d+[.,]\d{2}/);
  });

  test('clique em produto deve abrir pagina de detalhes com preco', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    const produtoLink = page.locator('#product-grid .product-card a.card-link').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);
    test.skip(!temProduto, 'Nenhum produto de exemplo encontrado neste ambiente');

    await produtoLink.click();
    await expect(page).toHaveURL(/\/produto/);
    await expect(page.locator('text=/R\\$/').first()).toBeVisible({ timeout: 5000 });
  });

  test('botao de compra deve existir na pagina de produto', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

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
    // Este teste roda com o guardrail de tests/fixtures.ts ativo: qualquer
    // chamada de rede para o Pagar.me durante este teste seria interceptada
    // e simulada, nunca chegando ao Pagar.me de verdade.
    const response = await page.goto(`${baseUrl}/checkout`, { waitUntil: 'domcontentloaded' }).catch(() => null);
    expect(response).not.toBeNull();
    expect(response!.status()).toBeLessThan(500);
  });
});
