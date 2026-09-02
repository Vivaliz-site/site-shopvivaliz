<?php
declare(strict_types=1);
$root = $argv[1] ?? dirname(__DIR__);
$path = $root . '/scripts/vm-desktop-commander-supervisor.sh';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: supervisor VM ausente {$path}\n"); exit(1); }
$script = file_get_contents($path);
if ($script === false) { fwrite(STDERR, "FALHOU: nao foi possivel ler supervisor VM\n"); exit(1); }
if (!preg_match("/^AUTH_REGEX='([^']+)'$/m", $script, $m)) {
    fwrite(STDERR, "FALHOU: AUTH_REGEX ausente\n"); exit(1);
}
$auth = $m[1];
foreach (['Please complete authentication','Starting device authorization flow','device code','Authorization required','Persisted session invalid'] as $needle) {
    if (strpos($auth, $needle) === false) { fwrite(STDERR, "FALHOU: AUTH_REGEX sem {$needle}\n"); exit(1); }
}
if (strpos($auth, 'Authenticating with Remote MCP server') !== false) {
    fwrite(STDERR, "FALHOU: mensagem informativa de autenticacao ainda classificada como AUTH_REQUIRED\n"); exit(1);
}
echo "vm-desktop-commander-auth-classification-contract: ok\n";