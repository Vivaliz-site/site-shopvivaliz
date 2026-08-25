<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-remote-bootstrap.ps1';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: fredwin bootstrap ausente\n"); exit(1); }
$s = file_get_contents($path);
if (strpos($s, '$ssh = @(Get-ManagedSsh)') === false) {
    fwrite(STDERR, "FALHOU: relay singleton ainda sujeito a Count escalar\n"); exit(1);
}
echo "fredwin-relay-singleton-count-contract: ok\n";
