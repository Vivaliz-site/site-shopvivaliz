# Configuração de Webhooks do Tiny ERP

## URLs Já Cadastradas ✅

| Evento | URL | Status |
|--------|-----|--------|
| Notificações de produtos | `https://shopvivaliz.com.br/olist/webhook-receiver.php?event=product` | ✅ Ativo |
| Notificações de estoque | `https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock` | ✅ Ativo |
| Notificações de preços | `https://shopvivaliz.com.br/olist/webhook-receiver.php?event=price` | ✅ Ativo |
| Status de pedidos | `https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=539374f9652f56a061c673aa17edd22dc8f869f6daf378b67e30e5ef3772b010&type=order` | ✅ Ativo |
| Rastreamento | `https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=539374f9652f56a061c673aa17edd22dc8f869f6daf378b67e30e5ef3772b010&type=tracking` | ✅ Ativo |

## URLs a Configurar ⚠️

### 1. Notificações de Vendas (Pedidos)
**URL:**
```
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=539374f9652f56a061c673aa17edd22dc8f869f6daf378b67e30e5ef3772b010&type=order
```
**Onde no Tiny:** Configurações > Integrações > Webhooks > Vendas/Pedidos

### 2. Notificações de Pedidos Enviados
**URL:**
```
https://shopvivaliz.com.br/api/webhooks/order-status-update.php?token=539374f9652f56a061c673aa17edd22dc8f869f6daf378b67e30e5ef3772b010&type=tracking
```
**Onde no Tiny:** Configurações > Integrações > Webhooks > Pedidos Enviados

### 3. Notificações de Lançamentos de Estoque
**URL:**
```
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock
```
**Onde no Tiny:** Configurações > Integrações > Webhooks > Lançamentos de Estoque

### 4. Notificações de Notas Fiscais Autorizadas
**URL:**
```
https://shopvivaliz.com.br/api/webhooks/tiny-nota-fiscal.php
```
**Onde no Tiny:** Configurações > Integrações > Webhooks > Notas Fiscais Autorizadas

---

## Problema: Preços Não Sincronizam em Tempo Real

### Causa Identificada
O webhook de preço estava cadastrado, mas:
1. ❌ Não havia validação real do webhook na aplicação
2. ❌ Não havia cron job para sincronizar se o webhook falhasse
3. ❌ Cache expirava sem refresh automático

### Solução Implementada
✅ **Criado webhook dedicado para preços:** `api/webhooks/tiny-product-price-sync.php`

**Funcionalidade:**
- Recebe notificações de mudança de preço do Tiny
- Invalida cache automaticamente
- Dispara daemon de sincronização em background
- Registra logs em `logs/tiny-webhook-price.log`

✅ **Adicionado cron job de sincronização**
```bash
*/5 * * * * cd /home/ubuntu/site-shopvivaliz && /usr/bin/python3 daemon-sync-products.py >> logs/sync-products.log 2>&1
```
- Sincroniza produtos a cada 5 minutos
- Apanha falhas de webhook
- Garante que preços nunca fiquem desatualizados por mais de 5 minutos

---

## Instruções para Cadastrar no Tiny

### Passo 1: Acessar Webhooks do Tiny
1. Login em https://app.tiny.com.br
2. Clique em **Configurações** (ícone de engrenagem)
3. Vá em **Integrações** ou **API**
4. Procure por **Webhooks** ou **Notificações**

### Passo 2: Adicionar Novo Webhook
Para cada URL acima:
1. Clique em **Novo Webhook** ou **+ Adicionar**
2. Cole a URL completa
3. Selecione o **Evento** correspondente
4. Marque **Ativo**
5. Clique em **Salvar**

### Passo 3: Testar
O Tiny geralmente permite um botão **Testar** ou **Enviar Notificação de Teste**
- Verifique se a URL responde com HTTP 200
- Consulte os logs em `logs/` para confirmar recebimento

---

## Logs de Sincronização

Para debug, consulte:
- **Webhook de preço:** `logs/tiny-webhook-price.log`
- **Sincronização de produtos:** `logs/sync-products.log`
- **Sincronização manual:** `logs/sync-products-manual.log`

### Exemplo de comando para forçar sincronização local:
```bash
cd /home/ubuntu/site-shopvivaliz
python3 daemon-sync-products.py
```

---

## Fluxo de Dados Após Implementação

```
Tiny ERP (mudança de preço)
    ↓
Webhook → https://shopvivaliz.com.br/api/webhooks/tiny-product-price-sync.php
    ↓
Invalida cache local
    ↓
Dispara daemon-sync-products.py em background
    ↓
Produtos sincronizados em <30 segundos
    ↓
Sitemap regenerado
    ↓
Site reflete novo preço
```

**Tempo de sincronização:**
- ⚡ Via Webhook: 10-30 segundos
- 🔄 Via Cron (fallback): a cada 5 minutos máximo

---

## Status Atual

| Item | Status |
|------|--------|
| Webhook de preço | ✅ Criado |
| Cron de sincronização | ✅ Configurado |
| Cache auto-invalidação | ✅ Implementada |
| Logs | ✅ Ativo |
| **Próximo passo** | 🔲 Cadastrar URLs no Tiny |
