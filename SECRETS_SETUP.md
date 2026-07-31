# Inventário seguro de secrets

Este arquivo lista apenas nomes canônicos. Valores reais pertencem exclusivamente ao GitHub Secrets ou ao environment protegido correspondente.

## IA

- `OPENAI_API_KEY`
- `OPENAI_MODEL`

## Shopee

- `SHOPEE_PARTNER_ID`
- `SHOPEE_PARTNER_KEY`
- `SHOPEE_ACCESS_TOKEN`
- `SHOPEE_REFRESH_TOKEN`
- `SHOPEE_SHOP_ID`

## TikTok Shop

- `TIKTOK_APP_KEY`
- `TIKTOK_APP_SECRET`
- `TIKTOK_ACCESS_TOKEN`
- `TIKTOK_REFRESH_TOKEN`
- `TIKTOK_SHOP_ID`

## Olist

- `OLIST_CLIENT_ID`
- `OLIST_CLIENT_SECRET`
- `OLIST_REFRESH_TOKEN`
- `OLIST_WEBHOOK_TOKEN`

## SMTP e FTP

- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USER`
- `SMTP_PASS`
- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

## Exemplo permitido

```text
SHOPEE_PARTNER_KEY=<SECRET_PROTEGIDO>
TIKTOK_APP_SECRET=<SECRET_PROTEGIDO>
```

Qualquer valor anteriormente presente neste documento deve ser considerado comprometido e rotacionado no provedor. A configuração não é considerada validada até existir execução real com read-back e artifact imutável.
