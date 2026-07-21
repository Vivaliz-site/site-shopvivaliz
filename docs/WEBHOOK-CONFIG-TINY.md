# Configuração de Webhooks - Tiny ERP

Guia para configurar os webhooks do Tiny ERP para sincronizar dados com o site em tempo real.

## 🎯 Webhooks Disponíveis

### 1. **Sincronização de Preços** (CRÍTICO)
**URL:** `https://shopvivaliz.com.br/api/webhooks/tiny-product-price-sync.php`

**Quando configurar:** Quando um preço de produto é alterado no Tiny

**O que faz:**
- Recebe notificação de mudança de preço
- Atualiza IMEDIATAMENTE no cache do site
- Sem delay - preço reflete em tempo real

**Tipo de evento:** `PRODUTO_ATUALIZADO` com campo `precos.preco`

---

### 2. **Sincronização de Estoque** (RECOMENDADO)
**URL:** `https://shopvivaliz.com.br/api/tiny/stock-webhook.php`

**Quando configurar:** Quando estoque é alterado no Tiny

**O que faz:**
- Recebe notificação de mudança de estoque
- Atualiza disponibilidade no site
- Bloqueia compra de itens sem estoque

**Tipo de evento:** `ESTOQUE_ATUALIZADO`

---

### 3. **Sincronização Olist** (OPCIONAL)
**URL:** `https://shopvivaliz.com.br/olist/webhook-receiver.php`

**Quando configurar:** Para sincronizar múltiplos canais

**O que faz:**
- Recebe notificações gerais da Olist
- Sincroniza produtos, preços, estoque, pedidos
- Funciona como fallback caso Tiny falhe

**Tipo de evento:** Todos (produto, preço, estoque, pedido)

---

## 🔧 Como Configurar no Tiny

### Passo 1: Acessar Configurações
1. Acesse o **Painel Tiny** (https://www.tiny.com.br/)
2. Vá para **Configurações** → **E-commerce**
3. Selecione sua **Integração** (Olist/Marketplace)

### Passo 2: Adicionar Webhook de Preços
1. Em **Webhooks** ou **Notificações**, clique **Adicionar novo webhook**
2. **Tipo de evento:** `PRODUTO_ATUALIZADO` ou equivalente
3. **URL:** `https://shopvivaliz.com.br/api/webhooks/tiny-product-price-sync.php`
4. **Método:** POST
5. **Formato:** JSON
6. **Clique em Salvar**

### Passo 3: Adicionar Webhook de Estoque (Opcional)
1. Clique **Adicionar novo webhook**
2. **Tipo de evento:** `ESTOQUE_ATUALIZADO`
3. **URL:** `https://shopvivaliz.com.br/api/tiny/stock-webhook.php`
4. **Método:** POST
5. **Formato:** JSON
6. **Clique em Salvar**

### Passo 4: Testar Webhooks
1. Altere um **preço** no Tiny
2. Verifique se refletiu no site em 1-5 segundos
3. Verifique o log: `/logs/tiny-webhook-price.log`

---

## 📊 Logs e Monitoramento

### Ver Log de Preços
```bash
tail -f logs/tiny-webhook-price.log
```

**Exemplo de sucesso:**
```
[2026-07-20 23:30:15] === WEBHOOK RECEBIDO ===
[2026-07-20 23:30:15] Tamanho: 512 bytes
[2026-07-20 23:30:15] SKU: KITROD12
[2026-07-20 23:30:15] Novo preço: R$ 149.99
[2026-07-20 23:30:15] Preço atualizado em storage/products-cache-ativos.json
[2026-07-20 23:30:15] ✓ SUCESSO: Preço atualizado no cache
```

### Ver Log de Estoque
```bash
tail -f logs/tiny-stock-webhook.log
```

### Ver Log Olist
```bash
tail -f logs/webhook.log
```

---

## 🔐 Segurança

Os webhooks não requerem autenticação por enquanto (Tiny não suporta).

**Para adicionar autenticação:**
1. Gere um token aleatório
2. Configure como variável de ambiente: `TINY_WEBHOOK_TOKEN`
3. Altere o código para validar `Authorization: Bearer {token}`

---

## ✅ Checklist de Verificação

- [ ] Webhook de preços configurado no Tiny
- [ ] Webhook de estoque configurado (opcional)
- [ ] Testou mudança de preço → refletiu no site
- [ ] Testou mudança de estoque → refletiu no site
- [ ] Verificou logs: `/logs/tiny-webhook-price.log`
- [ ] Verificou que preços atualizam em < 5 segundos

---

## 🚨 Troubleshooting

### Preço não atualiza no site

**Causa 1:** Webhook não está configurado no Tiny
- Verificar em Tiny → Configurações se URL está salva
- Testar acessando a URL direto: `https://shopvivaliz.com.br/api/webhooks/tiny-product-price-sync.php` (retorna erro 400 é normal)

**Causa 2:** SKU não bate entre Tiny e site
- Verificar se SKU no Tiny é idêntico ao no site
- Exemplo: `KITROD12` (sem espaços)

**Causa 3:** Arquivo de cache corrompido
- Deletar: `/storage/products-cache-ativos.json`
- Forçar resync: `/scripts/daemon-sync-products.py`

**Causa 4:** Permissões insuficientes
- Verificar que `/storage/` e `/logs/` têm permissão 755
- `chmod 755 storage/ logs/`

---

## 📝 Payload Esperado

**Exemplo de webhook de preço:**

```json
{
  "tipo": "PRODUTO_ATUALIZADO",
  "produto": {
    "sku": "KITROD12",
    "codigo": "KITROD12",
    "precos": {
      "preco": 149.99,
      "preco_venda": 149.99
    },
    "estoque_disponivel": 25
  }
}
```

**Exemplo de webhook de estoque:**

```json
{
  "sku": "KITROD12",
  "estoque_disponivel": 15,
  "quantidade": 15
}
```

---

**Última atualização:** 20/07/2026  
**Status:** ✅ Em produção  
**Contato:** fredmourao@gmail.com
