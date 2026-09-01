<?php

declare(strict_types=1);

require_once __DIR__ . '/../workers/amazon-returns/seller-central-worker.php';

function bcSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function bcAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function runNodeAdapter(array $input): array {
    $script = realpath(__DIR__ . '/../scripts/amazon-returns/seller-central-adapter.mjs');
    if ($script === false) throw new RuntimeException('Adapter script missing.');
    $proc = proc_open(['node', $script], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Could not start adapter.');
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) throw new RuntimeException('Adapter failed: ' . $stderr);
    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Adapter output invalid.');
    return $decoded;
}

$base = [
    'action'=>'SAFE_T_SUBMIT',
    'case'=>['order_id'=>'702-1234567-7654321','safe_t_id'=>null],
    'snapshot'=>['authenticated'=>true,'mfa_required'=>false,'captcha_present'=>false,'ui_contract'=>'safet-v1','existing_claim_id'=>null,'existing_support_case_id'=>null,'eligibility'=>['allowed'=>true]],
];
$dry = runNodeAdapter($base);
bcSame('ACCEPTED', $dry['status'], 'Default adapter evaluation must be accepted in dry-run.');
bcSame(true, $dry['dry_run'], 'Dry-run must default true.');
bcSame(false, $dry['submitted'], 'Dry-run must never submit.');

$mfa = $base; $mfa['snapshot']['mfa_required'] = true;
bcSame('AUTH_REQUIRED', runNodeAdapter($mfa)['status'], 'MFA must pause without bypass.');
$captcha = $base; $captcha['snapshot']['captcha_present'] = true;
bcSame('HUMAN_CHALLENGE', runNodeAdapter($captcha)['status'], 'CAPTCHA must pause without bypass.');
$drift = $base; $drift['snapshot']['ui_contract'] = 'unknown-v99';
bcSame('UI_DRIFT', runNodeAdapter($drift)['status'], 'Unexpected UI contract must circuit break.');
$existing = $base; $existing['snapshot']['existing_claim_id'] = '12797-64249-3531034';
$existingResult = runNodeAdapter($existing);
bcSame('ALREADY_EXISTS', $existingResult['status'], 'Existing SAFE-T must suppress duplicate write.');
bcSame('12797-64249-3531034', $existingResult['external_id'], 'Existing claim ID must be read back.');
$blocked = $base; $blocked['snapshot']['eligibility'] = ['allowed'=>false,'reason'=>'Aguarde o prazo de reembolso proativo.','next_allowed_at'=>'2026-09-06T00:00:00Z'];
$blockedResult = runNodeAdapter($blocked);
bcSame('BLOCKED_UNTIL', $blockedResult['status'], 'Eligibility block must be persisted instead of spam retry.');
bcSame('2026-09-06T00:00:00Z', $blockedResult['next_allowed_at'], 'Exact next permitted date must survive.');

$support = $base; $support['action']='SELLER_SUPPORT_OPEN'; $support['case']['safe_t_id']='12797-64249-3531034'; $support['snapshot']['existing_support_case_id']='21839000001';
$supportResult = runNodeAdapter($support);
bcSame('ALREADY_EXISTS', $supportResult['status'], 'Existing Help case must suppress duplicate support ticket.');
bcSame('21839000001', $supportResult['external_id'], 'Existing support Case ID must be retained.');

$realWrite = $base; $realWrite['dry_run']=false; $realWrite['write_flags']=['SAFE_T_SUBMIT'=>true];
$realWriteResult = runNodeAdapter($realWrite);
bcSame('FAILED', $realWriteResult['status'], 'Write enabled without a real browser bridge must fail closed.');
bcSame(false, $realWriteResult['retry_safe'], 'Unknown external write state must not blind-retry.');

$enabled = $base; $enabled['dry_run']=false; $enabled['write_flags']=['SAFE_T_SUBMIT'=>false];
$disabledResult = runNodeAdapter($enabled);
bcSame('ACCEPTED', $disabledResult['status'], 'Disabled write flag remains safe evaluation.');
bcSame(false, $disabledResult['submitted'], 'Write flag off cannot submit.');
bcSame(true, $disabledResult['dry_run'], 'Write flag off forces effective dry-run.');

$worker = new SvAmazonSellerCentralWorker();
$result = $worker->execute(['kind'=>'SAFE_T_SUBMIT','payload'=>['case_id'=>77]], static fn(array $payload): array => ['status'=>'ACCEPTED','submitted'=>false,'dry_run'=>true,'external_id'=>null]);
bcSame('ACCEPTED', $result['status'], 'Worker accepts structured adapter result.');
$thrown = false;
try { $worker->execute(['kind'=>'DELETE_ANYTHING','payload'=>[]], static fn(array $payload): array => []); } catch (InvalidArgumentException) { $thrown = true; }
bcAssert($thrown, 'Worker must reject unapproved operation kinds.');

echo "amazon-returns-browser-contract-test: OK\n";
