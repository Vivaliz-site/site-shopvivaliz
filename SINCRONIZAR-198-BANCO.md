# Sincronizar 198 Produtos ao Banco de Dados

## Status Atual

- ✅ **Catálogo:** 198 produtos exibindo perfeitamente
- ❌ **Banco de Dados:** Apenas 51 produtos

## Solução

Acesse uma destas URLs para sincronizar os 198 produtos ao banco de dados:

### Opção 1: Via Endpoint (recomendado quando deploy completar)
```
https://shopvivaliz.com.br/olist/sync-database-from-catalog.php
```

**Resposta esperada:**
```json
{
  "sucesso": true,
  "mensagem": "Sincronização concluída",
  "sincronizados": 198,
  "erros": 0,
  "total_produtos": 198,
  "timestamp": "2026-06-28T15:15:00+00:00"
}
```

### Opção 2: Via Script Local (imediato)
Se o deploy ainda estiver atrasado, execute localmente:

```bash
cd C:\Users\user\site-shopvivaliz
php -r "
require_once 'config/database.php';
\$db = Database::getInstance();

// Incluir catálogo para pegar os 198 produtos
ob_start();
include 'catalogo/index.php';
ob_end_clean();

\$total_sync = 0;
foreach (\$produtos as \$p) {
    \$sql = 'INSERT INTO products (product_id, name, price, description, category, stock, image_url, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            price = VALUES(price), description = VALUES(description), category = VALUES(category), stock = VALUES(stock), image_url = VALUES(image_url), updated_at = NOW()';
    \$stmt = \$db->prepare(\$sql);
    if (\$stmt->execute([\$p['id'], \$p['nome'], \$p['preco'], \$p['descricao'], \$p['categoria'], \$p['estoque'] ?? 0, \$p['url_imagem'] ?? ''])) {
        \$total_sync++;
    }
    \$stmt->close();
}
echo \"Sincronizados: \$total_sync/\" . count(\$produtos);
"
```

## Arquivos Criados

- `olist/sync-to-database.php` - Sincroniza do cache JSON
- `olist/sync-database-from-catalog.php` - Sincroniza do catálogo (recomendado)

## Próximas Etapas

Após sincronizar:

1. ✅ Abra o Gerenciador Pro e confirme: **198 produtos locais**
2. 📥 Baixar as 198 imagens (em desenvolvimento)
3. 🗄️ Atualizar tabelas de imagens (em desenvolvimento)
4. 📊 Verificar relatório final

---

**Quando executar:** Assim que o deploy FTP completar (verifique acessando a URL do endpoint acima)
