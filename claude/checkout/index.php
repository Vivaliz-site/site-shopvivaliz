<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !sv_csrf_valid('claude-checkout', $_POST['csrf_token'] ?? null)) {
    $_SERVER['REQUEST_METHOD'] = 'CSRF_REJECTED';
    http_response_code(419);
}

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
        $totalPedido = array_sum(array_map(static function (array $item): float {
            return (float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1));
        }, $items));
        $registro = [
            'id' => $pedidoId,
            'timestamp' => date('c'),
            'cliente' => $cliente,
            'items' => $items,
            'total' => $totalPedido,
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

        $pedidoCriado = true;
    }
}

$pixKey  = (string)(getenv('LOJA_PIX_KEY')  ?: '');
$pixName = (string)(getenv('LOJA_PIX_NAME') ?: 'ShopVivaliz');
$whatsapp = (string)(getenv('LOJA_WHATSAPP') ?: '');
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
<?php include __DIR__ . '/../navbar.php'; ?>
<main class="checkout-shell">
    <div class="container">
        <?php if ($pedidoCriado): ?>
            <section class="success-panel">
                <h2>Pedido recebido com sucesso</h2>
                <p class="muted">Seu pedido foi registrado e entrou na fila de atendimento da ShopVivaliz.</p>
                <div class="order-code"><?php echo htmlspecialchars((string)$pedidoId, ENT_QUOTES, 'UTF-8'); ?></div>

                <?php if ($totalPedido > 0): ?>
                <p style="margin:12px 0;font-size:1.1rem;font-weight:700;color:#0f172a;">
                    Total: R$ <?php echo number_format($totalPedido, 2, ',', '.'); ?>
                </p>
                <?php endif; ?>

                <?php if ($pixKey !== ''): ?>
                <div style="margin:18px 0;padding:18px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:14px;">
                    <p style="font-weight:700;color:#166534;margin-bottom:8px;">Pague via PIX</p>
                    <p style="color:#374151;font-size:.9rem;margin-bottom:10px;">
                        Beneficiário: <strong><?php echo htmlspecialchars($pixName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </p>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <code id="pix-key" style="flex:1;padding:10px 14px;background:white;border:1px solid #d1fae5;border-radius:8px;font-size:.95rem;word-break:break-all;">
                            <?php echo htmlspecialchars($pixKey, ENT_QUOTES, 'UTF-8'); ?>
                        </code>
                        <button onclick="navigator.clipboard.writeText(document.getElementById('pix-key').textContent.trim()).then(function(){this.textContent='Copiado!';}.bind(this))"
                            style="padding:10px 16px;background:#22c55e;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;white-space:nowrap;">
                            Copiar chave
                        </button>
                    </div>
                    <p style="color:#6b7280;font-size:.82rem;margin-top:10px;">
                        Após o pagamento, envie o comprovante pelo WhatsApp para confirmar seu pedido.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($whatsapp !== ''): ?>
                <?php
                    $waNumber = preg_replace('/\D/', '', $whatsapp);
                    $waMsg = urlencode('Olá! Acabei de fazer o pedido ' . $pedidoId . ' no site ShopVivaliz. Seguem meus dados.');
                ?>
                <a href="https://wa.me/<?php echo htmlspecialchars($waNumber, ENT_QUOTES, 'UTF-8'); ?>?text=<?php echo $waMsg; ?>"
                   target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:12px 20px;background:#25d366;color:white;border-radius:12px;font-weight:700;text-decoration:none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.122 1.532 5.855L.057 23.998l6.304-1.654A11.954 11.954 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.016-1.378l-.36-.213-3.732.979 1.001-3.645-.235-.375A9.818 9.818 0 112 12a9.818 9.818 0 0110 9.818z"/></svg>
                    Falar no WhatsApp
                </a>
                <?php endif; ?>

                <a class="ghost-btn" href="catalogo">Voltar ao catalogo</a>
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
                    <?= sv_csrf_input('claude-checkout') ?>
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
