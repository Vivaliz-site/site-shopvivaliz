<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/google-search-console-issue-classifier.php';

function gsc_classifier_assert(array $index, array $expected, string $message): void
{
    $actual = gsc_classify_index_issues($index);
    if ($actual !== $expected) {
        fwrite(STDERR, $message . "\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
        exit(1);
    }
}

gsc_classifier_assert([
    'verdict' => 'NEUTRAL',
    'indexingState' => 'INDEXING_ALLOWED',
    'pageFetchState' => 'SUCCESSFUL',
    'robotsTxtState' => 'ALLOWED',
    'googleCanonical' => 'https://shopvivaliz.com.br/produto/a',
    'userCanonical' => 'https://shopvivaliz.com.br/produto/a',
], [], 'NEUTRAL with healthy technical signals must not fail the audit');
gsc_classifier_assert([
    'verdict' => 'NEUTRAL',
    'indexingState' => 'INDEXING_STATE_UNSPECIFIED',
    'pageFetchState' => 'PAGE_FETCH_STATE_UNSPECIFIED',
    'robotsTxtState' => 'ROBOTS_TXT_STATE_UNSPECIFIED',
], [], 'Unspecified pre-crawl states must remain observations, not technical failures');

gsc_classifier_assert([
    'verdict' => 'FAIL',
    'indexingState' => 'BLOCKED_BY_META_TAG',
    'pageFetchState' => 'SOFT_404',
    'robotsTxtState' => 'DISALLOWED',
    'googleCanonical' => 'https://shopvivaliz.com.br/produto/old',
    'userCanonical' => 'https://shopvivaliz.com.br/produto/new',
], [
    'INDEX_VERDICT_FAIL',
    'INDEXING_NOT_ALLOWED',
    'PAGE_FETCH_SOFT_404',
    'ROBOTS_DISALLOWED',
    'CANONICAL_MISMATCH',
], 'Explicit technical failures must still fail the audit');

fwrite(STDOUT, "google-search-console-issue-classifier-test: ok\n");