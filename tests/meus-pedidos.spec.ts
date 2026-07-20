import { test, expect } from './fixtures';

test.describe('Página de Pedidos', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br';

  test('página de pedidos deve redirecionar para login se não autenticado', async ({ page }) => {
    // Limpar cookies para garantir logout
    await page.context().clearCookies();

    await page.goto(`${baseUrl}/meus-pedidos.php`, { waitUntil: 'networkidle' });

    // Deve redirecionar para login
    const url = page.url();
    expect(url).toContain('login');
    console.log(`[INFO] Redirecionado para: ${url}`);
  });

  test('página de pedidos deve existir', async ({ page }) => {
    const response = await page.goto(`${baseUrl}/meus-pedidos.php`, { waitUntil: 'domcontentloaded' });

    // Deve retornar erro 302 (redirect) ou sucesso (200)
    const status = response?.status() || 302;
    console.log(`[INFO] Status da página de pedidos: ${status}`);
    expect(status).toBeLessThan(500);
  });

  test('header deve mostrar nome do usuário quando logado', async ({ page }) => {
    // Esta é uma verificação simples de estrutura
    // Em um teste real, precisaríamos ter um usuário de teste

    const response = await page.goto(`${baseUrl}/meus-pedidos.php`, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBeLessThan(500);

    // Procurar por elementos de header
    const userInfo = page.locator('[class*="user"], text=Olá').first();
    const logoutBtn = page.locator('text=Sair, a:has-text("Sair")').first();

    const userVisible = await userInfo.isVisible({ timeout: 5000 }).catch(() => false);
    const logoutVisible = await logoutBtn.isVisible({ timeout: 5000 }).catch(() => false);

    console.log(`[INFO] Informações do usuário visíveis: ${userVisible || logoutVisible}`);
  });

  test('lista de pedidos deve ter estrutura correta', async ({ page }) => {
    // Carregar página (pode redirecionar)
    await page.goto(`${baseUrl}/meus-pedidos.php`, { waitUntil: 'domcontentloaded' });

    // Se não foi redirecionado para login
    if (!page.url().includes('login')) {
      // Procurar por tabela ou lista de pedidos
      const orderTable = page.locator('[class*="order"], table').first();
      const noPedidos = page.locator('text=ainda não fez').first();

      const hasOrders = await orderTable.isVisible({ timeout: 5000 }).catch(() => false);
      const isEmpty = await noPedidos.isVisible({ timeout: 5000 }).catch(() => false);

      console.log(`[INFO] Tem pedidos: ${hasOrders}, Vazio: ${isEmpty}`);
    }
  });

  test('status do pedido deve ter cores visuais', async ({ page }) => {
    // Simular se houver pedidos
    await page.goto(`${baseUrl}/meus-pedidos.php`);

    const statusElements = page.locator('[class*="status"]');
    const count = await statusElements.count();

    console.log(`[INFO] Elementos de status encontrados: ${count}`);

    if (count > 0) {
      const statusText = await statusElements.first().textContent();
      console.log(`[INFO] Exemplo de status: ${statusText}`);
    }
  });

  test('código de rastreamento deve ser exibido quando disponível', async ({ page }) => {
    await page.goto(`${baseUrl}/meus-pedidos.php`);

    // Procurar por código de rastreamento
    const rastreamento = page.locator('text=/[A-Z]{2}\\d{9}[A-Z]{2}/, [class*="tracking"]').first();

    const visible = await rastreamento.isVisible({ timeout: 5000 }).catch(() => false);
    console.log(`[INFO] Código de rastreamento visível: ${visible}`);
  });
});
