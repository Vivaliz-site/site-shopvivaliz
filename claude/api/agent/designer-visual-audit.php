<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

function svd_root(): string { return dirname(__DIR__, 2); }
function svd_full(string $path): string { return svd_root() . '/' . ltrim($path, '/'); }
function svd_path(string $path): array
{
    $full = svd_full($path);
    return array(
        'path' => $path,
        'exists' => file_exists($full),
        'type' => is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'missing'),
        'readable' => is_readable($full),
        'mtime' => file_exists($full) ? date('c', (int)filemtime($full)) : null,
        'size' => is_file($full) ? filesize($full) : null,
    );
}

function svd_scan_files(string $dir, int $limit = 400): array
{
    $base = svd_full($dir);
    if (!is_dir($base)) return array();
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = ltrim(str_replace(svd_root(), '', $file->getPathname()), '/');
        if (preg_match('/\.(png|jpe?g|webp|gif|svg|html|css|php)$/i', $rel)) {
            $out[] = array('path' => $rel, 'size' => $file->getSize(), 'mtime' => date('c', $file->getMTime()));
        }
        if (count($out) >= $limit) break;
    }
    return $out;
}

function svd_suspect_mockups(array $files): array
{
    $suspects = array();
    foreach ($files as $f) {
        $p = strtolower($f['path']);
        if (preg_match('/(mock|mockup|placeholder|demo|sample|teste|temp|provisorio|provisoria|banner[-_ ]?padrao|categoria[-_ ]?padrao|lorem|dummy|default)/i', $p)) {
            $suspects[] = $f;
        }
    }
    return $suspects;
}

function svd_required_assets(): array
{
    $variants = array(
        'desktop' => array(
            'home_banner' => '1920x620',
            'category_cover' => '1600x480',
            'product_hero' => '1600x900',
            'google_ads_banner' => '1200x628'
        ),
        'smartphone' => array(
            'home_banner' => '1080x1350',
            'category_cover' => '1080x1080',
            'product_hero' => '1080x1350',
            'story_reels' => '1080x1920'
        )
    );
    $categories = array('ferramentas', 'casa-construcao', 'pet', 'jardim', 'eletrica', 'hidraulica', 'automotivo', 'utilidades');
    $items = array();
    foreach ($variants as $variant => $types) {
        foreach ($types as $type => $size) {
            if ($type === 'category_cover') {
                foreach ($categories as $cat) {
                    $items[] = array('variant' => $variant, 'type' => $type, 'category' => $cat, 'size' => $size, 'status' => 'required');
                }
            } else {
                $items[] = array('variant' => $variant, 'type' => $type, 'category' => null, 'size' => $size, 'status' => 'required');
            }
        }
    }
    return $items;
}

function svd_asset_exists(array $item): bool
{
    $variant = $item['variant'];
    $type = $item['type'];
    $cat = $item['category'];
    $candidates = array();
    if ($cat) {
        $candidates[] = "assets/images/categories/$variant/$cat.webp";
        $candidates[] = "assets/images/categories/$variant/$cat.jpg";
        $candidates[] = "assets/img/categories/$variant/$cat.webp";
        $candidates[] = "uploads/categories/$variant/$cat.webp";
    } else {
        $candidates[] = "assets/images/banners/$variant/$type.webp";
        $candidates[] = "assets/images/banners/$variant/$type.jpg";
        $candidates[] = "assets/img/banners/$variant/$type.webp";
        $candidates[] = "uploads/banners/$variant/$type.webp";
    }
    foreach ($candidates as $p) if (is_file(svd_full($p))) return true;
    return false;
}

$apply = isset($_GET['apply']) && (string)$_GET['apply'] === '1';
$scanDirs = array('assets', 'uploads', 'public', 'img', 'images', 'admin');
$files = array();
foreach ($scanDirs as $dir) $files = array_merge($files, svd_scan_files($dir));

$required = svd_required_assets();
$queue = array();
foreach ($required as $item) {
    $exists = svd_asset_exists($item);
    $item['exists'] = $exists;
    $item['priority'] = $exists ? 'ok' : ($item['type'] === 'home_banner' ? 'alta' : 'media');
    $item['action'] = $exists ? 'manter_e_auditar_qualidade' : 'criar_arte_' . $item['variant'];
    if (!$exists) $queue[] = $item;
}

$out = array(
    'ok' => true,
    'agent' => 'designer_visual_audit',
    'version' => '1.0.0',
    'generated_at' => date('c'),
    'safe_mode' => true,
    'apply_requested' => $apply,
    'desktop_smartphone_required' => true,
    'summary' => array(
        'scanned_files' => count($files),
        'suspect_mockups' => count(svd_suspect_mockups($files)),
        'required_assets' => count($required),
        'missing_assets' => count($queue),
        'admin_approval_required' => true
    ),
    'device_variants' => array(
        'desktop' => array('banner_home' => '1920x620', 'category_cover' => '1600x480', 'product_hero' => '1600x900'),
        'smartphone' => array('banner_home' => '1080x1350', 'category_cover' => '1080x1080', 'product_hero' => '1080x1350')
    ),
    'suspect_mockups' => array_slice(svd_suspect_mockups($files), 0, 80),
    'creative_queue' => array_slice($queue, 0, 120),
    'rules' => array(
        'Criar sempre saidas separadas para desktop e smartphone.',
        'Substituir mockups/provisorios por artes finais aprovadas.',
        'Nao aplicar arte nova sem aprovacao do admin.',
        'Banners e capas nao devem depender de texto pequeno ilegivel no celular.',
        'Produto deve manter proporcao e identidade visual real.'
    ),
    'next_steps' => array(
        'Criar painel admin para aprovar/rejeitar fila criativa.',
        'Gerar prompts de imagem por categoria e dispositivo.',
        'Publicar assets finais em pastas padronizadas.',
        'Adicionar teste visual desktop/smartphone no pos-deploy.'
    )
);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
