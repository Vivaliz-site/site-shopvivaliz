# Shopee API Integration

## Estado

Os utilitários legados que continham credenciais foram aposentados e agora terminam em estado `blocked`. Use apenas os clientes e workflows canônicos do repositório.

## Secrets necessários

```text
SHOPEE_PARTNER_ID=<SECRET_PROTEGIDO>
SHOPEE_PARTNER_KEY=<SECRET_PROTEGIDO>
SHOPEE_ACCESS_TOKEN=<SECRET_PROTEGIDO>
SHOPEE_REFRESH_TOKEN=<SECRET_PROTEGIDO>
SHOPEE_SHOP_ID=<SECRET_PROTEGIDO>
```

## Fluxo autorizado

1. Autorize a aplicação pela interface oficial do provedor.
2. Valide callback, state e expiração.
3. Armazene tokens somente no environment protegido.
4. Execute primeiro em modo de leitura ou canário.
5. Registre request ID redigido, contagens e read-back em artifact.

Credenciais anteriormente publicadas nesta pasta devem ser consideradas comprometidas e rotacionadas. Este documento não comprova que a integração está operacional.
