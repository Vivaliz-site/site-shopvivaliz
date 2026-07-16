# 🚀 API Olist Local

API simulada do Olist para desenvolvimento e testes **sem depender de internet ou servidor remoto**.

## 📋 Sumário

- [Quick Start](#quick-start)
- [Endpoints](#endpoints)
- [Dados de Teste](#dados-de-teste)
- [Exemplos de Uso](#exemplos-de-uso)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

### 1. Iniciar o servidor

**PowerShell (Recomendado):**
```powershell
cd C:\site-shopvivaliz
.\api\olist\run-local-server.ps1
```

**Python direto:**
```bash
python C:\site-shopvivaliz\api\olist\local-server.py
```

**Resultado esperado:**
```
============================================================
🚀 API Olist Local
============================================================

URLs disponíveis:
  • Health: http://localhost:5000/health
  • Status: http://localhost:5000/status
  • Orders: http://localhost:5000/v2/orders
  • Products: http://localhost:5000/v2/products
  • Webhooks: http://localhost:5000/webhooks

Dados iniciais:
  • 2 pedidos
  • 2 produtos

Executando em http://localhost:5000
Pressione CTRL+C para parar
============================================================
```

### 2. Testar a API

**PowerShell:**
```powershell
.\api\olist\test-local-api.ps1 -Verbose
```

**cURL:**
```bash
curl http://localhost:5000/health
```

**Browser:**
```
http://localhost:5000/status
```

---

## Endpoints

### 🔐 Autenticação

**Obter Token de Acesso**
```
POST /oauth/token

Response:
{
  "access_token": "test-token-12345",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "refresh-token-12345"
}
```

### 📦 Pedidos (Orders)

**Listar todos os pedidos**
```
GET /v2/orders

Query Parameters:
  ?status=pending      (Filtrar por status)
  ?limit=50            (Limite de resultados)
```

**Obter detalhes de um pedido**
```
GET /v2/orders/{order_id}

Exemplo: GET /v2/orders/order-001
```

**Atualizar status do pedido**
```
PATCH /v2/orders/{order_id}

Body (JSON):
{
  "status": "shipped",
  "tracking_number": "BR123456789",
  "estimated_delivery": "2026-07-15"
}
```

### 🛍️ Produtos (Products)

**Listar todos os produtos**
```
GET /v2/products

Query Parameters:
  ?limit=50            (Limite de resultados)
```

**Obter detalhes de um produto**
```
GET /v2/products/{product_id}

Exemplo: GET /v2/products/prod-001
```

**Criar novo produto**
```
POST /v2/products

Body (JSON):
{
  "sku": "SKU123",
  "name": "Novo Produto",
  "price": 99.99,
  "quantity": 100
}
```

**Atualizar produto**
```
PATCH /v2/products/{product_id}

Body (JSON):
{
  "name": "Produto Atualizado",
  "price": 150.00,
  "quantity": 200,
  "status": "active"
}
```

### 🔗 Webhooks

**Listar webhooks registrados**
```
GET /webhooks
```

**Registrar novo webhook**
```
POST /webhooks

Body (JSON):
{
  "url": "http://localhost/api/webhooks/order-status-update.php",
  "event": "orders.v2"
}
```

### 💚 Status da API

**Health Check**
```
GET /health

Response:
{
  "status": "healthy",
  "timestamp": "2026-07-08T14:30:00.123456",
  "orders_count": 2,
  "products_count": 2
}
```

**Status Geral**
```
GET /status

Response:
{
  "api": "Olist Local",
  "version": "2.0",
  "environment": "development",
  "timestamp": "2026-07-08T14:30:00.123456",
  "endpoints": {
    "orders": "/v2/orders",
    "products": "/v2/products",
    "auth": "/oauth/token",
    "webhooks": "/webhooks",
    "health": "/health"
  }
}
```

---

## Dados de Teste

### Pedidos Iniciais

| ID | Status | Email | Total | Items |
|----|---------|---------|---------|----|
| order-001 | waiting_payment | cliente@example.com | R$ 100,00 | SKU001 (2x) |
| order-002 | payment_approved | maria@example.com | R$ 150,00 | SKU002 (1x) |

### Produtos Iniciais

| ID | SKU | Nome | Preço | Estoque | Status |
|----|-----|-------|---------|----------|---------|
| prod-001 | SKU001 | Rodízio Duplo | R$ 50,00 | 100 | active |
| prod-002 | SKU002 | Parafuso Aço | R$ 150,00 | 500 | active |

---

## Exemplos de Uso

### 1. Sincronizar Pedidos

```bash
# Listar todos os pedidos
curl -X GET http://localhost:5000/v2/orders

# Filtrar por status
curl -X GET "http://localhost:5000/v2/orders?status=payment_approved"

# Obter detalhes
curl -X GET http://localhost:5000/v2/orders/order-001
```

### 2. Atualizar Status com Webhook

```bash
# Atualizar status do pedido
curl -X PATCH http://localhost:5000/v2/orders/order-001 \
  -H "Content-Type: application/json" \
  -d '{
    "status": "shipped",
    "tracking_number": "BR123456789",
    "estimated_delivery": "2026-07-15"
  }'

# Resposta:
{
  "id": "order-001",
  "status": "shipped",
  "tracking_number": "BR123456789",
  "estimated_delivery": "2026-07-15",
  "updated_at": "2026-07-08T14:35:00.123456",
  ...
}
```

### 3. Sincronizar Produtos

```bash
# Listar produtos
curl -X GET http://localhost:5000/v2/products

# Criar novo
curl -X POST http://localhost:5000/v2/products \
  -H "Content-Type: application/json" \
  -d '{
    "sku": "SKU003",
    "name": "Novo Produto",
    "price": 199.99,
    "quantity": 50
  }'

# Atualizar
curl -X PATCH http://localhost:5000/v2/products/prod-001 \
  -H "Content-Type: application/json" \
  -d '{
    "price": 75.00,
    "quantity": 200
  }'
```

### 4. Registrar Webhook

```bash
curl -X POST http://localhost:5000/webhooks \
  -H "Content-Type: application/json" \
  -d '{
    "url": "http://localhost/api/webhooks/order-status-update.php",
    "event": "orders.v2"
  }'
```

### 5. Testar OAuth

```bash
curl -X POST http://localhost:5000/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"client_id": "test", "client_secret": "test"}'
```

---

## Troubleshooting

### ❌ Python não encontrado

**Erro:**
```
Python não encontrado no PATH
```

**Solução:**
1. Instale Python: https://www.python.org/downloads/
2. **Marque "Add Python to PATH"** durante instalação
3. Reinicie PowerShell

**Verificar:**
```powershell
python --version
py --version
```

### ❌ Flask não instalado

**Erro:**
```
ModuleNotFoundError: No module named 'flask'
```

**Solução:**
```powershell
python -m pip install flask
```

### ❌ Porta 5000 já em uso

**Erro:**
```
Address already in use
```

**Solução - Opção 1:** Parar processo anterior
```powershell
Get-Process python | Stop-Process -Force
```

**Solução - Opção 2:** Usar porta diferente
```powershell
.\api\olist\run-local-server.ps1 -Port 8000
```

Depois use a nova porta nos testes:
```powershell
.\api\olist\test-local-api.ps1 -BaseUrl http://localhost:8000
```

### ❌ Conexão recusada

**Erro:**
```
The underlying connection was closed
```

**Causas:**
1. Servidor não iniciado
2. Servidor travado/crashed
3. Firewall bloqueando

**Solução:**
```powershell
# Verificar se servidor está rodando
Get-Process python

# Se não estiver, iniciar:
.\api\olist\run-local-server.ps1

# Aguardar 3 segundos
Start-Sleep -Seconds 3

# Testar
.\api\olist\test-local-api.ps1
```

### ❌ Testes falhando

**Solução:**
1. Verifique se servidor está rodando
2. Rode testes em verbose:
   ```powershell
   .\api\olist\test-local-api.ps1 -Verbose
   ```
3. Verifique logs do servidor

---

## 🔗 Integração com Site

### Usando em sync-orders.php

```php
<?php
require_once __DIR__ . '/../../config/bootstrap-env.php';
sv_bootstrap_env();

// Para desenvolvimento, usar API local:
$olist_api_url = getenv('OLIST_API_URL') ?: 'http://localhost:5000';

// Buscar pedidos
$orders = json_decode(
    file_get_contents("$olist_api_url/v2/orders")
);

foreach ($orders->results as $order) {
    // Processar pedido
}
?>
```

### Usando em webhook

```php
<?php
// Simular webhook da API local
$url = 'http://localhost:5000/v2/orders/order-001';

$update = [
    'status' => 'shipped',
    'tracking_number' => 'TEST123456',
    'estimated_delivery' => '2026-07-15'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($update));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_exec($ch);
?>
```

---

## 📊 Dados Persistentes

A API **não persiste dados** entre reinicializações. Cada vez que o servidor inicia, retorna aos dados iniciais.

Para **persistência**, você pode:
1. Adicionar banco de dados SQLite
2. Adicionar arquivo JSON para salvar estado
3. Modificar o código Python

---

## 🔧 Customização

### Adicionar mais dados iniciais

Edite [local-server.py](local-server.py) linhas 19-64:

```python
INITIAL_ORDERS = {
    "seu-order-id": {
        "id": "seu-order-id",
        "status": "pending",
        "customer_email": "teste@example.com",
        ...
    },
}
```

### Adicionar novo endpoint

```python
@app.route('/v2/seu-endpoint', methods=['GET'])
def seu_endpoint():
    return jsonify({"mensagem": "seu endpoint"})
```

---

## 📝 Notas

- API é apenas para **testes e desenvolvimento**
- Não use em produção
- Dados não persistem entre execuções
- Token OAuth é fake/simulado
- Não há autenticação real
- Perfeito para testar integração local

---

## 🚀 Próximos Passos

1. Inicie o servidor local
2. Rode testes para validar
3. Integre com seu site (sync-orders.php, webhooks)
4. Teste fluxo completo localmente
5. Quando pronto, mude para API real do Olist

---

**Criado em:** 2026-07-08  
**Status:** Pronto para uso
