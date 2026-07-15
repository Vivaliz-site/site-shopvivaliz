<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

function sv_contato_env(string $key): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $constants = dirname(__DIR__) . '/config/constants.php';
        if (is_file($constants)) {
            require_once $constants;
        }
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k !== '' && getenv($k) === false) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
    }
    $v = getenv($key);
    return is_string($v) ? trim($v) : '';
}

$whatsapp = preg_replace('/\D+/', '', sv_contato_env('LOJA_WHATSAPP'));
$whatsappMsg = rawurlencode('Olá! Vim pelo site da Vivaliz e gostaria de falar com a equipe.');
$whatsappLink = $whatsapp !== '' ? "https://wa.me/{$whatsapp}?text={$whatsappMsg}" : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Entre em contato com a Vivaliz para suporte comercial, pedidos e atendimento.">
    <title>Contato | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .whatsapp-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #25d366;
            color: #fff;
            font-weight: 800;
            padding: 12px 20px;
            border-radius: 999px;
            text-decoration: none;
            margin-top: 4px;
            margin-bottom: 14px;
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .whatsapp-cta:hover {
            background: #1eb855;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
<?php $svNavCurrent = 'contato'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page">
    <section class="brand-hero">
        <div class="container">
            <div class="brand-hero-card">
                <span class="brand-eyebrow">Contato Vivaliz</span>
                <h1>Atendimento direto para pedido, orçamento e suporte comercial.</h1>
                <p>Os canais abaixo concentram o atendimento da Vivaliz para acelerar respostas e orientar a compra sem ruído visual.</p>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid brand-grid-2">
                <article class="brand-card">
                    <h2>Fale com a equipe</h2>
                    <?php if ($whatsappLink !== ''): ?>
                        <div>
                            <a class="whatsapp-cta" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                💬 Falar no WhatsApp
                            </a>
                        </div>
                    <?php endif; ?>
                    <p>E-mail: <a href="mailto:agentes@shopvivaliz.com.br">agentes@shopvivaliz.com.br</a></p>
                    <p>Use este canal para apoio em pedidos, dúvidas de catálogo e acompanhamento comercial.</p>
                </article>
                <article class="brand-card">
                    <h2>Horário de operação</h2>
                    <p>Atendimento comercial em dias úteis, com priorização para cotações, suporte de compra e retorno operacional.</p>
                    <p>Mensagens fora do horário entram na fila e são respondidas no próximo ciclo útil.</p>
                </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
