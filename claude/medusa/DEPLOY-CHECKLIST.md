# Checklist de Deploy - MedusaJS (ShopVivaliz)

## Status atual (ambiente de desenvolvimento)

| Item | Status |
|---|---|
| Backend Medusa (build + migrations + seed) | ✅ OK (Postgres local) |
| Storefront Next.js (build) | ✅ OK |
| Produtos de teste (T-shirt, Jeans, Tênis, Boné, Jaqueta) | ✅ Criados em BRL/USD |
| Cliente de teste | ✅ Criado (`cliente.teste@shopvivaliz.com.br`) |
| Webhook Medusa → EHA | ✅ Testado ponta a ponta |
| Banco de dados de produção | ⏳ Pendente (ver passo 1) |
| Deploy backend/storefront em produção | ⏳ Pendente (ver passo 2) |

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
