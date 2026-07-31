# Integrações de marketplaces

## Segurança

Este guia não contém credenciais. Shopee, TikTok e Olist devem usar somente secrets protegidos e autorização oficial do provedor.

## Shopee

Secrets necessários:

```text
SHOPEE_PARTNER_ID=<SECRET_PROTEGIDO>
SHOPEE_PARTNER_KEY=<SECRET_PROTEGIDO>
SHOPEE_ACCESS_TOKEN=<SECRET_PROTEGIDO>
SHOPEE_REFRESH_TOKEN=<SECRET_PROTEGIDO>
SHOPEE_SHOP_ID=<SECRET_PROTEGIDO>
```

A execução de produção é exclusivamente manual pelo workflow `shopee-production-seo.yml`, usando o environment `shopee-production` e evidência obrigatória.

## TikTok Shop

Secrets necessários:

```text
TIKTOK_APP_KEY=<SECRET_PROTEGIDO>
TIKTOK_APP_SECRET=<SECRET_PROTEGIDO>
TIKTOK_ACCESS_TOKEN=<SECRET_PROTEGIDO>
TIKTOK_REFRESH_TOKEN=<SECRET_PROTEGIDO>
TIKTOK_SHOP_ID=<SECRET_PROTEGIDO>
```

## Olist

Secrets necessários:

```text
OLIST_CLIENT_ID=<SECRET_PROTEGIDO>
OLIST_CLIENT_SECRET=<SECRET_PROTEGIDO>
OLIST_REFRESH_TOKEN=<SECRET_PROTEGIDO>
OLIST_WEBHOOK_TOKEN=<SECRET_PROTEGIDO>
```

## Critério de prontidão

Uma integração somente está pronta quando uma execução real fornece:

- exit code zero;
- request ID redigido;
- contagens de leitura e alteração;
- read-back do provedor;
- artifact ligado ao run e commit;
- rollback ou backup quando houver mutação.

Credenciais anteriormente versionadas devem ser revogadas e rotacionadas. Nenhuma afirmação histórica substitui essa ação externa.
