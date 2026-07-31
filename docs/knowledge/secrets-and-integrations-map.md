# Mapa Canônico de Secrets e Integrações

Este documento define nomes canônicos de secrets, aliases legados aceitos e regra de limpeza para evitar tokens duplicados.

## Regra obrigatória

- Antes de criar qualquer secret novo, verificar este mapa.
- Usar apenas o nome canônico quando criar workflows, scripts ou documentação nova.
- Aliases existem somente para compatibilidade com código legado.
- Quando um arquivo for tocado por manutenção, migrar para o nome canônico se for seguro.
- Nunca registrar valores reais de secrets neste repositório.
- GitHub Secrets são write-only: agentes não devem tentar ler valores reais.

## Convenção

| Tipo | Padrão |
|---|---|
| Cliente OAuth | `<INTEGRACAO>_CLIENT_ID`, `<INTEGRACAO>_CLIENT_SECRET` |
| Token de acesso | `<INTEGRACAO>_ACCESS_TOKEN` |
| Token de renovação | `<INTEGRACAO>_REFRESH_TOKEN` |
| API key simples | `<INTEGRACAO>_API_KEY` |
| IDs de loja/conta | `<INTEGRACAO>_SHOP_ID`, `<INTEGRACAO>_SELLER_ID`, `<INTEGRACAO>_ACCOUNT_ID` |
| URL base | `<INTEGRACAO>_API_BASE_URL` ou `<INTEGRACAO>_ENDPOINT` |

## Olist e Tiny

Olist e Tiny fazem parte do mesmo ecossistema empresarial, mas podem expor APIs, endpoints e credenciais diferentes dependendo do fluxo. Para evitar duplicidade:

### Regra canônica

- Para fluxos Olist/ERP Marketplace, usar `OLIST_*` como canônico.
- Para fluxos que chamam endpoint Tiny nativo diretamente, usar `TINY_*` apenas quando a API exigir credencial Tiny própria.
- Não criar `TOKEN_API_OLIST`, `CLIENT_ID_API_OLIST` ou variações novas em arquivos novos.
- Código novo deve importar nomes canônicos do centralizador de secrets.

### Secrets canônicos Olist

| Canônico | Uso |
|---|---|
| `OLIST_CLIENT_ID` | OAuth/client id Olist |
| `OLIST_CLIENT_SECRET` | OAuth/client secret Olist |
| `OLIST_ACCESS_TOKEN` | Access token Olist |
| `OLIST_REFRESH_TOKEN` | Refresh token Olist |
| `OLIST_REDIRECT_URI` | Redirect URI OAuth |
| `OLIST_API_BASE_URL` | Base URL, quando aplicável |

### Aliases legados aceitos temporariamente

| Alias legado | Mapear para | Status |
|---|---|---|
| `TOKEN_API_OLIST` | `OLIST_ACCESS_TOKEN` ou `OLIST_API_KEY`, conforme uso do script legado | centralizado em `config/secrets.py` |
| `CLIENT_ID_API_OLIST` | `OLIST_CLIENT_ID` | centralizado em `config/secrets.py` |
| `CLIENT_SECRET_OLIST` | `OLIST_CLIENT_SECRET` | centralizado em `config/secrets.py` |
| `URL_REDIRCT_OLIST` | `OLIST_REDIRECT_URI` | centralizado em `config/secrets.py` |
| `URL_TINY_OLIST` | `OLIST_API_BASE_URL` ou endpoint específico do script | centralizado em `config/secrets.py` |

### Secrets canônicos Tiny

| Canônico | Uso |
|---|---|
| `TINY_CLIENT_ID` | OAuth/client id Tiny nativo |
| `TINY_CLIENT_SECRET` | OAuth/client secret Tiny nativo |
| `TINY_ACCESS_TOKEN` | Access token Tiny nativo |
| `TINY_REFRESH_TOKEN` | Refresh token Tiny nativo |
| `TINY_API_BASE_URL` | Base URL Tiny nativa, quando aplicável |

## Shopee

| Canônico | Uso | Observação |
|---|---|---|
| `SHOPEE_PARTNER_ID` | Partner ID produção | Obrigatório |
| `SHOPEE_PARTNER_KEY` | Partner key produção | Obrigatório |
| `SHOPEE_SHOP_ID` | Loja | Obrigatório |
| `SHOPEE_ACCESS_TOKEN` | Access token | Pode ser renovado automaticamente se houver refresh token |
| `SHOPEE_REFRESH_TOKEN` | Refresh token | Usado para renovação automática |
| `SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS` | Intervalo preventivo | Padrão 7200 segundos, 2 horas |
| `SHOPEE_API_BASE_URL` ou `SHOPEE_BASE_URL` | Endpoint | Padronizar novos fluxos em `SHOPEE_API_BASE_URL`; `SHOPEE_BASE_URL` é aceito por compatibilidade |

### Estrutura canônica Shopee

- Implementações de marketplace Shopee: `scripts/marketplace/shopee/`.
- Cliente compartilhado Shopee: `scripts/utils/shopee_client.py`.
- Wrappers legados temporários: `scripts/shopee_production_seo_apply.py` e `scripts/shopee_full_catalog_optimizer.py`.

Ambiente de teste:

| Canônico teste | Uso |
|---|---|
| `SHOPEE_TEST_PARTNER_ID` | Partner ID sandbox |
| `SHOPEE_TEST_PARTNER_KEY` | Partner key sandbox |

## Mercado Livre

| Canônico | Uso |
|---|---|
| `ML_CLIENT_ID` | OAuth ML |
| `ML_CLIENT_SECRET` | OAuth ML |
| `ML_REDIRECT_URI` | Redirect OAuth |
| `ML_SELLER_ID` | Seller ID |
| `ML_ACCESS_TOKEN` | Access token, quando aplicável |
| `ML_REFRESH_TOKEN` | Refresh token, quando aplicável |
| `ML_WEBHOOK_URL` | Webhooks |

## Amazon SP-API

| Canônico | Uso |
|---|---|
| `AMAZON_LWA_CLIENT_ID` | Login With Amazon client id |
| `AMAZON_LWA_CLIENT_SECRET` | Login With Amazon client secret |
| `AMAZON_LWA_REFRESH_TOKEN` | Refresh token |
| `AMAZON_LWA_ACCESS_TOKEN` | Access token temporário, se usado |
| `AMAZON_AWS_ACCESS_KEY_ID` | AWS access key |
| `AMAZON_AWS_SECRET_ACCESS_KEY` | AWS secret key |
| `AMAZON_AWS_ROLE_ARN` | Role ARN |
| `AMAZON_SP_API_REGION` | Região SP-API |
| `AMAZON_SP_API_ENDPOINT` | Endpoint SP-API |
| `AMAZON_ACCOUNT_ID` | Conta/seller/account |

## TikTok Shop

| Canônico | Uso |
|---|---|
| `TIKTOK_SERVICE_ID` | Service id |
| `TIKTOK_APP_KEY` | App key |
| `TIKTOK_APP_SECRET` | App secret |
| `TIKTOK_AUTH_REGION` | Região de autenticação |
| `TIKTOK_REDIRECT_URL` | Redirect OAuth |
| `TIKTOK_ACCESS_TOKEN` | Access token |
| `TIKTOK_REFRESH_TOKEN` | Refresh token |
| `TIKTOK_SHOP_CIPHER` | Shop cipher |
| `TIKTOK_SHOP_ID` | Shop id |

## SMTP / Email

Canônicos preferidos:

| Canônico | Uso |
|---|---|
| `SMTP_HOST` | Host SMTP |
| `SMTP_PORT` | Porta SMTP |
| `SMTP_USER` | Usuário SMTP |
| `SMTP_PASS` | Senha SMTP |
| `EMAIL_FROM` | Remetente |
| `EMAIL_TO` | Destinatário padrão |

Aliases aceitos:

| Alias | Mapear para |
|---|---|
| `EMAIL_SMTP_HOST` | `SMTP_HOST` |
| `EMAIL_SMTP_PORT` | `SMTP_PORT` |
| `EMAIL_USER` | `SMTP_USER` |
| `EMAIL_PASSWORD` | `SMTP_PASS` |
| `MAIL_HOST` | `SMTP_HOST` |
| `MAIL_PORT` | `SMTP_PORT` |
| `MAIL_USER` | `SMTP_USER` |
| `MAIL_PASS` | `SMTP_PASS` |

## FTP / Deploy legado

Canônicos:

| Canônico | Uso |
|---|---|
| `FTP_SERVER` | Host FTP |
| `FTP_USERNAME` | Usuário FTP |
| `FTP_PASSWORD` | Senha FTP |
| `FTP_PORT` | Porta |
| `FTP_REMOTE_DIR` | Diretório remoto |

Aliases aceitos:

| Alias | Mapear para |
|---|---|
| `FTP_HOST` | `FTP_SERVER` |
| `FTP_USER` | `FTP_USERNAME` |
| `FTP_PASS` | `FTP_PASSWORD` |
| `FTP_REMOTE_PATH` | `FTP_REMOTE_DIR` |

## Banco de dados

Canônicos:

| Canônico | Uso |
|---|---|
| `DB_HOST` | Host |
| `DB_PORT` | Porta |
| `DB_NAME` | Nome do banco |
| `DB_USER` | Usuário |
| `DB_PASS` | Senha |

Aliases:

| Alias | Mapear para |
|---|---|
| `DB_DATABASE` | `DB_NAME` |
| `DB_USERNAME` | `DB_USER` |
| `DB_PASSWORD` | `DB_PASS` |

## Melhor Envio

| Canônico | Uso |
|---|---|
| `MELHORENVIO_ACCESS_TOKEN` | Token principal |
| `MELHORENVIO_FROM_POSTAL_CODE` | CEP origem |

Alias aceito temporariamente: `MELHORENVIO_API_KEY` para `MELHORENVIO_ACCESS_TOKEN` quando o script legado usar API key.

## Regra de limpeza contínua

1. Não remover alias antigo sem procurar usos no repositório.
2. Migrar primeiro código novo para canônico.
3. Migrar scripts antigos aos poucos.
4. Remover alias apenas quando não houver uso em workflows, scripts, `.env.example`, docs e deploy.
5. Toda mudança de secret deve registrar impacto, arquivos alterados e plano de rollback no PR.
