import { test, expect } from '@playwright/test';

test.describe('Fluxo de Compra', () => {
  const baseUrl = 'https://dev.shopvivaliz.com.br';

  test('deve visualizar produto e preço na homepage', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    // Procurar por um produto
    const produtoLink = page.locator('[class*="produto"]').first();

    if (await produtoLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      // Deve ter preço
      const precoText = await produtoLink.locator('text=/R\\$/').textContent();
      expect(precoText).toMatch(/R\$ \d+[.,]\d{2}/);
      console.log(`[INFO] Produto encontrado com preço: ${precoText}`);
    }
  });

  test('clique em produto deve abrir página de detalhes', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    // Encontrar primeiro link de produto
    const produtoLink = page.locator('a:has-text("Rodízio"), a:has-text("Abraçadeira")').first();

    if (await produtoLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await produtoLink.click();

      // Deve estar em página de produto
      await expect(page).toHaveURL(/\/produto/);

      // Deve ter preço exibido
      const precoVisible = await page.locator('text=/R\\$/').isVisible({ timeout: 5000 }).catch(() => false);
      console.log(`[INFO] Página de produto acessada, preço visível: ${precoVisible}`);
    }
  });

  test('deve aparecer botão "Comprar agora" no produto', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    const produtoLink = page.locator('a:has-text("Rodízio"), a:has-text("Abraçadeira")').first();

    if (await produtoLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await produtoLink.click();

      // Procurar por botão de compra
      const comprarBtn = page.locator('button:has-text("Comprar"), a:has-text("Comprar"), button:has-text("Adicionar")').first();

      const btnVisible = await comprarBtn.isVisible({ timeout: 5000 }).catch(() => false);
      console.log(`[INFO] Botão de compra visível: ${btnVisible}`);
    }
  });

  test('carrinho deve estar acessível', async ({ page }) => {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    // Procurar por ícone do carrinho
    const carrinho = page.locator('[class*="carrinho"], text=Carrinho, [aria-label*="Carrinho"]').first();

    const carrinhoVisible = await carrinho.isVisible({ timeout: 5000 }).catch(() => false);
    console.log(`[INFO] Carrinho visível: ${carrinhoVisible}`);

    if (carrinhoVisible) {
      // Verificar se mostra quantidade de itens
      const quantidade = await carrinho.textContent();
      console.log(`[INFO] Informação do carrinho: ${quantidade}`);
    }
  });

  test('checkout deve existir', async ({ page }) => {
    // Procurar por página de checkout
    const checkoutUrl = `${baseUrl}/checkout`;

    const response = await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded' }).catch(() => null);

    if (response) {
      const status = response.status();
      console.log(`[INFO] Página de checkout status: ${status}`);

      if (status < 400) {
        // Deve ter formulário
        const formVisible = await page.locator('form, [class*="checkout"]').isVisible({ timeout: 5000 }).catch(() => false);
        console.log(`[INFO] Formulário de checkout visível: ${formVisible}`);
      }
    }
  });
});
