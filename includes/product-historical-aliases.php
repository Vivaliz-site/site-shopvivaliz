<?php
declare(strict_types=1);

/**
 * Proven historical product aliases observed by Google Search Console.
 *
 * Keep this list explicit and narrow: only aliases with a verified current
 * product target belong here. Unknown values intentionally return null.
 */
function sv_product_historical_alias_target(string $requestedSlug): ?string
{
    $normalized = trim(rawurldecode($requestedSlug));
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($normalized, 'UTF-8')
        : strtolower($normalized);

    $aliases = [
        'massa-f12-para-calafetar-madeira-400g-mogno-viapol-411'
            => 'massa-f12-de-calafetar-e-correção-madeira-viapol-400g-mogno-v0210691',
        'casinha-cachorro-52x41x40-astra-pet-azul-504'
            => 'casinha-cachorro-52x41x40-astra-pet-azul-astrapetcasinhacachorroazul',
        'vaso-antique-44-70l-cimento-queimado-japi-546'
            => 'vaso-antique-44-70l-cimento-queimado-japi-jvaqcq44',
    ];

    return $aliases[$normalized] ?? null;
}
