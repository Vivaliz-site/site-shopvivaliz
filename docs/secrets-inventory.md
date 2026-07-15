# Secrets Inventory

Mapa consolidado dos secrets existentes no projeto ShopVivaliz.
Este documento agrupa nomes canônicos, aliases aceitos no código e uso principal.

Nao registrar valores reais aqui.

## IA

- Canônicos:
  - `OPENAI_API_KEY`
  - `ANTHROPIC_API_KEY`
  - `GEMINI_API_KEY`
- Uso:
  - agentes, automações, geração, validações e integrações AI
- Referências:
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:97)
  - [config/constants.php](C:/site-shopvivaliz/config/constants.php:77)

## Banco de Dados

- Canônicos:
  - `DB_HOST`
  - `DB_PORT`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
- Aliases aceitos:
  - `DB_DATABASE` -> `DB_NAME`
  - `DB_USERNAME` -> `DB_USER`
  - `DB_PASSWORD` -> `DB_PASS`
- Uso:
  - storefront, APIs, sincronizações e utilitários CLI
- Referências:
  - [config/constants.php](C:/site-shopvivaliz/config/constants.php:69)
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:219)

## Email / SMTP

- Canônicos preferidos:
  - `SMTP_HOST`
  - `SMTP_PORT`
  - `SMTP_USER`
  - `SMTP_PASS`
  - `EMAIL_FROM`
  - `EMAIL_TO`
- Aliases aceitos:
  - `EMAIL_SMTP_HOST`
  - `EMAIL_SMTP_PORT`
  - `EMAIL_USER`
  - `EMAIL_PASSWORD`
  - `MAIL_HOST`
  - `MAIL_PORT`
  - `MAIL_USER`
  - `MAIL_PASS`
  - `EMAIL_REPLY_TO`
- Uso:
  - relatórios, notificações, cron de email e mailer PHP
- Referências:
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:173)
  - [config/constants.php](C:/site-shopvivaliz/config/constants.php:127)
  - [docs/email-secrets-aliases.md](C:/site-shopvivaliz/docs/email-secrets-aliases.md:1)

## FTP / Deploy legado

- Canônicos preferidos:
  - `FTP_SERVER`
  - `FTP_USERNAME`
  - `FTP_PASSWORD`
  - `FTP_PORT`
  - `FTP_REMOTE_DIR`
- Aliases aceitos:
  - `FTP_HOST` -> `FTP_SERVER`
  - `FTP_USER` -> `FTP_USERNAME`
  - `FTP_PASS` -> `FTP_PASSWORD`
  - `FTP_REMOTE_PATH` aparece em config antiga
- Uso:
  - workflow FTP legado, upload de imagens, scripts auxiliares
- Referências:
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:161)
  - [config/constants.php](C:/site-shopvivaliz/config/constants.php:142)

## Oracle VM / Ubuntu / SSH

- Canônicos:
  - `ORACLE_VM_HOST`
  - `ORACLE_VM_USER`
  - `ORACLE_VM_SSH_KEY`
- Outros nomes presentes:
  - `UBUNTU_HOST`
  - `UBUNTU_USER`
  - `SSH_HOST`
  - `SSH_USER`
  - `SSH_KEY_PATH`
- Uso:
  - deploy real na VM Oracle, sincronização remota e workflows SSH
- Referências:
  - [.github/workflows/master-production-pipeline.yml](C:/site-shopvivaliz/.github/workflows/master-production-pipeline.yml:218)

## Shopee

- Canônicos:
  - `SHOPEE_PARTNER_ID`
  - `SHOPEE_PARTNER_KEY`
  - `SHOPEE_SHOP_ID`
  - `SHOPEE_ACCESS_TOKEN`
  - `SHOPEE_REFRESH_TOKEN`
  - `SHOPEE_AUTH_CODE`
- Sandbox / teste:
  - `SHOPEE_TEST_PARTNER_ID`
  - `SHOPEE_TEST_PARTNER_KEY`
  - `SHOPEE_TEST_API_KEY`
  - `SHOPEE_SANDBOX_USER`
  - `SHOPEE_SANDBOX_PASS`
- Uso:
  - catálogo, autenticação e integrações Shopee
- Referências:
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:104)
  - [scripts/utils/shopee_client.py](C:/site-shopvivaliz/scripts/utils/shopee_client.py:33)

## Olist / Tiny

- Canônicos Olist:
  - `OLIST_CLIENT_ID`
  - `OLIST_CLIENT_SECRET`
  - `OLIST_ACCESS_TOKEN`
  - `OLIST_REFRESH_TOKEN`
- Legados / compatibilidade:
  - `TOKEN_API_OLIST`
  - `CLIENT_ID_API_OLIST`
  - `CLIENT_SECRET_OLIST`
  - `URL_REDIRCT_OLIST`
  - `URL_TINY_OLIST`
- Tiny paralelos usados por scripts:
  - `TINY_CLIENT_ID`
  - `TINY_CLIENT_SECRET`
  - `TINY_ACCESS_TOKEN`
  - `TINY_REFRESH_TOKEN`
- Uso:
  - sincronização de catálogo, estoque, imagens e OAuth
- Referências:
  - [config/secrets.py](C:/site-shopvivaliz/config/secrets.py:132)
  - [scripts/sync-olist-images.py](C:/site-shopvivaliz/scripts/sync-olist-images.py:395)
  - [scripts/sync-stock-tiny.py](C:/site-shopvivaliz/scripts/sync-stock-tiny.py:57)

## Mercado Livre

- Canônicos:
  - `ML_CLIENT_ID`
  - `ML_CLIENT_SECRET`
  - `ML_REDIRECT_URI`
  - `ML_SELLER_ID`
  - `ML_SHOPVIVALIZ_API_URL`
  - `ML_WEBHOOK_URL`
- Uso:
  - integrações ML e automações de marketplace

## TikTok

- Canônicos:
  - `TIKTOK_SERVICE_ID`
  - `TIKTOK_APP_KEY`
  - `TIKTOK_APP_SECRET`
  - `TIKTOK_AUTH_REGION`
  - `TIKTOK_REDIRECT_URL`
  - `TIKTOK_ACCESS_TOKEN`
  - `TIKTOK_SHOP_CIPHER`
  - `TIKTOK_SHOP_ID`
- Uso:
  - integração TikTok Shop

## Amazon

- Canônicos:
  - `AMAZON_LWA_CLIENT_ID`
  - `AMAZON_LWA_CLIENT_SECRET`
  - `AMAZON_ACCOUNT_ID`
- Também referenciados no código:
  - `AMAZON_LWA_REFRESH_TOKEN`
  - `AMAZON_LWA_ACCESS_TOKEN`
  - `AMAZON_AWS_ACCESS_KEY_ID`
  - `AMAZON_AWS_SECRET_ACCESS_KEY`
  - `AMAZON_AWS_ROLE_ARN`
  - `AMAZON_SP_API_REGION`
  - `AMAZON_SP_API_ENDPOINT`

## Loja / Comercial

- Canônicos:
  - `LOJA_WHATSAPP`
  - `LOJA_PIX_KEY`
  - `LOJA_PIX_NAME`
  - `WHATSAPP_NUMBER`
- Uso:
  - contato comercial, checkout, confiança e comunicação

## Frete / Logística

- Canônicos:
  - `MELHORENVIO_ACCESS_TOKEN`
- Também aceitos em scripts:
  - `MELHORENVIO_API_KEY`
  - `MELHORENVIO_FROM_POSTAL_CODE`

## Analytics / Marketing / Social

- Encontrados:
  - `GOOGLE_ANALYTICS_ID`
  - `GOOGLE_TAG_MANAGER_ID`
  - `GOOGLE_MERCHANT_ID`
  - `GOOGLE_API_KEY`
  - `FACEBOOK_ACCESS_TOKEN`
  - `FACEBOOK_PAGE_ID`
  - `CLOUDFLARE_API_TOKEN`
  - `SLACK_WEBHOOK_URL`

## Administração / Agentes

- Encontrados:
  - `SHOPVIVALIZ_AGENT_KEY`
  - `EMAIL_AGENTES_SECRET`
  - `SQUAD_TOKEN`
  - `OPENAI_ID`
  - `GH_REPO_TOKEN`
  - `ADMIN_EMAIL`
  - `ADMIN_PASSWORD`

## Recomendação de padronização

- Email:
  - manter `SMTP_*` como canônico
- FTP:
  - manter `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PORT`, `FTP_REMOTE_DIR`
- Banco:
  - manter `DB_NAME`, `DB_USER`, `DB_PASS`, com aliases apenas para compatibilidade
- Deploy real:
  - preferir `ORACLE_VM_*` para workflows e documentação operacional
- Olist/Tiny:
  - explicitar sempre quando um script aceita `OLIST_*` ou `TINY_*`
