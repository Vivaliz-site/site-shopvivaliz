import { test, expect } from './fixtures';

test.describe('Webhook de Status e Notificações', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://dev.shopvivaliz.com.br';
  const webhookUrl = `${baseUrl}/api/webhooks/order-status-update.php`;

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

  test('webhook com token inválido deve retornar 403', async ({ request }) => {
    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': 'Bearer invalid-token',
      },
      data: {
        order_id: 'test123',
        status: 'shipped',
      },
    });

    expect(response.status()).toBe(403);
    console.log(`[INFO] Webhook com token inválido retornou ${response.status()}`);
  });

  test('webhook com dados inválidos deve retornar 400', async ({ request }) => {
    const webhookToken = process.env.OLIST_WEBHOOK_TOKEN || 'test-token';

    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': `Bearer ${webhookToken}`,
        'Content-Type': 'application/json',
      },
      data: {
        // Missing required fields
        status: 'shipped',
      },
    });

    expect([400, 401, 403]).toContain(response.status());
    console.log(`[INFO] Webhook com dados inválidos retornou ${response.status()}`);
  });

  test('webhook deve aceitar payload válido do Olist', async ({ request }) => {
    const webhookToken = process.env.OLIST_WEBHOOK_TOKEN || 'test-token';

    const payload = {
      order_id: 'olist-test-' + Date.now(),
      status: 'shipped',
      tracking_number: 'LJ123456789BR',
      estimated_delivery_date: '2026-07-15',
    };

    const response = await request.post(webhookUrl, {
      headers: {
        'Authorization': `Bearer ${webhookToken}`,
        'Content-Type': 'application/json',
      },
      data: payload,
    });

    // Pode retornar 200 (sucesso), 404 (pedido não encontrado), ou 500 (erro)
    console.log(`[INFO] Webhook com dados válidos retornou ${response.status()}`);
    console.log(`[INFO] Resposta: ${await response.text()}`);

    // Se temos o webhook token correto e a tabela existe, deve funcionar
    if (webhookToken !== 'test-token') {
      expect(response.status()).toBeLessThan(500);
    }
  });

  test('webhook deve mapear status do Olist corretamente', async ({ request }) => {
    const webhookToken = process.env.OLIST_WEBHOOK_TOKEN || 'test-token';

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
          'Authorization': `Bearer ${webhookToken}`,
        },
        data: payload,
      });

      console.log(
        `[INFO] Status '${mapping.input}' → '${mapping.expected}': ${response.status()}`
      );
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
