# Auditoria Mercado Pago real — 2026-07-15

## Resultado

A release cumulativa 9.2.104 implementa dois fluxos reais vinculados ao pedido validado:

- boleto bancário pelo Mercado Pago Orders API (`payment_method.id=boleto`, `type=ticket`);
- Checkout Pro para cartão, PIX, saldo e demais meios habilitados na conta.

Nenhuma cobrança ou boleto foi criado durante a validação técnica desta branch. A emissão financeira aguarda a escolha do produto, os dados do pagador preenchidos no checkout e autorização de deploy no ambiente `dev`.

## Causa encontrada

O checkout publicado apenas registrava um pedido em `storage/orders` e mostrava que o boleto seria emitido manualmente. O endpoint legado de pagamento consultava outra persistência, fixava cartão e CPF de exemplo e não era chamado pelo checkout. Outros endpoints aceitavam payload e valor arbitrários, e um deles desativava a validação TLS.

## Mudanças

- `includes/mercadopago-gateway.php`: payloads autoritativos, API HTTPS, idempotência, CPF, sessão de pagamento e HMAC.
- `api/mercadopago/create-boleto.php`: emite e persiste boleto somente para um pedido validado e uma sessão opaca.
- `api/mercadopago/create-preference.php`: cria Checkout Pro sem aceitar preços do navegador.
- `api/webhook-mercadopago.php`: valida `x-signature`, consulta `/v1/orders/{id}` ou `/v1/payments/{id}` e impede regressão de pedido aprovado.
- `checkout.php`: coleta endereço estruturado/CPF quando necessário, recupera tentativas idempotentes, mostra linha digitável e redireciona ao Checkout Pro.
- `checkout-return.php`: retorno seguro sem afirmar aprovação antes da confirmação oficial.
- endpoints legados de criação arbitrária retornam HTTP 410 e scripts financeiros de raiz ficam bloqueados no Apache.
- versão, self-test, exemplo de ambiente e release note atualizados para 9.2.104.
- credenciais Mercado Pago encontradas em arquivos rastreados foram substituídas por marcadores; os valores expostos no histórico devem ser rotacionados.

## Testes executados

- PHP lint nos 15 arquivos PHP alterados: aprovado.
- `php tests/mercadopago-payment-tests.php`: 10/10 aprovados.
- JavaScript principal do checkout validado com `node --check`: aprovado.
- `php scripts/quality/validate-updater-contract.php`: aprovado.
- `git diff --check`: aprovado.
- scanner de padrões de credenciais Mercado Pago rastreadas: zero valor não redigido na árvore atual.
- HTTP no `dev`: checkout 200; publicação ainda contém o fluxo manual e não contém os novos endpoints.
- endpoints legados publicados: `mercadopago-orders` 200, SDK 500, `process-payment` 400 e webhook 500.

## Credenciais e riscos

- O checkout local, `runtime-secrets.php`, secrets de Actions e secrets dos environments GitHub não apresentam chaves Mercado Pago.
- O endpoint legado publicado indica que algum valor de Access Token existe no `.env` do servidor, mas não prova que ele autentica.
- O webhook publicado responde 500 e ainda não está operacional.
- A extensão cURL do PHP local está indisponível; a implementação inclui transporte HTTPS por streams como fallback. O servidor deve manter CA/TLS válido.
- Credenciais anteriormente rastreadas devem ser consideradas comprometidas e rotacionadas antes do uso financeiro em produção.
- A automação visual não pôde ser concluída porque o runtime do navegador da sessão falhou ao inicializar. A auditoria HTTP confirmou o estado publicado, sem interação nem cobrança.

## Próxima tarefa recomendada

1. Rotacionar Access Token e webhook secret no Mercado Pago e salvar apenas no runtime do servidor.
2. Autorizar explicitamente o deploy da branch `codex/mercadopago-live-20260715` no ambiente `dev`.
3. Configurar no painel Mercado Pago o evento **Order (Mercado Pago)** e o evento de pagamentos para `https://shopvivaliz.com.br/api/webhook-mercadopago.php`.
4. Escolher o produto no carrinho e preencher os dados reais do pagador diretamente no checkout.
5. Emitir um único boleto, confirmar `action_required/waiting_payment`, testar idempotência e enviar o link/linha digitável para o Gmail autenticado do usuário.

Motivo: essas etapas são as únicas pendências para transformar a implementação validada em uma compra real auditada sem expor CPF, credenciais ou dados de sessão.
