<?php
declare(strict_types=1);

function svas_root(): string
{
    return dirname(__DIR__, 2);
}

function svas_path(string $rel): string
{
    return svas_root() . '/' . ltrim($rel, '/');
}

function svas_json(string $rel): array
{
    $path = svas_path($rel);
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function svas_bool_env(string $key): bool
{
    $value = strtolower(trim((string)(getenv($key) ?: '')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function svas_parse_iso(?string $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') return null;

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

function svas_cycle_status(): array
{
    $path = svas_path('scripts/autonomous-cycle-log.json');
    $maintenanceEnv = svas_bool_env('AUTONOMOUS_MAINTENANCE');
    $default = [
        'is_running' => false,
        'last_cycle_seconds_ago' => null,
        'last_cycle_at' => null,
        'incident_key' => null,
        'status' => 'critical',
        'maintenance_window' => $maintenanceEnv,
        'warning_after_seconds' => 300,
        'critical_after_seconds' => 900,
        'source' => 'scripts/autonomous-cycle-log.json',
        'details' => file_exists($path) ? 'invalid_cycle_log' : 'cycle_log_missing',
    ];

    if (!is_file($path)) {
        return $default;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        $default['details'] = 'cycle_log_unreadable';
        return $default;
    }

    $lastCycle = trim((string)($decoded['last_cycle_at'] ?? $decoded['generated_at'] ?? ''));
    $maintenance = $maintenanceEnv || !empty($decoded['maintenance_window']);
    $warningAfter = max(60, (int)($decoded['warning_after_seconds'] ?? 300));
    $criticalAfter = max($warningAfter + 1, (int)($decoded['critical_after_seconds'] ?? 900));
    $parsed = svas_parse_iso($lastCycle);
    if ($parsed === null) {
        $default['maintenance_window'] = $maintenance;
        $default['warning_after_seconds'] = $warningAfter;
        $default['critical_after_seconds'] = $criticalAfter;
        $default['details'] = 'cycle_log_missing_timestamp';
        return $default;
    }

    $secondsAgo = max(0, time() - $parsed->getTimestamp());
    $status = 'healthy';
    $isRunning = true;
    $details = 'cycle_recent';

    if ($secondsAgo > $criticalAfter && !$maintenance) {
        $status = 'critical';
        $isRunning = false;
        $details = 'cycle_stale';
    } elseif ($secondsAgo > $warningAfter && !$maintenance) {
        $status = 'warning';
        $details = 'cycle_slow';
    } elseif ($maintenance) {
        $status = 'warning';
        $details = 'maintenance_window';
    }

    return [
        'is_running' => $isRunning,
        'last_cycle_seconds_ago' => $secondsAgo,
        'last_cycle_at' => $lastCycle,
        'incident_key' => $lastCycle,
        'status' => $status,
        'maintenance_window' => $maintenance,
        'warning_after_seconds' => $warningAfter,
        'critical_after_seconds' => $criticalAfter,
        'source' => 'scripts/autonomous-cycle-log.json',
        'details' => $details,
    ];
}

function svas_self_healing_log_path(): string
{
    return svas_path('logs/autonomous-self-healing.jsonl');
}

function svas_self_healing_state(?string $incidentKey = null): array
{
    $path = svas_self_healing_log_path();
    $records = [];
    if (is_file($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
    }

    if ($incidentKey !== null && $incidentKey !== '') {
        $records = array_values(array_filter(
            $records,
            static fn(array $record): bool => (string)($record['incident_key'] ?? '') === $incidentKey
        ));
    }

    $attempts = count($records);
    $last = $attempts > 0 ? $records[$attempts - 1] : null;

    return [
        'attempts' => $attempts,
        'last_attempt' => $last['finished_at'] ?? $last['started_at'] ?? null,
        'status' => $last['status'] ?? 'none',
        'last_result' => $last['message'] ?? null,
        'last_exit_code' => $last['exit_code'] ?? null,
        'incident_key' => $incidentKey,
    ];
}

function svas_append_self_healing_attempt(array $record): void
{
    $path = svas_self_healing_log_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
