import { test as base, expect } from '@playwright/test';

// GUARDRAIL DE SEGURANCA FINANCEIRA
// -----------------------------------------------------------------------------
// As chaves do Pagar.me configuradas neste projeto sao REAIS (live), nao de
// sandbox. Nenhum teste automatizado pode, em hipotese alguma, deixar uma
// requisicao real chegar aos servidores do Pagar.me durante os testes E2E -
// isso poderia gerar uma cobranca de verdade.
//
// Este fixture intercepta QUALQUER chamada de rede para dominios do Pagar.me
// (api.pagar.me, dashboard.pagar.me, checkout.pagar.me, etc.) em todo teste
// que importar `test` a partir deste arquivo, e devolve uma resposta simulada
// em vez de deixar a requisicao sair de verdade. Se algum dia o checkout real
// for implementado e tentar chamar o Pagar.me durante os testes, a chamada
// sera bloqueada e simulada aqui - nunca vai para producao do Pagar.me.
//
// Para testar cenarios de sucesso/falha de pagamento, ajuste o mock abaixo
// (ex: mudar status para "failed" e o texto de erro) em vez de remover o
// bloqueio.

export const test = base.extend({
  page: async ({ page }, use) => {
    let pagarmeCallBlocked = false;

    await page.route(/pagar\.?me/i, async (route) => {
      pagarmeCallBlocked = true;
      console.warn(
        `[GUARDRAIL] Requisicao real para Pagar.me bloqueada em teste: ${route.request().url()}`
      );
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 'mock_tx_test_guardrail',
          status: 'paid',
          amount: 0,
          _mock: true,
          _note: 'Resposta simulada pelo guardrail de testes - nenhuma cobranca real foi feita.',
        }),
      });
    });

    await use(page);

    if (pagarmeCallBlocked) {
      console.warn('[GUARDRAIL] Este teste tentou alcancar o Pagar.me real e foi interceptado com sucesso.');
    }
  },
});

export { expect };
