# ⚙️ Configuração de Webhooks Olist/Tiny ERP

## Credenciais
- **Identificador:** 31816
- **Token:** 9fc7a699f6946099733fd722929652d38bb56b6f025c2ff15c51237325430878
- **Endpoint cotação:** https://erp.olist.com/webhook/api/v1/parceiro/31816/cotar

---

## URLs para Configurar na Olist

### ✅ Produtos (CRÍTICO)
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=product
```
**Eventos:** produto.criado, produto.atualizado, preco.alterado, estoque.alterado

### ✅ Estoque
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock
```
**Eventos:** estoque.alterado

### ✅ Preços
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=price
```
**Eventos:** preco.alterado

### ✅ Pedidos (Opcional inicialmente)
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=order
```
**Eventos:** pedido.criado, pedido.alterado, pedido.cancelado

### ✅ Rastreio (Opcional inicialmente)
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=tracking
```
**Eventos:** rastreio.alterado

### ✅ Nota Fiscal (Opcional inicialmente)
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=invoice
```
**Eventos:** nota_fiscal.emitida

---

## 🔧 Como Configurar na Olist

1. **Acesse:** https://erp.olist.com/
2. **Menu:** Configurações → Integrações → Webhooks
3. **Para cada URL acima:**
   - Cole a URL
   - Selecione os eventos desejados
   - Salve
   - **Teste** (Olist oferece botão de teste)

---

## ✅ Teste de Webhook

Após configurar:
1. Na Olist, clique em "Testar" para cada webhook
2. Verifique logs em: `/logs/webhook.log`
3. Atualize um produto no ERP
4. Confirm que: `https://shopvivaliz.com.br/api/catalog/products.php` retorna o produto atualizado em ~10 segundos

---

## 📊 Fluxo

```
Alteração no ERP Olist
    ↓
Webhook dispara para dev.shopvivaliz.com.br
    ↓
webhook-receiver.php processa
    ↓
sync-on-webhook.php sincroniza produtos
    ↓
storage/products-cache-ativos.json atualizado
    ↓
API retorna dados ao vivo (188 produtos)
    ↓
Site mostra alterações em tempo real ⚡
```

---

## 🚨 Troubleshooting

**Webhook não chega:**
- Verifique se URL está correta (HTTPS)
- Verifique se servidor está online
- Teste manualmente: `curl -X POST https://shopvivaliz.com.br/olist/webhook-receiver.php`

**Produtos não atualizam:**
- Verifique `/logs/webhook.log`
- Verifique `.env` tem token válido
- Tente sincronização manual: `python sync-products-to-json.py`

**Muitos webhooks causando lentidão:**
- Webhooks rodam em background via `exec()` em PHP
- Limite máximo de 5 sincronizações simultâneas no código

---

**PRONTO PARA PRODUÇÃO! 🚀**
