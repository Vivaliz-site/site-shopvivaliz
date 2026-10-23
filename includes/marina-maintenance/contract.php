<?php

declare(strict_types=1);

const SV_MARINA_MAINTENANCE_OPERATION = 'retire_origin_abandoned_cart_scheduler';
const SV_MARINA_MAINTENANCE_MAX_TTL = 180;

function sv_marina_maintenance_operation(): string
{
    return SV_MARINA_MAINTENANCE_OPERATION;
}

function sv_marina_recursive_sort(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[$key] = sv_marina_recursive_sort($item);
    }

    if (!array_is_list($normalized)) {
        ksort($normalized, SORT_STRING);
    }

    return $normalized;
}

function sv_marina_canonical_ticket(array $ticket): string
{
    return json_encode(
        sv_marina_recursive_sort($ticket),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function sv_marina_validate_ticket(array $ticket, int $now): array
{
    $allowed = [
        'ticket_version',
        'operation',
        'operator_id',
        'ticket_id',
        'nonce',
        'issued_at',
        'expires_at',
        'scope',
    ];
    $unknown = array_values(array_diff(array_keys($ticket), $allowed));
    if ($unknown !== []) {
        return ['ok' => false, 'code' => 'unknown_field', 'fields' => $unknown];
    }

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $ticket)) {
            return ['ok' => false, 'code' => 'required_field_missing', 'field' => $field];
        }
    }

    if ($ticket['ticket_version'] !== 1) {
        return ['ok' => false, 'code' => 'ticket_version_invalid'];
    }
    if ($ticket['operation'] !== SV_MARINA_MAINTENANCE_OPERATION) {
        return ['ok' => false, 'code' => 'operation_not_allowed'];
    }
    foreach (['operator_id', 'ticket_id', 'nonce'] as $field) {
        if (!is_string($ticket[$field]) || !preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $ticket[$field])) {
            return ['ok' => false, 'code' => $field . '_invalid', 'field' => $field];
        }
    }
    if (!is_int($ticket['issued_at']) || !is_int($ticket['expires_at'])) {
        return ['ok' => false, 'code' => 'ticket_timestamps_invalid'];
    }
    if ($ticket['issued_at'] > $now + 5) {
        return ['ok' => false, 'code' => 'ticket_not_yet_valid'];
    }
    if ($ticket['expires_at'] <= $now) {
        return ['ok' => false, 'code' => 'ticket_expired'];
    }
    if ($ticket['expires_at'] <= $ticket['issued_at']) {
        return ['ok' => false, 'code' => 'ticket_interval_invalid'];
    }
    if (($ticket['expires_at'] - $ticket['issued_at']) > SV_MARINA_MAINTENANCE_MAX_TTL) {
        return ['ok' => false, 'code' => 'ticket_ttl_exceeded'];
    }

    $expectedScope = [
        'source_host',
        'source_unit',
        'target_host',
    ];
    if (!is_array($ticket['scope']) || array_diff(array_keys($ticket['scope']), $expectedScope) !== []
        || array_diff($expectedScope, array_keys($ticket['scope'])) !== []) {
        return ['ok' => false, 'code' => 'scope_invalid'];
    }
    if ($ticket['scope']['source_host'] !== 'shopvivaliz-free-a1'
        || $ticket['scope']['source_unit'] !== 'shopvivaliz-abandoned-cart-recovery.timer'
        || $ticket['scope']['target_host'] !== 'marina') {
        return ['ok' => false, 'code' => 'scope_not_allowed'];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'ticket' => sv_marina_recursive_sort($ticket),
    ];
}

function sv_marina_signature_is_valid_shape(string $signature): bool
{
    if ($signature === '' || strlen($signature) > 1024) {
        return false;
    }
    $decoded = base64_decode($signature, true);
    return is_string($decoded) && $decoded !== '' && strlen($decoded) <= 768;
}

function sv_marina_audit_projection(mixed $value): mixed
{
    if (is_array($value)) {
        $projected = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string)$key);
            if (preg_match('/private|secret|token|signature|password|credential/', $keyString)) {
                $projected[$key] = '[REDACTED]';
            } else {
                $projected[$key] = sv_marina_audit_projection($item);
            }
        }
        return $projected;
    }

    return is_scalar($value) || $value === null ? $value : '[REDACTED]';
}
