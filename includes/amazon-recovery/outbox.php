<?php
declare(strict_types=1);

function sv_ar_action_idempotency_key(array $case,string $actionType): string
{
    $parts=[trim((string)($case['amazon_order_id'] ?? '')),trim((string)($case['amazon_order_item_id'] ?? '')),trim((string)($case['refund_event_id'] ?? '')),strtoupper(trim((string)($case['claim_reason'] ?? ''))),strtoupper(trim($actionType))];
    if ($parts[0]==='' || $parts[3]==='' || $parts[4]==='') throw new InvalidArgumentException('order, claim reason and action are required for idempotency key');
    return hash('sha256',implode('|',$parts));
}
