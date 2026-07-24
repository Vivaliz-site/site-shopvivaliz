# 🚨 BLOQUEADOR CRÍTICO: TOKEN OLIST EXPIRADO

**Prioridade**: 🔴 MÁXIMA  
**Data Descoberta**: 2026-07-12  
**Data de Expiração**: 2026-07-09 (EXPIROU HÁ 3 DIAS)  
**Impacto**: **ZERO PEDIDOS CHEGAM AO ERP**

---

## ❌ O PROBLEMA

### Status Atual
- ✅ Pedidos SÃO criados e salvos no banco de dados
- ✅ Pedidos SÃO salvos em `/storage/orders/*.json`
- ✅ Subscriber `order-created.ts` tenta enviar para Olist
- ❌ **MAS O TOKEN OAUTH EXPIROU**
- ❌ **API RETORNA 401 UNAUTHORIZED**
- ❌ **PEDIDOS NUNCA CHEGAM NO ERP**

### Consequência em Produção
Se isso fosse produção hoje:
- Cliente faz pedido e paga
- Pedido é criado no banco ✓
- Sistema tenta sincronizar com Olist ✗
- **Pedido fica perdido** → Fornecedor nunca recebe
- Cliente nunca recebe produto

**Isto é CRÍTICO e DEVE SER FIXADO HOJE.**

---

## 🔍 ANÁLISE TÉCNICA

### Credenciais Encontradas em `.env`
```
OLIST_ACCESS_TOKEN = [REDACTED - nunca commitar segredos em texto plano; valor tratado como comprometido]
OLIST_CLIENT_ID = [REDACTED]
OLIST_CLIENT_SECRET = [REDACTED - valor tratado como comprometido, nao usar mais]
OLIST_REFRESH_TOKEN = [JWT - REDACTED]
```

### Token JWT Decodificado
```json
{
  "exp": 1783639033,           // ← ISTO AQUI
  "type": "Offline",
  "subject": "fd7037bc-6d2b-4af6-837d-44aa14e75f92"
}
```

**Tradução**: 
- `1783639033` = July 9, 2026 23:17:13 UTC
- Data atual: July 12, 2026 (EXPIROU HÁ 3 DIAS)

### Onde Falha
**Arquivo**: `/api/orders/create-v2.php` linhas 213-239  
**Função**: `svo_tiny_get_token()`

```php
// Tentativa de renovar token
POST https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token
```

**Erro esperado**: 
```
401 Unauthorized - Refresh token expired or invalid
```

### Então o Sistema Aborta
**Arquivo**: `/api/orders/create-v2.php` linhas 456-468

```php
$tiny_push_result = svo_push_order_tiny($order_data, $access_token);
// Returns: "token_unavailable" ou "missing_credentials"
```

**Resultado no JSON do pedido**:
```json
{
  "order_id": "SV20260711233802609",
  "tiny_push": "token_unavailable",  // ← PROBLEMA AQUI
  "customer": {...},
  "items": [...]
}
```

---

## 🔧 COMO ARRUMAR (2 OPÇÕES)

### OPÇÃO 1: Renovar Token (PREFERIDO - 5 min)

1. **Ir para endpoint de renovação**
   ```
   GET https://shopvivaliz.com.br/api/olist/refresh-token.php
   ```
   Ou rodar via CLI:
   ```bash
   curl https://shopvivaliz.com.br/api/olist/refresh-token.php
   ```

2. **Script tenta renovar usando OLIST_REFRESH_TOKEN**
   - Se trabalhar: retorna novo access token
   - Se falhar (401): REFRESH TOKEN também expirou

3. **Se sucesso**: 
   - Novo token salvo em `.env`
   - Próximos pedidos sincronizam automaticamente
   - **PRONTO** ✓

4. **Se falha (401 ainda)**:
   - Ir para OPÇÃO 2

---

### OPÇÃO 2: Re-autenticar com Olist (NECESSÁRIO se Opção 1 falhar)

#### Passo 1: Ir para login Olist
```
Abrir: https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth
   ?client_id=OLIST_CLIENT_ID
   &redirect_uri=https://shopvivaliz.com.br/api/olist/callback.php
   &response_type=code
   &scope=openid profile email
```

#### Passo 2: Autorizar ShopVivaliz
- Fazer login com credenciais Olist
- Clicar "Autorizar"
- Será redirecionado para `/api/olist/callback.php`

#### Passo 3: Sistema Captura Token
O callback.php recebe `code` e troca por:
- Novo `access_token`
- Novo `refresh_token`
- Salva tudo em `.env`

#### Passo 4: Testar
```bash
# Fazer novo pedido de teste
# Verificar que chegou em Olist
```

---

## ✅ CHECKLIST DE RESOLUÇÃO

### AGORA (5-30 min)
- [ ] Ir para https://shopvivaliz.com.br/api/olist/refresh-token.php
- [ ] Observar resposta (sucesso ou erro)
- [ ] Se sucesso: Testar novo pedido
- [ ] Se erro 401: Ir para re-autenticação

### Se OPÇÃO 1 funcionar
- [ ] Novo pedido de teste
- [ ] Verificar que chegou no Olist
- [ ] Verificar `tiny_push: "ok"` no JSON
- [ ] **DONE** ✅

### Se OPÇÃO 1 falhar
- [ ] Executar fluxo OAuth novo (Passo 1-3 acima)
- [ ] Capturar novo tokens
- [ ] Salvar em `.env`
- [ ] Testar novo pedido
- [ ] Verificar sucesso no Olist

### Após Resolver
- [ ] Revisar pedidos ANTIGOS que falharam
- [ ] Implementar RETRY para pedidos com `tiny_push: "token_unavailable"`
- [ ] Adicionar ALERTING para futuros expirations
- [ ] Documentar token refresh cycle

---

## 🚀 RESYNC DE PEDIDOS ANTIGOS

Depois de arrumar o token, pedidos antigos com `tiny_push: "token_unavailable"` ainda estão no DB.

### Encontrar pedidos falhados
```bash
ls -la /storage/orders/*.json | head -10
# ou via DB:
SELECT * FROM orders WHERE metadata LIKE '%token_unavailable%'
```

### Resync manual (depois)
```
Para cada pedido falhado:
1. Chamar svo_push_order_tiny() novamente
2. Verificar se vai
3. Se sim: atualizar status no DB
```

**Idealmente**: Implementar endpoint de bulk resync

---

## 📊 TIMELINE

| Ação | Tempo | Status |
|------|-------|--------|
| Tentar renovar token | 5 min | ⏳ POR FAZER |
| Se falhar, re-autenticar | 10-15 min | ⏳ POR FAZER |
| Testar com novo pedido | 5 min | ⏳ POR FAZER |
| Implementar retry automático | 30 min | 📋 DEPOIS |
| Alerting para futuros expirations | 1h | 📋 DEPOIS |

---

## 🔐 INFORMAÇÕES DE ACESSO

### URLs Importantes
- **Token Refresh**: https://shopvivaliz.com.br/api/olist/refresh-token.php
- **OAuth Callback**: https://shopvivaliz.com.br/api/olist/callback.php
- **Olist Dashboard**: https://www.tiny.com.br/admin (se tiver acesso)

### Arquivos Relacionados
- `/api/orders/create-v2.php` (linhas 213-239)
- `/api/olist/refresh-token.php` (renovação)
- `/api/olist/callback.php` (OAuth callback)
- `/api/orders/process-validated.php` (salvamento)

---

## 💡 PREVENÇÃO FUTURA

```php
// TODO: Adicionar isto ao sistema
// Check token expiration antes de usar
// Se < 7 dias para expirar: renovar automaticamente
// Se expirou: ALERTAR IMEDIATAMENTE

if ($token_exp_timestamp < time() + (7 * 24 * 60 * 60)) {
  // Renovar token automaticamente
  svo_tiny_get_token_refresh();
  
  if (expired) {
    // Alertar admin
    send_alert_to_admin("Olist token expirou!");
  }
}
```

---

## 📋 NÃO FAÇA

❌ Não limpe pedidos antigos antes de resync  
❌ Não altere manuais OLIST_CLIENT_SECRET  
❌ Não tente usar access_token expirado  
❌ Não assuma que vai renovar automaticamente (não vai hoje)

---

## ✅ STATUS ESPERADO APÓS FIXO

```
ANTES (agora):
Pedido → Criado ✓ → Tentar sync → 401 ERROR ✗ → Perdido

DEPOIS (fixo):
Pedido → Criado ✓ → Sync OK ✓ → Olist recebe ✓ → PRONTO
```

---

## 🎯 RESPONSABILIDADE

**QUEM**: Agente de Deploy ou Administrador  
**QUANDO**: HOJE (bloqueador crítico)  
**RESULTADO**: Próximos pedidos sincronizam com sucesso  
**VALIDAÇÃO**: Testar pedido e verificar em Olist

---

**Este é o único bloqueador crítico descoberto na auditoria.**  
**Tudo mais funciona - mas sem isto, pedidos não chegam ao ERP.**

🚨 **RESOLVER AGORA** 🚨

