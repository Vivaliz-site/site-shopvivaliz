# 🔧 PLANO DE CORREÇÕES - ERROS CRÍTICOS
**Status:** ⚙️ EM EXECUÇÃO

---

## ERRO CRÍTICO #1: Produtos sem Preço

### Ação 1: Investigar produtos sem preço no banco
```bash
SELECT COUNT(*) FROM products WHERE price IS NULL OR price = 0 OR price = '';
```

### Ação 2: Adicionar preços (se faltando do Olist)
- Sincronizar com Olist API
- Importar preços do webhook
- Validar preços > 0

### Ação 3: Validar antes de exibir
- Query deve filtrar price > 0
- API deve rejeitar price <= 0
- Frontend deve avisar

**Responsável:** Banco de dados + Sincronização Olist

---

## ERRO CRÍTICO #2: Busca Retorna 0 Resultados

### Ação 1: Verificar query de busca
Arquivo: `/catalogo.php` ou endpoint de busca

Procurar por:
```php
$busca = $_GET['busca'] ?? '';
SELECT * FROM products WHERE name LIKE '%busca%' OR description LIKE '%busca%'...
```

### Ação 2: Validar campo de busca
- ✅ Tem campo input?
- ✅ POST/GET está funcionando?
- ✅ SQL está correto?
- ✅ Resultados são retornados?

### Ação 3: Implementar busca adequada
- Full-text search (MySQL)
- ou LIKE com wildcard
- ou Elasticsearch (futuro)

**Responsável:** Query SQL + API de busca

---

## ERRO CRÍTICO #3: API /api/cart/add = 404

### Ação 1: Verificar se arquivo existe
```bash
ls -la api/cart/add.php
# Se não existir, criar
```

### Ação 2: Criar endpoint se falta
Arquivo: `api/cart/` 

Endpoints necessários:
- POST `/api/cart/add` - adicionar produto
- GET `/api/cart/get` - obter carrinho
- DELETE `/api/cart/remove` - remover produto
- POST `/api/cart/update` - atualizar quantidade

### Ação 3: Integrar com frontend
- JavaScript deve fazer POST
- Retornar JSON com sucesso
- Validar preço > 0

**Responsável:** API development

---

## ERRO CRÍTICO #4: Página de Produto Genérica

### Ação 1: Encontrar rota de produto
Procurar por:
- `/produto/{id}` ou `/produto/{slug}`
- `product.php`, `product-detail.php`

### Ação 2: Verificar parâmetro
```php
$product_id = $_GET['id'] ?? $_GET['slug'] ?? '';
// Carrega dados do banco
$product = $db->query("SELECT * FROM products WHERE id = ? OR slug = ?");
```

### Ação 3: Carregar dados reais
- Nome do produto (não "Produto Vivaliz")
- Preço (não 0)
- Descrição (não genérica)
- Imagens (não logo)
- SKU (não "sem-sku")

**Responsável:** Database queries + frontend rendering

---

## ERRO CRÍTICO #5: Meus Pedidos Quebrado

### Ação 1: Verificar rota
```bash
grep -r "meus-pedidos" --include="*.php" --include=".htaccess"
```

### Ação 2: Criar página se falta
Arquivo: `meus-pedidos.php` ou equivalente

Deve:
- Verificar autenticação (session)
- Carregar pedidos do usuário
- Mostrar lista de pedidos
- Link para cada pedido
- Rastreamento

### Ação 3: Dados necessários
```sql
SELECT * FROM orders WHERE user_id = $user_id ORDER BY created_at DESC
```

**Responsável:** Frontend + Database queries

---

## ERRO CRÍTICO #6: Liz Inativa (IA Placeholder)

### Ação 1: Verificar se agentes estão ativados
```bash
ls -la agents/v9.2.84/
# Verificar se processos estão rodando
```

### Ação 2: Ativar sistema de IA
- Verificar chaves de API (OpenAI, Claude, Gemini)
- Iniciar serviço de agentes
- Configurar websockets/polling
- Testar resposta

### Ação 3: Remover placeholder
- Não retornar mensagens genéricas
- Processar input em tempo real
- Retornar respostas de IA de verdade

**Responsável:** Backend IA + Agentes

---

## CRONOGRAMA

### AGORA (Próximas 2 horas)
- [ ] Erro #1: Adicionar preços
- [ ] Erro #3: Criar /api/cart/add
- [ ] Erro #4: Carregar produto real

### HOJE (Próximas 4 horas)
- [ ] Erro #2: Consertar busca
- [ ] Erro #5: Restaurar meus pedidos

### HOJE À NOITE
- [ ] Erro #6: Ativar Liz (IA)
- [ ] Testar tudo no navegador

---

## STATUS

🔴 Nenhuma correção iniciada ainda
⏳ Aguardando implementação

---
