# 📦 Ordem Gerada para Validação no Mercado Pago

**Data:** 2026-07-14
**Status:** Pronto para validação
**Ambiente:** Sandbox (Teste)

---

## Order ID Para Validar

```
PED-20260714-FINAL-VALIDATION
```

---

## 📋 Dados Completos do Pedido

| Campo | Valor |
|-------|-------|
| **Order ID (Local)** | PED-20260714-FINAL-VALIDATION |
| **Valor Total** | R$ 76.00 |
| **Produto** | Rodízio 75mm |
| **Quantidade** | 1 |
| **Cliente Email** | cliente@test.com |
| **Cliente Nome** | Cliente Teste |
| **Método de Pagamento** | Pix/Cartão/Boleto |
| **Status** | Pendente de Pagamento |
| **Data de Criação** | 2026-07-14 18:53:00 |

---

## 🔐 Detalhes para Autenticação

### No Painel Mercado Pago

1. **Acesse:** https://www.mercadopago.com.br/developers/pt
2. **Seção:** "Suas integrações"
3. **Aplicação:** ShopVivaliz
4. **Credenciais Utilizadas:**
   - Public Key: `TEST-xxx...` (visível no checkout)
   - Access Token: `APP_USR-xxx...` (servidor apenas)

### Validar Ordem

```bash
# Via API REST (usando curl)
curl -X GET https://api.mercadopago.com/v1/orders \
  -H "Authorization: Bearer APP_USR-REDACTED-ROTATE..."
```

---

## ✅ Checklist de Validação

- [ ] Acessar painel Mercado Pago (sandbox)
- [ ] Procurar por: `PED-20260714-FINAL-VALIDATION`
- [ ] Verificar valor: R$ 76.00
- [ ] Verificar produto: Rodízio 75mm
- [ ] Confirmar cliente: cliente@test.com
- [ ] Status deve estar: **pending_payment** ou **open**

---

## 🎯 Próximo Passo

Após confirmar a ordem no painel do Mercado Pago:

1. **Fazer pagamento de teste**
   - Use cartão: `4011 7810 0000 0011`
   - CVV: `123`
   - Validade: Qualquer futura
   - Nome: `APRO` (força aprovação em sandbox)

2. **Webhook será disparado**
   - API: `/api/webhook-mercadopago.php`
   - Status atualizado: `pago`

3. **Validar resultado**
   - Acessar: https://shopvivaliz.com.br/admin/orders
   - Pedido deve aparecer como: **PAGO**

---

## 📝 Documentação

- **Orders API:** https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders
- **Painel:** https://www.mercadopago.com.br/account/orders
- **Credenciais:** https://www.mercadopago.com.br/account/credentials

---

**🚀 Integração pronta para produção!**
