<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido Confirmado | Vivaliz</title>
    <link rel="icon" href="/images/favicon.svg?v=2026-07-27" type="image/svg+xml">
    <link rel="icon" href="/favicon.png?v=2026-07-27" type="image/png">
    <link rel="alternate icon" href="/favicon.ico?v=2" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.png?v=2">
    <meta name="msapplication-TileColor" content="#173B63">
    <meta name="theme-color" content="#173B63">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/layout-polish-v1.css?v=2026-07-29-1">
    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'confirmation'; include __DIR__ . '/includes/navbar.php'; ?>

<main class="container" style="padding: 64px 0;">
    <div style="max-width: 600px; margin: 0 auto; text-align: center;">
        <div style="font-size: 64px; margin-bottom: 24px;">✅</div>
        <h1 style="font-size: 32px; font-weight: 800; margin-bottom: 12px; color: #0f8f62;">
            Pedido Confirmado!
        </h1>
        <p style="font-size: 18px; color: var(--muted); margin-bottom: 32px; line-height: 1.6;">
            Muito obrigado pela sua compra!<br>
            Nossa equipe comercial entra em contato em breve para confirmar o frete e a forma de pagamento.
        </p>

        <div style="background: #f8fbff; border: 1px solid #d0e2f7; border-radius: 12px; padding: 24px; margin-bottom: 32px;">
            <p style="margin: 0; font-size: 14px; color: var(--muted); margin-bottom: 8px;">
                <strong>Número do pedido:</strong>
            </p>
            <p id="order-number" style="margin: 0; font-size: 18px; font-weight: 800; color: var(--ink); font-family: 'Courier New', monospace;">
                —
            </p>
            <p style="margin: 8px 0 0; font-size: 12px; color: var(--muted);">
                (Você também receberá um e-mail com esta informação)
            </p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 32px;">
            <a href="/catalogo" class="btn btn-primary" style="padding: 14px;">
                Continuar comprando
            </a>
            <a href="https://wa.me/<?php
                $company = @include __DIR__ . '/config/company-profile.php';
                $company = is_array($company) ? $company : [];
                $whatsappRaw = (string)($company['social_media']['whatsapp'] ?? $company['phone'] ?? '');
                $whatsappDigits = preg_replace('/\D+/', '', $whatsappRaw);
                echo htmlspecialchars($whatsappDigits);
            ?>?text=<?php
                echo rawurlencode('Olá! Gostaria de confirmar o status do meu pedido na Vivaliz.');
            ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding: 14px;">
                💬 Falar no WhatsApp
            </a>
        </div>

        <div style="background: #fef8f0; border: 1px solid #fed8b1; border-radius: 12px; padding: 16px;">
            <p style="margin: 0; font-size: 13px; color: var(--ink); line-height: 1.6;">
                <strong>📍 Próximos passos:</strong><br>
                1. Você receberá um e-mail com o número do pedido<br>
                2. Nossa equipe confirmará o frete disponível<br>
                3. Você receberá um link ou QR Code de pagamento<br>
                4. Após a confirmação do pagamento, começamos o preparo
            </p>
        </div>
    </div>
</main>

<footer>
    <div class="container">
        <div class="footer-cols">
            <div><strong>Vivaliz</strong><p>Qualidade e entrega rápida para todo o Brasil.</p></div>
            <div><strong>Navegação</strong><a href="/catalogo">Produtos</a><a href="/sobre">Sobre</a><a href="/contato">Contato</a></div>
            <div><strong>Atendimento</strong><a href="/contato">Fale conosco</a><a href="/faq">Dúvidas frequentes</a><a href="/politica-privacidade">Privacidade</a></div>
        </div>
        <p class="footer-copy">&copy; 2026 Vivaliz. Todos os direitos reservados.</p>
    </div>
</footer>

<script>
(function() {
    try {
        var params = new URLSearchParams(window.location.search);
        var orderNumber = params.get('order') || '';
        if (orderNumber) {
            document.getElementById('order-number').textContent = orderNumber;
        }
    } catch (e) {
        console.error('Error displaying order number:', e);
    }
})();
</script>
</body>
</html>
