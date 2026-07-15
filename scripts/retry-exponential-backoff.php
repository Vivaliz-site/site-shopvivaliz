<?php
/**
 * 🔄 Retry with Exponential Backoff - Resilência contra falhas transitórias
 * Trata: Timeouts, rate limits, temporary failures
 */
declare(strict_types=1);

class RetryExponentialBackoff {
    private $maxRetries = 5;
    private $baseDelay = 1; // segundo
    private $maxDelay = 300; // 5 minutos
    private $backoffMultiplier = 2;
    private $jitterFactor = 0.1; // 10% de variação aleatória

    public function executeWithRetry($callback, $args = []) {
        $lastException = null;
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            try {
                echo "[Attempt " . ($attempt + 1) . "/$this->maxRetries] Executando...\n";

                $result = call_user_func_array($callback, $args);

                echo "✅ Sucesso na tentativa " . ($attempt + 1) . "\n";
                return [
                    'success' => true,
                    'result' => $result,
                    'attempts' => $attempt + 1,
                ];

            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt >= $this->maxRetries) {
                    break; // Sair do loop se atingiu máximo
                }

                // Calcular delay com backoff exponencial
                $delay = $this->calculateDelay($attempt);

                echo "❌ Tentativa $attempt falhou: " . $e->getMessage() . "\n";
                echo "⏳ Aguardando {$delay}s antes da próxima tentativa...\n";

                sleep($delay);
            }
        }

        // Falhou após todas as tentativas
        echo "\n❌ FALHA PERMANENTE após $this->maxRetries tentativas\n";

        return [
            'success' => false,
            'error' => $lastException->getMessage(),
            'attempts' => $this->maxRetries,
            'exception' => $lastException,
        ];
    }

    private function calculateDelay($attempt) {
        // Fórmula: base * (multiplier ^ attempt) + jitter
        $exponentialDelay = $this->baseDelay * pow($this->backoffMultiplier, $attempt - 1);

        // Capped no máximo permitido
        $cappedDelay = min($exponentialDelay, $this->maxDelay);

        // Adicionar jitter (variação aleatória)
        $jitter = $cappedDelay * $this->jitterFactor * (rand(0, 100) / 100);
        $finalDelay = $cappedDelay + $jitter;

        return (int)$finalDelay;
    }

    public function executeWithFallback($primaryCallback, $primaryArgs, $fallbackCallback, $fallbackArgs) {
        echo "🔄 Tentando executor principal com fallback...\n";

        // Tentar executor principal
        $result = $this->executeWithRetry($primaryCallback, $primaryArgs);

        if ($result['success']) {
            return $result;
        }

        echo "\n🔄 Executor principal falhou. Acionando fallback...\n";

        // Se principal falhou, tentar fallback
        $fallbackResult = $this->executeWithRetry($fallbackCallback, $fallbackArgs);

        return [
            'success' => $fallbackResult['success'],
            'result' => $fallbackResult['result'] ?? null,
            'primary_failed' => true,
            'primary_attempts' => $result['attempts'],
            'fallback_attempts' => $fallbackResult['attempts'],
            'error' => $fallbackResult['error'] ?? $result['error'],
        ];
    }

    public function moveToDeadLetterQueue($task, $error) {
        echo "☠️ Movendo task para Dead Letter Queue: {$task['task_id']}\n";

        $dlqPath = __DIR__ . '/.dead-letter-queue.json';
        $rawDlq = is_file($dlqPath) ? file_get_contents($dlqPath) : '[]';
        $dlq = json_decode($rawDlq ?: '[]', true);
        if (!is_array($dlq)) {
            $dlq = [];
        }

        $dlq[] = [
            'task_id' => $task['task_id'],
            'action' => $task['action'],
            'error' => $error,
            'moved_at' => date('Y-m-d H:i:s'),
            'requires_investigation' => true,
        ];

        file_put_contents($dlqPath, json_encode($dlq, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);

        // Notificar admin
        if (function_exists('mail')) {
            @mail(
                'fredmourao@gmail.com',
                "[SHOPVIVALIZ DLQ] Task {$task['task_id']} falhou permanentemente",
                "Task: {$task['task_id']}\n" .
                "Ação: {$task['action']}\n" .
                "Erro: $error\n\n" .
                "Requer investigação manual.",
                'From: retry@shopvivaliz.com.br'
            );
        }
    }
}

// ============================================
// TESTES / EXEMPLOS
// ============================================

// Exemplo 1: Função que falha às vezes (simula timeout)
function unstableAPICall() {
    $random = rand(1, 10);

    if ($random < 7) {
        // 70% de chance de falhar
        throw new Exception("Simulated timeout");
    }

    return "API Response OK";
}

// Exemplo 2: Função fallback confiável
function reliableFallbackCall() {
    return "Fallback Response OK";
}

// Para executar de teste:
if (getenv('RETRY_TEST') === '1') {
    $retry = new RetryExponentialBackoff();

    echo "🧪 Testando retry com exponential backoff...\n\n";

    $result = $retry->executeWithRetry('unstableAPICall', []);

    echo "\n📊 Resultado Final:\n";
    var_dump($result);

    echo "\n\n🧪 Testando com fallback...\n\n";

    $resultWithFallback = $retry->executeWithFallback(
        'unstableAPICall',
        [],
        'reliableFallbackCall',
        []
    );

    echo "\n📊 Resultado com Fallback:\n";
    var_dump($resultWithFallback);
}
