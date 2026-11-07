<?php
declare(strict_types=1);

// Offline-only RED/GREEN for reports under shared Olist/Tiny v3 rate limits.
// This suite NEVER issues network requests, purchases or database writes.
require_once dirname(__DIR__) . '/includes/financial-drift-classifier.php';

function fdv_check(string $case, array $input, string $status, int $exit, bool $complete): void
{
    $result = svfd_report_verdict($input);
    if (($result['status'] ?? '') !== $status
        || ($result['exit_code'] ?? -1) !== $exit
        || ($result['evidence_complete'] ?? null) !== $complete) {
        fwrite(STDERR, 'FAIL ' . $case . ' ' . json_encode($result) . PHP_EOL);
        exit(1);
    }
}

$complete = [
    'site_orders' => 29,
    'payment_candidates' => 2,
    'provider_verified' => 2,
    'erp_verified' => 2,
    'mysql_unavailable' => 0,
    'api_errors' => 0,
    'erp_rate_limited' => 0,
    'erp_checks_skipped_rate_limit' => 0,
    'discrepancy' => 2,
    'mysql_missing_evidence' => 2,
    'unverified' => 0,
];
fdv_check('known real P0 remains P0', $complete, 'P0_FINANCIAL_DRIFT_FOUND', 0, true);
fdv_check('ERP HTTP 429 cannot produce a clean report', array_replace($complete, [
    'erp_verified'=>0, 'discrepancy'=>0, 'mysql_missing_evidence'=>0,
    'api_errors'=>1, 'erp_rate_limited'=>1, 'erp_checks_skipped_rate_limit'=>1
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('previously verified P0 stays visible during later 429', array_replace($complete, [
    'erp_verified'=>1, 'api_errors'=>1, 'erp_rate_limited'=>1
]), 'P0_FINANCIAL_DRIFT_FOUND', 2, false);
fdv_check('no candidate never means all clear', array_replace($complete, [
    'payment_candidates'=>0, 'provider_verified'=>0, 'erp_verified'=>0,
    'discrepancy'=>0, 'mysql_missing_evidence'=>0
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('MySQL unavailable is incomplete', array_replace($complete, [
    'discrepancy'=>0, 'mysql_missing_evidence'=>0, 'mysql_unavailable'=>1
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('provider mismatch remains unverified', array_replace($complete, [
    'discrepancy'=>0, 'mysql_missing_evidence'=>0, 'unverified'=>1
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('all available candidates reconciled', array_replace($complete, [
    'discrepancy'=>0, 'mysql_missing_evidence'=>0
]), 'NO_VERIFIED_DRIFT_IN_SCANNED_ORDERS', 0, true);
fdv_check('missing current ERP read is incomplete', array_replace($complete, [
    'discrepancy'=>0, 'mysql_missing_evidence'=>0, 'erp_verified'=>1
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('no local site orders is incomplete', array_replace($complete, [
    'site_orders'=>0, 'payment_candidates'=>0, 'provider_verified'=>0,
    'erp_verified'=>0, 'discrepancy'=>0, 'mysql_missing_evidence'=>0
]), 'INCOMPLETE_EVIDENCE', 2, false);
fdv_check('generic upstream error is incomplete', array_replace($complete, [
    'discrepancy'=>0, 'mysql_missing_evidence'=>0, 'api_errors'=>1
]), 'INCOMPLETE_EVIDENCE', 2, false);

$report = file_get_contents(dirname(__DIR__) . '/scripts/finance/site-payment-drift-readonly.php');
foreach (['erp_rate_limited', 'erp_checks_skipped_rate_limit', 'svfd_report_verdict', 'http_status_counts', 'exit($verdict['] as $piece) {
    if ($report === false || !str_contains($report, $piece)) {
        fwrite(STDERR, 'FAIL missing rate-limit and incomplete-report guard ' . $piece . PHP_EOL);
        exit(1);
    }
}
echo "PASS: 10 verdict fixtures and rate-limit visibility contract.\n";
