# E-mail de carrinho abandonado

## Como funciona

1. `checkout.php` captura o e-mail de forma fire-and-forget quando o cliente sai do campo e envia para `api/checkout/track-abandonment.php`.
2. O endpoint faz upsert em `checkout_abandonments`, grava apenas os dados necessários para a recuperação e não recebe dados de pagamento.
3. `scripts/send-abandoned-cart-emails.php` procura abandonos elegíveis e envia uma única mensagem de recuperação para quem:
   - informou e-mail há mais de 1 hora e menos de 48 horas;
   - ainda não recebeu e-mail de recuperação;
   - não está marcado como recuperado;
   - ainda não possui um pedido com pagamento aprovado ou etapa posterior de fulfillment depois do abandono.
4. Pedido apenas criado, `aguardando_pagamento`, falha ou expiração de pagamento **não** encerra a recuperação. Essa distinção evita perder exatamente o cliente que chegou ao pagamento e não concluiu.
5. Antes de selecionar candidatos, o cron reconcilia `recovered_at` contra o espelho canônico de `orders`, usando os estados definidos em `src/Commerce/AbandonedCartRecovery.php`. Isso também autorrepara marcações perdidas por falhas anteriores de webhook.
6. O e-mail atual é transacional e **não promete cupom nem desconto adicional**. A regra comercial vigente deve continuar sendo a fonte de verdade para qualquer benefício.

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

Não use o caminho legado `/home/ubuntu/site-shopvivaliz`: ele antecede a migração para releases imutáveis e não representa o release ativo.

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
- nenhum envio duplicado para o mesmo abandono.

## Próxima melhoria

A recuperação atual devolve o cliente para `/carrinho`, portanto funciona melhor quando o link é aberto no mesmo navegador que ainda possui o carrinho local. Uma recuperação cross-device deve usar um token opaco de restauração e dados de carrinho mínimos validados no servidor; não deve expor e-mail, preço confiado pelo cliente ou identificadores sensíveis na URL.
