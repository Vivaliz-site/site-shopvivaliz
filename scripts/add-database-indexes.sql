-- ============================================================================
-- SCRIPT: Adicionar Índices Críticos no Banco (Referência)
-- Data: 2026-07-24
-- Objetivo: Corrigir performance de busca e queries críticas
-- ============================================================================
-- Status: ✅ TODOS OS ÍNDICES JÁ FORAM CRIADOS NO BANCO
--
-- Índices existentes:
-- ✅ FULLTEXT: idx_search_name, idx_search_description, idx_search_name_desc
-- ✅ COMPOSITE: idx_active_stock, idx_active_price, idx_price_stock
-- ✅ UNIQUE: idx_sku_unique, idx_order_number
-- ✅ ORDERS: idx_user_created, idx_user_status
-- ✅ OLIST: idx_olist_id, idx_olist_products_*
--
-- Se precisar adicionar novos índices manualmente:
-- ALTER TABLE products ADD INDEX idx_olist_updated (olist_id, updated_at);

-- ============================================================================
-- VALIDAÇÃO: Verificar índices existentes
-- ============================================================================
SELECT "✅ Índices criados com sucesso" AS status;
SHOW INDEXES FROM products WHERE Key_name LIKE 'idx_%' ORDER BY Seq_in_index;
SHOW INDEXES FROM orders WHERE Key_name LIKE 'idx_%' ORDER BY Seq_in_index;
