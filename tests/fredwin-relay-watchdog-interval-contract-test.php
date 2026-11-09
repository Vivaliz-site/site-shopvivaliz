<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-remote-bootstrap.ps1';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: bootstrap Fred-Win ausente\n"); exit(1); }
$script = (string) file_get_contents($path);
$expected = '-RepetitionInterval (New-TimeSpan -Minutes 2)';
$forbidden = '-RepetitionInterval (New-TimeSpan -Minutes 1)';
if (strpos($script, $expected) === false) { fwrite(STDERR, "FALHOU: watchdog do relay deve repetir a cada 2 minutos (Ensure-Task valida 120 s)\n"); exit(1); }
if (strpos($script, $forbidden) !== false) { fwrite(STDERR, "FALHOU: watchdog regressivo de 1 minuto ainda presente\n"); exit(1); }
echo "fredwin-relay-watchdog-interval-contract: ok\n";