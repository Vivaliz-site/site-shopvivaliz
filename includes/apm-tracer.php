<?php
/**
 * 📊 APM Tracer - Application Performance Monitoring
 * Rastreia requisições de ponta a ponta com traces distribuídos
 */

class APMTracer {
    private $traceId;
    private $spanId = 0;
    private $spans = [];
    private $startTime;
    private $datadog_enabled = false;

    public function __construct() {
        $this->traceId = bin2hex(random_bytes(8));
        $this->startTime = microtime(true);
        $this->datadog_enabled = (bool)getenv('DATADOG_API_KEY');

        $this->recordSpan('request.start', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'path' => $_SERVER['REQUEST_URI'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
    }

    public function recordSpan($operation, $data = []) {
        $this->spanId++;

        $span = [
            'span_id' => $this->spanId,
            'trace_id' => $this->traceId,
            'operation' => $operation,
            'timestamp' => microtime(true),
            'duration_ms' => 0,
            'data' => $data,
            'status' => 'active',
        ];

        $this->spans[] = $span;

        return $this->spanId;
    }

    public function closeSpan($spanId, $status = 'success', $error = null) {
        foreach ($this->spans as &$span) {
            if ($span['span_id'] === $spanId) {
                $span['duration_ms'] = (microtime(true) - $span['timestamp']) * 1000;
                $span['status'] = $status;
                if ($error) {
                    $span['error'] = $error;
                }
                break;
            }
        }
    }

    public function traceDatabase($query, $params = []) {
        $spanId = $this->recordSpan('database.query', [
            'query' => substr($query, 0, 200),
            'param_count' => count($params),
        ]);

        return $spanId;
    }

    public function traceAPI($method, $url) {
        return $this->recordSpan('api.call', [
            'method' => $method,
            'url' => $url,
        ]);
    }

    public function traceCPUIntensive($operation) {
        return $this->recordSpan('cpu.intensive', [
            'operation' => $operation,
        ]);
    }

    public function getTrace() {
        $totalDuration = (microtime(true) - $this->startTime) * 1000;

        // Calcular apdex (Application Performance Index)
        $satisfied = 0;
        $tolerating = 0;
        foreach ($this->spans as $span) {
            if ($span['duration_ms'] < 100) {
                $satisfied++;
            } elseif ($span['duration_ms'] < 300) {
                $tolerating++;
            }
        }

        $apdex = ($satisfied + ($tolerating / 2)) / count($this->spans);

        return [
            'trace_id' => $this->traceId,
            'total_duration_ms' => round($totalDuration, 2),
            'span_count' => count($this->spans),
            'apdex' => round($apdex, 2),
            'spans' => $this->spans,
        ];
    }

    public function sendToDatadog() {
        if (!$this->datadog_enabled) {
            return false;
        }

        $trace = $this->getTrace();

        // Formatar para Datadog APM
        $payload = json_encode([[
            'trace_id' => $trace['trace_id'],
            'spans' => array_map(fn($s) => [
                'trace_id' => $s['trace_id'],
                'span_id' => $s['span_id'],
                'name' => $s['operation'],
                'start' => $s['timestamp'] * 1e9,
                'duration' => $s['duration_ms'] * 1e6,
                'tags' => $s['data'],
            ], $trace['spans']),
        ]]);

        $apiKey = getenv('DATADOG_API_KEY');
        $site = getenv('DATADOG_SITE') ?: 'datadoghq.com';

        $ch = curl_init("https://trace.api.{$site}/v1/traces");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "DD-API-KEY: {$apiKey}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public function getMetrics() {
        $trace = $this->getTrace();

        $metrics = [
            'trace_id' => $trace['trace_id'],
            'total_time_ms' => $trace['total_duration_ms'],
            'p50' => $this->calculatePercentile(50),
            'p95' => $this->calculatePercentile(95),
            'p99' => $this->calculatePercentile(99),
            'slowest_span' => $this->getSlowestSpan(),
            'error_spans' => array_filter($this->spans, fn($s) => $s['status'] !== 'success'),
        ];

        return $metrics;
    }

    private function calculatePercentile($percentile) {
        $durations = array_map(fn($s) => $s['duration_ms'], $this->spans);
        sort($durations);

        $index = (int)((count($durations) * $percentile) / 100);
        return $durations[$index] ?? 0;
    }

    private function getSlowestSpan() {
        $slowest = null;
        $maxDuration = 0;

        foreach ($this->spans as $span) {
            if ($span['duration_ms'] > $maxDuration) {
                $maxDuration = $span['duration_ms'];
                $slowest = $span;
            }
        }

        return $slowest ? [
            'operation' => $slowest['operation'],
            'duration_ms' => $slowest['duration_ms'],
        ] : null;
    }

    public function logToFile() {
        $metrics = $this->getMetrics();

        $log = "[" . date('Y-m-d H:i:s') . "] " .
               "Trace: {$metrics['trace_id']} | " .
               "Total: {$metrics['total_time_ms']}ms | " .
               "P95: {$metrics['p95']}ms | " .
               "Slowest: {$metrics['slowest_span']['operation']} ({$metrics['slowest_span']['duration_ms']}ms)\n";

        file_put_contents('.apm-traces.log', $log, FILE_APPEND);
    }
}

// Inicializar globalmente
$GLOBALS['apm'] = new APMTracer();

// Hook para rastrear execução de scripts
register_shutdown_function(function() {
    $apm = $GLOBALS['apm'] ?? null;
    if ($apm) {
        $apm->logToFile();

        // Enviar para Datadog se configurado
        if (getenv('DATADOG_API_KEY')) {
            $apm->sendToDatadog();
        }
    }
});
