<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

ml_load_env();
$tokens = ml_tokens();

if (empty($tokens['refresh_token'])) {
    ml_json(['ok' => false, 'error' => 'refresh_token_ausente'], 400);
}

$newToken = ml_refresh($tokens);
if ($newToken) {
    ml_json(['ok' => true, 'renewed_at' => date('c')]);
} else {
    ml_json(['ok' => false, 'error' => 'falha_ao_renovar'], 500);
}
