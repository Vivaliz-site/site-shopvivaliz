<?php
/**
 * Teste de Webhooks - Simula eventos do Tiny/Olist
 * Acesso: /admin/webhook-test.php
 */
declare(strict_types=1);
session_start();
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: /admin/login.php');
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Webhooks - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto;
            background: #f5f5f5;
            color: #333;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        header {
            background: #173B63;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 { font-size: 24px; }
        .back { color: white; text-decoration: none; padding: 8px 16px; background: rgba(255,255,255,0.2); border-radius: 4px; }

        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h2 {
            color: #173B63;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #173B63;
            padding-bottom: 10px;
        }

        .webhook-item {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #28a745;
            border-radius: 4px;
        }
        .webhook-item.error { border-left-color: #dc3545; }
        .webhook-item h3 { font-size: 16px; margin-bottom: 8px; color: #173B63; }
        .webhook-item p { font-size: 13px; color: #666; margin: 5px 0; }
        .webhook-item code {
            display: block;
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 12px;
            overflow-x: auto;
        }

        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status.ok { background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        .status.warning { background: #fff3cd; color: #856404; }

        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            background: #28a745;
            color: white;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
            margin-top: 10px;
        }
        button:hover { background: #218838; }
        button.danger { background: #dc3545; }
        button.danger:hover { background: #c82333; }

        .log-box {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }

        .info {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header>
        <h1>🔌 Teste de Webhooks</h1>
        <a href="/admin/" class="back">← Voltar</a>
    </header>

    <div class="container">
        <div class="section">
            <h2>📊 Status dos Webhooks</h2>

            <div class="info">
                ℹ️ <strong>Nota:</strong> Estes testes simulam eventos do Tiny/Olist para verificar se os webhooks estão funcionando corretamente.
            </div>

            <?php
            $webhooks = [
                [
                    'name' => 'Webhook de Preços (Olist)',
                    'url' => '/olist/webhook-receiver.php?event=price',
                    'method' => 'POST',
                    'type' => 'price',
                    'payload' => [
                        'event' => 'preco.alterado',
                        'produto_id' => 'KITROD12',
                        'sku' => 'KITROD12',
                        'preco' => 149.99,
                        'precos' => ['preco' => 149.99]
                    ]
                ],
                [
                    'name' => 'Webhook de Estoque (Olist)',
                    'url' => '/olist/webhook-receiver.php?event=stock',
                    'method' => 'POST',
                    'type' => 'stock',
                    'payload' => [
                        'event' => 'estoque.alterado',
                        'produto_id' => 'KITROD12',
                        'sku' => 'KITROD12',
                        'estoque_disponivel' => 25
                    ]
                ],
                [
                    'name' => 'Webhook de Produto (Olist)',
                    'url' => '/olist/webhook-receiver.php?event=product',
                    'method' => 'POST',
                    'type' => 'product',
                    'payload' => [
                        'event' => 'produto.atualizado',
                        'produto_id' => 'KITROD12',
                        'sku' => 'KITROD12',
                        'nome' => 'Kit 12 Rodízios'
                    ]
                ],
                [
                    'name' => 'Webhook de Preços (Direto)',
                    'url' => '/api/webhooks/tiny-product-price-sync.php',
                    'method' => 'POST',
                    'type' => 'price-direct',
                    'payload' => [
                        'tipo' => 'produto.atualizado',
                        'produto' => [
                            'sku' => 'KITROD12',
                            'preco' => 149.99,
                            'precos' => ['preco' => 149.99]
                        ]
                    ]
                ]
            ];

            foreach ($webhooks as $idx => $webhook):
            ?>
                <div class="webhook-item">
                    <h3>
                        <?= htmlspecialchars($webhook['name']) ?>
                        <span class="status ok">Disponível</span>
                    </h3>
                    <p><strong>URL:</strong> <?= htmlspecialchars($webhook['url']) ?></p>
                    <p><strong>Tipo:</strong> <?= htmlspecialchars($webhook['type']) ?></p>
                    <p><strong>Payload:</strong></p>
                    <code><?= htmlspecialchars(json_encode($webhook['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></code>
                    <button onclick="testWebhook(<?= $idx ?>)">🧪 Testar este webhook</button>
                    <button class="danger" onclick="clearLog(<?= $idx ?>)">🗑️ Limpar log</button>
                    <div class="log-box" id="log-<?= $idx ?>"></div>
                </div>
            <?php endforeach; ?>

            <div class="webhook-item">
                <h3>📋 Verificar Logs</h3>
                <p>Acesse os logs dos webhooks para diagnosticar problemas:</p>
                <button onclick="viewLog('webhook')">Ver log webhook.log</button>
                <button onclick="viewLog('price')">Ver log tiny-webhook-price.log</button>
                <div class="log-box" id="log-view"></div>
            </div>
        </div>

        <div class="section">
            <h2>⚙️ Configuração</h2>
            <div class="info">
                <strong>URLs configuradas no Tiny/Olist:</strong>
                <ul style="margin-top: 10px; padding-left: 20px;">
                    <li>Produtos: <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">https://shopvivaliz.com.br/olist/webhook-receiver.php?event=product</code></li>
                    <li>Preços: <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">https://shopvivaliz.com.br/olist/webhook-receiver.php?event=price</code></li>
                    <li>Estoque: <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">https://shopvivaliz.com.br/olist/webhook-receiver.php?event=stock</code></li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        async function testWebhook(idx) {
            const webhooks = <?= json_encode($webhooks) ?>;
            const webhook = webhooks[idx];
            const logBox = document.getElementById(`log-${idx}`);

            logBox.textContent = '⏳ Enviando webhook...\n';

            try {
                const response = await fetch(webhook.url, {
                    method: webhook.method,
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(webhook.payload)
                });

                const data = await response.json();
                logBox.textContent += `✓ Resposta (Status ${response.status}):\n`;
                logBox.textContent += JSON.stringify(data, null, 2) + '\n';
                logBox.textContent += `\n✓ Webhook enviado com sucesso!\n`;
                logBox.textContent += `ℹ️ Verifique os logs para confirmar a sincronização.\n`;
            } catch (error) {
                logBox.textContent += `✗ Erro: ${error.message}\n`;
            }
        }

        async function viewLog(type) {
            const logBox = document.getElementById('log-view');
            logBox.textContent = '⏳ Carregando log...\n';

            const files = {
                'webhook': '/logs/webhook.log',
                'price': '/logs/tiny-webhook-price.log'
            };

            try {
                const response = await fetch(`/api/read-log.php?file=${files[type] || files['webhook']}`);
                const data = await response.json();
                if (data.content) {
                    logBox.textContent = data.content;
                } else {
                    logBox.textContent = '(Arquivo vazio ou não encontrado)\n';
                }
            } catch (error) {
                logBox.textContent = `✗ Erro ao carregar log: ${error.message}\n`;
            }
        }

        function clearLog(idx) {
            document.getElementById(`log-${idx}`).textContent = '';
        }
    </script>
</body>
</html>
