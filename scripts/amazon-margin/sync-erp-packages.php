<?php
declare(strict_types=1);

/**
 * Synchronize authoritative Olist ERP package dimensions (cm) and gross weight
 * (kg) to Amazon Seller Central (SP-API Listings Items PATCH) and export the
 * Seller Flex (FBA Onsite) master-data table.
 *
 * Modes (exactly one effect per run):
 *   --preview (default)  VALIDATION_PREVIEW only; nothing is written to Amazon.
 *   --apply              Live PATCH, followed by a GET readback per SKU.
 *   --skus=SKU1,SKU2     Restrict to specific Seller SKUs.
 *   --export-sellerflex=PATH  Write the CSV used to verify Seller Flex WMS data.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$shared = (string)(getenv('SHOPVIVALIZ_SHARED_DIR') ?: '/home/ubuntu/shopvivaliz-deploy/shared');
$envFile = rtrim($shared, '/') . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) continue;
        if (getenv($name) !== false) continue;
        putenv($name . '=' . trim(trim($value), chr(34) . chr(39)));
    }
}

require_once $root . '/includes/pdo-database.php';
require_once $root . '/includes/marketplace/AmazonPublisher.php';
require_once $root . '/includes/amazon-margin/AuditStore.php';
require_once $root . '/includes/amazon-margin/CurrentPriceMargin.php';
require_once $root . '/includes/amazon-margin/TargetMarginPublication.php';

/**
 * Manufacturer package overrides, used only when the Olist ERP rounding puts a
 * SKU slightly outside the program limits. Each entry must cite its source.
 *
 * @return array<string,array{length_cm:float,width_cm:float,height_cm:float,weight_kg:float,source:string}>
 */
function sv_amazon_manufacturer_package_overrides(): array
{
    return [
        'JFUBCQ10' => [
            'length_cm' => 100.0,
            'width_cm' => 40.0,
            'height_cm' => 40.0,
            'weight_kg' => 6.36,
            'source' => 'JAPI_OFFICIAL_SPEC_100X40X40_6360G',
        ],
    ];
}

/**
 * Rows of the latest persisted audit. SvAmazonMarginStore::latest() returns the
 * report under 'snapshot' (not 'payload'); an empty list means "no audit yet".
 *
 * @return list<array<string,mixed>>
 */
function sv_amazon_latest_snapshot_rows(?array $latest): array
{
    $rows = $latest['snapshot']['rows'] ?? null;
    return is_array($rows) ? array_values($rows) : [];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['preview', 'apply', 'skus:', 'export-sellerflex:']);
    $apply = array_key_exists('apply', $options);
    if ($apply && array_key_exists('preview', $options)) {
        fwrite(STDERR, json_encode(['state' => 'failed', 'reason' => 'preview_and_apply_are_exclusive']) . "\n");
        exit(2);
    }
    $selectedSkus = [];
    if (isset($options['skus'])) {
        $selectedSkus = array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string)$options['skus'])
        ))));
    }
    $exportPath = isset($options['export-sellerflex']) ? trim((string)$options['export-sellerflex']) : '';

    $db = sv_pdo();
    if (!$db) {
        fwrite(STDERR, json_encode(['state' => 'failed', 'reason' => 'database_unavailable']) . "\n");
        exit(2);
    }
    SvAmazonMarginStore::ensureSchema($db);
    $latest = SvAmazonMarginStore::latest($db);
    $auditRows = sv_amazon_latest_snapshot_rows($latest);
    if ($auditRows === []) {
        fwrite(STDERR, json_encode(['state' => 'failed', 'reason' => 'latest_audit_snapshot_missing']) . "\n");
        exit(2);
    }

    $overrides = sv_amazon_manufacturer_package_overrides();
    $client = new SvAmazonClient();
    $marketplace = $client->marketplaceId();
    $sellerId = $client->sellerId();

    $candidates = [];
    foreach ($auditRows as $row) {
        $sku = (string)($row['seller_sku'] ?? '');
        if ($sku === '') continue;
        if ($selectedSkus !== [] && !in_array($sku, $selectedSkus, true)) continue;
        if ($selectedSkus === []
            && !in_array('BUYABLE', is_array($row['amazon_status'] ?? null) ? $row['amazon_status'] : [], true)) {
            continue;
        }
        $multiplier = (int)($row['kit_cost_multiplier']
            ?? $row['current_product_link_reference']['kit_cost_multiplier'] ?? 1);
        if ($multiplier !== 1) continue;

        $erpPkg = $overrides[$sku]
            ?? ($row['erp_package'] ?? $row['current_product_link_reference']['erp_package'] ?? null);
        if (!is_array($erpPkg)) continue;
        $erpBillable = SvAmazonCurrentPriceMargin::billable($erpPkg);
        if ($erpBillable === null || !($erpBillable['eligible'] ?? false)) continue;

        $amzPkg = $row['amazon_package'] ?? null;
        $amzBillable = is_array($amzPkg) ? SvAmazonCurrentPriceMargin::billable($amzPkg) : null;
        $needsSync = $amzBillable === null
            || !($amzBillable['eligible'] ?? false)
            || ($row['current_package_conflict'] ?? false) === true
            || isset($overrides[$sku]);
        if (!$needsSync && $selectedSkus === []) continue;

        $candidates[] = [
            'seller_sku' => $sku,
            'asin' => (string)($row['asin'] ?? ''),
            'erp_id' => (int)($row['erp_id'] ?? $row['current_product_link_reference']['erp_id'] ?? 0),
            'erp_sku' => (string)($row['erp_sku'] ?? $row['current_product_link_reference']['erp_sku'] ?? ''),
            'program' => (string)($row['program'] ?? $row['current_product_link_reference']['program'] ?? ''),
            'target_package' => $erpPkg,
            'target_billable_kg' => $erpBillable['billable_kg'],
            'package_source' => $overrides[$sku]['source'] ?? 'OLIST_V3_REGISTERED_PACKAGE',
        ];
    }

    if ($exportPath !== '') {
        $fp = fopen($exportPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('cannot_open_sellerflex_export_path');
        }
        fputcsv($fp, [
            'seller_sku', 'asin', 'erp_id', 'erp_sku', 'program',
            'length_cm', 'width_cm', 'height_cm', 'weight_kg',
            'billable_kg', 'package_source',
        ]);
        foreach ($candidates as $c) {
            $pkg = $c['target_package'];
            fputcsv($fp, [
                $c['seller_sku'], $c['asin'], $c['erp_id'], $c['erp_sku'], $c['program'],
                $pkg['length_cm'], $pkg['width_cm'], $pkg['height_cm'], $pkg['weight_kg'],
                round((float)$c['target_billable_kg'], 4), $c['package_source'],
            ]);
        }
        fclose($fp);
    }

    $listingPath = static fn(string $sku): string =>
        '/listings/2021-08-01/items/' . rawurlencode($sellerId) . '/' . rawurlencode($sku);
    $results = [];
    foreach ($candidates as $c) {
        $sku = $c['seller_sku'];
        $pkg = $c['target_package'];
        $listingResp = $client->request('GET', $listingPath($sku), [
            'marketplaceIds' => $marketplace, 'includedData' => 'attributes,summaries', 'issueLocale' => 'pt_BR',
        ]);
        $listing = is_array($listingResp['data'] ?? null) ? $listingResp['data'] : [];
        $productType = trim((string)($listing['summaries'][0]['productType'] ?? $listing['productType'] ?? ''));
        if ($productType === '') {
            $results[] = ['seller_sku' => $sku, 'status' => 'skipped_missing_product_type'];
            continue;
        }
        $patch = SvAmazonTargetMarginPublication::amazonPackagePatch($productType, $marketplace, $pkg, $listing);
        $query = ['marketplaceIds' => $marketplace, 'issueLocale' => 'pt_BR'];
        if (!$apply) {
            $query['mode'] = 'VALIDATION_PREVIEW';
        }
        $patchResp = $client->request('PATCH', $listingPath($sku), $query, $patch);
        $ack = (string)($patchResp['data']['status'] ?? '');
        $verified = false;
        if ($apply && in_array($ack, ['ACCEPTED', 'VALID'], true)) {
            $afterResp = $client->request('GET', $listingPath($sku), [
                'marketplaceIds' => $marketplace, 'includedData' => 'attributes,summaries', 'issueLocale' => 'pt_BR',
            ]);
            $afterListing = is_array($afterResp['data'] ?? null) ? $afterResp['data'] : [];
            $verified = SvAmazonTargetMarginPublication::listingPackageMatches($afterListing, $pkg);
        }
        $results[] = [
            'seller_sku' => $sku,
            'asin' => $c['asin'],
            'program' => $c['program'],
            'mode' => $apply ? 'LIVE_APPLY' : 'VALIDATION_PREVIEW',
            'sp_api_status' => $ack,
            'readback_verified' => $verified,
            'target_package' => $pkg,
            'target_billable_kg' => round((float)$c['target_billable_kg'], 4),
            'issues' => $patchResp['data']['issues'] ?? [],
        ];
    }

    echo json_encode([
        'state' => 'done',
        'mode' => $apply ? 'LIVE_APPLY' : 'VALIDATION_PREVIEW',
        'candidate_count' => count($candidates),
        'sellerflex_csv' => $exportPath !== '' ? $exportPath : null,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}
