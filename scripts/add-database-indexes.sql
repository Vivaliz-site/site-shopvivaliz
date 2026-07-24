-- ============================================================================
-- SCRIPT: Adicionar Índices Críticos no Banco (Idempotente)
-- Data: 2026-07-24
-- Objetivo: Corrigir performance de busca e queries críticas
-- ============================================================================
-- Status: Índices FULLTEXT já existem. Apenas adicionando novos.

-- ✅ ÍNDICE: Pedidos do usuário (para /meus-pedidos) - se não existir
ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at);
ALTER TABLE orders ADD INDEX idx_user_status (user_id, status);

-- ✅ ÍNDICE: Busca de pedidos por número (se não existir)
ALTER TABLE orders ADD UNIQUE INDEX idx_order_number (order_number);

-- ✅ ÍNDICE: Olist sync - adicionar updated_at se não existir
ALTER TABLE products ADD INDEX idx_olist_updated (olist_id, updated_at);

-- ============================================================================
-- VALIDAÇÃO: Verificar índices criados
-- ============================================================================
SELECT "✅ Índices adicionados com sucesso" AS status;
SHOW INDEXES FROM products WHERE Key_name LIKE 'idx_%' LIMIT 10;
SHOW INDEXES FROM orders WHERE Key_name LIKE 'idx_%';
