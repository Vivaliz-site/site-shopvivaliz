<?php

declare(strict_types=1);

/**
 * Calcula o impacto editorial efetivo de um rascunho por canal.
 *
 * Este módulo não publica, não altera preço/estoque e não consulta rede.
 * Ele apenas compara o snapshot atual do canal com o conteúdo aprovado no
 * staging, respeitando o field_map canônico de CatalogChannelProfile.php.
 */

/** @return list<string> */
function sv_catalog_change_list(mixed $value): array
{
    if (is_array($value)) {
        $items = $value;
    } elseif (is_scalar($value)) {
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        $items = is_array($decoded)
            ? $decoded
            : (preg_split('/[\r\n,|]+/u', $text) ?: []);
    } else {
        return [];
    }

    $out = [];
    foreach ($items as $entry) {
        if (is_array($entry)) {
            $entry = $entry['value'] ?? $entry['name'] ?? $entry['value_name'] ?? '';
        }
        if (!is_scalar($entry)) {
            continue;
        }
        $item = trim((string)$entry);
        $item = preg_replace('/^[\s\-•]+/u', '', $item) ?? $item;
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return array_values(array_unique($out));
}

function sv_catalog_change_scalar(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim((string)$value);
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

function sv_catalog_change_fold(string $value): string
{
    $value = sv_catalog_change_scalar($value);
    if ($value === '') {
        return '';
    }
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/** @param list<string> $value */
function sv_catalog_change_fold_list(array $value): string
{
    $folded = [];
    foreach ($value as $entry) {
        $entry = sv_catalog_change_fold($entry);
        if ($entry !== '') {
            $folded[] = $entry;
        }
    }
    sort($folded, SORT_STRING);
    return implode('|', array_values(array_unique($folded)));
}

/** @return array<string,mixed> */
function sv_catalog_change_content(array $source): array
{
    return [
        'title' => sv_catalog_change_scalar($source['title'] ?? $source['optimized_title'] ?? ''),
        'description' => sv_catalog_change_scalar($source['description'] ?? $source['optimized_description'] ?? ''),
        'bullet_points' => sv_catalog_change_list($source['bullet_points'] ?? $source['bullet_points_json'] ?? []),
        'seo_keywords' => sv_catalog_change_list($source['seo_keywords'] ?? $source['seo_keywords_json'] ?? []),
        'marketing_hooks' => sv_catalog_change_list($source['marketing_hooks'] ?? $source['marketing_hooks_json'] ?? []),
        'meta_title' => sv_catalog_change_scalar($source['meta_title'] ?? ''),
        'meta_description' => sv_catalog_change_scalar($source['meta_description'] ?? ''),
    ];
}

/** @return array<string,string> */
function sv_catalog_change_field_keys(): array
{
    return [
        'optimized_title' => 'title',
        'optimized_description' => 'description',
        'bullet_points' => 'bullet_points',
        'seo_keywords' => 'seo_keywords',
        'marketing_hooks' => 'marketing_hooks',
        'meta_title' => 'meta_title',
        'meta_description' => 'meta_description',
    ];
}

function sv_catalog_change_value_changed(string $contentKey, mixed $before, mixed $after): bool
{
    if (in_array($contentKey, ['bullet_points', 'seo_keywords', 'marketing_hooks'], true)) {
        return sv_catalog_change_fold_list(sv_catalog_change_list($before))
            !== sv_catalog_change_fold_list(sv_catalog_change_list($after));
    }
    return sv_catalog_change_fold(sv_catalog_change_scalar($before))
        !== sv_catalog_change_fold(sv_catalog_change_scalar($after));
}

/**
 * @param array<string,mixed> $before Snapshot atual do canal.
 * @param array<string,mixed> $after Conteúdo do staging/formulário.
 * @param array<string,array<string,mixed>> $fieldMap Mapa canônico do canal.
 * @return array{
 *   all:list<array<string,mixed>>,
 *   effective:list<array<string,mixed>>,
 *   internal:list<array<string,mixed>>,
 *   unchanged_effective:list<array<string,mixed>>,
 *   counts:array{effective:int,internal:int,unchanged_effective:int,total_changed:int},
 *   has_effective_changes:bool
 * }
 */
function sv_catalog_change_set(array $before, array $after, array $fieldMap): array
{
    $before = sv_catalog_change_content($before);
    $after = sv_catalog_change_content($after);
    $keys = sv_catalog_change_field_keys();
    $all = [];
    $effective = [];
    $internal = [];
    $unchangedEffective = [];

    foreach ($keys as $field => $contentKey) {
        $definition = is_array($fieldMap[$field] ?? null) ? $fieldMap[$field] : [];
        $mode = strtolower(trim((string)($definition['mode'] ?? 'internal')));
        if (!in_array($mode, ['direct', 'embedded', 'internal'], true)) {
            $mode = 'internal';
        }
        $beforeValue = $before[$contentKey] ?? '';
        $afterValue = $after[$contentKey] ?? '';
        $changed = sv_catalog_change_value_changed($contentKey, $beforeValue, $afterValue);
        $row = [
            'field' => $field,
            'content_key' => $contentKey,
            'label' => trim((string)($definition['label'] ?? $field)),
            'mode' => $mode,
            'target' => trim((string)($definition['target'] ?? '')),
            'changed' => $changed,
            'before' => $beforeValue,
            'after' => $afterValue,
        ];
        $all[] = $row;
        if ($mode === 'internal') {
            if ($changed) {
                $internal[] = $row;
            }
            continue;
        }
        if ($changed) {
            $effective[] = $row;
        } else {
            $unchangedEffective[] = $row;
        }
    }

    return [
        'all' => $all,
        'effective' => $effective,
        'internal' => $internal,
        'unchanged_effective' => $unchangedEffective,
        'counts' => [
            'effective' => count($effective),
            'internal' => count($internal),
            'unchanged_effective' => count($unchangedEffective),
            'total_changed' => count($effective) + count($internal),
        ],
        'has_effective_changes' => $effective !== [],
    ];
}
