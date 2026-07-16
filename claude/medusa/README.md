# ShopVivaliz MedusaJS - Backend + Storefront

## 📦 Estrutura do Projeto

```
/claude/medusa/
├── README.md
├── INTEGRACAO_EHA.md
├── DEPLOY-CHECKLIST.md
└── apps/
    ├── backend/              # Backend MedusaJS v2 (Node.js)
    │   ├── src/
    │   │   ├── subscribers/eha-webhook.ts   # encaminha eventos -> EHA
    │   │   └── scripts/seed-shopvivaliz-test-data.ts
    │   ├── medusa-config.ts
    │   ├── package.json
    │   └── .env / .env.example
    │
    └── storefront/           # Frontend Next.js 15 (Medusa Storefront starter)
        ├── src/
        ├── package.json
        └── .env.local / .env.example
```

Este projeto foi criado a partir do starter oficial `medusajs/dtc-starter`, com um
subscriber e um script de seed adicionais para integrar com o EHA e popular
produtos de teste em Real (BRL).

## 🚀 Rodando localmente

### Pré-requisitos

- Node.js 20+
- PostgreSQL rodando localmente (ou uma URL de banco remoto, ex. Supabase)
- Redis (opcional em dev; sem `REDIS_URL` o Medusa usa um event bus em memória)

### Backend

```bash
cd claude/medusa/apps/backend
cp .env.example .env   # preencha DATABASE_URL, JWT_SECRET, COOKIE_SECRET
npm install
npx medusa db:migrate         # roda migrations + seed inicial (produtos demo em EUR/USD)
npx medusa exec ./src/scripts/seed-shopvivaliz-test-data.ts   # produtos BRL + região Brasil + cliente teste
npx medusa user -e admin@shopvivaliz.com.br -p "SUA_SENHA"    # cria usuário admin
npm run dev     # modo desenvolvimento (watch), porta 9000
```

Para rodar em modo produção local (mesmo fluxo usado no deploy):

```bash
npm run build
cd .medusa/server
ln -s ../../node_modules node_modules   # ou "npm install" se preferir node_modules próprio
cp ../../.env .env
npx medusa start   # porta 9000
```

- API: `http://localhost:9000`
- Admin: `http://localhost:9000/app`

### Storefront

```bash
cd claude/medusa/apps/storefront
cp .env.example .env.local   # preencha NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY (ver Admin > Settings > API Key Management)
npm install
npm run dev      # porta 8000
# ou, para build de produção:
npm run build && npm run start
```

Storefront: `http://localhost:8000`

## 🔗 Integração com EHA

- O `/claude/` continua com:
  - Homepage (`index.php`)
  - Dashboard (`dashboard/index.php`)
  - Catálogo (`catalogo/`)
  - Carrinho (`carrinho/`)
  - Checkout (`checkout/`)

- MedusaJS fornece:
  - API REST para produtos, pedidos e clientes
  - Admin para gerenciar catálogo
  - Subscriber (`src/subscribers/eha-webhook.ts`) que envia eventos
    (`product.created`, `product.updated`, `order.placed`, `customer.created`)
    para `claude/api/medusa-webhook.php`, assinados com HMAC-SHA256.

Veja `INTEGRACAO_EHA.md` para os detalhes do fluxo e `DEPLOY-CHECKLIST.md`
para o passo a passo de deploy em produção.

## 📝 Status

1. ✅ Setup do MedusaJS Backend (build limpo, migrations + seed OK)
2. ✅ Setup do Next.js Storefront (build limpo, testado com região `br`/BRL)
3. ✅ Webhook MedusaJS -> EHA (`claude/api/medusa-webhook.php`) testado ponta a ponta
4. ✅ 5 produtos de teste (T-shirt, Calça Jeans, Tênis, Boné, Jaqueta) com preços em BRL/USD
5. ✅ Pagamento Stripe/PIX (`@medusajs/payment-stripe`, condicional a `STRIPE_API_KEY`)
   e sincronização Olist ⇄ Medusa (`claude/api/sync-olist-products.php` +
   `src/api/webhooks/olist/route.ts`) — código adicionado e validado (build +
   registro do provider confirmado em Postgres); aguardando chaves/credenciais reais
6. ⏳ Banco de dados de produção (Supabase ou outro Postgres gerenciado) — requer criar
   conta e configurar `DATABASE_URL` real (ação humana, ver `DEPLOY-CHECKLIST.md`)
7. ⏳ Deploy em produção (HostGator/outro host) — requer decisão de hospedagem para
   Node.js (HostGator compartilhado normalmente não roda Node; considerar Railway,
   Render, Fly.io ou VPS para o backend Medusa, mantendo o PHP no HostGator)
7. ⏳ Migrar produtos reais (Olist/Shopee) para o catálogo Medusa
