<?php

declare(strict_types=1);

final class ShopvivalizConversionFunnelAgent
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root !== null ? rtrim($root, '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $pdo = $this->pdo();
        $actions = [];
        $errors = [];

        if (!$pdo) {
            return $this->result(false, $startedAt, [], [['error' => 'pdo_unavailable']]);
        }

        try {
            $ordersTable = $this->firstTable($pdo, ['orders', 'pedidos', 'sv_orders', 'customer_orders']);
            if ($ordersTable === null) {
                throw new RuntimeException('orders_table_not_found');
            }

            $columns = $this->columns($pdo, $ordersTable);
            $statusColumn = $this->firstColumn($columns, ['status', 'order_status', 'situacao', 'payment_status']);
            $createdColumn = $this->firstColumn($columns, ['created_at', 'date_created', 'data_criacao', 'created']);

            $total = $this->count($pdo, $ordersTable);
            $last24h = $createdColumn !== null
                ? $this->countWhere($pdo, $ordersTable, $this->identifier($createdColumn) . ' >= UTC_TIMESTAMP() - INTERVAL 24 HOUR')
                : null;
            $last7d = $createdColumn !== null
                ? $this->countWhere($pdo, $ordersTable, $this->identifier($createdColumn) . ' >= UTC_TIMESTAMP() - INTERVAL 7 DAY')
                : null;
            $statuses = $statusColumn !== null ? $this->statusCounts($pdo, $ordersTable, $statusColumn) : [];

            $actions[] = [
                'action' => 'measure_order_funnel',
                'status' => 'verified',
                'verified' => true,
                'orders_table' => $ordersTable,
                'orders_total' => $total,
                'orders_last_24h' => $last24h,
                'orders_last_7d' => $last7d,
                'status_counts' => $statuses,
                'changed_items' => 0,
            ];

            $newsletterTable = $this->firstTable($pdo, ['newsletter_subscribers', 'newsletter_subscriptions', 'subscribers', 'email_subscribers']);
            $newsletterTotal = $newsletterTable !== null ? $this->count($pdo, $newsletterTable) : null;
            $actions[] = [
                'action' => 'measure_lead_capture',
                'status' => $newsletterTable !== null ? 'verified' : 'verified_not_configured',
                'verified' => true,
                'newsletter_table' => $newsletterTable,
                'subscribers_total' => $newsletterTotal,
                'changed_items' => 0,
            ];

            $eventTable = $this->firstTable($pdo, ['analytics_events', 'conversion_events', 'funnel_events', 'tracking_events']);
            $eventSummary = [];
            if ($eventTable !== null) {
                $eventColumns = $this->columns($pdo, $eventTable);
                $eventNameColumn = $this->firstColumn($eventColumns, ['event_name', 'event', 'name', 'type']);
                if ($eventNameColumn !== null) {
                    $eventSummary = $this->statusCounts($pdo, $eventTable, $eventNameColumn, 20);
                }
            }
            $actions[] = [
                'action' => 'measure_client_events',
                'status' => $eventTable !== null ? 'verified' : 'verified_not_configured',
                'verified' => true,
                'events_table' => $eventTable,
                'event_counts' => $eventSummary,
                'changed_items' => 0,
            ];

            $evidence = [
                'generated_at' => date('c'),
                'orders' => $actions[0],
                'lead_capture' => $actions[1],
                'client_events' => $actions[2],
                'contains_personal_data' => false,
            ];
            $this->persist($evidence);
        } catch (Throwable $e) {
            $errors[] = ['action' => 'conversion_funnel', 'error' => $e->getMessage()];
        }

        return $this->result($errors === [], $startedAt, $actions, $errors);
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return [
            'ok' => $ok,
            'agent' => 'conversion_funnel',
            'execution_status' => $ok ? 'completed' : 'failed',
            'execution_completed' => $ok,
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'evidence_count' => count($actions),
            'changed_items' => 0,
            'actions' => $actions,
            'errors' => $errors,
        ];
    }

    private function pdo(): ?PDO
    {
        foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $function) {
            if (function_exists($function)) {
                $value = $function();
                if ($value instanceof PDO) return $value;
            }
        }
        return null;
    }

    private function firstTable(PDO $pdo, array $candidates): ?string
    {
        $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        foreach ($candidates as $candidate) {
            $stmt->execute([$candidate]);
            $table = $stmt->fetchColumn();
            if (is_string($table) && $table !== '') return $table;
        }
        return null;
    }

    private function columns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) return $candidate;
        }
        return null;
    }

    private function count(PDO $pdo, string $table): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM ' . $this->identifier($table))->fetchColumn();
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM ' . $this->identifier($table) . ' WHERE ' . $where)->fetchColumn();
    }

    private function statusCounts(PDO $pdo, string $table, string $column, int $limit = 30): array
    {
        $tableQ = $this->identifier($table);
        $columnQ = $this->identifier($column);
        $sql = "SELECT COALESCE(NULLIF(TRIM({$columnQ}), ''), '(empty)') AS bucket, COUNT(*) AS total FROM {$tableQ} GROUP BY bucket ORDER BY total DESC LIMIT " . max(1, min(100, $limit));
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['bucket']] = (int)$row['total'];
        }
        return $out;
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) throw new InvalidArgumentException('invalid_identifier');
        return '`' . $value . '`';
    }

    private function persist(array $evidence): void
    {
        $directory = $this->root . '/storage/agent-evidence';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('conversion_evidence_directory_unavailable');
        }
        $path = $directory . '/latest-conversion-funnel.json';
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('conversion_evidence_write_failed');
        }
        $readback = json_decode((string)file_get_contents($path), true);
        if (!is_array($readback) || ($readback['contains_personal_data'] ?? null) !== false) {
            throw new RuntimeException('conversion_evidence_readback_failed');
        }
    }
}
