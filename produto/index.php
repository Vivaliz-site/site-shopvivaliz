<?php
declare(strict_types=1);

// /produto/{slug} e a rota publica final. Normaliza apenas links internos
// gerados pelo template legado para evitar saltos 301 em catalogo/contato,
// sem tocar em dados comerciais (preco/estoque) nem no roteamento do produto.
ob_start(static function (string $html): string {
    $patterns = [
        '#https://shopvivaliz\.com\.br/catalogo(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/catalogo/',
        '#https://shopvivaliz\.com\.br/contato(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/contato/',
        '#(?<=["\'])/catalogo(?=(?:\?|["\']))#' => '/catalogo/',
        '#(?<=["\'])/contato(?=(?:\?|["\']))#' => '/contato/',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $html = preg_replace($pattern, $replacement, $html) ?? $html;
    }

    return $html;
});

require dirname(__DIR__) . '/produto.php';