<?php
/**
 * generate-covers.php — Gerador de capas para TikTok e Mercado Livre
 *
 * Issues #71 e #72 — gera capas usando a primeira imagem Olist de cada produto.
 *
 * Extensões PHP necessárias:
 *   - GD (php-gd): necessária para manipulação de imagens
 *   - Opcional: php-imagick (Imagick) como fallback/alternativa a GD
 *
 * Uso:
 *   php scripts/generate-covers.php [--limit=N] [--sku=SKU] [--force]
 *
 * Saída:
 *   storage/covers/{sku}/tiktok.jpg  — 1080x1920 (9:16) para TikTok
 *   storage/covers/{sku}/ml.jpg      — 1200x1200 (1:1) para Mercado Livre
 */

declare(strict_types=1);

// ── Verificação de extensões ────────────────────────────────────────────────

if (!extension_loaded('gd')) {
    fwrite(STDERR, "[ERRO] Extensão GD não encontrada. Instale php-gd e tente novamente.\n");
    fwrite(STDERR, "       Ubuntu/Debian: sudo apt install php-gd\n");
    fwrite(STDERR, "       CentOS/RHEL:  sudo yum install php-gd\n");
    exit(1);
}

$USE_IMAGICK = extension_loaded('imagick');

// ── Parâmetros de linha de comando ──────────────────────────────────────────

$opts = getopt('', ['limit:', 'sku:', 'force', 'dry-run']);
$LIMIT    = isset($opts['limit'])   ? (int)$opts['limit']  : 0;
$FILTER_SKU = $opts['sku']          ?? null;
$FORCE    = isset($opts['force']);
$DRY_RUN  = isset($opts['dry-run']);

// ── Caminhos ────────────────────────────────────────────────────────────────

$ROOT         = dirname(__DIR__);
$CATALOG_FILE = $ROOT . '/api/catalog/fallback-products.json';
$COVERS_DIR   = $ROOT . '/storage/covers';

if (!is_dir($COVERS_DIR)) {
    mkdir($COVERS_DIR, 0755, true);
}

// ── Configurações de design ──────────────────────────────────────────────────

define('BRAND_NAME',    'Vivaliz');
define('BRAND_COLOR',   [0x00, 0x7B, 0xFF]); // azul
define('ACCENT_COLOR',  [0xFF, 0xC1, 0x07]); // âmbar

// TikTok: 9:16
define('TIKTOK_W', 1080);
define('TIKTOK_H', 1920);

// ML: 1:1
define('ML_W', 1200);
define('ML_H', 1200);

// ── Carrega catálogo ─────────────────────────────────────────────────────────

if (!is_file($CATALOG_FILE)) {
    fwrite(STDERR, "[ERRO] Catálogo não encontrado: $CATALOG_FILE\n");
    exit(1);
}

$products = json_decode(file_get_contents($CATALOG_FILE), true);
if (!is_array($products)) {
    fwrite(STDERR, "[ERRO] JSON inválido em $CATALOG_FILE\n");
    exit(1);
}

// Filtra por SKU se solicitado
if ($FILTER_SKU !== null) {
    $products = array_filter($products, fn($p) => ($p['sku'] ?? '') === $FILTER_SKU);
    $products = array_values($products);
}

// Filtra produtos sem imagem
$products = array_filter($products, fn($p) => !empty($p['image_url']) || !empty($p['images'][0]));
$products = array_values($products);

if ($LIMIT > 0) {
    $products = array_slice($products, 0, $LIMIT);
}

$total   = count($products);
$success = 0;
$skipped = 0;
$errors  = 0;

echo "ShopVivaliz — Gerador de Capas\n";
echo str_repeat('─', 50) . "\n";
echo "Produtos a processar : $total\n";
echo "GD versão            : " . gd_info()['GD Version'] . "\n";
echo "Imagick disponível   : " . ($USE_IMAGICK ? 'sim' : 'não') . "\n";
echo "Modo dry-run         : " . ($DRY_RUN ? 'sim' : 'não') . "\n";
echo str_repeat('─', 50) . "\n\n";

// ── Loop principal ───────────────────────────────────────────────────────────

foreach ($products as $i => $product) {
    $sku      = sanitize_sku($product['sku'] ?? $product['id'] ?? "prod_$i");
    $name     = $product['name'] ?? 'Produto';
    $price    = (float)($product['price'] ?? 0);
    $imageUrl = $product['images'][0] ?? $product['image_url'] ?? null;

    $skuDir   = $COVERS_DIR . '/' . $sku;
    $tiktokPath = $skuDir . '/tiktok.jpg';
    $mlPath     = $skuDir . '/ml.jpg';

    $num = $i + 1;
    echo "[$num/$total] $sku — $name\n";

    // Verifica se já existe e não foi pedido --force
    if (!$FORCE && is_file($tiktokPath) && is_file($mlPath)) {
        echo "  → já existe, pulando (use --force para regenerar)\n";
        $skipped++;
        continue;
    }

    // Baixa imagem de origem
    $srcData = download_image($imageUrl);
    if ($srcData === null) {
        echo "  → [ERRO] falha ao baixar imagem: $imageUrl\n";
        $errors++;
        continue;
    }

    $srcImg = imagecreatefromstring($srcData);
    if ($srcImg === false) {
        echo "  → [ERRO] não foi possível decodificar imagem\n";
        $errors++;
        continue;
    }

    if ($DRY_RUN) {
        imagedestroy($srcImg);
        echo "  → [DRY-RUN] seria gerado: tiktok.jpg + ml.jpg\n";
        $success++;
        continue;
    }

    if (!is_dir($skuDir)) {
        mkdir($skuDir, 0755, true);
    }

    // Gera capa TikTok
    $ok1 = generate_tiktok($srcImg, $name, $price, $tiktokPath);
    // Gera capa ML
    $ok2 = generate_ml($srcImg, $name, $price, $mlPath);

    imagedestroy($srcImg);

    if ($ok1 && $ok2) {
        echo "  → OK tiktok.jpg + ml.jpg\n";
        $success++;
    } else {
        echo "  → [ERRO] falha na geração (" . (!$ok1 ? 'tiktok ' : '') . (!$ok2 ? 'ml' : '') . ")\n";
        $errors++;
    }
}

echo "\n" . str_repeat('─', 50) . "\n";
echo "Concluído: $success gerados, $skipped pulados, $errors erros\n";
echo "Capas em: $COVERS_DIR\n";

exit($errors > 0 ? 1 : 0);

// ════════════════════════════════════════════════════════════════════════════
// Funções auxiliares
// ════════════════════════════════════════════════════════════════════════════

/**
 * Normaliza SKU para uso como nome de diretório.
 */
function sanitize_sku(string $sku): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $sku);
}

/**
 * Baixa imagem via HTTP com timeout e user-agent, retorna bytes ou null.
 */
function download_image(string $url): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'     => 15,
            'method'      => 'GET',
            'user_agent'  => 'ShopVivaliz/1.0 CoverGenerator',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    return ($data !== false && strlen($data) > 100) ? $data : null;
}

/**
 * Aloca cor GD a partir de array RGB.
 */
function gd_color(\GdImage $img, array $rgb): int
{
    return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
}

/**
 * Desenha texto com quebra automática de linha e retorna a altura ocupada (px).
 */
function draw_wrapped_text(
    \GdImage $canvas,
    string   $text,
    int      $color,
    int      $fontSize,
    int      $x,
    int      $y,
    int      $maxWidth,
    int      $lineHeight
): int {
    $words = explode(' ', $text);
    $line  = '';
    $lineY = $y;
    $font  = 3; // fonte built-in GD (não requer arquivo TTF)

    // Estimativa de largura por caractere em pixels para fonte built-in
    $charW = imagefontwidth($font);

    foreach ($words as $word) {
        $test = $line === '' ? $word : "$line $word";
        if ($charW * strlen($test) > $maxWidth && $line !== '') {
            imagestring($canvas, $font, $x, $lineY, $line, $color);
            $line   = $word;
            $lineY += $lineHeight;
        } else {
            $line = $test;
        }
    }
    if ($line !== '') {
        imagestring($canvas, $font, $x, $lineY, $line, $color);
        $lineY += $lineHeight;
    }

    return $lineY - $y;
}

/**
 * Redimensiona imagem origem para caber em uma área, mantendo proporção (cover).
 * Retorna nova GdImage.
 */
function resize_cover(\GdImage $src, int $targetW, int $targetH): \GdImage
{
    $sw = imagesx($src);
    $sh = imagesy($src);

    $scaleW = $targetW / $sw;
    $scaleH = $targetH / $sh;
    $scale  = max($scaleW, $scaleH);

    $newW = (int)($sw * $scale);
    $newH = (int)($sh * $scale);

    $tmp = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newW, $newH, $sw, $sh);

    // Crop centralizado
    $out = imagecreatetruecolor($targetW, $targetH);
    $srcX = (int)(($newW - $targetW) / 2);
    $srcY = (int)(($newH - $targetH) / 2);
    imagecopy($out, $tmp, 0, 0, $srcX, $srcY, $targetW, $targetH);
    imagedestroy($tmp);

    return $out;
}

/**
 * Redimensiona imagem para caber dentro da área (fit, sem corte).
 * Retorna nova GdImage com fundo branco.
 */
function resize_fit(\GdImage $src, int $boxW, int $boxH): \GdImage
{
    $sw = imagesx($src);
    $sh = imagesy($src);

    $scale = min($boxW / $sw, $boxH / $sh);
    $newW  = (int)($sw * $scale);
    $newH  = (int)($sh * $scale);

    $out = imagecreatetruecolor($boxW, $boxH);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);

    $dstX = (int)(($boxW - $newW) / 2);
    $dstY = (int)(($boxH - $newH) / 2);
    imagecopyresampled($out, $src, $dstX, $dstY, 0, 0, $newW, $newH, $sw, $sh);

    return $out;
}

// ────────────────────────────────────────────────────────────────────────────
// TIKTOK: 1080x1920 — fundo escuro + gradiente + overlay de texto
// ────────────────────────────────────────────────────────────────────────────

function generate_tiktok(\GdImage $src, string $name, float $price, string $outPath): bool
{
    $W = TIKTOK_W;  // 1080
    $H = TIKTOK_H;  // 1920

    $canvas = imagecreatetruecolor($W, $H);

    // Fundo preto
    $black = imagecolorallocate($canvas, 10, 10, 10);
    imagefill($canvas, 0, 0, $black);

    // Produto redimensionado para preencher a parte central (70% da altura)
    $imgAreaH = (int)($H * 0.70);
    $imgAreaY = (int)($H * 0.08);
    $product  = resize_fit($src, $W, $imgAreaH);
    $imgW     = imagesx($product);
    $imgH     = imagesy($product);
    $imgX     = (int)(($W - $imgW) / 2);
    imagecopy($canvas, $product, $imgX, $imgAreaY, 0, 0, $imgW, $imgH);
    imagedestroy($product);

    // Gradiente no rodapé (escurece do centro para baixo)
    $gradStartY = (int)($H * 0.65);
    for ($row = $gradStartY; $row < $H; $row++) {
        $alpha = (int)(127 * (1 - ($row - $gradStartY) / ($H - $gradStartY)));
        $c = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
        imageline($canvas, 0, $row, $W - 1, $row, $c);
    }

    // Faixa azul no topo — MARCA
    $blue   = gd_color($canvas, BRAND_COLOR);
    $white  = imagecolorallocate($canvas, 255, 255, 255);
    $yellow = gd_color($canvas, ACCENT_COLOR);
    $gray   = imagecolorallocate($canvas, 200, 200, 200);

    $topBarH = 90;
    imagefilledrectangle($canvas, 0, 0, $W, $topBarH, $blue);

    // Nome da marca centralizado na faixa
    $brandFont = 5;
    $brandText = BRAND_NAME;
    $brandW    = imagefontwidth($brandFont) * strlen($brandText);
    $brandX    = (int)(($W - $brandW) / 2);
    $brandY    = (int)(($topBarH - imagefontheight($brandFont)) / 2);
    imagestring($canvas, $brandFont, $brandX, $brandY, $brandText, $white);

    // Nome do produto (área do rodapé)
    $padX     = 60;
    $nameY    = (int)($H * 0.78);
    $nameFont = 5;
    $nameColor = $white;
    $lineH    = imagefontheight($nameFont) + 8;
    $maxW     = $W - $padX * 2;

    // Trunca nome se muito longo para caber em 3 linhas
    $truncatedName = mb_strlen($name) > 80 ? mb_substr($name, 0, 77) . '...' : $name;
    $nameOccupied  = draw_wrapped_text($canvas, $truncatedName, $nameColor, $nameFont, $padX, $nameY, $maxW, $lineH);

    // Preço
    $priceY = $nameY + $nameOccupied + 20;
    if ($price > 0) {
        $priceText = 'R$ ' . number_format($price, 2, ',', '.');
        imagestring($canvas, 5, $padX, $priceY, $priceText, $yellow);
    }

    // Linha separadora acima do rodapé
    $footerY = $H - 80;
    imageline($canvas, $padX, $footerY - 10, $W - $padX, $footerY - 10, $gray);

    // Rodapé: logo textual + CTA
    $ctaText = 'vivaliz.com.br';
    imagestring($canvas, 4, $padX, $footerY + 10, $ctaText, $gray);

    $buyText = '>>> Ver oferta <<<';
    $buyW    = imagefontwidth(4) * strlen($buyText);
    imagestring($canvas, 4, $W - $padX - $buyW, $footerY + 10, $buyText, $yellow);

    // Salva
    imagejpeg($canvas, $outPath, 90);
    imagedestroy($canvas);

    return is_file($outPath) && filesize($outPath) > 0;
}

// ────────────────────────────────────────────────────────────────────────────
// ML: 1200x1200 — fundo branco, borda sutil, logo discreto
// ────────────────────────────────────────────────────────────────────────────

function generate_ml(\GdImage $src, string $name, float $price, string $outPath): bool
{
    $W = ML_W;  // 1200
    $H = ML_H;  // 1200

    $canvas = imagecreatetruecolor($W, $H);

    // Fundo branco
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    // Borda cinza muito suave
    $borderColor = imagecolorallocate($canvas, 230, 230, 230);
    $borderW     = 4;
    imagerectangle($canvas, 0, 0, $W - 1, $H - 1, $borderColor);
    imagerectangle($canvas, 1, 1, $W - 2, $H - 2, $borderColor);
    imagerectangle($canvas, 2, 2, $W - 3, $H - 3, $borderColor);
    imagerectangle($canvas, 3, 3, $W - 4, $H - 4, $borderColor);

    // Produto centralizado com padding de 60px em cada lado
    $pad      = 60;
    $imgAreaW = $W - $pad * 2;
    $imgAreaH = (int)($H * 0.78);  // deixa espaço para rodapé
    $product  = resize_fit($src, $imgAreaW, $imgAreaH);
    $imgW     = imagesx($product);
    $imgH     = imagesy($product);
    $imgX     = (int)(($W - $imgW) / 2);
    $imgY     = (int)(($imgAreaH - $imgH) / 2) + $pad;
    imagecopy($canvas, $product, $imgX, $imgY, 0, 0, $imgW, $imgH);
    imagedestroy($product);

    // Linha divisória
    $gray = imagecolorallocate($canvas, 220, 220, 220);
    $lineY = (int)($H * 0.82);
    imageline($canvas, $pad, $lineY, $W - $pad, $lineY, $gray);

    // Nome do produto (fonte pequena, discreta)
    $blue      = gd_color($canvas, BRAND_COLOR);
    $darkGray  = imagecolorallocate($canvas, 60, 60, 60);
    $nameY     = $lineY + 14;
    $nameFont  = 4;
    $lineH     = imagefontheight($nameFont) + 6;
    $maxW      = $W - $pad * 2;
    $shortName = mb_strlen($name) > 60 ? mb_substr($name, 0, 57) . '...' : $name;
    draw_wrapped_text($canvas, $shortName, $darkGray, $nameFont, $pad, $nameY, $maxW, $lineH);

    // Logo Vivaliz discreto no canto inferior direito
    $logoFont = 3;
    $logoText = BRAND_NAME;
    $logoW    = imagefontwidth($logoFont) * strlen($logoText);
    $logoX    = $W - $pad - $logoW;
    $logoY    = $H - $pad;
    imagestring($canvas, $logoFont, $logoX, $logoY, $logoText, $blue);

    // Salva
    imagejpeg($canvas, $outPath, 92);
    imagedestroy($canvas);

    return is_file($outPath) && filesize($outPath) > 0;
}
