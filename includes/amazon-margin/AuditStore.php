<?php
declare(strict_types=1);

final class SvAmazonMarginStore
{
    public static function ensureSchema(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS amazon_margin_audit_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(20) NOT NULL,
            skus_json LONGTEXT NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'queued',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            error_code VARCHAR(90) NULL,
            KEY margin_request_queue (state, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS amazon_margin_audit_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NULL,
            scope VARCHAR(20) NOT NULL,
            generated_at DATETIME NOT NULL,
            offer_count INT NOT NULL DEFAULT 0,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY margin_run_recency (id),
            KEY margin_run_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @param list<string> $skus */
    public static function request(PDO $db, string $scope, array $skus): int
    {
        if (!in_array($scope, ['selected','all'], true)) throw new InvalidArgumentException('invalid_scope');
        $skus = array_values(array_unique(array_map('trim', $skus)));
        if ($scope === 'selected' && (count($skus) < 1 || count($skus) > 100)) {
            throw new InvalidArgumentException('selected_sku_count_invalid');
        }
        if ($scope === 'all') $skus = [];
        foreach ($skus as $sku) {
            if ($sku === '' || strlen($sku) > 80) throw new InvalidArgumentException('invalid_seller_sku');
        }
        self::ensureSchema($db);
        $s = $db->prepare('INSERT INTO amazon_margin_audit_requests(scope,skus_json) VALUES (?,?)');
        $s->execute([$scope, json_encode($skus, JSON_THROW_ON_ERROR)]);
        return (int)$db->lastInsertId();
    }

    public static function next(PDO $db): ?array
    {
        self::ensureSchema($db);
        $db->beginTransaction();
        try {
            $stmt = $db->query("SELECT id,scope,skus_json FROM amazon_margin_audit_requests
                WHERE state = 'queued' ORDER BY id LIMIT 1 FOR UPDATE");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $db->commit(); return null; }
            $upd = $db->prepare("UPDATE amazon_margin_audit_requests
                SET state='running', started_at=UTC_TIMESTAMP() WHERE id=? AND state='queued'");
            $upd->execute([$row['id']]);
            $db->commit();
            $row['skus'] = json_decode((string)$row['skus_json'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function complete(PDO $db, int $requestId, string $scope, array $snapshot): int
    {
        $payload = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO amazon_margin_audit_runs
            (request_id,scope,generated_at,offer_count,payload_json) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)");
        $stmt->execute([$requestId ?: null, $scope, count($snapshot['rows'] ?? []), $payload]);
        $runId = (int)$db->lastInsertId();
        if ($requestId > 0) {
            $upd = $db->prepare("UPDATE amazon_margin_audit_requests SET state='done', finished_at=UTC_TIMESTAMP() WHERE id=?");
            $upd->execute([$requestId]);
        }
        return $runId;
    }

    public static function fail(PDO $db, int $requestId, string $code): void
    {
        if ($requestId <= 0) return;
        $upd = $db->prepare("UPDATE amazon_margin_audit_requests
            SET state='failed', finished_at=UTC_TIMESTAMP(), error_code=? WHERE id=?");
        $upd->execute([substr(preg_replace('/[^a-zA-Z0-9_:-]/','_', $code) ?: 'unknown', 0, 90),$requestId]);
    }

    public static function latest(PDO $db): ?array
    {
        $q = $db->query('SELECT id,scope,generated_at,payload_json FROM amazon_margin_audit_runs ORDER BY id DESC LIMIT 1');
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $data = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return ['run_id'=>(int)$row['id'], 'scope'=>$row['scope'], 'generated_at'=>$row['generated_at'], 'snapshot'=>$data];
    }

    public static function requests(PDO $db): array
    {
        return $db->query('SELECT id,scope,state,created_at,started_at,finished_at,error_code
            FROM amazon_margin_audit_requests ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
    }
}
