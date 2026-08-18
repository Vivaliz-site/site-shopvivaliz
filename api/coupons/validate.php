<?php
// Desativado em 2026-08-18 (Rodada 2 de melhoria contínua): endpoint legado,
// sem rate limit, com Access-Control-Allow-Origin: *, que declarava válidos
// cupons hardcoded no código (FRETEGRATIS, PRIMEIRA10, VIVALIZ10, BEMVINDO5)
// mesmo quando não existiam no banco -- e ignorava min_order_value,
// starts_at, owner_email e redeemed_at, todas regras que o motor oficial
// (includes/coupons.php::svcp_validate, usado em
// api/orders/create-validated.php) aplica corretamente. Nenhum arquivo do
// projeto consome este endpoint (confirmado via grep; só
// api/coupons/active.php é consumido, por includes/popup-cupons.php). Use
// /api/orders/validate-coupon.php. Ver achado B3 no relatório da Rodada 2 de
// melhoria contínua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => false, 'error' => 'Endpoint descontinuado. Use /api/orders/validate-coupon.php.']);
