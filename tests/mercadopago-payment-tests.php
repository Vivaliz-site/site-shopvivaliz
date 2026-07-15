<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/mercadopago-gateway.php';

$passed = 0;
$failed = 0;

function mp_test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        echo "PASS $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL $name: {$e->getMessage()}\n";
        $failed++;
    }
}

function mp_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mp_fixture_order(string $method = 'boleto'): array
{
    return [
        'order_number' => 'SV20260715123456789',
        'payment_method' => $method,
        'total' => 112.34,
        'shipping_total' => 12.34,
        'shipping_label' => 'Entrega padrão',
        'customer' => [
            'name' => 'Cliente de Teste',
            'email' => 'cliente@example.com',
            'phone' => '11999999999',
            'cpf' => '52998224725',
            'cep' => '01310100',
            'street_name' => 'Avenida de Teste',
            'street_number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ],
        'items' => [[
            'sku' => 'SKU-TESTE',
            'name' => 'Produto de teste',
            'quantity' => 2,
            'price' => 50.00,
        ]],
    ];
}

mp_test('bootstrap permite fallback quando fonte anterior esta vazia', function (): void {
    $key = 'SHOPVIVALIZ_TEST_EMPTY_SECRET';
    putenv($key . '=');
    $_ENV[$key] = '';
    $_SERVER[$key] = '';

    sv_bootstrap_env_assign($key, 'fallback-seguro');
    mp_assert(getenv($key) === 'fallback-seguro', 'valor vazio bloqueou o fallback não vazio');

    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
});

mp_test('valida CPF correto e rejeita repeticoes', function (): void {
    mp_assert(svmp_validate_cpf('529.982.247-25'), 'CPF de teste válido foi rejeitado');
    mp_assert(!svmp_validate_cpf('111.111.111-11'), 'CPF repetido foi aceito');
    mp_assert(!svmp_validate_cpf('52998224724'), 'dígito verificador inválido foi aceito');
});

mp_test('payload do boleto usa total autoritativo e Orders API', function (): void {
    $payload = svmp_boleto_payload(mp_fixture_order());
    mp_assert($payload['total_amount'] === '112.34', 'total incorreto');
    mp_assert($payload['transactions']['payments'][0]['amount'] === '112.34', 'valor da transação incorreto');
    mp_assert($payload['transactions']['payments'][0]['payment_method']['id'] === 'boleto', 'método incorreto');
    mp_assert($payload['transactions']['payments'][0]['payment_method']['type'] === 'ticket', 'tipo incorreto');
    mp_assert($payload['external_reference'] === 'SV20260715123456789', 'referência externa incorreta');
});

mp_test('payload do boleto exige endereco estruturado', function (): void {
    $order = mp_fixture_order();
    unset($order['customer']['street_number']);
    try {
        svmp_boleto_payload($order);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('endereço incompleto foi aceito');
});

mp_test('preferencia soma produtos e frete sem confiar no navegador', function (): void {
    $payload = svmp_preference_payload(mp_fixture_order('mercado_pago'));
    $total = 0.0;
    foreach ($payload['items'] as $item) {
        $total += (float)$item['unit_price'] * (int)$item['quantity'];
    }
    mp_assert(abs($total - 112.34) < 0.001, 'itens da preferência não fecham o total');
    mp_assert($payload['external_reference'] === 'SV20260715123456789', 'pedido não vinculado');
    mp_assert(str_starts_with($payload['notification_url'], 'https://'), 'webhook não usa HTTPS');
});

mp_test('sessao de pagamento aceita somente o token original', function (): void {
    $token = bin2hex(random_bytes(32));
    $order = ['payment_session_hash' => hash('sha256', $token)];
    mp_assert(svmp_session_matches($order, $token), 'token original rejeitado');
    mp_assert(!svmp_session_matches($order, $token . 'x'), 'token adulterado aceito');
});

mp_test('assinatura webhook HMAC e validada em tempo constante', function (): void {
    $secret = implode('-', ['webhook', 'secret', 'for', 'tests']);
    $requestId = 'request-123';
    $dataId = 'ORD01JTESTABC';
    $ts = '1784123456';
    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $ts . ';';
    $signature = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, $secret);
    mp_assert(svmp_validate_webhook_signature($signature, $requestId, $dataId, $secret), 'assinatura correta rejeitada');
    mp_assert(!svmp_validate_webhook_signature($signature, $requestId, $dataId . 'x', $secret), 'assinatura adulterada aceita');
});

mp_test('status do provedor nao aprova boleto pendente', function (): void {
    mp_assert(svmp_local_status('action_required') === 'payment_pending', 'action_required deveria permanecer pendente');
    mp_assert(svmp_local_status('approved') === 'payment_approved', 'approved deveria confirmar');
    mp_assert(svmp_local_status('charged_back') === 'payment_chargeback', 'chargeback deveria ser terminal');
});

mp_test('checkout chama endpoints vinculados ao pedido', function (): void {
    $checkout = (string)file_get_contents(dirname(__DIR__) . '/checkout.php');
    mp_assert(str_contains($checkout, '/api/mercadopago/create-boleto.php'), 'emissão de boleto não ligada ao checkout');
    mp_assert(str_contains($checkout, '/api/mercadopago/create-preference.php'), 'Checkout Pro não ligado ao checkout');
    mp_assert(str_contains($checkout, 'payment_session_token'), 'sessão segura ausente');
});

mp_test('endpoints legados nao criam pagamentos arbitrarios', function (): void {
    foreach (['api/process-payment.php', 'api/mercadopago-orders.php', 'api/mercadopago-orders-sdk.php'] as $file) {
        $source = (string)file_get_contents(dirname(__DIR__) . '/' . $file);
        mp_assert(str_contains($source, 'legacy_endpoint_retired'), "$file ainda está ativo");
        mp_assert(!str_contains($source, 'CURLOPT_SSL_VERIFYPEER'), "$file altera validação TLS");
    }
});

mp_test('webhook consulta recurso antes de atualizar pedido', function (): void {
    $source = (string)file_get_contents(dirname(__DIR__) . '/api/webhook-mercadopago.php');
    mp_assert(str_contains($source, "'/v1/orders/'"), 'consulta de order ausente');
    mp_assert(str_contains($source, "'/v1/payments/'"), 'consulta de payment ausente');
    mp_assert(str_contains($source, 'svmp_validate_webhook_signature'), 'validação de assinatura ausente');
});

echo "RESULT passed=$passed failed=$failed\n";
exit($failed === 0 ? 0 : 1);
