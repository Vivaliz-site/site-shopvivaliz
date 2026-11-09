<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/tiny-product-push.php';
require_once __DIR__ . '/../includes/csrf.php';

// Costs are confidential. A generic admin account is NOT an owner approval.
$ownerId = filter_var(getenv('SHOPVIVALIZ_PRICE_OWNER_USER_ID'), FILTER_VALIDATE_INT);
if ($ownerId === false || $ownerId === null || $ownerId < 1
    || (int)($_SESSION['user_id'] ?? 0) !== (int)$ownerId) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Fila de preços disponível somente para o proprietário identificado.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    exit('Método não permitido.');
}

$requests = [];
$error = '';
$feedback = '';
if ($method === 'POST') {
    if (!sv_csrf_valid('owner-price-approval', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Sessão expirada ou token CSRF inválido.';
    } else {
        try {
            if (!svtop_tiny_credentials_configured()) {
                throw new RuntimeException('Credenciais ERP não disponíveis.');
            }
            $token = svtop_tiny_get_token();
            if ($token === '') {
                throw new RuntimeException('Autenticação oficial ERP indisponível.');
            }
            $result = svtpp_apply_owner_approved_price(
                (string)($_POST['request_id'] ?? ''),
                (int)$_SESSION['user_id'],
                static fn(string $verb, string $path, ?array $payload): array =>
                    svtop_tiny_request($verb, $path, $token, $payload)
            );
            $feedback = match ($result['status']) {
                'applied' => 'Preço aprovado e aplicado ao ERP com confirmação por leitura. A sincronização do catálogo segue a rotina de leitura existente.',
                'already_applied' => 'Preço já havia sido aplicado; nenhuma escrita foi repetida.',
                default => 'Envio com confirmação pendente. O sistema NÃO repetirá a escrita automaticamente; conferir o preço oficial no ERP.',
            };
        } catch (Throwable $e) {
            $error = 'Preço não aplicado: ' . $e->getMessage();
        }
    }
}
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
    <p class="info">A API gera propostas sem publicar preços. <strong>Seu clique em "Aprovar e aplicar"</strong>
    é a autorização explícita para o sistema atualizar <strong>imediatamente</strong>
    o preço de venda e promocional exatos no Tiny por operação autenticada e de uso único.
    Não existe execução por horário ou aprovação automática. O custo zerado é permitido
    para sua análise e não é alterado por este botão; solicitações somente de custo
    exigem tratamento separado. Antes da escrita o preço atual é conferido no ERP.
    Se a resposta for ambígua, o sistema não repete o envio e exige conciliação.</p>
    <?php if ($feedback !== ''): ?><p role="status"><?= svtpp_review_html($feedback) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p role="alert"><?= svtpp_review_html($error) ?></p><?php endif; ?>
    <?php if ($requests === []): ?>
        <p>Nenhuma proposta comercial pendente.</p>
    <?php else: ?>
        <div class="scroll"><table>
            <thead><tr><th>Produto / ID</th><th>Preço atual</th><th>Preço proposto</th><th>Custo atual</th><th>Custo proposto</th><th>Promoção atual → proposta</th><th>Estado</th><th>Ação do proprietário</th></tr></thead>
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
                    <td>
                      <?php $hasPriceChange = ($request['before']['sale_price'] ?? null) !== ($request['proposed']['sale_price'] ?? null)
                          || ($request['before']['promotional_price'] ?? null) !== ($request['proposed']['promotional_price'] ?? null); ?>
                      <?php if ($hasPriceChange): ?>
                        <form method="post">
                          <?= sv_csrf_input('owner-price-approval') ?>
                          <input type="hidden" name="request_id" value="<?= svtpp_review_html($request['request_id'] ?? '') ?>">
                          <button type="submit">Aprovar e aplicar</button>
                        </form>
                      <?php else: ?>
                        <span>Somente custo: sem publicação de preço</span>
                      <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</body>
</html>
