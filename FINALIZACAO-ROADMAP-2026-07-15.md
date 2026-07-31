# 🚀 Roadmap de Finalização - Shop Vivaliz
**Data:** 2026-07-15  
**Status:** Code Finalizer - Fase 2 Iniciada  
**PR:** #316 ✅ MERGED  
**Commit base:** ad340b67

---

## 📊 Progresso Total
- ✅ **Fase 1 Concluída (57%)** - Resolução de conflitos + deploy básico
- ⏳ **Fase 2 Em Andamento (0%)** - Integração completa + testes reais
- ❌ **Fase 3 Pendente (0%)** - Validação final + compra real

---

## 🔧 FASE 2: INTEGRAÇÃO COMPLETA

### **2.1 Mercado Pago - Auditoria de Credenciais**

**Status:** ⏳ REQUER ACESSO DO USUÁRIO  
**Tempo estimado:** 30 minutos

#### Tarefas:
- [ ] Acessar GitHub Secrets (Settings > Secrets and variables)
- [ ] Verificar se existem:
  - `MERCADOPAGO_ACCESS_TOKEN` (produção)
  - `MERCADOPAGO_PUBLIC_KEY` (produção)
  - `MERCADOPAGO_WEBHOOK_SECRET` (produção)
  - Equivalentes de teste (_TEST_ suffix)

#### Checklist de Credenciais:
```
PRODUÇÃO:
  [ ] ACCESS_TOKEN começa com "prod_"
  [ ] PUBLIC_KEY começa com "PROD-"
  [ ] WEBHOOK_SECRET: string > 30 chars
  
TESTE:
  [ ] ACCESS_TOKEN começa com "test_"
  [ ] PUBLIC_KEY começa com "TEST-"
  [ ] WEBHOOK_SECRET: string > 30 chars
  
⚠️ Nunca commitar esses valores no repo
```

#### Script de Validação (execute no servidor):
```bash
#!/bin/bash
# scripts/validate-mercadopago-creds.sh

echo "Validando Mercado Pago..."

# Verificar se credenciais existem em variáveis de ambiente
if [ -z "$MERCADOPAGO_ACCESS_TOKEN" ]; then
  echo "❌ MERCADOPAGO_ACCESS_TOKEN não configurada"
  exit 1
fi

# Testar conexão com API (requer curl + jq)
curl -s -H "Authorization: Bearer $MERCADOPAGO_ACCESS_TOKEN" \
  https://api.mercadopago.com/v1/me | jq '.id' > /dev/null

if [ $? -eq 0 ]; then
  echo "✅ Credenciais de Mercado Pago validadas"
else
  echo "❌ Falha na autenticação"
  exit 1
fi
```

---

### **2.2 MCP Oficial Mercado Pago**

**Status:** ⏳ REQUER SETUP DE OAUTH  
**Docs:** https://www.mercadopago.com.br/developers/pt/docs/mcp-server/overview

#### Passos:
1. [ ] Acessar Claude Code settings
2. [ ] Adicionar MCP Server oficial do Mercado Pago
3. [ ] Autenticar via OAuth com sua conta Mercado Pago
4. [ ] Validar tools disponíveis:
   ```bash
   # Após configurar, testar no terminal Claude Code:
   tools/list  # Deve listar tools do Mercado Pago
   ```

#### Resultado esperado:
```json
{
  "name": "mercado-pago-mcp",
  "version": "1.0",
  "tools": [
    "get_application",
    "list_webhook_events",
    "create_order",
    "get_order",
    "...outros"
  ]
}
```

---

### **2.3 Campos da Medição - Validar Implementação**

**Status:** ⏳ REQUER AUDITORIA  
**Checklist de campos obrigatórios:**

```php
// Verificar se api/orders/create-validated.php envia:
$preference = [
    // ITEMS
    'items' => [
        [
            'quantity' => (int),          // ✓ Implementado?
            'unit_price' => (float),      // ✓ Implementado?
            'title' => (string),          // ✓ Implementado?
            'category_id' => (string),    // ✓ Implementado?
            'external_code' => (string),  // ✓ Implementado?
        ]
    ],
    
    // PAYER
    'payer' => [
        'email' => (string),             // ✓ Implementado?
        'first_name' => (string),        // ✓ Implementado?
        'last_name' => (string),         // ✓ Implementado?
        'identification' => [
            'type' => 'CPF',             // ✓ Implementado?
            'number' => (string),        // ✓ Implementado?
        ],
        'phone' => [
            'area_code' => (string),     // ✓ Implementado?
            'number' => (string),        // ✓ Implementado?
        ],
        'address' => [
            'street_name' => (string),   // ✓ Implementado?
            'street_number' => (string), // ✓ Implementado?
            'city' => (string),          // ✓ Implementado?
            'state' => (string),         // ✓ Implementado?
            'zip_code' => (string),      // ✓ Implementado?
        ]
    ],
    
    // ADDITIONAL INFO
    'additional_info' => [
        'shipments' => [
            'receivers_address' => [
                'city_name' => (string),         // ✓ Implementado?
                'state_name' => (string),        // ✓ Implementado?
                'zip_code' => (string),          // ✓ Implementado?
                'street_number' => (string),     // ✓ Implementado?
            ]
        ],
        'payer' => [
            'registration_date' => (string), // ✓ Implementado?
            'authentication_type' => (string), // ✓ Implementado?
            'is_first_purchase_online' => (bool), // ✓ Implementado?
            'last_purchase' => (string), // ✓ Implementado?
        ]
    ],
    
    // OUTROS
    'statement_descriptor' => 'SHOPVIVALIZ',        // ✓ Implementado?
    'capture_mode' => 'automatic',                  // ✓ Implementado?
    'external_reference' => 'SV_unique_id',        // ✓ Implementado?
];

// HEADERS
'X-Idempotency-Key' => (string), // ✓ Implementado?
```

**Ação:** Criar issue se faltarem campos

---

### **2.4 Olist/Tiny ERP - Validação de Credenciais**

**Status:** ⏳ REQUER ACESSO DO USUÁRIO  
**Tempo estimado:** 45 minutos

#### Checklist:
- [ ] Acessar GitHub Secrets
- [ ] Verificar variáveis:
  - `OLIST_CLIENT_ID`
  - `OLIST_CLIENT_SECRET`
  - `OLIST_ACCESS_TOKEN`
  - `OLIST_REFRESH_TOKEN`

#### Script de Validação:
```python
#!/usr/bin/env python3
# scripts/validate-olist-creds.py

import os
import requests
from datetime import datetime

OLIST_API = "https://api.olist.com/api/v2"
access_token = os.getenv("OLIST_ACCESS_TOKEN")

if not access_token:
    print("❌ OLIST_ACCESS_TOKEN não configurada")
    exit(1)

# Testar API
response = requests.get(
    f"{OLIST_API}/accounts/",
    headers={"Authorization": f"Bearer {access_token}"}
)

if response.status_code == 200:
    print("✅ Credenciais Olist/Tiny validadas")
    print(f"   Data: {datetime.now()}")
    print(f"   Status: {response.status_code}")
else:
    print(f"❌ Falha na autenticação: {response.status_code}")
    print(f"   Motivo: {response.text}")
    exit(1)
```

---

### **2.5 Daemon de Sincronização (2 minutos)**

**Status:** ⏳ REQUER ACESSO AO SERVIDOR  
**Ambiente:** VM Oracle Linux

#### Verificar se já existe:
```bash
# SSH para VM
ssh -i <chave> ubuntu@137.131.156.17

# Listar services
sudo systemctl list-unit-files | grep shop
sudo systemctl list-timers | grep shop

# Se NADA existir, criar novo:
sudo nano /etc/systemd/system/shopvivaliz-sync.service
sudo nano /etc/systemd/system/shopvivaliz-sync.timer
```

#### Arquivo de service (criar se não existir):
```ini
# /etc/systemd/system/shopvivaliz-sync.service
[Unit]
Description=ShopVivaliz 2-minute Synchronization Daemon
After=network.target

[Service]
Type=oneshot
User=ubuntu
WorkingDirectory=/home/ubuntu/site-shopvivaliz
ExecStart=/usr/bin/python3 /home/ubuntu/site-shopvivaliz/scripts/sync-daemon.py
StandardOutput=journal
StandardError=journal
```

#### Arquivo de timer (criar se não existir):
```ini
# /etc/systemd/system/shopvivaliz-sync.timer
[Unit]
Description=ShopVivaliz 2-minute Sync Timer
Requires=shopvivaliz-sync.service

[Timer]
OnBootSec=1min
OnUnitActiveSec=2min
Persistent=true
AccuracySec=1s

[Install]
WantedBy=timers.target
```

#### Ativar:
```bash
sudo systemctl daemon-reload
sudo systemctl enable shopvivaliz-sync.timer
sudo systemctl start shopvivaliz-sync.timer
sudo systemctl status shopvivaliz-sync.timer
```

---

### **2.6 Bloqueio de Estoque Zero**

**Status:** ⏳ REQUER IMPLEMENTAÇÃO  
**Arquivos:** `checkout.php`, `api/orders/create-validated.php`, `catalog.php`

#### Checklist:
- [ ] Produto com estoque 0 não aparece no catálogo
- [ ] Botão "Comprar" desabilitado se estoque ≤ 0
- [ ] Carrinho rejeita quantidade > estoque
- [ ] Checkout revalida estoque no servidor (POST)
- [ ] Mercado Pago preference não criada sem estoque

#### Script de teste:
```bash
#!/bin/bash
# scripts/test-stock-blocking.sh

# Testar produto inexistente/zero stock
PRODUCT_ID="test-no-stock"

# 1. Verificar se está oculto no catálogo
curl -s "https://shopvivaliz.com.br/api/catalog?product_id=$PRODUCT_ID" | jq '.results | length'

# 2. Tentar criar ordem (deve falhar)
curl -s -X POST "https://shopvivaliz.com.br/api/orders/create-validated.php" \
  -H "Content-Type: application/json" \
  -d "{\"items\":[{\"product_id\":\"$PRODUCT_ID\",\"quantity\":1}]}" | jq '.ok'

# Resultado esperado: false
```

---

## 🎯 FASE 3: TESTES E VALIDAÇÃO REAL

### **3.1 Checkout Anônimo - Teste Completo**

**Status:** ❌ REQUER NAVEGADOR + PLAYWRIGHT  
**Tempo estimado:** 20 minutos

#### Passos (em janela anônima):
1. [ ] Abrir https://shopvivaliz.com.br
2. [ ] Navegar ao catálogo
3. [ ] Verificar: apenas Mercado Pago visível
4. [ ] Adicionar produto ao carrinho
5. [ ] Ir para checkout
6. [ ] **Verificar CEP:**
   - [ ] CEP é o primeiro campo
   - [ ] Preencher com CEP válido (ex: 01310100)
   - [ ] Aguardar autopreenchimento
   - [ ] Verificar se rua, bairro, cidade, UF foram preenchidos
7. [ ] Preencher dados
8. [ ] Não fazer login no site
9. [ ] Iniciar pagamento com Mercado Pago
10. [ ] Verificar que nenhuma conta foi criada

#### Checklist visual:
- [ ] Selo Mercado Pago visível na home
- [ ] Botão MP tem ícone oficial (não emoji)
- [ ] Layout mobile responsivo
- [ ] Sem erros JavaScript no console

---

### **3.2 Compra Real (Gerar Boleto)**

**Status:** ❌ REQUER AUTORIZAÇÃO  
**Tempo estimado:** 15 minutos

#### Após aprovação do usuário:
1. [ ] Localizar produto ativo com menor preço
2. [ ] Confirmar estoque positivo
3. [ ] Adicionar 1 unidade
4. [ ] Calcular frete real
5. [ ] Preencher endereço válido
6. [ ] Criar order
7. [ ] Abrir Mercado Pago (produção)
8. [ ] Selecionar "Boleto"
9. [ ] Gerar boleto (NÃO PAGAR)
10. [ ] Capturar:
    - Número pedido Shop Vivaliz: `SV...`
    - Produto + SKU
    - Subtotal
    - Frete
    - Total
    - Data vencimento
    - Linha digitável (NÃO commitar)
    - Link do boleto
    - Order ID Mercado Pago

---

### **3.3 Validação Mercado Pago Developers**

**Status:** ❌ REQUER NOVO ORDER ID DE TESTE

#### Passos:
1. [ ] Ir para https://www.mercadopago.com.br/developers/
2. [ ] Acessar painel "Qualidade da integração"
3. [ ] Criar nova Order usando credenciais DE TESTE
4. [ ] Capturar Order ID real retornado
5. [ ] Verificar: criado nos últimos 7 dias
6. [ ] Inserir no campo "Order ID"
7. [ ] Executar medição
8. [ ] Registrar:
   - [ ] Order ID
   - [ ] Pontuação
   - [ ] Campos pendentes (se houver)

---

## 📋 Checklist Final de Deploy

### Pré-Deploy:
- [ ] Todos os testes passaram
- [ ] CI verde
- [ ] Sem warnings
- [ ] Credenciais validadas
- [ ] Daemon em execução
- [ ] Estoque bloqueado
- [ ] Mercado Pago funcionando

### Deploy:
- [ ] `git pull origin main` (sincronizar)
- [ ] Verificar workflow `force-deploy-now.yml`
- [ ] Acionar deploy via GitHub Actions
- [ ] Acompanhar logs
- [ ] Validar versão em produção

### Pós-Deploy:
- [ ] Home Page: HTTP 200
- [ ] Checkout: HTTP 200
- [ ] Webhook: HTTP 401 (sem assinatura)
- [ ] Catálogo carregando
- [ ] Mercado Pago respondendo
- [ ] CEP autofill funcionando
- [ ] Estoque bloqueado

---

## 📧 Relatório Final - Template

```markdown
# Relatório de Finalização - Shop Vivaliz
**Data:** 2026-07-15
**Responsável:** Code Finalizer / GPT

## Resumo Executivo
- [x] Conflitos resolvidos
- [x] Código validado
- [x] Deploy initial realizado
- [ ] Integrações completas (EM PROGRESSO)

## Mercado Pago
- Ambiente: PRODUÇÃO / TESTE
- Credenciais: ✅ VALIDADAS / ❌ PENDENTES
- MCP: ✅ CONFIGURADO / ⏳ EM SETUP
- Campos medição: X/24 implementados
- Webhook: ✅ FUNCIONANDO / ❌ ERRO

## Olist/Tiny ERP
- Credenciais: ✅ VÁLIDAS / ❌ INVÁLIDAS
- Teste de importação: ✅ SUCESSO / ❌ ERRO
- Pedido real importado: SV_xxxxx

## Daemon Sincronização
- Status: ✅ ATIVO / ❌ INATIVO / ⏳ EM SETUP
- Última execução: 2026-07-15 HH:MM:SS
- Próxima: 2026-07-15 HH:MM:SS
- Erros: 0

## Estoque
- Bloqueio zero: ✅ SIM / ❌ NÃO
- Reserva: ✅ FUNCIONA / ❌ ERRO
- Teste concorrente: ✅ PASSOU / ❌ FALHOU

## Checkout
- Anônimo: ✅ FUNCIONA / ❌ REQUER LOGIN
- CEP: ✅ AUTOFILL / ❌ MANUAL
- Selo MP: ✅ VISÍVEL / ❌ FALTANDO
- Mobile: ✅ RESPONSIVO / ⚠️ AJUSTES NEEDED

## Compra Real
- Produto: [nome] / SKU: [SKU]
- Subtotal: R$ X,XX
- Frete: R$ X,XX
- Total: R$ X,XX
- Boleto: [link]
- Vencimento: [data]
- Status: ⏳ AGUARDANDO PAGAMENTO

## Deploy
- Branch: fix/resolve-conflicts-and-finalize-integration
- PR: #316 ✅ MERGED
- Commit: ad340b67...
- CI Status: ✅ SUCCESS
- Produção: ✅ SINCRONIZADO

## Bloqueios Restantes
(liste pendências reais, não "concluído")
```

---

## 🚀 Próximos Passos Imediatos

**Para o Usuário:**
1. Fornecer credenciais Mercado Pago + Olist/Tiny
2. Autorizar setup do daemon no servidor
3. Aprovar compra real de teste

**Para o GPT/Claude:**
1. Usar MCP oficial Mercado Pago para validar
2. Testar importação ERP com dados reais
3. Executar compra real via Playwright

---

**Data de Criação:** 2026-07-15 14:00  
**Responsável:** Code Finalizer (Claude)  
**Status:** EM EXECUÇÃO
