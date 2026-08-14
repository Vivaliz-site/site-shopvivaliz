<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/includes/testimonials-repository.php';
require_once dirname(__DIR__) . '/includes/liz-testimonial-moderator.php';

function sv_testimonials_moderate_pending(TestimonialsRepository $repo, LizTestimonialModerator $moderator, int $limit = 10): void
{
    foreach ($repo->pendingUnmoderated($limit) as $row) {
        try {
            $decision = $moderator->moderate($row);
            $repo->moderate(
                (string)($row['id'] ?? ''),
                (string)($decision['status'] ?? 'pending'),
                (string)($decision['reason'] ?? 'moderacao_liz'),
                (string)($decision['moderated_by'] ?? 'liz'),
                (string)($decision['provider'] ?? 'rules'),
                ($decision['model'] ?? null) !== null ? (string)$decision['model'] : null
            );
        } catch (Throwable $e) {
            error_log('Falha na moderação automática de avaliação pela Liz: ' . $e->getMessage());
        }
    }
}

$repo = new TestimonialsRepository();
$moderator = new LizTestimonialModerator();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    sv_testimonials_moderate_pending($repo, $moderator, 10);
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
    $decision = $moderator->moderate($input);
    $row = $repo->submit($input, $decision);
    $_SESSION['testimonial_last_submit'] = time();

    $status = (string)($row['status'] ?? 'pending');
    $messageText = match ($status) {
        'approved' => 'Avaliação aprovada pela Liz e publicada. Obrigado por compartilhar sua experiência!',
        'rejected' => 'Avaliação recebida, mas não foi publicada porque a moderação identificou conteúdo incompatível com as regras.',
        default => 'Avaliação recebida pela Liz e encaminhada para revisão da equipe antes da publicação.',
    };

    echo json_encode([
        'ok' => true,
        'status' => $status,
        'message' => $messageText,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Falha ao salvar/moderar avaliação: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível enviar a avaliação agora.']);
}
