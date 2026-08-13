<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * Resolve the deployed commit without exposing filesystem paths.
 *
 * Resolution order:
 * 1. Explicit environment variable written by the deploy process.
 * 2. Release marker file stored at the project root.
 * 3. Immutable release directory name (when releases are named by SHA).
 * 4. Git metadata, when the deployed checkout still contains .git.
 */
function resolveReleaseSha(string $projectRoot): array
{
    $envCandidates = ['APP_RELEASE_SHA', 'RELEASE_SHA', 'GIT_COMMIT'];
    foreach ($envCandidates as $name) {
        $value = trim((string) getenv($name));
        if (preg_match('/^[a-f0-9]{7,40}$/i', $value)) {
            return [strtolower($value), 'environment'];
        }
    }

    $markerCandidates = [
        $projectRoot . '/.release-sha',
        $projectRoot . '/RELEASE_SHA',
        dirname($projectRoot) . '/.release-sha',
    ];
    foreach ($markerCandidates as $marker) {
        if (!is_readable($marker)) {
            continue;
        }
        $value = trim((string) file_get_contents($marker));
        if (preg_match('/^[a-f0-9]{7,40}$/i', $value)) {
            return [strtolower($value), 'marker'];
        }
    }

    $resolvedRoot = realpath($projectRoot) ?: $projectRoot;
    $segments = preg_split('~[\\\\/]~', $resolvedRoot) ?: [];
    foreach (array_reverse($segments) as $segment) {
        if (preg_match('/^[a-f0-9]{7,40}$/i', $segment)) {
            return [strtolower($segment), 'release-directory'];
        }
    }

    $gitDir = $projectRoot . '/.git';
    $headFile = $gitDir . '/HEAD';
    if (is_readable($headFile)) {
        $head = trim((string) file_get_contents($headFile));
        if (preg_match('/^[a-f0-9]{40}$/i', $head)) {
            return [strtolower($head), 'git-head'];
        }
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            if ($ref !== '' && !str_contains($ref, '..')) {
                $refFile = $gitDir . '/' . $ref;
                if (is_readable($refFile)) {
                    $value = trim((string) file_get_contents($refFile));
                    if (preg_match('/^[a-f0-9]{40}$/i', $value)) {
                        return [strtolower($value), 'git-ref'];
                    }
                }
            }
        }
    }

    return [null, 'unknown'];
}

/**
 * Release paths are switched atomically behind the stable `current` symlink.
 * A long-lived web SAPI may still keep bytecode from the previous target in
 * OPcache. The deployment always probes this endpoint immediately after the
 * symlink swap, so use that probe to invalidate OPcache once for each new SHA.
 * The marker lives in /tmp, contains no secret, and prevents routine health
 * checks from repeatedly flushing the cache.
 */
function refreshOpcacheForRelease(?string $sha): bool
{
    if ($sha === null || PHP_SAPI === 'cli' || !function_exists('opcache_reset')) {
        return false;
    }

    $marker = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'shopvivaliz-opcache-release.sha';
    $last = is_readable($marker) ? trim((string) @file_get_contents($marker)) : '';
    if (hash_equals($sha, $last)) {
        return false;
    }

    $reset = @opcache_reset();
    if ($reset) {
        @file_put_contents($marker, $sha . PHP_EOL, LOCK_EX);
    }
    return $reset;
}

$projectRoot = dirname(__DIR__, 2);
[$sha, $source] = resolveReleaseSha($projectRoot);
$opcacheRefreshed = refreshOpcacheForRelease($sha);

$payload = [
    'ok' => $sha !== null,
    'service' => 'shopvivaliz',
    'release_sha' => $sha,
    'release_source' => $source,
    'opcache_refreshed' => $opcacheRefreshed,
    'checked_at' => gmdate('c'),
];

http_response_code($sha !== null ? 200 : 503);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
