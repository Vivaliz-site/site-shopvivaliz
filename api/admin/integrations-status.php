<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/integration-health.php';
require_once __DIR__ . '/../../includes/integration-health-marketplaces.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$refreshRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals('1', (string)($_POST['refresh'] ?? ''));

$state = svih_read_state();
$checkedAt = strtotime((string)($state['checked_at'] ?? '')) ?: 0;
if ($state === [] || $checkedAt < time() - 65 * 60 || $refreshRequested) {
    // Atualizar agora apenas refaz as verificacoes. A rotacao OAuth pertence
    // exclusivamente ao daemon shopvivaliz-token-renewer.
    $state = svih_check_all(false);
}

// Acrescenta ao monitor central as mesmas conexões oficiais usadas pela
// publicação real de catálogo. São chamadas autenticadas somente de leitura;
// nenhuma aprovação, preço ou estoque é alterado nesta etapa.
$baseIntegrations = is_array($state['integrations'] ?? null) ? $state['integrations'] : [];
$publicationChecks = svih_marketplace_publication_checks();
$state['integrations'] = array_values(array_merge($baseIntegrations, $publicationChecks));

$connected = count(array_filter(
    $state['integrations'],
    static fn(array $item): bool => (string)($item['status'] ?? '') === 'connected'
));
$failed = count(array_filter(
    $state['integrations'],
    static fn(array $item): bool => (string)($item['status'] ?? '') === 'failed'
));
$attention = count(array_filter(
    $state['integrations'],
    static fn(array $item): bool => in_array((string)($item['status'] ?? ''), ['attention', 'not_configured'], true)
));
$state['summary'] = [
    'total' => count($state['integrations']),
    'connected' => $connected,
    'failed' => $failed,
    'attention' => $attention,
];
$state['ok'] = $failed === 0 && $attention === 0;
$state['publication_monitor'] = [
    'checked_at' => gmdate('c'),
    'checks' => count($publicationChecks),
    'validation' => 'authenticated_read_only',
    'price_stock_untouched' => true,
];

http_response_code(($state['ok'] ?? false) ? 200 : 207);
echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
