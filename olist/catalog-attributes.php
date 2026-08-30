<?php
declare(strict_types=1);

/**
 * Normaliza atributos estruturados do produto sem alterar seu conteudo editorial.
 */
function svs_catalog_attributes(array $product): array
{
    $attributes = [];
    foreach ((is_array($product['variacoes'] ?? null) ? $product['variacoes'] : []) as $variation) {
        foreach ((is_array($variation['grade'] ?? null) ? $variation['grade'] : []) as $pair) {
            $key = trim((string)($pair['chave'] ?? ''));
            $value = trim((string)($pair['valor'] ?? ''));
            $line = $key !== '' && $value !== '' ? "$key: $value" : '';
            if ($line !== '' && !in_array($line, $attributes, true)) {
                $attributes[] = $line;
            }
        }
    }

    $brand = is_array($product['marca'] ?? null)
        ? trim((string)($product['marca']['nome'] ?? ''))
        : trim((string)($product['marca'] ?? ''));
    $text = implode(' ', [
        (string)($product['descricao'] ?? $product['nome'] ?? ''),
        (string)($product['descricaoComplementar'] ?? ''),
    ]);
    $hasColor = false;
    foreach ($attributes as $attribute) {
        if (preg_match('/^cor\s*:/iu', $attribute) === 1) {
            $hasColor = true;
            break;
        }
    }

    // Japi comercializa o acabamento "Chumbo" dentro da familia de cor preta.
    // Mantemos "Chumbo" em titulo/descricao e normalizamos apenas a taxonomia Cor.
    $isJapi = preg_match('/^JAPI(?:\s|$)/iu', $brand) === 1;
    $isChumbo = stripos($text, 'chumbo') !== false;
    if ($isJapi && $isChumbo && !$hasColor) {
        $attributes[] = 'Cor: Preto';
    }

    return $attributes;
}
