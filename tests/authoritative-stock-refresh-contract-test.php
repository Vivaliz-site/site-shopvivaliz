<?php
declare(strict_types=1);

function stock_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$fetch = (string)file_get_contents($root . '/olist/fetch-estoque-v3.php');
$force = (string)file_get_contents($root . '/olist/force-sync-cache.php');
$webhook = (string)file_get_contents($root . '/olist/webhook-receiver.php');

stock_contract_assert(str_contains($fetch, '/public-api/v3/estoque/'), 'estoque precisa vir do endpoint v3 autoritativo');
stock_contract_assert(str_contains($fetch, "array_key_exists('disponivel'"), 'campo disponivel precisa ser a fonte de venda');
stock_contract_assert(str_contains($fetch, "\$item['estoque_disponivel'] = \$estoque"), 'refresh precisa sobrescrever zeros antigos com valor autoritativo');
stock_contract_assert(!str_contains($fetch, "!isset(\$item['estoque_disponivel'])"), 'estoque existente nao pode impedir novo refresh autoritativo');
stock_contract_assert(str_contains($fetch, 'x-ratelimit-remaining'), 'cliente precisa respeitar headers de rate limit');
stock_contract_assert(str_contains($fetch, 'authoritative_stock_coverage_below_90pct'), 'refresh incompleto precisa falhar fechado');
stock_contract_assert(str_contains($force, "require __DIR__ . '/sync-on-webhook.php'"), 'force sync deve reutilizar lista canonica de ativos');
stock_contract_assert(str_contains($force, "require __DIR__ . '/fetch-estoque-v3.php'"), 'force sync deve enriquecer estoque autoritativo');
stock_contract_assert(!str_contains($force, "estoque_disponivel'] = 0"), 'force sync nao pode fabricar estoque zero');
stock_contract_assert(str_contains($webhook, "'/olist/sync-on-webhook.php'") && str_contains($webhook, "'/olist/fetch-estoque-v3.php'") && str_contains($webhook, "'/sync-daemon-to-db.php'"), 'webhook precisa executar os tres passos');
stock_contract_assert(str_contains($webhook, " . ' && ' . "), 'webhook precisa sequenciar refresh com AND shell');

fwrite(STDOUT, "authoritative-stock-refresh-contract: ok\n");
