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
    ├── backend/                 # MedusaJS (Port 9000)
    │   ├── src/
    │   └── package.json
    └── storefront/              # Next.js (Port 8000)
        ├── app/
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
EHA Webhook Listener
├── Recebe eventos de Medusa
├── Sincroniza com Shopee/Amazon/Olist
└── Atualiza estoque em tempo real
```

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

### MedusaJS Backend (.env)
```
MEDUSA_BACKEND_URL=http://localhost:9000
MEDUSA_ADMIN_BACKEND_URL=http://localhost:9000
DATABASE_URL=postgres://user:pass@localhost:5432/shopvivaliz
JWT_SECRET=seu-secret-aqui
```

### EHA Integration (.env)
```
MEDUSA_API_URL=http://localhost:9000
MEDUSA_API_KEY=seu-api-key
EHA_WEBHOOK_SECRET=seu-webhook-secret
MARKETPLACE_SYNC_ENABLED=true
```

## ✅ Checklist de Setup

- [ ] MedusaJS Backend rodando (port 9000)
- [ ] Next.js Storefront rodando (port 8000)
- [ ] EHA Dashboard monitorando
- [ ] Webhooks Medusa → EHA configurados
- [ ] APIs Medusa consumidas em /claude/catalogo/
- [ ] Marketplace sync ativo
- [ ] Testes de fluxo end-to-end

## 🔐 Segurança

- EHA usa tokens JWT para acessar API Medusa
- Webhooks validados com secret
- Rate limiting em APIs
- Logs de todas as operações
- Backups automáticos via EHA
