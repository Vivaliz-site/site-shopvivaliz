<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/schema.php';
$mysql=implode("\n",sv_ar_schema_statements('mysql'));
foreach(['amazon_recovery_cases','amazon_recovery_events','amazon_recovery_ledger','amazon_recovery_policies','amazon_recovery_jobs','amazon_recovery_outbox','amazon_recovery_dlq','amazon_recovery_evidence','amazon_recovery_source_cursors'] as $table) ar_t_assert(str_contains($mysql,$table),"schema includes {$table}");
ar_t_assert(str_contains($mysql,'case_key'),'cases have stable case key');ar_t_assert(str_contains($mysql,'idempotency_key'),'jobs/outbox have idempotency');ar_t_assert(str_contains($mysql,'payload_hash'),'events/evidence have hash');ar_t_assert(str_contains(strtoupper($mysql),'UNIQUE'),'schema has uniqueness constraints');
ar_t_ok('schema statements');
