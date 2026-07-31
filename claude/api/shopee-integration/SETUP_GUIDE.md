# Shopee API — configuração segura

## Pré-requisitos

- aplicação cadastrada no portal oficial;
- redirect URI validada;
- environment `shopee-production` protegido;
- secrets `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_ACCESS_TOKEN` ou `SHOPEE_REFRESH_TOKEN`, e `SHOPEE_SHOP_ID`.

## Configuração

Use o GitHub Secrets de forma interativa:

```bash
gh secret set SHOPEE_PARTNER_ID
gh secret set SHOPEE_PARTNER_KEY
gh secret set SHOPEE_REFRESH_TOKEN
gh secret set SHOPEE_SHOP_ID
```

Não inclua valores em arquivos, URLs, argumentos de linha de comando, logs ou screenshots.

## Execução

A aplicação de SEO em produção deve ser iniciada manualmente no workflow `Shopee SEO Production Apply`. O run deve falhar quando faltar secret, relatório, backup ou read-back.

Credenciais e tokens anteriormente presentes neste guia devem ser revogados e rotacionados no provedor.
