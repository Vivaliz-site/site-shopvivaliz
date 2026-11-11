<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/marketplace/TinyV3Runtime.php';
function mustUseFreshOlistToken(bool $valid,string $reason):void {
    if(!$valid)throw new RuntimeException('olist_margin_rotating_token: '.$reason);
}
$path=sys_get_temp_dir().'/sv-olist-margin-'.bin2hex(random_bytes(6)).'.json';
$oldPath=getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
$oldEnv=getenv('TINY_ACCESS_TOKEN');
try {
    putenv('SHOPVIVALIZ_OLIST_TOKEN_FILE='.$path);
    putenv('TINY_ACCESS_TOKEN=invalid_old_env_token');
    $first='fixture-v1';
    $second='fixture-v2';
    $store=['OLIST_ACCESS_TOKEN'=>$first,'expires_at_epoch'=>time()+3600];
    file_put_contents($path,json_encode($store,JSON_THROW_ON_ERROR));chmod($path,0600);
    mustUseFreshOlistToken(sv_market_tiny_ensure_access_token()===$first,
        'read current canonical token before static environment');
    $store['OLIST_ACCESS_TOKEN']=$second;
    file_put_contents($path,json_encode($store,JSON_THROW_ON_ERROR));
    mustUseFreshOlistToken(sv_market_tiny_ensure_access_token()===$second,
        'observe OAuth token rotation without restart');

    // The previous release inadvertently published a placeholder bearer.
    // Keep this exact regression under the existing test suite.
    $collector=(string)file_get_contents(__DIR__.'/../../includes/amazon-margin/AuditCollector.php');
    $runner=(string)file_get_contents(__DIR__.'/../../scripts/amazon-margin/run.php');
    mustUseFreshOlistToken(!str_contains($collector,'[REDACTED]'),
        'do not send placeholder as an authentication header');
    $headerLine=array_values(array_filter(explode("\n",$collector),
        static fn(string $line):bool=>str_contains($line,'CURLOPT_HTTPHEADER=>')))[0]??'';
    mustUseFreshOlistToken(str_contains($headerLine,'$token')
        && str_contains($headerLine,"'Accept: application/json'"),
        'Olist request must use a dynamic bearer');
    mustUseFreshOlistToken(str_contains($runner,'sv_market_tiny_ensure_access_token()'),
        'CLI runner must load canonical token');
    echo "PASS Amazon margin uses rotating Olist OAuth token and dynamic request header\n";
} finally {
    @unlink($path);
    if($oldPath===false)putenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
    else putenv('SHOPVIVALIZ_OLIST_TOKEN_FILE='.$oldPath);
    if($oldEnv===false)putenv('TINY_ACCESS_TOKEN');
    else putenv('TINY_ACCESS_TOKEN='.$oldEnv);
}
