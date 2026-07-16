<?php
/**
 * ENDPOINT DE SINCRONIZAÇÃO DE ARQUIVOS CRÍTICOS
 * Bypass git when "dubious ownership" error occurs
 * Download direto de GitHub
 *
 * Access: https://dev.shopvivaliz.com.br/admin/sync-critical-files.php
 */

header('Content-Type: application/json');

$repo_url = 'https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/main';

// Arquivos críticos que precisam sincronizar
$files_to_sync = [
    'admin/menu-completo.php',
    'admin/force-git-pull.php',
    'checkout/index.php',
];

$results = [];
$success_count = 0;
$fail_count = 0;

foreach ($files_to_sync as $file) {
    $remote_url = "$repo_url/$file";

    // Baixar conteúdo
    $content = @file_get_contents($remote_url);

    if ($content === false) {
        $results[$file] = ['status' => 'ERROR', 'reason' => 'Download failed'];
        $fail_count++;
        continue;
    }

    // Criar diretório se necessário
    $local_path = __DIR__ . '/../' . $file;
    $dir = dirname($local_path);

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Escrever arquivo
    if (file_put_contents($local_path, $content) === false) {
        $results[$file] = ['status' => 'ERROR', 'reason' => 'Write failed'];
        $fail_count++;
        continue;
    }

    $results[$file] = ['status' => 'SUCCESS', 'bytes' => strlen($content)];
    $success_count++;
}

// Clear OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'files_synced' => $success_count,
    'files_failed' => $fail_count,
    'results' => $results,
    'status' => $fail_count === 0 ? 'SUCCESS' : 'PARTIAL'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
