<?php
/**
 * Configure Gemini API Key - Adiciona GEMINI_API_KEY ao .env da VM
 * Acesso: https://shopvivaliz.com.br/configure-gemini-key.php
 */

header('Content-Type: application/json; charset=utf-8');

$env_file = '/home/ubuntu/site-shopvivaliz/.env';
$gemini_key = '***REMOVED***';
$output = [];
$success = false;

// Verificar se arquivo existe
if (!file_exists($env_file)) {
    $output[] = "Arquivo .env não encontrado em: $env_file";
} else {
    // Ler conteúdo atual
    $current_content = file_get_contents($env_file);

    // Verificar se GEMINI_API_KEY já está lá
    if (stripos($current_content, 'GEMINI_API_KEY') !== false) {
        $output[] = "GEMINI_API_KEY já existe no .env";
        // Atualizar se necessário
        $current_content = preg_replace(
            '/GEMINI_API_KEY=.*/i',
            'GEMINI_API_KEY=' . $gemini_key,
            $current_content
        );
    } else {
        // Adicionar nova linha
        $current_content = trim($current_content) . "\n\n# === CREDENCIAIS IA ===\nGEMINI_API_KEY=$gemini_key\n";
        $output[] = "Adicionando GEMINI_API_KEY ao .env";
    }

    // Escrever de volta
    if (@file_put_contents($env_file, $current_content, LOCK_EX)) {
        $output[] = "✅ Arquivo .env atualizado com sucesso!";
        $success = true;
    } else {
        $output[] = "❌ Erro ao escrever arquivo .env";
    }
}

// Log
$log_dir = __DIR__ . '/logs';
@mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/configure-gemini-' . date('Y-m-d') . '.log';
$log_line = date('Y-m-d H:i:s') . ' | Status: ' . ($success ? 'SUCCESS' : 'FAILED') . ' | ' . implode(' | ', $output) . "\n";
@file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);

http_response_code($success ? 200 : 500);
echo json_encode([
    'ok' => $success,
    'message' => $success ? 'GEMINI_API_KEY configured successfully' : 'Failed to configure GEMINI_API_KEY',
    'output' => $output,
    'timestamp' => date('c'),
    'env_file' => $env_file,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
?>
