<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/ops/marina_pricing_repair.php';
function same($actual, $expected, $label) {if ($actual !== $expected) {throw new RuntimeException($label . ': actual=' . var_export($actual,true) . ', expected=' . var_export($expected,true));}}
function refused(callable $fn, string $label): void {try{$fn();}catch (InvalidArgumentException $e){return;} throw new RuntimeException('missing safety rejection: '.$label);}
$fixture="APP_ENV=prod\nDATABASE_URL=\"mysql://pricing_user:complex%40pwd@127.0.0.1:13312/ml_pricing_prod?serverVersion=8.0\"\nAPP_SECRET=DO_NOT_PRINT\n";
$data=MarinaPricingRecovery::config($fixture);
same($data['hostname'], '127.0.0.1', 'host');
same($data['port'], 13312, 'source port');
same($data['database'], 'ml_pricing_prod', 'database');
same($data['username'], 'pricing_user', 'username');
same($data['password'], 'complex@pwd', 'password decode');
$after=MarinaPricingRecovery::rewritePort($fixture);
same(substr_count($after, ':3306/'),1,'replacement unique');
same(str_replace('127.0.0.1:13312','127.0.0.1:3306',$fixture),$after,'non-secret content preserved');
same(MarinaPricingRecovery::rewritePort($after),$after,'idempotent');
refused(fn()=>MarinaPricingRecovery::config("DATABASE_URL=mysql://pricing_user:secret@10.0.0.1:13312/ml_pricing_prod\n"),'nonloopback');
refused(fn()=>MarinaPricingRecovery::config("DATABASE_URL=mysql://pricing_user:secret@127.0.0.1:13312/shopvivaliz\n"),'schema');
refused(fn()=>MarinaPricingRecovery::config("DATABASE_URL=mysql://root:secret@127.0.0.1:13312/ml_pricing_prod\n"),'root user');
refused(fn()=>MarinaPricingRecovery::config("DATABASE_URL=mysql://pricing_user:secret@127.0.0.1:20000/ml_pricing_prod\n"),'unexpected port');
refused(fn()=>MarinaPricingRecovery::config("DATABASE_URL=mysql://pricing_user:secret@127.0.0.1:13312/ml_pricing_prod\nDATABASE_URL=mysql://pricing_user:secret@127.0.0.1:13312/ml_pricing_prod\n"),'duplicate env');
refused(fn()=>MarinaPricingRecovery::config("APP_ENV=prod\n"),'missing env');
$summary=MarinaPricingRecovery::summary($data);
if (str_contains(json_encode($summary),'complex') || str_contains(json_encode($summary),'pwd') || str_contains(json_encode($summary),'APP_SECRET')) throw new RuntimeException('secret leaked to summary');
refused(fn()=>MarinaPricingRecovery::assertRecentSourceTimestamp('2026-01-01T00:00:00Z', time()), 'stale source fingerprint');
refused(fn()=>MarinaPricingRecovery::assertRecentSourceTimestamp(gmdate('Y-m-d\TH:i:s\Z', time()+1000), time()), 'future source fingerprint');
MarinaPricingRecovery::assertRecentSourceTimestamp(gmdate('Y-m-d\TH:i:s\Z', time()-60), time());
refused(fn()=>MarinaPricingRecovery::assertGeneralLogOff('ON'), 'general log ON');
MarinaPricingRecovery::assertGeneralLogOff('OFF');
MarinaPricingRecovery::assertGeneralLogOff('0');
refused(fn()=>MarinaPricingRecovery::assertOriginalDbPortUnbound(true), 'live original DB listener');
MarinaPricingRecovery::assertOriginalDbPortUnbound(false);
same(MarinaPricingRecovery::countFingerprint(['table_b'=>2,'table_a'=>1]),hash('sha256',json_encode(['table_a'=>1,'table_b'=>2])),'stable fingerprint');
echo "MARINA_PRICING_SAFETY_TESTS=PASS\n";
