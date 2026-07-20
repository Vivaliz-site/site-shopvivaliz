<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

$json = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/report-merchant-excluded-products.php'));
$payload = is_string($json) ? json_decode($json, true) : null;
if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
    fwrite(STDERR, "FALHOU: nao foi possivel gerar relacao de produtos excluidos\n");
    exit(1);
}

$rows = $payload['rows'];
$total = (int)($payload['total_excluidos'] ?? count($rows));

$subject = 'ShopVivaliz - 4 produtos fora do feed Merchant';
$html = '<h2>Produtos fora do feed Google Merchant</h2>';
$html .= '<p>O feed do Merchant esta correto: produtos sem campos obrigatorios nao sao enviados. Abaixo estao os itens bloqueados no cadastro-fonte.</p>';
$html .= '<p><strong>Total:</strong> ' . htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">';
$html .= '<thead><tr><th>SKU</th><th>Nome</th><th>Preco</th><th>Estoque</th><th>Slug</th><th>Motivo</th></tr></thead><tbody>';

$text = "Produtos fora do feed Google Merchant\n\n";
$text .= "Total: {$total}\n\n";

foreach ($rows as $row) {
    $sku = (string)($row['sku'] ?? '');
    $name = (string)($row['nome'] ?? '');
    $price = (string)($row['preco'] ?? '');
    $stock = (string)($row['estoque'] ?? '');
    $slug = (string)($row['slug'] ?? '');
    $reasons = (string)($row['motivos'] ?? '');

    $html .= '<tr>';
    foreach ([$sku, $name, $price, $stock, $slug, $reasons] as $cell) {
        $html .= '<td>' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
    }
    $html .= '</tr>';

    $text .= "- SKU: {$sku}\n";
    $text .= "  Nome: {$name}\n";
    $text .= "  Preco: {$price}\n";
    $text .= "  Estoque: {$stock}\n";
    $text .= "  Slug: {$slug}\n";
    $text .= "  Motivo: {$reasons}\n\n";
}

$html .= '</tbody></table>';
$html .= '<p>Acao necessaria: corrigir imagem e SKU no cadastro-fonte/ERP para que esses produtos entrem automaticamente no proximo processamento do feed.</p>';
$text .= "Acao necessaria: corrigir imagem e SKU no cadastro-fonte/ERP para que esses produtos entrem automaticamente no proximo processamento do feed.\n";

$defaultTo = 'fredmourao@gmail.com,atendimento@shopvivaliz.com.br';
$emailTo = getenv('EMAIL_TO') ?: getenv('NOTIFY_EMAIL_TO') ?: $defaultTo;
$recipients = array_values(array_filter(array_map('trim', explode(',', $emailTo))));
if ($recipients === []) {
    fwrite(STDERR, "FALHOU: nenhum destinatario configurado\n");
    exit(1);
}

$sent = [];
$failed = [];
foreach ($recipients as $recipient) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $failed[] = $recipient . ' (email invalido)';
        continue;
    }
    if (send_email($recipient, $subject, $html, $text)) {
        $sent[] = $recipient;
    } else {
        $failed[] = $recipient;
    }
}

echo json_encode([
    'sent' => $sent,
    'failed' => $failed,
    'total_rows' => $total,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failed === [] ? 0 : 1);
