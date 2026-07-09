<?php
/**
 * Script de Validação: Automação de Produto
 * Verifica se todas as APIs e credenciais estão configuradas
 *
 * Uso: php scripts/validate-automation-setup.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

use Core\Config;

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║         Validação de Setup — Automação de Produto             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$checks = [
    'GEMINI_API_KEY' => 'Google Gemini (Análise de imagem)',
    'ANTHROPIC_API_KEY' => 'Claude (Copywriting)',
    'OPENAI_API_KEY' => 'OpenAI (DALL-E 3)',
    'TINY_ERP_API_KEY' => 'Tiny ERP (Criar SKU)',
    'HUB_OLIST_API_KEY' => 'Hub Olist (Publicar)',
    'GOOGLE_DRIVE_FOLDER_ID' => 'Google Drive (Pasta Novos_Produtos)',
];

$results = [];
$allGood = true;

echo "📋 Verificando credenciais...\n\n";

foreach ($checks as $key => $description) {
    $value = Config::get($key);
    $isValid = !empty($value) && strlen($value) > 5;

    $status = $isValid ? '✅' : '❌';
    $results[$key] = $isValid;
    $allGood = $allGood && $isValid;

    printf("%s %-35s %s\n", $status, $description, $isValid ? 'OK' : 'FALTANDO');

    if (!$isValid && $key === 'GOOGLE_DRIVE_FOLDER_ID') {
        echo "   💡 Dica: Copie o ID da URL: https://drive.google.com/drive/folders/{ID}\n";
    }
}

echo "\n";

// Testes adicionais

echo "🔌 Testando conexão com APIs...\n\n";

// Test 1: Tiny ERP
if ($results['TINY_ERP_API_KEY']) {
    $tinyKey = Config::get('TINY_ERP_API_KEY');
    $ch = curl_init('https://tiny.com.br/api/v3/contatos');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tinyKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "✅ Tiny ERP — Autenticado\n";
    } elseif ($httpCode === 401) {
        echo "❌ Tiny ERP — Chave inválida\n";
        $allGood = false;
    } else {
        echo "⚠️  Tiny ERP — Status $httpCode (possível timeout)\n";
    }
} else {
    echo "⏭️  Tiny ERP — Pulando (chave não configurada)\n";
}

// Test 2: Claude
if ($results['ANTHROPIC_API_KEY']) {
    $claudeKey = Config::get('ANTHROPIC_API_KEY');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $claudeKey,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 100,
            'messages' => [['role' => 'user', 'content' => 'test']]
        ])
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "✅ Claude API — Autenticado\n";
    } elseif ($httpCode === 401) {
        echo "❌ Claude API — Chave inválida\n";
        $allGood = false;
    } else {
        echo "⚠️  Claude API — Status $httpCode\n";
    }
} else {
    echo "⏭️  Claude API — Pulando (chave não configurada)\n";
}

// Test 3: OpenAI
if ($results['OPENAI_API_KEY']) {
    $openaiKey = Config::get('OPENAI_API_KEY');
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $openaiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "✅ OpenAI API — Autenticado\n";
    } elseif ($httpCode === 401) {
        echo "❌ OpenAI API — Chave inválida\n";
        $allGood = false;
    } else {
        echo "⚠️  OpenAI API — Status $httpCode\n";
    }
} else {
    echo "⏭️  OpenAI API — Pulando (chave não configurada)\n";
}

// Test 4: Gemini
if ($results['GEMINI_API_KEY']) {
    $geminiKey = Config::get('GEMINI_API_KEY');
    echo "✅ Gemini API — Chave carregada (teste completo em Make.com)\n";
} else {
    echo "⏭️  Gemini API — Pulando\n";
}

echo "\n";

// Checklist de infraestrutura
echo "🏗️  Checklist de Infraestrutura:\n\n";

$infra = [
    'Pasta Novos_Produtos no Google Drive' => $results['GOOGLE_DRIVE_FOLDER_ID'],
    'Campos customizados criados no Tiny' => 'manual',
    'Hub Olist mapeando campos' => 'manual',
    'Make.com cenário montado' => 'manual',
];

foreach ($infra as $task => $status) {
    if ($status === 'manual') {
        echo "⏳ $task — Verificar manualmente\n";
    } elseif ($status) {
        echo "✅ $task\n";
    } else {
        echo "❌ $task — Faltando\n";
    }
}

echo "\n";

// Resultado final
if ($allGood) {
    echo "════════════════════════════════════════════════════════════════\n";
    echo "✅ TODAS AS CREDENCIAIS CONFIGURADAS!\n";
    echo "════════════════════════════════════════════════════════════════\n";
    echo "\nPróximos passos:\n";
    echo "1. Verificar Tiny ERP: campos customizados criados? ✓\n";
    echo "2. Verificar Hub Olist: mapeamento de campos? ✓\n";
    echo "3. Verificar Make.com: cenário montado? ✓\n";
    echo "4. Testar com 1 foto: salvar em Novos_Produtos\n";
    echo "\nComando para teste: curl http://localhost:8080/api/test-automation\n";
} else {
    echo "════════════════════════════════════════════════════════════════\n";
    echo "❌ FALTAM CREDENCIAIS\n";
    echo "════════════════════════════════════════════════════════════════\n";
    echo "\nAdicionar em .env:\n";
    foreach ($checks as $key => $desc) {
        if (!$results[$key]) {
            echo "  $key=your_value_here\n";
        }
    }
}

echo "\n";
