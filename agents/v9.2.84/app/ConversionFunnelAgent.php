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
            $created = !$this->tableExists($pdo, 'sv_conversion_events');
            $this->ensureConversionTable($pdo);
            if (!$this->tableExists($pdo, 'sv_conversion_events')) {
                throw new RuntimeException('conversion_event_table_not_verified');
            }
            $actions[] = [
                'action' => 'ensure_conversion_event_table',
                'status' => $created ? 'created' : 'verified_existing',
                'verified' => true,
                'changed_items' => $created ? 1 : 0,
            ];

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

            $eventSummary = $this->statusCounts($pdo, 'sv_conversion_events', 'event_name', 20);
            $eventsLast24h = $this->countWhere($pdo, 'sv_conversion_events', '`created_at` >= UTC_TIMESTAMP() - INTERVAL 24 HOUR');
            $uniqueSessions24h = (int)$pdo->query("SELECT COUNT(DISTINCT session_hash) FROM sv_conversion_events WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR")->fetchColumn();
            $uniqueByEvent24h = $this->uniqueSessionsByEvent($pdo, 24);
            $uniqueByEvent7d = $this->uniqueSessionsByEvent($pdo, 168);
            $transitions24h = $this->transitionMetrics($pdo, 24);
            $transitions7d = $this->transitionMetrics($pdo, 168);
            $weakest24h = $this->weakestTransition($transitions24h);
            $topPaths24h = $this->topFunnelPaths($pdo, 24);

            $actions[] = [
                'action' => 'measure_client_events',
                'status' => 'verified',
                'verified' => true,
                'events_table' => 'sv_conversion_events',
                'events_last_24h' => $eventsLast24h,
                'unique_sessions_last_24h' => $uniqueSessions24h,
                'event_counts' => $eventSummary,
                'unique_sessions_by_event_last_24h' => $uniqueByEvent24h,
                'unique_sessions_by_event_last_7d' => $uniqueByEvent7d,
                'session_transitions_last_24h' => $transitions24h,
                'session_transitions_last_7d' => $transitions7d,
                'weakest_transition_last_24h' => $weakest24h,
                'top_funnel_paths_last_24h' => $topPaths24h,
                'measurement_note' => 'Transition rates use distinct first-party session hashes and only count destination events occurring at or after the source event. Purchase revenue remains server-side and is not inferred from browser events.',
                'changed_items' => 0,
            ];

            $evidence = [
                'generated_at' => date('c'),
                'schema' => $actions[0],
                'orders' => $actions[1],
                'lead_capture' => $actions[2],
                'client_events' => $actions[3],
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
            'changed_items' => array_sum(array_map(static fn(array $action): int => (int)($action['changed_items'] ?? 0), $actions)),
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

    private function ensureConversionTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sv_conversion_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            event_name VARCHAR(64) NOT NULL,
            page_path VARCHAR(255) NOT NULL,
            item_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            session_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sv_conversion_event_id (event_id),
            KEY idx_sv_conversion_event_created (event_name, created_at),
            KEY idx_sv_conversion_session_created (session_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function firstTable(PDO $pdo, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->tableExists($pdo, $candidate)) return $candidate;
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

    private function uniqueSessionsByEvent(PDO $pdo, int $hours): array
    {
        $hours = max(1, min(24 * 31, $hours));
        $sql = "SELECT event_name, COUNT(DISTINCT session_hash) AS total
                FROM sv_conversion_events
                WHERE created_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR
                GROUP BY event_name
                ORDER BY total DESC, event_name ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['event_name']] = (int)$row['total'];
        }
        return $out;
    }

    private function transitionMetrics(PDO $pdo, int $hours): array
    {
        $hours = max(1, min(24 * 31, $hours));
        $pairs = [
            ['from' => 'view_item', 'to' => 'add_to_cart'],
            ['from' => 'add_to_cart', 'to' => 'view_cart'],
            ['from' => 'add_to_cart', 'to' => 'begin_checkout'],
            ['from' => 'view_cart', 'to' => 'begin_checkout'],
            ['from' => 'begin_checkout', 'to' => 'add_shipping_info'],
            ['from' => 'begin_checkout', 'to' => 'add_payment_info'],
        ];

        $sourceSql = "SELECT COUNT(DISTINCT session_hash)
                      FROM sv_conversion_events
                      WHERE event_name = ?
                        AND created_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR";
        $transitionSql = "SELECT COUNT(DISTINCT source.session_hash)
                          FROM sv_conversion_events source
                          WHERE source.event_name = ?
                            AND source.created_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR
                            AND EXISTS (
                                SELECT 1
                                FROM sv_conversion_events destination
                                WHERE destination.session_hash = source.session_hash
                                  AND destination.event_name = ?
                                  AND destination.created_at >= source.created_at
                                  AND destination.created_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR
                            )";
        $sourceStmt = $pdo->prepare($sourceSql);
        $transitionStmt = $pdo->prepare($transitionSql);
        $out = [];

        foreach ($pairs as $pair) {
            $sourceStmt->execute([$pair['from']]);
            $sourceSessions = (int)$sourceStmt->fetchColumn();
            $transitionStmt->execute([$pair['from'], $pair['to']]);
            $continuedSessions = (int)$transitionStmt->fetchColumn();
            $rate = $sourceSessions > 0 ? round(($continuedSessions / $sourceSessions) * 100, 2) : null;

            $out[] = [
                'from' => $pair['from'],
                'to' => $pair['to'],
                'source_sessions' => $sourceSessions,
                'continued_sessions' => $continuedSessions,
                'continuation_rate_pct' => $rate,
                'dropoff_rate_pct' => $rate !== null ? round(100 - $rate, 2) : null,
            ];
        }

        return $out;
    }

    private function weakestTransition(array $transitions): ?array
    {
        $eligible = array_values(array_filter($transitions, static function (array $transition): bool {
            return (int)($transition['source_sessions'] ?? 0) >= 5
                && is_numeric($transition['continuation_rate_pct'] ?? null);
        }));
        if ($eligible === []) return null;

        usort($eligible, static function (array $a, array $b): int {
            $rateCompare = (float)$a['continuation_rate_pct'] <=> (float)$b['continuation_rate_pct'];
            if ($rateCompare !== 0) return $rateCompare;
            return (int)$b['source_sessions'] <=> (int)$a['source_sessions'];
        });

        $weakest = $eligible[0];
        return [
            'from' => (string)$weakest['from'],
            'to' => (string)$weakest['to'],
            'source_sessions' => (int)$weakest['source_sessions'],
            'continued_sessions' => (int)$weakest['continued_sessions'],
            'continuation_rate_pct' => (float)$weakest['continuation_rate_pct'],
            'dropoff_rate_pct' => (float)$weakest['dropoff_rate_pct'],
        ];
    }

    private function topFunnelPaths(PDO $pdo, int $hours): array
    {
        $hours = max(1, min(24 * 31, $hours));
        $sql = "SELECT event_name, page_path, COUNT(DISTINCT session_hash) AS unique_sessions
                FROM sv_conversion_events
                WHERE created_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR
                  AND event_name IN ('view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'add_payment_info')
                GROUP BY event_name, page_path
                ORDER BY unique_sessions DESC, event_name ASC, page_path ASC
                LIMIT 30";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $event = (string)$row['event_name'];
            $out[$event] ??= [];
            $out[$event][] = [
                'page_path' => (string)$row['page_path'],
                'unique_sessions' => (int)$row['unique_sessions'],
            ];
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
