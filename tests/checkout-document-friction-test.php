<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/checkout-document-policy.php';
$errors = [];
$assert = static function (bool $ok, string $message) use (&$errors): void {
    if (!$ok) $errors[] = $message;
};

$checkout = (string) file_get_contents($root . '/checkout.php');
$validated = (string) file_get_contents($root . '/api/orders/process-validated.php');
$mpEmpty = sv_checkout_document_policy('mercado_pago', '', '');
$ipEmpty = sv_checkout_document_policy('infinitepay', '', '');
$boletoEmpty = sv_checkout_document_policy('boleto', '', '');

$assert(($mpEmpty['valid'] ?? false) && !($mpEmpty['required'] ?? true), 'Mercado Pago must not require document before redirect');
$assert(($ipEmpty['valid'] ?? false) && !($ipEmpty['required'] ?? true), 'InfinitePay must not require document before redirect');
$assert(!($boletoEmpty['valid'] ?? true) && ($boletoEmpty['required'] ?? false), 'direct boleto must require document');
$assert(str_contains($checkout, "selectedPaymentMethod() === 'boleto'"), 'checkout must require document only for direct boleto');
$assert(str_contains($checkout, 'input.required = requiresDocument'), 'checkout CPF required state must follow payment method');
$assert(str_contains($validated, 'sv_checkout_document_policy($paymentMethod, $cpf, $documentType)'), 'validated order must use document policy');

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "checkout-document-friction: ok\n";
