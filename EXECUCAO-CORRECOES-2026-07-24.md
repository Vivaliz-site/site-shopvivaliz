# 🔧 EXECUÇÃO DE CORREÇÕES CRÍTICAS - 2026-07-24

**Status:** ✅ SCRIPTS CRIADOS E PRONTOS PARA EXECUTAR  
**Prioridade:** 🔴 CRÍTICA  
**Tempo Estimado:** 15-20 minutos

---

## 📋 CHECKLIST DE EXECUÇÃO

### PASSO 1: Adicionar Índices no Banco de Dados
**Objetivo:** Acelerar buscas e queries críticas  
**Tempo:** ~2 minutos

```bash
# Via MySQL Console (recomendado) ou phpMyAdmin:
mysql -u root -p shopvivaliz < scripts/add-database-indexes.sql

# OU via SSH na VM Oracle:
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
mysql -u root -p shopvivaliz < scripts/add-database-indexes.sql
```

**Validação:**
```sql
SHOW INDEXES FROM products;
SHOW INDEXES FROM orders;
```

Deve retornar: 7-8 novos índices incluindo `idx_search_name`, `idx_active_stock`, etc.

---

### PASSO 2: Executar Migração is_admin
**Objetivo:** Criar coluna is_admin na tabela users (necessária para admin panel)  
**Tempo:** ~1 minuto  
**Crítico:** ✅ SIM - sem isso, ninguém consegue acessar /admin

```bash
# Executar localmente:
cd C:\Users\FRED\site-shopvivaliz
php scripts/migrate-is-admin-column.php

# OU na VM Oracle via SSH:
ssh -i "chave" ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
php scripts/migrate-is-admin-column.php
```

**Esperado:**
```
✅ Conectado ao banco: shopvivaliz
ℹ️  Verificando se coluna 'is_admin' já existe...
✅ Coluna 'is_admin' criada com sucesso!
ℹ️  Procurando usuário admin...
✅ Usuário #123 (admin@shopvivaliz.com.br) definido como admin
✅ Migração completada com sucesso!
```

---

### PASSO 3: Listar Produtos com Preço = 0
**Objetivo:** Identificar quais produtos estão com preço zerado  
**Tempo:** ~1 minuto

```bash
php scripts/fix-zero-price-products.php --list
```

**Esperado:**
```
ℹ️  Conectado ao banco de dados
ℹ️  Procurando produtos com price = 0...
ℹ️  Encontrados: 45 produtos com price = 0

📋 PRODUTOS COM PREÇO = 0:
─────────────────────────────────────────────────────────────────
  ID: 123   | SKU: RODIZIO-001         | Nome: Rodízio de Silicone  | Ativo: ✅
  ID: 124   | SKU: CADEADO-001         | Nome: Cadeado de Bicicleta | Ativo: ❌
  ...
```

**Decisão:** Escolher uma ação:
- **--set-min-price:** Deixar visível com preço R$ 0.01 (usuário vê "Consulte o valor")
- **--mark-inactive:** Ocultar da busca (não aparecem no catálogo)

---

### PASSO 4: Corrigir Preços
**Tempo:** ~2 minutos  
**Recomendado:** --set-min-price (deixa visível)

```bash
# OPÇÃO A: Atribuir preço mínimo (R$ 0.01)
php scripts/fix-zero-price-products.php --set-min-price

# OPÇÃO B: Marcar como inativos
php scripts/fix-zero-price-products.php --mark-inactive
```

**Esperado:**
```
ℹ️  Atribuindo preço mínimo (R$ 0.01) a 45 produtos...
✅ 45 produtos atualizados com preço = R$ 0.01
✅ Validação OK: 0 produtos com preço <= 0
```

---

### PASSO 5: Testar Busca
**Objetivo:** Validar que busca funciona  
**Tempo:** ~2 minutos

```bash
# Teste 1: Busca por termo simples
curl "http://shopvivaliz.com.br/catalogo?busca=rodizio"

# Teste 2: Busca em produção (VM Oracle)
curl "http://dev.shopvivaliz.com.br/catalogo?busca=rodizio"

# Teste 3: Via navegador
Abra: https://shopvivaliz.com.br/catalogo?busca=rodizio
Esperado: Ver produtos com "rodizio" no nome (com preço ou "Consulte o valor")
```

---

### PASSO 6: Testar Endpoints de Carrinho
**Objetivo:** Validar que APIs funcionam  
**Tempo:** ~3 minutos

**Teste 1: POST /api/cart/add**
```bash
curl -X POST http://shopvivaliz.com.br/api/cart/add \
  -H "Content-Type: application/json" \
  -d '{"sku":"rodizio-123","quantity":1}'
```

**Esperado:**
```json
{
  "ok": true,
  "message": "Product added to cart",
  "product": {...},
  "cart": {"total_items": 1, "total_price": 199.99}
}
```

**Teste 2: GET /api/cart/get**
```bash
curl http://shopvivaliz.com.br/api/cart/get
```

**Esperado:**
```json
{
  "ok": true,
  "cart": [...],
  "summary": {"total_items": 1, "total_price": 199.99}
}
```

**Teste 3: POST /api/cart/remove**
```bash
curl -X POST http://shopvivaliz.com.br/api/cart/remove \
  -H "Content-Type: application/json" \
  -d '{"sku":"rodizio-123"}'
```

---

### PASSO 7: Testar Admin Panel
**Objetivo:** Validar que admin consegue acessar /admin  
**Tempo:** ~2 minutos

```bash
# 1. Abrir navegador
Abra: https://shopvivaliz.com.br/admin

# 2. Login com credenciais admin
Email: admin@shopvivaliz.com.br
Senha: [sua senha]

# 3. Verificar se consegue entrar
Esperado: Dashboard admin funciona, não retorna 403
```

---

### PASSO 8: Fazer Git Commit
**Objetivo:** Registrar correções no repositório  
**Tempo:** ~2 minutos

```bash
cd C:\Users\FRED\site-shopvivaliz
git add .
git commit -m "fix: adicionar índices, corrigir preços zero, criar API carrinho"
git push origin main
```

**Esperado:** Commit aparece no GitHub

---

## 📊 RESUMO DE ALTERAÇÕES

| Arquivo | Tipo | O Que Mudou |
|---------|------|-----------|
| `scripts/add-database-indexes.sql` | ✨ NOVO | 7 índices para performance |
| `scripts/migrate-is-admin-column.php` | ✨ NOVO | Migração de schema |
| `scripts/fix-zero-price-products.php` | ✨ NOVO | Corrigir preços = 0 |
| `api/cart/clear.php` | ✨ NOVO | Limpar carrinho API |
| `.htaccess` | 📝 ATUALIZADO | Adicionar rotas cart API |

---

## ✅ VALIDAÇÃO FINAL

Depois de executar tudo:

- [ ] Índices criados (SHOW INDEXES retorna 7+)
- [ ] Migração is_admin executada (admin consegue fazer login)
- [ ] Busca funciona (busca por "rodizio" retorna produtos)
- [ ] APIs de carrinho funcionam (POST/GET/DELETE testado)
- [ ] Admin panel acessível (https://shopvivaliz.com.br/admin)
- [ ] Git push completado

---

## 🚀 PRÓXIMAS AÇÕES (DEPOIS)

1. ✅ Remover console.log calls (performance)
2. ✅ Adicionar títulos dinâmicos (SEO)
3. ✅ Implementar rate limiting (segurança)
4. ✅ Ativar Liz AI (automação)

---

**Tempo Total Estimado:** 15-20 minutos  
**Bloqueadores:** Nenhum  
**Risco:** Baixo (scripts são idempotentes)

