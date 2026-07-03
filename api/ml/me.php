<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$data = ml_get('/users/me');
if (isset($data['error']) && $data['error'] === 'sem_token') {
    ml_json(['error' => 'not_connected', 'message' => 'Token Mercado Livre não encontrado.'], 401);
}
ml_json($data);
