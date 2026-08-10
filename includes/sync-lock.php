<?php
declare(strict_types=1);

function sv_sync_lock_handle(string $name)
{
    $lockDir = dirname(__DIR__) . '/storage/locks';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }
    $handle = fopen($lockDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $name) . '.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        return false;
    }
    return $handle;
}

function sv_sync_unlock_handle($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
