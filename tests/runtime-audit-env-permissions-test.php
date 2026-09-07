<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$runtimePath = $root . '/.github/workflows/runtime-token-security.yml';
$runtime = is_file($runtimePath) ? (string)file_get_contents($runtimePath) : '';
foreach ([
    'sudo -u "$php_user" test -r "$env_file"',
    'sudo python3 "$current/scripts/maintenance/runtime_token_audit.py"',
    'sudo python3 "$current/scripts/maintenance/token_reference_audit.py"',
] as $fragment) {
    if (!str_contains($runtime, $fragment)) {
        $errors[] = 'runtime_audit_missing:' . $fragment;
    }
}

foreach ([
    'sudo chown ubuntu:"$env_group" "$env_file"',
    'sudo chmod 0640 "$env_file"',
] as $forbidden) {
    if (str_contains($runtime, $forbidden)) {
        $errors[] = 'runtime_audit_mutates_env_permissions:' . $forbidden;
    }
}

$keysetPath = $root . '/.github/workflows/runtime-env-keyset-lock.yml';
$keyset = is_file($keysetPath) ? (string)file_get_contents($keysetPath) : '';
if (!str_contains($keyset, 'sudo test -r "$VM_ENV"')) {
    $errors[] = 'keyset_guard_missing_privileged_env_read_check';
}
if (preg_match('/^\s*test -r "\$VM_ENV"/m', $keyset) === 1) {
    $errors[] = 'keyset_guard_uses_unprivileged_env_read_check';
}

$quality = (string)file_get_contents($root . '/.github/workflows/quality-gate.yml');
if (!str_contains($quality, 'php tests/runtime-audit-env-permissions-test.php')) {
    $errors[] = 'quality_gate_missing_runtime_env_permission_contract';
}

if ($errors !== []) {
    fwrite(STDERR, json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo "runtime-audit-env-permissions: ok\n";
