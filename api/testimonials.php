<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/includes/testimonials-repository.php';
$repo = new TestimonialsRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    echo json_encode(['ok' => true, 'items' => $repo->approved(12)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}
$input = $_POST;
if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
if (trim((string)($input['website'] ?? '')) !== '') {
    echo json_encode(['ok' => true, 'message' => 'Recebido.']);
    exit;
}
$last = (int)($_SESSION['testimonial_last_submit'] ?? 0);
if ($last > 0 && time() - $last < 60) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Aguarde um minuto antes de enviar outra avaliação.']);
    exit;
}
$name = trim((string)($input['name'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$rating = (int)($input['rating'] ?? 0);
if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || mb_strlen($message) < 20 || mb_strlen($message) > 1000 || $rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Revise nome, nota e comentário. O comentário deve ter entre 20 e 1000 caracteres.']);
    exit;
}
try {
    $repo->submit($input);
    $_SESSION['testimonial_last_submit'] = time();
    echo json_encode(['ok' => true, 'message' => 'Avaliação enviada para moderação.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível enviar a avaliação agora.']);
}
