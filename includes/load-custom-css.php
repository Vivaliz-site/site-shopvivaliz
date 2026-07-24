<?php
declare(strict_types=1);

/**
 * Carrega CSS customizado do admin para a página atual
 * Inclua isto no <head> de todas as páginas
 */
function load_custom_css(): void
{
    $root = dirname(__DIR__);
    $cssDir = $root . '/storage/css-custom';

    if (!is_dir($cssDir)) {
        return;
    }

    // Detectar página atual
    $pageName = basename($_SERVER['PHP_SELF'], '.php');
    if ($pageName === 'index') {
        $pageName = 'index';
    }

    // Arquivos CSS a carregar: página específica + global
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
