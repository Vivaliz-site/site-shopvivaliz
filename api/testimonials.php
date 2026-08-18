<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/includes/testimonials-repository.php';
require_once dirname(__DIR__) . '/includes/liz-testimonial-moderator.php';
require_once dirname(__DIR__) . '/includes/testimonial-purchase-verifier.php';
require_once dirname(__DIR__) . '/includes/order-rate-limit.php';

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

function sv_testimonials_same_origin(): bool
{
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) return false;
    $origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($origin === '') return true;
    $host = strtolower(preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))) ?: '');
    $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    return $host !== '' && $originHost !== '' && hash_equals($host, $originHost);
}

$repo = new TestimonialsRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    // Ate 2026-08-18 este backfill rodava a CADA GET (ou seja, a cada
    // visita a home, via js/public-experience-v1.js), disparando ate 50
    // UPDATEs no banco por pageview. Agora roda no maximo 1x a cada 5
    // minutos, usando APCu como trava global. Ver relatorio da Rodada 1 de
    // melhoria continua.
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
if (!sv_testimonials_same_origin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Origem não permitida.']);
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
if (!svorl_allow(3, 3600, 'testimonials')) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de avaliações atingido. Tente novamente mais tarde.']);
    exit;
}
$name = trim((string)($input['name'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$rating = (int)($input['rating'] ?? 0);
$email = strtolower(trim((string)($input['email'] ?? '')));
$orderReference = svtpv_order_reference((string)($input['order_reference'] ?? ''));
$productSku = trim((string)($input['product_sku'] ?? ''));
if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || mb_strlen($message) < 20 || mb_strlen($message) > 1000 || $rating < 1 || $rating > 5
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || $orderReference === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Revise nome, e-mail, número do pedido, nota e comentário.']);
    exit;
}
try {
    $input['order_reference'] = $orderReference;
    $input['product_sku'] = $productSku;
    $input['verified_purchase'] = svtpv_verify($orderReference, $email, $productSku);
    $moderator = new LizTestimonialModerator(true);
    $decision = $moderator->moderate($input);
    if (empty($input['verified_purchase']) && ($decision['status'] ?? '') === 'approved') {
        $decision['status'] = 'pending';
        $decision['reason'] = 'compra_nao_confirmada';
    }
    $row = $repo->submit($input, $decision);
    $status = (string)($row['status'] ?? 'pending');
    $messageText = match ($status) {
        'approved' => 'Compra confirmada e avaliação publicada após moderação. Obrigado por compartilhar sua experiência!',
        'rejected' => 'Avaliação recebida, mas não foi publicada porque a moderação identificou conteúdo incompatível com as regras.',
        default => empty($input['verified_purchase'])
            ? 'Avaliação recebida. Como pedido, e-mail ou pagamento não puderam ser confirmados automaticamente, ela seguirá para revisão da equipe.'
            : 'Compra confirmada. A avaliação foi encaminhada para revisão antes da publicação.',
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
