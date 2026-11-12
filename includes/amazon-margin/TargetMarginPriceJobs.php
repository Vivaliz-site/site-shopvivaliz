<?php
declare(strict_types=1);

/**
 * Durable commercial requests initiated by an authenticated admin action.
 * The audit collector remains read-only; no external writes happen here.
 * A separate approved CLI executor consumes this queue.
 */
final class SvAmazonTargetMarginPriceJobs
{
    public static function schema(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS amazon_margin_target_price_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            idempotency_key CHAR(64) NOT NULL,
            requested_by_user_id BIGINT NOT NULL,
            audit_run_id BIGINT UNSIGNED NOT NULL,
            target_bps INT NOT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'queued',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            heartbeat_at DATETIME NULL,
            status_code VARCHAR(90) NULL,
            UNIQUE KEY req_dedupe (idempotency_key),
            KEY price_job_queue (state,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS amazon_margin_target_price_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NOT NULL,
            seller_sku VARCHAR(80) NOT NULL,
            erp_id BIGINT UNSIGNED NOT NULL,
            old_price_cents BIGINT UNSIGNED NOT NULL,
            proposal_price_cents BIGINT UNSIGNED NOT NULL,
            target_bps INT NOT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'queued',
            stage VARCHAR(64) NOT NULL DEFAULT 'queued',
            result_price_cents BIGINT UNSIGNED NULL,
            original_table_json LONGTEXT NULL,
            errors_json LONGTEXT NULL,
            amazon_request_id VARCHAR(100) NULL,
            submitted_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY price_request_sku (request_id,seller_sku),
            KEY price_job_item_state (request_id,state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Deterministic digest of data actually approved in the owner's preview. */
    public static function digest(int $runId,array $plan):string
    {
        $items=[];
        foreach($plan['rows']??[] as $r){
            $items[]=[(string)$r['seller_sku'],(int)($r['erp_id']??0),
                (int)($r['current_price_cents']??0),(int)($r['proposed_price_cents']??0),
                (string)($r['state']??''),(bool)($r['price_list_shared_product_conflict']??false)];
        }
        return hash('sha256',json_encode([$runId,(int)$plan['target_margin_bps'],$items],JSON_THROW_ON_ERROR));
    }

    /** @return array{request_id:int,reused:bool,state:string} */
    public static function enqueue(PDO $db,int $runId,array $plan,string $expectedDigest,
        string $requestNonce,int $adminUserId):array
    {
        if($adminUserId<=0||!ctype_xdigit($requestNonce)||strlen($requestNonce)!==32)
            throw new InvalidArgumentException('price_request_invalid_identity_or_nonce');
        $digest=self::digest($runId,$plan);
        if(!hash_equals($digest,$expectedDigest))throw new RuntimeException('price_preview_changed_recalculate');
        $rows=$plan['rows']??[];
        if(!is_array($rows)||$rows===[])throw new InvalidArgumentException('price_skus_empty');
        $perSku=[];$erpPrice=[];
        foreach($rows as $r){
            if(($r['state']??'')!=='simulated'
                ||(int)($r['erp_id']??0)<=0
                ||(int)($r['proposed_price_cents']??0)<=0
                ||(bool)($r['price_list_shared_product_conflict']??false))
                throw new RuntimeException('price_proposal_not_ready_for_publication');
            $sku=(string)($r['seller_sku']??'');
            if($sku===''||strlen($sku)>80||isset($perSku[$sku]))
                throw new RuntimeException('price_request_seller_sku_invalid_or_duplicate');
            $erpId=(int)$r['erp_id'];$price=(int)$r['proposed_price_cents'];
            if(isset($erpPrice[$erpId])&&$erpPrice[$erpId]!==$price)
                throw new RuntimeException('price_request_erp_shared_id_conflict');
            $erpPrice[$erpId]=$price;
            $perSku[$sku]=[
                'seller_sku'=>$sku,'erp_id'=>$erpId,'old_price_cents'=>(int)$r['current_price_cents'],
                'proposal_price_cents'=>$price,
            ];
        }
        self::schema($db);
        $key=hash('sha256',$requestNonce.'|'.$adminUserId.'|'.$digest);
        $db->beginTransaction();
        try{
            $read=$db->prepare('SELECT id,state FROM amazon_margin_target_price_requests WHERE idempotency_key=? LIMIT 1');
            $read->execute([$key]);$existing=$read->fetch(PDO::FETCH_ASSOC);
            if($existing){$db->commit();return ['request_id'=>(int)$existing['id'],
                'reused'=>true,'state'=>(string)$existing['state']];}
            $ins=$db->prepare("INSERT INTO amazon_margin_target_price_requests
                (idempotency_key,requested_by_user_id,audit_run_id,target_bps,state) VALUES(?,?,?,?, 'queued')");
            $ins->execute([$key,$adminUserId,$runId,(int)$plan['target_margin_bps']]);
            $id=(int)$db->lastInsertId();
            $item=$db->prepare('INSERT INTO amazon_margin_target_price_items
                (request_id,seller_sku,erp_id,old_price_cents,proposal_price_cents,target_bps)
                VALUES(?,?,?,?,?,?)');
            foreach($perSku as $r)
                $item->execute([$id,$r['seller_sku'],$r['erp_id'],
                    $r['old_price_cents'],$r['proposal_price_cents'],(int)$plan['target_margin_bps']]);
            $db->commit();
            return ['request_id'=>$id,'reused'=>false,'state'=>'queued'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function status(PDO $db,int $requestId,int $userId):?array
    {
        if($requestId<=0||$userId<=0)return null;
        self::schema($db);
        $q=$db->prepare('SELECT id,audit_run_id,target_bps,state,status_code,created_at,started_at,finished_at
            FROM amazon_margin_target_price_requests WHERE id=? AND requested_by_user_id=? LIMIT 1');
        $q->execute([$requestId,$userId]);$request=$q->fetch(PDO::FETCH_ASSOC);
        if(!$request)return null;
        $items=$db->prepare('SELECT seller_sku,erp_id,old_price_cents,proposal_price_cents,
            result_price_cents,state,stage,errors_json,updated_at
            FROM amazon_margin_target_price_items WHERE request_id=? ORDER BY id');
        $items->execute([$requestId]);
        $request['items']=$items->fetchAll(PDO::FETCH_ASSOC);
        return $request;
    }

    /** One existing worker owns its MySQL named lock before calling this. */
    public static function next(PDO $db):?array
    {
        self::schema($db);
        $db->beginTransaction();
        try{
            $stmt=$db->query("SELECT id FROM amazon_margin_target_price_requests
                WHERE state IN ('queued','running')
                ORDER BY FIELD(state,'running','queued'),id LIMIT 1 FOR UPDATE");
            $job=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$job){$db->commit();return null;}
            $id=(int)$job['id'];
            $db->prepare("UPDATE amazon_margin_target_price_requests
                SET state='running',started_at=COALESCE(started_at,UTC_TIMESTAMP()),
                    heartbeat_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
            $item=$db->prepare("SELECT * FROM amazon_margin_target_price_items
                WHERE request_id=? AND state NOT IN ('done','manual_review','rejected')
                ORDER BY id LIMIT 1 FOR UPDATE");
            $item->execute([$id]);$next=$item->fetch(PDO::FETCH_ASSOC);
            if(!$next){
                $db->prepare("UPDATE amazon_margin_target_price_requests
                    SET state='finished',finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
                $db->commit();return null;
            }
            $db->commit();return $next;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function transition(PDO $db,int $itemId,string $state,
        string $stage,?int $resultPrice=null,?string $code=null,
        ?string $amazonRequestId=null,?array $originalTable=null):void
    {
        if(!in_array($state,['queued','running','awaiting_readback','done','manual_review','rejected'],true))
            throw new InvalidArgumentException('price_item_state_invalid');
        $q=$db->prepare("UPDATE amazon_margin_target_price_items
            SET state=?,stage=?,result_price_cents=COALESCE(?,result_price_cents),
                errors_json=COALESCE(?,errors_json),
                amazon_request_id=COALESCE(?,amazon_request_id),
                submitted_at=CASE WHEN ?='amazon_submit_started' THEN COALESCE(submitted_at,UTC_TIMESTAMP()) ELSE submitted_at END,
                original_table_json=COALESCE(?,original_table_json)
            WHERE id=?");
        $q->execute([$state,$stage,$resultPrice,$code===null?null:json_encode(['code'=>$code],JSON_THROW_ON_ERROR),
            $amazonRequestId,$stage,$originalTable===null?null:json_encode($originalTable,JSON_THROW_ON_ERROR),$itemId]);
    }
}
