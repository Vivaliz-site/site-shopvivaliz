<?php
$root = dirname(__DIR__);
$path = $root . '/ops/fredwin-desktop-commander-request.json';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: request Fred-Win ausente\n"); exit(1); }
$raw = file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data)) { fwrite(STDERR, "FALHOU: request JSON invalido\n"); exit(1); }
if (($data['action'] ?? null) !== 'authorize_interactive') { fwrite(STDERR, "FALHOU: action deve ser authorize_interactive\n"); exit(1); }
if (stripos($raw, 'device code') !== false || stripos($raw, 'token') !== false) { fwrite(STDERR, "FALHOU: request nao deve conter dados de autorizacao\n"); exit(1); }
echo "fredwin-desktop-commander-interactive-auth-request-contract: ok\n";
