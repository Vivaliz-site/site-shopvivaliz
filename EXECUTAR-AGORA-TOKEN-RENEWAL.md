# ⚡ EXECUTAR AGORA: Token Renewal (30 min)

**Responsável**: Admin / Deploy Engineer  
**Tempo**: 30 minutos máximo  
**Prioridade**: 🔴 CRÍTICA  
**Impacto**: Pedidos vão sincronizar com ERP após isto  

---

## PASSO 1️⃣: TENTAR RENOVAÇÃO AUTOMÁTICA (5 min)

### Opção A: Via Browser (Mais fácil)

```
1. Abra: https://dev.shopvivaliz.com.br/api/olist/refresh-token.php
2. Aguarde resposta (deve aparecer HTML)
3. Procure por:
   ✅ "Tokens refreshed and saved" = SUCESSO
   ❌ "Token refresh failed" = FALHA
```

### Opção B: Via Linha de Comando (SSH para VM Oracle)

```bash
ssh -i ~/.ssh/oracle-key ubuntu@137.131.156.17

cd /home/ubuntu/site-shopvivaliz

# Executar script de refresh
php api/olist/refresh-token.php
```

**Saída esperada se sucesso**:
```
Refreshing Olist token...
HTTP 200
✓ Tokens refreshed and saved
```

**Saída se falha**:
```
Refreshing Olist token...
HTTP 401
✗ Token refresh failed
{"error": "invalid_grant", "error_description": "Token is invalid or has expired"}
```

---

### Resultado do PASSO 1:

- ✅ **SE SUCESSO** → Vá para PASSO 3 (Testar)
- ❌ **SE FALHA (HTTP 401)** → Vá para PASSO 2 (Re-auth)

---

## PASSO 2️⃣: RE-AUTENTICAR (Se PASSO 1 falhou) - 10-15 min

### Cenário A: Tem Acesso ao Dashboard Olist

```
1. Abra: https://www.tiny.com.br/admin
2. Login com credenciais Olist
3. Procure: Integrações / Aplicações Conectadas
4. Procure: ShopVivaliz (ou algo similar)
5. Desconectar e reconectar
6. Autorizar novamente
7. Capturar novo Access Token + Refresh Token
8. Atualizar .env (veja abaixo)
```

### Cenário B: Não Tem Acesso Olist

```
1. Abra link de OAuth:
   https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth
      ?client_id=tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553
      &redirect_uri=https://dev.shopvivaliz.com.br/api/olist/callback.php
      &response_type=code
      &scope=openid profile email offline_access

2. Faça login
3. Clique "Autorizar"
4. Será redirecionado para:
   https://dev.shopvivaliz.com.br/api/olist/callback.php?code=...

5. Esse callback.php processa e salva novo token no .env automaticamente

6. Se sucesso: verá mensagem de confirmação
```

### Resultado do PASSO 2:

- ✅ Novo token capturado e salvo em `.env`
- → Vá para PASSO 3 (Testar)

---

## PASSO 3️⃣: VERIFICAR QUE FUNCIONOU (10 min)

### Verificação 1: Ver tokens no `.env`

```bash
# SSH para VM Oracle
ssh -i ~/.ssh/oracle-key ubuntu@137.131.156.17

# Ver tokens
grep "OLIST_" /home/ubuntu/site-shopvivaliz/.env

# Esperado:
# OLIST_ACCESS_TOKEN=novo_token_aqui_12345...
# OLIST_CLIENT_ID=tiny-api-...
# OLIST_CLIENT_SECRET=...
# OLIST_REFRESH_TOKEN=eyJhb...  (novo JWT)
```

Se tokens foram atualizados → ✅ Sucesso na renovação

### Verificação 2: Criar Pedido de Teste

**Fazer um pedido de teste no site**:

```
1. Ir para https://dev.shopvivaliz.com.br/
2. Adicionar produto ao carrinho
3. Ir para checkout
4. Preencher dados:
   - Email: test@example.com
   - Nome: Test Renewal
   - Endereço: Qualquer endereço válido (ex: São Paulo)
   - Pagamento: PIX (mais rápido de testar)
5. Clique "Confirmar Pedido"
6. Aguarde confirmação
```

**Procurar pelo pedido criado**:

```bash
# Pedido deve aparecer em:
ls -la /home/ubuntu/site-shopvivaliz/storage/orders/

# Deve ter arquivo mais recente:
tail -1 storage/orders/*.json
```

**Verificar campo `tiny_push` no pedido novo**:

```json
// Abrir arquivo JSON do pedido mais recente
{
  "order_number": "SV20260712...",
  "tiny_push": "ok"  // ← DEVE SER "ok"
}
```

**Se `tiny_push: "ok"`** → ✅ PEDIDO SINCRONIZOU COM ERP

### Verificação 3: Confirmar no Olist

```
1. Login no Olist Dashboard
2. Procure pedido mais recente
3. Deve ter número tipo: "SV20260712..."
4. Status deve ser "Aberto" ou similar
```

**Se pedido aparece em Olist** → ✅ SUCESSO COMPLETO

---

## ✅ CHECKLIST FINAL

```
[ ] PASSO 1: Tentou renovação automática
    [ ] HTTP 200 (sucesso) OU
    [ ] HTTP 401 (falha, foi para PASSO 2)

[ ] PASSO 2: Re-autenticou (se necessário)
    [ ] Novo token capturado
    [ ] Tokens salvos em .env

[ ] PASSO 3: Verificou funcionamento
    [ ] Novo token em .env?
    [ ] Pedido de teste criado?
    [ ] tiny_push = "ok" no novo pedido?
    [ ] Pedido aparece no Olist?

[ ] ✅ TUDO OK = PRONTO PARA PRODUÇÃO
```

---

## 🚨 SE FICAR PRESO

### Se PASSO 1 falha com 401:
- Refresh token TAMBÉM expirou
- Ir para PASSO 2 (re-auth obrigatória)

### Se PASSO 2 falha:
- Talvez credenciais Olist erradas
- Verificar: OLIST_CLIENT_ID e OLIST_CLIENT_SECRET estão corretos?
- Se duvidoso: entrar em contato com suporte Olist

### Se PASSO 3 mostra `tiny_push: "token_unavailable"` ainda:
- Talvez backend ainda não foi recarregado
- Reiniciar PHP-FPM: `systemctl restart php8.2-fpm`
- Tentar novo pedido

### Se PASSO 3 mostra `tiny_push: "error"`:
- Novo token funciona MAS dados do pedido estão errados
- Verificar formato dos dados sendo enviados
- Consultar logs: `/logs/pedidos.jsonl`

---

## 📞 TEMPO ESTIMADO

| Etapa | Tempo | Total |
|-------|-------|-------|
| PASSO 1 (Auto-refresh) | 5 min | 5 min |
| PASSO 2 (Re-auth, se needed) | 10-15 min | 15-20 min |
| PASSO 3 (Teste) | 10 min | 25-30 min |
| **TOTAL** | | **< 30 min** |

---

## 🎯 RESULTADO ESPERADO

**Depois de tudo isto**:
- ✅ Token renovado/atualizado
- ✅ Novo pedido sincroniza com Olist
- ✅ Campo `tiny_push: "ok"` aparece
- ✅ Pedido visível no Olist Dashboard
- ✅ **Sistema pronto para receber pedidos REAIS**

---

## 🚀 PRÓXIMO PASSO APÓS ISTO

1. Auditoria paralela continua testando outras fases
2. Quando tudo OK → Validar segurança
3. Quando tudo validado → DEPLOY PARA PRODUÇÃO

---

**Este é o ÚNICO bloqueador crítico.**

**Depois disto, tudo mais funciona.**

⚡ **COMEÇAR AGORA** ⚡

