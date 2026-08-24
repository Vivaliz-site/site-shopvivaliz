<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/local-auto-sync.ps1';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: local-auto-sync ausente\n"); exit(1); }
$s = file_get_contents($path);
foreach (['git status --porcelain','Working tree dirty; skipping fast-forward sync'] as $needle) {
    if (strpos($s, $needle) === false) {
        fwrite(STDERR, "FALHOU: auto-sync sem {$needle}\n"); exit(1);
    }
}
echo "local-auto-sync-dirty-tree-contract: ok\n";
