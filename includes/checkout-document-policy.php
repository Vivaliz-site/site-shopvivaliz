<?php
declare(strict_types=1);

require_once __DIR__ . '/mercadopago-gateway.php';

function sv_checkout_document_policy(string $paymentMethod, string $document, string $documentType = ''): array
{
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    $type = strtolower(trim($documentType));
    $required = $paymentMethod === 'boleto';

    if ($digits === '') {
        return ['required' => $required, 'valid' => !$required, 'digits' => '', 'type' => ''];
    }

    if ($type === 'cnpj' || strlen($digits) === 14) {
        return [
            'required' => $required,
            'valid' => svmp_validate_cnpj($digits),
            'digits' => $digits,
            'type' => 'cnpj',
        ];
    }

    return [
        'required' => $required,
        'valid' => svmp_validate_cpf($digits),
        'digits' => $digits,
        'type' => 'cpf',
    ];
}
