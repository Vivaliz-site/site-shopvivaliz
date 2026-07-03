<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

ml_load_env();
$tokens    = ml_tokens();
$token     = ml_access_token();
$connected = !empty($token);

$userId    = $tokens['user_id']    ?? getenv('ML_SELLER_ID') ?: null;
$expiresAt = isset($tokens['expires_at']) ? date('c', (int)$tokens['expires_at']) : null;

ml_json([
    'endpoint'   => 'status',
    'connected'  => $connected,
    'user_id'    => $userId,
    'expires_at' => $expiresAt,
    'token_source' => $token ? (getenv('ML_ACCESS_TOKEN') ? 'env' : 'file') : null,
]);
