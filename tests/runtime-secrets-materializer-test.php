<?php

declare(strict_types=1);

function rsm_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function rsm_run(string $script, string $source, string $target): array
{
    $command = [PHP_BINARY, $script];
    $environment = array_merge($_ENV, [
        'SHOPVIVALIZ_SHARED_ENV' => $source,
        'SHOPVIVALIZ_RUNTIME_SECRETS' => $target,
        'SHOPVIVALIZ_SHARED_GROUP' => function_exists('posix_getgrgid')
            ? (string)(posix_getgrgid(posix_getegid())['name'] ?? '')
            : '',
    ]);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('materializer_process_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = sys_get_temp_dir() . '/runtime-secrets-test-' . bin2hex(random_bytes(6));
$source = $root . '/shared.env';
$target = $root . '/runtime-secrets.php';
$invalidTarget = $root . '/invalid-runtime-secrets.php';
$script = dirname(__DIR__) . '/scripts/materialize-runtime-secrets.php';
mkdir($root, 0700, true);

try {
    $signingKey = str_repeat('a', 40);
    file_put_contents($source, implode("\n", [
        'DB_HOST=db.internal',
        'DB_PORT=3307',
        'DB_NAME=shopvivaliz',
        'DB_USER=shop_runtime',
        'DB_PASS=database-password',
        'SHOPVIVALIZ_AGENT_KEY=' . $signingKey,
        'OLIST_WEBHOOK_SECRET=olist-secret',
        'OPENAI_API_KEY=must-not-be-exported',
        'SMTP_PASS=must-not-be-exported',
        'PAGARME_LEGACY_KEY=must-remain-in-env',
        '',
    ]));

    $result = rsm_run($script, $source, $target);
    rsm_assert($result['exit'] === 0, 'valid runtime materialization should succeed');
    rsm_assert(is_file($target), 'runtime secrets file should be created');
    rsm_assert(str_contains($result['stdout'], 'runtime_secrets_materialized=true'), 'success evidence should be emitted');
    rsm_assert(str_contains($result['stdout'], 'retired_runtime_keys_removed=0'), 'materializer must report zero env-key removals');
    rsm_assert(!str_contains($result['stdout'] . $result['stderr'], $signingKey), 'secret values must never be logged');

    $sourceAfter = file_get_contents($source);
    rsm_assert(is_string($sourceAfter), 'shared env should remain readable');
    rsm_assert(str_contains($sourceAfter, 'PAGARME_LEGACY_KEY='), 'materializer must never delete an existing env key name');

    $values = require $target;
    rsm_assert(is_array($values), 'generated PHP file should return an array');
    rsm_assert(($values['DB_USER'] ?? '') === 'shop_runtime', 'database user should be preserved');
    rsm_assert(($values['SHOPVIVALIZ_AGENT_KEY'] ?? '') === $signingKey, 'signing fallback should be preserved');
    rsm_assert(($values['OLIST_WEBHOOK_SECRET'] ?? '') === 'olist-secret', 'Olist secret should be included');
    rsm_assert(!array_key_exists('OPENAI_API_KEY', $values), 'unrelated AI keys must not be exported');
    rsm_assert(!array_key_exists('SMTP_PASS', $values), 'unrelated mail secrets must not be exported');
    rsm_assert(!array_key_exists('PAGARME_LEGACY_KEY', $values), 'retired key may remain stored but must not be materialized');
    // The Oracle contract is POSIX 0640. Windows reports synthetic mode bits
    // and cannot represent this chmod contract through the local filesystem.
    if (PHP_OS_FAMILY !== 'Windows') {
        rsm_assert((fileperms($target) & 0777) === 0640, 'generated file mode should be 0640');
        rsm_assert((fileperms($source) & 0777) === 0640, 'shared env mode should be 0640');
    }

    // Task 9 (propriedade MLRR): com owner=mlrr o runtime protegido carrega a
    // metadata de propriedade e nenhum valor de token legado do Mercado Livre.
    $mlrrTarget = $root . '/mlrr-runtime-secrets.php';
    $snapshotPath = '/home/ubuntu/shopvivaliz-deploy/shared/storage/private/ml-access-token.json';
    file_put_contents($source, implode("\n", [
        'DB_HOST=db.internal',
        'DB_PORT=3307',
        'DB_NAME=shopvivaliz',
        'DB_USER=shop_runtime',
        'DB_PASS=database-password',
        'SHOPVIVALIZ_AGENT_KEY=' . $signingKey,
        'ML_TOKEN_OWNER=mlrr',
        'ML_ACCESS_SNAPSHOT_FILE=' . $snapshotPath,
        'ML_CLIENT_ID=4695185185661070',
        'ML_CLIENT_SECRET=static-app-secret',
        'ML_SELLER_ID=112962856',
        'ML_ACCESS_TOKEN=legacy-access',
        'ML_REFRESH_TOKEN=legacy-refresh',
        'MERCADO_LIVRE_ACCESS_TOKEN=legacy-access-alias',
        'MERCADO_LIVRE_REFRESH_TOKEN=legacy-refresh-alias',
        '',
    ]));

    $mlrr = rsm_run($script, $source, $mlrrTarget);
    rsm_assert($mlrr['exit'] === 0, 'MLRR-owned materialization should succeed');
    $mlrrValues = require $mlrrTarget;
    rsm_assert(is_array($mlrrValues), 'MLRR runtime should return an array');
    rsm_assert(($mlrrValues['ML_TOKEN_OWNER'] ?? '') === 'mlrr', 'owner metadata should be materialized');
    rsm_assert(($mlrrValues['ML_ACCESS_SNAPSHOT_FILE'] ?? '') === $snapshotPath, 'snapshot path should be materialized');
    rsm_assert(($mlrrValues['ML_CLIENT_ID'] ?? '') === '4695185185661070', 'static app id must be kept');
    rsm_assert(($mlrrValues['ML_CLIENT_SECRET'] ?? '') === 'static-app-secret', 'static app secret must be kept');
    rsm_assert(($mlrrValues['ML_SELLER_ID'] ?? '') === '112962856', 'static seller id must be kept');
    foreach (['ML_ACCESS_TOKEN', 'ML_REFRESH_TOKEN', 'MERCADO_LIVRE_ACCESS_TOKEN', 'MERCADO_LIVRE_REFRESH_TOKEN'] as $legacyKey) {
        rsm_assert(!array_key_exists($legacyKey, $mlrrValues), "legacy {$legacyKey} must not be materialized under MLRR ownership");
    }
    rsm_assert(!str_contains(file_get_contents($mlrrTarget), 'legacy-refresh'), 'legacy refresh value must never reach the runtime file');
    rsm_assert(!str_contains($mlrr['stdout'] . $mlrr['stderr'], 'legacy-refresh'), 'legacy refresh value must never be logged');
    rsm_assert(str_contains(file_get_contents($source), 'ML_REFRESH_TOKEN='), 'materializer must not delete env key names');

    // Com owner legacy (padrao pre-cutover) o comportamento historico continua.
    $legacyTarget = $root . '/legacy-runtime-secrets.php';
    file_put_contents($source, implode("\n", [
        'DB_HOST=db.internal',
        'DB_PORT=3307',
        'DB_NAME=shopvivaliz',
        'DB_USER=shop_runtime',
        'DB_PASS=database-password',
        'SHOPVIVALIZ_AGENT_KEY=' . $signingKey,
        'ML_TOKEN_OWNER=legacy',
        'ML_CLIENT_ID=4695185185661070',
        'ML_ACCESS_TOKEN=legacy-access',
        'ML_REFRESH_TOKEN=legacy-refresh',
        '',
    ]));
    $legacy = rsm_run($script, $source, $legacyTarget);
    rsm_assert($legacy['exit'] === 0, 'legacy materialization should succeed');
    $legacyValues = require $legacyTarget;
    rsm_assert(($legacyValues['ML_TOKEN_OWNER'] ?? '') === 'legacy', 'legacy owner metadata should be materialized');
    rsm_assert(($legacyValues['ML_ACCESS_TOKEN'] ?? '') === 'legacy-access', 'legacy access token must be preserved before cutover');
    rsm_assert(($legacyValues['ML_REFRESH_TOKEN'] ?? '') === 'legacy-refresh', 'legacy refresh token must be preserved before cutover');

    file_put_contents($source, implode("\n", [
        'DB_NAME=shopvivaliz',
        'DB_USER=root',
        'SHOPVIVALIZ_AGENT_KEY=' . $signingKey,
        '',
    ]));
    $invalid = rsm_run($script, $source, $invalidTarget);
    rsm_assert($invalid['exit'] !== 0, 'root database tuple must fail closed');
    rsm_assert(!is_file($invalidTarget), 'invalid materialization must not publish a file');

    echo "OK: runtime secrets materializer\n";
} finally {
    @unlink($root . '/mlrr-runtime-secrets.php');
    @unlink($root . '/legacy-runtime-secrets.php');
    @unlink($invalidTarget);
    @unlink($target);
    @unlink($source);
    @rmdir($root);
}
