# Integração EHA com MedusaJS

## 🤖 EHA (Autonomous Agent System) + 🏪 MedusaJS

### Arquitetura

```
/claude/
├── index.php                    # Homepage (Medusa API consumer)
├── dashboard/                   # EHA Monitoring Dashboard
├── catalogo/                    # Catálogo (Medusa API consumer)
├── carrinho/                    # Carrinho (Medusa API consumer)
├── checkout/                    # Checkout (Medusa API consumer)
├── api/                         # APIs (EHA + Medusa bridges)
└── medusa/
    └── apps/
        ├── backend/              # MedusaJS v2 (Port 9000)
        │   ├── src/
        │   │   ├── subscribers/eha-webhook.ts
        │   │   └── scripts/seed-shopvivaliz-test-data.ts
        │   └── package.json
        └── storefront/           # Next.js 15 (Port 8000)
            ├── src/
            └── package.json
```

## 🔄 Como Funcionam Juntos

### EHA Responsabilidades
- ✅ Monitorar saúde do sistema 24/7
- ✅ Validar dados de produtos
- ✅ Sincronizar com marketplaces
- ✅ Executar automações
- ✅ Corrigir problemas automaticamente
- ✅ Gerar relatórios

### MedusaJS Responsabilidades
- 🛍️ Gerenciar catálogo de produtos
- 💳 Processar pedidos
- 👥 Gerenciar clientes
- 📊 Admin para configurações
- 🔌 Plugins para integrações
- 🪝 Webhooks para eventos

## 🔗 Integração

### 1. EHA monitora MedusaJS
```bash
# EHA health checks
- Verifica se backend Medusa está rodando (port 9000)
- Valida integridade do banco de dados
- Monitora fila de tarefas
```

### 2. Frontend consome Medusa API
```bash
/claude/catalogo/index.php
├── Fetch -> http://localhost:9000/admin/products
├── Exibe produtos em HTML
└── Envia pedidos via API Medusa
```

### 3. EHA sincroniza com Marketplaces
```bash
EHA Webhook Listener (claude/api/medusa-webhook.php)
├── Recebe eventos assinados (HMAC-SHA256) do subscriber Medusa
├── Valida assinatura com EHA_WEBHOOK_SECRET
├── Registra evento em storage/logs/medusa-webhook.log
├── Enfileira tarefa em tasks-queue.json (product.created/updated, order.placed, customer.created)
└── Fluxo de automação existente do EHA processa a fila e sincroniza com Shopee/Olist
```

Testado ponta a ponta: atualizar um produto no Admin do Medusa dispara o
subscriber `eha-webhook.ts`, que faz POST assinado para o endpoint PHP acima,
que valida a assinatura e grava o evento/tarefa.

### 4. Automações autônomas
```bash
CI Autonomo + MedusaJS
├── Validar novos produtos
├── Otimizar descrições (IA)
├── Atualizar preços
└── Gerar thumbnails
```

## 🚀 Workflow Típico

```
1. Produto cadastrado no Admin Medusa
   ↓
2. EHA recebe webhook (novo produto)
   ↓
3. EHA valida e otimiza (IA)
   ↓
4. EHA sincroniza com marketplaces
   ↓
5. Frontend /claude/catalogo/ exibe
   ↓
6. Cliente compra
   ↓
7. Pedido em Medusa
   ↓
8. EHA notifica seller
   ↓
9. EHA monitora status
```

## 📋 Variáveis de Ambiente

### MedusaJS Backend (claude/medusa/apps/backend/.env)
```
DATABASE_URL=postgres://user:pass@host:5432/shopvivaliz
JWT_SECRET=seu-secret-aqui
COOKIE_SECRET=seu-secret-aqui
STORE_CORS=...
ADMIN_CORS=...
AUTH_CORS=...
REDIS_URL=redis://localhost:6379   # opcional em dev

# EHA / ShopVivaliz
MEDUSA_API_URL=http://localhost:9000
EHA_WEBHOOK_URL=http://localhost/claude/api/medusa-webhook.php
EHA_WEBHOOK_SECRET=seu-webhook-secret
MARKETPLACE_SYNC_ENABLED=false
```

### Storefront (claude/medusa/apps/storefront/.env.local)
```
NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=pk_...
NEXT_PUBLIC_MEDUSA_BACKEND_URL=http://localhost:9000
NEXT_PUBLIC_DEFAULT_REGION=br
NEXT_PUBLIC_BASE_URL=http://localhost:8000
```

## ✅ Checklist de Setup

- [x] MedusaJS Backend rodando (port 9000) — build + migrations + seed validados
- [x] Next.js Storefront rodando (port 8000) — build validado, região `br` testada
- [x] Webhooks Medusa → EHA configurados (`eha-webhook.ts` -> `medusa-webhook.php`)
- [x] Testes de fluxo end-to-end (update de produto -> webhook -> tasks-queue.json)
- [ ] EHA Dashboard monitorando o backend Medusa (ainda não há healthcheck dedicado)
- [ ] APIs Medusa consumidas em /claude/catalogo/ (catálogo PHP ainda usa fonte própria)
- [ ] Marketplace sync ativo (`MARKETPLACE_SYNC_ENABLED=true` + credenciais reais)
- [ ] Banco de produção real (Supabase/Postgres gerenciado) configurado
- [ ] Deploy em produção

## 🔐 Segurança

- EHA usa tokens JWT para acessar API Medusa
- Webhooks validados com secret
- Rate limiting em APIs
- Logs de todas as operações
- Backups automáticos via EHA
