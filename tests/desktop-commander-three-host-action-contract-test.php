<?php
$root = dirname(__DIR__);
$files = [
    $root . '/.github/workflows/fred-win-desktop-commander-action.yml',
    $root . '/.github/workflows/vm-desktop-commander-action.yml',
    $root . '/.github/workflows/desktopkocepsv-desktop-commander-action.yml'
];
foreach ($files as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$all = implode("\n", array_map('file_get_contents', $files));
foreach (['SHOPVIVALIZ_VM_SSH_KEY','SHOPVIVALIZ_VM_KNOWN_HOSTS','StrictHostKeyChecking=yes','UserKnownHostsFile=','Action not allowlisted'] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: controle sem {$needle}\n"); exit(1); }
}
foreach (['status)','restart)','install_or_repair)','kill_for_recovery_test)'] as $needle) {
    if (substr_count(strtolower($all), strtolower($needle)) < 3) { fwrite(STDERR, "FALHOU: acao {$needle} nao existe nos tres hosts\n"); exit(1); }
}
foreach (['127.0.0.1:5557','127.0.0.1:5558','desktopkocepsv-desktop-commander-status.ps1','fredwin-desktop-commander-status.ps1','shopvivaliz-desktop-commander.service'] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: interface ausente {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=no','authorize)','read_auth)','device code','verification_uri','access_token','refresh_token','auth_token','command)'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: acao/padrao proibido {$needle}\n"); exit(1); }
}
echo "desktop-commander-three-host-action-contract: ok\n";
