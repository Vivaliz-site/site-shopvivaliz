<?php
declare(strict_types=1);

/**
 * Carrega CSS versionado da aplicação e CSS customizado do admin para a página atual.
 * Inclua isto no <head> de todas as páginas.
 */
function load_custom_css(): void
{
    $root = dirname(__DIR__);

    // Detectar página atual antes de acessar storage. O diretório storage é
    // compartilhado em produção e substitui o conteúdo versionado da release.
    $pageName = basename($_SERVER['PHP_SELF'], '.php');

    // CSS funcional da home deve viver em /css, que faz parte da release.
    if ($pageName === 'index') {
        echo "    <link rel=\"stylesheet\" href=\"/css/home-mobile-compact.css?v=2026-07-28-2\">\n";
    }

    // CSS opcional criado pelo admin continua sendo lido do storage compartilhado.
    $cssDir = $root . '/storage/css-custom';
    if (!is_dir($cssDir)) {
        return;
    }

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

load_custom_css();
?>
