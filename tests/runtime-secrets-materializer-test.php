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
        '',
    ]));

    $result = rsm_run($script, $source, $target);
    rsm_assert($result['exit'] === 0, 'valid runtime materialization should succeed');
    rsm_assert(is_file($target), 'runtime secrets file should be created');
    rsm_assert(str_contains($result['stdout'], 'runtime_secrets_materialized=true'), 'success evidence should be emitted');
    rsm_assert(!str_contains($result['stdout'] . $result['stderr'], $signingKey), 'secret values must never be logged');

    $values = require $target;
    rsm_assert(is_array($values), 'generated PHP file should return an array');
    rsm_assert(($values['DB_USER'] ?? '') === 'shop_runtime', 'database user should be preserved');
    rsm_assert(($values['SHOPVIVALIZ_AGENT_KEY'] ?? '') === $signingKey, 'signing fallback should be preserved');
    rsm_assert(($values['OLIST_WEBHOOK_SECRET'] ?? '') === 'olist-secret', 'Olist secret should be included');
    rsm_assert(!array_key_exists('OPENAI_API_KEY', $values), 'unrelated AI keys must not be exported');
    rsm_assert(!array_key_exists('SMTP_PASS', $values), 'unrelated mail secrets must not be exported');
    // The Oracle contract is POSIX 0640. Windows reports synthetic mode bits
    // and cannot represent this chmod contract through the local filesystem.
    if (PHP_OS_FAMILY !== 'Windows') {
        rsm_assert((fileperms($target) & 0777) === 0640, 'generated file mode should be 0640');
    }

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
    @unlink($invalidTarget);
    @unlink($target);
    @unlink($source);
    @rmdir($root);
}
