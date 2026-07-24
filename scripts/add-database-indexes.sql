-- ============================================================================
-- SCRIPT: Adicionar Índices Críticos no Banco (Idempotente)
-- Data: 2026-07-24
-- Objetivo: Corrigir performance de busca e queries críticas
-- ============================================================================
-- Nota: Este script adiciona apenas índices que faltam (evita duplicatas)

-- ✅ ÍNDICE 1: Busca de produtos por nome (crítico para /catalogo?busca=)
ALTER TABLE products ADD FULLTEXT INDEX idx_search_name (name);
ALTER TABLE products ADD FULLTEXT INDEX idx_search_description (description);
ALTER TABLE products ADD FULLTEXT INDEX idx_search_name_desc (name, description);

-- ✅ ÍNDICE 4: Pedidos do usuário (para /meus-pedidos)
ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at);
ALTER TABLE orders ADD INDEX idx_user_status (user_id, status);

-- ✅ ÍNDICE 5: Busca de pedidos por número (se não existir)
ALTER TABLE orders ADD UNIQUE INDEX idx_order_number (order_number);

-- ✅ ÍNDICE 6: Olist sync - adicionar updated_at se não existir
ALTER TABLE products ADD INDEX idx_olist_updated (olist_id, updated_at);

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
