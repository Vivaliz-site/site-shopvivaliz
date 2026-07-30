# Configuração segura de Webhooks Tiny/Olist

## Endpoints

Use endpoints sem credencial fixa no código e injete a assinatura pelo secret `TINY_WEBHOOK_TOKEN` ou pelo nome canônico definido no mapa de integrações.

Exemplos:

```text
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=product
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=price
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=${TINY_WEBHOOK_TOKEN}&type=order
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=${TINY_WEBHOOK_TOKEN}&type=tracking
https://shopvivaliz.com.br/api/webhooks/tiny-nota-fiscal.php
```

## Regras obrigatórias

- Nunca registrar a URL final com token preenchido em Git, logs, issues ou documentação.
- Validar assinatura antes de processar o evento.
- Aplicar comparação em tempo constante quando possível.
- Rejeitar token ausente ou inválido com resposta não detalhada.
- Limitar taxa e tamanho do payload.
- Registrar somente evento, horário, código HTTP e identificador não sensível.
- Manter sincronização periódica como fallback controlado.

## Incidente

Uma versão anterior deste documento continha um token preenchido em URLs. O valor foi removido da árvore atual e deve ser considerado comprometido. A rotação no provedor/servidor continua obrigatória.

## Validação

1. Cadastre a URL usando o valor obtido diretamente do gerenciador de secrets.
2. Envie notificação de teste.
3. Confirme HTTP 2xx e processamento idempotente.
4. Confirme que nenhum log contém query string autenticada.
5. Teste token incorreto e ausência de token.
