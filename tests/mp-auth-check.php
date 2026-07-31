<?php
declare(strict_types=1);

/**
 * Verificação de Autenticação Mercado Pago
 *
 * Confirma que as credenciais estão configuradas e funcionando
 */

echo "🔐 VERIFICAÇÃO DE AUTENTICAÇÃO MERCADO PAGO\n";
echo str_repeat("═", 70) . "\n\n";

// 1. Carregar secrets (mesma prioridade que os endpoints)
$runtimeSecretsFile = __DIR__ . '/../config/runtime-secrets.php';
$secrets = (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile))
    ? (array)require $runtimeSecretsFile
    : [];

function mp_get_secret(string $key, array $secrets): string {
    $value = getenv($key);
    if (is_string($value) && $value !== '') return $value;
    if (isset($secrets[$key])) return (string)$secrets[$key];
    if (isset($_ENV[$key])) return (string)$_ENV[$key];
    return '';
}

// 2. Verificar token
$token = mp_get_secret('MERCADOPAGO_ACCESS_TOKEN', $secrets);

if (!$token) {
    echo "❌ ERRO: MERCADOPAGO_ACCESS_TOKEN não configurado\n\n";
    echo "Prioridade de carregamento:\n";
    echo "  1. getenv('MERCADOPAGO_ACCESS_TOKEN')\n";
    echo "  2. config/runtime-secrets.php['MERCADOPAGO_ACCESS_TOKEN']\n";
    echo "  3. \$_ENV['MERCADOPAGO_ACCESS_TOKEN']\n";
    echo "  4. .env MERCADOPAGO_ACCESS_TOKEN=...\n";
    exit(1);
}

echo "✅ Token carregado (primeiros 20 chars): " . substr($token, 0, 20) . "...\n\n";

// 3. Carregar SDK
$autoloadFile = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadFile)) {
    echo "❌ ERRO: vendor/autoload.php não encontrado\n";
    echo "Execute: composer install\n";
    exit(1);
}

require_once $autoloadFile;

// 4. Testar autenticação
try {
    $client = new MercadoPago\Client\UserClient();
    $client->setAccessToken($token);

    $user = $client->getUserInfo();

    if (!isset($user['id'])) {
        echo "❌ AUTENTICAÇÃO FALHOU\n";
        echo "Resposta: " . json_encode($user) . "\n";
        exit(1);
    }

    $userId = $user['id'];
    $email = $user['email'] ?? 'N/A';
    $status = $user['status'] ?? 'N/A';

    echo "✅ AUTENTICAÇÃO OK\n\n";
    echo "Informações da Conta:\n";
    echo "  User ID: $userId\n";
    echo "  Email: $email\n";
    echo "  Status: $status\n";
    echo "\n" . str_repeat("═", 70) . "\n";
    echo "✅ Mercado Pago está configurado e funcionando!\n";
    echo str_repeat("═", 70) . "\n";
    exit(0);
} catch (Exception $e) {
    echo "❌ ERRO NA AUTENTICAÇÃO\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    exit(1);
}
