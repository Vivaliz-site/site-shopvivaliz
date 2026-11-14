<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPriceJobs.php';
function qassert(bool $check,string $name):void{
    if(!$check)throw new RuntimeException('target_margin_queue_'.$name);
}
class LocalPriceQueueDb extends PDO
{
    public function __construct(){parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));
    }
    public function exec(string $statement):int|false {
        if(str_starts_with($statement,'CREATE TABLE IF NOT EXISTS amazon_margin_target_price_'))
            return 0; // SQLite fixtures below model existing MySQL tables.
        return parent::exec($statement);
    }
}
$db=new LocalPriceQueueDb();
$db->exec("CREATE TABLE amazon_margin_target_price_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,idempotency_key TEXT UNIQUE NOT NULL,
 requested_by_user_id INTEGER NOT NULL,audit_run_id INTEGER NOT NULL,target_bps INTEGER NOT NULL,
 state TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,started_at TEXT,
 finished_at TEXT,heartbeat_at TEXT,status_code TEXT)");
$db->exec("CREATE TABLE amazon_margin_target_price_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER NOT NULL,
 seller_sku TEXT NOT NULL,erp_id INTEGER NOT NULL,old_price_cents INTEGER NOT NULL,
 proposal_price_cents INTEGER NOT NULL,target_bps INTEGER NOT NULL,
 state TEXT NOT NULL DEFAULT 'queued',stage TEXT NOT NULL DEFAULT 'queued',
 result_price_cents INTEGER,original_table_json TEXT,errors_json TEXT,
 amazon_request_id TEXT,submitted_at TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(request_id,seller_sku))");
$plan=['target_margin_bps'=>1000,
'amazon_maximum_price_rule'=>'raise_to_110_percent_only_when_sale_exceeds_existing_maximum',
'cart_exception_rule'=>'exclude_tool_carts_by_current_erp_identity_and_title',
'rows'=>[
 ['seller_sku'=>'SKUA','erp_id'=>91,'current_price_cents'=>2500,
  'proposed_price_cents'=>3100,'state'=>'simulated','price_list_shared_product_conflict'=>false],
 ['seller_sku'=>'SKUB','erp_id'=>92,'current_price_cents'=>8000,
  'proposed_price_cents'=>8500,'state'=>'simulated','price_list_shared_product_conflict'=>false],
 ['seller_sku'=>'C08BR','erp_id'=>353735815,'current_price_cents'=>154900,
  'proposed_price_cents'=>null,'state'=>'excluded','reason'=>'carrinho_ferramentas_excecao',
  'price_list_shared_product_conflict'=>false],
]];
$hash=SvAmazonTargetMarginPriceJobs::digest(22,$plan);
qassert(strlen($hash)===64,'digest_sha256');
qassert($hash===SvAmazonTargetMarginPriceJobs::digest(22,$plan),'digest_deterministic');
$changedMaximumRule=$plan;
$changedMaximumRule['amazon_maximum_price_rule']='preserve_existing_maximum';
qassert($hash!==SvAmazonTargetMarginPriceJobs::digest(22,$changedMaximumRule),
    'maximum_price_rule_is_part_of_signed_preview');
$changedCartRule=$plan;
$changedCartRule['cart_exception_rule']='allow_all_skus';
qassert($hash!==SvAmazonTargetMarginPriceJobs::digest(22,$changedCartRule),
    'cart_exception_is_part_of_signed_preview');
$created=SvAmazonTargetMarginPriceJobs::enqueue($db,22,$plan,$hash,str_repeat('a',32),7);
qassert($created['request_id']>0&&$created['reused']===false
    &&$created['state']==='queued','enqueued_without_external_writes');
$same=SvAmazonTargetMarginPriceJobs::enqueue($db,22,$plan,$hash,str_repeat('a',32),7);
qassert($same['request_id']===$created['request_id']&&$same['reused']===true,
    'same_nonce_idempotent');
$status=SvAmazonTargetMarginPriceJobs::status($db,$created['request_id'],7);
qassert(count($status['items'])===2
    &&$status['items'][0]['seller_sku']==='SKUA','queue_persists_selected_skus_and_skips_tool_carts');
qassert(SvAmazonTargetMarginPriceJobs::status($db,$created['request_id'],8)===null,
    'other_admin_cannot_read_unowned_request');
$firstId=(int)$db->query('SELECT id FROM amazon_margin_target_price_items ORDER BY id LIMIT 1')->fetchColumn();
SvAmazonTargetMarginPriceJobs::transition($db,$firstId,'running','amazon_submit_started',
    3100,null,null,['descricao'=>'Amazon','excecoes'=>[]],3410);
$result=$db->query("SELECT stage,submitted_at,result_price_cents,original_table_json,
    amazon_maximum_price_cents
    FROM amazon_margin_target_price_items WHERE id=$firstId")->fetch(PDO::FETCH_ASSOC);
qassert($result['stage']==='amazon_submit_started'
    &&$result['submitted_at']!==null
    &&(int)$result['result_price_cents']===3100
    &&str_contains($result['original_table_json'],'Amazon')
    &&(int)$result['amazon_maximum_price_cents']===3410,
    'write_ahead_journal_and_maximum_price_checkpoint');
$status=SvAmazonTargetMarginPriceJobs::status($db,$created['request_id'],7);
qassert((int)$status['items'][0]['amazon_maximum_price_cents']===3410,
    'maximum_price_checkpoint_survives_status_readback');
$broken=$plan;$broken['rows'][0]['state']='pending';
try{SvAmazonTargetMarginPriceJobs::enqueue($db,22,$broken,
    SvAmazonTargetMarginPriceJobs::digest(22,$broken),str_repeat('b',32),7);
    throw new RuntimeException('pending_cost_changed_erp');}
catch(RuntimeException $e){qassert($e->getMessage()==='price_proposal_not_ready_for_publication',
    'noncertified_cost_never_publication_request');}
try{SvAmazonTargetMarginPriceJobs::enqueue($db,22,$plan,str_repeat('0',64),
    str_repeat('c',32),7);
    throw new RuntimeException('stale_preview_accepted');}
catch(RuntimeException $e){qassert($e->getMessage()==='price_preview_changed_recalculate',
    'stale_preview_fails');}
qassert((int)$db->query('SELECT COUNT(*) FROM amazon_margin_target_price_requests')->fetchColumn()===1,
    'no_duplicate_or_invalid_commercial_request');
echo "PASS durable owner-initiated price queue: SQLite red/green, operator scope, CAS digest, idempotency, journal, readback staging\n";
