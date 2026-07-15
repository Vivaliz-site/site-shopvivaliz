<?php

declare(strict_types=1);

header_remove('X-Powered-By');
require_once dirname(__DIR__, 3) . '/includes/admin-guard.php';
require_once dirname(__DIR__, 3) . '/includes/csrf.php';

function svic_root(): string { return dirname(__DIR__, 2); }

function svic_json(array $out, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svic_pdo(): ?PDO
{
    foreach (array('sv_pdo','sv_db','db','get_pdo') as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }
    foreach (array(svic_root().'/config.php', svic_root().'/includes/config.php', svic_root().'/app/config.php', svic_root().'/bootstrap.php') as $file) {
        if (is_file($file)) {
            require_once $file;
            foreach (array('sv_pdo','sv_db','db','get_pdo') as $fn) {
                if (function_exists($fn)) {
                    $db = $fn();
                    if ($db instanceof PDO) return $db;
                }
            }
        }
    }
    return null;
}

function svic_get_zip_xml(ZipArchive $zip, string $name): ?SimpleXMLElement
{
    $xml = $zip->getFromName($name);
    if (!is_string($xml) || $xml === '') return null;
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $sx instanceof SimpleXMLElement ? $sx : null;
}

function svic_col_index(string $cell): int
{
    if (!preg_match('/^([A-Z]+)/i', $cell, $m)) return 0;
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

function svic_cell_value(SimpleXMLElement $cell, array $shared): string
{
    $type = (string)($cell['t'] ?? '');
    $v = isset($cell->v) ? (string)$cell->v : '';
    if ($type === 's') return $shared[(int)$v] ?? '';
    if ($type === 'inlineStr') return trim((string)($cell->is->t ?? ''));
    return trim($v);
}

function svic_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive indisponivel no servidor.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Nao foi possivel abrir XLSX.');

    $shared = array();
    $sharedXml = svic_get_zip_xml($zip, 'xl/sharedStrings.xml');
    if ($sharedXml) {
        foreach ($sharedXml->si as $si) {
            if (isset($si->t)) $shared[] = (string)$si->t;
            else {
                $txt = '';
                foreach ($si->r as $r) $txt .= (string)$r->t;
                $shared[] = $txt;
            }
        }
    }

    $sheetName = 'xl/worksheets/sheet1.xml';
    $sheet = svic_get_zip_xml($zip, $sheetName);
    if (!$sheet) throw new RuntimeException('Planilha principal nao encontrada.');

    $rows = array();
    foreach ($sheet->sheetData->row as $row) {
        $arr = array();
        foreach ($row->c as $cell) {
            $idx = svic_col_index((string)$cell['r']);
            $arr[$idx] = svic_cell_value($cell, $shared);
        }
        if ($arr) {
            ksort($arr);
            $rows[] = $arr;
        }
    }
    $zip->close();
    return $rows;
}

function svic_norm_header(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = array('á','à','â','ã','ä','é','ê','í','ó','ô','õ','ú','ü','ç');
    $to = array('a','a','a','a','a','e','e','i','o','o','o','u','u','c');
    $s = str_replace($from, $to, $s);
    return preg_replace('/[^a-z0-9]+/', '_', $s) ?: '';
}

function svic_urls_from_text(string $text): array
{
    preg_match_all('~https?://[^\s,;\|\"]+\.(?:jpg|jpeg|png|webp|gif)(?:\?[^\s,;\|\"]*)?~i', $text, $m);
    return array_values(array_unique($m[0] ?? array()));
}

function svic_build_sheet_items(array $rows): array
{
    if (!$rows) return array('headers'=>array(), 'items'=>array(), 'warnings'=>array('empty_sheet'));
    $headers = array();
    $headerRow = $rows[0];
    foreach ($headerRow as $i => $h) $headers[$i] = svic_norm_header((string)$h);

    $skuCols = array(); $idCols = array(); $nameCols = array(); $imgCols = array();
    foreach ($headers as $i => $h) {
        if (preg_match('/(^|_)(sku|codigo|referencia|ref)(_|$)/', $h)) $skuCols[] = $i;
        if (preg_match('/(^|_)(id|idproduto|id_produto|codigo_produto|produto_id)(_|$)/', $h)) $idCols[] = $i;
        if (preg_match('/(^|_)(nome|descricao|produto|titulo)(_|$)/', $h)) $nameCols[] = $i;
        if (preg_match('/(imagem|imagens|foto|fotos|anexo|url|link)/', $h)) $imgCols[] = $i;
    }

    $items = array();
    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $sku = '';
        foreach ($skuCols as $c) { if (!empty($row[$c])) { $sku = trim((string)$row[$c]); break; } }
        $pid = '';
        foreach ($idCols as $c) { if (!empty($row[$c])) { $pid = preg_replace('/\D+/', '', (string)$row[$c]); break; } }
        $name = '';
        foreach ($nameCols as $c) { if (!empty($row[$c])) { $name = trim((string)$row[$c]); break; } }
        $urls = array();
        $scanCols = $imgCols ?: array_keys($row);
        foreach ($scanCols as $c) {
            if (!isset($row[$c])) continue;
            $urls = array_merge($urls, svic_urls_from_text((string)$row[$c]));
        }
        $urls = array_values(array_unique($urls));
        if ($sku !== '' || $pid !== '' || $urls) {
            $key = $sku !== '' ? 'sku:'.mb_strtoupper($sku, 'UTF-8') : 'id:'.$pid;
            if (!isset($items[$key])) $items[$key] = array('sku'=>$sku, 'olist_product_id'=>$pid, 'name'=>$name, 'urls'=>array(), 'rows'=>array());
            $items[$key]['urls'] = array_values(array_unique(array_merge($items[$key]['urls'], $urls)));
            $items[$key]['rows'][] = $r + 1;
            if (!$items[$key]['name'] && $name) $items[$key]['name'] = $name;
        }
    }
    return array('headers'=>$headers, 'items'=>array_values($items), 'warnings'=>array());
}

function svic_norm_url(string $url): string
{
    $url = trim($url);
    $url = preg_replace('/\?.*$/', '', $url);
    return mb_strtolower($url, 'UTF-8');
}

function svic_db_images(PDO $pdo, string $sku, string $olistId): array
{
    $where = array(); $params = array();
    if ($sku !== '') { $where[] = 'UPPER(sku) = UPPER(?)'; $params[] = $sku; }
    if ($olistId !== '') { $where[] = 'olist_product_id = ?'; $params[] = $olistId; }
    if (!$where) return array('product'=>null, 'images'=>array());

    $product = null;
    try {
        $sqlp = 'SELECT id, sku, olist_id, olist_product_id, idProduto, name, primary_image_url, images_count FROM olist_products WHERE ' . str_replace('olist_product_id', 'olist_product_id', implode(' OR ', $where)) . ' LIMIT 1';
        $stp = $pdo->prepare($sqlp);
        $stp->execute($params);
        $product = $stp->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $product = array('error'=>$e->getMessage()); }

    try {
        $sql = 'SELECT id, product_local_id, olist_product_id, sku, image_url, position, is_primary, source, status FROM olist_product_images WHERE ' . implode(' OR ', $where) . ' ORDER BY position, id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $imgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) { $imgs = array(array('error'=>$e->getMessage())); }
    return array('product'=>$product, 'images'=>$imgs);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Comparar imagens Olist x ShopVivaliz</title>';
    echo '<h1>Agente comparador de imagens Olist</h1>';
    echo '<p>Use somente a planilha exportada hoje, pois as imagens da Olist mudam diariamente.</p>';
    echo '<form method="post" enctype="multipart/form-data">' . sv_csrf_input('olist-image-compare') . '<input type="file" name="sheet" accept=".xlsx" required> <button>Comparar</button></form>';
    echo '<p>Modo seguro: gera conferencia JSON e nao altera imagens automaticamente.</p>';
    exit;
}

if (!sv_csrf_valid('olist-image-compare', $_POST['csrf_token'] ?? null)) {
    svic_json(['ok'=>false,'error'=>'csrf_invalid'], 419);
}

if (empty($_FILES['sheet']['tmp_name'])) svic_json(array('ok'=>false,'error'=>'missing_sheet'), 400);

try {
    $rows = svic_parse_xlsx($_FILES['sheet']['tmp_name']);
    $parsed = svic_build_sheet_items($rows);
    $pdo = svic_pdo();
    if (!$pdo) svic_json(array('ok'=>false,'error'=>'database_unavailable','parsed_items'=>count($parsed['items'])), 500);

    $summary = array('sheet_items'=>count($parsed['items']), 'sheet_urls'=>0, 'matched_products'=>0, 'db_images'=>0, 'exact_url_matches'=>0, 'missing_in_db'=>0, 'extra_in_db'=>0, 'without_sheet_images'=>0, 'without_db_images'=>0);
    $diffs = array();
    foreach ($parsed['items'] as $item) {
        $summary['sheet_urls'] += count($item['urls']);
        if (!count($item['urls'])) $summary['without_sheet_images']++;
        $db = svic_db_images($pdo, (string)$item['sku'], (string)$item['olist_product_id']);
        if ($db['product']) $summary['matched_products']++;
        $dbUrls = array();
        foreach ($db['images'] as $img) if (!empty($img['image_url'])) $dbUrls[] = (string)$img['image_url'];
        $summary['db_images'] += count($dbUrls);
        if (!count($dbUrls)) $summary['without_db_images']++;

        $sheetNorm = array_map('svic_norm_url', $item['urls']);
        $dbNorm = array_map('svic_norm_url', $dbUrls);
        $missing = array();
        foreach ($item['urls'] as $u) if (!in_array(svic_norm_url($u), $dbNorm, true)) $missing[] = $u;
        $extra = array();
        foreach ($dbUrls as $u) if (!in_array(svic_norm_url($u), $sheetNorm, true)) $extra[] = $u;
        $matches = count($item['urls']) - count($missing);
        $summary['exact_url_matches'] += max(0, $matches);
        $summary['missing_in_db'] += count($missing);
        $summary['extra_in_db'] += count($extra);
        if ($missing || $extra || !$db['product']) {
            $diffs[] = array('sku'=>$item['sku'], 'olist_product_id'=>$item['olist_product_id'], 'name'=>$item['name'], 'rows'=>$item['rows'], 'product_found'=>(bool)$db['product'], 'sheet_images'=>count($item['urls']), 'db_images'=>count($dbUrls), 'missing_in_shopvivaliz'=>$missing, 'extra_in_shopvivaliz'=>$extra, 'product'=>$db['product']);
        }
    }

    svic_json(array('ok'=>true, 'agent'=>'olist_image_sheet_compare', 'safe_mode'=>true, 'reference'=>'xlsx_uploaded_for_today_only', 'generated_at'=>date('c'), 'summary'=>$summary, 'headers_detected'=>$parsed['headers'], 'diff_count'=>count($diffs), 'diffs'=>array_slice($diffs, 0, 200), 'note'=>'Nao aplica alteracoes. Use o resultado para aprovar importacao/reconciliacao do dia.'));
} catch (Throwable $e) {
    svic_json(array('ok'=>false, 'agent'=>'olist_image_sheet_compare', 'error'=>$e->getMessage()), 500);
}
