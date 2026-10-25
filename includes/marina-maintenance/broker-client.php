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
            'transport_identity' => getenv('MARINA_MAINTENANCE_BROKER_TRANSPORT_ID') ?: '',
            'ca_file' => getenv('MARINA_MAINTENANCE_BROKER_CA_FILE') ?: '',
            'client_cert' => getenv('MARINA_MAINTENANCE_BROKER_CLIENT_CERT') ?: '',
            'client_key' => getenv('MARINA_MAINTENANCE_BROKER_CLIENT_KEY') ?: '',
            'spki_pin' => getenv('MARINA_MAINTENANCE_BROKER_SPKI_PIN') ?: '',
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

    /** @param array<string, mixed> $context */
    public function preflight(array $context): array
    {
        $contextResult = sv_marina_validate_authorization_context($context, time());
        if (($contextResult['ok'] ?? false) !== true) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)$contextResult['code']];
        }
        return $this->request('POST', '/v1/maintenance/preflight', [
            'operation' => sv_marina_maintenance_operation(),
            ...$contextResult['context'],
        ]);
    }

    /** @param array<string, mixed> $ticket @param array<string, mixed> $context @param array<string, mixed> $preflight */
    public function authorize(array $ticket, string $signature, array $context, array $preflight): array
    {
        $validation = sv_marina_validate_ticket($ticket, time());
        $contextResult = sv_marina_validate_authorization_context($context, time());
        $binding = sv_marina_validate_preflight_binding($preflight);
        $match = (($validation['ok'] ?? false) && ($contextResult['ok'] ?? false) && ($binding['ok'] ?? false))
            ? sv_marina_ticket_matches_authorization($validation['ticket'], $contextResult['context'], $binding['preflight']) : ['ok' => false];
        if (($validation['ok'] ?? false) !== true || ($contextResult['ok'] ?? false) !== true || ($binding['ok'] ?? false) !== true || ($match['ok'] ?? false) !== true || !sv_marina_signature_is_valid_shape($signature)) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)($validation['code'] ?? $contextResult['code'] ?? $binding['code'] ?? $match['code'] ?? 'signature_invalid')];
        }
        return $this->request('POST', '/v1/maintenance/authorize', [
            'operation' => sv_marina_maintenance_operation(),
            'ticket' => $validation['ticket'],
            'signature_b64' => $signature,
            'preflight_id' => $binding['preflight']['preflight_id'],
            'preflight_digest' => $binding['preflight']['preflight_digest'],
            // The broker atomically reserves this signed key with the ticket nonce.
            'idempotency_key' => $contextResult['context']['authorization_request_id'],
            ...$contextResult['context'],
        ]);
    }

    /** @param array<string, mixed> $context */
    public function operation(string $operationId, array $context): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $operationId)) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => 'operation_id_invalid'];
        }
        $contextResult = sv_marina_validate_authorization_context($context, time());
        if (($contextResult['ok'] ?? false) !== true) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)$contextResult['code']];
        }
        return $this->request('GET', '/v1/maintenance/operations/' . rawurlencode($operationId), $contextResult['context']);
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
        $identity = trim((string)($this->config['transport_identity'] ?? ''));
        $pin = trim((string)($this->config['spki_pin'] ?? ''));
        if ($url === '' || $route === '' || $audience === '' || $identity === '' || $pin === '') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_not_provisioned'];
        }
        if ($audience !== SV_MARINA_MAINTENANCE_BROKER_AUDIENCE) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_audience_invalid'];
        }
        if (!preg_match('/^(vcn-|private-|unix:)/', $route)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'private_route_required'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_url_invalid'];
        }
        $host = strtolower((string)$parts['host']);
        if (!preg_match('/(?:\.internal|\.svc)$/', $host)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_private_hostname_required'];
        }
        if ($identity !== 'spiffe://shopvivaliz/maintenance/broker-v1') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_transport_identity_required'];
        }
        if (!preg_match('#^sha256//[A-Za-z0-9+/]{43}=?$#', $pin)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'broker_spki_pin_required'];
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
        foreach (['code', 'operation_id', 'preview_id', 'preflight_id', 'preflight_digest', 'audit_id', 'expires_at', 'scope', 'freshness', 'signer'] as $key) {
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
            foreach (['ca_file', 'client_cert', 'client_key'] as $material) {
                if (!is_file((string)($config[$material] ?? ''))) {
                    throw new RuntimeException('broker_transport_material_unavailable');
                }
            }
            $baseUrl = rtrim((string)($config['broker_url'] ?? ''), '/');
            $url = $baseUrl . $path . ($method === 'GET' && $payload !== [] ? '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : '');
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
                'X-Marina-Broker-Audience: ' . (string)($config['audience'] ?? ''),
                'X-Marina-Transport-Identity: ' . (string)($config['transport_identity'] ?? ''),
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
                CURLOPT_CAINFO => (string)$config['ca_file'],
                CURLOPT_SSLCERT => (string)$config['client_cert'],
                CURLOPT_SSLKEY => (string)$config['client_key'],
                CURLOPT_PINNEDPUBLICKEY => (string)$config['spki_pin'],
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
