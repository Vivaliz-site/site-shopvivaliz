import { test, expect } from './fixtures';

test.describe('Fluxo de Compra Completa', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://dev.shopvivaliz.com.br';
  const testUser = {
    email: 'test@example.com',
    password: 'password123',
  };

  test('realizar uma compra completa com login, endereco e verificacao de pedido', async ({ page }) => {
    // 1. Navegar para a página inicial e adicionar um produto ao carrinho
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await expect(page.locator('#product-grid')).toBeAttached();

    const produtoLink = page.locator('#product-grid .product-card a.card-link').first();
    const temProduto = await produtoLink.isVisible({ timeout: 5000 }).catch(() => false);
    test.skip(!temProduto, 'Nenhum produto de exemplo encontrado neste ambiente para adicionar ao carrinho');

    await produtoLink.click();
    await expect(page).toHaveURL(/\/produto/);
    await page.locator('button:has-text("Comprar"), a:has-text("Comprar"), button:has-text("Adicionar")').first().click();

    // Esperar que o carrinho seja atualizado ou redirecionado para a página do carrinho
    await page.waitForURL(/\/carrinho/);
    await expect(page.locator('.cart-item')).toBeVisible();

    // 2. Ir para o checkout
    await page.locator('button:has-text("Finalizar Compra"), a:has-text("Finalizar Compra")').first().click();
    await page.waitForURL(/\/checkout/);

    // 3. Realizar login (simulado com e-mail/senha, pois login com Google requer setup de credenciais)
    // TODO: Implementar login com Google se as credenciais de teste forem fornecidas e configuradas
    await page.locator('input[name="email"]').fill(testUser.email);
    await page.locator('input[name="password"]').fill(testUser.password);
    await page.locator('button:has-text("Entrar"), button:has-text("Login")').first().click();
    await page.waitForLoadState('networkidle');

    // 4. Preencher endereço com CEP (assumindo que o login redireciona para o checkout com formulário de endereço)
    // TODO: Ajustar seletores e valores conforme a UI real do seu checkout
    await page.locator('input[name="cep"]').fill('01001000'); // Exemplo de CEP
    await page.waitForLoadState('networkidle'); // Esperar o preenchimento automático do endereço
    await page.locator('input[name="numero"]').fill('123');
    await page.locator('input[name="complemento"]').fill('Apto 1');

    // 5. Selecionar método de pagamento e finalizar a compra
    // TODO: Selecionar um método de pagamento (ex: boleto, pix, cartão de crédito simulado)
    // Por enquanto, vou assumir que um método padrão já está selecionado ou que o teste de fixture.ts já lida com isso.
    await page.locator('button:has-text("Pagar"), button:has-text("Confirmar Pedido")').first().click();

    // 6. Verificar a mensagem de sucesso do pedido
    await page.waitForURL(/\/pedido-confirmado|\/sucesso/);
    await expect(page.locator('h1:has-text("Pedido Confirmado"), h1:has-text("Sucesso")')).toBeVisible();
    await expect(page.locator('text=Seu pedido foi realizado com sucesso!')).toBeVisible();

    // 7. Verificações adicionais (requerem acesso a sistemas externos ou mocks)
    // TODO: Verificar se o pedido saiu na ERP (requer API do ERP ou mock)
    // console.log('Verificar integração com ERP - Requer acesso a API ou mock do ERP');

    // TODO: Verificar se os e-mails foram disparados (requer acesso a um servidor de e-mail de teste ou mock)
    // console.log('Verificar disparo de e-mails - Requer acesso a servidor de e-mail de teste ou mock');

    // TODO: Verificar login com Google (se implementado e configurado com credenciais de teste)
    // console.log('Verificar login com Google - Requer credenciais de teste e fluxo específico');
  });
});
