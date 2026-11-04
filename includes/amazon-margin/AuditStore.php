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
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            stage VARCHAR(48) NOT NULL DEFAULT 'queued',
            progress_done INT NOT NULL DEFAULT 0,
            progress_total INT NULL,
            heartbeat_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            error_code VARCHAR(90) NULL,
            KEY margin_request_queue (state, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Idempotent upgrade of the initial production request table.
        $probe = $db->query("SHOW COLUMNS FROM amazon_margin_audit_requests LIKE 'next_attempt_at'");
        if (!$probe || !$probe->fetch(PDO::FETCH_ASSOC)) {
            try {
                $db->exec("ALTER TABLE amazon_margin_audit_requests ADD COLUMN next_attempt_at DATETIME NULL");
            } catch (PDOException $e) {
                // A second process may have completed the same migration.
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
            }
        }
        foreach ([
            'stage'=>"VARCHAR(48) NOT NULL DEFAULT 'queued'",
            'progress_done'=>"INT NOT NULL DEFAULT 0",
            'progress_total'=>"INT NULL",
            'heartbeat_at'=>"DATETIME NULL"
        ] as $column=>$definition) {
            $column = preg_replace('/[^a-z_]/','',$column);
            $probe=$db->query("SHOW COLUMNS FROM amazon_margin_audit_requests LIKE '".$column."'");
            if (!$probe || !$probe->fetch(PDO::FETCH_ASSOC)) {
                try {$db->exec("ALTER TABLE amazon_margin_audit_requests ADD COLUMN ".$column." ".$definition);}
                catch(PDOException $e) {if ((int)($e->errorInfo[1]??0)!==1060) throw $e;}
            }
        }
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

    /** Canonical idempotency key for all or a set of Seller SKUs. */
    public static function requestKey(string $scope, array $skus): string
    {
        if (!in_array($scope,['all','selected'],true)) throw new InvalidArgumentException('invalid_scope');
        $skus=array_values(array_unique(array_map('trim',$skus)));
        sort($skus,SORT_STRING);
        return hash('sha256',$scope.'|'.json_encode($scope==='all'?[]:$skus,JSON_THROW_ON_ERROR));
    }

    /** Caller owns the short-lived MySQL queue mutex. */
    private static function coalesceUnlocked(PDO $db): int
    {
        $active=$db->query("SELECT id,scope,skus_json,state FROM amazon_margin_audit_requests
            WHERE state IN ('running','queued') ORDER BY (state='running') DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $seen=[];$removed=0;
        $mark=$db->prepare("UPDATE amazon_margin_audit_requests
            SET state='coalesced',stage='coalesced',finished_at=UTC_TIMESTAMP(),
                error_code=?,next_attempt_at=NULL
            WHERE id=? AND state='queued'");
        foreach($active as $entry) {
            $skus=json_decode((string)$entry['skus_json'],true);
            if(!is_array($skus))continue;
            $key=self::requestKey((string)$entry['scope'],$skus);
            if(!isset($seen[$key])) { $seen[$key]=(int)$entry['id'];continue; }
            if($entry['state']!=='queued')continue;
            $mark->execute(['duplicate_of_'.$seen[$key],(int)$entry['id']]);
            $removed+=$mark->rowCount();
        }
        return $removed;
    }

    private static function queueLock(PDO $db): void
    {
        if ((int)$db->query("SELECT GET_LOCK('sv_amazon_margin_enqueue',5)")->fetchColumn()!==1)
            throw new RuntimeException('audit_request_queue_busy');
    }

    public static function coalesceQueued(PDO $db): int
    {
        self::ensureSchema($db);
        self::queueLock($db);
        try { return self::coalesceUnlocked($db); }
        finally { $db->query("SELECT RELEASE_LOCK('sv_amazon_margin_enqueue')"); }
    }

    /**
     * @param list<string> $skus
     * @return array{request_id:int,reused:bool,state:string,next_attempt_at:?string}
     */
    public static function request(PDO $db,string $scope,array $skus): array
    {
        if (!in_array($scope,['selected','all'],true))throw new InvalidArgumentException('invalid_scope');
        $skus=array_values(array_unique(array_map('trim',$skus)));
        sort($skus,SORT_STRING);
        if($scope==='selected' && (count($skus)<1||count($skus)>1000))
            throw new InvalidArgumentException('selected_sku_count_invalid');
        if($scope==='all')$skus=[];
        foreach($skus as $sku) {
            if($sku===''||strlen($sku)>80)throw new InvalidArgumentException('invalid_seller_sku');
        }

        self::ensureSchema($db);
        self::queueLock($db);
        try {
            self::coalesceUnlocked($db);
            $key=self::requestKey($scope,$skus);
            $query=$db->query("SELECT id,scope,skus_json,state,next_attempt_at
                FROM amazon_margin_audit_requests WHERE state IN ('running','queued')
                ORDER BY (state='running') DESC,id ASC");
            foreach($query->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $past=json_decode((string)$entry['skus_json'],true);
                if(!is_array($past)||self::requestKey((string)$entry['scope'],$past)!==$key)continue;
                return ['request_id'=>(int)$entry['id'],'reused'=>true,
                    'state'=>(string)$entry['state'],'next_attempt_at'=>$entry['next_attempt_at']];
            }
            $insert=$db->prepare('INSERT INTO amazon_margin_audit_requests(scope,skus_json,stage)
                VALUES(?,?,?)');
            $insert->execute([$scope,json_encode($skus,JSON_THROW_ON_ERROR),'queued']);
            return ['request_id'=>(int)$db->lastInsertId(),'reused'=>false,
                'state'=>'queued','next_attempt_at'=>null];
        } finally { $db->query("SELECT RELEASE_LOCK('sv_amazon_margin_enqueue')"); }
    }

    public static function next(PDO $db): ?array
    {
        self::coalesceQueued($db);
        // Exclusive runner lock is held by the caller. Reclaim only crashed jobs.
        $db->exec("UPDATE amazon_margin_audit_requests SET state='queued', started_at=NULL
            WHERE state='running' AND attempts<3 AND started_at<UTC_TIMESTAMP()-INTERVAL 90 MINUTE");
        $db->exec("UPDATE amazon_margin_audit_requests SET state='failed', finished_at=UTC_TIMESTAMP(), error_code='stale_attempt_limit'
            WHERE state='running' AND attempts>=3 AND started_at<UTC_TIMESTAMP()-INTERVAL 90 MINUTE");
        $db->beginTransaction();
        try {
            $stmt = $db->query("SELECT id,scope,skus_json FROM amazon_margin_audit_requests
                WHERE state = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
                ORDER BY id LIMIT 1 FOR UPDATE");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $db->commit(); return null; }
            $upd = $db->prepare("UPDATE amazon_margin_audit_requests
                SET state='running',stage='starting',progress_done=0,progress_total=NULL,
                    heartbeat_at=UTC_TIMESTAMP(), attempts=attempts+1, next_attempt_at=NULL,
                    started_at=UTC_TIMESTAMP(), finished_at=NULL WHERE id=? AND state='queued'");
            $upd->execute([$row['id']]);
            $db->commit();
            $row['skus'] = json_decode((string)$row['skus_json'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function progress(PDO $db,int $requestId,string $stage,int $done=0,?int $total=null): void
    {
        if($requestId<=0)return;
        if(!preg_match('/^[a-z_]{2,48}$/',$stage))throw new InvalidArgumentException('invalid_stage');
        $q=$db->prepare("UPDATE amazon_margin_audit_requests
            SET stage=?,progress_done=?,progress_total=?,heartbeat_at=UTC_TIMESTAMP()
            WHERE id=? AND state='running'");
        $q->execute([$stage,max(0,$done),$total===null?null:max(0,$total),$requestId]);
    }

    public static function complete(PDO $db, int $requestId, string $scope, array $snapshot): int
    {
        $payload = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO amazon_margin_audit_runs
            (request_id,scope,generated_at,offer_count,payload_json) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)");
        $stmt->execute([$requestId ?: null, $scope, count($snapshot['rows'] ?? []), $payload]);
        $runId = (int)$db->lastInsertId();
        if ($requestId > 0) {
            $upd = $db->prepare("UPDATE amazon_margin_audit_requests SET state='done', stage='completed',
                progress_done=?,progress_total=?,heartbeat_at=UTC_TIMESTAMP(),
                error_code=NULL, finished_at=UTC_TIMESTAMP() WHERE id=?");
            $count=count($snapshot['rows']??[]);
            $upd->execute([$count,$count,$requestId]);
        }
        return $runId;
    }

    public static function fail(PDO $db, int $requestId, string $code): void
    {
        if ($requestId <= 0) return;
        $code = substr(preg_replace('/[^a-zA-Z0-9_:-]/','_', $code) ?: 'unknown', 0, 90);
        if ($code === 'AMAZON_QUOTA_429') {
            // SP-API quota is external/transient, not a corrupt audit. Do not
            // burn the 3 attempts while requests remain throttled.
            $upd = $db->prepare("UPDATE amazon_margin_audit_requests
                SET state='queued', stage='waiting_amazon_quota',
                    attempts=GREATEST(0, attempts-1),
                    next_attempt_at=UTC_TIMESTAMP()+INTERVAL 20 MINUTE,
                    heartbeat_at=UTC_TIMESTAMP(),finished_at=NULL,error_code=? WHERE id=?");
            $upd->execute([$code,$requestId]);
            return;
        }
        $upd = $db->prepare("UPDATE amazon_margin_audit_requests
            SET state=IF(attempts<3,'queued','failed'),
                stage=IF(attempts<3,'retry_pending','failed'),
                next_attempt_at=IF(attempts<3,UTC_TIMESTAMP()+INTERVAL 5 MINUTE,NULL),
                heartbeat_at=UTC_TIMESTAMP(),finished_at=UTC_TIMESTAMP(), error_code=? WHERE id=?");
        $upd->execute([$code,$requestId]);
    }

    public static function latest(PDO $db): ?array
    {
        $q = $db->query('SELECT id,scope,generated_at,payload_json FROM amazon_margin_audit_runs ORDER BY id DESC LIMIT 1');
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $data = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return ['run_id'=>(int)$row['id'], 'scope'=>$row['scope'], 'generated_at'=>$row['generated_at'], 'snapshot'=>$data];
    }

    public static function latestMeta(PDO $db): ?array
    {
        $row=$db->query('SELECT id,scope,generated_at,offer_count
            FROM amazon_margin_audit_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ? ['run_id'=>(int)$row['id'],'scope'=>$row['scope'],
            'generated_at'=>$row['generated_at'],'offer_count'=>(int)$row['offer_count']] : null;
    }

    public static function requests(PDO $db): array
    {
        return $db->query('SELECT id,scope,state,stage,progress_done,progress_total,
            heartbeat_at,attempts,next_attempt_at,created_at,started_at,finished_at,error_code
            FROM amazon_margin_audit_requests ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    }
}
