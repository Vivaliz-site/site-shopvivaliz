<?php
declare(strict_types=1);

require_once __DIR__ . '/product-historical-aliases.php';

function gsc_classifier_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return rtrim($url, '/');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    return $scheme . '://' . $host . ($path !== '' ? $path : '/') . $query;
}

function gsc_classifier_product_slug(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)) {
        return null;
    }
    $host = preg_replace('/^www\./', '', strtolower((string)($parts['host'] ?? '')));
    if ($host !== 'shopvivaliz.com.br') {
        return null;
    }
    $path = rawurldecode((string)($parts['path'] ?? ''));
    if (preg_match('~^/produto/([^/]+)/?$~u', $path, $matches) !== 1) {
        return null;
    }
    $slug = trim((string)$matches[1]);
    return $slug !== '' ? $slug : null;
}

function gsc_classifier_normalize_slug(string $slug): string
{
    $slug = trim(rawurldecode($slug));
    return function_exists('mb_strtolower')
        ? mb_strtolower($slug, 'UTF-8')
        : strtolower($slug);
}

function gsc_classifier_is_remediated_canonical(string $googleCanonical, string $userCanonical): bool
{
    $legacySlug = gsc_classifier_product_slug($googleCanonical);
    $currentSlug = gsc_classifier_product_slug($userCanonical);
    if ($legacySlug === null || $currentSlug === null) {
        return false;
    }

    $target = sv_product_historical_alias_target($legacySlug);
    return $target !== null
        && gsc_classifier_normalize_slug($target) === gsc_classifier_normalize_slug($currentSlug);
}

/** @return list<string> */
function gsc_classify_index_issues(array $index): array
{
    $verdict = trim((string)($index['verdict'] ?? ''));
    $indexingState = trim((string)($index['indexingState'] ?? ''));
    $pageFetchState = trim((string)($index['pageFetchState'] ?? ''));
    $robotsTxtState = trim((string)($index['robotsTxtState'] ?? ''));
    $googleCanonical = trim((string)($index['googleCanonical'] ?? ''));
    $userCanonical = trim((string)($index['userCanonical'] ?? ''));

    $issues = [];
    if ($verdict !== '' && !in_array($verdict, ['PASS', 'NEUTRAL'], true)) {
        $issues[] = 'INDEX_VERDICT_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($verdict));
    }
    if ($indexingState !== '' && !in_array($indexingState, ['INDEXING_ALLOWED', 'INDEXING_STATE_UNSPECIFIED'], true)) {
        $issues[] = 'INDEXING_NOT_ALLOWED';
    }
    if ($pageFetchState !== '' && !in_array($pageFetchState, ['SUCCESSFUL', 'PAGE_FETCH_STATE_UNSPECIFIED'], true)) {
        $issues[] = 'PAGE_FETCH_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($pageFetchState));
    }
    if ($robotsTxtState !== '' && !in_array($robotsTxtState, ['ALLOWED', 'ROBOTS_TXT_STATE_UNSPECIFIED'], true)) {
        $issues[] = 'ROBOTS_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($robotsTxtState));
    }
    $canonicalDiffers = $googleCanonical !== ''
        && $userCanonical !== ''
        && gsc_classifier_normalize_url($googleCanonical) !== gsc_classifier_normalize_url($userCanonical);
    if ($canonicalDiffers && !gsc_classifier_is_remediated_canonical($googleCanonical, $userCanonical)) {
        $issues[] = 'CANONICAL_MISMATCH';
    }

    return array_values(array_unique($issues));
}
