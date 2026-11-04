<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/amazon-margin/AuditStore.php';

function check(bool $ok,string $message):void
{
    if(!$ok) throw new RuntimeException($message);
}

// A permutation of the same selected SKUs must never create extra work.
check(SvAmazonMarginStore::requestKey('selected',['A21','ASSLIMFBA'])
    ===SvAmazonMarginStore::requestKey('selected',['ASSLIMFBA','A21','A21']),
    'selected order/duplicates not canonicalized');
check(SvAmazonMarginStore::requestKey('all',[])
    ===SvAmazonMarginStore::requestKey('all',['IGNORED']),
    'all scope must use the same idempotency key');
check(SvAmazonMarginStore::requestKey('all',[])
    !==SvAmazonMarginStore::requestKey('selected',['A21']),
    'all/selected scopes must differ');
echo "PASS margin queue identity: selected permutations and all scope\n";

// CI has no production MySQL. Run with SV_MARGIN_DB_INTEGRATION_TEST=1
// only against an authorized test transaction on the Marina deployment.
if(getenv('SV_MARGIN_DB_INTEGRATION_TEST')!=='1')exit(0);
require_once dirname(__DIR__,2).'/includes/pdo-database.php';
$db=sv_pdo();
if(!$db)throw new RuntimeException('db_unavailable');
SvAmazonMarginStore::ensureSchema($db);
$db->beginTransaction();
try{
    $sku='__SV_MARGIN_QUEUE_TEST_20261103__';
    $first=SvAmazonMarginStore::request($db,'selected',[$sku]);
    check($first['reused']===false,'first request not inserted');
    $second=SvAmazonMarginStore::request($db,'selected',[$sku,$sku]);
    check($second['reused']===true,'duplicate click must reuse request');
    check($second['request_id']===$first['request_id'],'duplicate request ID changed');
    $id=$first['request_id'];
    $s=$db->prepare("UPDATE amazon_margin_audit_requests SET state='running',stage='starting' WHERE id=?");
    $s->execute([$id]);
    SvAmazonMarginStore::progress($db,$id,'loading_prices',40,80);
    $s=$db->prepare("SELECT stage,progress_done,progress_total FROM amazon_margin_audit_requests WHERE id=?");
    $s->execute([$id]);$progress=$s->fetch(PDO::FETCH_ASSOC);
    check($progress['stage']==='loading_prices','progress stage missing');
    check((int)$progress['progress_done']===40&&(int)$progress['progress_total']===80,
        'progress numbers missing');
    $db->rollBack();
    echo "PASS margin queue MySQL: click idempotency + stage progress (rollback)\n";
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    throw $e;
}
