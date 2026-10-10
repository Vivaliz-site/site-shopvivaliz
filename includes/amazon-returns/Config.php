<?php

declare(strict_types=1);

final class SvAmazonReturnsConfig
{
    /** @param array<string,string> $override */
    public function __construct(private array $override = []) {}

    public function enabled(): bool { return $this->bool('AMAZON_RETURNS_ENABLED', false); }

    public function mode(): string
    {
        $mode = strtolower(trim($this->get('AMAZON_RETURNS_MODE', 'dry-run')));
        return in_array($mode, ['development','dry-run','shadow','production'], true) ? $mode : 'dry-run';
    }

    public function flag(string $name): bool
    {
        $map = [
            'gmail_ingest' => 'AMAZON_RETURNS_GMAIL_INGEST',
            'safe_t_write' => 'AMAZON_RETURNS_SAFE_T_WRITE',
            'appeal_write' => 'AMAZON_RETURNS_APPEAL_WRITE',
            'support_write' => 'AMAZON_RETURNS_SUPPORT_WRITE',
            'policy_monitor' => 'AMAZON_RETURNS_POLICY_MONITOR',
        ];
        return isset($map[$name]) ? $this->bool($map[$name], false) : false;
    }

    public function externalWriteAllowed(string $action): bool
    {
        if (!$this->enabled() || $this->mode() !== 'production') return false;
        return match (strtoupper(trim($action))) {
            'SAFE_T_SUBMIT' => $this->flag('safe_t_write'),
            'SAFE_T_APPEAL' => $this->flag('appeal_write'),
            'SELLER_SUPPORT_OPEN', 'SELLER_SUPPORT_UPDATE' => $this->flag('support_write'),
            default => false,
        };
    }

    public function sellerCentralBridgeMode(): string
    {
        if ($this->get('SELLER_CENTRAL_BROWSER_BRIDGE_URL') !== '') return 'direct';
        if ($this->get('SELLER_CENTRAL_BRIDGE_TOKEN') !== '') return 'polling';
        return 'unavailable';
    }

    /** @return array<string,array{ready:bool,missing:list<string>}> */
    public function readiness(): array
    {
        $bridgeReady = $this->sellerCentralBridgeMode() !== 'unavailable';
        return [
            'sp_api' => $this->requirements(['AMAZON_LWA_CLIENT_ID','AMAZON_LWA_CLIENT_SECRET','AMAZON_LWA_REFRESH_TOKEN']),
            // Completeness only; authorization and API scopes require a live probe.
            'gmail' => $this->gmailOAuthCredentials() !== null
                ? ['ready'=>true,'missing'=>[]]
                : ['ready'=>false,'missing'=>['GMAIL_OAUTH_*|GOOGLE_OAUTH_* (complete family)']],
            'seller_central_bridge' => $bridgeReady
                ? ['ready'=>true,'missing'=>[]]
                : ['ready'=>false,'missing'=>['SELLER_CENTRAL_BROWSER_BRIDGE_URL|SELLER_CENTRAL_BRIDGE_TOKEN']],
        ];
    }

    /** @return array<string,bool> */
    public function writeFlags(): array
    {
        return [
            'SAFE_T_SUBMIT' => $this->externalWriteAllowed('SAFE_T_SUBMIT'),
            'SAFE_T_APPEAL' => $this->externalWriteAllowed('SAFE_T_APPEAL'),
            'SELLER_SUPPORT_OPEN' => $this->externalWriteAllowed('SELLER_SUPPORT_OPEN'),
            'SELLER_SUPPORT_UPDATE' => $this->externalWriteAllowed('SELLER_SUPPORT_UPDATE'),
        ];
    }

    public function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->override)) return trim((string)$this->override[$key]);
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public function first(string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== '') return $value;
        }
        return '';
    }

    /**
     * Select one complete OAuth credential family; never mix client ID/secret
     * and refresh token from different grants. A partially configured Gmail
     * family must not shadow a complete, independent Google family.
     *
     * @return array{client_id:string,client_secret:string,refresh_token:string}|null
     */
    public function gmailOAuthCredentials(): ?array
    {
        foreach (['GMAIL_OAUTH', 'GOOGLE_OAUTH'] as $prefix) {
            $clientId = $this->get($prefix . '_CLIENT_ID');
            $clientSecret = $this->get($prefix . '_CLIENT_SECRET');
            $refreshToken = $this->get($prefix . '_REFRESH_TOKEN');
            if ($clientId !== '' && $clientSecret !== '' && $refreshToken !== '') {
                return [
                    'client_id'=>$clientId,
                    'client_secret'=>$clientSecret,
                    'refresh_token'=>$refreshToken,
                ];
            }
        }
        return null;
    }

    private function bool(string $key, bool $default): bool
    {
        $raw = strtolower($this->get($key, $default ? '1' : '0'));
        return in_array($raw, ['1','true','yes','on'], true);
    }

    /** @param list<string> $keys @return array{ready:bool,missing:list<string>} */
    private function requirements(array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($this->get($key) === '') $missing[] = $key;
        }
        return ['ready'=>$missing === [], 'missing'=>$missing];
    }

}
