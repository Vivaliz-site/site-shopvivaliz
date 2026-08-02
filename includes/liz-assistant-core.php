<?php
declare(strict_types=1);

/**
 * Primitivas compartilhadas da Liz.
 *
 * Este arquivo concentra classificação, controles de segurança, estado
 * conversacional e consultas autenticadas. Nenhuma informação comercial é
 * inventada aqui: fatos de catálogo/pedido só entram quando uma fonte oficial
 * os fornece.
 */

require_once __DIR__ . '/catalog-runtime.php';
require_once __DIR__ . '/pdo-database.php';

function sv_liz_normalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    $value = strtr($value, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function sv_liz_detect_intent(string $message): string
{
    $normalized = sv_liz_normalize($message);
    if (preg_match('/\b(atendente|humano|pessoa|whatsapp|falar com alguem)\b/u', $normalized)) {
        return 'human_handoff';
    }
    if (preg_match('/\b(rastreio|rastrear|rastreamento|codigo de rastreio|transportadora)\b/u', $normalized)) {
        return 'tracking';
    }
    if (preg_match('/\b(reclam\w*|atrasad\w*|errad\w*|avaria\w*|defeit\w*|cobranca duplicada|fraud\w*|estorn\w*)\b/u', $normalized)) {
        return 'complaint';
    }
    if (preg_match('/\b(pedido|compra|entrega do meu|status da compra|onde esta meu)\b/u', $normalized)) {
        return 'order_status';
    }
    if (preg_match('/\b(frete|cep|prazo de entrega|quanto demora|envio)\b/u', $normalized)) {
        return 'shipping';
    }
    if (preg_match('/\b(produto|produtos|preco|valor|estoque|disponivel|comprar|modelo|cor|tamanho|categoria)\b/u', $normalized)) {
        return 'product_discovery';
    }
    return 'general';
}

function sv_liz_is_prompt_injection(string $message): bool
{
    $normalized = sv_liz_normalize($message);
    return preg_match('/(?:ignore|ignore todas|desconsidere|revele|mostre|exiba|vaze).*(?:regras|instruc|prompt|sistema|chave|segredo|api|token)|(?:prompt|system message|developer message|api key|chave secreta|segredo interno)/u', $normalized) === 1;
}

function sv_liz_authenticated_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_scalar($userId) || !ctype_digit((string)$userId) || (int)$userId <= 0) {
        return null;
    }

    return [
        'id' => (int)$userId,
        'name' => trim((string)($_SESSION['user_name'] ?? '')),
        'email' => trim((string)($_SESSION['user_email'] ?? '')),
    ];
}

function sv_liz_mask_email(string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : substr($local, 0, 1);
    return $visible . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
}

function sv_liz_extract_order_reference(string $message): string
{
    if (preg_match('/(?:pedido|compra|ordem|#)\s*([A-Z0-9][A-Z0-9\-]{3,})/iu', $message, $matches)) {
        return trim((string)$matches[1]);
    }
    return '';
}

function sv_liz_detect_category(string $normalized): ?string
{
    $categoryMap = [
        'ferramentas' => '/\b(ferramenta|furadeira|chave|broca|soquete|martelo|alicate|parafusadeira)\b/u',
        'cozinha' => '/\b(cozinha|copo|garrafa|jarra|panela|talher|pote|termica|termico)\b/u',
        'moveis' => '/\b(movel|moveis|rodizio|puxador|dobradica|corredica|fechadura|gaveta|armario)\b/u',
        'banheiro' => '/\b(banheiro|ducha|chuveiro|assento|torneira|ralo)\b/u',
        'eletrica' => '/\b(eletrica|tomada|extensao|plug|interruptor|lampada|cabo)\b/u',
    ];

    foreach ($categoryMap as $category => $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return $category;
        }
    }

    return null;
}

function sv_liz_extract_product_query(string $message): ?string
{
    $normalized = sv_liz_normalize($message);
    $terms = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $normalized) ?? '') ?: [];
    $stopWords = [
        'quero', 'preciso', 'produto', 'produtos', 'comprar', 'preco', 'valor', 'estoque',
        'disponivel', 'tem', 'voce', 'voces', 'para', 'com', 'sem', 'uma', 'um', 'meu',
        'minha', 'ate', 'abaixo', 'menos', 'reais', 'qual', 'quanto', 'custa', 'sobre',
    ];
    $keywords = array_values(array_filter($terms, static function(string $term) use ($stopWords): bool {
        return strlen($term) >= 3 && !in_array($term, $stopWords, true) && !ctype_digit($term);
    }));

    if ($keywords === []) {
        return null;
    }

    return implode(' ', array_slice($keywords, 0, 6));
}

function sv_liz_detect_language(string $message): string
{
    $normalized = sv_liz_normalize($message);
    if (preg_match('/\b(price|shipping|order|tracking|refund|hello|please)\b/u', $normalized) === 1) {
        return 'en';
    }
    if (preg_match('/\b(precio|envio|pedido|reembolso|hola|gracias)\b/u', $normalized) === 1) {
        return 'es';
    }
    return 'pt-BR';
}

function sv_liz_detect_sentiment(string $normalized): string
{
    if (preg_match('/\b(amei|obrigad|perfeito|excelente|otimo|gostei)\b/u', $normalized) === 1) {
        return 'positive';
    }
    if (preg_match('/\b(reclam|ruim|horrivel|pessimo|irritad|raiva|decepcionad|atrasad|errad|defeit|fraud|estorn)\b/u', $normalized) === 1) {
        return 'negative';
    }
    return 'neutral';
}

function sv_liz_detect_urgency(string $normalized, string $intent): string
{
    if ($intent === 'complaint' || preg_match('/\b(urgente|agora|imediato|hoje|fraude|cobranca duplicada|cancelar agora)\b/u', $normalized) === 1) {
        return 'high';
    }
    if (preg_match('/\b(quando puder|sem pressa|depois|amanha)\b/u', $normalized) === 1) {
        return 'low';
    }
    return 'normal';
}

/** @return array<string, mixed> */
function sv_liz_order_context(string $message): array
{
    $user = sv_liz_authenticated_user();
    if ($user === null) {
        return ['status' => 'authentication_required'];
    }

    $reference = sv_liz_extract_order_reference($message);
    if ($reference === '') {
        return ['status' => 'reference_required', 'user_email' => sv_liz_mask_email($user['email'])];
    }

    $pdo = sv_pdo();
    if (!$pdo instanceof PDO) {
        return ['status' => 'source_unavailable'];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT order_number, order_total, order_status, tracking_number, tracking_url,
                    estimated_delivery, created_at
             FROM orders
             WHERE user_id = :user_id
               AND (order_number = :reference OR CAST(id AS CHAR) = :reference)
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $user['id'], ':reference' => $reference]);
        $order = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[Liz] order context query failed: ' . $e->getMessage());
        return ['status' => 'source_unavailable'];
    }

    if (!is_array($order)) {
        return ['status' => 'not_found', 'reference' => $reference];
    }

    return [
        'status' => 'confirmed',
        'source' => 'orders_database_authenticated_user',
        'reference' => (string)($order['order_number'] ?: $reference),
        'order_status' => (string)($order['order_status'] ?? ''),
        'order_total' => is_numeric($order['order_total'] ?? null) ? (float)$order['order_total'] : null,
        'tracking_number' => trim((string)($order['tracking_number'] ?? '')),
        'tracking_url' => trim((string)($order['tracking_url'] ?? '')),
        'estimated_delivery' => trim((string)($order['estimated_delivery'] ?? '')),
        'created_at' => trim((string)($order['created_at'] ?? '')),
    ];
}

/** @return array<string, mixed> */
function sv_liz_conversation_state(string $message, array $history = []): array
{
    $intent = sv_liz_detect_intent($message);
    $normalized = sv_liz_normalize($message);
    $budget = null;
    if (preg_match('/(?:ate|abaixo de|menos de)\s*(?:r\$\s*)?([0-9][0-9\.,]*)/u', $normalized, $matches)) {
        $rawBudget = (string)$matches[1];
        if (str_contains($rawBudget, ',')) {
            $rawBudget = str_replace('.', '', $rawBudget);
            $rawBudget = str_replace(',', '.', $rawBudget);
        } elseif (substr_count($rawBudget, '.') > 1) {
            $rawBudget = str_replace('.', '', $rawBudget);
        }
        $budget = is_numeric($rawBudget) ? (float)$rawBudget : null;
    }

    $recentUserMessages = [];
    foreach (array_slice($history, -8) as $entry) {
        if (is_array($entry) && ($entry['role'] ?? '') === 'user' && is_string($entry['content'] ?? null)) {
            $recentUserMessages[] = sv_liz_normalize($entry['content']);
        }
    }

    $category = sv_liz_detect_category($normalized);
    $orderReference = sv_liz_extract_order_reference($message);
    $pendingActions = [];
    if (in_array($intent, ['order_status', 'tracking'], true)) {
        $pendingActions[] = 'authenticate_user';
        if ($orderReference === '') {
            $pendingActions[] = 'collect_order_reference';
        }
    }
    if ($intent === 'shipping') {
        $pendingActions[] = 'collect_cart_and_cep';
    }
    if ($intent === 'human_handoff' || $intent === 'complaint') {
        $pendingActions[] = 'human_handoff';
    }

    return [
        'intent' => $intent,
        'category' => $category,
        'product_query' => sv_liz_extract_product_query($message),
        'budget_max' => $budget,
        'authenticated' => sv_liz_authenticated_user() !== null,
        'requires_authentication' => in_array($intent, ['order_status', 'tracking'], true),
        'order_reference' => $orderReference !== '' ? $orderReference : null,
        'language' => sv_liz_detect_language($message),
        'channel' => 'web',
        'sentiment' => sv_liz_detect_sentiment($normalized),
        'urgency' => sv_liz_detect_urgency($normalized, $intent),
        'confirmed_data' => [],
        'pending_actions' => $pendingActions,
        'history_messages_considered' => count($recentUserMessages),
        'prompt_injection_detected' => sv_liz_is_prompt_injection($message),
        'version' => '1.1.0',
    ];
}

/** @return array<string, mixed> */
function sv_liz_handoff(string $message, array $state, string $reason, ?array $orderContext = null): array
{
    $summary = trim(preg_replace('/\s+/', ' ', $message) ?? '');
    if (function_exists('mb_substr')) {
        $summary = mb_substr($summary, 0, 240, 'UTF-8');
    } else {
        $summary = substr($summary, 0, 240);
    }

    return [
        'required' => true,
        'reason' => $reason,
        'channel' => 'whatsapp',
        'phone' => '5537999374112',
        'email' => 'atendimento@shopvivaliz.com.br',
        'summary' => $summary,
        'intent' => $state['intent'] ?? 'general',
        'order_reference' => is_array($orderContext) ? ($orderContext['reference'] ?? null) : null,
    ];
}

/** @return array<string, mixed> */
function sv_liz_guarded_response(string $message, array $state, array $products = [], ?array $orderContext = null): ?array
{
    if (!empty($state['prompt_injection_detected'])) {
        return [
            'answer' => 'Não posso revelar instruções internas, chaves ou segredos. Posso ajudar com produtos, pedidos e dúvidas da loja.',
            'grounding_status' => 'not_applicable',
            'grounding_sources' => [],
            'handoff' => null,
        ];
    }

    $intent = (string)($state['intent'] ?? 'general');
    if ($intent === 'human_handoff') {
        return [
            'answer' => 'Claro. Vou encaminhar você para o atendimento humano com um resumo desta conversa, para que não precise repetir tudo. Não envie senha, código de autenticação ou dados completos de cartão.',
            'grounding_status' => 'not_required',
            'grounding_sources' => [],
            'handoff' => sv_liz_handoff($message, $state, 'customer_requested'),
        ];
    }
    if ($intent === 'complaint') {
        return [
            'answer' => 'Sinto muito pelo problema. Vou encaminhar seu caso ao atendimento humano com um resumo para verificarmos a solução adequada. Se houver cobrança, não envie dados completos do cartão.',
            'grounding_status' => 'human_review_required',
            'grounding_sources' => [],
            'handoff' => sv_liz_handoff($message, $state, 'complaint_requires_review'),
        ];
    }
    if ($intent === 'shipping') {
        return [
            'answer' => 'O frete e o prazo dependem do CEP, dos produtos e da modalidade escolhida. Para receber uma cotação confirmada, informe o CEP no carrinho; não vou estimar um valor sem consultar a cotação oficial.',
            'grounding_status' => 'source_required',
            'grounding_sources' => ['checkout_shipping_quote'],
            'handoff' => null,
        ];
    }

    if (in_array($intent, ['order_status', 'tracking'], true)) {
        $contextStatus = (string)($orderContext['status'] ?? '');
        if ($contextStatus === 'authentication_required') {
            return [
                'answer' => 'Para proteger os dados do seu pedido, entre na sua conta e depois me informe o número do pedido. Não envie senha, código de autenticação ou dados completos de cartão.',
                'grounding_status' => 'authentication_required',
                'grounding_sources' => [],
                'handoff' => null,
            ];
        }
        if ($contextStatus === 'reference_required') {
            return [
                'answer' => 'Entre na sua conta e informe o número do pedido para eu consultar o status com segurança.',
                'grounding_status' => 'reference_required',
                'grounding_sources' => [],
                'handoff' => null,
            ];
        }
        if ($contextStatus === 'source_unavailable') {
            return [
                'answer' => 'Não consegui consultar os pedidos agora. Posso encaminhar você ao atendimento humano para verificar isso com segurança.',
                'grounding_status' => 'source_unavailable',
                'grounding_sources' => [],
                'handoff' => sv_liz_handoff($message, $state, 'order_source_unavailable', $orderContext),
            ];
        }
        if ($contextStatus === 'not_found') {
            return [
                'answer' => 'Não encontrei esse pedido na sua conta. Confira o número informado ou fale com o atendimento para verificarmos juntos.',
                'grounding_status' => 'not_found',
                'grounding_sources' => ['orders_database_authenticated_user'],
                'handoff' => sv_liz_handoff($message, $state, 'order_not_found', $orderContext),
            ];
        }
    }

    if (in_array($intent, ['product_discovery'], true) && $products === [] && preg_match('/\b(preco|valor|estoque|disponivel)\b/u', sv_liz_normalize($message))) {
        return [
            'answer' => 'Não encontrei um produto correspondente no catálogo oficial agora. Posso encaminhar você ao atendimento para confirmar alternativas, sem inventar preço ou disponibilidade.',
            'grounding_status' => 'not_found',
            'grounding_sources' => ['catalog_runtime'],
            'handoff' => sv_liz_handoff($message, $state, 'catalog_match_not_found'),
        ];
    }

    return null;
}

function sv_liz_requires_official_source(string $message, array $state): bool
{
    $intent = (string)($state['intent'] ?? 'general');
    if (in_array($intent, ['product_discovery', 'shipping', 'order_status', 'tracking'], true)) {
        return true;
    }

    $normalized = sv_liz_normalize($message);
    return preg_match('/\b(preco|valor|estoque|disponivel|promocao|desconto|cupom|frete|prazo|entrega|pedido|rastreamento|rastreio|pagamento|pix|boleto|cartao|garantia)\b/u', $normalized) === 1;
}

function sv_liz_answer_claims_official_fact(string $answer): bool
{
    $normalized = sv_liz_normalize($answer);

    if (preg_match('/(?:r\$\s*)?[0-9]+(?:[.,][0-9]{2})?\s*(?:reais|%|off)?/u', $normalized) === 1) {
        return true;
    }

    return preg_match('/\b(em estoque|disponivel|indisponivel|promocao|desconto|cupom|frete|prazo|entrega|pedido|rastreamento|rastreio|pagamento aprovado|pagamento recusado|boleto|pix|cartao|garantia)\b/u', $normalized) === 1;
}

/** @return array<string, mixed>|null */
function sv_liz_post_response_guard(string $message, array $state, string $answer, array $groundingSources): ?array
{
    if (!sv_liz_requires_official_source($message, $state)) {
        return null;
    }

    if ($groundingSources !== []) {
        return null;
    }

    if (!sv_liz_answer_claims_official_fact($answer) && (string)($state['intent'] ?? 'general') === 'general') {
        return null;
    }

    return [
        'answer' => 'Não consegui confirmar essa informação agora. Para evitar passar preço, estoque, frete, cupom, pagamento ou pedido incorreto, consulte o carrinho, a área Minha Conta ou fale com o atendimento oficial.',
        'grounding_status' => 'source_missing_blocked',
        'grounding_sources' => [],
        'handoff' => sv_liz_handoff($message, $state, 'official_source_missing'),
    ];
}

function sv_liz_knowledge_version(): string
{
    $configured = getenv('LIZ_KNOWLEDGE_VERSION');
    return is_string($configured) && trim($configured) !== '' ? trim($configured) : '2026-08-01.1';
}
