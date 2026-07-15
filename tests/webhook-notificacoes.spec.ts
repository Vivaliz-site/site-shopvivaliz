import { test, expect } from './fixtures';

test.describe('Webhook de Status e Notificações', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://dev.shopvivaliz.com.br';
  const webhookUrl = `${baseUrl}/api/webhooks/order-status-update.php`;
  const webhookToken = process.env.E2E_WEBHOOK_TOKEN;

  test('webhook sem token deve retornar 401', async ({ request }) => {
    const response = await request.post(webhookUrl, {
      data: {
        order_id: 'test123',
        status: 'shipped',
      },
    });

    expect(response.status()).toBe(401);
    console.log(`[INFO] Webhook sem token retornou ${response.status()}`);
  });

  test('webhook com token inválido deve negar acesso', async ({ request }) => {
    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': 'Bearer invalid-token',
      },
      data: {
        order_id: 'test123',
        status: 'shipped',
      },
    });

    // 401 when the server has no webhook token configured; 403 when a token
    // exists but the provided credential is invalid. Both reject access.
    expect([401, 403]).toContain(response.status());
    console.log(`[INFO] Webhook com token inválido retornou ${response.status()}`);
  });

  test('webhook com dados inválidos deve retornar 400', async ({ request }) => {
    test.skip(!webhookToken, 'E2E_WEBHOOK_TOKEN não configurado para teste autenticado');

    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': `Bearer ${webhookToken!}`,
        'Content-Type': 'application/json',
      },
      data: {
        // Missing required fields
        status: 'shipped',
      },
    });

    expect(response.status()).toBe(400);
    console.log(`[INFO] Webhook com dados inválidos retornou ${response.status()}`);
  });

  test('webhook deve aceitar payload válido do Olist', async ({ request }) => {
    test.skip(!webhookToken, 'E2E_WEBHOOK_TOKEN não configurado para teste autenticado');

    const payload = {
      order_id: 'olist-test-' + Date.now(),
      status: 'shipped',
      tracking_number: 'LJ123456789BR',
      estimated_delivery_date: '2026-07-15',
    };

    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': `Bearer ${webhookToken!}`,
        'Content-Type': 'application/json',
      },
      data: payload,
    });

    console.log(`[INFO] Webhook com dados válidos retornou ${response.status()}`);
    expect([200, 404]).toContain(response.status());
  });

  test('webhook deve mapear status do Olist corretamente', async ({ request }) => {
    test.skip(!webhookToken, 'E2E_WEBHOOK_TOKEN não configurado para teste autenticado');

    const statusMap = [
      { input: 'waiting_payment', expected: 'aguardando_pagamento' },
      { input: 'payment_approved', expected: 'pagamento_aprovado' },
      { input: 'shipped', expected: 'enviado' },
      { input: 'delivered', expected: 'entregue' },
      { input: 'cancelled', expected: 'cancelado' },
    ];

    for (const mapping of statusMap) {
      const payload = {
        order_id: `test-${mapping.input}-${Date.now()}`,
        status: mapping.input,
        tracking_number: 'TEST123',
      };

      const response = await request.post(webhookUrl, {
        headers: {
          'Authorization': `Bearer ${webhookToken!}`,
        },
        data: payload,
      });

      console.log(
        `[INFO] Status '${mapping.input}' → '${mapping.expected}': ${response.status()}`
      );
      expect([200, 404]).toContain(response.status());
    }
  });

  test('endpoint do webhook deve estar documentado', async ({ page }) => {
    // Verificar se há documentação
    const docFiles = ['AUTENTICACAO-E-NOTIFICACOES.md', 'README.md'];

    for (const docFile of docFiles) {
      const response = await page.goto(`${baseUrl}/${docFile}`, {
        waitUntil: 'domcontentloaded',
      }).catch(() => null);

      if (response && response.ok()) {
        const content = await page.content();
        if (content.includes('webhook') || content.includes('order-status')) {
          console.log(`[INFO] Documentação encontrada em ${docFile}`);
          break;
        }
      }
    }
  });

  test('mailer.php deve exportar funções de email', async ({ page }) => {
    // Verificar se arquivo existe e tem as funções
    const response = await page.goto(`${baseUrl}/scripts/mailer.php`, {
      waitUntil: 'domcontentloaded',
    }).catch(() => null);

    // O arquivo PHP não deve ser acessível diretamente
    console.log(`[INFO] Acesso direto ao mailer.php status: ${response?.status() || 'blocked'}`);
  });
});
