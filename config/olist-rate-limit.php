<?php
/**
 * Rate Limiting Handler para Olist API
 * Respeita headers X-RateLimit-* conforme documentação
 */

class OlistRateLimit {
    private static $cache_file = __DIR__ . '/../logs/olist-rate-limit.json';

    /**
     * Registra rate limit da resposta e aguarda se necessário
     */
    public static function handle_response_headers($response_headers) {
        $limit = $response_headers['x-ratelimit-limit'] ?? null;
        $remaining = $response_headers['x-ratelimit-remaining'] ?? null;
        $reset = $response_headers['x-ratelimit-reset'] ?? null;

        if (!$limit || $remaining === null || !$reset) {
            return; // Headers não presentes
        }

        // Registrar estado atual
        $state = [
            'timestamp' => date('c'),
            'limit' => (int)$limit,
            'remaining' => (int)$remaining,
            'reset' => (int)$reset,
        ];

        @mkdir(dirname(self::$cache_file), 0755, true);
        file_put_contents(self::$cache_file, json_encode($state));

        error_log("[RateLimit] Limit: {$limit}, Remaining: {$remaining}, Reset in: {$reset}s");

        // Se remaining < 10%, aguardar
        $threshold = ($limit * 0.1); // 10% do limite
        if ((int)$remaining < $threshold) {
            error_log("[RateLimit] AVISO: Apenas {$remaining} requisições restantes. Aguardando {$reset}s...");
            sleep((int)$reset + 2); // +2s de margem
        }

        // Se remaining = 0, aguardar obrigatoriamente
        if ((int)$remaining === 0) {
            error_log("[RateLimit] CRÍTICO: Limite esgotado! Aguardando {$reset}s...");
            sleep((int)$reset + 5);
        }
    }

    /**
     * Extrai headers rate limit de resposta curl
     */
    public static function extract_headers($curl_handle) {
        $headers = [];

        // Callback para capturar headers
        curl_setopt($curl_handle, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;

            $name = strtolower(trim($header[0]));
            $value = trim($header[1]);

            $headers[$name] = $value;
            return $len;
        });

        return $headers;
    }

    /**
     * Retorna status atual do rate limit
     */
    public static function get_status() {
        if (!file_exists(self::$cache_file)) {
            return ['status' => 'unknown', 'mensagem' => 'Nenhuma requisição feita ainda'];
        }

        $state = json_decode(file_get_contents(self::$cache_file), true);
        $remaining_pct = ($state['remaining'] / $state['limit']) * 100;

        return [
            'status' => $remaining_pct > 10 ? 'ok' : 'warning',
            'limit' => $state['limit'],
            'remaining' => $state['remaining'],
            'percentual' => round($remaining_pct, 1) . '%',
            'reset_in' => $state['reset'] . 's',
            'timestamp' => $state['timestamp']
        ];
    }
}

// Usar:
// $headers = OlistRateLimit::extract_headers($curl_handle);
// OlistRateLimit::handle_response_headers($headers);
?>
