<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: supervisor Fred-Win ausente\n"); exit(1); }
$script = (string) file_get_contents($path);
$watchdog = '$watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 5)';
$guardian = '$guardianWatchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(15) -RepetitionInterval (New-TimeSpan -Minutes 15)';
$forbidden = '-RepetitionInterval (New-TimeSpan -Minutes 1)';
if (strpos($script, $watchdog) === false) { fwrite(STDERR, "FALHOU: Desktop Commander deve repetir a cada 5 minutos\n"); exit(1); }
if (strpos($script, $guardian) === false) { fwrite(STDERR, "FALHOU: guardian deve repetir a cada 15 minutos\n"); exit(1); }
if (strpos($script, $forbidden) !== false) { fwrite(STDERR, "FALHOU: repeticao regressiva de 1 minuto ainda presente\n"); exit(1); }
echo "fredwin-desktop-commander-watchdog-interval-contract: ok\n";
