<?php
declare(strict_types=1);

require_once __DIR__.'/../../includes/admin-guard.php';
require_once __DIR__.'/../../includes/amazon-margin/AuditStore.php';
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

function sv_margin_api_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    exit;
}
function sv_margin_api_selected(mixed $value): array
{
    if (!is_array($value)) throw new InvalidArgumentException('skus_must_be_array');
    if (count($value)>1000) throw new InvalidArgumentException('too_many_skus');
    $selected=[];
    foreach ($value as $sku) {
        if (!is_string($sku)||$sku===''||strlen($sku)>80)throw new InvalidArgumentException('invalid_sku');
        $selected[]=$sku;
    }
    return array_values(array_unique($selected));
}

$db=sv_pdo();
if (!$db) sv_margin_api_json(503,['ok'=>false,'error'=>'database_unavailable']);

try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='POST') {
        $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        $sessionToken=(string)($_SESSION['sv_margin_csrf_token']??'');
        if ($token===''||$sessionToken===''||!hash_equals($sessionToken,$token)) {
            sv_margin_api_json(403,['ok'=>false,'error'=>'csrf_denied']);
        }
        $raw=file_get_contents('php://input');
        if (!is_string($raw)||strlen($raw)>100000) sv_margin_api_json(413,['ok'=>false,'error'=>'payload_too_large']);
        $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($body)) sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_action']);
        if (($body['action']??'')==='export') {
            $latest=SvAmazonMarginStore::latest($db);
            if (!$latest)sv_margin_api_json(404,['ok'=>false,'error'=>'no_audit_snapshot']);
            $format=(string)($body['format']??'');
            if (!in_array($format,['pdf','xlsx'],true))sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_format']);
            $skus=sv_margin_api_selected($body['skus']??[]);
            $rows=SvAmazonMarginExport::rows($latest['snapshot'],$skus);
            if (!$rows)sv_margin_api_json(404,['ok'=>false,'error'=>'no_matching_rows']);
            $date=substr((string)$latest['generated_at'],0,10);
            $bytes=$format==='pdf' ? SvAmazonMarginExport::pdf($rows,$date) : SvAmazonMarginExport::xlsx($rows);
            header('Content-Type: '.($format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
            header('Content-Disposition: attachment; filename="shopvivaliz-margens-amazon-'.$date.'.'.$format.'"');
            header('Content-Length: '.strlen($bytes));
            echo $bytes;exit;
        }
        if (($body['action']??'')!=='request')sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_action']);
        $scope=(string)($body['scope']??'');
        $skus=sv_margin_api_selected($body['skus']??[]);
        $job=SvAmazonMarginStore::request($db,$scope,$skus);
        sv_margin_api_json(202,['ok'=>true,'request_id'=>$job['request_id'],
            'state'=>$job['state'],'reused'=>$job['reused'],
            'next_attempt_at'=>$job['next_attempt_at'],
            'detail'=>$job['reused']?'existing_audit_request':'audit_request_queued']);
    }
    if ($method!=='GET') sv_margin_api_json(405,['ok'=>false,'error'=>'method_not_allowed']);

    $action=(string)($_GET['action']??'latest');
    if($action==='status') {
        SvAmazonMarginStore::ensureSchema($db);
        sv_margin_api_json(200,['ok'=>true,
            'latest_meta'=>SvAmazonMarginStore::latestMeta($db),
            'requests'=>SvAmazonMarginStore::requests($db),
            'price_updates_enabled'=>false]);
    }
    $latest=SvAmazonMarginStore::latest($db);
    if ($action==='export') {
        if (!$latest) sv_margin_api_json(404,['ok'=>false,'error'=>'no_audit_snapshot']);
        $format=(string)($_GET['format']??'');
        if (!in_array($format,['pdf','xlsx'],true))sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_format']);
        $skuParam=trim((string)($_GET['skus']??''));
        $skus=$skuParam===''?[]:sv_margin_api_selected(explode(',',$skuParam));
        $data=SvAmazonMarginExport::rows($latest['snapshot'],$skus);
        if (!$data)sv_margin_api_json(404,['ok'=>false,'error'=>'no_matching_rows']);
        $date=substr((string)$latest['generated_at'],0,10);
        $bytes=$format==='pdf'
            ? SvAmazonMarginExport::pdf($data,$date)
            : SvAmazonMarginExport::xlsx($data);
        $filename='shopvivaliz-margens-amazon-'.$date.'.'.$format;
        header('Content-Type: '.($format==='pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($bytes));
        echo $bytes;exit;
    }
    if ($action!=='latest') sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_action']);
    SvAmazonMarginStore::ensureSchema($db);
    $requests=SvAmazonMarginStore::requests($db);
    sv_margin_api_json(200,['ok'=>true,'latest'=>$latest,'requests'=>$requests,
        'price_updates_enabled'=>false]);
} catch(InvalidArgumentException|JsonException $e) {
    sv_margin_api_json(400,['ok'=>false,'error'=>'invalid_input']);
} catch(Throwable $e) {
    error_log('amazon_margin_admin_api_failure: '.get_class($e));
    sv_margin_api_json(503,['ok'=>false,'error'=>'audit_storage_unavailable',
        'detail'=>'Awaiting first audited snapshot or schema deployment.']);
}
