# Issue: Sincronização de Produtos via Webhook Não Funciona

## Problema
Alterações na Olist (preço, estoque, status, imagens, deletar produto) **NÃO são replicadas automaticamente** para o site.

## Causa Raiz
1. **Webhook recebendo mas NÃO processando**
   - `api/olist/webhook.php` apenas loga eventos e retorna OK
   - Não atualiza banco de dados com mudanças
   - Não remove produtos deletados
   - Não sincroniza preços e estoque

2. **SKUs Desincronizados**
   - Site tem produtos que não existem mais na Olist (ex: maçaneta)
   - Aparece 2x porque banco e fallback JSON estão dessinc

3. **Falta de Processador**
   - Webhook precisa de logic de processamento para:
     - Atualizar preço
     - Atualizar estoque  
     - Atualizar imagens
     - Remover produtos deletados
     - Atualizar fallback JSON após mudanças

## Solução

### 1. Novo Arquivo: `/api/olist/webhook-processor.php`
- ✅ Criado
- Processa eventos da Olist
- Atualiza banco de dados em tempo real
- Remove produtos deletados
- Loga todas as operações

### 2. Configurar Webhook da Olist
Modificar `api/olist/webhook.php` para chamar o processador:
```php
// Em api/olist/webhook.php adicionar:
require_once __DIR__ . '/webhook-processor.php';
```

Ou melhor ainda: configurar a Olist para enviar webhooks direto para:
```
https://shopvivaliz.com.br/api/olist/webhook-processor.php
```

### 3. Status de Cada Componente
- ✅ Webhook recebimento: **FUNCIONA** (logs mostram eventos recebidos)
- ❌ Processamento: **QUEBRADO** (processador novo precisa ser ativado)
- ❌ Fallback JSON: **DESATUALIZADO** (tem SKUs antigos, não sincroniza com mudanças)
- ❌ Preços: **NÃO SINCRONIZAM** (resultado do processamento quebrado)

## Próximos Passos

1. **URGENTE:** Ativar webhook-processor.php na Olist
2. Testar se webhook processa corretamente (check logs em `/logs/olist-webhook-processor.log`)
3. Criar script que reconstrói fallback JSON após cada webhook (para produtos que ainda existem)
4. Validar se produtos deletados são removidos do site
5. Validar se preços sincronizam automaticamente

## Webhook Events Esperados da Olist
- `product.updated` → atualizar produto
- `product.price.updated` → atualizar preço
- `product.stock.updated` → atualizar estoque
- `product.deleted` → remover do banco
- `product.images.updated` → atualizar imagens

## Teste
Após ativar webhook-processor.php:
1. Alterar preço de um produto na Olist
2. Aguardar webhook (deve ser instantâneo)
3. Verificar se banco foi atualizado: `SELECT sku, price FROM products WHERE sku = 'xxx';`
4. Verificar log: `tail -f /logs/olist-webhook-processor.log`
