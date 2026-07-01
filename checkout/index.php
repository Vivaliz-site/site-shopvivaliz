<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$pedidoCriado = false;
$pedidoId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'finalizar_pedido') {
    $cliente = [
        'nome' => trim((string)($_POST['nome'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'telefone' => trim((string)($_POST['telefone'] ?? '')),
        'endereco' => trim((string)($_POST['endereco'] ?? '')),
        'numero' => trim((string)($_POST['numero'] ?? '')),
        'complemento' => trim((string)($_POST['complemento'] ?? '')),
        'cidade' => trim((string)($_POST['cidade'] ?? '')),
        'cep' => trim((string)($_POST['cep'] ?? '')),
    ];
    $itemsPayload = json_decode((string)($_POST['cart_payload'] ?? '[]'), true);
    $items = is_array($itemsPayload) ? array_values(array_filter($itemsPayload, static function ($item): bool {
        return is_array($item) && !empty($item['name']);
    })) : [];

    if ($cliente['nome'] !== '' && $cliente['email'] !== '' && $cliente['telefone'] !== '' && $cliente['endereco'] !== '' && $cliente['numero'] !== '' && $cliente['cidade'] !== '' && $cliente['cep'] !== '' && $items) {
        $pedidoId = 'PED-' . date('YmdHis');
        $registro = [
            'id' => $pedidoId,
            'timestamp' => date('c'),
            'cliente' => $cliente,
            'items' => $items,
            'status' => 'pendente_atendimento',
            'source' => 'checkout_site',
        ];

        $logsDir = __DIR__ . '/../logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        file_put_contents(
            $logsDir . '/pedidos.jsonl',
            json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Email de notificacao para o lojista
        $adminEmail = defined('LOJA_EMAIL_ADMIN') ? LOJA_EMAIL_ADMIN : 'fredmourao@gmail.com';
        $subject = "Novo pedido {$pedidoId} - Vivaliz";
        $itemLines = implode("
", array_map(function ($it) {
            $price = number_format((float)($it['price'] ?? 0), 2, ',', '.');
            $qty = (int)($it['quantity'] ?? 1);
            return "  - {$it['name']} (SKU: {$it['sku']}) x{$qty} = R$ {$price}";
        }, $items));
        $total = array_reduce($items, function ($s, $it) {
            return $s + (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1);
        }, 0.0);
        $totalFmt = number_format($total, 2, ',', '.');
        $body  = "Novo pedido recebido pelo site Vivaliz.

";
        $body .= "ID: {$pedidoId}
";
        $body .= "Data: " . date('d/m/Y H:i') . "

";
        $body .= "CLIENTE
";
        $body .= "Nome: {$cliente['nome']}
";
        $body .= "Email: {$cliente['email']}
";
        $body .= "Telefone: {$cliente['telefone']}
";
        $body .= "Endereco: {$cliente['endereco']}, {$cliente['numero']} {$cliente['complemento']}
";
        $body .= "Cidade/CEP: {$cliente['cidade']} - {$cliente['cep']}

";
        $body .= "ITENS
{$itemLines}

";
        $body .= "TOTAL: R$ {$totalFmt} (frete a calcular)

";
        $body .= "Acesse os pedidos em: https://dev.shopvivaliz.com.br/admin/
";
        @mail($adminEmail, $subject, $body, "From: pedidos@dev.shopvivaliz.com.br

Content-Type: text/plain; charset=UTF-8");

        $pedidoCriado = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vivaliz - Checkout</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
        }
        .checkout-shell {
            padding: 36px 0 64px;
        }
        .checkout-shell h1 {
            color: #1F3A70;
            margin-bottom: 8px;
        }
        .checkout-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(300px, 1fr);
            gap: 24px;
            margin-top: 24px;
        }
        .form-panel,
        .summary-panel,
        .success-panel {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .form-panel {
            padding: 26px;
        }
        .summary-panel {
            padding: 22px;
            height: fit-content;
        }
        .success-panel {
            padding: 32px;
            max-width: 780px;
        }
        .success-panel h2 {
            color: #166534;
            margin-bottom: 12px;
        }
        .order-code {
            margin: 18px 0;
            padding: 14px 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-family: Consolas, monospace;
            font-weight: 700;
            color: #0f172a;
        }
        .form-section {
            margin-bottom: 22px;
        }
        .form-section h2,
        .summary-panel h2 {
            color: #1F3A70;
            margin-bottom: 12px;
            font-size: 20px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-grid.full {
            grid-template-columns: 1fr;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .field label {
            font-weight: 600;
            color: #334155;
        }
        .field input {
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font: inherit;
        }
        .field input:focus {
            outline: none;
            border-color: #1F3A70;
            box-shadow: 0 0 0 3px rgba(31, 58, 112, 0.12);
        }
        .summary-list {
            display: grid;
            gap: 12px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f7;
            color: #475569;
        }
        .summary-total {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }
        .muted {
            color: #64748b;
        }
        .primary-btn,
        .ghost-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            padding: 14px 18px;
        }
        .primary-btn {
            width: 100%;
            border: none;
            background: #1F3A70;
            color: white;
            cursor: pointer;
        }
        .ghost-btn {
            margin-top: 14px;
            background: white;
            color: #1F3A70;
            border: 1px solid #cbd5e1;
        }
        .checkout-empty {
            padding: 32px;
            text-align: center;
            color: #64748b;
        }
        .checkout-empty h2 {
            color: #1F3A70;
            margin-bottom: 12px;
        }
        @media (max-width: 900px) {
            .checkout-layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="checkout-shell">
    <div class="container">
        <?php if ($pedidoCriado): ?>
            <section class="success-panel">
                <h2>Pedido recebido com sucesso</h2>
                <p class="muted">Seu pedido foi registrado e entrou na fila de atendimento da Vivaliz.</p>
                <div class="order-code"><?php echo htmlspecialchars((string)$pedidoId, ENT_QUOTES, 'UTF-8'); ?></div>
                <p class="muted">Seu pedido foi registrado. Entraremos em contato em breve para confirmar o pagamento e prazo de entrega.</p>
                <?php
                $wppItems = implode(', ', array_map(function($it){ return $it['name'] . ' x' . ($it['quantity'] ?? 1); }, $items));
                $wppMsg = rawurlencode("Ola! Fiz um pedido na Vivaliz (ID: {$pedidoId}).
Itens: {$wppItems}
Aguardo confirmacao e dados de pagamento. Obrigado!");
                $wppTel = defined('LOJA_WHATSAPP') ? LOJA_WHATSAPP : '5511999999999';
                ?>
                <div style="display:grid;gap:12px;margin-top:16px;">
                    <a class="primary-btn" href="https://wa.me/<?= $wppTel ?>?text=<?= $wppMsg ?>" target="_blank" rel="noreferrer"
                       style="background:#25D366;border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                        📱 Confirmar pelo WhatsApp
                    </a>
                    <a class="ghost-btn" href="/catalogo" style="border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;">Voltar ao catálogo</a>
                </div>
            </section>
        <?php else: ?>
            <h1>Checkout</h1>
            <p class="muted">Finalize seus dados para transformar o carrinho em pedido.</p>

            <div id="checkout-empty" class="checkout-empty" hidden>
                <h2>Nao ha itens no carrinho</h2>
                <p>Adicione produtos antes de seguir para a finalizacao.</p>
                <a class="ghost-btn" href="catalogo">Ir para o catalogo</a>
            </div>

            <div id="checkout-content" class="checkout-layout">
                <form method="POST" class="form-panel" id="checkout-form">
                    <input type="hidden" name="acao" value="finalizar_pedido">
                    <input type="hidden" name="cart_payload" id="cart-payload" value="[]">

                    <section class="form-section">
                        <h2>Dados pessoais</h2>
                        <div class="form-grid full">
                            <div class="field">
                                <label for="nome">Nome completo</label>
                                <input type="text" id="nome" name="nome" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="email">E-mail</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="field">
                                <label for="telefone">Telefone</label>
                                <input type="tel" id="telefone" name="telefone" required>
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <h2>Endereco de entrega</h2>
                        <div class="form-grid full">
                            <div class="field">
                                <label for="endereco">Rua ou avenida</label>
                                <input type="text" id="endereco" name="endereco" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="numero">Numero</label>
                                <input type="text" id="numero" name="numero" required>
                            </div>
                            <div class="field">
                                <label for="complemento">Complemento</label>
                                <input type="text" id="complemento" name="complemento">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="cidade">Cidade</label>
                                <input type="text" id="cidade" name="cidade" required>
                            </div>
                            <div class="field">
                                <label for="cep">CEP</label>
                                <input type="text" id="cep" name="cep" required>
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <h2>Confirmacao</h2>
                        <p class="muted">O pedido sera salvo com os itens do carrinho e encaminhado para atendimento comercial.</p>
                    </section>

                    <button class="primary-btn" type="submit">Confirmar pedido</button>
                </form>

                <aside class="summary-panel">
                    <h2>Resumo do pedido</h2>
                    <div id="checkout-summary" class="summary-list"></div>
                    <div class="summary-total">
                        <span>Total</span>
                        <span id="checkout-total">R$ 0,00</span>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php if (!$pedidoCriado): ?>
<script>
    (function () {
        const content = document.getElementById('checkout-content');
        const emptyState = document.getElementById('checkout-empty');
        const summary = document.getElementById('checkout-summary');
        const totalNode = document.getElementById('checkout-total');
        const payloadNode = document.getElementById('cart-payload');
        const form = document.getElementById('checkout-form');

        function money(value) {
            return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }

        function readCart() {
            try {
                const value = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
                return Array.isArray(value) ? value : [];
            } catch (error) {
                return [];
            }
        }

        const items = readCart().filter(function (item) {
            return item && item.name;
        });

        if (!items.length) {
            if (content) content.hidden = true;
            if (emptyState) emptyState.hidden = false;
            return;
        }

        const total = items.reduce(function (sum, item) {
            return sum + (Number(item.price || 0) * Number(item.quantity || 1));
        }, 0);

        if (summary) {
            summary.innerHTML = items.map(function (item) {
                const quantity = Math.max(1, Number(item.quantity || 1));
                return `
                    <div class="summary-item">
                        <div>
                            <strong>${item.name || 'Produto'}</strong><br>
                            <span class="muted">${item.sku || 'sem-sku'} x ${quantity}</span>
                        </div>
                        <strong>${money((item.price || 0) * quantity)}</strong>
                    </div>
                `;
            }).join('');
        }

        if (totalNode) totalNode.textContent = money(total);
        if (payloadNode) payloadNode.value = JSON.stringify(items);

        form.addEventListener('submit', function () {
            localStorage.removeItem('shopvivaliz_cart');
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
