<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/AuditStore.php';
if(getenv('SV_MARGIN_DB_INTEGRATION_TEST')!=='1') {
    echo "SKIP Olist quota queue: enable SV_MARGIN_DB_INTEGRATION_TEST=1 on authorized test transaction\n";
    exit(0);
}
require_once __DIR__.'/../../includes/pdo-database.php';
$db=sv_pdo();
if(!$db)throw new RuntimeException('database_unavailable');
SvAmazonMarginStore::ensureSchema($db);
$db->beginTransaction();
try {
    $db->exec("INSERT INTO amazon_margin_audit_requests (scope,skus_json,state,stage,attempts)
        VALUES ('selected','[\"__OLIST_QUOTA_TEST__\"]','running','loading_prices',1)");
    $id=(int)$db->lastInsertId();
    SvAmazonMarginStore::fail($db,$id,'OLIST_QUOTA_429');
    $q=$db->prepare("SELECT state,stage,attempts,error_code,
        TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),next_attempt_at) remaining
        FROM amazon_margin_audit_requests WHERE id=?");
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row || $row['state']!=='queued' || $row['stage']!=='waiting_olist_quota'
        || (int)$row['attempts']!==0 || $row['error_code']!=='OLIST_QUOTA_429'
        || (int)$row['remaining']<1180 || (int)$row['remaining']>1220) {
        throw new RuntimeException('Olist quota must be safely deferred: '.json_encode($row));
    }
    $db->rollBack();
    echo "PASS MySQL Olist quota: job deferred 20 min without exhausting attempts (rollback)\n";
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    throw $e;
}
