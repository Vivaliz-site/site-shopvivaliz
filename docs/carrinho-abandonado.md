# E-mail de carrinho abandonado

## Como funciona

1. `checkout.php` captura o e-mail de forma fire-and-forget quando o cliente sai do campo e envia para `api/checkout/track-abandonment.php`.
2. O endpoint faz upsert em `checkout_abandonments`, não recebe dados de pagamento e resolve a intenção do carrinho contra o catálogo canônico. O snapshot persistido contém somente SKU, quantidade limitada e nome editorial; preço e estoque não são usados como fonte de verdade.
3. `scripts/send-abandoned-cart-emails.php` procura abandonos elegíveis e envia uma única mensagem de recuperação para quem:
   - informou e-mail há mais de 1 hora e menos de 48 horas;
   - ainda não recebeu e-mail de recuperação;
   - não está marcado como recuperado;
   - ainda não possui um pedido com pagamento aprovado ou etapa posterior de fulfillment depois do abandono.
4. Pedido apenas criado, `aguardando_pagamento`, falha ou expiração de pagamento **não** encerra a recuperação.
5. Antes de selecionar candidatos, o cron reconcilia `recovered_at` contra o espelho canônico de `orders`, usando os estados definidos em `src/Commerce/AbandonedCartRecovery.php`.
6. Para cada envio, o cron gera um token aleatório de 256 bits. O banco guarda somente `SHA-256` em `recovery_token_hash`, com expiração em `recovery_token_expires_at`.
7. O token puro vai somente no fragmento do link: `/recuperar-carrinho.php#token=...`. Fragmentos não são enviados no request HTTP nem no cabeçalho `Referer`. O caminho usa o arquivo físico `.php` porque o `.htaccess` atual não possui uma rota extensionless para essa landing.
8. `recuperar-carrinho.php` remove o fragmento da barra de endereço e envia o token via `POST` para `api/checkout/restore-abandonment.php`.
9. A API de restauração compara o hash, exige token válido/não expirado, ignora carrinhos já recuperados por pagamento e consulta novamente `svcr_products()`. Ela devolve somente itens ainda vendáveis, com **preço, estoque, nome e imagem atuais do servidor**.
10. A landing grava esses itens no `localStorage`, remove qualquer cotação de frete antiga e redireciona para `/carrinho`.
11. O e-mail é transacional e **não promete cupom nem desconto adicional**.

## Segurança da restauração

A recuperação cross-device foi desenhada para não transformar o e-mail em uma fonte de dados comerciais ou pessoais:

- o token não contém e-mail, pedido, SKU ou preço;
- o banco não armazena o token puro;
- o token expira automaticamente;
- a resposta da API não devolve e-mail, nome do cliente ou `cart_total`;
- preço e estoque capturados no navegador nunca são usados para restaurar o carrinho;
- produtos removidos, sem preço ou sem estoque são descartados no momento da restauração;
- a quantidade restaurada é limitada pela quantidade pedida no snapshot e pelo estoque atual;
- o endpoint de restauração é rate-limited;
- depois que um e-mail de recuperação foi enviado, uma nova captura da mesma sessão preserva o token já emitido em vez de invalidá-lo silenciosamente.

Observação: o cliente atual do checkout envia apenas o nome dos itens no evento de abandono. O endpoint resolve esse nome de forma exata e única contra o catálogo canônico; nesses registros a quantidade padrão é 1. Clientes futuros podem enviar `sku` e `quantity`, que já são aceitos pelo endpoint sem depender de preço do navegador.

## Estados que encerram a recuperação

Atualmente são tratados como compra concluída/fulfillment iniciado:

- `pagamento_aprovado`;
- `nota_fiscal_enviada`;
- `pronto_para_enviar`;
- `enviado`;
- `entregue`;
- `nao_entregue`.

`aguardando_pagamento` e `cancelado` não entram nessa lista, pois o backend usa `cancelado` também para falha/expiração de pagamento. Se um pedido já tiver sido aprovado antes de um cancelamento posterior, o marcador `recovered_at` previamente gravado continua preservado.

## Cron de produção

A produção usa releases imutáveis. O código ativo fica em:

```text
/home/ubuntu/shopvivaliz-deploy/current
```

Os logs persistentes ficam em:

```text
/home/ubuntu/shopvivaliz-deploy/shared/logs
```

O cron canônico é:

```cron
*/30 * * * * flock -n /var/lock/shopvivaliz-abandoned-cart-email.lock php /home/ubuntu/shopvivaliz-deploy/current/scripts/send-abandoned-cart-emails.php >> /home/ubuntu/shopvivaliz-deploy/shared/logs/abandoned-cart-email.log 2>&1
```

Não use o caminho legado `/home/ubuntu/site-shopvivaliz`.

## Instalação e autorreparo

O workflow `.github/workflows/abandoned-cart-cron-install.yml` é a fonte operacional do cron. Ele roda quando há push em `main` que altera:

- `ops/abandoned-cart-cron-install.json`;
- o próprio workflow;
- `scripts/send-abandoned-cart-emails.php`.

Na VM, o workflow:

1. confirma que o script existe no symlink `current`;
2. executa `php -l` antes de tocar no crontab;
3. remove entradas antigas de `send-abandoned-cart-emails.php`;
4. instala exatamente uma entrada canônica com `flock`;
5. lê o crontab de volta e falha se a entrada não estiver exatamente como esperado.

Esse comportamento é idempotente e também corrige automaticamente instalações antigas com caminho errado.

## Validação comercial

Depois da instalação, a evidência mínima é:

- workflow concluído com `ABANDONED_CART_CRON_VERIFIED`;
- log persistente em `shared/logs/abandoned-cart-email.log` com `Enviados`, `Falhas`, `Candidatos`, `Marcados recuperados` e `Ignorados por recuperacao`;
- `recovery_email_sent_at` preenchido somente quando `send_email()` retorna sucesso e a linha continua elegível;
- `recovered_at` preenchido para abandonos associados a pedidos comprovadamente pagos/fulfillment;
- `recovery_token_hash` preenchido sem token puro persistido;
- link do e-mail usando `/recuperar-carrinho.php#token=` e nunca query string;
- restauração retornando apenas itens disponíveis do catálogo corrente;
- nenhum envio duplicado para o mesmo abandono.

## Teste de regressão

Execute:

```bash
php tests/abandoned-cart-paid-state-test.php
php tests/abandoned-cart-cross-device-test.php
```

O segundo teste garante que o token é hasheado, não usa query string, o endpoint revalida o catálogo, a rota do e-mail existe fisicamente e a resposta não expõe e-mail.