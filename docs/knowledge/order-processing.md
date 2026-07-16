# Processamento Seguro de Pedidos

## Idempotência atômica

A criação de pedido usa arquivo de lock criado em modo exclusivo. Duas requisições concorrentes com a mesma chave não podem adquirir o mesmo lock.

Locks expiram após 15 minutos e são removidos automaticamente durante novas tentativas. O cliente recebe uma chave por sessão de checkout e o servidor também consegue derivar uma chave quando necessário.

## Rate limit e proxy

O endereço informado em `X-Forwarded-For` só é considerado quando `SHOPVIVALIZ_TRUST_PROXY=true`. Sem essa configuração, o sistema usa `REMOTE_ADDR`, evitando que o cliente contorne o limite enviando um cabeçalho falso.

## Endpoints

- `/api/orders/security-health.php`
- `/api/orders/idempotency-health.php`

O segundo endpoint confirma disponibilidade do armazenamento de locks, criação atômica, limpeza e mecanismo de liberação.

## Frontend

`checkout-idempotency-v122.js` cria e preserva uma chave na sessão do checkout. Uma nova chave deve ser criada após a conclusão confirmada do pedido.

## Validação

```bash
php scripts/quality/validate-order-processing.php
bash tests/storefront-smoke.sh
```

Os health checks confirmam a presença dos mecanismos. Testes de concorrência em homologação continuam necessários para validar o comportamento do ambiente real.
