# GitHub Secrets pendentes (CI/CD do backend Medusa)

Esta sessão não tem acesso ao `gh` CLI nem a uma ferramenta MCP de secrets do
GitHub (apenas leitura/escrita de conteúdo de repositório, issues e PRs), então
os secrets abaixo **não puderam ser configurados automaticamente**. Configure-os
manualmente em `Settings > Secrets and variables > Actions` do repositório, ou
rode os comandos `gh secret set` listados quando tiver o CLI autenticado.

Os valores de **desenvolvimento** já estão em
`claude/medusa/apps/backend/.env` (gitignored, não commitado). **Não reutilize
os valores de dev em produção** - gere novos secretos para produção com
`openssl rand -base64 32`.

## Secrets a configurar

```bash
# Banco de dados de produção (BLOCKER - ver DEPLOY_CHECKLIST.md item 1)
gh secret set DATABASE_URL --body "<connection string do Postgres gerenciado>"

# Segurança (gerar novos valores, não usar os de dev)
gh secret set JWT_SECRET --body "$(openssl rand -base64 32)"
gh secret set COOKIE_SECRET --body "$(openssl rand -base64 32)"

# Pagamentos (test keys usadas nesta sessão; trocar por chaves live antes do go-live)
gh secret set STRIPE_API_KEY --body "sk_test_4eC39HqLyjWDarhtXpEJf4e"
gh secret set STRIPE_PUBLIC_KEY --body "pk_test_4eC39HqLyjWDarhtXpEJf4e"
gh secret set STRIPE_WEBHOOK_SECRET --body "<obter no dashboard do Stripe ao registrar o endpoint /webhooks/stripe>"

# PayPal (credenciais de teste ainda não geradas - criar em
# https://developer.paypal.com/dashboard/applications/sandbox)
gh secret set PAYPAL_CLIENT_ID --body "<pendente>"
gh secret set PAYPAL_CLIENT_SECRET --body "<pendente>"

# Olist ERP (já usado pelo site PHP legado - reaproveitar os mesmos valores)
gh secret set OLIST_CLIENT_ID --body "<mesmo valor já usado em claude/api/olist/*.php>"
gh secret set OLIST_CLIENT_SECRET --body "<idem>"
gh secret set OLIST_WEBHOOK_SECRET --body "$(openssl rand -base64 32)"

# Bridge Medusa <-> EHA (ver claude/api/medusa-webhook.php e
# src/subscribers/eha-webhook.ts)
gh secret set EHA_WEBHOOK_URL --body "https://shopvivaliz.com.br/claude/api/medusa-webhook.php"
gh secret set EHA_WEBHOOK_SECRET --body "$(openssl rand -base64 32)"
```

## Observação

O `OLIST_WEBHOOK_SECRET` deve ser o **mesmo valor** em três lugares:
1. Secret do GitHub / `.env` de produção do backend Medusa
   (`src/api/webhooks/olist/route.ts` valida a assinatura recebida)
2. `.env` de produção do site PHP (usado por
   `claude/api/sync-olist-products.php` se ele também enviar assinatura)
3. Configuração do webhook do lado da Olist/Tiny, se a API deles suportar
   assinatura de payload (verificar na documentação da Olist)
