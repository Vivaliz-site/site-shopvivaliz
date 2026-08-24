<?php
$root = dirname(__DIR__);
$script = $root . '/scripts/desktop-commander-control-plane-status.py';
if (!is_file($script)) { fwrite(STDERR, "FALHOU: formatter ausente\n"); exit(1); }
$tmp = sys_get_temp_dir() . '/dc-status-' . bin2hex(random_bytes(4));
mkdir($tmp);
$windows = "CONTROL_REACHABLE=true\nDEVICE_STATE_EXISTS=true\nCANONICAL_AGENT_COUNT=1\nNONCANONICAL_AGENT_COUNT=0\nTASK_EXISTS=true\nTASK_STATE=Ready\nTASK_LOGON_TYPE=S4U\nTASK_RUN_LEVEL=Highest\nAUTH_REQUIRED=false\nTOKEN=SHOULD_NOT_LEAK\nIP_ADDRESS=203.0.113.7\nCOMMAND_LINE=secret-command\n";
$vm = "CONTROL_REACHABLE=true\nDEVICE_STATE_EXISTS=true\nSERVICE_ENABLED=enabled\nSERVICE_ACTIVE=active\nCANONICAL_REMOTE_COUNT=1\nNONCANONICAL_REMOTE_COUNT=0\nAUTH_REQUIRED=false\nPRIVATE_KEY=SHOULD_NOT_LEAK\nRELAY_URL=http://secret.invalid\n";
file_put_contents("$tmp/fred.txt", $windows);
file_put_contents("$tmp/desktop.txt", $windows);
file_put_contents("$tmp/vm.txt", $vm);
file_put_contents("$tmp/recovery.json", json_encode([
    'LAPTOP-NIG4IFUU' => ['attempted' => false, 'outcome' => 'none'],
    'shopvivaliz-ai' => ['attempted' => false, 'outcome' => 'none'],
    'DESKTOP-KOCEPSV' => ['attempted' => false, 'outcome' => 'none'],
]));
$cmd = 'python3 ' . escapeshellarg($script)
    . ' --fred-status ' . escapeshellarg("$tmp/fred.txt")
    . ' --vm-status ' . escapeshellarg("$tmp/vm.txt")
    . ' --desktop-status ' . escapeshellarg("$tmp/desktop.txt")
    . ' --recovery-json ' . escapeshellarg("$tmp/recovery.json")
    . ' --run-id 12345'
    . ' --json-out ' . escapeshellarg("$tmp/out.json")
    . ' --markdown-out ' . escapeshellarg("$tmp/out.md");
exec($cmd, $output, $code);
if ($code !== 0) { fwrite(STDERR, "FALHOU: formatter exit={$code}\n"); exit(1); }
$jsonText = file_get_contents("$tmp/out.json");
$md = file_get_contents("$tmp/out.md");
$data = json_decode($jsonText, true);
if (!is_array($data) || count($data['hosts'] ?? []) !== 3) { fwrite(STDERR, "FALHOU: schema invalido\n"); exit(1); }
foreach ($data['hosts'] as $host) {
    if (($host['state'] ?? null) !== 'healthy') { fwrite(STDERR, "FALHOU: host nao saudavel\n"); exit(1); }
    foreach (['host','state','watchdog','canonical_agent_count','auth_required','last_success','last_recovery','run_id'] as $key) {
        if (!array_key_exists($key, $host)) { fwrite(STDERR, "FALHOU: campo seguro ausente {$key}\n"); exit(1); }
    }
}
$combined = strtolower($jsonText . $md);
foreach (['should_not_leak','203.0.113.7','secret-command','secret.invalid','private_key','token','relay_url','command_line'] as $needle) {
    if (strpos($combined, strtolower($needle)) !== false) { fwrite(STDERR, "FALHOU: vazamento {$needle}\n"); exit(1); }
}
array_map('unlink', glob("$tmp/*")); rmdir($tmp);
echo "desktop-commander-status-sanitization-contract: ok\n";
