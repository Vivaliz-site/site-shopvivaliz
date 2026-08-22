<?php
declare(strict_types=1);
$base = getenv('SV_A11Y_BASE_URL') ?: 'https://shopvivaliz.com.br';
$paths = ['/', '/catalogo/', '/blog/', '/sobre/', '/carrinho', '/minha-conta/pedidos.php'];
$errors = [];
foreach ($paths as $path) {
    $url = rtrim($base, '/') . $path;
    $html = @file_get_contents($url);
    if (!is_string($html) || $html === '') {
        $errors[] = $path . ':fetch_failed';
        continue;
    }
    if (!preg_match('/<main\b/i', $html)) $errors[] = $path . ':missing_main_landmark';
    if (!preg_match('/<h1\b/i', $html)) $errors[] = $path . ':missing_h1';
    $scanHtml = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $scanHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $scanHtml) ?? $scanHtml;
    $scanHtml = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $scanHtml) ?? $scanHtml;
    if (preg_match_all('/<img\b[^>]*>/i', $scanHtml, $matches)) {
        foreach ($matches[0] as $img) {
            if (!preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $img)) {
                $errors[] = $path . ':image_missing_alt:' . substr(preg_replace('/\s+/', ' ', $img), 0, 120);
                break;
            }
        }
    }
    if (preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $scanHtml, $buttons, PREG_SET_ORDER)) {
        foreach ($buttons as $button) {
            $attrs = $button[1];
            $text = trim(strip_tags($button[2]));
            if ($text === '' && !preg_match('/\b(aria-label|title)\s*=/i', $attrs)) {
                $errors[] = $path . ':button_without_accessible_name';
                break;
            }
        }
    }
    if (preg_match_all('/<input\b[^>]*>/i', $scanHtml, $inputs)) {
        foreach ($inputs[0] as $input) {
            if (preg_match('/type\s*=\s*["\'](?:hidden|submit|button|checkbox|radio)["\']/i', $input)) continue;
            $id = '';
            if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $input, $m)) $id = $m[1];
            $hasLabel = $id !== '' && preg_match('/<label\b[^>]*for\s*=\s*["\']' . preg_quote($id, '/') . '["\']/i', $scanHtml);
            $hasAria = preg_match('/\b(aria-label|aria-labelledby|placeholder)\s*=/i', $input);
            if (!$hasLabel && !$hasAria) {
                $errors[] = $path . ':input_without_label:' . substr(preg_replace('/\s+/', ' ', $input), 0, 120);
                break;
            }
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, "PUBLIC_A11Y_SMOKE_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo 'PUBLIC_A11Y_SMOKE_OK pages=' . count($paths) . PHP_EOL;
