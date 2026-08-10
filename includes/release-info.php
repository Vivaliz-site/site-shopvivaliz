<?php

declare(strict_types=1);

/**
 * Resolve o identificador da release atual sem depender de caminho fixo.
 *
 * Em produção o deploy imutavel vive em /releases/<identifier>/..., entao
 * basta ler o segmento imediatamente posterior a "releases".
 *
 * @return array{identifier:string,source:string}
 */
function sv_release_info_from_path(string $anchorDir): array
{
    $resolved = realpath($anchorDir) ?: $anchorDir;
    $segments = preg_split('~[\\\\/]~', $resolved) ?: [];

    for ($i = 0, $len = count($segments) - 1; $i < $len; $i++) {
        if ($segments[$i] !== 'releases') {
            continue;
        }

        $identifier = trim((string)($segments[$i + 1] ?? ''));
        if ($identifier !== '') {
            return ['identifier' => $identifier, 'source' => 'path'];
        }
    }

    return ['identifier' => 'local', 'source' => 'fallback'];
}
