<?php

declare(strict_types=1);

/**
 * Coleta e valida os campos editáveis de um conteúdo em staging.
 *
 * @param array<string,mixed> $input
 * @return array{optimized_title:string,optimized_description:string,bullet_points_json:string,seo_keywords:string,marketing_hooks:string,meta_data_json:string}
 */
function catalog_draft_payload(array $input): array
{
    $title = trim((string) ($input['optimized_title'] ?? ''));
    $description = trim((string) ($input['optimized_description'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException('O título otimizado não pode ficar vazio.');
    }
    if ($description === '') {
        throw new InvalidArgumentException('A descrição otimizada não pode ficar vazia.');
    }

    $bullets = preg_split('/\R/u', trim((string) ($input['bullet_points'] ?? ''))) ?: [];
    $bullets = array_values(array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        $bullets
    ), static fn (string $value): bool => $value !== ''));

    $bulletPointsJson = json_encode($bullets, JSON_UNESCAPED_UNICODE);
    $metaDataJson = json_encode([
        'meta_title' => trim((string) ($input['meta_title'] ?? '')),
        'meta_description' => trim((string) ($input['meta_description'] ?? '')),
    ], JSON_UNESCAPED_UNICODE);

    if ($bulletPointsJson === false || $metaDataJson === false) {
        throw new RuntimeException('Falha ao serializar os campos editáveis.');
    }

    return [
        'optimized_title' => $title,
        'optimized_description' => $description,
        'bullet_points_json' => $bulletPointsJson,
        'seo_keywords' => trim((string) ($input['seo_keywords'] ?? '')),
        'marketing_hooks' => trim((string) ($input['marketing_hooks'] ?? '')),
        'meta_data_json' => $metaDataJson,
    ];
}

/**
 * Salva alterações manuais no staging sem publicar em nenhum canal.
 * O read-back obrigatório garante que o título persistido é exatamente o
 * informado pelo administrador e que o registro permanece pendente.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function catalog_draft_save(PDO $db, int $stagingId, array $input): array
{
    if ($stagingId <= 0) {
        throw new InvalidArgumentException('ID de staging inválido.');
    }

    $payload = catalog_draft_payload($input);
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

    if (!is_array($row)) {
        throw new RuntimeException('Falha ao reler o conteúdo salvo.');
    }
    if ((string) ($row['optimized_title'] ?? '') !== $payload['optimized_title']) {
        throw new RuntimeException('O read-back do título não corresponde à edição solicitada.');
    }
    if ((string) ($row['optimized_description'] ?? '') !== $payload['optimized_description']) {
        throw new RuntimeException('O read-back da descrição não corresponde à edição solicitada.');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new RuntimeException('A edição manual deve permanecer pendente até aprovação explícita.');
    }

    return $row;
}
