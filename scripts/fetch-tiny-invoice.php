<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Use via CLI: php fetch-tiny-invoice.php <idNota>\n");
    exit(1);
}

require_once __DIR__ . '/../includes/tiny-order-push.php';

$idNota = trim((string)($argv[1] ?? ''));
if ($idNota === '') {
    fwrite(STDERR, "Uso: php fetch-tiny-invoice.php <idNota>\n");
    exit(1);
}

$token = svtop_tiny_get_token();
if ($token === '') {
    fwrite(STDERR, "Tiny token indisponivel.\n");
    exit(1);
}

$invoice = svtop_tiny_get_invoice($idNota, $token);
if (($invoice['status'] ?? 0) < 200 || ($invoice['status'] ?? 0) >= 300) {
    fwrite(STDERR, "Falha ao buscar nota {$idNota}: HTTP " . ($invoice['status'] ?? 0) . "\n");
    exit(1);
}

$xml = svtop_tiny_get_invoice_xml($idNota, $token);
$baseDir = dirname(__DIR__) . '/storage/tiny/notas';
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    fwrite(STDERR, "Nao foi possivel criar diretorio de cache: {$baseDir}\n");
    exit(1);
}

$meta = [
    'fetched_at' => date(DATE_ATOM),
    'invoice' => $invoice['json'],
    'xml' => $xml['json'],
];

$jsonPath = $baseDir . DIRECTORY_SEPARATOR . $idNota . '.json';
$tmpJson = tempnam($baseDir, 'nf_');
if ($tmpJson === false) {
    fwrite(STDERR, "Nao foi possivel criar arquivo temporario.\n");
    exit(1);
}
file_put_contents($tmpJson, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
rename($tmpJson, $jsonPath);

$xmlRaw = (string)($xml['json']['xmlNfe'] ?? '');
if ($xmlRaw !== '') {
    file_put_contents($baseDir . DIRECTORY_SEPARATOR . $idNota . '.xml', $xmlRaw, LOCK_EX);
}
$xmlCancelamento = (string)($xml['json']['xmlCancelamento'] ?? '');
if ($xmlCancelamento !== '') {
    file_put_contents($baseDir . DIRECTORY_SEPARATOR . $idNota . '.cancel.xml', $xmlCancelamento, LOCK_EX);
}

echo json_encode([
    'ok' => true,
    'invoice_path' => $jsonPath,
    'xml_path' => $xmlRaw !== '' ? $baseDir . DIRECTORY_SEPARATOR . $idNota . '.xml' : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
