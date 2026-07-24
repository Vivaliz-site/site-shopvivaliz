-- ============================================================================
-- SCRIPT: Adicionar Índices Críticos no Banco
-- Data: 2026-07-24
-- Objetivo: Corrigir performance de busca e queries críticas
-- ============================================================================

-- ✅ ÍNDICE 1: Busca de produtos por nome (crítico para /catalogo?busca=)
ALTER TABLE products ADD FULLTEXT INDEX idx_search_name (name);
ALTER TABLE products ADD FULLTEXT INDEX idx_search_description (description);
ALTER TABLE products ADD FULLTEXT INDEX idx_search_name_desc (name, description);

-- ✅ ÍNDICE 2: Filtros de produtos (active, stock, preço)
ALTER TABLE products ADD INDEX idx_active_stock (active, stock);
ALTER TABLE products ADD INDEX idx_active_price (active, price);
ALTER TABLE products ADD INDEX idx_price_stock (price, stock);

-- ✅ ÍNDICE 3: Busca por SKU (usado em adicionar carrinho)
ALTER TABLE products ADD UNIQUE INDEX idx_sku_unique (sku);

-- ✅ ÍNDICE 4: Pedidos do usuário (para /meus-pedidos)
ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at DESC);
ALTER TABLE orders ADD INDEX idx_user_status (user_id, status);

-- ✅ ÍNDICE 5: Busca de pedidos por número
ALTER TABLE orders ADD UNIQUE INDEX idx_order_number (order_number);

-- ✅ ÍNDICE 6: Olist sync (para webhooks)
ALTER TABLE products ADD INDEX idx_olist_id (olist_id);
ALTER TABLE products ADD INDEX idx_olist_updated (olist_id, updated_at);

-- ✅ ÍNDICE 7: Busca por categoria (REMOVIDO - coluna category_id não existe)
-- ALTER TABLE products ADD INDEX idx_category_active (category_id, active);

-- ============================================================================
-- VALIDAÇÃO: Verificar índices criados
-- ============================================================================
-- Execute depois para verificar:
-- SHOW INDEXES FROM products;
-- SHOW INDEXES FROM orders;

-- ============================================================================
-- PERFORMANCE: Testar busca com FULLTEXT
-- ============================================================================
-- SELECT * FROM products WHERE MATCH(name, description) AGAINST('rodizio' IN BOOLEAN MODE);
-- SELECT * FROM products WHERE active = 1 AND price > 0 AND stock > 0 LIMIT 20;

-- ============================================================================
-- CLEANUP (se precisar remover um índice)
-- ============================================================================
-- ALTER TABLE products DROP INDEX idx_search_name;
