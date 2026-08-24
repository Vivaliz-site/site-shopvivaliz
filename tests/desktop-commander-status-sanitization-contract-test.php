<?php
$root = dirname(__DIR__);
$script = $root . '/scripts/desktop-commander-control-plane-status.py';
if (!is_file($script)) { fwrite(STDERR, "FALHOU: formatter ausente\n"); exit(1); }
$input = tempnam(sys_get_temp_dir(), 'dc-in-');
$output = tempnam(sys_get_temp_dir(), 'dc-out-');
$data = [[
    'host' => 'LAPTOP-NIG4IFUU',
    'state' => 'healthy',
    'watchdog' => 'ready',
    'canonical_agent_count' => 1,
    'auth_required' => false,
    'last_success' => '2026-08-24T00:00:00Z',
    'last_recovery' => 'none',
    'run_id' => '123',
    'access_token' => 'SECRET',
    'session_blob' => 'SECRET',
    'device_code' => 'SECRET',
    'command_line' => 'SECRET',
    'relay_url' => 'http://127.0.0.1:5557',
    'private_key' => 'SECRET'
]];
file_put_contents($input, json_encode($data));
$cmd = 'python ' . escapeshellarg($script) . ' --input ' . escapeshellarg($input) . ' --json-out ' . escapeshellarg($output);
exec($cmd, $lines, $rc);
if ($rc !== 0) { fwrite(STDERR, "FALHOU: formatter rc={$rc}\n"); exit(1); }
$raw = file_get_contents($output);
$decoded = json_decode($raw, true);
if (!is_array($decoded) || count($decoded) !== 1) { fwrite(STDERR, "FALHOU: JSON sanitizado invalido\n"); exit(1); }
$row = $decoded[0];
$required = ['host','state','watchdog','canonical_agent_count','auth_required','last_success','last_recovery','run_id'];
foreach ($required as $key) { if (!array_key_exists($key, $row)) { fwrite(STDERR, "FALHOU: chave segura ausente {$key}\n"); exit(1); } }
foreach (['token','cookie','device_code','session','private_key','device.json','command_line','relay_url','127.0.0.1'] as $needle) {
    if (stripos($raw, $needle) !== false) { fwrite(STDERR, "FALHOU: dado sensivel vazou {$needle}\n"); exit(1); }
}
@unlink($input); @unlink($output);
echo "desktop-commander-status-sanitization-contract: ok\n";
