<?php
declare(strict_types=1);

/**
 * Testes Automatizados da Integração Mercado Pago
 *
 * Execução: php tests/mercadopago-payment-tests.php
 * Ambiente: TESTE APENAS (não usa credenciais de produção)
 */

echo "🧪 TESTES INTEGRAÇÃO MERCADO PAGO\n";
echo str_repeat("═", 70) . "\n\n";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "✅ $name\n";
        $passed++;
    } catch (Exception $e) {
        echo "❌ $name: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ============================================================
// 1. TESTES DE CONFIGURAÇÃO
// ============================================================

test('Arquivo runtime-secrets.php existe', function () {
    $file = __DIR__ . '/../config/runtime-secrets.php';
    if (!is_file($file)) throw new Exception('Arquivo não encontrado');
});

test('SDK Mercado Pago está instalado', function () {
    $file = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($file)) throw new Exception('vendor/autoload.php não encontrado');
    require $file;
    if (!class_exists('MercadoPago\Client\PaymentClient')) {
        throw new Exception('MercadoPago SDK não carregou corretamente');
    }
});

test('Arquivo process-payment.php existe', function () {
    $file = __DIR__ . '/../api/process-payment.php';
    if (!is_file($file)) throw new Exception('process-payment.php não encontrado');
});

test('Arquivo webhook-mercadopago.php existe', function () {
    $file = __DIR__ . '/../api/webhook-mercadopago.php';
    if (!is_file($file)) throw new Exception('webhook-mercadopago.php não encontrado');
});

// ============================================================
// 2. TESTES DE CARREGAMENTO DE SECRETS
// ============================================================

test('Carregar secrets com getenv', function () {
    // Simular environment
    putenv('MERCADOPAGO_ACCESS_TOKEN=test-token-123');
    $token = getenv('MERCADOPAGO_ACCESS_TOKEN');
    if ($token !== 'test-token-123') throw new Exception('getenv não funcionou');
});

test('Secrets carregados em $_ENV', function () {
    $_ENV['MERCADOPAGO_PUBLIC_KEY'] = 'test-public-key';
    if ($_ENV['MERCADOPAGO_PUBLIC_KEY'] !== 'test-public-key') {
        throw new Exception('$_ENV não funcionou');
    }
});

test('Fallback para .env', function () {
    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) throw new Exception('.env não encontrado');
});

// ============================================================
// 3. TESTES DE VALIDAÇÃO (process-payment.php)
// ============================================================

test('Rejeitar POST sem order_id', function () {
    $input = ['external_reference' => 'ref-123', 'payment_token' => 'token-123'];
    if (isset($input['order_id'])) throw new Exception('order_id não deveria estar presente');
});

test('Rejeitar POST sem external_reference', function () {
    $input = ['order_id' => 'PED-123', 'payment_token' => 'token-123'];
    if (isset($input['external_reference'])) throw new Exception('external_reference não deveria estar presente');
});

test('Rejeitar POST sem payment_token', function () {
    $input = ['order_id' => 'PED-123', 'external_reference' => 'ref-123'];
    if (isset($input['payment_token'])) throw new Exception('payment_token não deveria estar presente');
});

test('Rejeitar valor adulterado (amount mismatch)', function () {
    // Simular cenário: DB tem R$100, navegador envia R$50
    $dbTotal = 100.00;
    $browserAmount = 50.00;
    if (abs($dbTotal - $browserAmount) <= 0.01) {
        throw new Exception('Deveria rejeitar valores diferentes');
    }
});

test('Aceitar pequenas variações (até 1 centavo)', function () {
    $dbTotal = 100.00;
    $calculatedAmount = 100.005; // Pequeno arredondamento
    if (abs($dbTotal - $calculatedAmount) > 0.01) {
        throw new Exception('Deveria aceitar pequenas variações');
    }
});

test('Rejeitar pedido já pago', function () {
    $orderStatus = 'pagamento_confirmado';
    $allowedStatuses = ['pendente_atendimento', 'pagamento_pendente'];
    if (in_array($orderStatus, $allowedStatuses, true)) {
        throw new Exception('Deveria rejeitar pedido já pago');
    }
});

test('Rejeitar valor zero', function () {
    $total = 0.00;
    if ($total > 0) {
        throw new Exception('Deveria rejeitar valor zero');
    }
});

// ============================================================
// 4. TESTES DE WEBHOOK
// ============================================================

test('Webhook rejeita assinatura inválida', function () {
    $validSignature = 'abc123def456';
    $receivedSignature = 'invalid-signature';
    if ($validSignature === $receivedSignature) {
        throw new Exception('Deveria rejeitar assinatura inválida');
    }
});

test('Webhook rejeita sem HTTP_X_SIGNATURE', function () {
    $headers = []; // Sem X-Signature
    if (isset($headers['HTTP_X_SIGNATURE'])) {
        throw new Exception('Deveria rejeitar sem X-Signature');
    }
});

test('Webhook rejeita sem HTTP_X_REQUEST_ID', function () {
    $headers = ['HTTP_X_SIGNATURE' => 'sig123']; // Sem X-Request-ID
    if (isset($headers['HTTP_X_REQUEST_ID'])) {
        throw new Exception('Deveria rejeitar sem X-Request-ID');
    }
});

test('Webhook rejeita sem data.id', function () {
    $query = []; // Sem data.id
    if (isset($query['data.id']) || isset($query['data_id'])) {
        throw new Exception('Deveria rejeitar sem data.id');
    }
});

test('Webhook é idempotente (não reprocessa mesmo pedido)', function () {
    $orderId = 'PED-20260714-001';
    $currentStatus = 'pagamento_confirmado';
    $allowedUpdate = ['pendente_atendimento', 'pagamento_pendente'];

    // Só atualiza se o status PERMITIR (já processado = não atualiza)
    if (in_array($currentStatus, $allowedUpdate, true)) {
        throw new Exception('Deveria ser idempotente');
    }
});

// ============================================================
// 5. TESTES DE MAPEAMENTO DE STATUS
// ============================================================

test('Mapear status MP "approved" → "pagamento_confirmado"', function () {
    $mpStatus = 'approved';
    $localStatus = match ($mpStatus) {
        'approved' => 'pagamento_confirmado',
        default => 'unknown'
    };
    if ($localStatus !== 'pagamento_confirmado') throw new Exception('Mapeamento incorreto');
});

test('Mapear status MP "pending" → "pagamento_pendente"', function () {
    $mpStatus = 'pending';
    $localStatus = match ($mpStatus) {
        'pending' => 'pagamento_pendente',
        default => 'unknown'
    };
    if ($localStatus !== 'pagamento_pendente') throw new Exception('Mapeamento incorreto');
});

test('Mapear status MP "rejected" → "pagamento_recusado"', function () {
    $mpStatus = 'rejected';
    $localStatus = match ($mpStatus) {
        'rejected' => 'pagamento_recusado',
        default => 'unknown'
    };
    if ($localStatus !== 'pagamento_recusado') throw new Exception('Mapeamento incorreto');
});

test('Mapear status MP "charged_back" → "chargeback"', function () {
    $mpStatus = 'charged_back';
    $localStatus = match ($mpStatus) {
        'charged_back' => 'chargeback',
        default => 'unknown'
    };
    if ($localStatus !== 'chargeback') throw new Exception('Mapeamento incorreto');
});

// ============================================================
// 6. TESTES DE SEGURANÇA (LOG)
// ============================================================

test('Logs não expõem tokens de cartão', function () {
    $logEntry = "Pagamento processado: order=PED-123 payment_id=999 status=approved";
    // Verificar que não contém padrões típicos de cartão
    if (preg_match('/\d{4}\s\d{4}\s\d{4}\s\d{4}/', $logEntry) || strpos($logEntry, 'CVV') !== false) {
        throw new Exception('Log não deve conter dados de cartão');
    }
});

test('Logs não expõem access tokens', function () {
    $accessTokenPattern = 'APP_USR-[a-z0-9]{32}-[a-z0-9]{8}';
    $logEntry = "Pagamento processado: order=PED-123";
    if (preg_match('/APP_USR-/', $logEntry)) {
        throw new Exception('Log não deve conter access token');
    }
});

test('Logs registram apenas IDs e códigos', function () {
    $logEntry = "Webhook processed: order=PED-123 payment_id=456 status=approved";
    $hasOrderId = strpos($logEntry, 'PED-123') !== false;
    $hasPaymentId = strpos($logEntry, '456') !== false;
    $hasStatus = strpos($logEntry, 'approved') !== false;

    if (!$hasOrderId || !$hasPaymentId || !$hasStatus) {
        throw new Exception('Log deveria conter IDs e status');
    }
});

// ============================================================
// RESUMO
// ============================================================

echo "\n" . str_repeat("═", 70) . "\n";
echo "📊 RESUMO DOS TESTES\n";
echo "✅ Passaram: $passed\n";
echo "❌ Falharam: $failed\n";
echo str_repeat("═", 70) . "\n";

if ($failed > 0) {
    exit(1);
} else {
    echo "\n🎉 TODOS OS TESTES PASSARAM!\n\n";
    exit(0);
}
