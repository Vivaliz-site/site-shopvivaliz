# Issue: Produtos Deletados da Olist Ainda Aparecem no Site

## Problema
Alguns produtos que foram deletados da Olist ainda aparecem no catálogo do site.

## Causa Raiz
1. Token de autenticação Olist expirou (`Token is not active`)
2. Sem token válido, script `olist/sync-products.php` não consegue buscar lista atualizada de produtos
3. Arquivo `/api/catalog/fallback-products.json` não é atualizado
4. Produtos que foram deletados da Olist permanecem no arquivo local

## Solução Imediata (Workaround)
Use script para remover produtos específicos:

```bash
# Remove um produto pelo olist_product_id ou sku
php api/catalog/remove-product.php <OLIST_ID ou SKU>

# Exemplos:
php api/catalog/remove-product.php 337703208
php api/catalog/remove-product.php 9976-284
```

## Solução Permanente
1. Renovar credenciais Olist/Tiny no dashboard da conta
2. Atualizar `.env` com novo token
3. Executar sync completo: `php olist/sync-products.php`

## Status
- ✅ Script de remoção criado: `/api/catalog/remove-product.php`
- ✅ Script de renovação de token criado: `/api/olist/refresh-token.php` (aguarda credenciais válidas)
- ❌ Credenciais Olist precisam ser renovadas manualmente
- 📝 Aguardando lista de produtos a remover

## Próximas Ações
Informe quais produtos devem ser removidos (olist_product_id ou sku):
- Cada produto será removido do catálogo local
- Alterações serão commitadas e deployadas
- Após credenciais serem renovadas, sync automático restaurará sincronização
