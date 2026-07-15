<?php
declare(strict_types=1);

/**
 * Gera o payload descritivo de um PR automático (não abre PR real).
 */
function autodev_pr_payload(string $message, string $branch): array
{
    return [
        'title'  => '[AUTO] ' . $message,
        'branch' => $branch,
        'body'   => "PR gerado automaticamente pelo EHA em " . date('c') . ".\n\nMotivo: $message",
        'base'   => 'main',
        'draft'  => true,
    ];
}
