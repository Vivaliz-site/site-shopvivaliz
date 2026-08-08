<?php
/**
 * Sincronizar imagens baixadas da Olist para o cadastro local.
 *
 * Regra de identidade: nunca atualizar por `olist_id OR sku`.
 * Quando ambos existem, ambos precisam apontar para o mesmo produto.
 * Correspondencia ambigua ou conflitante e rejeitada e registrada em log.
 */

header('Content-Type: application/json; charset=utf-8');

log_msg('=== SINCRONIZAR IMAGENS PARA SITE ===');

try {
    $mappingFile = __DIR__ . '/../logs/olist-images-mapping.json';
    if (!is_file($mappingFile)) {
        exit_error('Mapping não encontrado. Execute /olist/download-images.php primeiro');
    }

    $raw = file_get_contents($mappingFile);
    $mapping = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($mapping)) {
        exit_error('Mapping de imagens inválido ou não é JSON.');
    }

    log_msg(count($mapping) . ' produtos com imagens no mapping');

    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance();
    log_msg('Banco conectado');

    $updatedProducts = 0;
    $updatedImages = 0;
    $insertedImages = 0;
    $errors = 0;
    $skipped = 0;

    foreach ($mapping as $mappingOlistId => $data) {
        if (!is_array($data)) {
            $errors++;
            log_msg('[ERRO] Entrada de mapping não é objeto/array.');
            continue;
        }

        $sourceOlistId = trim((string)($data['olist_id'] ?? $mappingOlistId));
        $sourceSku = trim((string)($data['sku'] ?? ''));
        $name = trim((string)($data['nome'] ?? $data['name'] ?? 'produto sem nome'));
        $images = is_array($data['imagens'] ?? null) ? $data['imagens'] : [];

        if ($images === []) {
            $skipped++;
            continue;
        }

        $resolved = resolve_unique_olist_product($db, $sourceOlistId, $sourceSku);
        if (($resolved['ok'] ?? false) !== true) {
            $errors++;
            log_msg('[IDENTIDADE BLOQUEADA] ' . $name . ' | olist_id=' . $sourceOlistId . ' | sku=' . $sourceSku . ' | ' . ($resolved['error'] ?? 'não resolvido'));
            continue;
        }

        $olistRow = $resolved['row'];
        $canonicalOlistId = trim((string)($olistRow['olist_id'] ?? $sourceOlistId));
        $canonicalSku = trim((string)($olistRow['sku'] ?? $sourceSku));
        $olistInternalId = (int)($olistRow['id'] ?? 0);

        if ($olistInternalId <= 0) {
            $errors++;
            log_msg('[ERRO] Produto Olist resolvido sem id interno: ' . $name);
            continue;
        }

        $validImages = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $path = trim((string)($image['local_path'] ?? ''));
            if ($path !== '') {
                $validImages[] = $image;
            }
        }
        if ($validImages === []) {
            $skipped++;
            log_msg('[PULADO] ' . $name . ' sem local_path válido.');
            continue;
        }

        $primary = trim((string)$validImages[0]['local_path']);
        $totalImages = count($validImages);

        // Atualiza uma única linha já resolvida por identidade estrita.
        $stmt = $db->prepare(
            'UPDATE olist_products SET primary_image_url = ?, images_count = ?, last_image_sync_at = NOW() WHERE id = ?'
        );
        if (!$stmt) {
            $errors++;
            log_msg('[ERRO] Falha ao preparar atualização do produto: ' . $name);
            continue;
        }
        $stmt->bind_param('sii', $primary, $totalImages, $olistInternalId);
        if (!$stmt->execute()) {
            $errors++;
            log_msg('[ERRO] Falha ao atualizar produto: ' . $name . ' | ' . $stmt->error);
            $stmt->close();
            continue;
        }
        $updatedProducts += max(0, $stmt->affected_rows);
        $stmt->close();

        // Resolve o produto local somente com os mesmos identificadores.
        $localProductId = resolve_unique_local_product_id($db, $canonicalOlistId, $canonicalSku);
        if ($localProductId === null) {
            log_msg('[AVISO] Produto local não pôde ser resolvido de forma única; imagens ficam sem product_local_id: ' . $name);
        }

        foreach ($validImages as $index => $image) {
            $localPath = trim((string)($image['local_path'] ?? ''));
            if ($localPath === '') {
                continue;
            }

            $position = $index + 1;
            $isPrimary = $index === 0 ? 1 : 0;

            $result = upsert_exact_product_image(
                $db,
                $canonicalOlistId,
                $canonicalSku,
                $localProductId,
                $localPath,
                $position,
                $isPrimary
            );

            if (($result['ok'] ?? false) !== true) {
                $errors++;
                log_msg('[ERRO IMAGEM] ' . $name . ' pos=' . $position . ' | ' . ($result['error'] ?? 'falha desconhecida'));
                continue;
            }

            if (($result['action'] ?? '') === 'inserted') {
                $insertedImages++;
            } else {
                $updatedImages++;
            }
        }

        log_msg('[OK] ' . $name . ' | olist_id=' . $canonicalOlistId . ' | sku=' . $canonicalSku . ' | ' . $totalImages . ' imagens');
    }

    log_msg('=== SINCRONIZACAO CONCLUIDA ===');
    log_msg('Produtos atualizados: ' . $updatedProducts);
    log_msg('Imagens inseridas: ' . $insertedImages);
    log_msg('Imagens atualizadas: ' . $updatedImages);
    log_msg('Pulados: ' . $skipped);
    log_msg('Erros: ' . $errors);

    http_response_code($errors > 0 ? 207 : 200);
    echo json_encode([
        'sucesso' => $errors === 0,
        'produtos_atualizados' => $updatedProducts,
        'imagens_inseridas' => $insertedImages,
        'imagens_atualizadas' => $updatedImages,
        'pulados' => $skipped,
        'erros' => $errors,
        'mensagem' => $errors === 0
            ? 'Sincronização de imagens concluída com identidade estrita.'
            : 'Sincronização concluída com itens bloqueados por erro ou identidade ambígua.',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    log_msg('EXCEPTION: ' . $e->getMessage());
    exit_error('Erro: ' . $e->getMessage());
}

/** @return array{ok:bool,row?:array<string,mixed>,error?:string} */
function resolve_unique_olist_product(Database $db, string $olistId, string $sku): array
{
    $olistId = trim($olistId);
    $sku = trim($sku);
    if ($olistId === '' && $sku === '') {
        return ['ok' => false, 'error' => 'sem olist_id e sem SKU'];
    }

    if ($olistId !== '' && $sku !== '') {
        $sql = 'SELECT id, CAST(olist_id AS CHAR) AS olist_id, sku FROM olist_products WHERE CAST(olist_id AS CHAR) = ? AND sku = ? LIMIT 2';
        $types = 'ss';
        $params = [$olistId, $sku];
    } elseif ($olistId !== '') {
        $sql = 'SELECT id, CAST(olist_id AS CHAR) AS olist_id, sku FROM olist_products WHERE CAST(olist_id AS CHAR) = ? LIMIT 2';
        $types = 's';
        $params = [$olistId];
    } else {
        $sql = 'SELECT id, CAST(olist_id AS CHAR) AS olist_id, sku FROM olist_products WHERE sku = ? LIMIT 2';
        $types = 's';
        $params = [$sku];
    }

    $rows = stmt_rows($db, $sql, $types, $params);
    if (count($rows) !== 1) {
        return [
            'ok' => false,
            'error' => count($rows) === 0 ? 'nenhuma correspondência exata' : 'correspondência ambígua',
        ];
    }

    $row = $rows[0];
    if ($olistId !== '' && trim((string)($row['olist_id'] ?? '')) !== $olistId) {
        return ['ok' => false, 'error' => 'olist_id divergente após resolução'];
    }
    if ($sku !== '' && trim((string)($row['sku'] ?? '')) !== $sku) {
        return ['ok' => false, 'error' => 'SKU divergente após resolução'];
    }

    return ['ok' => true, 'row' => $row];
}

function resolve_unique_local_product_id(Database $db, string $olistId, string $sku): ?int
{
    $olistId = trim($olistId);
    $sku = trim($sku);
    if ($olistId === '' && $sku === '') {
        return null;
    }

    if ($olistId !== '' && $sku !== '') {
        $rows = stmt_rows(
            $db,
            'SELECT id FROM products WHERE CAST(olist_id AS CHAR) = ? AND sku = ? LIMIT 2',
            'ss',
            [$olistId, $sku]
        );
    } elseif ($olistId !== '') {
        $rows = stmt_rows($db, 'SELECT id FROM products WHERE CAST(olist_id AS CHAR) = ? LIMIT 2', 's', [$olistId]);
    } else {
        $rows = stmt_rows($db, 'SELECT id FROM products WHERE sku = ? LIMIT 2', 's', [$sku]);
    }

    return count($rows) === 1 ? (int)($rows[0]['id'] ?? 0) ?: null : null;
}

/** @return array{ok:bool,action?:string,error?:string} */
function upsert_exact_product_image(
    Database $db,
    string $olistId,
    string $sku,
    ?int $localProductId,
    string $imageUrl,
    int $position,
    int $isPrimary
): array {
    if ($olistId === '' && $sku === '') {
        return ['ok' => false, 'error' => 'imagem sem identidade de produto'];
    }

    // A identidade persistida usa os dois identificadores canônicos quando disponíveis.
    if ($olistId !== '' && $sku !== '') {
        $existing = stmt_rows(
            $db,
            'SELECT id FROM olist_product_images WHERE CAST(olist_id AS CHAR) = ? AND sku = ? AND position = ? LIMIT 2',
            'ssi',
            [$olistId, $sku, $position]
        );
    } elseif ($olistId !== '') {
        $existing = stmt_rows(
            $db,
            'SELECT id FROM olist_product_images WHERE CAST(olist_id AS CHAR) = ? AND position = ? LIMIT 2',
            'si',
            [$olistId, $position]
        );
    } else {
        $existing = stmt_rows(
            $db,
            'SELECT id FROM olist_product_images WHERE sku = ? AND position = ? LIMIT 2',
            'si',
            [$sku, $position]
        );
    }

    if (count($existing) > 1) {
        return ['ok' => false, 'error' => 'mais de uma imagem existente para a mesma identidade/posição'];
    }

    if (count($existing) === 1) {
        $id = (int)$existing[0]['id'];
        $stmt = $db->prepare(
            "UPDATE olist_product_images SET image_url = ?, product_local_id = ?, is_primary = ?, status = 'active', updated_at = NOW() WHERE id = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'falha ao preparar UPDATE de imagem'];
        }
        $local = $localProductId ?? 0;
        $stmt->bind_param('siii', $imageUrl, $local, $isPrimary, $id);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();
        return $ok ? ['ok' => true, 'action' => 'updated'] : ['ok' => false, 'error' => $error];
    }

    $stmt = $db->prepare(
        "INSERT INTO olist_product_images (olist_id, sku, product_local_id, image_url, position, is_primary, status, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'falha ao preparar INSERT de imagem'];
    }
    $local = $localProductId ?? 0;
    $stmt->bind_param('ssisii', $olistId, $sku, $local, $imageUrl, $position, $isPrimary);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    return $ok ? ['ok' => true, 'action' => 'inserted'] : ['ok' => false, 'error' => $error];
}

/** @return list<array<string,mixed>> */
function stmt_rows(Database $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }
    $stmt->close();
    return $rows;
}

function log_msg(string $msg): void
{
    $logFile = __DIR__ . '/../logs/olist-sync-images-to-site.log';
    @mkdir(dirname($logFile), 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    error_log('[Sync] ' . $msg);
}

function exit_error(string $msg): never
{
    log_msg('ERRO: ' . $msg);
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
