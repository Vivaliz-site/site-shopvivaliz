<?php
declare(strict_types=1);

/**
 * Select a private, persistent directory for one-time OAuth callback output.
 * The caller provisions the base outside the web root and the Apache
 * PrivateTmp mount. Never place refresh tokens in a release or public path.
 */
function sv_google_oauth_private_pending_dir(string $base): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '' || $base[0] !== '/' || str_contains($base, "\0")
        || is_link($base) || !is_dir($base)) {
        throw new RuntimeException('Private OAuth pending base is unavailable.');
    }

    $check = static function (string $path): void {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || !is_dir($path) || is_link($path)
            || (($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || !is_readable($path) || !is_writable($path)) {
            throw new RuntimeException('Private OAuth pending directory permissions are invalid.');
        }
    };

    $check($base);
    $dir = $base . '/shopvivaliz-google-ads-refresh';
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('Could not create private OAuth pending directory.');
    }
    $check($dir);
    return $dir;
}
