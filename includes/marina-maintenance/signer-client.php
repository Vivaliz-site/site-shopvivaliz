<?php

declare(strict_types=1);

require_once __DIR__ . '/contract.php';

/**
 * Client for the independently operated signing service.
 *
 * It never generates, stores, accepts, or returns a private key. Ticket and
 * signature are intentionally kept inside the PHP-to-broker request path.
 */
final class SvMarinaMaintenanceSignerClient
{
    /** @var callable|null */
    private $transport;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?callable $transport = null, ?array $config = null)
    {
        $this->transport = $transport;
        $this->config = $config ?? [
            'signer_url' => getenv('MARINA_MAINTENANCE_SIGNER_URL') ?: '',
            'private_route' => getenv('MARINA_MAINTENANCE_SIGNER_PRIVATE_ROUTE') ?: '',
            'audience' => getenv('MARINA_MAINTENANCE_SIGNER_AUDIENCE') ?: '',
            'transport_identity' => getenv('MARINA_MAINTENANCE_SIGNER_TRANSPORT_ID') ?: '',
            'ca_file' => getenv('MARINA_MAINTENANCE_SIGNER_CA_FILE') ?: '',
            'client_cert' => getenv('MARINA_MAINTENANCE_SIGNER_CLIENT_CERT') ?: '',
            'client_key' => getenv('MARINA_MAINTENANCE_SIGNER_CLIENT_KEY') ?: '',
            'spki_pin' => getenv('MARINA_MAINTENANCE_SIGNER_SPKI_PIN') ?: '',
        ];
    }

    public function status(): array
    {
        return $this->request('GET', '/v1/maintenance/signer/status', [], false);
    }

    /**
     * @param array<string, mixed> $context Server-derived authentication context.
     * @param array<string, mixed> $preflight Broker preflight, never browser input.
     */
    public function issue(array $context, array $preflight): array
    {
        $contextResult = sv_marina_validate_authorization_context($context, time());
        if (($contextResult['ok'] ?? false) !== true) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)$contextResult['code']];
        }
        $preflightResult = sv_marina_validate_preflight_binding($preflight);
        if (($preflightResult['ok'] ?? false) !== true) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)$preflightResult['code']];
        }

        $issued = $this->request('POST', '/v1/maintenance/tickets', [
            'operation' => sv_marina_maintenance_operation(),
            'audience' => SV_MARINA_MAINTENANCE_BROKER_AUDIENCE,
            'authorization_request_id' => $contextResult['context']['authorization_request_id'],
            'requested_by' => $contextResult['context']['requested_by'],
            'operator_id' => $contextResult['context']['requested_by'],
            'mfa_verified_at' => $contextResult['context']['mfa_verified_at'],
            ...$preflightResult['preflight'],
        ], true);
        if (($issued['ok'] ?? false) !== true || !is_array($issued['ticket'] ?? null)) {
            return $issued;
        }
        $match = sv_marina_ticket_matches_authorization($issued['ticket'], $contextResult['context'], $preflightResult['preflight']);
        if (($match['ok'] ?? false) !== true) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)($match['code'] ?? 'signer_ticket_binding_invalid')];
        }
        return $issued;
    }

    private function request(string $method, string $path, array $payload, bool $issuedTicket): array
    {
        $gate = $this->configurationGate();
        if ($gate !== null) {
            return $gate;
        }
        $transport = is_callable($this->transport) ? $this->transport : $this->defaultTransport();
        try {
            $response = $transport($method, $path, $payload, $this->config);
        } catch (Throwable $e) {
            return ['ok' => false, 'state' => 'INDETERMINATE_INSPECTION_REQUIRED', 'code' => 'signer_transport_error'];
        }
        return $this->normalizeResponse($response, $issuedTicket);
    }

    private function configurationGate(): ?array
    {
        $url = trim((string)($this->config['signer_url'] ?? ''));
        $route = trim((string)($this->config['private_route'] ?? ''));
        $audience = trim((string)($this->config['audience'] ?? ''));
        $identity = trim((string)($this->config['transport_identity'] ?? ''));
        $pin = trim((string)($this->config['spki_pin'] ?? ''));
        if ($url === '' || $route === '' || $audience === '' || $identity === '' || $pin === '') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_not_provisioned'];
        }
        if ($audience !== 'marina-maintenance-signer') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_audience_invalid'];
        }
        if (!preg_match('/^(vcn-|private-|unix:)/', $route)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_private_route_required'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_url_invalid'];
        }
        $host = strtolower((string)$parts['host']);
        if (!preg_match('/(?:\.internal|\.svc)$/', $host)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_private_hostname_required'];
        }
        if ($identity !== 'spiffe://shopvivaliz/maintenance/signer-v1') {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_transport_identity_required'];
        }
        if (!preg_match('#^sha256//[A-Za-z0-9+/]{43}=?$#', $pin)) {
            return ['ok' => false, 'state' => 'NAO_APTO', 'code' => 'signer_spki_pin_required'];
        }
        return null;
    }

    private function normalizeResponse(mixed $response, bool $issuedTicket): array
    {
        if (!is_array($response) || !is_string($response['state'] ?? null)) {
            return ['ok' => false, 'state' => 'UNKNOWN', 'code' => 'signer_response_invalid'];
        }
        if (!$issuedTicket) {
            if (!in_array($response['state'], ['READY', 'NAO_APTO', 'REFUSED', 'UNKNOWN'], true)) {
                return ['ok' => false, 'state' => 'UNKNOWN', 'code' => 'signer_state_invalid'];
            }
            return ['ok' => $response['state'] === 'READY', 'state' => $response['state'], 'code' => (string)($response['code'] ?? '')];
        }
        if ($response['state'] !== 'TICKET_ISSUED') {
            return ['ok' => false, 'state' => (string)$response['state'], 'code' => (string)($response['code'] ?? 'signer_refused')];
        }
        $ticket = is_array($response['ticket'] ?? null) ? $response['ticket'] : [];
        $signature = (string)($response['signature_b64'] ?? '');
        $validation = sv_marina_validate_ticket($ticket, time());
        if (($validation['ok'] ?? false) !== true || !sv_marina_signature_is_valid_shape($signature)) {
            return ['ok' => false, 'state' => 'REFUSED', 'code' => (string)($validation['code'] ?? 'signer_ticket_invalid')];
        }
        // This is internal-only and must be consumed immediately by the broker.
        return ['ok' => true, 'state' => 'TICKET_ISSUED', 'ticket' => $validation['ticket'], 'signature_b64' => $signature];
    }

    private function defaultTransport(): callable
    {
        return static function (string $method, string $path, array $payload, array $config): array {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('signer_curl_unavailable');
            }
            foreach (['ca_file', 'client_cert', 'client_key'] as $material) {
                if (!is_file((string)($config[$material] ?? ''))) {
                    throw new RuntimeException('signer_transport_material_unavailable');
                }
            }
            $url = rtrim((string)$config['signer_url'], '/') . $path . ($method === 'GET' && $payload !== [] ? '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : '');
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('signer_curl_init_failed');
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json; charset=utf-8',
                    'X-Marina-Signer-Audience: ' . (string)$config['audience'],
                    'X-Marina-Transport-Identity: ' . (string)$config['transport_identity'],
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => (string)$config['ca_file'],
                CURLOPT_SSLCERT => (string)$config['client_cert'],
                CURLOPT_SSLKEY => (string)$config['client_key'],
                CURLOPT_PINNEDPUBLICKEY => (string)$config['spki_pin'],
            ]);
            if ($method !== 'GET') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
                throw new RuntimeException('signer_http_error');
            }
            $decoded = json_decode((string)$body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('signer_json_invalid');
            }
            return $decoded;
        };
    }
}
