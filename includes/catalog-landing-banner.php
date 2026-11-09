<?php
declare(strict_types=1);

function svci_sales_landing_banner(): string
{
    $queryRaw = $_GET['q'] ?? $_GET['busca'] ?? '';
    $query = is_scalar($queryRaw) ? strtolower(trim((string)$queryRaw)) : '';
    if (in_array($query, ['antique', 'decore'], true)) {
        $line = ucfirst($query);
        return '<section class="container" aria-label="Condições para comprar ' . $line . '" style="margin:18px auto 4px">'
            . '<div style="display:grid;gap:8px;padding:16px 18px;border:1px solid #cfe0ee;border-radius:14px;background:#f5f9fc;color:#173b63">'
            . '<strong style="font-size:18px">Vasos ' . $line . ' Japi: escolha o modelo e compre online</strong>'
            . '<span>10% OFF em compras acima de R$ 100 com o cupom <b>VIVALIZ10</b>.</span>'
            . '<span style="font-size:14px;color:#475569">Frete calculado pelo CEP antes do pagamento • pagamento em ambiente protegido • atendimento humano se precisar de ajuda.</span>'
            . '</div></section>';
    }

    if (in_array($query, ['vedante', 'rodo', 'rodo vedante', 'vedante porta', 'vedante de porta'], true) || str_contains($query, 'vedan') || str_contains($query, 'rodo')) {
        return '<section class="container" aria-label="Rodo Vedante de Alumínio Vivaliz" style="margin:18px auto 8px">'
            . '<div style="display:grid;gap:8px;padding:18px 20px;border:1px solid #bbf7d0;border-radius:14px;background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);color:#14532d;box-shadow:0 2px 6px rgba(0,0,0,0.04)">'
            . '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">'
            . '<strong style="font-size:19px;color:#166534">🚪 Rodo Vedante de Alumínio com Borracha Vivaliz</strong>'
            . '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:700">Original de Fábrica</span>'
            . '</div>'
            . '<span style="font-size:14px;color:#15803d;line-height:1.4">Proteção definitiva contra insetos, poeira, vento e entrada de água • Instalação fácil com parafusos inclusos.</span>'
            . '<span style="font-size:13px;color:#166534;font-weight:600">Disponível nos tamanhos 70 cm, 80 cm, 90 cm e 100 cm (unidades e kits econômicos). Escolha o seu abaixo:</span>'
            . '</div></section>';
    }

    return '';
}
