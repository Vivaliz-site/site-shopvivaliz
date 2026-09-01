<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/melhorenvio-label.php';

function setSenderEnv(string $document, string $cnpj): void
{
    putenv('MELHORENVIO_FROM_DOCUMENT=' . $document);
    putenv('MELHORENVIO_FROM_CNPJ=' . $cnpj);
    $_ENV['MELHORENVIO_FROM_DOCUMENT'] = $document;
    $_ENV['MELHORENVIO_FROM_CNPJ'] = $cnpj;
    $_SERVER['MELHORENVIO_FROM_DOCUMENT'] = $document;
    $_SERVER['MELHORENVIO_FROM_CNPJ'] = $cnpj;
}

setSenderEnv('', '11222333000181');
$companyFrom = svml_from_address();

if (($companyFrom['company_document'] ?? '') !== '11222333000181') {
    fwrite(STDERR, "FAIL: remetente PJ deve enviar CNPJ em company_document\n");
    exit(1);
}

if (array_key_exists('document', $companyFrom) && trim((string)$companyFrom['document']) !== '') {
    fwrite(STDERR, "FAIL: remetente PJ nao deve enviar CNPJ em document\n");
    exit(1);
}

$completeCompany = [
    'name' => 'Empresa Teste',
    'company_document' => '11222333000181',
    'address' => 'Rua Teste',
    'number' => '1',
    'district' => 'Centro',
    'city' => 'Divinopolis',
    'state_abbr' => 'MG',
    'postal_code' => '35501236',
];
if (!svml_from_address_complete($completeCompany)) {
    fwrite(STDERR, "FAIL: endereco PJ com company_document deve ser completo\n");
    exit(1);
}

setSenderEnv('52998224725', '');
$personFrom = svml_from_address();

if (($personFrom['document'] ?? '') !== '52998224725') {
    fwrite(STDERR, "FAIL: remetente PF deve enviar CPF em document\n");
    exit(1);
}
if (array_key_exists('company_document', $personFrom) && trim((string)$personFrom['company_document']) !== '') {
    fwrite(STDERR, "FAIL: remetente PF nao deve enviar CPF em company_document\n");
    exit(1);
}

echo "OK: remetente PJ/PF usa o campo fiscal correto\n";
