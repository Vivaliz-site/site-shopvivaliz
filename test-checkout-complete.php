<?php
/**
 * TESTE COMPLETO DE CHECKOUT
 * Valida: CEP, Frete, Mercado Pago, BD, Email
 */

declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

echo "🧪 TESTE COMPLETO DE CHECKOUT\n";
echo "==============================\n\n";

$passed = 0;
$failed = 0;

// TEST 1: Arquivo checkout existe?
echo "[TEST 1] Arquivo checkout existe?\n";
if (file_exists(__DIR__ . '/checkout/index.php')) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU\n";
    $failed++;
}
echo "\n";

// TEST 2: Apenas Mercado Pago?
echo "[TEST 2] Apenas Mercado Pago como payment option?\n";
$checkoutContent = file_get_contents(__DIR__ . '/checkout/index.php');
if (strpos($checkoutContent, "'mercado_pago'") !== false &&
    strpos($checkoutContent, "'pix'") === false &&
    strpos($checkoutContent, "'boleto'") === false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - Encontradas outras opções de pagamento\n";
    $failed++;
}
echo "\n";

// TEST 3: Botão Mercado Pago presente?
echo "[TEST 3] Botão Mercado Pago (não radio button) presente?\n";
if (strpos($checkoutContent, 'checkout-mp-btn') !== false &&
    strpos($checkoutContent, 'Continuar com Mercado Pago') !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - Botão MP não encontrado\n";
    $failed++;
}
echo "\n";

// TEST 4: ViaCEP integrado?
echo "[TEST 4] ViaCEP integrado para preenchimento de endereço?\n";
if (strpos($checkoutContent, 'viacep.com.br') !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - ViaCEP não encontrado\n";
    $failed++;
}
echo "\n";

// TEST 5: MelhorEnvio para frete?
echo "[TEST 5] MelhorEnvio integrado para recalcular frete?\n";
if (strpos($checkoutContent, 'shipping-check-v2') !== false ||
    strpos($checkoutContent, 'recalculateShipping') !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - MelhorEnvio não encontrado\n";
    $failed++;
}
echo "\n";

// TEST 6: API MelhorEnvio existe?
echo "[TEST 6] API MelhorEnvio shipping-check-v2.php existe?\n";
if (file_exists(__DIR__ . '/api/melhorenvio/shipping-check-v2.php')) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU\n";
    $failed++;
}
echo "\n";

// TEST 7: Email para cliente?
echo "[TEST 7] Email de confirmação para cliente implementado?\n";
if (strpos($checkoutContent, '@mail($cliente[\'email\']') !== false ||
    strpos($checkoutContent, '$clienteSubject') !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - Email cliente não configurado\n";
    $failed++;
}
echo "\n";

// TEST 8: BD integrado?
echo "[TEST 8] Checkout salva no banco de dados?\n";
if (strpos($checkoutContent, 'Database::getInstance') !== false &&
    strpos($checkoutContent, 'INSERT INTO orders') !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - BD não integrado\n";
    $failed++;
}
echo "\n";

// TEST 9: Tabelas BD existem?
echo "[TEST 9] Tabelas orders e order_items existem?\n";
try {
    $db = Database::getInstance();
    $ordersCheck = $db->query("SELECT 1 FROM orders LIMIT 1");
    $itemsCheck = $db->query("SELECT 1 FROM order_items LIMIT 1");

    if ($ordersCheck !== false && $itemsCheck !== false) {
        echo "✅ PASSOU\n";
        $passed++;
    } else {
        echo "❌ FALHOU - Tabelas não existem\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "❌ FALHOU - Erro ao verificar: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// TEST 10: Validação de dados?
echo "[TEST 10] Validação de dados (nome, email, CEP, endereço)?\n";
if (strpos($checkoutContent, "nome || !email || !cep || !endereco") !== false ||
    strpos($checkoutContent, "Preencha todos os dados") !== false) {
    echo "✅ PASSOU\n";
    $passed++;
} else {
    echo "❌ FALHOU - Validação não encontrada\n";
    $failed++;
}
echo "\n";

// RESUMO
echo "==============================\n";
echo "📊 RESULTADO FINAL\n";
echo "==============================\n\n";

echo "✅ PASSOU: $passed/10\n";
echo "❌ FALHOU: $failed/10\n";
echo "📈 Taxa de sucesso: " . round($passed / 10 * 100) . "%\n";

echo "\n";

if ($failed === 0) {
    echo "🟢 TUDO OK! CHECKOUT ESTÁ PRONTO!\n";
    echo "\n";
    echo "Flow completo:\n";
    echo "1. User preenche CEP\n";
    echo "2. ViaCEP preenche Rua/Cidade\n";
    echo "3. MelhorEnvio calcula frete\n";
    echo "4. User clica botão Mercado Pago\n";
    echo "5. Dados salvam no BD\n";
    echo "6. Email enviado\n";
    exit(0);
} else {
    echo "🔴 HÁ PROBLEMAS A CORRIGIR\n";
    exit(1);
}
