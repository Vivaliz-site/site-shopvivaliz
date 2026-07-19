<?php
/**
 * Script para ativar processamento de webhooks da Olist
 * Modifica api/olist/webhook.php para chamar o processador
 */

$webhookFile = __DIR__ . '/api/olist/webhook.php';

if (!file_exists($webhookFile)) {
    echo "❌ Arquivo não encontrado: $webhookFile\n";
    exit(1);
}

$content = file_get_contents($webhookFile);

// Verificar se webhook-processor já está sendo chamado
if (str_contains($content, 'webhook-processor.php')) {
    echo "✅ Webhook-processor.php já está ativo\n";
    echo "   (arquivo api/olist/webhook.php já inclui o processador)\n";
    exit(0);
}

// Adicionar require do webhook-processor antes de http_response_code
$processorInclude = "require_once __DIR__ . '/webhook-processor.php';\n";
$content = str_replace(
    "http_response_code(200);",
    "$processorInclude\nhttp_response_code(200);",
    $content
);

// Salvar alteração
if (!file_put_contents($webhookFile, $content)) {
    echo "❌ Falha ao salvar webhook.php\n";
    exit(1);
}

echo "✅ Webhook-processor.php ativado!\n";
echo "\n   Próximas ações:\n";
echo "   1. Configure Olist para enviar webhooks para:\n";
echo "      https://shopvivaliz.com.br/api/olist/webhook.php\n";
echo "\n   2. Teste alterando um produto na Olist\n";
echo "\n   3. Verifique logs em:\n";
echo "      /logs/olist-webhook-processor.log\n";
?>
