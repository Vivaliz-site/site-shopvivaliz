<?php

declare(strict_types=1);

require_once __DIR__ . '/contract.php';

final class SvMarinaMaintenanceBrokerClient
{
    /** @var callable|null */
    private $transport;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?callable $transport = null, ?array $config = null)
    {
        $this->transport = $transport;
        $this->config = $config ?? [
            'broker_url' => getenv('MARINA_MAINTENANCE_BROKER_URL') ?: '',
            'private_route' => getenv('MARINA_MAINTENANCE_PRIVATE_ROUTE') ?: '',
            'audience' => getenv('MARINA_MAINTENANCE_BROKER_AUDIENCE') ?: '',
        ];
    }

    public function status(): array
    {
        return $this->request('GET', '/v1/maintenance/status', []);
    }

    public function preview(): array
    {
        return $this->request('POST', '/v1/maintenance/preview', [
            'operation' => sv_marina_maintenance_operation(),
        ]);
    }

    public function approve(array $ticket, string $signature): array
    {
        $validation = sv_marina_validate_ticket($ticket, time());
        if (($validation['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'state' => 'REFUSED',
                'code' => (string)($validation['code'] ?? 'ticket_invalid'),
            ];
        }
        if (!sv_marina_signature_is_valid_shape($signature)) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => 'signature_invalid'];
        }

        return $this->request('POST', '/v1/maintenance/approve', [
            'operation' => sv_marina_maintenance_operation(),
            'ticket' => $validation['ticket'],
            'signature_b64' => $signature,
        ]);
    }

    public function operation(string $operationId): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $operationId)) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => 'operation_id_invalid'];
        }
        return $this->request('GET', '/v1/maintenance/operations/' . rawurlencode($operationId), []);
    }

    private function request(string $method, string $path, array $payload): array
    {
        $gate = $this->configurationGate();
        if ($gate !== null) {
            return $gate;
        }
        $transport = is_callable($this->transport) ? $this->transport : $this->defaultTransport();

        try {
            $response = $transport($method, $path, $payload, $this->config);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'state' => 'INDETERMINATE_INSPECTION_REQUIRED',
                'code' => 'broker_transport_error',
            ];
        }

        return $this->normalizeResponse($response);
    }

    private function configurationGate(): ?array
    {
        $url = trim((string)($this->config['broker_url'] ?? ''));
        $route = trim((string)($this->config['private_route'] ?? ''));
        $audience = trim((string)($this->config['audience'] ?? ''));
        if ($url === '' || $route === '' || $audience === '') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_not_provisioned'];
        }
        if (!preg_match('/^(vcn-|private-|unix:)/', $route)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'private_route_required'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_url_invalid'];
        }
        $host = (string)$parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'public_route_forbidden'];
        }
        return null;
    }

    private function normalizeResponse(mixed $response): array
    {
        if (!is_array($response) || !is_string($response['state'] ?? null)) {
            return ['ok' => false, 'state' => 'UNKNOWN', 'code' => 'broker_response_invalid'];
        }
        $allowedStates = [
            'READY_FOR_APPROVAL',
            'APPROVAL_REQUIRED',
            'RUNNING',
            'SUCCEEDED_RECONCILED',
            'REFUSED',
            'INDETERMINATE_INSPECTION_REQUIRED',
            'NAO_APTO',
            'UNKNOWN',
        ];
        if (!in_array($response['state'], $allowedStates, true)) {
            return ['ok' => false, 'state' => 'UNKNOWN', 'code' => 'broker_state_invalid'];
        }

        $safe = ['ok' => $response['state'] === 'SUCCEEDED_RECONCILED', 'state' => $response['state']];
        foreach (['code', 'operation_id', 'preview_id', 'audit_id', 'expires_at', 'scope', 'preflight', 'freshness'] as $key) {
            if (array_key_exists($key, $response)) {
                $safe[$key] = sv_marina_audit_projection($response[$key]);
            }
        }
        return $safe;
    }

    /**
     * The production path is the official broker over its private route.
     * No host command, SSH fallback, or direct scheduler control is permitted.
     * @return callable(string, string, array, array): array
     */
    private function defaultTransport(): callable
    {
        return static function (string $method, string $path, array $payload, array $config): array {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('broker_curl_unavailable');
            }
            $baseUrl = rtrim((string)($config['broker_url'] ?? ''), '/');
            $url = $baseUrl . $path;
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
                'X-Marina-Broker-Audience: ' . (string)($config['audience'] ?? ''),
                'X-Marina-Private-Route: ' . (string)($config['private_route'] ?? ''),
            ];
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('broker_curl_init_failed');
            }
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($method !== 'GET') {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
                throw new RuntimeException('broker_http_error');
            }
            $decoded = json_decode((string)$body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('broker_json_invalid');
            }
            return $decoded;
        };
    }
}
