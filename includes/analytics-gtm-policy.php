<?php
declare(strict_types=1);

/** Resolve an explicitly configured, non-retired GTM container.
 *
 * The retired container sends events to a different GA4 property. Ignoring it
 * here also protects deployments whose persistent environment predates its
 * retirement, without editing credentials or discarding environment keys.
 */
function svat_google_tag_manager_id(): string
{
    $id = trim((string)(getenv('GOOGLE_TAG_MANAGER_ID')
        ?: (getenv('GTM_ID') ?: (getenv('TAG_MANAGER') ?: ''))));
    if (!preg_match('/^GTM-[A-Z0-9]+$/D', $id) || $id === 'GTM-PHZ55CP3') {
        return '';
    }
    return $id;
}
