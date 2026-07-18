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

function svtop_tiny_get(string $path, string $token): array
{
    return svtop_tiny_request('GET', $path, $token);
}

function svtop_tiny_get_order(string $idPedido, string $token): array
{
    return svtop_tiny_get('/pedidos/' . rawurlencode($idPedido), $token);
}

function svtop_tiny_update_dispatch(string $idPedido, string $token, array $payload): array
{
    return svtop_tiny_request('PUT', '/pedidos/' . rawurlencode($idPedido) . '/despacho', $token, $payload);
}

function svtop_tiny_get_invoice(string $idNota, string $token): array
{
    return svtop_tiny_get('/notas/' . rawurlencode($idNota), $token);
}

function svtop_tiny_get_invoice_xml(string $idNota, string $token): array
{
    return svtop_tiny_get('/notas/' . rawurlencode($idNota) . '/xml', $token);
}

function svtop_tiny_list_categories(string $token): array
{
    return svtop_tiny_get('/categorias/todas', $token);
}

function svtop_tiny_int_env(string ...$keys): ?int
{
    foreach ($keys as $key) {
        $value = trim((string)svtop_env($key));
        if ($value !== '' && ctype_digit($value)) {
            return (int)$value;
        }
    }
    return null;
}

function svtop_tiny_float_env(string ...$keys): ?float
{
    foreach ($keys as $key) {
        $value = trim((string)svtop_env($key));
        if ($value !== '' && is_numeric($value)) {
            return (float)$value;
        }
    }
    return null;
}

function svtop_tiny_first_non_empty(array $values): string
{
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function svtop_tiny_dispatch_forma_envio_id(array $order): ?int
{
    $explicit = svtop_tiny_int_env('TINY_DESPACHO_FORMA_ENVIO_ID', 'TINY_FORMA_ENVIO_ID');
    if ($explicit !== null) {
        return $explicit;
    }

    $label = strtolower(svtop_tiny_first_non_empty([
        $order['shipping_label'] ?? '',
        $order['shipping_service'] ?? '',
        $order['shipping_method'] ?? '',
    ]));

    if (str_contains($label, 'correios')) {
        return 337724753;
    }
    if (str_contains($label, 'jadlog')) {
        return 337724757;
    }

    return null;
}

function svtop_tiny_dispatch_forma_frete_id(array $order): ?int
{
    $explicit = svtop_tiny_int_env('TINY_DESPACHO_FORMA_FRETE_ID', 'TINY_FORMA_FRETE_ID');
    if ($explicit !== null) {
        return $explicit;
    }

    $label = strtolower(svtop_tiny_first_non_empty([
        $order['shipping_label'] ?? '',
        $order['shipping_service'] ?? '',
    ]));

    if (str_contains($label, 'pac')) {
        return svtop_tiny_int_env('TINY_DESPACHO_FORMA_FRETE_ID_PAC');
    }
    if (str_contains($label, 'sedex')) {
        return svtop_tiny_int_env('TINY_DESPACHO_FORMA_FRETE_ID_SEDEX');
    }

    return null;
}

function svtop_tiny_build_dispatch_payload(array $order, array $context = []): array
{
    $payload = [];
    $trackingCode = svtop_tiny_first_non_empty([
        $context['codigoRastreamento'] ?? '',
        $order['tracking_number'] ?? '',
        $order['tracking'] ?? '',
        $order['shipping_tracking_code'] ?? '',
    ]);
    $trackingUrl = svtop_tiny_first_non_empty([
        $context['urlRastreamento'] ?? '',
        $order['tracking_url'] ?? '',
        $order['shipping_tracking_url'] ?? '',
    ]);
    $dispatchNote = svtop_tiny_first_non_empty([
        $context['observacoes'] ?? '',
        $order['notes'] ?? '',
        $order['remarks'] ?? '',
    ]);
    $shippingTotal = svtop_tiny_float_env('TINY_DESPACHO_FRETE_PAGO_EMPRESA', 'TINY_DESPACHO_VALOR_FRETE');
    if ($shippingTotal === null && isset($order['shipping_total']) && is_numeric($order['shipping_total'])) {
        $shippingTotal = (float)$order['shipping_total'];
    }

    if ($trackingCode !== '') {
        $payload['codigoRastreamento'] = $trackingCode;
    }
    if ($trackingUrl !== '') {
        $payload['urlRastreamento'] = $trackingUrl;
    }
    $formaEnvioId = svtop_tiny_dispatch_forma_envio_id($order);
    if ($formaEnvioId !== null) {
        $payload['formaEnvio'] = ['id' => $formaEnvioId];
    }
    $formaFreteId = svtop_tiny_dispatch_forma_frete_id($order);
    if ($formaFreteId !== null) {
        $payload['formaFrete'] = ['id' => $formaFreteId];
    }
    if ($shippingTotal !== null) {
        $payload['fretePagoEmpresa'] = $shippingTotal;
    }

    $datePrevista = svtop_tiny_first_non_empty([
        $context['dataPrevista'] ?? '',
        $order['estimated_delivery'] ?? '',
        $order['shipping_estimated_delivery'] ?? '',
    ]);
    if ($datePrevista !== '') {
        $payload['dataPrevista'] = substr($datePrevista, 0, 10);
    }

    $contactId = svtop_tiny_int_env('TINY_DESPACHO_ID_CONTATO_TRANSPORTADORA', 'TINY_ID_CONTATO_TRANSPORTADORA');
    if ($contactId !== null) {
        $payload['idContatoTransportadora'] = $contactId;
    }

    $volumes = $context['volumes'] ?? null;
    if (is_int($volumes) || is_float($volumes)) {
        $payload['volumes'] = max(1, (int)$volumes);
    } elseif (is_array($volumes) && isset($volumes['count'])) {
        $payload['volumes'] = max(1, (int)$volumes['count']);
    } elseif (!empty($order['items']) && is_array($order['items'])) {
        $payload['volumes'] = max(1, count($order['items']));
    }

    $pesoBruto = $context['pesoBruto'] ?? null;
    if (is_numeric($pesoBruto)) {
        $payload['pesoBruto'] = (float)$pesoBruto;
    }

    $pesoLiquido = $context['pesoLiquido'] ?? null;
    if (is_numeric($pesoLiquido)) {
        $payload['pesoLiquido'] = (float)$pesoLiquido;
    }

    if ($dispatchNote !== '') {
        $payload['observacoes'] = $dispatchNote;
    }

    return $payload;
}

function svtop_tiny_dispatch_from_order(array $order, array $context = []): array
{
    $token = svtop_tiny_get_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'missing_tiny_token'];
    }

    $tinyOrderId = trim((string)($order['tiny_order_id'] ?? $order['olist_order_id'] ?? ''));
    if ($tinyOrderId === '') {
        return ['ok' => false, 'error' => 'missing_tiny_order_id'];
    }

    $payload = svtop_tiny_build_dispatch_payload($order, $context);
    if ($payload === []) {
        return ['ok' => false, 'error' => 'dispatch_payload_empty'];
    }

    $res = svtop_tiny_update_dispatch($tinyOrderId, $token, $payload);
    if ($res['status'] === 200 || $res['status'] === 204) {
        return ['ok' => true, 'status' => $res['status'], 'payload' => $payload, 'body' => $res['json']];
    }

    return [
        'ok' => false,
        'error' => 'dispatch_failed',
        'status' => $res['status'],
        'body' => $res['json'] ?: $res['body'],
        'payload' => $payload,
    ];
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
    $phone = (string)($customer['phone'] ?? '');
    $contactCode = (string)($customer['contact_code'] ?? $customer['order_number'] ?? '');
    // Confirmado no schema oficial (api-docs.erp.olist.com/api-reference/contatos/criar-contato):
    // o campo se chama 'telefone', nao 'fone' -- 'fone' nao existe e era
    // ignorado silenciosamente pela Tiny, entao NENHUM contato criado pelo
    // push do site tinha telefone salvo, apesar do codigo "enviar" isso.
    // Tambem adiciona 'celular' (mesmo numero, o checkout do site so coleta
    // um telefone/WhatsApp) e 'emailNfe' (usado pra envio da nota fiscal --
    // sem isso a NF pode nao ser enviada por email ao cliente automaticamente).
    $address = [
        'endereco'  => $customer['street_name'] ?? $customer['address'] ?? '',
        'numero'    => $customer['street_number'] ?? '',
        'complemento' => $customer['complement'] ?? '',
        'bairro'    => $customer['neighborhood'] ?? '',
        'cep'       => $cep,
        'municipio' => $customer['city'] ?? '',
        'uf'        => $customer['state'] ?? '',
        'pais'      => 'Brasil',
    ];
    $payload = [
        'nome'             => $customer['name'] ?? '',
        'codigo'           => $contactCode !== '' ? $contactCode : ($customer['name'] ?? ''),
        'fantasia'         => $customer['name'] ?? '',
        'tipoPessoa'       => strlen($docDigits) === 14 ? 'J' : 'F',
        'cpfCnpj'          => $docDigits,
        'email'            => $customer['email'] ?? '',
        'emailNfe'         => $customer['email'] ?? '',
        'telefone'         => $phone,
        'celular'          => $phone,
        'endereco'         => $address,
        // Sem endereco de cobranca proprio coletado no checkout -- usa o
        // mesmo endereco de entrega (evita a Tiny cair num endereco de
        // cobranca vazio/divergente na hora de faturar).
        'enderecoCobranca' => $address,
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
        $contactId = svtop_create_contact($token, array_merge($c, [
            'contact_code' => $siteOrderNumber,
            'order_number' => $siteOrderNumber,
        ]));
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
        // Vincula explicitamente o numero do checkout proprio ao canal de
        // e-commerce. Na conta atual, pedidos do site usam ecommerce.id = 0
        // e continuam pesquisaveis pelo numero do pedido externo.
        'ecommerce' => [
            'id' => 0,
            'numeroPedidoEcommerce' => $siteOrderNumber,
        ],
        // Confirmado na documentacao oficial (api-docs.erp.olist.com/api-reference/pedidos/criar-pedido):
        // 8=Dados Incompletos, 0=Aberta, 3=Aprovada, 4=Preparando Envio,
        // 1=Faturada, 7=Pronto Envio, 5=Enviada, 6=Entregue, 2=Cancelada,
        // 9=Nao Entregue. O codigo antigo mandava 1 achando que era "Aberto"
        // -- na verdade e "Faturada", ou seja todo pedido criado por aqui
        // nascia marcado como se ja tivesse nota fiscal emitida, sem nunca
        // ter sido de fato. Corrigido para 0 (Aberta): o pedido so deve
        // virar Faturada quando a NF for realmente emitida no ERP.
        'situacao'     => 0,
        // Sem 'data' o pedido nasce sem data de venda -- a busca da UI da
        // Tiny filtra por essa data (nao pela data de cadastro), entao
        // pedidos sem ela ficam invisiveis na busca mesmo existindo de
        // verdade (confirmado ao vivo: GET /pedidos/{id} retornava 200 mas
        // a busca "nao retornou resultados"). Usa a data de criacao local
        // do pedido, ou a data atual se ausente.
        'data'         => date('Y-m-d', strtotime((string)($order['created_at'] ?? 'now')) ?: time()),
        'idContato'    => $contactId,
        // "Loja Online" -- vendedor generico cadastrado especificamente pra
        // isso (a conta nao tinha nenhum vendedor antes, GET /vendedores
        // retornava vazio, e pedidos sem vendedor tambem pareciam sumir da
        // busca da UI). Ver docs/TINY-ERP-API-V3.md.
        'vendedor'     => ['id' => 369463749],
        'deposito'     => ['id' => 337683271], // "Geral" -- unico deposito proprio (nao-marketplace) cadastrado
        'itens' => array_map(static fn(array $i) => [
            'produto'       => ['id' => (int)$i['olist_product_id']],
            'quantidade'    => $i['quantity'],
            'valorUnitario' => $i['price'],
        ], $items),
        'valorFrete' => (float)($order['shipping_total'] ?? 0),
        // O campo se chama 'observacoes' na API v3 -- 'obs' nao existe no
        // schema oficial (api-docs.erp.olist.com/api-reference/pedidos/criar-pedido)
        // e era ignorado silenciosamente pela Tiny, entao o pedido no ERP
        // nunca tinha nenhuma observacao visivel apesar do codigo "enviar" isso.
        'observacoes' => $obs,
        // Marca a venda como pra consumidor final (nao revenda) -- afeta o
        // calculo de tributacao da nota fiscal. A Tiny troca o nome exibido
        // na coluna "Cliente" da listagem por "Consumidor Final" quando essa
        // flag esta ativa (comportamento normal da UI pra esse tipo de
        // venda, nao um bug -- o nome real do cliente continua no cadastro
        // do contato, so a exibicao na lista muda).
        'consumidorFinal' => [
            'cpfCnpj'                => $docDigits,
            'clienteConsumidorFinal' => true,
        ],
        // Endereco de entrega do cliente -- antes nao era enviado, entao o
        // pedido no ERP ficava sem endereco de entrega definido (so o do
        // contato cadastrado, se houver).
        'enderecoEntrega' => [
            'endereco'         => (string)($c['street_name'] ?? $c['address'] ?? ''),
            'enderecoNro'      => (string)($c['street_number'] ?? ''),
            'complemento'      => (string)($c['complement'] ?? ''),
            'bairro'           => (string)($c['neighborhood'] ?? ''),
            'municipio'        => (string)($c['city'] ?? ''),
            'cep'              => preg_replace('/\D/', '', (string)($c['cep'] ?? '')),
            'uf'               => (string)($c['state'] ?? ''),
            'fone'             => (string)($c['phone'] ?? ''),
            'nomeDestinatario' => (string)($c['name'] ?? ''),
            'cpfCnpj'          => $docDigits,
            // enum J/F/E/X (doc oficial) -- todo checkout do site e pessoa
            // fisica (CPF de 11 digitos), nunca CNPJ.
            'tipoPessoa'       => strlen($docDigits) === 14 ? 'J' : 'F',
        ],
    ];

    // Forma de envio real, escolhida pelo cliente no checkout via Melhor
    // Envio (salva em shipping_label no formato "<Transportadora> - <Servico>",
    // ex: "Jadlog - .Package") -- confirmado via GET /formas-envio que a
    // conta ja tem cadastro pra cada transportadora "via Melhor envio"
    // vinculado ao gatewayLogistico "Melhor envio" (id 337724739). So o
    // ID da forma de envio (nao um id de "transportador" separado -- a doc
    // aceita transportador.id como null) e suficiente pra Tiny saber qual
    // transportadora sera usada.
    $shippingLabel = strtolower((string)($order['shipping_label'] ?? ''));
    $formaEnvioIds = [
        'correios'      => 357119973,
        'jadlog'        => 357119976,
        'jet'           => 357119979,
        'loggi'         => 357119982,
        'total express' => 357119984,
    ];
    foreach ($formaEnvioIds as $needle => $formaEnvioId) {
        if (str_contains($shippingLabel, $needle)) {
            $payload['transportador'] = ['formaEnvio' => ['id' => $formaEnvioId]];
            break;
        }
    }
    if ($paymentFormId !== null) {
        $payload['pagamento'] = [
            'formaRecebimento' => ['id' => $paymentFormId],
        ];
    }

    $res = svtop_tiny_request('POST', '/pedidos', $token, $payload);

    if ($res['status'] !== 200 && $res['status'] !== 201) {
        throw new RuntimeException("Tiny POST /pedidos HTTP {$res['status']}: " . substr($res['body'], 0, 400));
    }
    return (string)($res['json']['id'] ?? $res['json']['idPedido'] ?? '');
}
