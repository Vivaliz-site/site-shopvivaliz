# GitHub Secrets pendentes (CI/CD do backend Medusa)

Esta sessão não tem acesso ao `gh` CLI nem a uma ferramenta MCP de secrets do
GitHub (apenas leitura/escrita de conteúdo de repositório, issues e PRs), então
os secrets abaixo **não puderam ser configurados automaticamente**. Configure-os
manualmente em `Settings > Secrets and variables > Actions` do repositório, ou
rode os comandos `gh secret set` abaixo (com os valores reais) quando tiver o
CLI autenticado localmente.

**Nunca reutilize valores de desenvolvimento em produção** — gere novos
secrets para produção com `openssl rand -base64 32`.

## Secrets a configurar

```bash
# Banco de dados de produção (BLOCKER - ver DEPLOY-CHECKLIST.md item 1)
gh secret set DATABASE_URL --body "<connection string do Postgres gerenciado>"

# Segurança (gerar novos valores, não usar os de dev)
gh secret set JWT_SECRET --body "$(openssl rand -base64 32)"
gh secret set COOKIE_SECRET --body "$(openssl rand -base64 32)"

# Pagamentos (obter chaves reais em https://dashboard.stripe.com/test/apikeys,
# trocar por chaves live antes do go-live)
gh secret set STRIPE_API_KEY --body "<sk_test_... ou sk_live_...>"
gh secret set STRIPE_PUBLIC_KEY --body "<pk_test_... ou pk_live_...>"
gh secret set STRIPE_WEBHOOK_SECRET --body "<obter no dashboard do Stripe ao registrar o endpoint /webhooks/stripe>"

# PayPal (credenciais de teste ainda não geradas - criar em
# https://developer.paypal.com/dashboard/applications/sandbox)
gh secret set PAYPAL_CLIENT_ID --body "<pendente>"
gh secret set PAYPAL_CLIENT_SECRET --body "<pendente>"

# Olist ERP (já usado pelo site PHP legado - reaproveitar os mesmos valores,
# conforme valores autorizados)
gh secret set OLIST_CLIENT_ID --body "<client id autorizado>"
gh secret set OLIST_CLIENT_SECRET --body "<client secret autorizado>"
gh secret set OLIST_WEBHOOK_SECRET --body "$(openssl rand -base64 32)"

# Bridge Medusa <-> EHA (ver claude/api/medusa-webhook.php e
# src/subscribers/eha-webhook.ts)
gh secret set EHA_WEBHOOK_URL --body "https://shopvivaliz.com.br/claude/api/medusa-webhook.php"
gh secret set EHA_WEBHOOK_SECRET --body "$(openssl rand -base64 32)"
```

## Observação sobre OLIST_WEBHOOK_SECRET

O `OLIST_WEBHOOK_SECRET` deve ser o **mesmo valor** em três lugares:
1. Secret do GitHub / `.env` de produção do backend Medusa
   (`src/api/webhooks/olist/route.ts` valida a assinatura recebida)
2. `.env` de produção do site PHP (usado por
   `claude/api/sync-olist-products.php` se ele também enviar assinatura)
3. Configuração do webhook do lado da Olist/Tiny, se a API deles suportar
   assinatura de payload (verificar na documentação da Olist)

