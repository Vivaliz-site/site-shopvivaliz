<?php
/**
 * Setup Tiny ERP: Criar Campos Customizados Automaticamente
 *
 * Uso: php scripts/setup-tiny-fields.php
 *
 * Requer: TINY_ERP_API_KEY configurado em .env
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

use Core\Config;

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║        Setup Tiny ERP — Criar Campos Customizados            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$apiKey = Config::get('TINY_ERP_API_KEY');

if (!$apiKey) {
    echo "❌ TINY_ERP_API_KEY não configurada em .env\n";
    echo "\nAdicione em .env:\n";
    echo "  TINY_ERP_API_KEY=seu_token_aqui\n";
    echo "\nPara obter o token:\n";
    echo "  1. Acesse: https://app.tiny.com.br/\n";
    echo "  2. Vá em: ⚙️ Configurações > E-commerce > Integrações\n";
    echo "  3. Encontre 'Hub Olist'\n";
    echo "  4. Copie a 'Chave de API'\n";
    exit(1);
}

// Campos a criar
$fields = [
    ['name' => 'titulo_meli', 'label' => 'Título Mercado Livre', 'type' => 'texto'],
    ['name' => 'desc_meli', 'label' => 'Descrição Mercado Livre', 'type' => 'texto'],
    ['name' => 'titulo_shopee', 'label' => 'Título Shopee', 'type' => 'texto'],
    ['name' => 'desc_shopee', 'label' => 'Descrição Shopee', 'type' => 'texto'],
    ['name' => 'titulo_amazon', 'label' => 'Título Amazon', 'type' => 'texto'],
    ['name' => 'bullet_1', 'label' => 'Bullet Point 1', 'type' => 'texto'],
    ['name' => 'bullet_2', 'label' => 'Bullet Point 2', 'type' => 'texto'],
    ['name' => 'bullet_3', 'label' => 'Bullet Point 3', 'type' => 'texto'],
    ['name' => 'titulo_tiktok', 'label' => 'Título TikTok', 'type' => 'texto'],
    ['name' => 'desc_tiktok', 'label' => 'Descrição TikTok', 'type' => 'texto'],
    ['name' => 'ean_gemini', 'label' => 'EAN Gemini', 'type' => 'texto'],
    ['name' => 'peso_g', 'label' => 'Peso (g)', 'type' => 'numero'],
    ['name' => 'altura_cm', 'label' => 'Altura (cm)', 'type' => 'numero'],
    ['name' => 'largura_cm', 'label' => 'Largura (cm)', 'type' => 'numero'],
    ['name' => 'comprimento_cm', 'label' => 'Comprimento (cm)', 'type' => 'numero'],
    ['name' => 'url_bg_chat', 'label' => 'URL Fundo Studio (ChatGPT)', 'type' => 'texto'],
    ['name' => 'status_automacao', 'label' => 'Status Automação', 'type' => 'texto'],
];

echo "📋 Criando " . count($fields) . " campos customizados no Tiny ERP...\n\n";

$created = 0;
$failed = 0;
$skipped = 0;

foreach ($fields as $field) {
    echo "Criando: {$field['label']}... ";

    try {
        $ch = curl_init('https://tiny.com.br/api/v3/produtos/campos-customizados');

        $payload = json_encode([
            'nome' => $field['name'],
            'label' => $field['label'],
            'tipo' => $field['type'],
            'obrigatorio' => false,
            'ativo' => true
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201 || $httpCode === 200) {
            echo "✅\n";
            $created++;
        } elseif ($httpCode === 409) {
            echo "⏭️  (já existe)\n";
            $skipped++;
        } elseif ($httpCode === 401) {
            echo "❌ (token inválido)\n";
            $failed++;
            break;
        } else {
            echo "❌ (HTTP $httpCode)\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "❌ (erro: " . $e->getMessage() . ")\n";
        $failed++;
    }
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "Resultado:\n";
echo "  ✅ Criados: $created\n";
echo "  ⏭️  Já existentes: $skipped\n";
echo "  ❌ Erros: $failed\n";
echo "════════════════════════════════════════════════════════════════\n";

if ($failed === 0) {
    echo "\n✅ SETUP COMPLETO!\n";
    echo "\nPróximos passos:\n";
    echo "  1. Verificar campos em: ⚙️ > Suprimentos > Campos Customizados\n";
    echo "  2. Configurar Hub Olist: mapear cada marketplace\n";
    echo "  3. Criar pasta 'Novos_Produtos' no Google Drive\n";
    echo "  4. Montar cenário no Make.com\n";
} else {
    echo "\n❌ Alguns campos falharam. Verifique:\n";
    echo "  - Token TINY_ERP_API_KEY está correto?\n";
    echo "  - Você tem permissão de admin no Tiny?\n";
    echo "  - Alguns campos podem já existir (verifique em Campos Customizados)\n";
}

echo "\n";
