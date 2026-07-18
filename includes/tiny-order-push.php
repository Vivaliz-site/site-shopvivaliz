<?php
declare(strict_types=1);

/**
 * Push de pedidos para o Tiny ERP (API v3). Extraido de api/orders/create-v2.php
 * para ser reutilizado tambem pelo webhook do Mercado Pago -- antes disso, pedidos
 * pagos via Mercado Pago nunca eram enviados ao ERP (apenas os do fluxo manual/offline).
 */

function svtop_root(): string
{
    return dirname(__DIR__);
}

function svtop_load_runtime_secrets(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = svtop_root() . '/config/runtime-secrets.php';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $secrets = require $path;
    if (!is_array($secrets)) {
        return;
    }

    foreach ($secrets as $key => $value) {
        if (!is_string($key) || $key === '' || getenv($key) !== false) {
            continue;
        }
        $stringValue = is_scalar($value) ? (string)$value : '';
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

function svtop_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        svtop_load_runtime_secrets();
        $envFile = svtop_root() . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
        $tf = svtop_root() . '/storage/private/tokens.json';
        if (is_file($tf)) {
            $t = json_decode((string)file_get_contents($tf), true) ?: [];
            foreach ($t as $k => $v) {
                if (is_string($k) && is_string($v) && getenv($k) === false) {
                    putenv("$k=$v"); $_ENV[$k] = $v;
                }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV[$k]) && is_string($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    }
    return '';
}

function svtop_tiny_credentials_configured(): bool
{
    return svtop_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN') !== ''
        && svtop_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID') !== ''
        && svtop_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET') !== '';
}

function svtop_tiny_get_token(): string
{
    $TOKEN_URL    = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    $refresh      = svtop_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN');
    $clientId     = svtop_env('OLIST_CLIENT_ID',     'TINY_CLIENT_ID');
    $clientSecret = svtop_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET');
    if ($refresh === '' || $clientId === '' || $clientSecret === '') return '';

    $ch = curl_init($TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200) return '';
    $json = json_decode(is_string($body) ? $body : '', true);
    return is_array($json) ? (string)($json['access_token'] ?? '') : '';
}

function svtop_tiny_request(string $method, string $path, string $token, ?array $payload = null): array
{
    $ch = curl_init('https://api.tiny.com.br/public-api/v3' . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ShopVivaliz/3.0',
        ],
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode(is_string($body) ? $body : '', true);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'json' => is_array($json) ? $json : []];
}

function svtop_format_cpf(string $digits): string
{
    $digits = preg_replace('/\D/', '', $digits);
    if (strlen($digits) !== 11) return $digits;
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
}

function svtop_format_cnpj(string $digits): string
{
    $digits = preg_replace('/\D/', '', $digits);
    if (strlen($digits) !== 14) return $digits;
    return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
}

/**
 * Busca o contato no Tiny pelo CPF/CNPJ. A API v3 exige o filtro cpfCnpj FORMATADO
 * (com pontuacao/traco) -- passar so os digitos retorna lista vazia mesmo quando o
 * contato existe (confirmado empiricamente contra a API real).
 */
function svtop_find_contact_id(string $token, string $cpfCnpj, string $name): ?int
{
    $digits = preg_replace('/\D/', '', $cpfCnpj);
    if ($digits === '') return null;
    $formatted = strlen($digits) === 14 ? svtop_format_cnpj($digits) : svtop_format_cpf($digits);

    $res = svtop_tiny_request('GET', '/contatos?' . http_build_query(['cpfCnpj' => $formatted]), $token);
    if ($res['status'] !== 200) return null;

    foreach ($res['json']['itens'] ?? [] as $item) {
        $itemCpf = preg_replace('/\D/', '', (string)($item['cpfCnpj'] ?? ''));
        if ($itemCpf !== '' && $itemCpf === $digits) {
            return (int)($item['id'] ?? 0) ?: null;
        }
    }
    return null;
}

function svtop_create_contact(string $token, array $customer): ?int
{
    $docDigits = preg_replace('/\D/', '', (string)($customer['cpf'] ?? ''));
    $cep = preg_replace('/\D/', '', (string)($customer['cep'] ?? ''));
    $payload = [
        'nome'       => $customer['name'] ?? '',
        'tipoPessoa' => strlen($docDigits) === 14 ? 'J' : 'F',
        'cpfCnpj'    => $docDigits,
        'email'      => $customer['email'] ?? '',
        'fone'       => $customer['phone'] ?? '',
        'endereco'   => [
            'endereco'  => $customer['street_name'] ?? $customer['address'] ?? '',
            'numero'    => $customer['street_number'] ?? '',
            'bairro'    => $customer['neighborhood'] ?? '',
            'cep'       => $cep,
            'municipio' => $customer['city'] ?? '',
            'uf'        => $customer['state'] ?? '',
        ],
    ];
    $res = svtop_tiny_request('POST', '/contatos', $token, $payload);
    if ($res['status'] === 200 || $res['status'] === 201) {
        return (int)($res['json']['id'] ?? 0) ?: null;
    }
    // Se ja existe (corrida entre pedidos concorrentes), busca de novo em vez de falhar.
    if ($res['status'] === 400 && str_contains($res['body'], 'já existe')) {
        return svtop_find_contact_id($token, (string)($customer['cpf'] ?? ''), (string)($customer['name'] ?? ''));
    }
    return null;
}

/**
 * @param array $order Precisa de: order_number, customer{name,email,phone,cep,cpf,street_name,
 *                      street_number,neighborhood,city,state}, items[{sku,name,quantity,price,
 *                      olist_product_id}], payment_method, notes
 */
function svtop_push_order_tiny(array $order): ?string
{
    $token = svtop_tiny_get_token();
    if ($token === '') {
        throw new RuntimeException('Tiny: nao foi possivel obter access_token (refresh_token ausente ou invalido)');
    }

    $c = $order['customer'] ?? [];
    $paymentMethod = (string)($order['payment_label'] ?? $order['payment_method'] ?? 'PIX');
    $notes = trim((string)($order['notes'] ?? ''));
    $siteOrderNumber = (string)($order['order_number'] ?? '');
    $obs = trim("Pedido no site: {$siteOrderNumber}\nForma de pagamento: {$paymentMethod}\n" . $notes);

    // Mapa forma de pagamento do site -> id cadastrado na Tiny (GET
    // /formas-pagamento). Cartao de credito/pix/boleto sao os unicos meios
    // reais oferecidos no checkout (ver svop_payment_method/svo_payment_method).
    $paymentFormIds = [
        'pix' => 337683284,
        'boleto' => 337683279,
        'mercado_pago' => 337683277, // pago via Mercado Pago = cartao de credito na pratica
        'pagarme' => 337683277,
        'cartao de credito' => 337683277,
        'transferencia' => 337683281,
        'whatsapp' => 337683282,
    ];
    $paymentMethodKey = strtolower(trim((string)($order['payment_method'] ?? 'pix')));
    $paymentFormId = $paymentFormIds[$paymentMethodKey] ?? null;

    $docDigits = preg_replace('/\D/', '', (string)($c['cpf'] ?? ''));
    if ($docDigits === '') {
        throw new RuntimeException('Tiny: cliente sem CPF/CNPJ informado -- o ERP exige documento para criar/vincular o contato');
    }

    $contactId = svtop_find_contact_id($token, (string)($c['cpf'] ?? ''), (string)($c['name'] ?? ''));
    if ($contactId === null) {
        $contactId = svtop_create_contact($token, $c);
    }
    // A busca de contato na Tiny e inconsistente logo apos criacao (indexacao
    // com atraso) -- confirmado ao vivo: chamadas a svtop_find_contact_id com
    // o mesmo CPF, segundos apart, retornaram resultados diferentes (achou,
    // depois nao achou). Um unico retry de 500ms nao era suficiente e deixava
    // pedidos reais falharem por "contato nao encontrado" mesmo com o
    // contato ja existindo no ERP. Tenta varias vezes com backoff crescente
    // antes de desistir.
    for ($attempt = 0; $contactId === null && $attempt < 4; $attempt++) {
        usleep((int)(500000 * (2 ** $attempt))); // 0.5s, 1s, 2s, 4s
        $contactId = svtop_find_contact_id($token, (string)($c['cpf'] ?? ''), (string)($c['name'] ?? ''));
    }
    if ($contactId === null) {
        throw new RuntimeException('Tiny: nao foi possivel localizar nem criar o contato do cliente no ERP (documento: ' . $docDigits . ')');
    }

    $items = array_values(array_filter($order['items'] ?? [], static fn(array $i) => (int)($i['olist_product_id'] ?? 0) > 0));
    if (count($items) === 0) {
        throw new RuntimeException('Tiny: nenhum item do pedido tem olist_product_id valido para vincular ao produto no ERP');
    }

    $payload = [
        // 'numeroPedido' nao aceita string customizada (a Tiny ignora e
        // atribui seu proprio numero sequencial interno) -- o numero do
        // pedido do site vai em 'obs' e 'numeroOrdemCompra' (referencia
        // externa) para ficar rastreavel dentro do ERP.
        'numeroOrdemCompra' => $siteOrderNumber,
        'situacao'     => 1, // Aberto -- a API v3 exige inteiro, nao objeto
        'idContato'    => $contactId,
        'idDeposito'   => 337683271, // "Geral" -- unico deposito proprio (nao-marketplace) cadastrado
        'itens' => array_map(static fn(array $i) => [
            'produto'       => ['id' => (int)$i['olist_product_id']],
            'quantidade'    => $i['quantity'],
            'valorUnitario' => $i['price'],
        ], $items),
        'valorFrete' => (float)($order['shipping_total'] ?? 0),
        'obs' => $obs,
    ];
    if ($paymentFormId !== null) {
        // vendedor: nao ha nenhum vendedor cadastrado na conta (GET
        // /vendedores retorna vazio), entao nao ha id valido pra mandar.
        // transportador: a transportadora real so e decidida depois deste
        // push, quando a etiqueta e comprada na Melhor Envio de forma
        // assincrona (ver api/melhorenvio/generate-label-background.php) --
        // nao da pra saber qual serviço/transportadora sera usado ainda
        // neste momento.
        $payload['pagamento'] = [
            'formaPagamento' => ['id' => $paymentFormId],
            'formaRecebimento' => ['id' => $paymentFormId],
        ];
    }

    $res = svtop_tiny_request('POST', '/pedidos', $token, $payload);

    if ($res['status'] !== 200 && $res['status'] !== 201) {
        throw new RuntimeException("Tiny POST /pedidos HTTP {$res['status']}: " . substr($res['body'], 0, 400));
    }
    return (string)($res['json']['id'] ?? $res['json']['idPedido'] ?? '');
}
