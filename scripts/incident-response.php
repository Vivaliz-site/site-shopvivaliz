<?php
/**
 * ShopVivaliz incident detector.
 *
 * This script is intentionally monitor-only. It never kills processes,
 * restarts services, changes firewall rules, performs failover, writes circuit
 * breaker flags, or changes production state. Remediation requires a reviewed
 * operational runbook and explicit human approval.
 */

declare(strict_types=1);

final class IncidentDetector
{
    private string $healthcheckUrl;

    public function __construct()
    {
        $mode = strtolower(trim((string) getenv('INCIDENT_MODE')));
        if ($mode !== '' && $mode !== 'monitor') {
            throw new RuntimeException('Only monitor mode is supported.');
        }

        $this->healthcheckUrl = trim((string) getenv('HEALTHCHECK_URL'));
        if ($this->healthcheckUrl === '') {
            $this->healthcheckUrl = 'https://shopvivaliz.com.br/api/health/version.php';
        }

        if (!filter_var($this->healthcheckUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid HEALTHCHECK_URL.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function detect(): array
    {
        $incidents = [];

        $api = $this->checkApi();
        if (!$api['healthy']) {
            $incidents[] = [
                'type' => 'api_unavailable',
                'severity' => 'HIGH',
                'evidence' => $api,
            ];
        }

        $memory = $this->readMemoryUsage();
        if ($memory !== null && $memory >= 90.0) {
            $incidents[] = [
                'type' => 'runner_memory_pressure',
                'severity' => 'MEDIUM',
                'evidence' => ['percent' => round($memory, 2)],
                'note' => 'This measures the CI runner, not the production host.',
            ];
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        if (is_array($load) && isset($load[0]) && $load[0] >= 8.0) {
            $incidents[] = [
                'type' => 'runner_load_high',
                'severity' => 'LOW',
                'evidence' => ['load_1m' => $load[0]],
                'note' => 'This measures the CI runner, not the production host.',
            ];
        }

        return $incidents;
    }

    /** @return array<string, mixed> */
    private function checkApi(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "User-Agent: ShopVivaliz-Incident-Monitor/1.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($this->healthcheckUrl, false, $context);
        $status = 0;
        $headers = $http_response_header ?? [];
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }

        $payload = is_string($body) ? json_decode($body, true) : null;
        $bodyIsHealthy = is_array($payload) && ($payload['ok'] ?? false) === true;

        return [
            'healthy' => $body !== false && $status >= 200 && $status < 400 && $bodyIsHealthy,
            'url' => $this->healthcheckUrl,
            'http_status' => $status,
            'response_received' => $body !== false,
            'response_ok' => $bodyIsHealthy,
        ];
    }

    private function readMemoryUsage(): ?float
    {
        $path = '/proc/meminfo';
        if (!is_readable($path)) {
            return null;
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', $line, $matches)) {
                $values[$matches[1]] = (float) $matches[2];
            }
        }

        if (empty($values['MemTotal']) || !isset($values['MemAvailable'])) {
            return null;
        }

        return (($values['MemTotal'] - $values['MemAvailable']) / $values['MemTotal']) * 100;
    }
}

try {
    $detector = new IncidentDetector();
    $incidents = $detector->detect();

    $report = [
        'mode' => 'monitor-only',
        'timestamp_utc' => gmdate('c'),
        'incident_count' => count($incidents),
        'incidents' => $incidents,
        'remediation_executed' => false,
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($incidents === [] ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'mode' => 'monitor-only',
        'timestamp_utc' => gmdate('c'),
        'error' => $exception->getMessage(),
        'remediation_executed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(3);
}
