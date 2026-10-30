# Configuração segura de webhooks Tiny/Olist

## Endpoints

```text
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=product
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=price
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?type=order
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?type=tracking
```

## Autenticação

Não coloque tokens em query strings. Configure o valor rotacionado como `TINY_WEBHOOK_TOKEN` ou `OLIST_WEBHOOK_TOKEN` em secret protegido e envie-o pelo mecanismo de autenticação suportado pelo endpoint, preferencialmente cabeçalho.

## Validação

- teste oficial do provedor;
- timestamp e event ID;
- status HTTP sem cabeçalhos sensíveis;
- idempotência;
- read-back do pedido, estoque ou produto;
- artifact imutável.

O token anteriormente publicado neste documento deve ser considerado comprometido e revogado no provedor. URLs sem evidência de teste não comprovam que o webhook está ativo.
