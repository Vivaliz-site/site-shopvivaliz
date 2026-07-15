<?php
declare(strict_types=1);

// Precisa iniciar antes de qualquer output; a pagina imprime bastante HTML
// (todo o <head>) antes de incluir includes/navbar.php, entao o
// session_start() de la dentro chega tarde demais (headers ja enviados).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Gerar CSRF token para o formulário de checkout
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once dirname(__DIR__) . '/includes/csrf.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !sv_csrf_valid('checkout', $_POST['csrf_token'] ?? null)) {
    $_SERVER['REQUEST_METHOD'] = 'CSRF_REJECTED';
    http_response_code(419);
}

header('Content-Type: text/html; charset=UTF-8');

require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/database.php';

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

$pixKey = sv_checkout_env('LOJA_PIX_KEY') ?: 'contato@vivaliz.com.br';
$pixName = sv_checkout_env('LOJA_PIX_NAME') ?: 'Vivaliz Store';
$whatsapp = sv_checkout_env('LOJA_WHATSAPP') ?: '5511999999999';

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

        // Criar Order no Mercado Pago conforme API oficial
        $mpOrderId = null;
        $total = array_reduce($items, function ($s, $it) {
            return $s + (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1);
        }, 0.0);

        $mpOrderPayload = [
            'external_reference' => $pedidoId,
            'total_amount' => (float)$total,
            'items' => array_map(function ($item) {
                return [
                    'sku_number' => $item['sku'] ?? $item['id'] ?? '',
                    'category' => $item['category'] ?? 'Produto',
                    'title' => $item['name'] ?? 'Item',
                    'description' => $item['name'] ?? 'Item do pedido',
                    'unit_price' => (float)($item['price'] ?? 0),
                    'quantity' => (int)($item['quantity'] ?? 1)
                ];
            }, $items),
            'payer' => [
                'email' => $cliente['email'],
                'first_name' => explode(' ', $cliente['nome'])[0] ?? '',
                'last_name' => implode(' ', array_slice(explode(' ', $cliente['nome']), 1)) ?? '',
                'phone' => $cliente['telefone'] ?? ''
            ]
        ];

        // Chamar Orders API do Mercado Pago
        try {
            $ch = curl_init('https://api.mercadopago.com/v1/orders');

            $env = [];
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                    [$key, $value] = explode('=', $line, 2);
                    $env[trim($key)] = trim(trim($value), "\"'");
                }
            }

            $mpToken = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? '';

            if ($mpToken) {
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $mpToken,
                    ],
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($mpOrderPayload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 10,
                ]);

                $mpResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 201) {
                    $mpData = json_decode($mpResponse, true);
                    if (isset($mpData['id'])) {
                        $mpOrderId = $mpData['id'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Erro ao criar Order no Mercado Pago: ' . $e->getMessage());
        }

        $registro = [
            'id' => $pedidoId,
            'mercadopago_order_id' => $mpOrderId,
            'timestamp' => date('c'),
            'cliente' => $cliente,
            'items' => $items,
            'payment_method' => trim((string)($_POST['payment_method'] ?? 'mercado_pago')),
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

        // Salvar no banco de dados
        try {
            $db = Database::getInstance();
            $total = array_reduce($items, function ($s, $it) {
                return $s + (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1);
            }, 0.0);

            $stmt = $db->prepare('INSERT INTO orders (id, customer_name, customer_email, customer_phone, customer_address, customer_city, customer_zip, total, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->bind_param('sssssssds', $pedidoId, $cliente['nome'], $cliente['email'], $cliente['telefone'], $cliente['endereco'], $cliente['cidade'], $cliente['cep'], $total, $registro['payment_method']);
            $stmt->execute();

            // Salvar itens do pedido
            foreach ($items as $item) {
                $stmt = $db->prepare('INSERT INTO order_items (order_id, product_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())');
                $qty = (int)($item['quantity'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $stmt->bind_param('sidi', $pedidoId, $item['id'], $qty, $price);
                $stmt->execute();
            }
        } catch (Exception $e) {
            error_log('Erro ao salvar pedido no BD: ' . $e->getMessage());
        }

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
        @mail($adminEmail, $subject, $body, "From: pedidos@dev.shopvivaliz.com.br\r\nContent-Type: text/plain; charset=UTF-8");

        // Email de confirmacao para o cliente
        $clienteSubject = "Pedido confirmado - {$pedidoId} - Vivaliz";
        $clienteBody = "Olá {$cliente['nome']},

Seu pedido foi recebido com sucesso!

ID DO PEDIDO: {$pedidoId}
Data: " . date('d/m/Y H:i') . "

ITENS COMPRADOS
{$itemLines}

TOTAL: R$ {$totalFmt}
Forma de pagamento: " . ucfirst(str_replace('_', ' ', $registro['payment_method'])) . "

ENDEREÇO DE ENTREGA
{$cliente['endereco']}, {$cliente['numero']} {$cliente['complemento']}
{$cliente['cidade']} - {$cliente['cep']}

Você pode acompanhar seu pedido aqui: https://dev.shopvivaliz.com.br/

Se tiver dúvidas, entre em contato conosco pelo WhatsApp: https://wa.me/{$whatsapp}

Obrigado pela compra!
Vivaliz";

        @mail($cliente['email'], $clienteSubject, $clienteBody, "From: pedidos@dev.shopvivaliz.com.br\r\nContent-Type: text/plain; charset=UTF-8");

        $pedidoCriado = true;
    }
}

$paymentOptions = [
    'mercado_pago' => ['title' => 'Mercado Pago', 'desc' => 'Cartão, PIX, Boleto'],
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
<?php include __DIR__ . '/../includes/navbar.php'; ?>
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
                    <?= sv_csrf_input('checkout') ?>
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
                    </section>

                    <section class="form-section">
                        <h2>Endereco de entrega</h2>
                        <div class="form-grid full">
                            <div class="field">
                                <label for="cep">CEP</label>
                                <input type="text" id="cep" name="cep" placeholder="12345-678" required>
                                <small id="cep-status"></small>
                            </div>
                        </div>
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
                        <div class="form-grid full">
                            <div class="field">
                                <label for="cidade">Cidade</label>
                                <input type="text" id="cidade" name="cidade" required>
                            </div>
                        </div>
                    </section>

                    <!-- Hidden payment method field -->
                    <input type="hidden" name="payment_method" value="mercado_pago">

                    <!-- Mercado Pago Payment Section -->
                    <section class="form-section">
                        <h2>Forma de pagamento</h2>
                        <div style="text-align: center; padding: 2rem 0;">
                            <img src="https://http2.mlstatic.com/frontend-assets/ui-navigation/5.17.1/mercadopago/60.png" alt="Mercado Pago" style="height: 60px; margin-bottom: 1rem;">
                            <p style="color: #666; margin-bottom: 1.5rem;">
                                Pagamento seguro via Mercado Pago
                                <br>
                                <small>Aceita: Cartão de Crédito, PIX, Boleto e mais</small>
                            </p>
                            <button type="button" id="checkout-mp-btn" class="primary-btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 1rem; font-weight: 600; cursor: pointer;">
                                💳 Continuar com Mercado Pago
                            </button>
                        </div>
                    </section>
                    <div class="status-message" id="checkout-status" aria-live="polite"></div>
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

        function setStatus(message, type) {
            if (!statusNode) return;
            statusNode.textContent = message;
            statusNode.className = 'status-message' + (type ? ' ' + type : '');
        }

        function hiddenValue(name) {
            const field = form.querySelector('input[name="' + name + '"]');
            return field ? String(field.value || '') : '';
        }

        function buildWhatsappLink(orderNumber, totalLabel, paymentMethod) {
            const text = encodeURIComponent(
                'Ola! Acabei de fazer um pedido na Vivaliz.\n' +
                'Numero: ' + orderNumber + '\n' +
                'Pagamento: ' + (paymentLabels[paymentMethod] || paymentMethod) + '\n' +
                'Total: ' + totalLabel + '\n' +
                'Aguardo confirmacao de frete e pagamento.'
            );
            return 'https://wa.me/' + String(whatsapp || '').replace(/\D/g, '') + '?text=' + text;
        }

        function renderSuccess(response, paymentMethod) {
            const totalLabel = money(total);
            const whatsappLink = buildWhatsappLink(response.order_number, totalLabel, paymentMethod);
            const tinySyncOk = response.tiny_order_id && response.tiny_push === 'ok';
            const tinySyncNote = tinySyncOk
                ? '<p class="muted">Pedido sincronizado com o ERP. Codigo Tiny: <strong>' + response.tiny_order_id + '</strong></p>'
                : '<p class="muted">Importacao ERP pendente. Status tecnico: <strong>' + (response.tiny_push || 'nao informado') + '</strong></p>';

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
                + tinySyncNote
                + '<div style="display:grid;gap:12px;margin-top:16px;">'
                + '<a class="primary-btn" href="' + whatsappLink + '" target="_blank" rel="noreferrer" style="background:#25D366;">📱 Falar no WhatsApp</a>'
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
                address: [
                    formData.get('endereco') || '',
                    formData.get('numero') || '',
                    formData.get('complemento') || '',
                    formData.get('cidade') || ''
                ].filter(Boolean).join(', '),
                notes: '',
                payment_method: formData.get('payment_method') || 'pix',
                shipping_total: Number(hiddenValue('shipping_total') || 0),
                shipping_label: hiddenValue('shipping_label'),
                shipping_service: hiddenValue('shipping_service'),
                shipping_cep: hiddenValue('shipping_cep') || (formData.get('cep') || ''),
                shipping_quote_id: hiddenValue('shipping_quote_id'),
                shipping_expires_at: Number(hiddenValue('shipping_expires_at') || 0),
                items: items
            };

            fetch('/api/orders/create.php', {
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
                    const fallback = window.svCheckoutErrorMessage
                        ? window.svCheckoutErrorMessage(response.error, response.message)
                        : (response.message || response.error || 'Erro ao registrar pedido.');
                    setStatus(fallback, 'err');
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

    // ============================================
    // CEP LOOKUP & FRETE RECALCULATION
    // ============================================
    (function () {
        const cepInput = document.getElementById('cep');
        const cepStatus = document.getElementById('cep-status');
        const addressInput = document.getElementById('endereco');
        const cityInput = document.getElementById('cidade');

        if (!cepInput) return;

        function fetchCepData(cepValue) {
            const cep = cepValue.replace(/\D/g, '');
            if (cep.length !== 8) {
                cepStatus.textContent = '';
                return;
            }

            cepStatus.textContent = 'Buscando endereço...';

            // Fetch CEP data from ViaCEP proxy (evita CORS blocker)
            fetch('/api/viacep-proxy.php?cep=' + cep)
                .then(r => r.json())
                .then(data => {
                    if (data.erro) {
                        cepStatus.textContent = '❌ CEP não encontrado';
                        return;
                    }
                    // Preencher campos
                    addressInput.value = (data.logradouro || '') + (data.complemento ? ' ' + data.complemento : '');
                    cityInput.value = data.localidade || '';
                    cepStatus.textContent = '✅ Endereço encontrado';

                    // Recalcular frete
                    recalculateShipping(cep);
                })
                .catch(err => {
                    cepStatus.textContent = '❌ Erro ao buscar CEP';
                    console.error(err);
                });
        }

        // Buscar ao sair do campo
        cepInput.addEventListener('blur', function () {
            fetchCepData(this.value);
        });

        // Também buscar automaticamente após digitar 8 dígitos
        cepInput.addEventListener('input', function () {
            const cep = this.value.replace(/\D/g, '');
            if (cep.length === 8) {
                fetchCepData(this.value);
            }
        });

        function recalculateShipping(cep) {
            const items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            if (!Array.isArray(items) || items.length === 0) return;

            cepStatus.textContent = 'Calculando frete...';

            fetch('/api/melhorenvio/shipping-check-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cep: cep, items: items })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok && Array.isArray(data.shipping_options)) {
                    // Mostrar opções de transportadora
                    showShippingOptions(data.shipping_options);
                    cepStatus.textContent = '✅ Transportadoras disponíveis';
                } else {
                    cepStatus.textContent = '❌ Sem opções de frete';
                }
            })
            .catch(err => {
                cepStatus.textContent = '❌ Erro ao calcular frete';
                console.error('Erro:', err);
            });
        }

        function showShippingOptions(options) {
            let optionsHTML = '<div style="margin-top: 1rem; padding: 1rem; background: #f9f9f9; border-radius: 8px;">';
            optionsHTML += '<p style="margin-top: 0; font-weight: 600; color: #333;">Escolha a transportadora:</p>';

            options.forEach((opt, idx) => {
                const price = parseFloat(opt.price || 0).toFixed(2);
                const days = opt.delivery_time || '?';
                const selected = idx === 0 ? 'checked' : '';
                const labelId = 'shipping-' + idx;

                optionsHTML += `
                    <label style="display: block; margin: 0.75rem 0; padding: 0.75rem; background: white; border: 2px solid #e0e0e0; border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="radio" name="shipping_option" value="${idx}" ${selected} onchange="selectShipping(${idx}, ${price}, '${opt.name}', '${opt.quote_id}')">
                        <strong>${opt.company || opt.name}</strong>
                        <span style="float: right; color: #667eea; font-weight: 600;">R$ ${price.replace('.', ',')}</span>
                        <div style="font-size: 0.9rem; color: #666; margin-top: 0.25rem;">Entrega em ${days} dias</div>
                    </label>
                `;
            });

            optionsHTML += '</div>';

            const shippingContainer = document.getElementById('shipping-options-container') || createShippingContainer();
            shippingContainer.innerHTML = optionsHTML;

            // Selecionar primeira opção automaticamente
            if (options.length > 0) {
                selectShipping(0, options[0].price, options[0].name, options[0].quote_id);
            }
        }

        function createShippingContainer() {
            const container = document.createElement('div');
            container.id = 'shipping-options-container';
            const form = document.querySelector('form');
            const section = form.querySelector('.form-section');
            if (section) {
                section.parentNode.insertBefore(container, section.nextSibling);
            }
            return container;
        }

        window.selectShipping = function(idx, price, name, quoteId) {
            localStorage.setItem('shopvivaliz_shipping_total', price.toString());
            localStorage.setItem('shopvivaliz_shipping_label', name);
            localStorage.setItem('shopvivaliz_quote_id', quoteId);
            updateCheckoutSummary();
        }
    })();

    // ============================================
    // MERCADO PAGO BUTTON
    // ============================================
    (function () {
        const mpBtn = document.getElementById('checkout-mp-btn');
        if (!mpBtn) return;

        mpBtn.addEventListener('click', function (e) {
            e.preventDefault();

            // Validar formulário
            const form = document.querySelector('form');
            if (!form) return;

            const nome = document.getElementById('nome').value.trim();
            const email = document.getElementById('email').value.trim();
            const cep = document.getElementById('cep').value.trim();
            const endereco = document.getElementById('endereco').value.trim();

            if (!nome || !email || !cep || !endereco) {
                alert('Preencha todos os dados de entrega antes de continuar');
                return;
            }

            // Submeter formulário normalmente
            form.submit();
        });
    })();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
