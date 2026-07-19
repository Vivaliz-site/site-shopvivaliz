# Pedido Real - Autenticação Mercado Pago Developers

**Data:** 2026-07-14 21:19:33 UTC  
**Status:** ✅ PEDIDO CRIADO E PRONTO PARA AUTENTICAÇÃO

## 🆔 ID DO PEDIDO (Para Mercado Pago)

```
PED-20260714211933
```

## 📋 Detalhes do Pedido

| Campo | Valor |
|-------|-------|
| **ID** | PED-20260714211933 |
| **Cliente** | Pedido ID Exato |
| **Email** | pedido-id-exato@test.com |
| **Telefone** | (37) 99999-1234 |
| **Produto** | Rodízio 75mm |
| **Quantidade** | 1 |
| **Valor** | R$ 76,00 |
| **Método de Pagamento** | Boleto Bancário |
| **Status** | Pendente |

## 🏠 Endereço de Entrega

```
Rua ID Exato, 123
Divinópolis - MG
CEP: 35501-236
```

## 💳 Autenticação Mercado Pago

**Plataforma:** https://www.mercadopago.com.br/developers

**Seções para testar:**
1. Orders → Buscar por `PED-20260714211933`
2. Payments → Validar transação de boleto
3. Webhooks → Testar callback
4. API Reference → Validar integração

**Casos de uso:**
- ✅ Webhook de confirmação de pagamento
- ✅ Rastreamento de pedido
- ✅ Integração de boleto
- ✅ Status de transação
- ✅ Callback de frete

## 🔗 Links Úteis

| Link | Descrição |
|------|-----------|
| `https://shopvivaliz.com.br/pedido?id=PED-20260714211933` | Página do pedido |
| `https://shopvivaliz.com.br/admin/pedidos.php` | Admin - Todos os pedidos |
| `https://shopvivaliz.com.br/checkout` | Checkout para nova compra |

## ✅ Checklist de Testes

- [x] Pedido criado no BD
- [x] Email de confirmação enviado
- [x] ID do pedido capturado
- [x] Boleto registrado como método de pagamento
- [x] Documentação para Mercado Pago
- [ ] Webhook do Mercado Pago testado
- [ ] Boleto gerado e emitido
- [ ] Pagamento confirmado

---

**Sistema ShopVivaliz:** Pronto para testes de integração com Mercado Pago  
**Data de Criação:** 2026-07-14 21:19:33 UTC
