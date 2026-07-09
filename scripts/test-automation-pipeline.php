<?php
/**
 * Teste Completo: Pipeline de Automação
 * Simula fluxo: Imagem → Gemini → Claude → ChatGPT → Tiny
 *
 * Uso: php scripts/test-automation-pipeline.php /caminho/para/imagem.jpg
 *
 * Exemplo:
 *   php scripts/test-automation-pipeline.php ./test-image.jpg
 *
 * Retorna: JSON com resultado de cada etapa
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

use Core\Config;

$imagePath = $argv[1] ?? null;

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║     Teste Completo — Pipeline de Automação de Produto         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

if (!$imagePath) {
    echo "❌ Uso: php scripts/test-automation-pipeline.php <caminho/imagem>\n\n";
    echo "Exemplo:\n";
    echo "  php scripts/test-automation-pipeline.php ./test-image.jpg\n";
    echo "  php scripts/test-automation-pipeline.php C:\\Users\\FRED\\Downloads\\produto.jpg\n";
    exit(1);
}

if (!file_exists($imagePath)) {
    echo "❌ Arquivo não encontrado: $imagePath\n";
    exit(1);
}

// Iniciar pipeline
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'image' => basename($imagePath),
    'image_size' => filesize($imagePath),
    'stages' => []
];

echo "📤 Lendo imagem: " . basename($imagePath) . "\n";
$imageData = file_get_contents($imagePath);
$imageBase64 = base64_encode($imageData);

echo "✅ Imagem carregada (" . formatBytes(strlen($imageData)) . ")\n\n";

// ────────────────────────────────────────────────────────────────────────────
// ETAPA 1: GEMINI — Análise de Imagem
// ────────────────────────────────────────────────────────────────────────────

echo "🔄 ETAPA 1: Gemini (Análise de Imagem)\n";
echo "   Enviando para análise...\n";

$geminiKey = Config::get('GEMINI_API_KEY');

if (!$geminiKey) {
    echo "   ❌ GEMINI_API_KEY não configurada\n";
    $results['stages']['gemini'] = ['status' => 'skip', 'reason' => 'API key missing'];
    $geminiData = null;
} else {
    try {
        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=$geminiKey";

        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Analise esta imagem de produto e extraia: marca, modelo, EAN (se visível), categoria, características, cor. Retorne um JSON válido com os campos: marca, modelo, ean (null se não visível), categoria, caracteristicas (array), cor, observacoes.'
                        ],
                        [
                            'inlineData' => [
                                'mimeType' => 'image/jpeg',
                                'data' => $imageBase64
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $ch = curl_init($geminiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            $geminiText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Extrair JSON da resposta
            if (preg_match('/\{.*\}/s', $geminiText, $matches)) {
                $geminiData = json_decode($matches[0], true);
                echo "   ✅ Análise concluída\n";
                echo "      Marca: " . ($geminiData['marca'] ?? 'N/A') . "\n";
                echo "      Modelo: " . ($geminiData['modelo'] ?? 'N/A') . "\n";
                echo "      EAN: " . ($geminiData['ean'] ?? 'Não encontrado') . "\n";
                $results['stages']['gemini'] = [
                    'status' => 'success',
                    'data' => $geminiData
                ];
            } else {
                echo "   ⚠️  Resposta sem JSON válido\n";
                $results['stages']['gemini'] = [
                    'status' => 'error',
                    'reason' => 'Invalid JSON in response',
                    'raw' => $geminiText
                ];
                $geminiData = null;
            }
        } else {
            echo "   ❌ HTTP $httpCode: " . substr($response, 0, 100) . "\n";
            $results['stages']['gemini'] = [
                'status' => 'error',
                'http_code' => $httpCode
            ];
            $geminiData = null;
        }
    } catch (\Throwable $e) {
        echo "   ❌ Exception: " . $e->getMessage() . "\n";
        $results['stages']['gemini'] = ['status' => 'error', 'exception' => $e->getMessage()];
        $geminiData = null;
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// ETAPA 2: CLAUDE — Copywriting
// ────────────────────────────────────────────────────────────────────────────

if (!$geminiData) {
    echo "⏭️  ETAPA 2: Claude (Copywriting) — Pulando (Gemini falhou)\n";
    $results['stages']['claude'] = ['status' => 'skipped', 'reason' => 'Gemini failed'];
    $claudeData = null;
} else {
    echo "🔄 ETAPA 2: Claude (Copywriting)\n";
    echo "   Gerando conteúdo para marketplaces...\n";

    $claudeKey = Config::get('ANTHROPIC_API_KEY');

    if (!$claudeKey) {
        echo "   ❌ ANTHROPIC_API_KEY não configurada\n";
        $results['stages']['claude'] = ['status' => 'skip', 'reason' => 'API key missing'];
        $claudeData = null;
    } else {
        try {
            $prompt = "Você é copywriter chefe de e-commerce. Com base nestes dados:
- Marca: {$geminiData['marca']}
- Modelo: {$geminiData['modelo']}
- Categoria: {$geminiData['categoria']}
- Características: " . implode(', ', $geminiData['caracteristicas'] ?? []) . "

Gere títulos e descrições para 4 marketplaces (ML, Shopee, Amazon, TikTok).
RETORNE APENAS JSON (válido, sem markdown).";

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $claudeKey,
                    'Content-Type: application/json',
                    'anthropic-version: 2023-06-01'
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'claude-3-5-sonnet-20241022',
                    'max_tokens' => 1500,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ]
                ]),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_CUSTOMREQUEST => 'POST'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                $claudeText = $responseData['content'][0]['text'] ?? '';

                if (preg_match('/\{.*\}/s', $claudeText, $matches)) {
                    $claudeData = json_decode($matches[0], true);
                    echo "   ✅ Copywriting concluído\n";
                    echo "      Mercado Livre: " . mb_substr($claudeData['mercado_livre']['titulo'] ?? '', 0, 40) . "...\n";
                    echo "      Shopee: " . mb_substr($claudeData['shopee']['titulo'] ?? '', 0, 40) . "...\n";
                    $results['stages']['claude'] = [
                        'status' => 'success',
                        'data' => $claudeData
                    ];
                } else {
                    echo "   ⚠️  JSON inválido na resposta\n";
                    $results['stages']['claude'] = [
                        'status' => 'error',
                        'reason' => 'Invalid JSON'
                    ];
                    $claudeData = null;
                }
            } else {
                echo "   ❌ HTTP $httpCode\n";
                $results['stages']['claude'] = ['status' => 'error', 'http_code' => $httpCode];
                $claudeData = null;
            }
        } catch (\Throwable $e) {
            echo "   ❌ Exception: " . $e->getMessage() . "\n";
            $results['stages']['claude'] = ['status' => 'error', 'exception' => $e->getMessage()];
            $claudeData = null;
        }
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────────────────
// ETAPA 3: OPENAI — Gerar Imagem
// ────────────────────────────────────────────────────────────────────────────

echo "🔄 ETAPA 3: OpenAI/DALL-E (Gerar Fundo Studio)\n";
echo "   ⏳ Nota: DALL-E pode levar 30-60 segundos...\n";

$openaiKey = Config::get('OPENAI_API_KEY');

if (!$openaiKey) {
    echo "   ❌ OPENAI_API_KEY não configurada\n";
    $results['stages']['dalle'] = ['status' => 'skip', 'reason' => 'API key missing'];
} else {
    try {
        $prompt = "Create a PHOTOREALISTIC, 8K quality studio product photography of a {$geminiData['categoria']} in {$geminiData['cor']}. Professional studio lighting, white background, sharp focus. Product CENTERED. NO text or logos.";

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openaiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'dall-e-3',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
                'quality' => 'hd'
            ]),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            $imageUrl = $responseData['data'][0]['url'] ?? null;

            if ($imageUrl) {
                echo "   ✅ Imagem gerada\n";
                echo "      URL: " . substr($imageUrl, 0, 50) . "...\n";
                $results['stages']['dalle'] = [
                    'status' => 'success',
                    'image_url' => $imageUrl
                ];
            } else {
                echo "   ⚠️  Resposta sem URL de imagem\n";
                $results['stages']['dalle'] = ['status' => 'error', 'reason' => 'No image URL'];
            }
        } else {
            echo "   ❌ HTTP $httpCode\n";
            $results['stages']['dalle'] = ['status' => 'error', 'http_code' => $httpCode];
        }
    } catch (\Throwable $e) {
        echo "   ❌ Exception: " . $e->getMessage() . "\n";
        $results['stages']['dalle'] = ['status' => 'error', 'exception' => $e->getMessage()];
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// ETAPA 4: TINY ERP — Criar SKU
// ────────────────────────────────────────────────────────────────────────────

echo "🔄 ETAPA 4: Tiny ERP (Criar SKU)\n";

if (!$geminiData || !$claudeData) {
    echo "   ⏭️  Pulando (Gemini ou Claude falhou)\n";
    $results['stages']['tiny'] = ['status' => 'skipped', 'reason' => 'Previous stages failed'];
} else {
    echo "   📝 Simulando criação do SKU...\n";

    $tinyKey = Config::get('TINY_ERP_API_KEY');

    if (!$tinyKey) {
        echo "   ⚠️  TINY_ERP_API_KEY não configurada (teste skipped)\n";
        $results['stages']['tiny'] = ['status' => 'skip', 'reason' => 'API key missing'];
    } else {
        echo "   (Teste real requer autenticação Tiny)\n";
        $results['stages']['tiny'] = [
            'status' => 'mock',
            'sku' => 'AUTO-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'preco' => 100.00,
            'message' => 'Endpoint funcionará quando Make.com for configurado'
        ];
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// RESULTADO FINAL
// ────────────────────────────────────────────────────────────────────────────

echo "════════════════════════════════════════════════════════════════\n";
echo "📊 RESULTADO FINAL\n";
echo "════════════════════════════════════════════════════════════════\n\n";

$successCount = 0;
foreach ($results['stages'] as $stage => $data) {
    if ($data['status'] === 'success') {
        $successCount++;
        echo "✅ $stage";
    } elseif ($data['status'] === 'skip' || $data['status'] === 'skipped') {
        echo "⏭️  $stage";
    } else {
        echo "❌ $stage";
    }
    echo "\n";
}

echo "\n";
$total = count($results['stages']);
echo "Status: $successCount/$total etapas completadas\n";

if ($successCount === $total) {
    echo "\n✅ PIPELINE COMPLETO — Pronto para Make.com!\n";
} elseif ($successCount >= $total - 1) {
    echo "\n⚠️  PIPELINE PARCIAL — Faltam credenciais ou testes\n";
} else {
    echo "\n❌ PIPELINE INCOMPLETO — Verificar erros acima\n";
}

echo "\n";
echo "📄 Resultado JSON salvo em:\n";
$outputFile = '/tmp/automation-test-' . date('YmdHis') . '.json';
file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "   $outputFile\n";

echo "\n";

// Helper function
function formatBytes($bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}
