<?php
declare(strict_types=1);

function sv_official_site_http_status_is_inconclusive(int $status): bool
{
    return in_array($status, [401, 403, 429], true);
}

function sv_official_site_http_status_is_blocking(int $status): bool
{
    if ($status >= 200 && $status < 400) {
        return false;
    }
    if (sv_official_site_http_status_is_inconclusive($status)) {
        return false;
    }
    return $status >= 400;
}
