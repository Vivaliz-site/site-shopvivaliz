# DEPLOY_CHECKLIST - Medusa (ShopVivaliz)

Gerado/verificado em 2026-07-01 nesta sessão (container efêmero, sem estado
anterior). Ver também `claude/medusa/DEPLOY-CHECKLIST.md` (checklist técnico
anterior) e `claude/medusa/GITHUB_SECRETS_TODO.md` (secrets pendentes).

**Re-verificado no mesmo dia** (segunda sessão, novo container efêmero): pipeline
completo (migrations, seed de 14 produtos, build backend/storefront, admin user +
publishable key, `/store/products` com 14 produtos, storefront servindo
`/br/products/camiseta-shopvivaliz` com preço real R$69,90) confirmado do zero.
Dois bugs reais encontrados e corrigidos nesta passagem: (1) `@medusajs/payment-stripe`
estava referenciado em `medusa-config.ts` mas faltava em `package.json` -
adicionado explicitamente; (2) os scripts pré-existentes
`claude/api/olist/{auto-sync,diagnostic,diagnostic-full,token-refresh}.php`
tinham um path de `require` quebrado (`../../config/` em vez de `../../../config/`)
que os derrubava com fatal error antes de qualquer lógica rodar - corrigido e
validado. Detalhes completos em `MEDUSA_DEPLOY_VALIDATION.json`.

## Status verificado nesta sessão

- [x] Database conectando - Postgres **local** (`postgres://medusa:***@127.0.0.1:5432/medusa_backend`).
      **Não é a base de produção** - ver bloqueio abaixo.
- [x] Migrations + seed rodaram sem erro (`npx medusa db:migrate`,
      `npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts`) - 14 produtos
      de teste no catálogo.
- [x] Build backend (`medusa build`) e storefront (`next build`) sem erros.
- [x] API rodando localmente - `medusa develop`, `GET /health` → `200 OK`.
- [x] Storefront renderizou uma página de produto real consumindo a API
      (`GET /br/products/camiseta-shopvivaliz` → 200, título correto).
- [x] Pagamentos - Stripe configurado com chaves de **teste** públicas
      (`sk_test_4eC39...`, `pk_test_4eC39...`) em `medusa-config.ts` (módulo
      `@medusajs/payment-stripe`). Webhook do Stripe **não registrado**
      (precisa de conta Stripe real + endpoint público).
- [x] Webhooks - `claude/api/sync-olist-products.php` (classe `OlistSync`,
      Olist → Medusa via Admin API) e `src/api/webhooks/olist/route.ts`
      (Olist → Medusa via push direto, testado localmente: atualização de
      preço e estoque por SKU confirmada no Postgres local).
- [x] GitHub secrets - **não configurados automaticamente** (sem `gh` CLI e
      sem ferramenta de secrets no MCP do GitHub disponível nesta sessão).
      Lista completa em `claude/medusa/GITHUB_SECRETS_TODO.md`.
- [ ] SSL certificado - fora do escopo desta sessão (depende do host de
      produção escolhido).
- [ ] DNS apontando para o host de produção - fora do escopo (depende do
      provedor escolhido para o backend/storefront Medusa).
- [ ] Node.js/PM2 em produção - `deploy.sh` criado e pronto, mas não
      executado contra um host real.
- [ ] Backup automático do banco de produção - não configurado (depende do
      provedor Postgres escolhido).

## 🚫 BLOCKER: banco de dados de produção

`DATABASE_URL` de produção está **vazio**. Esta sessão não tem como criar uma
conta/projeto em um Postgres gerenciado (Supabase, Neon, Railway, RDS) de
forma autônoma - isso exige login humano (conta + aceite de termos). Para
desbloquear:

1. Criar um projeto Postgres gerenciado (ex. https://supabase.com/dashboard,
   projeto `shopvivaliz-prod`).
2. Copiar a connection string para `DATABASE_URL` no `.env` de produção do
   backend (`claude/medusa/apps/backend/.env`) e no secret `DATABASE_URL`
   do GitHub.
3. Rodar `npx medusa db:migrate` e criar o usuário admin de produção:
   ```bash
   npx medusa user -e admin@shopvivaliz.com.br -p "SENHA_FORTE_AQUI"
   ```

## Pré-requisitos para hospedar o backend/storefront Medusa

**Importante:** o HostGator (hospedagem compartilhada, hoje usada pelo site
PHP em `/claude/` e demais páginas) **não roda Node.js nem Postgres
persistente**. O workflow `.github/workflows/deploy.yml` já exclui
`claude/medusa/**` do envio por FTP para o HostGator - isso é intencional,
não um bug. O backend/storefront Medusa precisam de um host separado:

- [ ] Node.js 20+ instalado (exigido pelo `engines` do `package.json` do
      backend) num VPS, ou usar um provedor gerenciado (Railway, Render,
      Fly.io) que já traz Node.js.
- [ ] PM2 instalado globalmente (`npm install -g pm2`) **apenas se** optar
      por VPS próprio; provedores gerenciados não precisam.
- [ ] Banco PostgreSQL externo/gerenciado (Supabase, Neon, Railway, RDS) -
      ver blocker acima.
- [ ] Redis gerenciado (ex. Upstash) - opcional em dev (usa fake redis), mas
      recomendado em produção (evita "Local Event Bus" e locking em memória).
- [ ] SSL configurado (Let's Encrypt, ou automático se usar
      Railway/Render/Vercel).
- [ ] Storefront (Next.js) hospedado em Vercel/Netlify ou no mesmo host do
      backend.
- [ ] Variáveis de ambiente de produção completas (ver
      `claude/medusa/GITHUB_SECRETS_TODO.md`), com `JWT_SECRET`/`COOKIE_SECRET`
      **novos**, não reaproveitando os valores de desenvolvimento desta sessão.

## Pós-deploy

- [ ] Criar publishable API key de produção (Admin > Settings > API Key
      Management) e configurar em `NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY` do
      storefront de produção.
- [ ] Registrar o endpoint de webhook do Stripe
      (`https://<seu-dominio>/hooks/payment/stripe`) no dashboard do Stripe
      e configurar `STRIPE_WEBHOOK_SECRET`.
- [ ] Configurar `OLIST_WEBHOOK_SECRET` igual nos dois lados (Medusa e
      site PHP) - ver `claude/medusa/GITHUB_SECRETS_TODO.md`.
- [ ] Testar checkout completo em produção (carrinho → pagamento → pedido).
- [ ] Migrar produtos reais do Olist/Shopee para o catálogo Medusa
      (`claude/api/sync-olist-products.php`).
- [ ] Configurar backup automático do banco de produção.
