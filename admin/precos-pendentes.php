<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/tiny-price-owner-review.php';

// Costs are confidential. A generic admin account is NOT an owner approval.
$ownerId = filter_var(getenv('SHOPVIVALIZ_PRICE_OWNER_USER_ID'), FILTER_VALIDATE_INT);
if ($ownerId === false || $ownerId === null || $ownerId < 1
    || (int)($_SESSION['user_id'] ?? 0) !== (int)$ownerId) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Fila de preços disponível somente para o proprietário identificado.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit('Método não permitido.');
}

$requests = [];
$error = '';
try {
    $requests = svtpp_pending_price_owner_reviews();
} catch (Throwable $e) {
    http_response_code(503);
    $error = 'Armazenamento privado indisponível: ' . $e->getMessage();
}
function svtpp_review_html(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preços aguardando validação - ShopVivaliz</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;line-height:1.45}
        table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:.7rem;text-align:left}
        th{background:#f5f5f5}.warning{color:#9a4b00}.info{background:#f2f5fb;padding:1rem}
        .scroll{overflow-x:auto}
    </style>
</head>
<body>
    <p><a href="/admin/produtos.php">← Voltar aos produtos</a></p>
    <h1>Solicitações de preço e custo para validação</h1>
    <p class="info">Estas propostas NÃO alteram os preços do catálogo nem enviam PUT de preço ao Tiny.
    Valide os valores, incluindo custos zerados, e autorize/aplique a mudança pelo fluxo
    humano autenticado oficial do ERP. Esta lista é apenas para revisão: visualizá-la
    não aprova nem publica preços. Não há autorização automática por agente.</p>
    <?php if ($error !== ''): ?>
        <p role="alert"><?= svtpp_review_html($error) ?></p>
    <?php elseif ($requests === []): ?>
        <p>Nenhuma proposta comercial pendente.</p>
    <?php else: ?>
        <div class="scroll"><table>
            <thead><tr><th>Produto / ID</th><th>Preço atual</th><th>Preço proposto</th><th>Custo atual</th><th>Custo proposto</th><th>Promoção atual → proposta</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><?= svtpp_review_html($request['sku'] ?? '') ?>
                        <small>(<?= svtpp_review_html($request['product_id'] ?? '') ?>)</small></td>
                    <td>R$ <?= svtpp_review_html($request['before']['sale_price'] ?? '') ?></td>
                    <td>R$ <?= svtpp_review_html($request['proposed']['sale_price'] ?? '') ?></td>
                    <td>R$ <?= svtpp_review_html($request['before']['cost_price'] ?? '') ?></td>
                    <td>R$ <?= svtpp_review_html($request['proposed']['cost_price'] ?? '') ?></td>
                    <td><?= svtpp_review_html($request['before']['promotional_price'] ?? '') ?> → <?= svtpp_review_html($request['proposed']['promotional_price'] ?? '') ?></td>
                    <td><?= !empty($request['warning_cost_zero']) ? '<strong class="warning">Custo zero: revisão obrigatória</strong>' : 'Aguardando autorização' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</body>
</html>
