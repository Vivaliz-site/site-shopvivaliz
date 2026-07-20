# 📧 FLUXO CORRETO DE EMAILS COM MERCADO PAGO

**Data:** 2026-07-15  
**Status:** ✅ DOCUMENTADO E PRONTO  

---

## 🎯 FLUXO COMPLETO (Correto)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. CLIENTE CRIA PEDIDO (Site ShopVivaliz)                   │
├─────────────────────────────────────────────────────────────┤
│ - ID Local: ORD01KXJC418EH19N25A2TZYCVYHN                   │
│ - Arquivo salvo: /orders/ORD01KXJC418EH19N25A2TZYCVYHN.json │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. CLIENTE É REDIRECIONADO PARA MERCADO PAGO                │
├─────────────────────────────────────────────────────────────┤
│ - Link: checkout.mercadopago.com.br/...                     │
│ - Cliente escolhe forma de pagamento                        │
│ - Cliente paga                                              │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. MERCADO PAGO ENVIA WEBHOOK                               │
├─────────────────────────────────────────────────────────────┤
│ POST https://shopvivaliz.com.br/api/webhook-mp.php      │
│                                                              │
│ Headers:                                                     │
│ - X-Signature: [HMAC-SHA256 assinado]                       │
│ - X-Request-ID: [ID único da requisição]                    │
│                                                              │
│ Body:                                                        │
│ {                                                            │
│   "data": {                                                  │
│     "id": "123456789"  ← ID DO MERCADO PAGO (IMPORTANTE)    │
│   },                                                         │
│   "type": "payment"                                          │
│ }                                                            │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. WEBHOOK PROCESSA (webhook-mercadopago.php)               │
├─────────────────────────────────────────────────────────────┤
│ ✅ Valida assinatura HMAC-SHA256                            │
│ ✅ Busca o pedido local por external_reference              │
│ ✅ Atualiza status do pedido                                │
│                                                              │
│ SALVA NO PEDIDO:                                             │
│ {                                                            │
│   "order_number": "ORD01KXJC418EH19N25A2TZYCVYHN",         │
│   "status": "payment_approved",                             │
│   "mercadopago": {                                           │
│     "order_id": "123456789",  ← AGORA TEMOS O ID!           │
│     "payment_id": "98765432",                               │
│     "status": "approved",                                   │
│     "last_webhook_at": "2026-07-15T15:30:45Z"              │
│   }                                                          │
│ }                                                            │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. PÓS-PROCESSAMENTO (webhook-post-processor.php)           │
├─────────────────────────────────────────────────────────────┤
│ ✅ Lê arquivo do pedido atualizado                          │
│ ✅ Extrai ID do Mercado Pago (order_id ou payment_id)       │
│ ✅ Se status = "approved": envia email                      │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. EMAIL DE CONFIRMAÇÃO ENVIADO                             │
├─────────────────────────────────────────────────────────────┤
│ Para: fredmourao@gmail.com                                  │
│ Assunto: Pedido Confirmado - ShopVivaliz #ORD...           │
│                                                              │
│ Corpo:                                                       │
│ - Número do Pedido Local: ORD01KXJC418EH19N25A2TZYCVYHN    │
│ - ID Mercado Pago: 123456789  ← CORRETO!                    │
│ - Status: ✅ PAGAMENTO APROVADO                             │
│ - Total: R$ 99,90                                           │
│ - Link para acompanhamento                                  │
│                                                              │
│ Via: SMTP (Gmail, SendGrid, etc) ou mail() do PHP           │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. CLIENTE RECEBE EMAIL ✅                                   │
├─────────────────────────────────────────────────────────────┤
│ Email chega na caixa de entrada do cliente                  │
│ Cliente vê todos os detalhes do pedido                      │
│ Cliente pode acompanhar via link no email                   │
└─────────────────────────────────────────────────────────────┘
```

---

## 📝 ARQUIVOS ENVOLVIDOS

| Arquivo | Função | Status |
|---------|--------|--------|
| `checkout.php` | Redireciona para Mercado Pago | ✅ |
| `api/webhook-mercadopago.php` | Recebe webhook + processa | ✅ |
| `api/webhook-post-processor.php` | Envia email após webhook | ✅ |
| `api/send-order-confirmation-email.php` | Função de envio de email | ✅ |
| `.env` | Credenciais SMTP | ⏳ Precisa senha |
| `config/runtime-secrets.php` (VM) | Secrets sincronizados | ✅ |

---

## 🔧 COMO INTEGRAR (Passo a Passo)

### 1. Webhook executar post-processor

**Em: api/webhook-mercadopago.php (fim do arquivo)**

Adicionar após webhook ser processado com sucesso:

```php
// Se pagamento foi aprovado, enviar email
if ($localStatus === 'payment_approved') {
    // Chamar post-processor em background
    $orderPath = svmp_find_order_path($externalReference);
    if ($orderPath) {
        // Executar em background (non-blocking)
        $cmd = "php " . escapeshellarg(__DIR__ . '/webhook-post-processor.php') . " " .
               escapeshellarg($externalReference) . " " .
               escapeshellarg($orderPath);
        
        // Linux/Mac
        exec("$cmd > /dev/null 2>&1 &");
        // Windows
        // pclose(popen("start /B " . $cmd, "r"));
    }
}
```

### 2. Configurar SMTP (Uma única vez)

**GitHub Secrets:**
```bash
gh secret set SMTP_PASS --body "sua_senha_app_gmail"
```

**Disparar sync:**
```bash
gh workflow run sync-oracle-vm-secrets.yml
```

### 3. Testar fluxo completo

**Criar pedido teste:**
```bash
# 1. Acessar site
# 2. Criar carrinho
# 3. Ir para checkout
# 4. Escolher Mercado Pago
# 5. Fazer pagamento teste
# 6. Aguardar webhook
# 7. Verificar email recebido ✅
```

---

## 📧 DADOS NO EMAIL

**O email conterá:**

```
═══════════════════════════════════════════════════════

DADOS DO PEDIDO

✅ Número do Pedido (Local): ORD01KXJC418EH19N25A2TZYCVYHN
✅ Número do Pedido (Mercado Pago): 123456789
✅ ID do Pagamento: 98765432
✅ Data: 15/07/2026 15:30:45
✅ Status: PAGAMENTO APROVADO

VALOR: R$ 99,90

═══════════════════════════════════════════════════════

PRÓXIMOS PASSOS

1. ✅ Seu pagamento foi aprovado
2. ⏳ Equipe confirmará frete
3. ⏳ Você receberá rastreamento
4. ⏳ Acompanhe seu pedido

Link: https://shopvivaliz.com.br/meu-pedido?order=ORD...

═══════════════════════════════════════════════════════
```

---

## ✅ CHECKLIST DE IMPLEMENTAÇÃO

- [x] Script webhook-mercadopago.php (recebe webhook)
- [x] Script webhook-post-processor.php (envia email)
- [x] Script send-order-confirmation-email.php (função auxiliar)
- [x] Credenciais SMTP no GitHub Secrets
- [x] Sincronização de secrets para VM
- [ ] **INTEGRAR** post-processor no webhook (seu trabalho)
- [ ] **TESTAR** fluxo completo com pedido real
- [ ] **CONFIRMAR** recebimento de email no Gmail

---

## 🚀 RESUMO FINAL

**ID CORRETO vem do Mercado Pago no webhook.**

```
❌ ERRADO: Gerar ID local (ORD9531A4F2812EF2E003C5623)
✅ CORRETO: Usar ID do Mercado Pago (vem do webhook)
```

**Fluxo correto:**
1. Cliente paga ✅
2. Mercado Pago envia webhook com ID ✅
3. Webhook processa e salva ID ✅
4. Post-processor lê ID e envia email ✅
5. Cliente recebe email com ID correto ✅

---

**Status:** ✅ PRONTO PARA IMPLEMENTAR  
**Próximo passo:** Integrar webhook-post-processor.php no webhook

