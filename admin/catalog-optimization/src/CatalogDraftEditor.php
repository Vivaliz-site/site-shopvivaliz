<?php

declare(strict_types=1);

/**
 * Coleta e valida os campos editaveis de um conteudo em staging.
 *
 * O meta_data_json tambem guarda o relatorio de qualidade e a versao da
 * politica do marketplace. Uma edicao manual nao pode apagar silenciosamente
 * esses dados de auditoria.
 *
 * @param array<string,mixed> $input
 * @param array<string,mixed> $existingMeta
 * @return array{optimized_title:string,optimized_description:string,bullet_points_json:string,seo_keywords:string,marketing_hooks:string,meta_data_json:string}
 */
function catalog_draft_payload(array $input, array $existingMeta = []): array
{
    $title = trim((string)($input['optimized_title'] ?? ''));
    $description = trim((string)($input['optimized_description'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('O titulo otimizado nao pode ficar vazio.');
    if ($description === '') throw new InvalidArgumentException('A descricao otimizada nao pode ficar vazia.');

    $bullets = preg_split('/\R/u', trim((string)($input['bullet_points'] ?? ''))) ?: [];
    $bullets = array_values(array_filter(array_map(
        static fn(mixed $value): string => trim((string)$value),
        $bullets
    ), static fn(string $value): bool => $value !== ''));

    // Textareas aceitam um item por linha para funcionar bem no mobile, mas
    // o staging mantem os delimitadores historicos usados pelos publishers.
    $keywords = preg_split('/(?:\R|,)+/u', trim((string)($input['seo_keywords'] ?? ''))) ?: [];
    $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords), static fn(string $v): bool => $v !== '')));
    $hooks = preg_split('/(?:\R|\|)+/u', trim((string)($input['marketing_hooks'] ?? ''))) ?: [];
    $hooks = array_values(array_unique(array_filter(array_map('trim', $hooks), static fn(string $v): bool => $v !== '')));

    $bulletPointsJson = json_encode($bullets, JSON_UNESCAPED_UNICODE);
    $meta = $existingMeta;
    $meta['meta_title'] = trim((string)($input['meta_title'] ?? ''));
    $meta['meta_description'] = trim((string)($input['meta_description'] ?? ''));
    $meta['manual_edit_pending_quality_recheck'] = true;
    $metaDataJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($bulletPointsJson === false || $metaDataJson === false) {
        throw new RuntimeException('Falha ao serializar os campos editaveis.');
    }

    return [
        'optimized_title' => $title,
        'optimized_description' => $description,
        'bullet_points_json' => $bulletPointsJson,
        'seo_keywords' => implode(', ', $keywords),
        'marketing_hooks' => implode(' | ', $hooks),
        'meta_data_json' => $metaDataJson,
    ];
}

/**
 * Salva alteracoes manuais no staging sem publicar em nenhum canal.
 * O read-back obrigatorio garante que o conteudo persistido corresponde ao
 * informado pelo administrador e que o registro permanece pendente.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function catalog_draft_save(PDO $db, int $stagingId, array $input): array
{
    if ($stagingId <= 0) throw new InvalidArgumentException('ID de staging invalido.');

    $current = $db->prepare('SELECT meta_data_json FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
    $current->execute([$stagingId]);
    $existingMetaRaw = $current->fetchColumn();
    if ($existingMetaRaw === false) throw new RuntimeException('Conteudo de staging nao encontrado.');
    $existingMeta = json_decode((string)$existingMetaRaw, true);
    $existingMeta = is_array($existingMeta) ? $existingMeta : [];

    $payload = catalog_draft_payload($input, $existingMeta);
    $stmt = $db->prepare(
        "UPDATE catalog_optimizations_staging SET optimized_title = ?, optimized_description = ?, bullet_points_json = ?, "
        . "seo_keywords = ?, marketing_hooks = ?, meta_data_json = ?, status = 'pending', error_message = NULL, "
        . 'publication_summary_json = NULL, published_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $stmt->execute([
        $payload['optimized_title'],
        $payload['optimized_description'],
        $payload['bullet_points_json'],
        $payload['seo_keywords'],
        $payload['marketing_hooks'],
        $payload['meta_data_json'],
        $stagingId,
    ]);

    $readBack = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
    $readBack->execute([$stagingId]);
    $row = $readBack->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Falha ao reler o conteudo salvo.');
    if ((string)($row['optimized_title'] ?? '') !== $payload['optimized_title']) throw new RuntimeException('O read-back do titulo nao corresponde a edicao solicitada.');
    if ((string)($row['optimized_description'] ?? '') !== $payload['optimized_description']) throw new RuntimeException('O read-back da descricao nao corresponde a edicao solicitada.');
    if ((string)($row['status'] ?? '') !== 'pending') throw new RuntimeException('A edicao manual deve permanecer pendente ate aprovacao explicita.');
    return $row;
}
