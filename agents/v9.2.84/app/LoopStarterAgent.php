<?php

declare(strict_types=1);

final class ShopvivalizLoopStarterAgent
{
    public function run(array $options = []): array
    {
        $cycles = max(1, min(500, (int)($options['cycles'] ?? 3)));
        $chunkSize = max(1, min(25, (int)($options['chunk_size'] ?? 10)));
        $imageLimit = max(1, min(5000, (int)($options['image_limit'] ?? 250)));
        $pdo = $this->pdo();
        $result = [
            'ok' => false,
            'agent' => 'loop_starter',
            'generated_at' => date('c'),
            'request' => ['cycles' => $cycles, 'chunk_size' => $chunkSize, 'image_limit' => $imageLimit],
            'status' => 'not_started',
            'message' => null,
        ];

        if (!$pdo) {
            $result['ok'] = true;
            $result['status'] = 'degraded';
            $result['message'] = 'PDO indisponivel para registrar pedido de loop; fluxo local mantido.';
            return $result;
        }

        try {
            $payload = json_encode($result['request']);
            $stmt = $pdo->prepare('INSERT INTO sv_autonomous_loop_requests (source, status, payload_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute(['loop_starter', 'queued', $payload]);
            $result['ok'] = true;
            $result['status'] = 'queued';
            $result['message'] = 'Pedido de ciclo autonomo registrado para o executor residente.';
        } catch (Throwable $e) {
            $result['status'] = 'failed';
            $result['message'] = $e->getMessage();
        }

        $this->heartbeat($pdo, $result);
        return $result;
    }

    private function pdo(): ?PDO
    {
        foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $fn) {
            if (function_exists($fn)) {
                $value = $fn();
                if ($value instanceof PDO) return $value;
            }
        }
        return null;
    }

    private function heartbeat(PDO $pdo, array $result): void
    {
        try {
            $stmt = $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute(['loop_starter', $result['ok'] ? 'ok' : 'warning', json_encode($result)]);
        } catch (Throwable $ignored) {}
    }
}
