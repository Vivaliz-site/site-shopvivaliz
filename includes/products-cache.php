<?php
/**
 * Acesso a produtos reais (tabela `products`). Antes havia um fallback que
 * fabricava 188 produtos ficticios (nomes/precos/estoque aleatorios, imagem
 * via placeholder.com) sempre que o BD "nao estava disponivel" -- e por um
 * bug (`function_exists('Database')` numa classe, sempre false) esse
 * fallback fake rodava SEMPRE, nunca tentava o banco real de fato. Removido:
 * sem produtos ficticios, nem como fallback. Se o BD falhar, retorna vazio
 * e quem chama deve tratar como catalogo indisponivel, nao inventar dados.
 */
function obter_produtos(int $limit = null, int $offset = 0): array
{
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
            $query = 'SELECT * FROM products ORDER BY id DESC';
            if ($limit) {
                $query .= " LIMIT $offset, $limit";
            }
            $result = $db->query($query);
            $produtos = [];
            while ($p = $result->fetch_assoc()) {
                $produtos[] = $p;
            }
            return $produtos;
        }
    } catch (Exception $e) {
        error_log('[products-cache] obter_produtos falhou: ' . $e->getMessage());
    }

    return [];
}

/**
 * Contar produtos
 */
function contar_produtos(): int
{
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
            $result = $db->query('SELECT COUNT(*) as total FROM products');
            $row = $result->fetch_assoc();
            return (int)$row['total'];
        }
    } catch (Exception $e) {
        error_log('[products-cache] contar_produtos falhou: ' . $e->getMessage());
    }

    return 0;
}

/**
 * Obter categorias únicas
 */
function obter_categorias(): array
{
    $cachedPath = dirname(__DIR__) . '/storage/tiny/categories-flat.json';
    if (is_file($cachedPath)) {
        $decoded = json_decode((string)file_get_contents($cachedPath), true);
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        $categorias = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string)($item['caminho'] ?? $item['descricao'] ?? ''));
            if ($label !== '') {
                $categorias[] = $label;
            }
        }
        $categorias = array_values(array_unique($categorias));
        sort($categorias);
        if ($categorias !== []) {
            return $categorias;
        }
    }

    $produtos = obter_produtos(999);
    $categorias = array_unique(array_column($produtos, 'category'));
    sort($categorias);
    return $categorias;
}

/**
 * Filtrar produtos
 */
function filtrar_produtos(array $produtos, array $filtros = []): array
{
    if (isset($filtros['categoria']) && $filtros['categoria']) {
        $produtos = array_filter($produtos, function($p) use ($filtros) {
            return strcasecmp($p['category'], $filtros['categoria']) === 0;
        });
    }

    if (isset($filtros['priceMin']) && $filtros['priceMin']) {
        $produtos = array_filter($produtos, function($p) use ($filtros) {
            return $p['price'] >= (float)$filtros['priceMin'];
        });
    }

    if (isset($filtros['priceMax']) && $filtros['priceMax']) {
        $produtos = array_filter($produtos, function($p) use ($filtros) {
            return $p['price'] <= (float)$filtros['priceMax'];
        });
    }

    if (isset($filtros['search']) && $filtros['search']) {
        $search = strtolower($filtros['search']);
        $produtos = array_filter($produtos, function($p) use ($search) {
            return stripos($p['name'], $search) !== false || stripos($p['description'], $search) !== false;
        });
    }

    return $produtos;
}
?>
