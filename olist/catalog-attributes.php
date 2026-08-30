<?php
declare(strict_types=1);

/**
 * Normaliza atributos estruturados do produto sem alterar seu conteudo editorial.
 */
function svs_catalog_attributes(array $product): array
{
    $brand = is_array($product['marca'] ?? null)
        ? trim((string)($product['marca']['nome'] ?? ''))
        : trim((string)($product['marca'] ?? ''));
    $text = implode(' ', [
        (string)($product['descricao'] ?? $product['nome'] ?? ''),
        (string)($product['descricaoComplementar'] ?? ''),
    ]);

    // Japi comercializa o acabamento "Chumbo" dentro da familia de cor preta.
    // Mantemos "Chumbo" em titulo/descricao e normalizamos apenas a taxonomia Cor.
    $isJapi = preg_match('/^JAPI(?:\s|$)/iu', $brand) === 1;
    $isChumbo = stripos($text, 'chumbo') !== false;

    $attributes = [];
    $hasColor = false;
    foreach ((is_array($product['variacoes'] ?? null) ? $product['variacoes'] : []) as $variation) {
        foreach ((is_array($variation['grade'] ?? null) ? $variation['grade'] : []) as $pair) {
            $key = trim((string)($pair['chave'] ?? ''));
            $value = trim((string)($pair['valor'] ?? ''));
            $isColorKey = preg_match('/^cor$/iu', $key) === 1;
            if ($isColorKey) {
                $hasColor = true;
                if ($isJapi && $isChumbo && strcasecmp($value, 'chumbo') === 0) {
                    $value = 'Preto';
                }
            }
            $line = $key !== '' && $value !== '' ? "$key: $value" : '';
            if ($line !== '' && !in_array($line, $attributes, true)) {
                $attributes[] = $line;
            }
        }
    }

    if ($isJapi && $isChumbo && !$hasColor) {
        $attributes[] = 'Cor: Preto';
    }

    return $attributes;
}
