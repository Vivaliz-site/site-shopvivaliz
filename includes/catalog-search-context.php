<?php
declare(strict_types=1);

/**
 * Ignore connective words only in multiword searches. A one-word query such
 * as "para" retains its original meaning instead of matching every product.
 *
 * @return list<string>
 */
function sv_catalog_search_terms(string $normalizedQuery): array
{
    $tokens = preg_split('/\\s+/u', trim($normalizedQuery)) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    if (count($tokens) < 2) {
        return $tokens;
    }
    $connectives = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'PARA', 'COM', 'EM', 'E'];
    $meaningful = array_values(array_filter(
        $tokens,
        static fn(string $token): bool => !in_array($token, $connectives, true)
    ));
    return $meaningful !== [] ? $meaningful : $tokens;
}

/**
 * Paid-search-aware catalog copy.
 *
 * Keeps commercial claims factual while aligning the visible catalog heading
 * with the visitor's search intent. Search pages remain noindex; this context
 * exists for user clarity and paid-search landing-page relevance.
 *
 * @return array{eyebrow:string,heading:string,lead:string}
 */
function sv_catalog_search_context(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [
            'eyebrow' => 'Todos os produtos',
            'heading' => 'Produtos Vivaliz',
            'lead' => '',
        ];
    }

    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($query, 'UTF-8')
        : strtolower($query);

    $doorSealIntent = str_contains($normalized, 'vedante')
        || str_contains($normalized, 'vedador')
        || str_contains($normalized, 'veda porta')
        || (str_contains($normalized, 'rodo') && str_contains($normalized, 'porta'));
    if ($doorSealIntent) {
        return [
            'eyebrow' => 'Vedantes para porta',
            'heading' => 'Vedantes para porta em alumínio e borracha',
            'lead' => 'Compare modelos de vedação para portas e escolha a medida compatível. Consulte preço, disponibilidade e frete por CEP.',
        ];
    }

    if (str_contains($normalized, 'antique')) {
        return [
            'eyebrow' => 'Linha Antique Japi',
            'heading' => 'Vasos Antique Japi',
            'lead' => 'Compare modelos da linha Antique Japi e consulte preço, disponibilidade e frete por CEP antes de comprar.',
        ];
    }

    if (str_contains($normalized, 'decore')) {
        return [
            'eyebrow' => 'Linha Decore Japi',
            'heading' => 'Vasos Decore Japi',
            'lead' => 'Compare modelos da linha Decore Japi e consulte preço, disponibilidade e frete por CEP antes de comprar.',
        ];
    }

    return [
        'eyebrow' => 'Busca no catálogo',
        'heading' => 'Resultados para: ' . $query,
        'lead' => 'Compare os produtos encontrados e consulte preço, disponibilidade e frete por CEP.',
    ];
}
