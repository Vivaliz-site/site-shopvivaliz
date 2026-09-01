<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function sv_ar_normalize_amazon_notification(array $notification): array
{
    $type=strtoupper(trim((string)($notification['notificationType'] ?? $notification['notification_type'] ?? 'UNKNOWN')));
    $payload=is_array($notification['payload'] ?? null)?$notification['payload']:[];
    return ['event_type'=>$type,'source'=>'sp_api_notification','source_id'=>(string)($notification['notificationId'] ?? $payload['transactionId'] ?? $payload['reportId'] ?? hash('sha256',json_encode($notification) ?: '')),'payload'=>$payload];
}
