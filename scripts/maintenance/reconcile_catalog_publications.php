<?php
declare(strict_types=1);

/**
 * Reconciles marketplace submissions that entered an external review queue.
 *
 * Amazon Listings Items and TikTok Shop can accept a request before the new
 * version becomes live. This command performs authenticated read-back, keeps
 * the item as `submitted` while review is pending, moves it to `published`
 * only when the live channel confirms the approved content, and records a
 * real failure when the channel rejects it or the configured deadline expires.
 *
 * Amazon read-back is opt-in only. Set CATALOG_RECONCILE_AMAZON=1 in an
 * explicitly approved manual run; routine executions must not touch Amazon.
 *
 * No price, inventory or stock endpoint is called.
 */
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/marketplace/MarketplaceRuntime.php';
require_once dirname(__DIR__, 2) . '/includes/marketplace/AmazonPublisher.php';
require_once dirname(__DIR__, 2) . '/includes/marketplace/TikTokPublisher.php';

$db = sv_pdo();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database_unavailable\n");
    exit(2);
}
svcp_ensure_schema($db);

$limit = max(1, min(500, (int)(getenv('CATALOG_RECONCILE_LIMIT') ?: 100)));
$maxAge = max(3600, (int)(getenv('CATALOG_SUBMISSION_MAX_AGE_SECONDS') ?: 259200));
$amazonReconcileEnabled = in_array(strtolower(trim((string)(getenv('CATALOG_RECONCILE_AMAZON') ?: ''))), ['1', 'true', 'yes', 'on'], true);

/** @return array<string,mixed>|null */
function svrec_channel_content(PDO $db, int $productId, string $channel): ?array
{
    $stmt = $db->prepare('SELECT * FROM product_channel_content WHERE product_id = ? AND channel = ? LIMIT 1');
    $stmt->execute([$productId, $channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return list<string> */
function svrec_recursive_strings(mixed $value): array
{
    $out = [];
    $walk = static function (mixed $node) use (&$walk, &$out): void {
        if (is_string($node)) {
            $text = trim($node);
            if ($text !== '' && !in_array($text, $out, true)) $out[] = $text;
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $child) $walk($child);
    };
    $walk($value);
    return $out;
}

/** @return list<string> */
function svrec_image_uris(mixed $images): array
{
    $uris = [];
    foreach (is_array($images) ? $images : [] as $entry) {
        if (!is_array($entry)) continue;
        foreach (['uri', 'url', 'media_location'] as $key) {
            $value = trim((string)($entry[$key] ?? ''));
            if ($value !== '' && !in_array($value, $uris, true)) $uris[] = $value;
        }
    }
    return $uris;
}

/** @return list<string> */
function svrec_site_image_urls(PDO $db, int $productId): array
{
    $stmt = $db->prepare('SELECT public_url FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
    $stmt->execute([$productId]);
    $urls = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $url) {
        $absolute = sv_market_absolute_url((string)$url);
        if ($absolute !== '' && !in_array($absolute, $urls, true)) $urls[] = $absolute;
    }
    return $urls;
}

/** @param array<string,mixed> $publication @return array<string,mixed> */
function svrec_amazon(PDO $db, SvAmazonClient $client, array $publication, int $maxAge): array
{
    $sku = trim((string)$publication['external_id']);
    if ($sku === '') throw new RuntimeException('Amazon submission sem SKU externo.');
    $response = $client->request(
        'GET',
        '/listings/2021-08-01/items/' . rawurlencode($client->sellerId()) . '/' . rawurlencode($sku),
        ['marketplaceIds' => $client->marketplaceId(), 'includedData' => 'summaries,attributes,issues']
    );
    $listing = $response['data'];
    $issues = is_array($listing['issues'] ?? null) ? $listing['issues'] : [];
    $blocking = [];
    foreach ($issues as $issue) {
        $severity = strtoupper((string)($issue['severity'] ?? ''));
        if (in_array($severity, ['ERROR', 'FATAL'], true)) $blocking[] = $issue;
    }
    if ($blocking !== []) {
        return [
            'status' => 'publication_failed',
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'response' => ['issues' => $blocking, 'reconciliation' => 'amazon_listing_rejected'],
            'error_message' => 'Amazon rejeitou a submissão: ' . sv_market_safe_excerpt(sv_market_json($blocking)),
            'verified' => true,
        ];
    }

    $publicationType = (string)$publication['publication_type'];
    $confirmed = false;
    $evidence = [];
    if ($publicationType === 'text') {
        $content = svrec_channel_content($db, (int)$publication['product_id'], 'amazon');
        $desiredTitle = trim((string)($content['title'] ?? ''));
        $titles = svrec_recursive_strings($listing['attributes']['item_name'] ?? []);
        $confirmed = $desiredTitle !== '' && in_array($desiredTitle, $titles, true);
        $evidence = ['desired_title_confirmed' => $confirmed, 'listing_title_values' => count($titles)];
    } else {
        $desiredUrls = svrec_site_image_urls($db, (int)$publication['product_id']);
        $imageAttributes = [];
        foreach (is_array($listing['attributes'] ?? null) ? $listing['attributes'] : [] as $name => $value) {
            if (str_contains((string)$name, 'product_image_locator')) $imageAttributes[$name] = $value;
        }
        $liveUrls = svrec_recursive_strings($imageAttributes);
        $confirmed = $desiredUrls !== [] && array_intersect($desiredUrls, $liveUrls) !== [];
        $evidence = ['desired_image_confirmed' => $confirmed, 'live_image_locator_count' => count($liveUrls)];
    }

    if ($confirmed) {
        return [
            'status' => 'published',
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'response' => array_merge($evidence, ['issues' => $issues, 'reconciliation' => 'amazon_live_readback']),
            'verified' => true,
        ];
    }

    $age = time() - strtotime((string)$publication['created_at']);
    if ($age >= $maxAge) {
        return [
            'status' => 'publication_failed',
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'response' => array_merge($evidence, ['issues' => $issues, 'reconciliation' => 'amazon_confirmation_timeout', 'age_seconds' => $age]),
            'error_message' => 'Amazon não confirmou a submissão dentro do prazo configurado.',
            'verified' => true,
        ];
    }

    return [
        'status' => 'submitted',
        'http_status' => (int)$response['status'],
        'request_id' => (string)$response['request_id'],
        'response' => array_merge($evidence, ['issues' => $issues, 'reconciliation' => 'amazon_still_pending', 'age_seconds' => $age]),
        'verified' => false,
    ];
}

/** @param array<string,mixed> $publication @return array<string,mixed> */
function svrec_tiktok(PDO $db, SvTikTokClient $client, array $publication, int $maxAge): array
{
    $externalId = trim((string)$publication['external_id']);
    if ($externalId === '') throw new RuntimeException('TikTok submission sem product_id externo.');
    $review = $client->request('GET', '/product/202309/products/' . rawurlencode($externalId), null, ['return_under_review_version' => 'true']);
    $live = $client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
    $reviewData = $review['data'];
    $liveData = $live['data'];
    $auditStatus = strtoupper((string)($reviewData['audit']['status'] ?? $liveData['audit']['status'] ?? ''));
    $liveStatus = strtoupper((string)($liveData['status'] ?? $liveData['product_status'] ?? ''));
    if (in_array($auditStatus, ['REJECTED', 'FAILED', 'DISAPPROVED'], true)) {
        return [
            'status' => 'publication_failed',
            'http_status' => (int)$review['status'],
            'request_id' => (string)($review['request_id'] ?: $live['request_id']),
            'response' => ['audit_status' => $auditStatus, 'product_status' => $liveStatus, 'reconciliation' => 'tiktok_rejected'],
            'error_message' => 'TikTok Shop rejeitou a versão submetida.',
            'verified' => true,
        ];
    }

    $publicationType = (string)$publication['publication_type'];
    $confirmed = false;
    $pendingConfirmed = false;
    $evidence = ['audit_status' => $auditStatus, 'product_status' => $liveStatus];
    if ($publicationType === 'text') {
        $content = svrec_channel_content($db, (int)$publication['product_id'], 'tiktok');
        $desiredTitle = trim((string)($content['title'] ?? ''));
        $reviewTitle = trim((string)($reviewData['title'] ?? $reviewData['product']['title'] ?? ''));
        $liveTitle = trim((string)($liveData['title'] ?? $liveData['product']['title'] ?? ''));
        $pendingConfirmed = $desiredTitle !== '' && $reviewTitle === $desiredTitle;
        $confirmed = $desiredTitle !== '' && $liveTitle === $desiredTitle && in_array($liveStatus, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true);
        $evidence += ['under_review_confirmed' => $pendingConfirmed, 'live_confirmed' => $confirmed];
    } else {
        $initial = sv_market_decode_json((string)($publication['response_json'] ?? ''));
        $initialReviewCount = (int)($initial['under_review_image_count'] ?? 0);
        $reviewImages = $reviewData['main_images'] ?? $reviewData['product']['main_images'] ?? [];
        $liveImages = $liveData['main_images'] ?? $liveData['product']['main_images'] ?? [];
        $reviewUris = svrec_image_uris($reviewImages);
        $liveUris = svrec_image_uris($liveImages);
        $submittedUris = is_array($initial['submitted_uris'] ?? null) ? array_values(array_filter(array_map('strval', $initial['submitted_uris']))) : [];
        $pendingConfirmed = $submittedUris !== [] ? array_intersect($submittedUris, $reviewUris) !== [] : count($reviewUris) >= max(1, $initialReviewCount);
        $confirmed = $submittedUris !== []
            ? array_intersect($submittedUris, $liveUris) !== []
            : count($liveUris) >= max(1, $initialReviewCount);
        $confirmed = $confirmed && in_array($liveStatus, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true);
        $evidence += [
            'under_review_confirmed' => $pendingConfirmed,
            'live_confirmed' => $confirmed,
            'under_review_image_count' => count($reviewUris),
            'live_image_count' => count($liveUris),
        ];
    }

    if ($confirmed) {
        return [
            'status' => 'published',
            'http_status' => (int)$live['status'],
            'request_id' => (string)($live['request_id'] ?: $review['request_id']),
            'response' => array_merge($evidence, ['reconciliation' => 'tiktok_live_readback']),
            'verified' => true,
        ];
    }
    $age = time() - strtotime((string)$publication['created_at']);
    if ($age >= $maxAge) {
        return [
            'status' => 'publication_failed',
            'http_status' => (int)$review['status'],
            'request_id' => (string)($review['request_id'] ?: $live['request_id']),
            'response' => array_merge($evidence, ['reconciliation' => 'tiktok_confirmation_timeout', 'age_seconds' => $age]),
            'error_message' => 'TikTok Shop não confirmou a submissão dentro do prazo configurado.',
            'verified' => true,
        ];
    }
    return [
        'status' => 'submitted',
        'http_status' => (int)$review['status'],
        'request_id' => (string)($review['request_id'] ?: $live['request_id']),
        'response' => array_merge($evidence, ['reconciliation' => 'tiktok_still_pending', 'age_seconds' => $age]),
        'verified' => false,
    ];
}

/** @param array<string,mixed> $publication @param array<string,mixed> $result */
function svrec_update_publication(PDO $db, array $publication, array $result): void
{
    $oldResponse = sv_market_decode_json((string)($publication['response_json'] ?? ''));
    $newResponse = array_merge($oldResponse, is_array($result['response'] ?? null) ? $result['response'] : []);
    $stmt = $db->prepare(
        'UPDATE catalog_publications SET status = ?, http_status = ?, request_id = ?, response_json = ?, '
        . 'error_message = ?, verified_at = ?, updated_at = NOW() WHERE id = ?'
    );
    $status = (string)$result['status'];
    $stmt->execute([
        $status,
        (int)($result['http_status'] ?? $publication['http_status'] ?? 0),
        (string)($result['request_id'] ?? $publication['request_id'] ?? ''),
        sv_market_json($newResponse),
        $result['error_message'] ?? null,
        in_array($status, ['published', 'publication_failed'], true) ? date('Y-m-d H:i:s') : null,
        (int)$publication['id'],
    ]);
    if ($status === 'published' && (string)$publication['publication_type'] === 'text') {
        $content = $db->prepare('UPDATE product_channel_content SET published_at = NOW(), updated_at = NOW() WHERE product_id = ? AND channel = ?');
        $content->execute([(int)$publication['product_id'], (string)$publication['channel']]);
    }
}

function svrec_refresh_staging(PDO $db, string $publicationType, int $stagingId): void
{
    $stmt = $db->prepare('SELECT * FROM catalog_publications WHERE publication_type = ? AND staging_id = ? ORDER BY id ASC');
    $stmt->execute([$publicationType, $stagingId]);
    $latest = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $latest[(string)$row['channel']] = $row;
    if ($latest === []) return;
    $statuses = array_map(static fn(array $row): string => (string)$row['status'], $latest);
    $failures = array_filter($latest, static fn(array $row): bool => (string)$row['status'] === 'publication_failed');
    if (count($failures) === count($latest)) $final = 'publication_failed';
    elseif ($failures !== []) $final = 'partial_published';
    elseif (in_array('submitted', $statuses, true)) $final = 'submitted';
    elseif (count(array_filter($statuses, static fn(string $status): bool => $status === 'published')) === count($latest)) $final = 'published';
    else $final = 'publishing';
    $summary = [];
    $errors = [];
    foreach ($latest as $channel => $row) {
        $summary[$channel] = [
            'status' => (string)$row['status'],
            'external_id' => (string)$row['external_id'],
            'http_status' => (int)$row['http_status'],
            'request_id' => (string)$row['request_id'],
            'response' => sv_market_decode_json((string)($row['response_json'] ?? '')),
        ];
        if (trim((string)($row['error_message'] ?? '')) !== '') $errors[] = $channel . ': ' . trim((string)$row['error_message']);
    }
    $table = $publicationType === 'image' ? 'product_images_staging' : 'catalog_optimizations_staging';
    $update = $db->prepare(
        "UPDATE `{$table}` SET status = ?, error_message = ?, publication_summary_json = ?, published_at = ?, updated_at = NOW() WHERE id = ?"
    );
    $update->execute([
        $final,
        $errors !== [] ? sv_market_safe_excerpt(implode(' | ', $errors), 1000) : null,
        sv_market_json(['status' => $final, 'channels' => $summary]),
        in_array($final, ['published', 'partial_published'], true) ? date('Y-m-d H:i:s') : null,
        $stagingId,
    ]);
}

$eligibleChannelsSql = $amazonReconcileEnabled ? "'amazon','tiktok'" : "'tiktok'";
$query = $db->prepare(
    "SELECT * FROM catalog_publications WHERE status = 'submitted' AND channel IN ({$eligibleChannelsSql}) ORDER BY id ASC LIMIT {$limit}"
);
$query->execute();
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$clients = [];
$counts = ['checked' => 0, 'published' => 0, 'submitted' => 0, 'publication_failed' => 0, 'transient_errors' => 0];
$items = [];

foreach ($rows as $row) {
    $counts['checked']++;
    $channel = (string)$row['channel'];
    try {
        if ($channel === 'amazon') {
            $clients['amazon'] ??= new SvAmazonClient();
            $result = svrec_amazon($db, $clients['amazon'], $row, $maxAge);
        } else {
            $clients['tiktok'] ??= new SvTikTokClient();
            $result = svrec_tiktok($db, $clients['tiktok'], $row, $maxAge);
        }
        svrec_update_publication($db, $row, $result);
        svrec_refresh_staging($db, (string)$row['publication_type'], (int)$row['staging_id']);
        $counts[(string)$result['status']]++;
        $items[] = ['publication_id' => (int)$row['id'], 'channel' => $channel, 'status' => (string)$result['status']];
    } catch (Throwable $e) {
        $counts['transient_errors']++;
        $items[] = [
            'publication_id' => (int)$row['id'],
            'channel' => $channel,
            'status' => 'submitted',
            'reconcile_error' => sv_market_safe_excerpt($e->getMessage(), 500),
        ];
    }
}

$result = [
    'ok' => $counts['transient_errors'] === 0,
    'checked_at' => gmdate('c'),
    'max_submission_age_seconds' => $maxAge,
    'amazon_reconcile_enabled' => $amazonReconcileEnabled,
    'counts' => $counts,
    'items' => $items,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($counts['transient_errors'] === 0 ? 0 : 2);
