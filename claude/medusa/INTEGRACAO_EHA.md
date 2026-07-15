# 🔗 Integração EHA + MedusaJS (Monorepo Oficial)

## 📦 Estrutura Completa

```
claude/
├── medusa/                          # Monorepo MedusaJS
│   ├── apps/
│   │   ├── backend/                # Backend (port 9000)
│   │   │   ├── src/
│   │   │   ├── package.json
│   │   │   └── .env.example
│   │   └── storefront/             # Next.js (port 3000)
│   │       ├── app/
│   │       ├── package.json
│   │       └── .env.example
│   ├── package.json                # Root monorepo
│   ├── pnpm-workspace.yaml         # Workspace config
│   ├── MONOREPO_SETUP.md
│   ├── INTEGRACAO_EHA.md           # Este arquivo
│   └── SETUP_SERVIDOR.md
│
├── api/
│   ├── medusa-webhook.php          # Webhooks Medusa → EHA
│   └── sync-with-medusa.php        # Sincronização manual
│
├── logs/
│   ├── webhook-events.log          # Log de eventos
│   └── medusa-sync.log
│
├── dashboard/                       # EHA Monitoring
├── catalogo/                        # Consume API Medusa
├── carrinho/                        # Carrinho
└── checkout/                        # Checkout
```

## 🔄 Como Funcionam Juntos

### 🏪 Medusa JS (Commerce Core)
- Catálogo de produtos
- Processamento de pedidos
- Gestão de clientes
- GraphQL + REST APIs
- Webhooks automáticos
- Admin integrado

### 🤖 EHA (Autonomous Operations)
- Monitorar saúde 24/7
- Validar integridade de dados
- Sincronizar marketplaces
- Otimizar com IA
- Auto-corrigir erros
- Gerar relatórios

## 🔗 Fluxo de Integração

### 1️⃣ Produto Criado no Medusa

```
Admin cria produto
    ↓
Medusa valida
    ↓
Medusa emite: product.created
    ↓
Webhook HTTP POST para:
  https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php
    ↓
EHA recebe evento
    ↓
EHA processa:
  ✅ Validar estrutura
  ✅ Otimizar descrição (IA)
  ✅ Gerar imagens (IA)
  ✅ Sincronizar com Shopee/Amazon/Olist
    ↓
EHA loga em claude/logs/webhook-events.log
    ↓
Dashboard mostra status
```

### 2️⃣ Cliente Compra

```
Cliente acessa: storefront (port 3000)
    ↓
Storefront consome: Medusa API
    ↓
Seleciona produtos
    ↓
Cria pedido via: Medusa /store/orders
    ↓
Medusa emite: order.created
    ↓
EHA recebe evento
    ↓
EHA processa:
  ✅ Validar estoque
  ✅ Notificar vendedor
  ✅ Sincronizar marketplace
  ✅ Gerar label de envio
```

## 📡 Webhooks Configuração

### Em apps/backend, webhooks são automáticos:

Quando o servidor inicia, Medusa detecta e registra:

```javascript
// apps/backend/src/loaders/webhook.ts
event: 'product.created'      → POST /claude/api/medusa-webhook.php
event: 'product.updated'      → POST /claude/api/medusa-webhook.php
event: 'order.created'        → POST /claude/api/medusa-webhook.php
```

**Formato do evento recebido:**
```json
{
  "type": "product.created",
  "timestamp": "2024-01-01T10:00:00Z",
  "data": {
    "id": "prod_123",
    "title": "Produto",
    "description": "...",
    "price": 99.99
  }
}
```

## 🔐 Arquivo Webhook EHA

Criar: `claude/api/medusa-webhook.php`

```php
<?php
// Receber e processar webhooks do Medusa

// 1. Ler payload
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// 2. Validar assinatura
$secret = $_ENV['EHA_WEBHOOK_SECRET'];
$signature = $_SERVER['HTTP_X_MEDUSA_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Unauthorized');
}

// 3. Processar por tipo
$type = $event['type'] ?? 'unknown';

switch ($type) {
    case 'product.created':
    case 'product.updated':
        eha_optimize_product($event['data']);
        break;
    
    case 'order.created':
        eha_process_order($event['data']);
        break;
}

// 4. Log
log_webhook_event($type, $event['data']['id'] ?? null);

// Resposta
http_response_code(200);
echo json_encode(['ok' => true]);

function eha_optimize_product($product) {
    // Chamar EHA para:
    // - Otimizar descrição
    // - Gerar imagens
    // - Sincronizar marketplaces
    // - Atualizar preços
}

function eha_process_order($order) {
    // Chamar EHA para:
    // - Notificar vendedor
    // - Sincronizar marketplace
    // - Gerar label de envio
    // - Atualizar estoque
}

function log_webhook_event($type, $id) {
    $log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => $type,
        'id' => $id
    ];
    file_put_contents(
        __DIR__ . '/../logs/webhook-events.log',
        json_encode($log) . PHP_EOL,
        FILE_APPEND
    );
}
?>
```

## 🔑 Variáveis de Ambiente

### apps/backend/.env

```
# Backend
MEDUSA_BACKEND_URL=http://localhost:9000
NODE_ENV=development

# Database
DATABASE_URL=postgresql://user:pass@localhost:5432/shopvivaliz_medusa

# Auth
JWT_SECRET=seu_secret_jwt_mudar_em_producao
COOKIE_SECRET=seu_secret_cookie_mudar_em_producao

# EHA Webhooks
EHA_WEBHOOK_SECRET=seu_secret_eha_mudar_em_producao
MEDUSA_WEBHOOK_URL=https://dev.shopvivaliz.com.br/claude/api/medusa-webhook.php

# Marketplaces
SHOPEE_API_KEY=sua_chave
SHOPEE_API_SECRET=seu_secret
AMAZON_ACCESS_KEY=sua_chave
AMAZON_SECRET_KEY=seu_secret
OLIST_CLIENT_ID=seu_id
OLIST_CLIENT_SECRET=seu_secret
```

### apps/storefront/.env.local

```
NEXT_PUBLIC_MEDUSA_BACKEND_URL=http://localhost:9000
NEXT_PUBLIC_STORE_URL=http://localhost:3000
```

## 🧪 Testando Localmente

### Terminal 1: Iniciar Backend

```bash
cd claude/medusa
pnpm dev:backend
# Aguardar até: "Server running on http://localhost:9000"
```

### Terminal 2: Iniciar Storefront

```bash
cd claude/medusa
pnpm dev:storefront
# Acessa em: http://localhost:3000
```

### Terminal 3: Criar Produto para Testar Webhook

```bash
# 1. Login (obter token)
curl -X POST http://localhost:9000/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# 2. Criar produto (vai disparar webhook)
curl -X POST http://localhost:9000/admin/products \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Product",
    "description": "Test Description",
    "handle": "test-product"
  }'

# 3. Verificar webhook foi recebido
tail -f claude/logs/webhook-events.log
```

## 📊 Monitoramento

### Health Checks (EHA)

A cada 30 minutos, EHA valida:
```bash
GET http://localhost:9000/health
GET http://localhost:3000/health
```

### Logs

```bash
# Eventos de webhook
tail -f claude/logs/webhook-events.log

# Sincronizações
tail -f claude/logs/medusa-sync.log

# Backend Medusa
tail -f claude/medusa/apps/backend/logs/medusa.log
```

### Dashboard

Em `/claude/dashboard/`:
- Taxa de produtos sincronizados
- Erros de webhook
- Performance de API
- Status de marketplaces

## ⚙️ Próximos Passos

1. ✅ Monorepo oficial configurado
2. ⏳ Aguardar conclusão de `pnpm install`
3. ⏳ Configurar PostgreSQL localmente
4. ⏳ Criar arquivo `claude/api/medusa-webhook.php`
5. ⏳ Testar backend em localhost:9000
6. ⏳ Testar storefront em localhost:3000
7. ⏳ Testar webhook criando produto
8. ⏳ Deploy para produção (HostGator)

## 🐛 Troubleshooting

### "Port 9000 already in use"
```bash
lsof -ti:9000 | xargs kill -9
# ou
MEDUSA_BACKEND_PORT=9001 npm run dev
```

### "Database connection failed"
```bash
# Verificar PostgreSQL está rodando
psql -h localhost -U medusa_user -d shopvivaliz_medusa

# Verificar .env tem credenciais corretas
cat apps/backend/.env | grep DATABASE_URL
```

### "Webhook not received"
- Verificar se /claude/api/medusa-webhook.php existe
- Verificar URL é acessível: curl https://seu_domain/claude/api/medusa-webhook.php
- Verificar secret em ambos os lados
- Ver logs do Medusa

## 🔐 Segurança

✅ Webhooks validados com HMAC-SHA256
✅ JWT para autenticação de APIs
✅ Secrets em .env (não commitados)
✅ Rate limiting em produção
✅ Logs de todas as operações
✅ HTTPS obrigatório em produção

