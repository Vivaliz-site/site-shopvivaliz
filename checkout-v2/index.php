<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/includes/csrf.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !sv_csrf_valid('checkout-v2', $_POST['csrf_token'] ?? null)) {
    $_SERVER['REQUEST_METHOD'] = 'CSRF_REJECTED';
    http_response_code(419);
}

header('Content-Type: text/html; charset=UTF-8');

$runtimeSecretsFile = dirname(__DIR__) . '/config/runtime-secrets.php';
if (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile)) {
    $runtimeSecrets = require $runtimeSecretsFile;
    if (is_array($runtimeSecrets)) {
        foreach ($runtimeSecrets as $key => $value) {
            if (!is_string($key) || $key === '' || getenv($key) !== false) {
                continue;
            }
            $stringValue = is_scalar($value) ? (string)$value : '';
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
        }
    }
}

function sv_checkout_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim(trim($value), "\"'");
                if ($key !== '' && getenv($key) === false) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
    }

    return '';
}

function sv_checkout_shipping_note(float $shippingTotal, string $shippingLabel): string
{
    if ($shippingTotal <= 0 && $shippingLabel === '') {
        return '';
    }

    $parts = [];
    if ($shippingLabel !== '') {
        $parts[] = 'Frete: ' . $shippingLabel;
    }
    if ($shippingTotal > 0) {
        $parts[] = 'Valor do frete: R$ ' . number_format($shippingTotal, 2, ',', '.');
    }

    return implode(' | ', $parts);
}

$pixKey = sv_checkout_env('LOJA_PIX_KEY') ?: 'contato@vivaliz.com.br';
$pixName = sv_checkout_env('LOJA_PIX_NAME') ?: 'Vivaliz Store';
$whatsapp = sv_checkout_env('LOJA_WHATSAPP');

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
            'payment_method' => trim((string)($_POST['payment_method'] ?? 'pix')),
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
        // Sanitizar valores para prevenir email injection
        $sanitize = fn($v) => str_replace(["\n", "\r", "\0"], "", (string)$v);

        $body  = "Novo pedido recebido pelo site Vivaliz.

";
        $body .= "ID: " . $sanitize($pedidoId) . "
";
        $body .= "Data: " . date('d/m/Y H:i') . "

";
        $body .= "CLIENTE
";
        $body .= "Nome: " . $sanitize($cliente['nome']) . "
";
        $body .= "Email: " . $sanitize($cliente['email']) . "
";
        $body .= "Telefone: " . $sanitize($cliente['telefone']) . "
";
        $body .= "Endereco: " . $sanitize($cliente['endereco']) . ", " . $sanitize($cliente['numero']) . " " . $sanitize($cliente['complemento']) . "
";
        $body .= "Cidade/CEP: " . $sanitize($cliente['cidade']) . " - " . $sanitize($cliente['cep'])

";
        $body .= "ITENS
{$itemLines}

";
        $body .= "TOTAL: R$ {$totalFmt} (frete a calcular)

";
        $body .= "Acesse os pedidos em: https://shopvivaliz.com.br/admin/
";
        @mail($adminEmail, $subject, $body, "From: pedidos@shopvivaliz.com.br

Content-Type: text/plain; charset=UTF-8");

        $pedidoCriado = true;
    }
}

$paymentOptions = [
    'pix' => ['title' => 'PIX', 'desc' => 'Aprovacao imediata'],
    'boleto' => ['title' => 'Boleto', 'desc' => 'Emissao apos confirmacao do frete'],
    'whatsapp' => ['title' => 'WhatsApp', 'desc' => 'Atendimento assistido'],
    'transferencia' => ['title' => 'Transferencia', 'desc' => 'TED / DOC'],
];
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
        .checkout-layout[hidden] {
            display: none;
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
        .payment-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .payment-option {
            display: flex;
        }
        .payment-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .payment-option-box {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .payment-option input:checked + .payment-option-box {
            border-color: #1F3A70;
            box-shadow: 0 0 0 3px rgba(31, 58, 112, 0.12);
            background: #f8fbff;
        }
        .payment-option-box strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .payment-option-box small {
            color: #64748b;
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
        .summary-meta {
            display: grid;
            gap: 10px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
        }
        .summary-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            color: #475569;
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
        .checkout-login-hint {
            background: #ecfdf5;
            border: 1px solid rgba(5, 150, 105, 0.25);
            color: #065f46;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 18px;
        }
        .checkout-login-hint a {
            color: #047857;
            font-weight: 700;
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
        .status-message {
            margin-top: 12px;
            font-size: 14px;
        }
        .status-message.err {
            color: #b91c1c;
        }
        .status-message.ok {
            color: #166534;
        }
        .payment-help {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #f8fafc;
            color: #475569;
            line-height: 1.6;
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
            .payment-options {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php $svNavCurrent = 'checkout'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="checkout-shell">
    <div class="container">
        <?php if ($pedidoCriado): ?>
            <section class="success-panel">
                <h2>Pedido recebido com sucesso</h2>
                <p class="muted">Seu pedido foi registrado e entrou na fila de atendimento da Vivaliz.</p>
                <div class="order-code"><?php echo htmlspecialchars((string)$pedidoId, ENT_QUOTES, 'UTF-8'); ?></div>
                <p class="muted">Seu pedido foi registrado. Nossa equipe comercial já seguirá com a confirmação de pagamento e prazo de entrega.</p>
                <?php
                $wppItems = implode(', ', array_map(function($it){ return $it['name'] . ' x' . ($it['quantity'] ?? 1); }, $items));
                $wppMsg = rawurlencode("Ola! Fiz um pedido na Vivaliz (ID: {$pedidoId}).
Itens: {$wppItems}
Aguardo confirmacao e dados de pagamento. Obrigado!");
                $wppTel = preg_replace('/\D+/', '', $whatsapp);
                ?>
                <div style="display:grid;gap:12px;margin-top:16px;">
                    <?php if ($wppTel !== ''): ?>
                        <a class="primary-btn" href="https://wa.me/<?= $wppTel ?>?text=<?= $wppMsg ?>" target="_blank" rel="noreferrer"
                           style="background:#25D366;border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                            📱 Confirmar pelo WhatsApp
                        </a>
                    <?php else: ?>
                        <a class="primary-btn" href="/contato" style="border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                            Falar com a equipe
                        </a>
                    <?php endif; ?>
                    <a class="ghost-btn" href="/catalogo" style="border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;">Voltar ao catálogo</a>
                </div>
            </section>
        <?php else: ?>
            <h1>Checkout</h1>
            <p class="muted">Finalize seus dados para transformar o carrinho em pedido.</p>

            <?php if (empty($svLoggedIn)): ?>
                <div class="checkout-login-hint">
                    Já é cliente? <a href="/auth/login.php?redirect=/checkout">Faça login</a> para agilizar o preenchimento dos seus dados.
                </div>
            <?php endif; ?>

            <div id="checkout-empty" class="checkout-empty" hidden>
                <h2>Nao ha itens no carrinho</h2>
                <p>Adicione produtos antes de seguir para a finalizacao.</p>
                <a class="ghost-btn" href="catalogo">Ir para o catalogo</a>
            </div>

            <div id="checkout-content" class="checkout-layout">
                <form method="POST" class="form-panel" id="checkout-form">
                    <?= sv_csrf_input('checkout-v2') ?>
                    <input type="hidden" name="acao" value="finalizar_pedido">
                    <input type="hidden" name="cart_payload" id="cart-payload" value="[]">

                    <section class="form-section">
                        <h2>Dados pessoais</h2>
                        <div class="form-grid full">
                            <div class="field">
                                <label for="nome">Nome completo</label>
                                <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($svUserName ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="email">E-mail</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="field">
                                <label for="telefone">Telefone</label>
                                <input type="tel" id="telefone" name="telefone" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="cpf">CPF</label>
                                <input type="text" id="cpf" name="cpf" inputmode="numeric" maxlength="14" required>
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
                                <label for="bairro">Bairro</label>
                                <input type="text" id="bairro" name="bairro" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="estado">Estado (UF)</label>
                                <input type="text" id="estado" name="estado" maxlength="2" minlength="2" style="text-transform:uppercase" required>
                            </div>
                            <div class="field">
                                <label for="cep">CEP</label>
                                <input type="text" id="cep" name="cep" required>
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <h2>Forma de pagamento</h2>
                        <div class="payment-options">
                            <?php foreach ($paymentOptions as $value => $option): ?>
                                <label class="payment-option">
                                    <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $value === 'pix' ? 'checked' : ''; ?>>
                                    <span class="payment-option-box">
                                        <strong><?php echo htmlspecialchars($option['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <small><?php echo htmlspecialchars($option['desc'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="payment-help">
                            PIX mostra a chave imediatamente. Boleto e transferencia seguem para confirmacao manual de frete e emissao pela equipe.
                        </div>
                    </section>

                    <button class="primary-btn" type="submit" id="checkout-submit">Confirmar pedido</button>
                    <div class="status-message" id="checkout-status" aria-live="polite"></div>
                </form>

                <aside class="summary-panel">
                    <h2>Resumo do pedido</h2>
                    <div id="checkout-summary" class="summary-list"></div>
                    <div class="summary-meta">
                        <div class="summary-meta-row"><span>Subtotal</span><strong id="checkout-subtotal">R$ 0,00</strong></div>
                        <div class="summary-meta-row"><span>Frete</span><strong id="checkout-shipping">A calcular</strong></div>
                        <div class="summary-meta-row"><span>Entrega</span><strong id="checkout-shipping-label">A confirmar</strong></div>
                    </div>
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
        const subtotalNode = document.getElementById('checkout-subtotal');
        const shippingNode = document.getElementById('checkout-shipping');
        const shippingLabelNode = document.getElementById('checkout-shipping-label');
        const totalNode = document.getElementById('checkout-total');
        const payloadNode = document.getElementById('cart-payload');
        const form = document.getElementById('checkout-form');
        const statusNode = document.getElementById('checkout-status');
        const submitNode = document.getElementById('checkout-submit');
        const pixKey = <?php echo json_encode($pixKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const pixName = <?php echo json_encode($pixName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const whatsapp = <?php echo json_encode($whatsapp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const paymentLabels = {
            pix: 'PIX',
            boleto: 'Boleto bancario',
            whatsapp: 'WhatsApp',
            transferencia: 'Transferencia bancaria'
        };

        function money(value) {
            const amount = Number(value || 0);
            if (!amount) return 'Preco sob consulta';
            return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }

        function readCart() {
            try {
                const value = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
                return Array.isArray(value) ? value : [];
            } catch (error) {
                return [];
            }
        }

        function readShippingQuote() {
            try {
                const value = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null');
                return value && typeof value === 'object' ? value : null;
            } catch (error) {
                return null;
            }
        }

        const items = readCart().filter(function (item) {
            return item && item.name;
        });
        const shippingQuote = readShippingQuote();

        if (!items.length) {
            if (content) content.hidden = true;
            if (emptyState) emptyState.hidden = false;
            return;
        }

        const subtotal = items.reduce(function (sum, item) {
            return sum + (Number(item.price || 0) * Number(item.quantity || 1));
        }, 0);
        const shippingTotal = Number(shippingQuote && shippingQuote.shipping_total || 0);
        const grandTotal = subtotal + shippingTotal;
        const shippingLabel = shippingQuote && shippingQuote.selected_option
            ? [shippingQuote.selected_option.company, shippingQuote.selected_option.name].filter(Boolean).join(' - ')
            : '';

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

        if (subtotalNode) subtotalNode.textContent = money(subtotal);
        if (shippingNode) shippingNode.textContent = shippingTotal > 0 ? money(shippingTotal) : 'A calcular';
        if (shippingLabelNode) shippingLabelNode.textContent = shippingLabel || 'A confirmar';
        if (totalNode) totalNode.textContent = money(grandTotal);
        if (payloadNode) payloadNode.value = JSON.stringify(items);

        function setStatus(message, type) {
            if (!statusNode) return;
            statusNode.textContent = message;
            statusNode.className = 'status-message' + (type ? ' ' + type : '');
        }

        function buildWhatsappLink(orderNumber, totalLabel, paymentMethod) {
            const number = String(whatsapp || '').replace(/\D/g, '');
            if (!number) return '/contato';
            const text = encodeURIComponent(
                'Ola! Acabei de fazer um pedido na Vivaliz.\n' +
                'Numero: ' + orderNumber + '\n' +
                'Pagamento: ' + (paymentLabels[paymentMethod] || paymentMethod) + '\n' +
                'Total: ' + totalLabel + '\n' +
                'Aguardo confirmacao de frete e pagamento.'
            );
            return 'https://wa.me/' + number + '?text=' + text;
        }

        function renderSuccess(response, paymentMethod) {
            const totalLabel = money(grandTotal);
            const whatsappLink = buildWhatsappLink(response.order_number, totalLabel, paymentMethod);
            const tinySyncNote = response.tiny_order_id
                ? '<p class="muted">Pedido sincronizado com o ERP. Codigo Tiny: <strong>' + response.tiny_order_id + '</strong></p>'
                : '<p class="muted">Importacao ERP: <strong>' + (response.tiny_push || 'nao informado') + '</strong></p>';
            const shippingBlock = response.shipping_total > 0
                ? '<div class="payment-help"><strong>Frete confirmado:</strong> ' + money(response.shipping_total) + (response.shipping_label ? '<br><strong>Entrega:</strong> ' + response.shipping_label : '') + '</div>'
                : '';

            let paymentBlock = '<p class="muted">' + (response.payment_instructions || 'Pedido registrado com sucesso.') + '</p>';
            if (paymentMethod === 'pix') {
                paymentBlock += '<div class="payment-help"><strong>Chave PIX:</strong> ' + pixKey + '<br><strong>Beneficiario:</strong> ' + pixName + '</div>';
            } else if (paymentMethod === 'boleto') {
                paymentBlock += '<div class="payment-help"><strong>Boleto:</strong> a equipe vai emitir o boleto apos confirmar frete, estoque e dados do pedido.</div>';
            }

            content.innerHTML = ''
                + '<section class="success-panel">'
                + '<h2>Pedido recebido com sucesso</h2>'
                + '<p class="muted">Seu pedido foi registrado e entrou na fila de atendimento da Vivaliz.</p>'
                + '<div class="order-code">' + response.order_number + '</div>'
                + paymentBlock
                + shippingBlock
                + tinySyncNote
                + '<div style="display:grid;gap:12px;margin-top:16px;">'
                + '<a class="primary-btn" href="' + whatsappLink + '"' + (whatsappLink === '/contato' ? '' : ' target="_blank" rel="noreferrer"') + ' style="background:' + (whatsappLink === '/contato' ? '#1F3A70' : '#25D366') + ';">' + (whatsappLink === '/contato' ? 'Falar com a equipe' : '📱 Falar no WhatsApp') + '</a>'
                + '<a class="ghost-btn" href="/catalogo">Voltar ao catalogo</a>'
                + '</div>'
                + '</section>';
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!items.length) {
                setStatus('Nao ha itens no carrinho.', 'err');
                return;
            }

            submitNode.disabled = true;
            submitNode.textContent = 'Enviando...';
            setStatus('', '');

            const formData = new FormData(form);
            const payload = {
                customer_name: formData.get('nome') || '',
                customer_email: formData.get('email') || '',
                customer_phone: formData.get('telefone') || '',
                cep: formData.get('cep') || '',
                address: formData.get('endereco') || '',
                street_name: formData.get('endereco') || '',
                street_number: formData.get('numero') || '',
                complement: formData.get('complemento') || '',
                neighborhood: formData.get('bairro') || '',
                city: formData.get('cidade') || '',
                state: formData.get('estado') || '',
                cpf: formData.get('cpf') || '',
                notes: '',
                payment_method: formData.get('payment_method') || 'pix',
                items: items,
                shipping_total: shippingTotal,
                shipping_label: shippingLabel,
                shipping_service: shippingQuote && shippingQuote.selected_option ? (shippingQuote.selected_option.id || '') : '',
                shipping_cep: shippingQuote && shippingQuote.cep ? shippingQuote.cep : ''
            };

            fetch('/api/orders/create-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                submitNode.disabled = false;
                submitNode.textContent = 'Confirmar pedido';
                if (!response.ok) {
                    if (response.error === 'insufficient_stock' && Array.isArray(response.items)) {
                        var details = response.items.map(function (it) {
                            return it.name + ' (disponivel: ' + it.available + ', pedido: ' + it.requested + ')';
                        }).join('; ');
                        setStatus('Estoque insuficiente: ' + details + '. Ajuste as quantidades no carrinho.', 'err');
                        return;
                    }
                    setStatus(response.message || response.error || 'Erro ao registrar pedido.', 'err');
                    return;
                }

                localStorage.removeItem('shopvivaliz_cart');
                renderSuccess(response, payload.payment_method);
            })
            .catch(function () {
                submitNode.disabled = false;
                submitNode.textContent = 'Confirmar pedido';
                setStatus('Erro de conexao. Tente novamente.', 'err');
            });
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
