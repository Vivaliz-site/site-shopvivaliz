from pathlib import Path

checkout = Path('checkout.php')
text = checkout.read_text(encoding='utf-8')

old_top = """<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
"""
new_top = """<?php
declare(strict_types=1);

// O checkout e generico para visitantes: carrinho e formulario vivem no
// navegador. Retoma PHP session apenas quando ja existe cookie (ex.: login),
// evitando Set-Cookie e permitindo cache curto de borda para GET/HEAD anonimo.
$svCheckoutMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$svCheckoutSessionName = session_name();
$svCheckoutHasSessionCookie = $svCheckoutSessionName !== ''
    && isset($_COOKIE[$svCheckoutSessionName])
    && trim((string)$_COOKIE[$svCheckoutSessionName]) !== '';
$svCheckoutPublicCache = in_array($svCheckoutMethod, ['GET', 'HEAD'], true)
    && !$svCheckoutHasSessionCookie;

if ($svCheckoutHasSessionCookie && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: text/html; charset=UTF-8');
if ($svCheckoutPublicCache) {
    header('Cache-Control: public, max-age=0, s-maxage=15, stale-while-revalidate=30');
    header('Vary: Cookie');
} else {
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}
"""
if text.count(old_top) != 1:
    raise SystemExit(f'checkout top block count={text.count(old_top)}')
text = text.replace(old_top, new_top, 1)

old_scripts = """    <!-- Mercado Pago SDK V2 + Device ID para fraude -->
    <script src=\"https://sdk.mercadopago.com/js/v2\"></script>
    <script src=\"https://www.mercadopago.com/v2/security.js\" output=\"deviceId\"></script>
"""
new_scripts = """    <!-- Mercado Pago: baixa em paralelo sem bloquear o parser; os scripts
         defer executam antes de DOMContentLoaded, preservando o Device ID. -->
    <link rel=\"preconnect\" href=\"https://sdk.mercadopago.com\" crossorigin>
    <link rel=\"preconnect\" href=\"https://www.mercadopago.com\" crossorigin>
    <script defer src=\"https://sdk.mercadopago.com/js/v2\"></script>
    <script defer src=\"https://www.mercadopago.com/v2/security.js\" output=\"deviceId\"></script>
"""
if text.count(old_scripts) != 1:
    raise SystemExit(f'checkout MP script block count={text.count(old_scripts)}')
text = text.replace(old_scripts, new_scripts, 1)

old_init = """    try {
        if (PUBLIC_KEY && window.MercadoPago) {
            // SDK V2 usa o construtor `new MercadoPago(...)`, nao o metodo
            // estatico `.configure()` da API antiga (V1). O Device ID de
            // fraude ja e coletado automaticamente pelo v2/security.js
            // carregado no <head>, nao precisa de chamada manual.
            new MercadoPago(PUBLIC_KEY, { locale: 'pt-BR' });
        }
    } catch (mpInitError) {
        console.error('MercadoPago SDK init failed', mpInitError);
    }
"""
new_init = """    function svInitMercadoPagoSdk() {
        try {
            if (PUBLIC_KEY && window.MercadoPago) {
                // Os SDKs sao defer: neste ponto (DOMContentLoaded) ja foram
                // executados e o security.js teve chance de preencher deviceId.
                new MercadoPago(PUBLIC_KEY, { locale: 'pt-BR' });
            }
        } catch (mpInitError) {
            console.error('MercadoPago SDK init failed', mpInitError);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', svInitMercadoPagoSdk, { once: true });
    } else {
        svInitMercadoPagoSdk();
    }
"""
if text.count(old_init) != 1:
    raise SystemExit(f'checkout MP init block count={text.count(old_init)}')
text = text.replace(old_init, new_init, 1)
checkout.write_text(text, encoding='utf-8')

coupons = Path('includes/active-coupons.php')
c = coupons.read_text(encoding='utf-8')
old_open = """    if ($cached === null) {
        $cached = [];

        try {
"""
new_open = """    if ($cached === null) {
        $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
        $apcuKey = 'sv_active_coupons_public_v1';
        if ($apcu) {
            $hit = false;
            $stored = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($stored)) {
                $cached = $stored;
            }
        }

        if ($cached === null) {
            $cached = [];

            try {
"""
if c.count(old_open) != 1:
    raise SystemExit(f'active coupons open block count={c.count(old_open)}')
c = c.replace(old_open, new_open, 1)

old_close = """        } catch (Throwable $e) {
            error_log('[active-coupons] db lookup failed: ' . $e->getMessage());
        }
    }

    $limit = max(0, min(6, $limit));
"""
new_close = """            } catch (Throwable $e) {
                error_log('[active-coupons] db lookup failed: ' . $e->getMessage());
            }

            // Fonte publica apenas para anuncio no navbar. O checkout/API
            // continuam validando o cupom na fonte autoritativa. TTL curto
            // reduz conexoes MySQL durante picos sem manter oferta stale.
            if ($apcu) {
                apcu_store($apcuKey, $cached, 60);
            }
        }
    }

    $limit = max(0, min(6, $limit));
"""
if c.count(old_close) != 1:
    raise SystemExit(f'active coupons close block count={c.count(old_close)}')
c = c.replace(old_close, new_close, 1)
coupons.write_text(c, encoding='utf-8')
