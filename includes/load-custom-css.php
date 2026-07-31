<?php
declare(strict_types=1);

/**
 * Resolve a página PHP realmente executada, inclusive quando Apache/Nginx usa
 * uma rota amigável como /produto/<slug>. PHP_SELF pode conter o slug nessas
 * rotas e não deve ser a única fonte para decidir quais assets carregar.
 */
function sv_current_page_name(): string
{
    $knownPages = ['index', 'catalogo', 'produto', 'carrinho', 'checkout'];

    foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $serverKey) {
        $candidate = basename((string)($_SERVER[$serverKey] ?? ''), '.php');
        if (in_array($candidate, $knownPages, true)) {
            return $candidate;
        }
    }

    $path = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    if ($path === '' || $path === 'index.php') {
        return 'index';
    }

    $route = explode('/', $path, 2)[0];
    $routeAliases = [
        'produtos' => 'catalogo',
        'catalogo' => 'catalogo',
        'produto' => 'produto',
        'carrinho' => 'carrinho',
        'checkout' => 'checkout',
    ];

    return $routeAliases[$route] ?? basename($path, '.php');
}

/**
 * Carrega CSS versionado da aplicação e CSS customizado do admin para a página atual.
 * Inclua isto no <head> de todas as páginas.
 */
function load_custom_css(): void
{
    $root = dirname(__DIR__);
    $pageName = sv_current_page_name();

    // CSS e JS funcionais da home devem viver fora de storage, como parte da release.
    if ($pageName === 'index') {
        echo "    <link rel=\"stylesheet\" href=\"/css/home-mobile-compact.css?v=2026-07-28-3\">\n";
        echo "    <link rel=\"stylesheet\" href=\"/css/home-mobile-final.css?v=2026-07-28-1\">\n";
        echo "    <script src=\"/js/home-mobile-layout.js?v=2026-07-28-2\" defer></script>\n";
    }

    // Quarta rodada de polimento visual. O arquivo é restrito às páginas onde
    // foram medidos conflitos na primeira dobra ou estados vazios excessivos.
    if (in_array($pageName, ['index', 'carrinho', 'checkout'], true)) {
        echo "    <link rel=\"stylesheet\" href=\"/css/visual-polish-v4.css?v=2026-07-31-2\">\n";
        echo "    <script src=\"/js/visual-polish-v4.js?v=2026-07-31-1\" defer></script>\n";
    }

    $loadVisualPolish = in_array($pageName, ['index', 'catalogo', 'produto', 'carrinho', 'checkout'], true);

    // CSS opcional criado pelo admin continua sendo lido do storage compartilhado.
    $cssDir = $root . '/storage/css-custom';
    if (is_dir($cssDir)) {
        $cssFiles = [
            $cssDir . '/' . $pageName . '.css',
            $cssDir . '/global.css',
        ];

        foreach ($cssFiles as $cssFile) {
            if (is_file($cssFile) && is_readable($cssFile)) {
                echo "    <style>\n";
                echo "        /* CSS customizado de: " . basename($cssFile) . " */\n";
                echo file_get_contents($cssFile);
                echo "\n    </style>\n";
            }
        }
    }

    // Camadas validadas de acabamento sempre ficam por último na cascata.
    if ($loadVisualPolish) {
        echo "    <link rel=\"stylesheet\" href=\"/css/visual-polish-v5.css?v=2026-07-31-2\">\n";
        echo "    <link rel=\"stylesheet\" href=\"/css/visual-polish-v6.css?v=2026-07-31-1\">\n";
    }
}

load_custom_css();
?>