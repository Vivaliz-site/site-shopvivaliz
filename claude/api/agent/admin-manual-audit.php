<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function svama_root(): string
{
    return dirname(__DIR__, 2);
}

function svama_path_status(string $path): array
{
    $full = svama_root() . '/' . ltrim($path, '/');
    return array(
        'path' => $path,
        'exists' => is_file($full) || is_dir($full),
        'type' => is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'missing'),
        'readable' => is_readable($full),
        'writable' => file_exists($full) ? is_writable($full) : is_writable(dirname($full)),
        'mtime' => file_exists($full) ? date('c', (int)filemtime($full)) : null,
    );
}

function svama_write_manual(array $report): array
{
    $root = svama_root();
    $dir = $root . '/docs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $manual = array();
    $manual[] = '# Manual operacional do Admin ShopVivaliz';
    $manual[] = '';
    $manual[] = 'Atualizado automaticamente em: ' . date('c');
    $manual[] = '';
    $manual[] = '## Objetivo';
    $manual[] = 'Centralizar verificacoes, rotas administrativas, agentes e procedimentos de teste do ambiente dev.';
    $manual[] = '';
    $manual[] = '## Rotas de teste automatico';
    $manual[] = '- /cnpj-rodape-teste.html - confirma deploy FTP GitHub Actions para HostGator dev.';
    $manual[] = '- api/agent/olist-zero-id-repair.php - diagnostica IDs Olist zerados.';
    $manual[] = '- api/agent/olist-zero-id-repair.php?apply=1 - aplica reparo cumulativo em IDs Olist zerados.';
    $manual[] = '- api/melhorenvio/diagnostic.php?cep=35500025 - diagnostica token/payload/cotacao Melhor Envio.';
    $manual[] = '- api/agent/admin-manual-audit.php - revisa o admin e atualiza este manual.';
    $manual[] = '';
    $manual[] = '## Checklist do admin';
    foreach ($report['checks'] as $check) {
        $manual[] = '- ' . ($check['exists'] ? '[OK] ' : '[PENDENTE] ') . $check['path'] . ' (' . $check['type'] . ')';
    }
    $manual[] = '';
    $manual[] = '## Resultado esperado a cada atualizacao';
    $manual[] = '1. GitHub Actions publica no dev.';
    $manual[] = '2. Teste do rodape confirma deploy.';
    $manual[] = '3. Agentes retornam JSON valido.';
    $manual[] = '4. Erros criticos sao convertidos em diagnostico acionavel.';
    $manual[] = '';
    $manual[] = '## Pendencias atuais';
    $manual[] = '- Confirmar JSON do Melhor Envio para liberar cotacao real no checkout.';
    $manual[] = '- Confirmar execucao do reparo Olist e repetir importacao de imagens.';
    $manual[] = '- Receber/publicar favicon definitivo.';
    $manual[] = '';

    $path = $dir . '/ADMIN_MANUAL.md';
    $ok = (bool)file_put_contents($path, implode("\n", $manual));
    return array('path' => 'docs/ADMIN_MANUAL.md', 'written' => $ok, 'bytes' => $ok ? filesize($path) : 0);
}

$apply = isset($_GET['apply']) && (string)$_GET['apply'] === '1';
$checks = array(
    svama_path_status('admin'),
    svama_path_status('admin/index.php'),
    svama_path_status('api'),
    svama_path_status('api/agent'),
    svama_path_status('api/agent/olist-zero-id-repair.php'),
    svama_path_status('api/melhorenvio/diagnostic.php'),
    svama_path_status('cnpj-rodape-teste.html'),
    svama_path_status('docs'),
    svama_path_status('favicon.ico'),
    svama_path_status('assets/favicon.png'),
);

$report = array(
    'ok' => true,
    'agent' => 'admin_manual_audit',
    'apply' => $apply,
    'generated_at' => date('c'),
    'root' => svama_root(),
    'checks' => $checks,
    'manual' => null,
    'summary' => array(
        'deploy_validated_by_footer_test' => is_file(svama_root() . '/cnpj-rodape-teste.html'),
        'olist_repair_agent_present' => is_file(svama_root() . 'api/agent/olist-zero-id-repair.php'),
        'melhorenvio_diagnostic_present' => is_file(svama_root() . 'api/melhorenvio/diagnostic.php'),
        'favicon_present' => is_file(svama_root() . '/favicon.ico') || is_file(svama_root() . '/assets/favicon.png'),
    ),
);

if ($apply) {
    $report['manual'] = svama_write_manual($report);
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
