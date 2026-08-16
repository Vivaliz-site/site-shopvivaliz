<?php
declare(strict_types=1);

require_once __DIR__ . '/asset-manifest.php';

/**
 * Resolve a pagina PHP realmente executada, inclusive quando Apache/Nginx usa
 * uma rota amigavel como /produto/<slug>. PHP_SELF pode conter o slug nessas
 * rotas e nao deve ser a unica fonte para decidir quais assets carregar.
 */
function sv_current_page_name(): string
{
    $knownPages = ['index', 'catalogo', 'produto', 'carrinho', 'checkout'];

    foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $serverKey) {
        $candidate = basename((string)($_SERVER[$serverKey] ?? ''), '.php');
        if (in_array($candidate, $knownPages, true)) {
            return $candidate;
        }
    }

    $path = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    if ($path === '' || $path === 'index.php') {
        return 'index';
    }

    $route = explode('/', $path, 2)[0];
    $routeAliases = [
        'produtos' => 'catalogo',
        'catalogo' => 'catalogo',
        'produto' => 'produto',
        'carrinho' => 'carrinho',
        'checkout' => 'checkout',
    ];

    return $routeAliases[$route] ?? basename($path, '.php');
}

/**
 * O loader tambem e usado por algumas superficies administrativas. Um arquivo
 * chamado admin/index.php nao pode ser classificado como a home publica apenas
 * porque basename(SCRIPT_NAME) == "index": isso injetava todo o polish da loja,
 * JS da home e CSS customizado no Admin, aumentando carga e criando colisoes de
 * layout. Detectamos /admin antes de resolver o nome da pagina publica.
 */
function sv_is_admin_request(): bool
{
    foreach (['SCRIPT_NAME', 'PHP_SELF', 'REQUEST_URI'] as $serverKey) {
        $raw = (string)($_SERVER[$serverKey] ?? '');
        if ($raw === '') {
            continue;
        }
        $path = (string)(parse_url($raw, PHP_URL_PATH) ?? $raw);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return true;
        }
    }

    $filename = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return $filename !== '' && preg_match('#/admin(?:/|$)#', $filename) === 1;
}

/**
 * Emite as classes que alteram a geometria da primeira dobra antes do CSS ser
 * aplicado. Antes, um script deferido adicionava essas classes e reorganizava
 * o DOM depois do primeiro paint, causando CLS de ate 0,96 no checkout.
 */
function sv_emit_prepaint_page_state(string $pageName): void
{
    if (!in_array($pageName, ['index', 'carrinho', 'checkout'], true)) {
        return;
    }

    $pageClass = match ($pageName) {
        'index' => 'sv-home-page sv-home-top',
        'carrinho' => 'sv-cart-page',
        'checkout' => 'sv-checkout-page',
        default => '',
    };

    $emptyClass = match ($pageName) {
        'carrinho' => 'sv-cart-empty',
        'checkout' => 'sv-checkout-empty',
        default => '',
    };

    echo "    <script>\n";
    echo "    (function(){\n";
    echo "      var root=document.documentElement;\n";
    echo "      root.className+=(root.className?' ':'')+" . json_encode($pageClass) . ";\n";
    if ($emptyClass !== '') {
        echo "      var empty=false;\n";
        echo "      try{var cart=JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');empty=Array.isArray(cart)&&cart.length===0;}catch(error){empty=false;}\n";
        echo "      root.classList.toggle(" . json_encode($emptyClass) . ",empty);\n";
    }
    echo "    })();\n";
    echo "    </script>\n";
}

function sv_public_asset_attr(string $path): string
{
    return htmlspecialchars(sv_asset_url($path), ENT_QUOTES, 'UTF-8');
}

/**
 * Carrega CSS versionado da aplicacao e CSS customizado do admin para a pagina atual.
 * Inclua isto no <head> de todas as paginas publicas suportadas.
 */
function load_custom_css(): void
{
    $root = dirname(__DIR__);

    // Admin precisa ser uma superficie isolada e leve. O arquivo responsivo e
    // emitido com filemtime para invalidar imediatamente o cache de 30 dias do
    // Apache em celulares que ainda guardam uma versao antiga do CSS.
    if (sv_is_admin_request()) {
        $adminCss = $root . '/css/admin-zoom-responsive.css';
        if (is_file($adminCss) && is_readable($adminCss)) {
            $adminCssVersion = (string)filemtime($adminCss);
            echo "    <link rel=\"stylesheet\" href=\"/css/admin-zoom-responsive.css?v="
                . htmlspecialchars($adminCssVersion, ENT_QUOTES, 'UTF-8') . "\">\n";
        }
        return;
    }

    $pageName = sv_current_page_name();

    if ($pageName === 'produto') {
        require_once __DIR__ . '/product-review-integrity.php';
        sv_enable_product_review_integrity_guard();
    }

    sv_emit_prepaint_page_state($pageName);

    // CSS funcional da home deve viver fora de storage, como parte da release.
    // A antiga home-mobile-layout.js foi removida porque mudava texto e ordem de
    // secoes apos o primeiro paint. O HTML canonico agora e igual em todos os
    // dispositivos; apenas CSS responsivo altera sua apresentacao.
    //
    // Na home (index), esses dois arquivos ja vem dentro do bundle emitido em
    // index.php (ver includes/asset-bundle-manifest.php) — nao reemitir aqui
    // para nao duplicar a carga. Outras paginas nao usam este bloco.
    if ($pageName === 'index') {
        // ja incluido via /css/home-bundle.php
    }

    // Quarta rodada de polimento visual. O estado geometrico correspondente ja
    // foi definido no <html> pelo script sincrono acima, evitando layout shift.
    // O CSS do visual-polish-v4 na home tambem ja vem no bundle de
    // index.php; aqui so falta o <script>, que precisa continuar separado
    // (e comportamento, nao estilo, e roda em carrinho/checkout tambem).
    if (in_array($pageName, ['index', 'carrinho', 'checkout'], true)) {
        if ($pageName !== 'index') {
            echo "    <link rel=\"stylesheet\" href=\"" . sv_public_asset_attr('/css/visual-polish-v4.css') . "\">\n";
        }
        echo "    <script src=\"" . sv_public_asset_attr('/js/visual-polish-v4.js') . "\" defer></script>\n";
    }

    $loadVisualPolishV5 = in_array($pageName, ['index', 'catalogo', 'produto', 'carrinho', 'checkout'], true);
    // CSS opcional criado pelo admin continua sendo lido do storage compartilhado.
    $cssDir = $root . '/storage/css-custom';
    if (is_dir($cssDir)) {
        $cssFiles = [
            $cssDir . '/' . $pageName . '.css',
            $cssDir . '/global.css',
        ];

        foreach ($cssFiles as $cssFile) {
            if (is_file($cssFile) && is_readable($cssFile)) {
                echo "    <style>\n";
                echo "        /* CSS customizado de: " . basename($cssFile) . " */\n";
                echo file_get_contents($cssFile);
                echo "\n    </style>\n";
            }
        }
    }

    // V5 permanece restrita as paginas-alvo originais.
    if ($loadVisualPolishV5) {
        echo "    <link rel=\"stylesheet\" href=\"" . sv_public_asset_attr('/css/visual-polish-v5.css') . "\">\n";
    }

    // V6 contem somente seletores publicos e correcoes fail-safe de imagem/titulo.
    // E emitida sempre que este loader publico e incluido, eliminando dependencia
    // de variaveis de rewrite para rotas amigaveis como /produto/<slug>.
    echo "    <link rel=\"stylesheet\" href=\"" . sv_public_asset_attr('/css/visual-polish-v6.css') . "\">\n";
    $visualPolishHotfixCss = $root . '/css/visual-polish-v6-hotfix.css';
    if (is_file($visualPolishHotfixCss) && is_readable($visualPolishHotfixCss)) {
        $visualPolishHotfixVersion = (string) filemtime($visualPolishHotfixCss);
        echo "    <link rel=\"stylesheet\" href=\"/css/visual-polish-v6-hotfix.css?v="
            . htmlspecialchars($visualPolishHotfixVersion, ENT_QUOTES, 'UTF-8') . "\">\n";
    }

    // Ajustes desta auditoria carregam por ultimo para vencer apenas conflitos
    // visuais medidos, sem depender do CSS editavel do admin.
    $visualAuditCss = $root . '/css/visual-audit-v1.css';
    if (is_file($visualAuditCss) && is_readable($visualAuditCss)) {
        $visualAuditVersion = (string) filemtime($visualAuditCss);
        echo "    <link rel=\"stylesheet\" href=\"/css/visual-audit-v1.css?v="
            . htmlspecialchars($visualAuditVersion, ENT_QUOTES, 'UTF-8') . "\">\n";
    }

    // Regras transversais de acessibilidade do main sao preservadas antes do
    // hotfix de geometria, que deve permanecer como a ultima camada visual.
    $accessibilityCss = $root . '/css/accessibility-hardening-v1.css';
    if (is_file($accessibilityCss) && is_readable($accessibilityCss)) {
        $accessibilityVersion = (string) filemtime($accessibilityCss);
        echo "    <link rel=\"stylesheet\" href=\"/css/accessibility-hardening-v1.css?v="
            . htmlspecialchars($accessibilityVersion, ENT_QUOTES, 'UTF-8') . "\">\n";
    }

    $clsStabilityCss = $root . '/css/cls-stability-v1.css';
    if (is_file($clsStabilityCss) && is_readable($clsStabilityCss)) {
        $clsStabilityVersion = (string) filemtime($clsStabilityCss);
        echo "    <link rel=\"stylesheet\" href=\"/css/cls-stability-v1.css?v="
            . htmlspecialchars($clsStabilityVersion, ENT_QUOTES, 'UTF-8') . "\">\n";
    }
}

load_custom_css();
?>
