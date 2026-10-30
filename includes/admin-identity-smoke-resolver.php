<?php
declare(strict_types=1);

/**
 * Choose a persisted admin candidate for the CLI-only production guard smoke.
 * Mirrors the canonical-account, existing-admin, unique-account precedence.
 *
 * @param array<string, mixed>|null $canonical
 * @param array<string, mixed>|null $domainAdmin
 * @param list<array<string, mixed>> $domainAccounts
 * @return array<string, mixed>|null
 */
function sv_choose_admin_smoke_identity(?array $canonical, ?array $domainAdmin, array $domainAccounts): ?array
{
    if (is_array($canonical) && !empty($canonical['id'])) {
        return $canonical;
    }

    if (is_array($domainAdmin) && !empty($domainAdmin['id']) && (int)($domainAdmin['is_admin'] ?? 0) === 1) {
        return $domainAdmin;
    }

    if (count($domainAccounts) === 1 && !empty($domainAccounts[0]['id'])) {
        return $domainAccounts[0];
    }

    return null;
}
