# 📦 Monorepo Setup - ShopVivaliz Medusa

## Estrutura

```
claude/medusa/
├── apps/
│   ├── backend/          # Medusa Commerce Backend
│   └── storefront/       # Next.js Storefront
├── package.json          # Root dependencies
├── pnpm-workspace.yaml   # Workspace config
├── INTEGRACAO_EHA.md     # EHA integration guide
├── SETUP_SERVIDOR.md     # Server deployment guide
└── COMO_AJUDAR_IA.md     # Feedback guide
```

## Pré-requisitos

- ✅ Node.js 20+ (v24.18.0)
- ✅ pnpm 8+ ou npm 10+
- ✅ PostgreSQL 14+
- ✅ Git

## Instalação (primeiro setup)

### 1️⃣ Instalar Dependências do Monorepo

```bash
cd claude/medusa

# Com pnpm (recomendado)
pnpm install

# Ou com npm
npm install --legacy-peer-deps
```

### 2️⃣ Configurar Backend (.env)

```bash
# Copiar template
cp apps/backend/.env.example apps/backend/.env

# Editar com suas credenciais
# - DATABASE_URL (PostgreSQL)
# - JWT_SECRET (gerar com openssl rand -base64 32)
# - COOKIE_SECRET (gerar com openssl rand -base64 32)
# - Marketplace API keys
# - EHA_WEBHOOK_SECRET
```

### 3️⃣ Configurar Banco de Dados

```bash
# Criar banco de dados PostgreSQL
createdb shopvivaliz_medusa

# Criar usuário
createuser medusa_user

# Dar permissões
psql -c "ALTER USER medusa_user WITH PASSWORD 'sua_senha';"
psql -c "GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa_user;"
```

### 4️⃣ Rodar Migrations

```bash
cd apps/backend
npm run migrate
npm run seed  # Criar dados iniciais
```

## Desenvolvendo

### Iniciar Backend

```bash
cd claude/medusa
pnpm dev:backend
# ou
cd apps/backend
npm run dev
```

Backend roda em: http://localhost:9000

### Iniciar Storefront

```bash
cd claude/medusa
pnpm dev:storefront
# ou
cd apps/storefront
npm run dev
```

Storefront roda em: http://localhost:3000

### Rodar Ambos em Paralelo

```bash
cd claude/medusa
pnpm dev
```

## Scripts Disponíveis

### Root (monorepo)

```bash
pnpm install         # Instalar todas as dependências
pnpm dev             # Rodar dev em todos os apps
pnpm build           # Build de todos os apps
pnpm lint            # Lint em todos os apps
pnpm test            # Testes em todos os apps
pnpm dev:backend     # Dev apenas backend
pnpm dev:storefront  # Dev apenas storefront
```

### Backend (apps/backend)

```bash
npm run dev          # Rodar em desenvolvimento
npm run build        # Build para produção
npm run start        # Rodar versão compilada
npm run migrate      # Rodar migrations
npm run seed         # Seed com dados iniciais
npm run test         # Rodar testes
```

### Storefront (apps/storefront)

```bash
npm run dev          # Rodar em desenvolvimento
npm run build        # Build para produção
npm run start        # Rodar versão compilada
npm run lint         # Lint
npm run type-check   # Verificar tipos TypeScript
```

## Estrutura de Diretórios

### apps/backend/

```
src/
├── api/              # Rotas e endpoints
├── models/           # Modelos de banco de dados
├── services/         # Lógica de negócio
├── loaders/          # Inicialização
├── migrations/       # Migrações de banco
└── plugins/          # Plugins customizados
```

### apps/storefront/

```
app/
├── layout.tsx        # Layout root
├── page.tsx          # Homepage
├── catalog/          # Catálogo de produtos
├── product/          # Detalhe do produto
├── cart/             # Carrinho
└── checkout/         # Checkout
components/
lib/
├── api.ts            # Cliente API Medusa
styles/
```

## Integração com EHA

### 1️⃣ Webhooks do Medusa → EHA

No backend, quando um produto é criado/atualizado:
```javascript
// apps/backend/src/api/hooks/product-updated.ts
POST /admin/webhooks → https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php
```

### 2️⃣ Arquivo Webhook em PHP

```php
// claude/api/medusa-webhook.php
- Recebe evento do Medusa
- Valida assinatura (EHA_WEBHOOK_SECRET)
- Dispara sincronização com marketplaces
- Loga em claude/logs/
```

### 3️⃣ Autonomous Validation

EHA valida a cada 30 minutos:
- ✅ Integridade de dados Medusa
- ✅ Sincronização com marketplaces
- ✅ Estoque atualizado
- ✅ Preços competitivos

## Troubleshooting

### Erro: "ENOENT: no such file or directory"
```bash
# Reinstalar node_modules
rm -rf node_modules apps/*/node_modules
pnpm install
```

### Erro: "Port 9000 already in use"
```bash
# Matar processo
lsof -ti:9000 | xargs kill -9

# Ou usar porta diferente
MEDUSA_BACKEND_PORT=9001 npm run dev
```

### Erro: "Database connection failed"
```bash
# Verificar credenciais em .env
# Verificar se PostgreSQL está rodando
# Testar conexão:
psql -h localhost -U medusa_user -d shopvivaliz_medusa
```

## Deployment

### Produção (HostGator)

1. Push code para repo
2. SSH para servidor
3. cd /home/user/public_html/claude/medusa
4. pnpm install
5. npm run build (em cada app)
6. PM2 para iniciar Backend
7. Next.js em modo production para Storefront

Ver: SETUP_SERVIDOR.md

## Status Atual

- ✅ Monorepo estruturado
- ✅ Backend Medusa pronto
- ✅ Storefront Next.js pronto
- ✅ Documentação EHA integrada
- ⏳ Dependências instalando...
- ⏳ Banco de dados configurar
- ⏳ Webhooks testar

## Próximos Passos

1. ✅ Aguardar conclusão do pnpm install
2. ⏳ Configurar PostgreSQL localmente
3. ⏳ Rodar migrations
4. ⏳ Testar backend em http://localhost:9000
5. ⏳ Testar storefront em http://localhost:3000
6. ⏳ Integrar webhooks com EHA
7. ⏳ Deploy para HostGator
