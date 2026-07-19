# 🧪 VALIDAÇÃO PONTA A PONTA - TESTE E2E COMPLETO

**Data:** 2026-07-13 23:32 UTC  
**Objetivo:** Validar fluxo completo de venda até ERP  
**Status:** 🔄 INICIANDO TESTES  

---

## 📋 CHECKLIST DE VALIDAÇÃO E2E

### TESTE 1: Site Acessível + Carregamento Rápido
```bash
# Teste 1.1: Site respondendo
curl -s -I https://shopvivaliz.com.br/ | grep "HTTP"
# Esperado: HTTP/1.1 200 OK ✅

# Teste 1.2: Página home carrega
curl -s https://shopvivaliz.com.br/ | grep -c "ShopVivaliz\|Loja\|Produtos" > 0
# Esperado: conteúdo encontrado ✅

# Teste 1.3: CSS + JS carregam
curl -s -I https://shopvivaliz.com.br/css/style.css | grep "200\|304"
# Esperado: 200 ou 304 Not Modified ✅
```

### TESTE 2: Catálogo de Produtos (Sincronizado com Olist)
```bash
# Teste 2.1: Produtos carregam
curl -s https://shopvivaliz.com.br/api/catalog/products.php | grep -c "id\|name\|price" > 0
# Esperado: JSON com produtos ✅

# Teste 2.2: Preços atualizados (do Olist)
curl -s https://shopvivaliz.com.br/api/catalog/products.php | grep "price" | head -1
# Esperado: preços numéricos ✅

# Teste 2.3: Estoque sincronizado
curl -s https://shopvivaliz.com.br/api/catalog/products.php | grep "stock" | head -1
# Esperado: quantidade de estoque ✅
```

### TESTE 3: Carrinho de Compras
```bash
# Teste 3.1: Adicionar ao carrinho
# Simular POST /api/cart/add.php
curl -X POST https://shopvivaliz.com.br/api/cart/add.php \
  -d '{"product_id":"123","quantity":1}' \
  -H "Content-Type: application/json"
# Esperado: {"status":"ok","cart_id":"xxx"} ✅

# Teste 3.2: Recuperar carrinho
curl -s https://shopvivaliz.com.br/api/cart/get.php?cart_id=xxx
# Esperado: items, totals, shipping ✅
```

### TESTE 4: Checkout e Geração de Boleto
```bash
# Teste 4.1: Iniciar checkout
curl -X POST https://shopvivaliz.com.br/api/checkout/init.php \
  -d '{
    "customer":{"name":"Test","email":"test@gmail.com","phone":"11999999999"},
    "address":{"zip":"01234567"},
    "payment_method":"boleto"
  }' \
  -H "Content-Type: application/json"
# Esperado: order_id, payment_status, boleto_url ✅

# Teste 4.2: Gerar boleto
curl -s https://shopvivaliz.com.br/api/payment/generate-boleto.php?order_id=xxx
# Esperado: boleto_number, due_date, barcode ✅

# Teste 4.3: Verificar status pagamento
curl -s https://shopvivaliz.com.br/api/payment/status.php?order_id=xxx
# Esperado: {"status":"pending","method":"boleto"} ✅
```

### TESTE 5: Email de Confirmação Enviado
```bash
# Teste 5.1: Verificar log de email
tail -20 logs/email-*.log | grep "Order confirmation\|test@gmail.com"
# Esperado: Email enviado com sucesso ✅

# Teste 5.2: Validar SMTP Gmail
echo "HELO shopvivaliz.com.br" | nc -w 5 smtp.gmail.com 587
# Esperado: 220 Conexão OK ✅

# Teste 5.3: Testar envio de teste
curl -X POST https://shopvivaliz.com.br/api/mail/test.php \
  -d "to=fredmourao@gmail.com&subject=Teste"
# Verificar inbox após 30 segundos ✅
```

### TESTE 6: Sincronização com Olist/Tiny ERP
```bash
# Teste 6.1: Verificar token Olist válido
grep "OLIST_REFRESH_TOKEN" .env | cut -d= -f2 | head -c 20
# Esperado: token começando com "eyJ..." (JWT válido) ✅

# Teste 6.2: Sincronizar catálogo
curl -X POST https://shopvivaliz.com.br/api/olist/sync-catalog.php
# Esperado: {"status":"ok","synced":X,"errors":0} ✅

# Teste 6.3: Verificar pedido no ERP
# (requer autenticação Olist)
curl -H "Authorization: Bearer $OLIST_TOKEN" \
  https://api.tiny.com.br/api/v2/pedidos?numero_do_pedido=xxx
# Esperado: Pedido encontrado no ERP ✅

# Teste 6.4: Log de sincronização
tail -10 logs/olist-sync.log | grep "success\|error"
# Esperado: syncs bem-sucedidos ✅
```

### TESTE 7: Fluxo Shopee (Integrações Extras)
```bash
# Teste 7.1: Token Shopee válido
grep "SHOPEE_" .env | wc -l
# Esperado: > 0 credenciais ✅

# Teste 7.2: Sincronização Shopee
curl -X POST https://shopvivaliz.com.br/api/shopee/sync.php
# Esperado: {"status":"ok","products_synced":X} ✅
```

### TESTE 8: Monitoramento e Alertas
```bash
# Teste 8.1: Health check rodando
curl -s https://shopvivaliz.com.br/admin/health-check.php
# Esperado: {"status":"healthy","uptime":X,"db":"connected"} ✅

# Teste 8.2: Agent heartbeats atualizados
ls -la .agent-heartbeats/*.heartbeat | grep "$(date +%d)"
# Esperado: arquivos modificados hoje ✅

# Teste 8.3: Logs em tempo real
tail -f logs/orchestrator.log | head -5
# Esperado: activity logs ✅
```

### TESTE 9: Sincronização Automática
```bash
# Teste 9.1: Daemon sync funcionando
git log -1 --oneline
# Verificar timestamp recente (últimos 5 min) ✅

# Teste 9.2: Workflow renovador rodando
tail -10 logs/olist-live-sync-response.json
# Esperado: última execução recente ✅

# Teste 9.3: Local = Remoto
git status
# Esperado: "working tree clean" ✅
```

### TESTE 10: Validação de Segurança
```bash
# Teste 10.1: .env não está commitado
git ls-files | grep ".env"
# Esperado: nada encontrado ✅

# Teste 10.2: Secrets em GitHub
gh secret list 2>/dev/null | wc -l
# Esperado: > 5 secrets ✅

# Teste 10.3: HTTPS ativo
curl -I https://shopvivaliz.com.br/ | grep "Strict-Transport"
# Esperado: header presente ou conexão HTTPS ✅
```

---

## 🧪 SCRIPT DE TESTE AUTOMATIZADO

```bash
#!/bin/bash
# save as: test-e2e.sh
# usage: bash test-e2e.sh

SITE="https://shopvivaliz.com.br"
RESULTS=""
PASS=0
FAIL=0

test_endpoint() {
  local name=$1
  local url=$2
  local expected=$3
  
  echo -n "  [$name] "
  response=$(curl -s -I "$url" | head -1)
  
  if echo "$response" | grep -q "$expected"; then
    echo "✅ PASS"
    PASS=$((PASS+1))
  else
    echo "❌ FAIL - Got: $response"
    FAIL=$((FAIL+1))
  fi
}

echo "🧪 VALIDAÇÃO E2E SHOPVIVALIZ"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Testes de Site
echo "1️⃣ SITE ACESSÍVEL"
test_endpoint "Homepage" "$SITE/" "HTTP/1.1 200"
test_endpoint "CSS" "$SITE/css/style.css" "200\|304"

# Testes de API
echo ""
echo "2️⃣ API ENDPOINTS"
test_endpoint "Produtos" "$SITE/api/catalog/products.php" "200"
test_endpoint "Carrinho" "$SITE/api/cart/get.php" "200"
test_endpoint "Health" "$SITE/admin/health-check.php" "200"

# Testes de Integrações
echo ""
echo "3️⃣ INTEGRAÇÕES"
test_endpoint "Olist Sync" "$SITE/api/olist/sync-catalog.php" "200"
test_endpoint "Shopee Sync" "$SITE/api/shopee/sync.php" "200"

# Resultado
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 RESULTADO: ✅ $PASS / ❌ $FAIL"
if [ $FAIL -eq 0 ]; then
  echo "🎉 TODOS OS TESTES PASSARAM!"
else
  echo "⚠️ ALGUNS TESTES FALHARAM"
fi
```

---

## ✅ RESULTADO ESPERADO

Se todos os testes passarem:

```
✅ Site respondendo
✅ Produtos carregando (Olist sync)
✅ Carrinho funcionando
✅ Checkout + boleto gerando
✅ Email enviado
✅ Pedido sincronizado com ERP
✅ Shopee integrado
✅ Monitoramento ativo
✅ Auto-sync funcionando
✅ Segurança OK
```

**Resultado Final:** 🚀 **PRODUÇÃO LIBERADA PARA TRÁFEGO REAL**

---

## 🚨 SE ALGUM TESTE FALHAR

**Teste 1-3 falharam:** Problema no servidor web
```bash
# Verificar logs Apache
tail -50 /var/log/apache2/error.log
sudo systemctl restart apache2
```

**Teste 4-5 falharam:** Problema checkout/email
```bash
# Verificar logs
tail -20 logs/email-*.log
tail -20 logs/checkout.log
# Se email falhar: verificar SMTP Gmail
```

**Teste 6 falhou:** Problema Olist/ERP
```bash
# Renovar token
php api/olist/refresh-token.php
# Verificar logs
tail -50 logs/olist-sync.log
```

**Teste 9 falhou:** Daemon não sincronizando
```bash
# Reiniciar daemon
pkill -f auto-sync-daemon.py
nohup python3 scripts/auto-sync-daemon.py > logs/auto-sync-daemon.log 2>&1 &
```

---

**Próximo:** Execute os testes acima e reporte resultados! 🧪
