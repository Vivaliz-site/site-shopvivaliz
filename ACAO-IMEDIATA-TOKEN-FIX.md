# 🔥 AÇÃO IMEDIATA: FIX TOKEN OLIST

**Prioridade**: 🔴 MÁXIMA  
**Prazo**: AGORA (< 30 min)  
**Impacto**: Pedidos só chegam no ERP com isto

---

## PASSO 1: TENTAR RENOVAR (5 min)

```bash
# Via curl
curl "https://shopvivaliz.com.br/api/olist/refresh-token.php"

# Ou abrir no browser
https://shopvivaliz.com.br/api/olist/refresh-token.php
```

**Se tiver sucesso**: 
- Script retorna novo token
- `.env` é atualizado automaticamente
- **PRONTO** ✓

**Se tiver erro 401**:
- Refresh token expirou também
- Vai para PASSO 2

---

## PASSO 2: RE-AUTENTICAR (10-15 min)

### Cenário A: Tem acesso Olist/Tiny
1. Vá para Olist Dashboard (https://tiny.com.br)
2. Faça login com conta que tem ShopVivaliz
3. Procure por "Aplicações" ou "Apps conectados"
4. Reconecte ou autorize novamente ShopVivaliz
5. Capture novo `access_token` e `refresh_token`
6. Salve em `.env`

### Cenário B: Não tem acesso Olist
1. Abra link de OAuth:
```
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth
   ?client_id=tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553
   &redirect_uri=https://shopvivaliz.com.br/api/olist/callback.php
   &response_type=code
   &scope=openid profile email
```

2. Faça login
3. Autorize ShopVivaliz
4. Sistema captures tokens automaticamente
5. **PRONTO** ✓

---

## PASSO 3: TESTAR (5 min)

Criar pedido de TESTE:
- Produto: qualquer um
- Endereço: endereço válido
- Pagamento: PIX (mais rápido)

Ir para **Olist Dashboard** e verificar:
- ✅ Novo pedido apareceu?
- ✅ Dados corretos?
- ✅ Status = "pending"?

Se SIM → **RESOLVIDO** ✅

Se NÃO → Revisar logs:
```bash
tail -f /logs/pedidos.jsonl
grep "token" /logs/pedidos.jsonl
```

---

## PASSO 4: DEPOIS (30 min)

Depois que novo token funciona:

1. **Resync pedidos antigos** (opcionalmente)
   - Histórico de pedidos com `tiny_push: "token_unavailable"`
   - Idealmente: implementar endpoint `/api/olist/resync-failed.php`

2. **Adicionar monitoramento**
   - Check token expiration antes de usar
   - Alertar se < 7 dias para expirar

3. **Documentar o processo**
   - Como renovar manualmente
   - Como re-autenticar

---

## ARQUIVOS ENVOLVIDOS

| Arquivo | Responsabilidade |
|---------|-----------------|
| `.env` | Tokens (a ser atualizado) |
| `/api/olist/refresh-token.php` | Renovação automática |
| `/api/olist/callback.php` | OAuth callback |
| `/api/orders/create-v2.php` | Push para Olist (usa token) |

---

## SINAIS DE SUCESSO

```
ANTES:
  tiny_push: "token_unavailable"
  Olist Dashboard: sem pedido

DEPOIS:
  tiny_push: "ok"
  Olist Dashboard: pedido aparece
  Status: "pending_fulfillment"
```

---

## ⏰ TIMELINE

| Etapa | Tempo | O Que |
|-------|-------|-------|
| Renovação | 5 min | Tentar auto-refresh |
| Re-auth | 10-15 min | OAuth manual se preciso |
| Teste | 5 min | Fazer pedido + verificar |
| **TOTAL** | **20-25 min** | **PRONTO PARA PRODUÇÃO** |

---

## 🚨 NÃO ESQUECER

- [ ] `.env` foi atualizado com novo token?
- [ ] Backend foi reiniciado? (ou file watcher detectou)
- [ ] Pedido de teste foi criado?
- [ ] Apareceu no Olist?
- [ ] Status é "pending"?

Todos SIM? → **VAI PRO AR** 🚀

---

## CONTACTO RÁPIDO

Se travar:
1. Ler: `BLOQUEADOR-CRITICO-TOKEN-EXPIRADO.md` (contexto completo)
2. Verificar logs: `/logs/pedidos.jsonl`
3. Verificar `.env`: tokens são válidos?
4. Testar endpoint OAuth: HTTP 200 ou HTTP 401?

