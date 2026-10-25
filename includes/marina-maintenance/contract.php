<?php

declare(strict_types=1);

const SV_MARINA_MAINTENANCE_OPERATION = 'retire_origin_abandoned_cart_scheduler';
const SV_MARINA_MAINTENANCE_MAX_TTL = 180;
const SV_MARINA_MAINTENANCE_MFA_MAX_AGE = 900;
const SV_MARINA_MAINTENANCE_BROKER_AUDIENCE = 'marina-maintenance-broker';

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
        'audience',
        'authorization_request_id',
        'requested_by',
        'preflight_id',
        'preflight_digest',
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

    if ($ticket['ticket_version'] !== 2) {
        return ['ok' => false, 'code' => 'ticket_version_invalid'];
    }
    if ($ticket['operation'] !== SV_MARINA_MAINTENANCE_OPERATION) {
        return ['ok' => false, 'code' => 'operation_not_allowed'];
    }
    foreach (['operator_id', 'ticket_id', 'nonce', 'authorization_request_id', 'preflight_id'] as $field) {
        if (!is_string($ticket[$field]) || !preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $ticket[$field])) {
            return ['ok' => false, 'code' => $field . '_invalid', 'field' => $field];
        }
    }
    if ($ticket['audience'] !== SV_MARINA_MAINTENANCE_BROKER_AUDIENCE) {
        return ['ok' => false, 'code' => 'ticket_audience_invalid'];
    }
    if (!is_string($ticket['requested_by']) || !preg_match('/^admin:[1-9][0-9]{0,18}$/', $ticket['requested_by'])) {
        return ['ok' => false, 'code' => 'ticket_subject_invalid'];
    }
    if ($ticket['operator_id'] !== $ticket['requested_by']) {
        return ['ok' => false, 'code' => 'ticket_operator_mismatch'];
    }
    if (!is_string($ticket['preflight_digest']) || !preg_match('/^[a-f0-9]{64}$/', $ticket['preflight_digest'])) {
        return ['ok' => false, 'code' => 'preflight_digest_invalid'];
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

/** @param array<string, mixed> $preflight */
function sv_marina_validate_preflight_binding(array $preflight): array
{
    $id = $preflight['preflight_id'] ?? null;
    $digest = $preflight['preflight_digest'] ?? null;
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $id)) {
        return ['ok' => false, 'code' => 'preflight_id_invalid'];
    }
    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest)) {
        return ['ok' => false, 'code' => 'preflight_digest_invalid'];
    }
    return ['ok' => true, 'preflight' => ['preflight_id' => $id, 'preflight_digest' => $digest]];
}

/** @param array<string, mixed> $ticket @param array<string, mixed> $context @param array<string, mixed> $preflight */
function sv_marina_ticket_matches_authorization(array $ticket, array $context, array $preflight): array
{
    $contextResult = sv_marina_validate_authorization_context($context, time());
    $preflightResult = sv_marina_validate_preflight_binding($preflight);
    if (($contextResult['ok'] ?? false) !== true || ($preflightResult['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string)($contextResult['code'] ?? $preflightResult['code'])];
    }
    $expected = [
        'audience' => SV_MARINA_MAINTENANCE_BROKER_AUDIENCE,
        'authorization_request_id' => $contextResult['context']['authorization_request_id'],
        'requested_by' => $contextResult['context']['requested_by'],
        'operator_id' => $contextResult['context']['requested_by'],
        'preflight_id' => $preflightResult['preflight']['preflight_id'],
        'preflight_digest' => $preflightResult['preflight']['preflight_digest'],
    ];
    foreach ($expected as $field => $value) {
        if (($ticket[$field] ?? null) !== $value) {
            return ['ok' => false, 'code' => 'ticket_' . $field . '_mismatch'];
        }
    }
    return ['ok' => true];
}

function sv_marina_signature_is_valid_shape(string $signature): bool
{
    // Detached Ed25519 signatures are exactly 64 bytes. This only verifies
    // canonical encoding and length; the independent broker must authenticate
    // the registered signer and verify Ed25519 against the signed ticket.
    if (strlen($signature) !== 88) {
        return false;
    }
    $decoded = base64_decode($signature, true);
    return is_string($decoded)
        && strlen($decoded) === 64
        && hash_equals(base64_encode($decoded), $signature);
}

/** @param array<string, mixed> $context */
function sv_marina_validate_authorization_context(array $context, int $now): array
{
    $allowed = ['authorization_request_id', 'requested_by', 'mfa_verified_at'];
    if (array_diff(array_keys($context), $allowed) !== [] || array_diff($allowed, array_keys($context)) !== []) {
        return ['ok' => false, 'code' => 'authorization_context_invalid'];
    }
    if (!is_string($context['authorization_request_id'] ?? null)
        || !preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $context['authorization_request_id'])) {
        return ['ok' => false, 'code' => 'authorization_request_id_invalid'];
    }
    if (!is_string($context['requested_by'] ?? null) || !preg_match('/^admin:[1-9][0-9]{0,18}$/', $context['requested_by'])) {
        return ['ok' => false, 'code' => 'authorization_subject_invalid'];
    }
    $mfa = $context['mfa_verified_at'] ?? null;
    if (!is_int($mfa) || $mfa > $now || ($now - $mfa) > SV_MARINA_MAINTENANCE_MFA_MAX_AGE) {
        return ['ok' => false, 'code' => 'reauth_required'];
    }
    return ['ok' => true, 'context' => $context];
}

function sv_marina_audit_projection(mixed $value): mixed
{
    if (is_array($value)) {
        $projected = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string)$key);
            if (preg_match('/private|secret|token|signature|password|credential|ticket|envelope|nonce/', $keyString)) {
                $projected[$key] = '[REDACTED]';
            } else {
                $projected[$key] = sv_marina_audit_projection($item);
            }
        }
        return $projected;
    }

    return is_scalar($value) || $value === null ? $value : '[REDACTED]';
}
