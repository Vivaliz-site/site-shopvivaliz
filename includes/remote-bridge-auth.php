<?php

declare(strict_types=1);

final class SvRemoteBridgeAuth
{
    /**
     * Resolves the raw Authorization header value across server SAPIs.
     *
     * Apache's mod_php does not reliably populate $_SERVER['HTTP_AUTHORIZATION']
     * (confirmed in production for the Amazon Returns bridge: it was empty on
     * every request regardless of the token sent). apache_request_headers()
     * reads straight from Apache's own request record and reliably includes
     * Authorization, so it is used as a fallback when the $_SERVER copy is
     * missing.
     *
     * @param array<string,mixed> $server Typically $_SERVER.
     * @param array<string,mixed> $apacheHeaders Typically apache_request_headers(), or [] outside Apache/CLI.
     */
    public static function resolveAuthorizationHeader(array $server, array $apacheHeaders): string
    {
        $auth = $server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!is_string($auth) || trim($auth) === '') {
            foreach ($apacheHeaders as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization') {
                    $auth = $value;
                    break;
                }
            }
        }
        return is_string($auth) ? trim($auth) : '';
    }

    public static function authorized(string $expected, string $authorization): bool
    {
        $expected = trim($expected);
        if ($expected === '') return false;
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) return false;
        return hash_equals($expected, trim($m[1]));
    }
}
