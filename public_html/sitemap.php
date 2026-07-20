<?php
/**
 * Gerador dinâmico de Sitemap XML
 * URL: https://shopvivaliz.com.br/sitemap.xml
 * Este arquivo é rewriteado via .htaccess (sitemap.xml → sitemap.php)
 */

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

// URLs estáticas da loja
$staticUrls = [
    ['url' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
    ['url' => '/sobre', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['url' => '/contato', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['url' => '/politica-privacidade', 'priority' => '0.5', 'changefreq' => 'yearly'],
    ['url' => '/termos-servico', 'priority' => '0.5', 'changefreq' => 'yearly'],
    ['url' => '/catalogo', 'priority' => '0.8', 'changefreq' => 'daily'],
];

$today = date('Y-m-d');

// Adicionar URLs estáticas
foreach ($staticUrls as $item) {
    echo "  <url>\n";
    echo "    <loc>https://shopvivaliz.com.br" . htmlspecialchars($item['url']) . "</loc>\n";
    echo "    <lastmod>$today</lastmod>\n";
    echo "    <changefreq>" . htmlspecialchars($item['changefreq']) . "</changefreq>\n";
    echo "    <priority>" . htmlspecialchars($item['priority']) . "</priority>\n";
    echo "  </url>\n";
}

// Adicionar URLs dinâmicas de produtos
// Detectar se estamos em ambiente com banco de dados ou usando dados do Olist/Tiny

try {
    // Tentar conectar ao banco de dados
    // Variar conforme sua configuração de DB

    // Opção 1: Usar arquivo de configuração existente
    if (file_exists(__DIR__ . '/../includes/config.php')) {
        include_once(__DIR__ . '/../includes/config.php');
    }

    // Opção 2: Tentar usar PDO se disponível
    $dbConnection = null;
    if (class_exists('PDO')) {
        try {
            // Ajustar credenciais conforme seu banco
            $dsn = getenv('DB_DSN') ?: 'mysql:host=localhost;dbname=shopvivaliz;charset=utf8mb4';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';

            $dbConnection = new PDO($dsn, $user, $pass);
            $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        } catch (Exception $e) {
            // Silenciosamente falhar se DB não está disponível
            $dbConnection = null;
        }
    }

    // Se conseguiu conectar, buscar produtos
    if ($dbConnection) {
        // Query para buscar produtos ativos
        $stmt = $dbConnection->query("
            SELECT
                id,
                slug,
                COALESCE(updated_at, created_at, NOW()) as lastmod
            FROM produtos
            WHERE ativo = 1 AND slug IS NOT NULL AND slug != ''
            ORDER BY updated_at DESC
            LIMIT 5000
        ");

        if ($stmt) {
            while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $productUrl = '/produto/' . htmlspecialchars($product['slug']);
                $lastmod = date('Y-m-d', strtotime($product['lastmod']));

                echo "  <url>\n";
                echo "    <loc>https://shopvivaliz.com.br" . $productUrl . "</loc>\n";
                echo "    <lastmod>$lastmod</lastmod>\n";
                echo "    <changefreq>daily</changefreq>\n";
                echo "    <priority>0.8</priority>\n";
                echo "  </url>\n";
            }
        }
    } else {
        // Se não conseguir conectar ao DB, buscar dados do Olist/Tiny
        // Via arquivo cache ou API

        if (file_exists(__DIR__ . '/../cache/produtos_cache.json')) {
            $cache = json_decode(file_get_contents(__DIR__ . '/../cache/produtos_cache.json'), true);
            if (is_array($cache)) {
                foreach (array_slice($cache, 0, 1000) as $product) {
                    if (!empty($product['slug'])) {
                        $lastmod = !empty($product['updated_at'])
                            ? date('Y-m-d', strtotime($product['updated_at']))
                            : $today;

                        echo "  <url>\n";
                        echo "    <loc>https://shopvivaliz.com.br/produto/" . htmlspecialchars($product['slug']) . "</loc>\n";
                        echo "    <lastmod>$lastmod</lastmod>\n";
                        echo "    <changefreq>daily</changefreq>\n";
                        echo "    <priority>0.8</priority>\n";
                        echo "  </url>\n";
                    }
                }
            }
        }
    }

} catch (Exception $e) {
    // Em caso de erro, apenas retornar URLs estáticas (já foram adicionadas acima)
}

echo '</urlset>' . PHP_EOL;
?>
