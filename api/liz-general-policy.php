<?php
declare(strict_types=1);

function lizg_normalize_question(string $message): string
{
    $message = mb_strtolower(trim($message), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
    return is_string($ascii) ? strtolower($ascii) : $message;
}

function lizg_needs_web_grounding(string $message): bool
{
    $text = lizg_normalize_question($message);
    $patterns = [
        '/\b(hoje|agora|atual|atualmente|recente|recentes|ultima|ultimas|ultimo|ultimos)\b/',
        '/\b(noticia|noticias|pesquise|pesquisar|busque|buscar|procure|procurar)\b/',
        '/\b(previsao do tempo|clima hoje|cotacao|cambio|dolar hoje|euro hoje)\b/',
        '/\b(placar|resultado de hoje|eleicao|eleicoes|presidente atual)\b/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) return true;
    }
    return false;
}

function lizg_should_retry_plain(int $status): bool
{
    return in_array($status, [0, 429, 500, 502, 503, 504], true);
}
