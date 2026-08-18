<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/testimonials-repository.php';
require_once __DIR__ . '/../includes/liz-testimonial-moderator.php';

$moderator = new LizTestimonialModerator(false);

$safe = $moderator->moderate([
    'rating' => 5,
    'message' => 'Chegou rápido, produto bom. Recomendo a loja e compraria novamente.',
]);
if (($safe['status'] ?? '') !== 'approved') {
    fwrite(STDERR, "Avaliação legítima deveria ser aprovada pela Liz.\n");
    exit(1);
}

$spam = $moderator->moderate([
    'rating' => 5,
    'message' => 'Ganhe dinheiro rápido no nosso grupo VIP: https://example.com e https://spam.example.com',
]);
if (($spam['status'] ?? '') !== 'rejected') {
    fwrite(STDERR, "Spam deveria ser rejeitado pela Liz.\n");
    exit(1);
}

$sensitive = $moderator->moderate([
    'rating' => 3,
    'message' => 'Meu telefone é 11999998888 e preciso que entrem em contato sobre meu pedido.',
]);
if (($sensitive['status'] ?? '') !== 'pending') {
    fwrite(STDERR, "Conteúdo com dado pessoal deveria ficar pendente.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/shopvivaliz-testimonials-' . bin2hex(random_bytes(5)) . '.json';
$repo = new TestimonialsRepository($tmp);
try {
    $saved = $repo->submit([
        'name' => 'Cliente Teste',
        'city' => 'Divinópolis - MG',
        'rating' => 5,
        'message' => 'Chegou rápido, produto bom. Recomendo a loja e compraria novamente.',
        'order_reference' => '12345',
        'verified_purchase' => true,
    ], $safe);
    if (($saved['status'] ?? '') !== 'approved' || ($saved['moderated_by'] ?? '') !== 'liz') {
        fwrite(STDERR, "Decisão da Liz não foi persistida.\n");
        exit(1);
    }

    $legacy = $repo->submit([
        'name' => 'Cliente Legado',
        'city' => 'São Paulo - SP',
        'rating' => 4,
        'message' => 'Atendimento bom e entrega dentro do prazo informado no pedido.',
        'verified_purchase' => true,
    ]);
    $pending = $repo->pendingUnmoderated(10);
    if (count($pending) !== 1 || ($pending[0]['id'] ?? '') !== ($legacy['id'] ?? '')) {
        fwrite(STDERR, "Fila pendente antiga não foi detectada para moderação automática.\n");
        exit(1);
    }

    $decision = $moderator->moderate($pending[0]);
    $ok = $repo->moderate(
        (string)$pending[0]['id'],
        (string)$decision['status'],
        (string)$decision['reason'],
        (string)$decision['moderated_by'],
        (string)$decision['provider'],
        null
    );
    if (!$ok || count($repo->approved(10)) !== 2) {
        fwrite(STDERR, "Backfill da fila pendente não publicou a avaliação legítima.\n");
        exit(1);
    }

    foreach ($repo->approved(10) as $publicRow) {
        if (array_key_exists('order_reference', $publicRow) || array_key_exists('moderation_reason', $publicRow)) {
            fwrite(STDERR, "API pública não deve expor referência do pedido nem metadados internos.\n");
            exit(1);
        }
    }

    $unverified = $repo->submit([
        'name' => 'Visitante sem confirmação',
        'rating' => 5,
        'message' => 'Comentário válido, mas sem uma compra que o servidor tenha confirmado.',
        'verified_purchase' => false,
    ]);
    $pending = $repo->pendingUnmoderated(10);
    if (in_array((string)($unverified['id'] ?? ''), array_column($pending, 'id'), true)) {
        fwrite(STDERR, "Avaliação sem compra confirmada não pode entrar no auto-approve.\n");
        exit(1);
    }
} finally {
    @unlink($tmp);
    @unlink($tmp . '.tmp');
}

fwrite(STDOUT, "OK testimonials Liz moderation smoke\n");
