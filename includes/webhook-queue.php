<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/queue/queue.php';

function sv_webhook_enqueue(string $provider, array $payload, int $priority = 50): int
{
    return sv_queue_enqueue('webhook:' . $provider, $payload, $priority);
}
