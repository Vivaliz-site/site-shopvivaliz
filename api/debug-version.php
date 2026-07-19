<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
$versionFile = $root . '/config/shopvivaliz-version.php';
$version = is_file($versionFile) ? require $versionFile : [];

$gitDir = $root . '/.git';
$git = [
    'available' => false,
    'branch' => 'unknown',
    'commit' => 'unknown',
    'commit_short' => 'unknown',
];

if (is_dir($gitDir)) {
    $gitHead = trim((string)@file_get_contents($gitDir . '/HEAD'));
    $git['available'] = $gitHead !== '';
    if (preg_match('/^ref: refs\/heads\/(.+)$/', $gitHead, $matches)) {
        $branchRef = $matches[1];
        $git['branch'] = basename($branchRef);
        $branchFile = $gitDir . '/refs/heads/' . $branchRef;
        if (is_file($branchFile)) {
            $commit = trim((string)file_get_contents($branchFile));
            if ($commit !== '') {
                $git['commit'] = $commit;
                $git['commit_short'] = substr($commit, 0, 7);
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'endpoint' => 'debug-version',
    'timestamp' => date('c'),
    'server' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'version' => [
        'version' => (string)($version['version'] ?? 'unknown'),
        'version_code' => (int)($version['version_code'] ?? 0),
        'channel' => (string)($version['channel'] ?? 'unknown'),
        'codename' => (string)($version['codename'] ?? 'unknown'),
        'release_type' => (string)($version['release_type'] ?? 'unknown'),
        'generated_at' => (string)($version['generated_at'] ?? 'unknown'),
    ],
    'git' => $git,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
