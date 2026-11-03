<?php
declare(strict_types=1);

// Read-only CLI collector. Has no price or stock write operations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$shared = (string)(getenv('SHOPVIVALIZ_SHARED_DIR') ?: '/home/ubuntu/shopvivaliz-deploy/shared');
$envFile = rtrim($shared, '/') . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line=trim($line);
        if ($line==='' || $line[0]==='#' || !str_contains($line,'=')) continue;
        [$name,$value]=explode('=',$line,2);
        $name=trim($name);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/',$name)!==1) continue;
        if (getenv($name) !== false) continue;
        putenv($name.'='.trim(trim($value),chr(34).chr(39)));
    }
}
require_once $root.'/includes/pdo-database.php';
require_once $root.'/includes/marketplace/AmazonPublisher.php';
require_once $root.'/includes/amazon-margin/AuditStore.php';
require_once $root.'/includes/amazon-margin/AuditCollector.php';

$options = getopt('', ['all','next','skus:','from:','dry-run']);
$dryRun = array_key_exists('dry-run', $options);
$selected = [];
$scope = 'selected';
$requestId = 0;
$db = null;

try {
    $modes = (int)array_key_exists('all',$options) + (int)array_key_exists('next',$options)
           + (int)array_key_exists('skus',$options);
    if ($modes !== 1) throw new InvalidArgumentException('pass_exactly_one_scope_all_next_or_skus');
    if (isset($options['skus'])) {
        $selected = array_values(array_unique(array_filter(array_map('trim',
            explode(',', (string)$options['skus'])))));
        if (count($selected)<1 || count($selected)>100) throw new InvalidArgumentException('invalid_selected_sku_count');
    }
    if (array_key_exists('all',$options)) $scope='all';
    if (array_key_exists('next',$options)) {
        if ($dryRun) throw new InvalidArgumentException('next_dry_run_invalid');
        $db = sv_pdo();
        if (!$db) throw new RuntimeException('database_unavailable');
        SvAmazonMarginStore::ensureSchema($db);
        $locked = (int)$db->query("SELECT GET_LOCK('shopvivaliz_amazon_margin_runner',0)")->fetchColumn();
        if ($locked !== 1) throw new RuntimeException('another_margin_audit_running');
        $job = SvAmazonMarginStore::next($db);
        if (!$job) { echo json_encode(['state'=>'queue_empty']),"\n"; exit(0); }
        $requestId=(int)$job['id'];
        $scope=(string)$job['scope'];
        $selected=$scope==='selected' ? $job['skus'] : [];
    }
    $token = (string)(getenv('TINY_ACCESS_TOKEN') ?: getenv('OLIST_ACCESS_TOKEN') ?: '');
    if ($token==='') throw new RuntimeException('tiny_access_token_missing');
    $collector = new SvAmazonMarginCollector(new SvAmazonClient(),$token);
    $snapshot = $collector->collect($selected, (string)($options['from']??'')?:null);
    if ($dryRun) {
        $examples = array_slice(array_map(static fn($r):array => [
            'seller_sku'=>$r['seller_sku'],'status'=>$r['status'],
            'price'=>$r['price']??null,'cost'=>$r['last_cost']??null,
            'program'=>$r['program']??null,
            'margin_weighted_bps'=>$r['margin_weighted_bps']??null,
            'confirmed_sales'=>$r['confirmed_sales']??0,
        ],$snapshot['rows']),0,12);
        echo json_encode(['mode'=>'dry_run','offer_count'=>$snapshot['offer_count'],
            'warning_count'=>count($snapshot['warnings']),'sample'=>$examples],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
        exit(0);
    }
    if (!$db) $db=sv_pdo();
    if (!$db) throw new RuntimeException('database_unavailable');
    SvAmazonMarginStore::ensureSchema($db);
    $runId=SvAmazonMarginStore::complete($db,$requestId,$scope,$snapshot);
    echo json_encode(['state'=>'done','run_id'=>$runId,'scope'=>$scope,
        'offer_count'=>$snapshot['offer_count'],
        'warning_count'=>count($snapshot['warnings'])],JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e) {
    if ($db instanceof PDO && $requestId>0) {
        try { SvAmazonMarginStore::fail($db,$requestId,get_class($e)); } catch(Throwable) {}
    }
    fwrite(STDERR,json_encode(['state'=>'failed','code'=>get_class($e),
        'reason'=>preg_replace('/[^a-zA-Z0-9_:-]/','_',substr($e->getMessage(),0,90))],
        JSON_THROW_ON_ERROR)."\n");
    exit(2);
} finally {
    if ($db instanceof PDO && array_key_exists('next',$options)) {
        try { $db->query("SELECT RELEASE_LOCK('shopvivaliz_amazon_margin_runner')"); } catch(Throwable) {}
    }
}
