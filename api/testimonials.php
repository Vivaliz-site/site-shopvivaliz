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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    // Ate 2026-08-18 este backfill rodava a CADA GET (ou seja, a cada
    // visita a home, via js/public-experience-v1.js), disparando ate 50
    // UPDATEs no banco por pageview. Agora roda no maximo 1x a cada 5
    // minutos, usando APCu como trava global -- sem isso, so cron/
    // workflow_dispatch deveriam fazer esse trabalho, mas nao havia nenhum
    // registrado, entao mantemos o backfill (mais raro) em vez de remover
    // a funcionalidade. Ver relatorio da Rodada 1 de melhoria continua.
    $canModerate = true;
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $flagKey = 'sv_testimonials_moderate_lock_v1';
        $ok = false;
        apcu_fetch($flagKey, $ok);
        if ($ok) {
            $canModerate = false;
        } else {
            apcu_store($flagKey, 1, 300);
        }
    }
    if ($canModerate) {
        sv_testimonials_moderate_pending($repo, new LizTestimonialModerator(false), 50);
    }
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
// Ate 2026-08-18 o throttle era guardado so em $_SESSION: quem nao manda
// cookie de sessao ganha uma sessao nova a cada request, entao o limite de
// "1 por minuto" nunca acumulava de fato. Agora o limite tambem e aplicado
// por IP via APCu (quando disponivel), que nao depende de cookie. Ver
// relatorio da Rodada 1 de melhoria continua.
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($clientIp !== '' && function_exists('apcu_fetch') && function_exists('apcu_store')) {
    $ipKey = 'sv_testimonial_throttle_' . md5($clientIp);
    $ok = false;
    $lastIp = apcu_fetch($ipKey, $ok);
    if ($ok && is_int($lastIp) && time() - $lastIp < 60) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Aguarde um minuto antes de enviar outra avaliação.']);
        exit;
    }
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
    $moderator = new LizTestimonialModerator(true);
    $decision = $moderator->moderate($input);
    $row = $repo->submit($input, $decision);
    $_SESSION['testimonial_last_submit'] = time();
    if ($clientIp !== '' && function_exists('apcu_store')) {
        apcu_store('sv_testimonial_throttle_' . md5($clientIp), time(), 60);
    }

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
