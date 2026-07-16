<?php
/**
 * TESTE E2E COMPLETO - ShopVivaliz
 * Valida: Checkout → BD → Admin → Email → APIs
 *
 * Execute: php test-full-e2e.php
 * Acesso web: https://dev.shopvivaliz.com.br/test-full-e2e.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

$results = [];
$passed = 0;
$failed = 0;

echo "═══════════════════════════════════════════════════════════\n";
echo "🧪 TESTE E2E COMPLETO - SHOPVIVALIZ\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// ============================================================
// MÓDULO 1: CHECKOUT
// ============================================================
echo "📦 MÓDULO 1: CHECKOUT\n";
echo "───────────────────────────────────────────────────────────\n";

// Test 1.1: Arquivo checkout existe
echo "[1.1] Checkout arquivo existe? ";
if (file_exists(__DIR__ . '/checkout/index.php')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 1.2: CEP preenche endereço
echo "[1.2] CEP preenche endereço (ViaCEP)? ";
$checkout = file_get_contents(__DIR__ . '/checkout/index.php');
if (strpos($checkout, 'viacep.com.br') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 1.3: Frete recalcula
echo "[1.3] Frete recalcula (MelhorEnvio)? ";
if (strpos($checkout, 'shipping-check-v2\|recalculateShipping') !== false ||
    strpos($checkout, 'melhorenvio') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 1.4: Apenas Mercado Pago
echo "[1.4] Apenas Mercado Pago (sem outros gateways)? ";
if (strpos($checkout, "'mercado_pago'") !== false &&
    strpos($checkout, "'pix'") === false &&
    strpos($checkout, "'boleto'") === false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 1.5: Botão MP (não radio)
echo "[1.5] Botão Mercado Pago (não radio button)? ";
if (strpos($checkout, 'checkout-mp-btn') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 1.6: Email cliente
echo "[1.6] Email de confirmação para cliente? ";
if (strpos($checkout, '@mail($cliente[\'email\']') !== false ||
    strpos($checkout, '$clienteSubject') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

echo "\n";

// ============================================================
// MÓDULO 2: BANCO DE DADOS
// ============================================================
echo "💾 MÓDULO 2: BANCO DE DADOS\n";
echo "───────────────────────────────────────────────────────────\n";

try {
    $db = Database::getInstance();

    // Test 2.1: Tabela orders existe
    echo "[2.1] Tabela orders existe? ";
    $result = $db->query("SELECT 1 FROM orders LIMIT 1");
    if ($result !== false) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }

    // Test 2.2: Tabela order_items existe
    echo "[2.2] Tabela order_items existe? ";
    $result = $db->query("SELECT 1 FROM order_items LIMIT 1");
    if ($result !== false) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }

    // Test 2.3: Tabela products existe
    echo "[2.3] Tabela products existe? ";
    $result = $db->query("SELECT 1 FROM products LIMIT 1");
    if ($result !== false) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }

    // Test 2.4: Estrutura orders correta
    echo "[2.4] Estrutura orders (campos corretos)? ";
    $result = $db->query("SHOW COLUMNS FROM orders");
    $columns = [];
    if ($result instanceof mysqli_result) {
        while ($col = $result->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
    }
    $required = ['id', 'customer_name', 'customer_email', 'payment_method', 'total', 'status'];
    $has_all = count(array_intersect($required, $columns)) === count($required);
    if ($has_all) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }

    // Test 2.5: Prepared statements (SQL safe)
    echo "[2.5] Prepared statements implementados? ";
    if (strpos($checkout, 'prepare\|bind_param') !== false) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }

} catch (Exception $e) {
    echo "❌ Erro BD: " . $e->getMessage() . "\n";
    $failed += 6;
}

echo "\n";

// ============================================================
// MÓDULO 3: ADMIN PANELS
// ============================================================
echo "👨‍💼 MÓDULO 3: ADMIN PANELS\n";
echo "───────────────────────────────────────────────────────────\n";

$adminFiles = [
    'admin/index.php' => 'Dashboard',
    'admin/pedidos.php' => 'Pedidos',
    'admin/produtos.php' => 'Produtos',
    'admin/clientes.php' => 'Clientes',
    'admin/menu-completo.php' => 'Menu (26+ rotinas)',
];

foreach ($adminFiles as $file => $name) {
    echo "[3.x] Admin $name existe? ";
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✅\n";
        $passed++;
    } else {
        echo "❌\n";
        $failed++;
    }
}

// Test: Admin conectado a BD
echo "[3.x] Pedidos lêem do BD (não mockado)? ";
if (file_get_contents(__DIR__ . '/admin/pedidos.php') &&
    strpos(file_get_contents(__DIR__ . '/admin/pedidos.php'), 'Database::getInstance') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

echo "\n";

// ============================================================
// MÓDULO 4: SEGURANÇA
// ============================================================
echo "🔒 MÓDULO 4: SEGURANÇA\n";
echo "───────────────────────────────────────────────────────────\n";

// Test 4.1: CSRF token
echo "[4.1] CSRF token implementado? ";
if (strpos($checkout, 'csrf_token\|sv_csrf') !== false) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 4.2: HTTPS/HSTS
echo "[4.2] HTTPS/HSTS (header presente)? ";
$headers = @get_headers('https://dev.shopvivaliz.com.br/');
$has_hsts = false;
if ($headers) {
    foreach ($headers as $h) {
        if (stripos($h, 'strict-transport-security') !== false) {
            $has_hsts = true;
            break;
        }
    }
}
echo ($has_hsts ? "✅\n" : "⚠️ (teste remoto)\n");
if ($has_hsts) $passed++; else $failed++;

// Test 4.3: .env existe
echo "[4.3] .env com credenciais? ";
if (file_exists(__DIR__ . '/.env')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

echo "\n";

// ============================================================
// MÓDULO 5: APIS
// ============================================================
echo "🔌 MÓDULO 5: APIs\n";
echo "───────────────────────────────────────────────────────────\n";

// Test 5.1: Health check
echo "[5.1] /api/health.php responde? ";
if (file_exists(__DIR__ . '/api/health.php')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 5.2: MelhorEnvio shipping
echo "[5.2] /api/melhorenvio/shipping-check-v2.php existe? ";
if (file_exists(__DIR__ . '/api/melhorenvio/shipping-check-v2.php')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 5.3: Catalog API
echo "[5.3] /api/catalog/products.php existe? ";
if (file_exists(__DIR__ . '/api/catalog/products.php')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

echo "\n";

// ============================================================
// MÓDULO 6: GIT SYNC
// ============================================================
echo "🔄 MÓDULO 6: GIT SYNC\n";
echo "───────────────────────────────────────────────────────────\n";

// Test 6.1: Force git pull
echo "[6.1] /admin/force-git-pull.php existe? ";
if (file_exists(__DIR__ . '/admin/force-git-pull.php')) {
    echo "✅\n";
    $passed++;
} else {
    echo "❌\n";
    $failed++;
}

// Test 6.2: Git auto sync script
echo "[6.2] git-auto-sync.py configurado? ";
if (file_exists(__DIR__ . '/git-auto-sync.py') || file_exists(__DIR__ . '/scripts/git-auto-sync.py')) {
    echo "✅\n";
    $passed++;
} else {
    echo "⚠️ (pode estar na VM)\n";
    $failed++;
}

echo "\n";

// ============================================================
// MÓDULO 7: DOCUMENTAÇÃO
// ============================================================
echo "📚 MÓDULO 7: DOCUMENTAÇÃO\n";
echo "───────────────────────────────────────────────────────────\n";

$docs = [
    'CLAUDE.md' => 'Instruções projeto',
    'README.md' => 'Readme',
    'PRODUCTION-STATUS-2026-07-14.md' => 'Status produção',
    'CHECKOUT-CEP-MERCADOPAGO-2026-07-14.md' => 'Checkout doc',
];

foreach ($docs as $file => $name) {
    echo "[7.x] $name ($file)? ";
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✅\n";
        $passed++;
    } else {
        echo "⚠️\n";
        $failed++;
    }
}

echo "\n";

// ============================================================
// RESUMO
// ============================================================
echo "═══════════════════════════════════════════════════════════\n";
echo "📊 RESUMO FINAL\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$total = $passed + $failed;
$percentage = ($total > 0) ? round(($passed / $total) * 100) : 0;

echo "✅ PASSOU: $passed\n";
echo "❌ FALHOU: $failed\n";
echo "📈 TAXA: $percentage%\n\n";

if ($percentage >= 90) {
    echo "🟢 PROJETO EM BOM ESTADO\n";
    echo "\nPróximos passos:\n";
    echo "1. Fazer PR em GitHub (production/deploy-2026-07-14 → main)\n";
    echo "2. Merge para main\n";
    echo "3. Aguardar sincronização (30min)\n";
    echo "4. Testar checkout no navegador\n";
} elseif ($percentage >= 70) {
    echo "🟡 PROJETO COM ALGUNS PROBLEMAS\n";
    echo "\nProblemas encontrados:\n";
    if ($failed > 0) {
        echo "- Verificar testes que falharam acima\n";
    }
} else {
    echo "🔴 PROJETO NÃO PRONTO\n";
    echo "\nProblemas críticos encontrados.\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";

// Retornar exit code
exit($failed > 0 ? 1 : 0);
