<?php
/**
 * Validar configuração de Google Ads/GA4 tracking.
 * Execute: php scripts/validate-tracking-config.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap-env.php';

$colors = [
    'reset' => "\e[0m",
    'green' => "\e[32m",
    'red' => "\e[31m",
    'yellow' => "\e[33m",
    'blue' => "\e[34m",
];

$errors = [];
$warnings = [];
$success = [];

echo "\n{$colors['blue']}╔════════════════════════════════════════════════════════════╗{$colors['reset']}\n";
echo "{$colors['blue']}║  Validação - Configuração Google Ads/GA4 Tracking        ║{$colors['reset']}\n";
echo "{$colors['blue']}╚════════════════════════════════════════════════════════════╝{$colors['reset']}\n\n";

// 1. Verificar GA4_ID
$ga4Id = getenv('GA4_ID') ?: (getenv('GOOGLE_ANALYTICS_ID') ?: (getenv('GOOGLE_ANALYTICS') ?: (getenv('GOOGLE_ANALITYCS') ?: '')));
if ($ga4Id === '' || $ga4Id === 'G-XXXXXXXXXX') {
    $errors[] = 'GA4_ID não configurado ou contém placeholder';
    echo "{$colors['red']}❌ GA4_ID: FALTANDO{$colors['reset']}\n";
} elseif (!preg_match('/^G-[A-Z0-9]{10}$/', $ga4Id)) {
    $warnings[] = 'GA4_ID não segue o padrão esperado (G-XXXXXXXXXX)';
    echo "{$colors['yellow']}⚠️  GA4_ID: Padrão incomum ($ga4Id){$colors['reset']}\n";
} else {
    $success[] = 'GA4_ID configurado corretamente';
    echo "{$colors['green']}✅ GA4_ID: $ga4Id{$colors['reset']}\n";
}

// 2. Verificar GA4_SECRET
$ga4Secret = getenv('GA4_SECRET') ?: '';
if ($ga4Secret === '') {
    $errors[] = 'GA4_SECRET não configurado (necessário para server-side tracking)';
    echo "{$colors['red']}❌ GA4_SECRET: FALTANDO{$colors['reset']}\n";
} elseif (strpos($ga4Secret, 'YOUR_') === 0 || strpos($ga4Secret, 'replace-with') !== false) {
    $errors[] = 'GA4_SECRET ainda contém placeholder';
    echo "{$colors['red']}❌ GA4_SECRET: PLACEHOLDER (não preenchido){$colors['reset']}\n";
} elseif (strlen($ga4Secret) < 30) {
    $warnings[] = 'GA4_SECRET parece muito curto (esperado ~40 caracteres)';
    echo "{$colors['yellow']}⚠️  GA4_SECRET: Comprimento incomum (" . strlen($ga4Secret) . " chars){$colors['reset']}\n";
} else {
    $success[] = 'GA4_SECRET configurado';
    echo "{$colors['green']}✅ GA4_SECRET: Configurado (" . strlen($ga4Secret) . " chars){$colors['reset']}\n";
}

// 3. Verificar Google Ads Conversion ID (opcional)
$adConversionId = getenv('GOOGLE_ADS_CONVERSION_ID') ?: '';
if ($adConversionId === '') {
    $warnings[] = 'GOOGLE_ADS_CONVERSION_ID não configurado (opcional, mas recomendado)';
    echo "{$colors['blue']}ℹ️  GOOGLE_ADS_CONVERSION_ID: Não configurado (opcional){$colors['reset']}\n";
} elseif (strpos($adConversionId, 'YOUR_') === 0 || strpos($adConversionId, 'replace-with') !== false) {
    echo "{$colors['blue']}ℹ️  GOOGLE_ADS_CONVERSION_ID: Placeholder (opcional){$colors['reset']}\n";
} else {
    $success[] = 'Google Ads Conversion ID configurado';
    echo "{$colors['green']}✅ GOOGLE_ADS_CONVERSION_ID: $adConversionId{$colors['reset']}\n";
}

// 4. Verificar Google Ads Label (opcional)
$adLabel = getenv('GOOGLE_ADS_CONVERSION_LABEL') ?: '';
if ($adLabel === '') {
    echo "{$colors['blue']}ℹ️  GOOGLE_ADS_CONVERSION_LABEL: Não configurado (opcional){$colors['reset']}\n";
} elseif (strpos($adLabel, 'YOUR_') === 0 || strpos($adLabel, 'replace-with') !== false) {
    echo "{$colors['blue']}ℹ️  GOOGLE_ADS_CONVERSION_LABEL: Placeholder (opcional){$colors['reset']}\n";
} else {
    $success[] = 'Google Ads Conversion Label configurado';
    echo "{$colors['green']}✅ GOOGLE_ADS_CONVERSION_LABEL: $adLabel{$colors['reset']}\n";
}

// 5. Verificar que analytics-tracking.php existe
if (file_exists(__DIR__ . '/../includes/analytics-tracking.php')) {
    echo "{$colors['green']}✅ analytics-tracking.php encontrado{$colors['reset']}\n";
} else {
    $errors[] = 'analytics-tracking.php não encontrado';
    echo "{$colors['red']}❌ analytics-tracking.php: NÃO ENCONTRADO{$colors['reset']}\n";
}

// 6. Verificar que head-analytics.php existe
if (file_exists(__DIR__ . '/../includes/head-analytics.php')) {
    echo "{$colors['green']}✅ head-analytics.php encontrado{$colors['reset']}\n";
} else {
    $errors[] = 'head-analytics.php não encontrado';
    echo "{$colors['red']}❌ head-analytics.php: NÃO ENCONTRADO{$colors['reset']}\n";
}

// 7. Verificar que página de confirmação existe
if (file_exists(__DIR__ . '/../pedido-confirmado.php')) {
    echo "{$colors['green']}✅ pedido-confirmado.php encontrado{$colors['reset']}\n";
} else {
    $errors[] = 'pedido-confirmado.php não encontrado';
    echo "{$colors['yellow']}⚠️  pedido-confirmado.php: NÃO ENCONTRADO{$colors['reset']}\n";
}

// 8. Tentar disparar um evento GA4 de teste
echo "\n{$colors['blue']}Testando disparo de evento GA4 server-side...{$colors['reset']}\n";
try {
    require_once __DIR__ . '/../includes/analytics-tracking.php';

    $testResult = AnalyticsTracking::sendPurchaseEventGA4(
        'test-client-id-' . uniqid(),
        'TEST-ORDER-' . date('YmdHis'),
        99.90,
        [
            ['sku' => 'TEST-001', 'name' => 'Produto Teste', 'price' => 49.95, 'quantity' => 2],
        ]
    );

    if ($testResult) {
        echo "{$colors['green']}✅ Evento GA4 disparado com sucesso (teste){$colors['reset']}\n";
        $success[] = 'GA4 server-side event fired successfully';
    } else {
        if ($ga4Secret === '' || strpos($ga4Secret, 'YOUR_') === 0) {
            echo "{$colors['blue']}ℹ️  Não foi possível disparar evento (GA4_SECRET não configurado - esperado){$colors['reset']}\n";
        } else {
            $warnings[] = 'Falha ao disparar evento GA4 (verificar GA4_ID e GA4_SECRET)';
            echo "{$colors['yellow']}⚠️  Falha ao disparar evento GA4{$colors['reset']}\n";
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Erro ao testar GA4: ' . $e->getMessage();
    echo "{$colors['red']}❌ Erro ao testar GA4: " . $e->getMessage() . "{$colors['reset']}\n";
}

// Resumo
echo "\n{$colors['blue']}╔════════════════════════════════════════════════════════════╗{$colors['reset']}\n";
echo "{$colors['blue']}║  RESUMO{$colors['reset']}                                                    {$colors['blue']}║{$colors['reset']}\n";
echo "{$colors['blue']}╚════════════════════════════════════════════════════════════╝{$colors['reset']}\n";

echo "\n{$colors['green']}✅ Sucesso: " . count($success) . "{$colors['reset']}\n";
foreach ($success as $msg) {
    echo "   • $msg\n";
}

if ($warnings) {
    echo "\n{$colors['yellow']}⚠️  Avisos: " . count($warnings) . "{$colors['reset']}\n";
    foreach ($warnings as $msg) {
        echo "   • $msg\n";
    }
}

if ($errors) {
    echo "\n{$colors['red']}❌ Erros: " . count($errors) . "{$colors['reset']}\n";
    foreach ($errors as $msg) {
        echo "   • $msg\n";
    }
    echo "\n{$colors['red']}AÇÃO NECESSÁRIA:{$colors['reset']}\n";
    echo "  1. Configure GA4_ID e GA4_SECRET em .env\n";
    echo "  2. Execute novamente: php scripts/validate-tracking-config.php\n";
    echo "  3. Teste checkout em staging: /checkout → PIX → Simular pagamento\n";
    exit(1);
}

echo "\n{$colors['green']}✅ Configuração OK - Pronto para testes em staging!{$colors['reset']}\n";
echo "\n{$colors['blue']}Próximos passos:{$colors['reset']}\n";
echo "  1. Testar checkout: /checkout → adicionar produto → PIX\n";
echo "  2. Validar em GA4: Admin → Realtime → Events\n";
echo "  3. Usar Google Tag Assistant para verificar gtag.js\n";
echo "\n";
