<?php
declare(strict_types=1);

function mehp_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

require_once __DIR__ . '/../includes/integration-health.php';

mehp_assert(function_exists('svih_melhor_envio_probe_request'), 'probe request helper must exist');
$request = svih_melhor_envio_probe_request('35501236');
mehp_assert(($request['method'] ?? '') === 'POST', 'probe must use POST');
mehp_assert(str_ends_with((string)($request['url'] ?? ''), '/api/v2/me/shipment/calculate'), 'probe must use shipment/calculate');
$payload = $request['payload'] ?? null;
mehp_assert(is_array($payload), 'probe payload must be an array');
mehp_assert(($payload['from']['postal_code'] ?? '') === '35501236', 'probe must preserve origin postal code');
mehp_assert(strlen((string)($payload['to']['postal_code'] ?? '')) === 8, 'probe must use a valid destination postal code');
mehp_assert(!empty($payload['products'][0]['weight']), 'probe must include a product weight');
mehp_assert(!empty($payload['products'][0]['quantity']), 'probe must include a product quantity');

echo "OK: Melhor Envio health probe uses shipment calculation\n";