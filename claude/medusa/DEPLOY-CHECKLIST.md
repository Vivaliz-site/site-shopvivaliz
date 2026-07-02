# Checklist de Deploy - MedusaJS (ShopVivaliz)

## Status atual (ambiente de desenvolvimento)

| Item | Status |
|---|---|
| Backend Medusa (build + migrations + seed) | ✅ OK (Postgres local) |
| Storefront Next.js (build) | ✅ OK |
| Produtos de teste (T-shirt, Jeans, Tênis, Boné, Jaqueta) | ✅ Criados em BRL/USD |
| Cliente de teste | ✅ Criado (`cliente.teste@shopvivaliz.com.br`) |
| Webhook Medusa → EHA | ✅ Testado ponta a ponta |
| Pagamento Stripe/PIX (módulo `@medusajs/payment-stripe`, condicional a `STRIPE_API_KEY`) | ✅ Código validado 2026-07-01 (build + registro do provider confirmado no Postgres), aguardando chaves reais (ver seção 4) |
| Sincronização Olist ⇄ Medusa (`sync-olist-products.php` + webhook `src/api/webhooks/olist/route.ts`) | ✅ Código adicionado e sintaxe validada 2026-07-01, aguardando credenciais Olist/Tiny (ver seção 4) |
| Banco de dados de produção | ⏳ Pendente (ver passo 1) |
| Deploy backend/storefront em produção | ⏳ Pendente (ver passo 2) |

Reverificado em ambiente novo (container efêmero, sem estado anterior): Postgres/Redis
locais provisionados, `npm install` + `npm run build` OK nos dois apps, migrations +
seed (5 produtos incluindo T-shirt, cliente teste) aplicados, backend/storefront
subiram e o storefront renderizou a página do produto a partir da API real, webhook
testado com assinatura válida/inválida/ausente.

**Reverificado novamente em 2026-07-01** (novo container efêmero, `main` sincronizado
com `origin/main`): Postgres 16 + Redis locais provisionados, `npm install` limpo em
ambos os apps (backend: 1341 pacotes / ~23min; storefront: 544 pacotes), `npx medusa
db:migrate` + seed inicial + `seed-shopvivaliz-test-data.ts` aplicados sem erros
(região Brasil/BRL, 5 produtos ShopVivaliz, cliente `cliente.teste@shopvivaliz.com.br`),
usuário admin criado, `npm run build` OK nos dois apps (backend: 4.9s backend + 24.5s
frontend/admin; storefront: 109 páginas estáticas geradas). Publishable API key criada
via Admin API e vinculada ao Default Sales Channel; `GET /store/products` retornou os
9 produtos (4 demo + 5 ShopVivaliz). Storefront em modo produção (`npm run start`, porta
8000) renderizou `/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90).
Webhook Medusa → EHA reverificado ponta a ponta com o backend real rodando: update de
produto via Admin API disparou o subscriber, que fez POST assinado (HMAC-SHA256) para
`medusa-webhook.php`, validado e enfileirado em `tasks-queue.json` (entrada de teste
revertida após a validação para não poluir a fila real).

**Reverificado novamente em 2026-07-01** (terceira rodada, novo container efêmero,
`main` sincronizado com `origin/main` pós force-push que unificou o histórico
EHA/Medusa): Postgres 16 + Redis locais provisionados, `npm install` limpo em
ambos os apps (backend: ~685 pacotes; storefront: 544 pacotes, 2 vulnerabilidades
moderadas pré-existentes no `npm audit`, não investigadas nesta rodada), `npx
medusa db:migrate` + seed inicial + `seed-shopvivaliz-test-data.ts` aplicados sem
erros (região Brasil/BRL, 5 produtos ShopVivaliz + 4 produtos demo = 9 no total,
cliente `cliente.teste@shopvivaliz.com.br`), usuário admin criado, `npm run
build` OK nos dois apps. Publishable API key criada via Admin API e vinculada
ao Default Sales Channel; `GET /store/products` retornou os 9 produtos.
Storefront em modo produção (`npm run start`, porta 8000) renderizou
`/br/products/camiseta-shopvivaliz` com preço real da API (R$69,90). Webhook
Medusa → EHA reverificado ponta a ponta com o backend real rodando: update de
produto via Admin API disparou o subscriber, que fez POST assinado
(HMAC-SHA256) para `medusa-webhook.php` (servidor PHP embutido local), validado
(200) e enfileirado em `tasks-queue.json`; requisições com assinatura ausente/
inválida corretamente rejeitadas com 401. Entradas de teste revertidas
(`git checkout -- tasks-queue.json`, metadata de teste removida do produto)
após a validação para não poluir dados reais.

**Nota:** `npm install` no backend falhava com `ERESOLVE` porque `@medusajs/ui` e
`react-router-dom` estavam pinados em versões incompatíveis com o peer exigido por
`@medusajs/draft-order@2.17.0` (corrigido no `package.json`: `@medusajs/ui@4.1.17`,
`react-router-dom@6.30.4`). Se voltar a acontecer após atualizar `@medusajs/medusa`,
verifique a versão de peer exigida na mensagem de erro do npm e alinhe o `package.json`.

**Validação do módulo de pagamento Stripe/PIX (2026-07-01):** `npm install` +
`npm run build` OK no backend com `STRIPE_API_KEY` ausente (comportamento antigo
preservado, zero regressão) e também com uma chave de teste dummy definida. Para
confirmar que o módulo realmente resolve (o `medusa build` sozinho não faz DI/module
resolution), subimos um Postgres/Redis locais descartáveis, rodamos `npx medusa
db:migrate` e depois `npx medusa develop` com as chaves dummy: o servidor subiu limpo
e a tabela `payment_provider` do Postgres mostrou `pp_stripe_stripe` (e variantes
PIX/OXXO/PromptPay/etc. do Stripe) com `is_enabled = true`, confirmando que
`medusa-config.ts` registra o provider corretamente. Banco/role/`.env` temporários
foram removidos depois do teste. `claude/api/sync-olist-products.php` e
`claude/api/olist/webhook.php` passaram em `php -l` (sem erro de sintaxe); não têm
credenciais Olist reais nesta sessão para testar a chamada de rede em si.

**Reverificado novamente em 2026-07-02** (quarta rodada, novo container
efêmero, `main` sincronizado com `origin/main`): Postgres 16 local
provisionado, `npm install` limpo em ambos os apps (backend: 1341 pacotes/
~24min; storefront: 544 pacotes), `npx medusa db:migrate` + seed inicial +
`seed-shopvivaliz-test-data.ts` aplicados sem erros. Adicionados 2 produtos
novos ao script de seed (Vestido e Bolsa ShopVivaliz) para atingir 11
produtos no catálogo (7 ShopVivaliz + 4 demo), acima do mínimo de 10 pedido
nesta rodada. Usuário admin criado, `npm run build` OK nos dois apps (backend:
4.2s + 22.5s; storefront: 125 páginas estáticas geradas). Publishable API key
criada via Admin API e vinculada ao Default Sales Channel; `GET
/store/products` retornou os 11 produtos. Backend subiu limpo (`npm run dev`,
`GET /health` → 200 OK) e o storefront em modo produção (`npm run start`,
porta 8000) renderizou `/br/products/camiseta-shopvivaliz` com preço real da
API (R$69,90). `sync-olist-products.php` executado sem credenciais reais:
falhou de forma controlada com a mensagem esperada ("credenciais Olist/Tiny
necessárias"), confirmando que o script já existe e se comporta corretamente.
Criados `DEPLOY_HOSTGATOR.md` e `DEPLOY_CHECKLIST.md` (o `deploy.sh` já
referenciava este último, mas o arquivo ainda não existia).

**Confirmado nesta rodada:** este ambiente **não tem acesso de rede** a
`supabase.com`, `api.stripe.com` nem `paypal.com` (bloqueio 403 da política
de proxy da organização, não um erro transitório), então a criação de conta/
projeto Supabase e a geração de chaves reais de Stripe/PayPal continuam sendo
ações humanas que não podem ser automatizadas nesta sessão. `gh` CLI também
não está disponível e a ferramenta MCP do GitHub não tem operação de secrets,
então a configuração de GitHub Secrets segue manual (comandos prontos em
`GITHUB_SECRETS_TODO.md`).

**Reverificado novamente em 2026-07-02** (quinta rodada, mesmo container desta
sessão): `npm install` limpo em ambos os apps (backend: 1341 pacotes/25min;
storefront: 544 pacotes/31s), `npx medusa db:migrate` + seed inicial +
`seed-shopvivaliz-test-data.ts` sem erros (11 produtos, cliente de teste).
`npm run build` OK nos dois apps. Desta vez o storefront foi buildado e
iniciado (`npm run start`, porta 8000) contra o backend real rodando (não
apenas revalidado com chave dummy): publishable API key real criada via
Admin API, `GET /store/products` retornou os 11 produtos, e as páginas de
produto renderizaram preços reais (R$69,90 a R$249,90). Webhook Medusa → EHA
revalidado ponta a ponta (update de produto → subscriber → POST assinado →
`medusa-webhook.php` → `tasks-queue.json`), entradas de teste revertidas.
`npm audit` no backend reporta 100 vulnerabilidades (92 moderate/8 high),
majoritariamente transitivas da árvore de dependências do Medusa (ex.
`bullmq`→`uuid`); não corrigidas nesta rodada por risco de quebrar as versões
fixadas (ver nota sobre `@medusajs/ui`/`react-router-dom` abaixo) — avaliar
`npm audit fix` (sem `--force`) em uma sessão dedicada.

⚠️ **Achado novo desta rodada:** `origin/main` tem um histórico de git
**totalmente desconectado** (unrelated histories) de todas as branches de
deploy do Medusa, incluindo esta. Detalhes e recomendação em
`deploy-status-2026-07-02.json` → `git_branch_status`. Por isso esta rodada
**não** deu push/merge para `main` — só commitou nesta branch
(`feat/medusa-deploy-prep`), que já rastreia seu remoto.

## 1. Banco de dados de produção

O backend Medusa precisa de PostgreSQL. Este ambiente usou um Postgres local
para desenvolvimento (`postgres://medusa:***@localhost:5432/medusa_shopvivaliz`),
mas isso **não persiste** fora desta sessão. Para produção:

1. Criar um banco Postgres gerenciado (ex. [Supabase](https://supabase.com),
   Neon, Railway, RDS). Isso requer login humano (conta + aceite de termos),
   por isso não foi feito automaticamente aqui.
2. Copiar a "Connection string" (modo *pooled*, porta 6543 no Supabase, ou
   direta 5432) para `DATABASE_URL` no `.env` de produção do backend.
3. Rodar as migrations contra o banco novo:
   ```bash
   cd claude/medusa/apps/backend
   npx medusa db:migrate
   npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts   # dados de teste (opcional em produção)
   npx medusa user -e admin@shopvivaliz.com.br -p "SENHA_FORTE_AQUI"
   ```

## 2. Deploy do backend + storefront

O HostGator (hospedagem compartilhada, usada hoje pelo site PHP) **não roda
Node.js/Postgres**, então o backend/storefront Medusa precisam de um host
separado:

- **Backend** (Node.js + Postgres + Redis): Railway, Render, Fly.io, ou um VPS
  com Docker. Rodar `npm run build` e depois `.medusa/server` (`npm install &&
  npx medusa start`), ou usar o Dockerfile oficial do Medusa.
- **Storefront** (Next.js): Vercel, Netlify, Railway, ou o mesmo VPS do backend.
- **PHP (`/claude/`)**: continua no HostGator, chamando a API pública do
  Medusa (`NEXT_PUBLIC_MEDUSA_BACKEND_URL` / `MEDUSA_API_URL`) e recebendo
  webhooks em `claude/api/medusa-webhook.php`.

Variáveis a configurar no host de produção do backend:
```
DATABASE_URL=<connection string do Postgres gerenciado>
REDIS_URL=<Redis gerenciado, ex. Upstash>
JWT_SECRET=<gerar novo, não reutilizar o de dev>
COOKIE_SECRET=<gerar novo, não reutilizar o de dev>
STORE_CORS=https://shopvivaliz.com.br
ADMIN_CORS=https://admin.shopvivaliz.com.br
AUTH_CORS=https://shopvivaliz.com.br,https://admin.shopvivaliz.com.br
EHA_WEBHOOK_URL=https://shopvivaliz.com.br/claude/api/medusa-webhook.php
EHA_WEBHOOK_SECRET=<gerar novo, mesmo valor no .env do PHP em produção>
```

No servidor PHP (HostGator), garantir que `EHA_WEBHOOK_SECRET` no ambiente
do site seja **o mesmo valor** configurado no backend Medusa, senão o
webhook responde 401.

## 3. Pós-deploy

- [ ] Criar publishable API key de produção no Admin (`Settings > API Key
      Management`) e configurar no storefront (`NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY`)
- [ ] Trocar `JWT_SECRET` / `COOKIE_SECRET` / `EHA_WEBHOOK_SECRET` de dev por
      valores novos gerados para produção
- [ ] Testar checkout completo em produção (carrinho → pagamento → pedido)
- [ ] Testar webhook em produção (atualizar um produto no Admin e conferir
      `storage/logs/medusa-webhook.log` + `tasks-queue.json`)
- [ ] Migrar produtos reais do Olist/Shopee para o catálogo Medusa
- [ ] Configurar backups automáticos do banco de produção
- [ ] Teste de carga (fora do escopo desta sessão)

## 4. Pagamentos (Stripe/PIX) e sincronização Olist

Adicionado em 2026-07-01 (portado de sessões anteriores que já haviam
validado o desenho, mas cujo branch não tinha sido integrado a `main`):

- **Pagamento**: `medusa-config.ts` registra `@medusajs/payment-stripe`
  automaticamente quando `STRIPE_API_KEY` está definido no `.env` do backend
  (senão nenhum módulo de pagamento é carregado — comportamento antigo
  preservado). PIX no Brasil é feito enviando
  `payment_method_types: ["pix"]` ao criar o PaymentIntent do Stripe.
  Variáveis: `STRIPE_API_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`
  (chaves de teste em https://dashboard.stripe.com/test/apikeys). PayPal
  ainda não tem credenciais configuradas (`PAYPAL_CLIENT_ID/SECRET`
  documentados em `.env.example`, mas sem provedor Medusa registrado ainda).
- **Olist → Medusa (pull/lote)**: `claude/api/sync-olist-products.php`
  (classe `OlistSync`) busca produtos na API Tiny/Olist e faz upsert via
  Admin API do Medusa (login JWT, não API key estática). Requer
  `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `MEDUSA_BACKEND_URL`,
  `MEDUSA_ADMIN_EMAIL`, `MEDUSA_ADMIN_PASSWORD`.
- **Olist → Medusa (webhook/push por SKU)**: `src/api/webhooks/olist/route.ts`
  recebe `{ sku, preco_venda, estoque_atual }` e atualiza preço/estoque da
  variante correspondente, com verificação de assinatura HMAC-SHA256
  (`OLIST_WEBHOOK_SECRET`) igual ao padrão já usado no bridge EHA.
  `claude/api/olist/webhook.php` é o receptor do lado PHP (Olist chama esta
  URL), que dispara `OlistSync`.
- Secrets pendentes de configurar (Stripe, PayPal, Olist, EHA) estão listados
  com comandos prontos em `claude/medusa/GITHUB_SECRETS_TODO.md`.

⚠️ **Achado de segurança (2026-07-01):** um `OLIST_CLIENT_ID`/`OLIST_CLIENT_SECRET`
reais estavam commitados em texto puro em vários arquivos (`SETUP-OLIST-SECRETS.md`,
`GITHUB-SECRETS-TO-ADD.md`, `scripts/olist-*.py`) e um authorization code OAuth
estava versionado em `.tokens/olist-oauth-code.txt`. Os valores nos arquivos
atuais foram redigidos e `.tokens/` foi removido do git e adicionado ao
`.gitignore` nesta sessão, mas **o segredo antigo permanece no histórico do
git**. Recomenda-se rotacionar o client secret no painel Tiny/Olist o quanto
antes; ver `claude/medusa/GITHUB_SECRETS_TODO.md` para detalhes.

## Rodando localmente (resumo)

```bash
# Backend
cd claude/medusa/apps/backend
cp .env.example .env   # editar DATABASE_URL etc.
npm install
npx medusa db:migrate
npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts
npx medusa user -e admin@shopvivaliz.com.br -p "SUA_SENHA"
npm run dev   # http://localhost:9000 (admin em /app)

# Storefront (em outro terminal)
cd claude/medusa/apps/storefront
cp .env.example .env.local   # editar NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY
npm install
npm run dev   # http://localhost:8000
```
