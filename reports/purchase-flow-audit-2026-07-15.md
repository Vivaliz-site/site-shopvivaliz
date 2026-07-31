# Auditoria cumulativa do processo de compra — 2026-07-15

## Resultado executivo

O funil publicado em `https://shopvivaliz.com.br` permite navegar, manter carrinho, consultar CEP e obter frete real do Melhor Envio. As proteções de preço, estoque e cotação assinada também estão ativas.

O fluxo financeiro Mercado Pago ainda não pode ser considerado funcional no ambiente publicado: o checkout mostra emissão manual de boleto, não chama o endpoint novo, a rota de retorno responde 404 e o webhook responde 500. Nenhum pedido, cobrança ou boleto foi criado nesta auditoria para não contaminar o ERP nem executar ação financeira desnecessária.

## Matriz de auditoria

| Etapa | Evidência em `dev` | Resultado |
| --- | --- | --- |
| Home, catálogo, carrinho e checkout | Home 200, catálogo redireciona para a rota canônica, carrinho 200 e checkout 200 | Aprovado |
| Produto real | SKU público `KIT4R-SOPRÃO`, preço autoritativo R$ 45,00 e estoque disponível | Aprovado |
| Persistência do carrinho | `shopvivaliz_cart` e cotação de frete persistidos no navegador | Aprovado por código/HTTP |
| CEP válido | ViaCEP para `01001-000` devolveu logradouro, bairro, cidade e UF | Aprovado |
| CEP inexistente | ViaCEP para `00000-000` devolveu `erro=true` | Aprovado |
| Preenchimento de endereço | Checkout publicado concatena logradouro, bairro e cidade/UF em um único campo | Reprovado para boleto |
| Número, bairro, cidade e UF estruturados | Campos não existem no checkout publicado | Reprovado |
| Frete real | Melhor Envio devolveu 5 opções; seleção observada de R$ 16,28 e 5 dias | Aprovado |
| Assinatura/expiração do frete | `quote_id` presente e expiração de 30 minutos | Aprovado |
| Frete inválido | CEP inválido e carrinho vazio retornaram 422 | Aprovado |
| Pedido sem itens | HTTP 422 `empty_items` | Aprovado |
| Preço adulterado | HTTP 409 `item_price_mismatch` | Aprovado |
| Pedido sem frete | HTTP 422 `shipping_quote_required` | Aprovado |
| Cotação adulterada | HTTP 409 `shipping_quote_invalid` | Aprovado |
| Criação bem-sucedida do pedido em `dev` | Não executada para evitar pedido real no ERP | Pendente com autorização |
| Boleto Mercado Pago | Checkout publicado ainda informa emissão manual e não chama `create-boleto.php` | Reprovado |
| Retorno do pagamento | `/checkout/retorno` responde 404 | Reprovado |
| Webhook Mercado Pago | `/api/webhook-mercadopago.php` responde 500 | Reprovado |
| Endpoints legados | `mercadopago-orders.php` 200; SDK 500; `process-payment.php` 400 | Risco crítico |

## Endereço por CEP

O serviço ViaCEP está operacional. O problema está no formulário publicado: após a consulta, o JavaScript gera apenas uma string com `logradouro, bairro, cidade/UF` e a grava em `address`. Não há campo separado para número, bairro, cidade ou estado.

Isso impede o atendimento do contrato atual do boleto pela Orders API do Mercado Pago, que exige `zip_code`, `street_name`, `street_number`, `neighborhood`, `city` e `state`. Também deixa o endereço de entrega ambíguo porque o usuário não recebe um campo explícito para o número.

A release local 9.2.104 já separa esses campos, mantém o número para preenchimento manual e usa o CEP somente para completar os dados retornados pelo ViaCEP. Ela ainda não foi implantada.

## Segurança e integridade

- preço e estoque são resolvidos novamente no servidor;
- o navegador não pode impor preço de produto nem valor de frete;
- a cotação de frete é assinada e expira;
- as tentativas de adulteração testadas foram bloqueadas antes de criar pedido;
- a release local vincula boleto/preferência a pedido validado, usa idempotência e valida assinatura do webhook;
- credenciais anteriormente encontradas em arquivos rastreados precisam ser rotacionadas antes de qualquer transação real;
- nenhum CPF, e-mail, cookie, token ou dado de sessão foi gravado neste relatório.

## Regressões locais da release 9.2.104

- lint PHP dos componentes Mercado Pago, checkout e retorno: aprovado;
- `tests/mercadopago-payment-tests.php`: 10 aprovados, 0 falhas;
- sintaxe JavaScript do frete no checkout: aprovada;
- contrato do atualizador: aprovado;
- aviso do ambiente local: extensão PHP cURL ausente; o código possui fallback HTTPS, mas TLS/CA deve ser validado no servidor após o deploy;
- saúde de logs: `WARNING` por ausência de `logs/execution`, `logs/monitor-messages.log` e `logs/monitor-responses.jsonl` no worktree isolado.

## Pendências para homologação financeira real

1. Rotacionar o Access Token e o segredo de webhook e mantê-los somente no runtime do servidor.
2. Autorizar e implantar a release 9.2.104 no ambiente `dev`.
3. Configurar a URL e os eventos de webhook no painel Mercado Pago.
4. Repetir a auditoria visual do funil e conferir o endereço estruturado preenchido pelo CEP.
5. Criar um único pedido autorizado, emitir boleto real, confirmar estado pendente, repetir a chamada para provar idempotência e validar o evento oficial no webhook.
6. Enviar o boleto ao e-mail do próprio usuário somente após a emissão bem-sucedida.

## Limitação da auditoria

O controle visual do navegador não inicializou nesta sessão. A lógica publicada foi auditada por requisições HTTP reais e inspeção do HTML/JavaScript servido, mas layout, foco, mensagens visuais, acessibilidade prática e comportamento mobile precisam de uma rodada visual após o deploy.
