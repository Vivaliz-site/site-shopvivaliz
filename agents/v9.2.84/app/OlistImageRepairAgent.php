<?php

declare(strict_types=1);

final class ShopvivalizOlistImageRepairAgent
{
    public function run(array $options = array()): array
    {
        $startedAt = date('c');
        $actions = array();
        $errors = array();
        $pdo = $this->pdo();

        if (!$pdo) {
            return $this->result(false, $startedAt, $actions, array(array('step' => 'pdo', 'message' => 'PDO indisponivel')));
        }

        try {
            $stats = $this->stats($pdo);
            $actions[] = array('step' => 'diagnostic_stats', 'status' => 'ok', 'data' => $stats);
            $result = $this->result(true, $startedAt, $actions, $errors);
            $this->heartbeat($pdo, 'olist_image_repair', 'ok', $result);
            return $result;
        } catch (Throwable $e) {
            $errors[] = array('step' => 'run', 'message' => $e->getMessage());
            $result = $this->result(false, $startedAt, $actions, $errors);
            $this->heartbeat($pdo, 'olist_image_repair', 'error', $result);
            return $result;
        }
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return array('ok' => $ok, 'agent' => 'olist_image_repair', 'started_at' => $startedAt, 'finished_at' => date('c'), 'actions' => $actions, 'errors' => $errors);
    }

    private function pdo(): ?PDO
    {
        foreach (array('sv_pdo', 'sv_db', 'db', 'get_pdo') as $fn) {
            if (function_exists($fn)) {
                $db = $fn();
                if ($db instanceof PDO) {
                    return $db;
                }
            }
        }
        return null;
    }

    private function stats(PDO $pdo): array
    {
        $out = array();
        $out['database_connected'] = true;
        $out['checked_at'] = date('c');
        return $out;
    }

    private function heartbeat(PDO $pdo, string $agent, string $status, array $summary): void
    {
        try {
            $payload = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(array($agent, $status, $payload));
        } catch (Throwable $ignored) {
        }
    }
}
