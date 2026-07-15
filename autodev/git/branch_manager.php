<?php
declare(strict_types=1);

/**
 * Gera um nome de branch sanitizado para PRs automáticos.
 */
function autodev_branch_name(string $prefix): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($prefix)));
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 50);
    return 'autodev/' . $slug . '-' . date('Ymd-His');
}
